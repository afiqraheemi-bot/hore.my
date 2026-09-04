<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Accounting\Journal;

use App\Infrastructure\Accounting\Journal\Exception\ImmutableJournalStateException;
use App\Infrastructure\Accounting\Journal\JournalPersistenceAdapter;
use App\Infrastructure\Accounting\Journal\JournalRepository;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Tests\Feature\Infrastructure\Accounting\Journal\JournalRepositoryIntegrationTest;

/**
 * Structural-only coverage for {@see JournalRepository} (M3-T10): the
 * repository's public shape, its exclusive reliance on
 * {@see JournalPersistenceAdapter} for domain <-> persistence mapping,
 * its transactional save path, and the deliberate absence of a delete
 * API, a raw-row/balance leakage, or any Posting-Engine/idempotency/
 * Audit/Outbox dependency — all provable from the class's own declared
 * structure and source text, with no database connection required.
 * Real behavior (save/find against actual rows, atomicity, immutable-
 * state rejection, tenant isolation, concurrency) is covered by
 * {@see JournalRepositoryIntegrationTest} against real PostgreSQL,
 * never here — mirroring the precedent already established by
 * `AccountRepositoryTest`/`AccountRepositoryIntegrationTest`.
 */
final class JournalRepositoryTest extends TestCase
{
    public function test_public_api_contains_only_intended_operations(): void
    {
        $reflection = new ReflectionClass(JournalRepository::class);

        $publicMethodNames = array_map(
            static fn (ReflectionMethod $method): string => $method->getName(),
            $reflection->getMethods(ReflectionMethod::IS_PUBLIC),
        );

        sort($publicMethodNames);

        $this->assertSame(['__construct', 'findById', 'save'], $publicMethodNames);
    }

    public function test_no_delete_or_crud_method_exists(): void
    {
        $reflection = new ReflectionClass(JournalRepository::class);
        $publicMethodNames = array_map(
            static fn (ReflectionMethod $method): string => strtolower($method->getName()),
            $reflection->getMethods(ReflectionMethod::IS_PUBLIC),
        );

        foreach (['delete', 'remove', 'destroy', 'purge', 'update', 'list', 'search', 'all'] as $forbiddenMethodName) {
            $this->assertNotContains($forbiddenMethodName, $publicMethodNames);
        }
    }

    public function test_save_accepts_exactly_one_journal_argument_and_returns_void(): void
    {
        $method = new ReflectionMethod(JournalRepository::class, 'save');

        $parameters = $method->getParameters();
        $this->assertCount(1, $parameters);
        $this->assertSame('App\Domain\Accounting\Journal\Journal', (string) $parameters[0]->getType());

        $returnType = $method->getReturnType();
        $this->assertInstanceOf(ReflectionNamedType::class, $returnType);
        $this->assertSame('void', $returnType->getName());
    }

    public function test_find_by_id_requires_tenant_id_and_journal_id_and_returns_nullable_journal(): void
    {
        $method = new ReflectionMethod(JournalRepository::class, 'findById');

        $parameters = $method->getParameters();
        $this->assertCount(2, $parameters);
        $this->assertSame('App\Domain\Shared\Tenancy\TenantId', (string) $parameters[0]->getType());
        $this->assertSame('App\Domain\Accounting\Journal\JournalId', (string) $parameters[1]->getType());

        $returnType = $method->getReturnType();
        $this->assertInstanceOf(ReflectionNamedType::class, $returnType);
        $this->assertTrue($returnType->allowsNull());
        $this->assertSame('App\Domain\Accounting\Journal\Journal', $returnType->getName());
    }

    public function test_uses_journal_persistence_adapter_for_mapping(): void
    {
        $reflection = new ReflectionClass(JournalRepository::class);
        $source = file_get_contents((string) $reflection->getFileName());

        $this->assertIsString($source);
        $this->assertStringContainsString('JournalPersistenceAdapter', $source);
        $this->assertStringContainsString('toPersistedHeader', $source);
        $this->assertStringContainsString('toPersistedLines', $source);
        $this->assertStringContainsString('fromPersistedJournal', $source);
    }

    public function test_does_not_expose_eloquent(): void
    {
        $reflection = new ReflectionClass(JournalRepository::class);
        $source = file_get_contents((string) $reflection->getFileName());

        $this->assertIsString($source);
        $this->assertStringNotContainsString('Eloquent', $source);
    }

    public function test_save_runs_inside_a_database_transaction(): void
    {
        $reflection = new ReflectionClass(JournalRepository::class);
        $source = file_get_contents((string) $reflection->getFileName());

        $this->assertIsString($source);
        $this->assertStringContainsString('->transaction(', $source);
        $this->assertStringContainsString('->lockForUpdate()', $source);
    }

    public function test_guards_immutable_journal_state_with_the_dedicated_exception(): void
    {
        $reflection = new ReflectionClass(JournalRepository::class);
        $source = file_get_contents((string) $reflection->getFileName());

        $this->assertIsString($source);
        $this->assertStringContainsString(ImmutableJournalStateException::class, $source);
        $this->assertStringContainsString('forPostedJournal', $source);
        $this->assertStringContainsString('forField', $source);
    }

    public function test_has_no_account_taxonomy_or_balance_related_behavior(): void
    {
        $reflection = new ReflectionClass(JournalRepository::class);
        $source = file_get_contents((string) $reflection->getFileName());

        $this->assertIsString($source);
        $this->assertStringNotContainsString('AccountType', $source);
        $this->assertStringNotContainsString('NormalBalance', $source);
        $this->assertStringNotContainsStringIgnoringCase('->balance', $source);
        $this->assertStringNotContainsString('debit_total', $source);
        $this->assertStringNotContainsString('credit_total', $source);
        $this->assertStringNotContainsString('signedAmount', $source);
    }

    /**
     * No Posting Engine, idempotency, Audit Event, Outbox, or
     * Actor/Source/Evidence dependency exists yet — this repository is
     * a pure persistence boundary (M3-T10 scope). The class's own
     * docblock legitimately *names* several of these concepts in
     * prose, to explain that they are deliberately absent — exactly
     * the same pattern already established for
     * `AccountRepositoryTest::test_does_not_run_hierarchy_policy_on_a_single_read()`.
     * What must be absent is any actual *use* of them: an import or a
     * call, never a bare mention of the word.
     */
    public function test_has_no_out_of_scope_dependencies(): void
    {
        $reflection = new ReflectionClass(JournalRepository::class);
        $source = file_get_contents((string) $reflection->getFileName());

        $this->assertIsString($source);

        foreach (['PostingEngine', 'IdempotencyKey', 'AuditEvent', 'Outbox', 'Actor', 'Evidence'] as $outOfScopeSymbol) {
            $this->assertStringNotContainsString('use App\\'.$outOfScopeSymbol, $source);
            $this->assertStringNotContainsString($outOfScopeSymbol.'::', $source);
            $this->assertStringNotContainsString('new '.$outOfScopeSymbol, $source);
        }
    }

    public function test_find_methods_never_return_a_raw_database_row(): void
    {
        $method = new ReflectionMethod(JournalRepository::class, 'findById');
        $returnType = $method->getReturnType();

        $this->assertInstanceOf(ReflectionNamedType::class, $returnType);
        $this->assertNotSame('array', $returnType->getName());
        $this->assertNotSame('object', $returnType->getName());
        $this->assertNotSame('stdClass', $returnType->getName());
    }
}
