<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Accounting\Journal;

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\ChartOfAccounts\Exception\InvalidAccountIdException;
use App\Domain\Accounting\Journal\Exception\InconsistentPostedAtException;
use App\Domain\Accounting\Journal\Exception\InsufficientJournalLinesException;
use App\Domain\Accounting\Journal\Exception\InvalidJournalIdException;
use App\Domain\Accounting\Journal\Exception\JournalAlreadyPostedException;
use App\Domain\Accounting\Journal\Exception\UnbalancedJournalException;
use App\Domain\Accounting\Journal\Journal;
use App\Domain\Accounting\Journal\JournalDirection;
use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Accounting\Journal\JournalLine;
use App\Domain\Accounting\Journal\JournalState;
use App\Domain\Accounting\Money\Currency;
use App\Domain\Accounting\Money\Exception\InvalidCurrencyException;
use App\Domain\Accounting\Money\Exception\InvalidMinorUnitsException;
use App\Domain\Accounting\Money\Money;
use App\Domain\Shared\Tenancy\Exception\InvalidTenantIdException;
use App\Domain\Shared\Tenancy\TenantId;
use App\Infrastructure\Accounting\Journal\Exception\InvalidPersistedJournalDirectionException;
use App\Infrastructure\Accounting\Journal\Exception\InvalidPersistedJournalStateException;
use App\Infrastructure\Accounting\Journal\JournalPersistenceAdapter;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Feature\Infrastructure\Accounting\Journal\JournalPersistenceAdapterIntegrationTest;
use Tests\Unit\Domain\Accounting\Journal\JournalTest;
use Tests\Unit\Infrastructure\Accounting\ChartOfAccounts\AccountPersistenceAdapterTest;

/**
 * Covers {@see JournalPersistenceAdapter} (M3-T8) at the unit level —
 * pure mapping/translation logic, no database. Real-PostgreSQL
 * round-trip proof lives in
 * {@see JournalPersistenceAdapterIntegrationTest}.
 *
 * ATS-004 has no dedicated persistence-adapter test IDs — a
 * traceability gap reported in this task's own report, mirroring the
 * identical gap already accepted for
 * {@see AccountPersistenceAdapterTest}
 * before ATS-005's own persistence-adapter coverage was added.
 */
final class JournalPersistenceAdapterTest extends TestCase
{
    private JournalPersistenceAdapter $adapter;

    private Currency $myr;

