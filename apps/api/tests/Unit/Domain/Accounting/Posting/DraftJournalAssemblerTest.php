<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Accounting\Posting;

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\Journal\Exception\InsufficientJournalLinesException;
use App\Domain\Accounting\Journal\Exception\MixedCurrencyJournalException;
use App\Domain\Accounting\Journal\Exception\UnbalancedJournalException;
use App\Domain\Accounting\Journal\JournalDirection;
use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Accounting\Journal\JournalLine;
use App\Domain\Accounting\Journal\JournalState;
use App\Domain\Accounting\Money\Currency;
use App\Domain\Accounting\Money\Money;
use App\Domain\Accounting\Posting\ActorReference;
use App\Domain\Accounting\Posting\DraftJournalAssembler;
use App\Domain\Accounting\Posting\IdempotencyKey;
use App\Domain\Accounting\Posting\PostingCommand;
use App\Domain\Accounting\Posting\SourceReference;
use App\Domain\Shared\Tenancy\TenantId;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Proves the PostingCommand -> Journal assembly boundary
 * `DraftJournalAssembler` implements (AETS-007 §11, §14 steps 6–8).
 * These tests provide direct construction-level coverage for
 * `POST-T058`, `POST-T061`–`POST-T064` — they prove candidate Journal
 * assembly and the Journal domain's own line-count/single-Currency/
 * balance invariant enforcement only. They do not, and cannot, prove
 * durable posting success or `POST-T065`'s atomicity claim (exactly
 * one *authoritative Posted* Journal) — that requires the future
 * Posting Engine's atomic persistence step, out of scope here.
 */
final class DraftJournalAssemblerTest extends TestCase
{
    private DraftJournalAssembler $assembler;

    private TenantId $tenantId;

    private JournalId $journalId;

