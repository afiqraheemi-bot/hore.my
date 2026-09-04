<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Accounting\ChartOfAccounts;

use App\Infrastructure\Accounting\ChartOfAccounts\AccountPersistenceAdapter;
use App\Infrastructure\Accounting\ChartOfAccounts\AccountRepository;
use App\Infrastructure\Accounting\ChartOfAccounts\Exception\ImmutableAccountStateException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Tests\Feature\Infrastructure\Accounting\ChartOfAccounts\AccountRepositoryIntegrationTest;

/**
 * Structural-only coverage for {@see AccountRepository} (M2-T8.2): the
 * repository's public shape, its exclusive reliance on
 * {@see AccountPersistenceAdapter} for domain <-> persistence mapping,
 * and the deliberate absence of a delete API or any raw-row/balance
 * leakage — all provable from the class's own declared structure and
 * source text, with no database connection required. Real behavior
 * (save/find/codeExists against actual rows, uniqueness, tenant
 * isolation) is covered by {@see AccountRepositoryIntegrationTest}
 * against real PostgreSQL, never here.
 */
final class AccountRepositoryTest extends TestCase
{
    public function test_public_api_contains_only_intended_operations(): void
    {
        $reflection = new ReflectionClass(AccountRepository::class);

        $publicMethodNames = array_map(
            static fn (ReflectionMethod $method): string => $method->getName(),
            $reflection->getMethods(ReflectionMethod::IS_PUBLIC),
        );

        sort($publicMethodNames);

        $this->assertSame(
            ['__construct', 'codeExists', 'findByCode', 'findById', 'save'],
            $publicMethodNames,
        );
    }

    public function test_no_delete_method_exists(): void
    {
        $reflection = new ReflectionClass(AccountRepository::class);

        $publicMethodNames = array_map(
            static fn (ReflectionMethod $method): string => strtolower($method->getName()),
            $reflection->getMethods(ReflectionMethod::IS_PUBLIC),
        );

        foreach (['delete', 'remove', 'destroy', 'purge'] as $forbiddenMethodName) {
            $this->assertNotContains($forbiddenMethodName, $publicMethodNames);
        }
    }

    public function test_save_returns_void(): void
    {
        $method = new ReflectionMethod(AccountRepository::class, 'save');
        $returnType = $method->getReturnType();

        $this->assertInstanceOf(ReflectionNamedType::class, $returnType);
        $this->assertSame('void', $returnType->getName());
    }

    public function test_find_methods_return_only_account_or_null_never_a_raw_row(): void
    {
        foreach (['findById', 'findByCode'] as $methodName) {
            $method = new ReflectionMethod(AccountRepository::class, $methodName);
            $returnType = $method->getReturnType();

            $this->assertInstanceOf(ReflectionNamedType::class, $returnType);
            $this->assertTrue($returnType->allowsNull(), sprintf('%s() must be nullable.', $methodName));
            $this->assertSame('App\Domain\Accounting\ChartOfAccounts\Account', $returnType->getName());
        }
    }

    public function test_code_exists_returns_bool(): void
    {
        $method = new ReflectionMethod(AccountRepository::class, 'codeExists');
        $returnType = $method->getReturnType();

        $this->assertInstanceOf(ReflectionNamedType::class, $returnType);
        $this->assertSame('bool', $returnType->getName());
    }

    public function test_uses_account_persistence_adapter_for_mapping(): void
    {
        $reflection = new ReflectionClass(AccountRepository::class);
        $source = file_get_contents((string) $reflection->getFileName());

        $this->assertIsString($source);
        $this->assertStringContainsString('AccountPersistenceAdapter', $source);
        $this->assertStringContainsString('toPersistedRow', $source);
        $this->assertStringContainsString('fromPersistedRow', $source);
    }