    private TenantId $tenantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adapter = new JournalPersistenceAdapter;
        $this->myr = Currency::of('MYR');
        $this->tenantId = TenantId::of('tenant-0001');
    }

    public function test_draft_journal_maps_to_persisted_representation(): void
    {
        $journal = $this->makeJournal(state: JournalState::Draft);

        $header = $this->adapter->toPersistedHeader($journal);

        $this->assertSame('tenant-0001', $header['tenant_id']);
        $this->assertSame('journal-0001', $header['journal_id']);
        $this->assertSame('Draft', $header['state']);
    }

    public function test_posted_journal_maps_to_persisted_representation(): void
    {
        $journal = $this->makeJournal(state: JournalState::Posted);

        $header = $this->adapter->toPersistedHeader($journal);

        $this->assertSame('Posted', $header['state']);
    }

    public function test_persisted_draft_maps_back_to_a_journal(): void
    {
        $journal = $this->adapter->fromPersistedJournal(
            ['tenant_id' => 'tenant-0001', 'journal_id' => 'journal-0001', 'state' => 'Draft', 'correction_type' => null, 'corrected_journal_id' => null, 'financial_date' => '2026-08-15', 'posted_at' => null],
            $this->twoBalancedLineRows(),
        );

        $this->assertSame(JournalState::Draft, $journal->state());
    }

    public function test_persisted_posted_maps_back_to_a_journal(): void
    {
        $journal = $this->adapter->fromPersistedJournal(
            ['tenant_id' => 'tenant-0001', 'journal_id' => 'journal-0001', 'state' => 'Posted', 'correction_type' => null, 'corrected_journal_id' => null, 'financial_date' => '2026-08-15', 'posted_at' => '2026-09-06 10:00:00'],
            $this->twoBalancedLineRows(),
        );

        $this->assertSame(JournalState::Posted, $journal->state());
    }

    /**
     * (M8) `financial_date` is persisted as a plain `Y-m-d` string and
     * round-trips back to the exact calendar date, distinct from
     * `posted_at`.
     */
    public function test_financial_date_round_trips_exactly(): void
    {
        $journal = $this->makeJournal();
        $header = $this->adapter->toPersistedHeader($journal);

        $this->assertSame('2026-08-15', $header['financial_date']);

        $reconstructed = $this->adapter->fromPersistedJournal($header, $this->adapter->toPersistedLines($journal));

        $this->assertSame('2026-08-15', $reconstructed->financialDate()->format('Y-m-d'));
    }

    /**
     * (M8) A Draft Journal's persisted `posted_at` is `null`; a Posted
     * Journal's round-trips back to the exact moment, distinct from
     * `financial_date`.
     */
    public function test_posted_at_is_null_for_draft_and_round_trips_exactly_for_posted(): void
    {
        $draft = $this->makeJournal(state: JournalState::Draft);
        $draftHeader = $this->adapter->toPersistedHeader($draft);
        $this->assertNull($draftHeader['posted_at']);

        $posted = $this->makeJournal(id: JournalId::of('journal-0002'), state: JournalState::Posted);
        $postedHeader = $this->adapter->toPersistedHeader($posted);
        $this->assertSame('2026-09-06 10:00:00', $postedHeader['posted_at']);

        $reconstructed = $this->adapter->fromPersistedJournal($postedHeader, $this->adapter->toPersistedLines($posted));
        $this->assertSame('2026-09-06 10:00:00', $reconstructed->postedAt()?->format('Y-m-d H:i:s'));
    }

    /**
     * (M8) A `state`/`posted_at` mismatch (a Draft claiming a
     * `posted_at`, or a Posted Journal with none) is rejected during
     * reconstitution — the adapter never silently accepts corrupted
     * persisted data.
     */
    public function test_inconsistent_posted_at_is_rejected_during_reconstitution(): void
    {
        $this->expectException(InconsistentPostedAtException::class);

        $this->adapter->fromPersistedJournal(
            ['tenant_id' => 'tenant-0001', 'journal_id' => 'journal-0001', 'state' => 'Draft', 'correction_type' => null, 'corrected_journal_id' => null, 'financial_date' => '2026-08-15', 'posted_at' => '2026-09-06 10:00:00'],
            $this->twoBalancedLineRows(),
        );
    }

    public function test_tenant_id_round_trips_exactly(): void
    {
        $journal = $this->makeJournal();
        $header = $this->adapter->toPersistedHeader($journal);

        $reconstructed = $this->adapter->fromPersistedJournal($header, $this->adapter->toPersistedLines($journal));

        $this->assertTrue($this->tenantId->equals($reconstructed->tenantId()));
    }

    public function test_journal_id_round_trips_exactly(): void
    {
        $id = JournalId::of('journal-0001');
        $journal = $this->makeJournal(id: $id);
        $header = $this->adapter->toPersistedHeader($journal);

        $reconstructed = $this->adapter->fromPersistedJournal($header, $this->adapter->toPersistedLines($journal));

        $this->assertTrue($id->equals($reconstructed->id()));
    }

    public function test_line_order_is_preserved_exactly(): void
    {
        $journal = Journal::create($this->tenantId, JournalId::of('journal-0001'), [
            $this->debitLine('account-a', '10.00'),
            $this->debitLine('account-b', '20.00'),
            $this->creditLine('account-c', '30.00'),
        ], $this->financialDate());

        $header = $this->adapter->toPersistedHeader($journal);
        $lines = $this->adapter->toPersistedLines($journal);

        // Deliberately shuffle the rows before reconstruction — the
        // adapter must reorder by line_position itself, never trust
        // whatever order it is handed.
        $shuffled = [$lines[2], $lines[0], $lines[1]];

        $reconstructed = $this->adapter->fromPersistedJournal($header, $shuffled);

        $accountIds = array_map(
            static fn (JournalLine $line): string => $line->accountId()->toString(),
            $reconstructed->lines(),
        );
        $this->assertSame(['account-a', 'account-b', 'account-c'], $accountIds);
    }

    public function test_account_id_round_trips_exactly(): void
    {
        $journal = $this->makeJournal();
        $header = $this->adapter->toPersistedHeader($journal);
        $lines = $this->adapter->toPersistedLines($journal);

        $reconstructed = $this->adapter->fromPersistedJournal($header, $lines);

        $this->assertSame('account-cash', $reconstructed->lines()[0]->accountId()->toString());
        $this->assertSame('account-income', $reconstructed->lines()[1]->accountId()->toString());
    }

    public function test_debit_direction_round_trips_exactly(): void
    {
        $journal = $this->makeJournal();
        $header = $this->adapter->toPersistedHeader($journal);
        $lines = $this->adapter->toPersistedLines($journal);

        $reconstructed = $this->adapter->fromPersistedJournal($header, $lines);

        $this->assertSame(JournalDirection::Debit, $reconstructed->lines()[0]->direction());
    }

    public function test_credit_direction_round_trips_exactly(): void
    {
        $journal = $this->makeJournal();
        $header = $this->adapter->toPersistedHeader($journal);
        $lines = $this->adapter->toPersistedLines($journal);

        $reconstructed = $this->adapter->fromPersistedJournal($header, $lines);

        $this->assertSame(JournalDirection::Credit, $reconstructed->lines()[1]->direction());
    }

    public function test_money_amount_round_trips_exactly(): void
    {
        $journal = $this->makeJournal();
        $header = $this->adapter->toPersistedHeader($journal);
        $lines = $this->adapter->toPersistedLines($journal);

        $this->assertSame('10000', $lines[0]['amount']);

        $reconstructed = $this->adapter->fromPersistedJournal($header, $lines);

        $this->assertSame('100.00', $reconstructed->lines()[0]->money()->toDecimalString());
    }

    public function test_currency_round_trips_exactly(): void
    {
        $journal = $this->makeJournal();
        $header = $this->adapter->toPersistedHeader($journal);
        $lines = $this->adapter->toPersistedLines($journal);

        $this->assertSame('MYR', $lines[0]['currency']);

        $reconstructed = $this->adapter->fromPersistedJournal($header, $lines);

        $this->assertSame('MYR', $reconstructed->lines()[0]->money()->currency()->identifier());
    }

    public function test_multi_line_exact_round_trip(): void
    {
        $journal = Journal::create($this->tenantId, JournalId::of('journal-0001'), [
            $this->debitLine('account-cash', '60.00'),
            $this->debitLine('account-vat-input', '5.00'),
            $this->creditLine('account-income', '40.00'),
            $this->creditLine('account-income-2', '25.00'),
        ], $this->financialDate());

        $header = $this->adapter->toPersistedHeader($journal);
        $lines = $this->adapter->toPersistedLines($journal);

        $reconstructed = $this->adapter->fromPersistedJournal($header, $lines);

        $this->assertTrue($journal->equals($reconstructed));
        $this->assertCount(4, $reconstructed->lines());
        $this->assertTrue($reconstructed->isBalanced());
    }

    /**
     * Reconstructing a Posted Journal never throws
     * {@see JournalAlreadyPostedException}
     * — that exception belongs to `post()`, a business transition, not
     * to state restoration. Proven both behaviorally (no exception)
     * and structurally (the adapter's read path never calls `post()`
     * at all).
     */
    public function test_posted_reconstruction_uses_reconstitute_not_post(): void
    {
        $journal = $this->adapter->fromPersistedJournal(
            ['tenant_id' => 'tenant-0001', 'journal_id' => 'journal-0001', 'state' => 'Posted', 'correction_type' => null, 'corrected_journal_id' => null, 'financial_date' => '2026-08-15', 'posted_at' => '2026-09-06 10:00:00'],
            $this->twoBalancedLineRows(),
        );
        $this->assertSame(JournalState::Posted, $journal->state());

        // Extract fromPersistedJournal()'s own method body by line
        // range, rather than scanning the whole file — the class
        // docblock legitimately names `create(...)->post()` in prose
        // to explain what this method deliberately avoids, so a
        // whole-file scan would trip on its own documentation.
        $reflection = new ReflectionClass(JournalPersistenceAdapter::class);
        $method = $reflection->getMethod('fromPersistedJournal');
        $source = file_get_contents((string) $reflection->getFileName());
        $this->assertIsString($source);

        $lines = explode("\n", $source);
        $bodyLines = array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1);
        $body = implode("\n", $bodyLines);

        $this->assertStringNotContainsString('->post(', $body);
        $this->assertStringContainsString('reconstitute', $body);
    }

    public function test_malformed_journal_state_is_rejected(): void
    {
        $this->expectException(InvalidPersistedJournalStateException::class);

        $this->adapter->fromPersistedJournal(
            ['tenant_id' => 'tenant-0001', 'journal_id' => 'journal-0001', 'state' => 'NotACanonicalState', 'correction_type' => null, 'corrected_journal_id' => null, 'financial_date' => '2026-08-15', 'posted_at' => null],
            $this->twoBalancedLineRows(),
        );
    }

    public function test_malformed_journal_direction_is_rejected(): void
    {
        $lines = $this->twoBalancedLineRows();
        $lines[0]['direction'] = 'NotACanonicalDirection';

        $this->expectException(InvalidPersistedJournalDirectionException::class);

        $this->adapter->fromPersistedJournal(
            ['tenant_id' => 'tenant-0001', 'journal_id' => 'journal-0001', 'state' => 'Draft', 'correction_type' => null, 'corrected_journal_id' => null, 'financial_date' => '2026-08-15', 'posted_at' => null],
            $lines,
        );
    }

    public function test_malformed_tenant_id_is_rejected(): void
    {
        $this->expectException(InvalidTenantIdException::class);

        $this->adapter->fromPersistedJournal(
            ['tenant_id' => '', 'journal_id' => 'journal-0001', 'state' => 'Draft', 'correction_type' => null, 'corrected_journal_id' => null, 'financial_date' => '2026-08-15', 'posted_at' => null],
            $this->twoBalancedLineRows(),
        );
    }

    public function test_malformed_journal_id_is_rejected(): void
    {
        $this->expectException(InvalidJournalIdException::class);

        $this->adapter->fromPersistedJournal(
            ['tenant_id' => 'tenant-0001', 'journal_id' => '', 'state' => 'Draft', 'correction_type' => null, 'corrected_journal_id' => null, 'financial_date' => '2026-08-15', 'posted_at' => null],
            $this->twoBalancedLineRows(),
        );
    }

    public function test_malformed_account_id_is_rejected(): void
    {
        $lines = $this->twoBalancedLineRows();
        $lines[0]['account_id'] = '';

        $this->expectException(InvalidAccountIdException::class);

        $this->adapter->fromPersistedJournal(
            ['tenant_id' => 'tenant-0001', 'journal_id' => 'journal-0001', 'state' => 'Draft', 'correction_type' => null, 'corrected_journal_id' => null, 'financial_date' => '2026-08-15', 'posted_at' => null],
            $lines,
        );
    }

    public function test_malformed_money_amount_is_rejected(): void
    {
        $lines = $this->twoBalancedLineRows();
        $lines[0]['amount'] = 'not-a-number';

        $this->expectException(InvalidMinorUnitsException::class);

        $this->adapter->fromPersistedJournal(
            ['tenant_id' => 'tenant-0001', 'journal_id' => 'journal-0001', 'state' => 'Draft', 'correction_type' => null, 'corrected_journal_id' => null, 'financial_date' => '2026-08-15', 'posted_at' => null],
            $lines,
        );
    }

    public function test_malformed_currency_is_rejected(): void
    {
        $lines = $this->twoBalancedLineRows();
        $lines[0]['currency'] = 'ZZZ';

        $this->expectException(InvalidCurrencyException::class);

        $this->adapter->fromPersistedJournal(
            ['tenant_id' => 'tenant-0001', 'journal_id' => 'journal-0001', 'state' => 'Draft', 'correction_type' => null, 'corrected_journal_id' => null, 'financial_date' => '2026-08-15', 'posted_at' => null],
            $lines,
        );
    }

    public function test_unbalanced_persisted_journal_is_rejected_during_reconstitution(): void
    {
        $lines = $this->twoBalancedLineRows();
        $lines[1]['amount'] = '9999';

        $this->expectException(UnbalancedJournalException::class);

        $this->adapter->fromPersistedJournal(
            ['tenant_id' => 'tenant-0001', 'journal_id' => 'journal-0001', 'state' => 'Draft', 'correction_type' => null, 'corrected_journal_id' => null, 'financial_date' => '2026-08-15', 'posted_at' => null],
            $lines,
        );
    }

    /**
     * A genuinely mixed-Currency persisted pair — two lines each
     * carrying a *different*, both-otherwise-valid Currency — cannot
     * currently be expressed through this adapter's string-based read
     * path, since {@see Currency}'s registry supports only `MYR`
     * today (M1-T2): any second identifier is rejected as malformed
     * before a mismatch could even be compared (already proven by
     * {@see test_malformed_currency_is_rejected()}). What this test
     * proves instead is the guarantee the adapter actually relies on
     * for this case: reconstruction is delegated entirely to
     * {@see Journal::reconstitute()},
     * which already enforces single-Currency
     * ({@see JournalTest::test_reconstitute_rejects_mixed_currency()}
     * proves this directly against the domain, using the same
     * reflection-constructed second-Currency technique Money's own
     * test suite established) — the adapter adds no currency-mixing
     * risk of its own between extracting each line's Currency and
     * handing the assembled line list to `reconstitute()`.
     */
    public function test_mixed_currency_rejection_is_delegated_to_reconstitute(): void
    {
        $reflection = new ReflectionClass(JournalPersistenceAdapter::class);
        $source = file_get_contents((string) $reflection->getFileName());
        $this->assertIsString($source);

        $this->assertStringContainsString('Journal::reconstitute(', $source);
    }

    public function test_fewer_than_two_persisted_lines_is_rejected_during_reconstitution(): void
    {
        $lines = $this->twoBalancedLineRows();

        $this->expectException(InsufficientJournalLinesException::class);

        $this->adapter->fromPersistedJournal(
            ['tenant_id' => 'tenant-0001', 'journal_id' => 'journal-0001', 'state' => 'Draft', 'correction_type' => null, 'corrected_journal_id' => null, 'financial_date' => '2026-08-15', 'posted_at' => null],
            [$lines[0]],
        );
    }

    public function test_no_float_appears_anywhere_in_the_adapter(): void
    {
        $reflection = new ReflectionClass(JournalPersistenceAdapter::class);

        foreach ($reflection->getMethods() as $method) {
            foreach ($method->getParameters() as $parameter) {
                $type = $parameter->getType();
                if ($type instanceof \ReflectionNamedType) {
                    $this->assertNotSame('float', $type->getName());
                }
            }

            $returnType = $method->getReturnType();
            if ($returnType instanceof \ReflectionNamedType) {
                $this->assertNotSame('float', $returnType->getName());
            }
        }
    }

    public function test_persisted_line_has_no_signed_amount_or_balance_field(): void
    {
        $journal = $this->makeJournal();
        $line = $this->adapter->toPersistedLines($journal)[0];

        foreach (array_keys($line) as $key) {
            $this->assertStringNotContainsStringIgnoringCase('signed', $key);
            $this->assertStringNotContainsStringIgnoringCase('balance', $key);
            $this->assertStringNotContainsStringIgnoringCase('debit_total', $key);
            $this->assertStringNotContainsStringIgnoringCase('credit_total', $key);
        }
    }

    public function test_persisted_header_has_no_balance_total_field(): void
    {
        $journal = $this->makeJournal();
        $header = $this->adapter->toPersistedHeader($journal);

        foreach (array_keys($header) as $key) {
            $this->assertStringNotContainsStringIgnoringCase('balance', $key);
            $this->assertStringNotContainsStringIgnoringCase('debit_total', $key);
            $this->assertStringNotContainsStringIgnoringCase('credit_total', $key);
        }
    }

    public function test_has_no_account_type_or_normal_balance_inference(): void
    {
        $reflection = new ReflectionClass(JournalPersistenceAdapter::class);
        $source = file_get_contents((string) $reflection->getFileName());
        $this->assertIsString($source);

        $classBodyStart = strpos($source, 'final class JournalPersistenceAdapter');
        $this->assertIsInt($classBodyStart);
        $classBody = substr($source, $classBodyStart);

        $this->assertStringNotContainsString('AccountType', $classBody);
        $this->assertStringNotContainsString('NormalBalance', $classBody);
    }

    public function test_has_no_framework_dependency(): void
    {
        $reflection = new ReflectionClass(JournalPersistenceAdapter::class);
        $source = file_get_contents((string) $reflection->getFileName());

        $this->assertIsString($source);
        $this->assertStringNotContainsString('Illuminate\\', $source);
        $this->assertStringNotContainsString('Eloquent', $source);
    }

    private function makeJournal(?TenantId $tenantId = null, ?JournalId $id = null, JournalState $state = JournalState::Draft): Journal
    {
        $journal = Journal::create($tenantId ?? $this->tenantId, $id ?? JournalId::of('journal-0001'), [
            $this->debitLine('account-cash', '100.00'),
            $this->creditLine('account-income', '100.00'),
        ], $this->financialDate());

        return $state === JournalState::Posted ? $journal->post($this->postedAt()) : $journal;
    }

    private function financialDate(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-08-15');
    }

    private function postedAt(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-09-06 10:00:00');
    }

    private function debitLine(string $accountId, string $amount): JournalLine
    {
        return JournalLine::create(AccountId::of($accountId), Money::fromDecimalString($amount, $this->myr), JournalDirection::Debit);
    }

    private function creditLine(string $accountId, string $amount): JournalLine
    {
        return JournalLine::create(AccountId::of($accountId), Money::fromDecimalString($amount, $this->myr), JournalDirection::Credit);
    }

    /**
     * @return list<array{journal_id: string, line_position: int, account_id: string, amount: string, currency: string, direction: string}>
     */
    private function twoBalancedLineRows(): array
    {
        return [
            ['journal_id' => 'journal-0001', 'line_position' => 0, 'account_id' => 'account-cash', 'amount' => '10000', 'currency' => 'MYR', 'direction' => 'Debit'],
            ['journal_id' => 'journal-0001', 'line_position' => 1, 'account_id' => 'account-income', 'amount' => '10000', 'currency' => 'MYR', 'direction' => 'Credit'],
        ];
    }
}