    private Currency $myr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assembler = new DraftJournalAssembler;
        $this->tenantId = TenantId::of('tenant-0001');
        $this->journalId = JournalId::of('journal-0001');
        $this->myr = Currency::of('MYR');
    }

    /**
     * (POST-T062, POST-T063) A valid, balanced, two-line command
     * assembles successfully into a candidate Draft Journal.
     */
    public function test_valid_balanced_command_assembles_successfully(): void
    {
        $lines = [
            $this->debitLine('account-cash', '100.00'),
            $this->creditLine('account-income', '100.00'),
        ];

        $journal = $this->assembler->assemble($this->makeCommand($lines));

        $this->assertSame(JournalState::Draft, $journal->state());
        $this->assertTrue($journal->isBalanced());
    }

    /**
     * (POST-T061) A one-line command fails with the Journal domain's
     * own existing insufficient-lines exception, propagated unchanged
     * — never caught or wrapped by the assembler.
     */
    public function test_one_line_command_fails_with_insufficient_lines_exception(): void
    {
        $this->expectException(InsufficientJournalLinesException::class);

        $this->assembler->assemble($this->makeCommand([
            $this->debitLine('account-cash', '100.00'),
        ]));
    }

    /**
     * A zero-line command likewise fails with the same existing
     * exception — the assembler introduces no separate rule of its
     * own for the minimum-line-count boundary.
     */
    public function test_zero_line_command_fails_with_insufficient_lines_exception(): void
    {
        $this->expectException(InsufficientJournalLinesException::class);

        $this->assembler->assemble($this->makeCommand([]));
    }

    /**
     * A mixed-Currency command fails with the Journal domain's own
     * existing mixed-currency exception, propagated unchanged.
     */
    public function test_mixed_currency_command_fails_with_mixed_currency_exception(): void
    {
        $otherCurrency = $this->currencyOtherThanMyr();

        $this->expectException(MixedCurrencyJournalException::class);

        $this->assembler->assemble($this->makeCommand([
            JournalLine::create(AccountId::of('account-cash'), Money::fromDecimalString('100.00', $this->myr), JournalDirection::Debit),
            JournalLine::create(AccountId::of('account-income'), Money::fromDecimalString('100.00', $otherCurrency), JournalDirection::Credit),
        ]));
    }

    /**
     * Currency's registry currently supports only MYR (M1-T2), so a
     * genuinely mixed-Currency line pair cannot be expressed through
     * `Currency::of()` alone. Synthesizes a second, otherwise-valid
     * Currency via reflection — the same established technique
     * `JournalTest::currencyOtherThanMyr()` already uses for this
     * exact scenario.
     */
    private function currencyOtherThanMyr(): Currency
    {
        $reflection = new ReflectionClass(Currency::class);
        $other = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('identifier')->setValue($other, 'XXX');
        $reflection->getProperty('scale')->setValue($other, 2);

        return $other;
    }

    /**
     * (POST-T064) An unbalanced command — total Debit not exactly
     * equal to total Credit, even by one minor unit — fails with the
     * Journal domain's own existing unbalanced exception, propagated
     * unchanged.
     */
    public function test_unbalanced_command_fails_with_unbalanced_exception(): void
    {
        $this->expectException(UnbalancedJournalException::class);

        $this->assembler->assemble($this->makeCommand([
            $this->debitLine('account-cash', '100.00'),
            $this->creditLine('account-income', '99.99'),
        ]));
    }

    /**
     * (POST-T063) Total Debit exactly equal to total Credit succeeds
     * — restated as its own dedicated test, distinct from the general
     * "valid balanced command" case, per this task's explicit
     * traceability requirement.
     */
    public function test_exact_balance_succeeds(): void
    {
        $journal = $this->assembler->assemble($this->makeCommand([
            $this->debitLine('account-expense', '45.50'),
            $this->creditLine('account-cash', '45.50'),
        ]));

        $this->assertTrue($journal->isBalanced());
    }

    /**
     * The supplied TenantId, JournalId, and Journal Line order/content
     * are preserved exactly — the assembler introduces no
     * transformation of its own.
     */
    public function test_supplied_tenant_id_journal_id_and_lines_are_preserved_exactly(): void
    {
        $lines = [
            $this->debitLine('account-a', '10.00'),
            $this->debitLine('account-b', '20.00'),
            $this->creditLine('account-c', '30.00'),
        ];

        $journal = $this->assembler->assemble($this->makeCommand($lines, $this->tenantId, $this->journalId));

        $this->assertTrue($this->tenantId->equals($journal->tenantId()));
        $this->assertTrue($this->journalId->equals($journal->id()));
        $this->assertCount(3, $journal->lines());
        $this->assertSame($lines[0], $journal->lines()[0]);
        $this->assertSame($lines[1], $journal->lines()[1]);
        $this->assertSame($lines[2], $journal->lines()[2]);
    }

    /**
     * (M8) The PostingCommand's Financial Date is copied onto the
     * resulting Journal exactly — the assembler never substitutes a
     * different value.
     */
    public function test_financial_date_is_copied_onto_the_journal_exactly(): void
    {
        $financialDate = new \DateTimeImmutable('2026-05-20');

        $command = new PostingCommand(
            IdempotencyKey::of('key-0001'),
            $this->tenantId,
            ActorReference::of('actor-0001'),
            SourceReference::of('source-0001'),
            $this->journalId,
            [
                $this->debitLine('account-cash', '100.00'),
                $this->creditLine('account-income', '100.00'),
            ],
            $financialDate,
        );

        $journal = $this->assembler->assemble($command);

        $this->assertSame($financialDate, $journal->financialDate());
    }

    /**
     * The assembler exposes exactly one public method beyond its
     * constructor — no persistence, tenant, account, idempotency, or
     * orchestration method exists on this class.
     */
    public function test_exposes_only_the_assemble_method(): void
    {
        $reflection = new ReflectionClass(DraftJournalAssembler::class);

        $publicMethodNames = array_map(
            static fn (ReflectionMethod $method): string => $method->getName(),
            $reflection->getMethods(ReflectionMethod::IS_PUBLIC),
        );

        $this->assertSame(['assemble'], $publicMethodNames);
    }

    /**
     * No persistence dependency: the assembler does not use a
     * repository, does not touch the database, and has no framework
     * dependency.
     */
    public function test_has_no_persistence_or_framework_dependency(): void
    {
        $reflection = new ReflectionClass(DraftJournalAssembler::class);
        $source = file_get_contents((string) $reflection->getFileName());

        $this->assertIsString($source);
        $this->assertStringNotContainsString('use App\\Infrastructure\\', $source);
        $this->assertStringNotContainsString('Repository', $source);
        $this->assertStringNotContainsString('Illuminate\\', $source);
        $this->assertStringNotContainsString('Eloquent', $source);
        $this->assertFalse($reflection->getParentClass());
        $this->assertSame([], $reflection->getInterfaceNames());
    }

    /**
     * @param  list<JournalLine>  $lines
     */
    private function makeCommand(array $lines, ?TenantId $tenantId = null, ?JournalId $journalId = null): PostingCommand
    {
        return new PostingCommand(
            IdempotencyKey::of('key-0001'),
            $tenantId ?? $this->tenantId,
            ActorReference::of('actor-0001'),
            SourceReference::of('source-0001'),
            $journalId ?? $this->journalId,
            $lines,
            new \DateTimeImmutable('2026-08-15'),
        );
    }

    private function debitLine(string $accountId, string $amount): JournalLine
    {
        return JournalLine::create(AccountId::of($accountId), Money::fromDecimalString($amount, $this->myr), JournalDirection::Debit);
    }

    private function creditLine(string $accountId, string $amount): JournalLine
    {
        return JournalLine::create(AccountId::of($accountId), Money::fromDecimalString($amount, $this->myr), JournalDirection::Credit);
    }
}
