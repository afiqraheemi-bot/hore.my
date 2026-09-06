<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Accounting\Posting;

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\Journal\JournalDirection;
use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Accounting\Journal\JournalLine;
use App\Domain\Accounting\Money\Currency;
use App\Domain\Accounting\Money\Money;
use App\Domain\Accounting\Posting\ActorReference;
use App\Domain\Accounting\Posting\Exception\InvalidPostingCommandException;
use App\Domain\Accounting\Posting\IdempotencyKey;
use App\Domain\Accounting\Posting\PostingCommand;
use App\Domain\Accounting\Posting\SourceFingerprint;
use App\Domain\Accounting\Posting\SourceReference;
use App\Domain\Shared\Tenancy\TenantId;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Covers `PostingCommand` at exactly the construction level ATS-007
 * §7 defines (`POST-T001`–`POST-T010`) — the required-field presence,
 * the two-shape proposed Journal identity, and the "not a Journal /
 * not an Accounting Proposal" structural distinctions. It does not
 * exercise any Posting Validation Pipeline behavior (tenant ownership,
 * Account/Money/balance validity, duplicate prevention, atomicity,
 * AI-authority parity) — those remain the future Posting Engine's
 * concern and are out of this class's, and this test's, scope.
 */
final class PostingCommandTest extends TestCase
{
    private IdempotencyKey $idempotencyKey;

    private TenantId $tenantId;

    private ActorReference $actor;

    private SourceReference $source;

    private JournalId $journalId;

    /**
     * @var list<JournalLine>
     */
    private array $lines;

    protected function setUp(): void
    {
        parent::setUp();

        $this->idempotencyKey = IdempotencyKey::of('key-0001');
        $this->tenantId = TenantId::of('tenant-0001');
        $this->actor = ActorReference::of('actor-0001');
        $this->source = SourceReference::of('source-0001');
        $this->journalId = JournalId::of('journal-0001');

        $myr = Currency::of('MYR');
        $this->lines = [
            JournalLine::create(AccountId::of('account-cash'), Money::fromDecimalString('100.00', $myr), JournalDirection::Debit),
            JournalLine::create(AccountId::of('account-income'), Money::fromDecimalString('100.00', $myr), JournalDirection::Credit),
        ];
    }

    // (POST-T001) A Posting Command with every required field present is well-formed.
    public function test_valid_posting_command_is_constructed_with_every_required_field(): void
    {
        $command = new PostingCommand(
            $this->idempotencyKey,
            $this->tenantId,
            $this->actor,
            $this->source,
            $this->journalId,
            $this->lines,
            $this->financialDate(),
        );

        $this->assertSame($this->idempotencyKey, $command->idempotencyKey());
        $this->assertSame($this->tenantId, $command->tenantId());
        $this->assertSame($this->actor, $command->actor());
        $this->assertSame($this->source, $command->source());
        $this->assertSame($this->journalId, $command->journalId());
        $this->assertSame($this->lines, $command->lines());
        $this->assertNull($command->sourceFingerprint());
        $this->assertSame([], $command->evidenceReferences());
    }

    /**
     * (POST-T002) A Posting Command with no Idempotency Key is not
     * well-formed. PHP's own strict constructor typing enforces this:
     * there is no way to omit the parameter, so this is proven
     * structurally, not by attempting a call that cannot compile.
     */
    public function test_idempotency_key_parameter_is_required_and_non_nullable(): void
    {
        $this->assertParameterIsRequiredAndNonNullable('idempotencyKey', IdempotencyKey::class);
    }

    // (POST-T003) A Posting Command with no TenantId is not well-formed.
    public function test_tenant_id_parameter_is_required_and_non_nullable(): void
    {
        $this->assertParameterIsRequiredAndNonNullable('tenantId', TenantId::class);
    }

    // (POST-T004) A Posting Command with no Actor is not well-formed.
    public function test_actor_parameter_is_required_and_non_nullable(): void
    {
        $this->assertParameterIsRequiredAndNonNullable('actor', ActorReference::class);
    }

    // (POST-T005) A Posting Command with no Source is not well-formed.
    public function test_source_parameter_is_required_and_non_nullable(): void
    {
        $this->assertParameterIsRequiredAndNonNullable('source', SourceReference::class);
    }

    /**
     * (POST-028, M8) A Posting Command with no Financial Date is not
     * well-formed — never defaulted by this class to `created_at`,
     * "today," or any other system-derived value.
     */
    public function test_financial_date_parameter_is_required_and_non_nullable(): void
    {
        $this->assertParameterIsRequiredAndNonNullable('financialDate', \DateTimeImmutable::class);
    }