    public function test_does_not_expose_eloquent(): void
    {
        $reflection = new ReflectionClass(AccountRepository::class);
        $source = file_get_contents((string) $reflection->getFileName());

        $this->assertIsString($source);
        $this->assertStringNotContainsString('Eloquent', $source);
    }

    public function test_has_no_balance_related_behavior(): void
    {
        $reflection = new ReflectionClass(AccountRepository::class);
        $source = file_get_contents((string) $reflection->getFileName());

        $this->assertIsString($source);
        $this->assertStringNotContainsStringIgnoringCase('debit', $source);
        $this->assertStringNotContainsStringIgnoringCase('credit', $source);
        $this->assertStringNotContainsStringIgnoringCase('->balance', $source);
    }

    public function test_never_reads_or_persists_normal_balance_directly(): void
    {
        $reflection = new ReflectionClass(AccountRepository::class);
        $source = file_get_contents((string) $reflection->getFileName());

        $this->assertIsString($source);
        $this->assertStringNotContainsString('normalBalance', $source);
        $this->assertStringNotContainsString('normal_balance', $source);
    }

    /**
     * The five fields the current Account domain exposes no valid
     * business operation to change (Tenant, Account Code, Account
     * Name, Account Type, Account Origin) must each be named in the
     * guard `save()` runs before allowing an update — proven from
     * source text, not behavior (behavior is proven against real
     * PostgreSQL in the integration suite).
     */
    public function test_immutable_field_list_is_explicitly_protected(): void
    {
        $reflection = new ReflectionClass(AccountRepository::class);
        $source = file_get_contents((string) $reflection->getFileName());

        $this->assertIsString($source);
        $this->assertStringContainsString(ImmutableAccountStateException::class, $source);

        foreach (['tenant_id', 'account_code', 'account_name', 'account_type', 'account_origin'] as $immutableField) {
            $this->assertStringContainsString(
                sprintf("'%s'", $immutableField),
                $source,
                sprintf('Expected the immutable-field guard to reference "%s".', $immutableField),
            );
        }
    }

    /**
     * Only the three fields the domain actually exposes a valid
     * mutation path for (`active`, `posting_eligible`, `parent_id`)
     * may appear on the right-hand side of an update statement.
     */
    public function test_only_domain_mutable_fields_are_ever_updated(): void
    {
        $reflection = new ReflectionClass(AccountRepository::class);
        $source = file_get_contents((string) $reflection->getFileName());

        $this->assertIsString($source);

        $updateBlockStart = strpos($source, '->update([');
        $this->assertIsInt($updateBlockStart, 'Expected save() to contain exactly one ->update([ call.');

        $updateBlockEnd = strpos($source, ']);', $updateBlockStart);
        $this->assertIsInt($updateBlockEnd);

        $updateBlock = substr($source, $updateBlockStart, $updateBlockEnd - $updateBlockStart);

        foreach (['active', 'posting_eligible', 'parent_id'] as $mutableField) {
            $this->assertStringContainsString(sprintf("'%s'", $mutableField), $updateBlock);
        }

        foreach (['tenant_id', 'account_code', 'account_name', 'account_type', 'account_origin'] as $immutableField) {
            $this->assertStringNotContainsString(sprintf("'%s'", $immutableField), $updateBlock);
        }
    }

    public function test_does_not_run_hierarchy_policy_on_a_single_read(): void
    {
        $reflection = new ReflectionClass(AccountRepository::class);
        $source = file_get_contents((string) $reflection->getFileName());

        $this->assertIsString($source);
        // The docblock explains, in prose, why AccountHierarchyPolicy is
        // deliberately not consulted here — so it names the class. What
        // must be absent is any actual *use* of it: an import or a call.
        $this->assertStringNotContainsString('use App\Domain\Accounting\ChartOfAccounts\AccountHierarchyPolicy', $source);
        $this->assertStringNotContainsString('AccountHierarchyPolicy::', $source);
        $this->assertStringNotContainsString('new AccountHierarchyPolicy', $source);
    }
}