    /**
     * (POST-028) The Financial Date supplied is carried exactly.
     */
    public function test_financial_date_is_carried_exactly(): void
    {
        $financialDate = new \DateTimeImmutable('2026-03-01');

        $command = new PostingCommand(
            $this->idempotencyKey,
            $this->tenantId,
            $this->actor,
            $this->source,
            $this->journalId,
            $this->lines,
            $financialDate,
        );

        $this->assertSame($financialDate, $command->financialDate());
    }

    /**
     * (POST-T006) A Posting Command for a non-evidence-backed effect
     * carries no Evidence reference and is accepted — Evidence is
     * required only where applicable, never fabricated to fill the
     * field.
     */
    public function test_command_with_no_evidence_references_is_accepted(): void
    {
        $command = new PostingCommand(
            $this->idempotencyKey,
            $this->tenantId,
            $this->actor,
            $this->source,
            $this->journalId,
            $this->lines,
            $this->financialDate(),
        );

        $this->assertSame([], $command->evidenceReferences());
    }

    /**
     * (POST-T007) A Posting Command's proposed Journal identity may
     * be a fresh, not-yet-persisted identifier — this class accepts
     * any JournalId; whether it is fresh is a repository-level fact
     * this class does not decide.
     */
    public function test_proposed_journal_identity_may_be_a_fresh_identifier(): void
    {
        $command = new PostingCommand(
            $this->idempotencyKey,
            $this->tenantId,
            $this->actor,
            $this->source,
            JournalId::of('journal-fresh'),
            $this->lines,
            $this->financialDate(),
        );

        $this->assertSame('journal-fresh', $command->journalId()->toString());
    }

    /**
     * (POST-T008) A Posting Command's proposed Journal identity may
     * instead be a reference to an existing Draft Journal's
     * identifier — the same JournalId type serves both shapes; this
     * class carries no separate flag distinguishing them.
     */
    public function test_proposed_journal_identity_may_reference_an_existing_draft_journal(): void
    {
        $command = new PostingCommand(
            $this->idempotencyKey,
            $this->tenantId,
            $this->actor,
            $this->source,
            JournalId::of('journal-existing-draft'),
            $this->lines,
            $this->financialDate(),
        );

        $this->assertSame('journal-existing-draft', $command->journalId()->toString());
    }

    /**
     * (POST-T009) A Posting Command is not itself a Journal — it has
     * no ledger effect merely by existing, and constructing one
     * performs no persistence: no database or Eloquent dependency
     * exists anywhere in the class.
     */
    public function test_constructing_a_posting_command_performs_no_persistence(): void
    {
        $reflection = new ReflectionClass(PostingCommand::class);
        $source = file_get_contents((string) $reflection->getFileName());

        $this->assertIsString($source);
        $this->assertStringNotContainsString('Illuminate\\', $source);
        $this->assertStringNotContainsString('Eloquent', $source);
        $this->assertStringNotContainsString('Repository', $source);
        $this->assertFalse($reflection->getParentClass());
        $this->assertSame([], $reflection->getInterfaceNames());
    }

    /**
     * (POST-T010) A Posting Command is not itself an Accounting
     * Proposal — a candidate proposal with no confirming Actor cannot
     * be submitted as a Posting Command. Since Actor is a mandatory,
     * non-nullable constructor parameter (POST-T004), a bare
     * proposal-shaped input with no Actor structurally cannot become
     * a PostingCommand at all.
     */
    public function test_a_command_with_no_actor_cannot_be_constructed(): void
    {
        $this->assertParameterIsRequiredAndNonNullable('actor', ActorReference::class);
    }

    /**
     * Journal Lines are preserved as the exact instances supplied, in
     * exactly the order supplied.
     */
    public function test_lines_preserve_exact_instances_and_order(): void
    {
        $command = new PostingCommand(
            $this->idempotencyKey,
            $this->tenantId,
            $this->actor,
            $this->source,
            $this->journalId,
            $this->lines,
            $this->financialDate(),
        );

        $this->assertSame($this->lines[0], $command->lines()[0]);
        $this->assertSame($this->lines[1], $command->lines()[1]);
        $this->assertCount(2, $command->lines());
    }

    /**
     * A non-JournalLine member in the proposed lines array is
     * rejected deterministically.
     */
    public function test_non_journal_line_member_is_rejected(): void
    {
        $this->expectException(InvalidPostingCommandException::class);

        new PostingCommand(
            $this->idempotencyKey,
            $this->tenantId,
            $this->actor,
            $this->source,
            $this->journalId,
            [$this->lines[0], 'not-a-journal-line'],
            $this->financialDate(),
        );
    }

    /**
     * A well-formed list of Evidence reference strings is accepted.
     */
    public function test_evidence_string_list_is_accepted(): void
    {
        $command = new PostingCommand(
            $this->idempotencyKey,
            $this->tenantId,
            $this->actor,
            $this->source,
            $this->journalId,
            $this->lines,
            $this->financialDate(),
            null,
            ['evidence-0001', 'evidence-0002'],
        );

        $this->assertSame(['evidence-0001', 'evidence-0002'], $command->evidenceReferences());
    }

    /**
     * An empty Evidence reference list is accepted — the default.
     */
    public function test_empty_evidence_list_is_accepted(): void
    {
        $command = new PostingCommand(
            $this->idempotencyKey,
            $this->tenantId,
            $this->actor,
            $this->source,
            $this->journalId,
            $this->lines,
            $this->financialDate(),
            null,
            [],
        );

        $this->assertSame([], $command->evidenceReferences());
    }

    /**
     * A non-string member in the Evidence references array is
     * rejected deterministically.
     */
    public function test_non_string_evidence_member_is_rejected(): void
    {
        $this->expectException(InvalidPostingCommandException::class);

        new PostingCommand(
            $this->idempotencyKey,
            $this->tenantId,
            $this->actor,
            $this->source,
            $this->journalId,
            $this->lines,
            $this->financialDate(),
            null,
            ['evidence-0001', 12345],
        );
    }

    /**
     * SourceFingerprint is nullable: a command with no Source
     * Fingerprint is accepted, and one with a valid Source Fingerprint
     * is carried exactly.
     */
    public function test_source_fingerprint_is_nullable_and_carried_exactly_when_present(): void
    {
        $withoutFingerprint = new PostingCommand(
            $this->idempotencyKey,
            $this->tenantId,
            $this->actor,
            $this->source,
            $this->journalId,
            $this->lines,
            $this->financialDate(),
        );
        $this->assertNull($withoutFingerprint->sourceFingerprint());

        $fingerprint = SourceFingerprint::of('fp-0001');
        $withFingerprint = new PostingCommand(
            $this->idempotencyKey,
            $this->tenantId,
            $this->actor,
            $this->source,
            $this->journalId,
            $this->lines,
            $this->financialDate(),
            $fingerprint,
        );
        $this->assertSame($fingerprint, $withFingerprint->sourceFingerprint());
    }

    /**
     * PostingCommand is immutable: every property is readonly and no
     * public mutator method exists.
     */
    public function test_posting_command_is_immutable(): void
    {
        $reflection = new ReflectionClass(PostingCommand::class);

        foreach ($reflection->getProperties() as $property) {
            $this->assertTrue(
                $property->isReadOnly(),
                sprintf('Property "%s" must be readonly.', $property->getName()),
            );
        }

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $this->assertStringStartsNotWith(
                'set',
                $method->getName(),
                sprintf('Public method "%s" must not be a mutator.', $method->getName()),
            );
        }
    }

    /**
     * PostingCommand exposes exactly the intended accessors — no
     * Posting Validation Pipeline behavior (tenant matching, Account
     * validation, Money validation, balance checking, duplicate
     * detection, idempotent replay) is implemented on this class.
     */
    public function test_exposes_only_the_intended_accessors(): void
    {
        $reflection = new ReflectionClass(PostingCommand::class);

        $publicMethodNames = array_map(
            static fn (ReflectionMethod $method): string => $method->getName(),
            $reflection->getMethods(ReflectionMethod::IS_PUBLIC),
        );

        sort($publicMethodNames);

        $this->assertSame(
            ['__construct', 'actor', 'evidenceReferences', 'financialDate', 'idempotencyKey', 'journalId', 'lines', 'source', 'sourceFingerprint', 'tenantId'],
            $publicMethodNames,
        );
    }

    /**
     * No framework/database dependency anywhere in the file.
     */
    public function test_has_no_framework_dependency(): void
    {
        $reflection = new ReflectionClass(PostingCommand::class);
        $source = file_get_contents((string) $reflection->getFileName());

        $this->assertIsString($source);
        $this->assertStringNotContainsString('Illuminate\\', $source);
        $this->assertStringNotContainsString('Eloquent', $source);
    }

    private function financialDate(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-08-15');
    }

    private function assertParameterIsRequiredAndNonNullable(string $parameterName, string $expectedType): void
    {
        $constructor = new ReflectionMethod(PostingCommand::class, '__construct');

        foreach ($constructor->getParameters() as $parameter) {
            if ($parameter->getName() === $parameterName) {
                $this->assertFalse($parameter->isOptional(), sprintf('Parameter "%s" must be required.', $parameterName));
                $this->assertFalse($parameter->allowsNull(), sprintf('Parameter "%s" must not allow null.', $parameterName));

                $type = $parameter->getType();
                $this->assertInstanceOf(ReflectionNamedType::class, $type);
                $this->assertSame($expectedType, $type->getName());

                return;
            }
        }

        $this->fail(sprintf('Constructor parameter "%s" not found.', $parameterName));
    }
}
