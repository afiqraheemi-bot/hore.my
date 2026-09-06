<?php

declare(strict_types=1);

namespace App\Infrastructure\Accounting\Reporting;

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\ChartOfAccounts\AccountType;
use App\Domain\Accounting\Journal\JournalDirection;
use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Accounting\Money\Currency;
use App\Domain\Accounting\Money\MinorUnits;
use App\Domain\Accounting\Money\Money;
use App\Domain\Accounting\Period\PeriodClosingService;
use App\Domain\Accounting\Reporting\AccountBalance;
use App\Domain\Accounting\Reporting\NetBalance;
use App\Domain\Shared\Tenancy\TenantId;
use App\Infrastructure\Accounting\ChartOfAccounts\Exception\InvalidPersistedAccountTypeException;
use App\Infrastructure\Accounting\Money\MoneyPersistenceAdapter;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;

/**
 * The shared SQL-aggregation primitive every report in
 * {@see App\Domain\Accounting\Reporting} is built from (AETS-009 §5):
 * every Account belonging to a Tenant, together with its cumulative
 * total Debit Money and total Credit Money summed across every
 * Journal Line of every Posted Journal whose `financial_date` falls
 * on or before `$financialDateTo` (and, where supplied, on or after
 * `$financialDateFrom`) — never a Draft Journal, never another
 * Tenant's data.
 *
 * **An Account with zero contributing lines still appears**, with a
 * zero total Debit and zero total Credit — {@see TrialBalance} (SRS
 * RPT-004) relies on this to list the full Chart of Accounts, not only
 * Accounts with activity.
 *
 * **Aggregated in PHP, not SQL `GROUP BY`.** At the transaction volume
 * this system's solopreneur Tenants are expected to produce, fetching
 * every matching Journal Line row and summing them via {@see Money}'s
 * own exact arithmetic is simpler to read, simpler to test, and avoids
 * ever computing a monetary total through anything other than Money's
 * own guarded operations (AETS-009 §5) — a native SQL `SUM()` would
 * return a raw numeric string this class would then have to re-validate
 * through {@see MoneyPersistenceAdapter} anyway, with no correctness
 * benefit over summing there directly. Revisiting this for a real,
 * measured performance problem at a materially larger scale remains an
 * explicitly deferred, not yet needed, optimization (AETS-009 §2.2).
 */
final class AccountBalanceAggregator
{
    private const ACCOUNT_TABLE = 'accounts';

    private const JOURNAL_TABLE = 'journals';

    private const LINE_TABLE = 'journal_lines';

    private const POSTED_STATE = 'Posted';

    private readonly MoneyPersistenceAdapter $money;

    public function __construct(
        private readonly ConnectionInterface $connection,
    ) {
        $this->money = new MoneyPersistenceAdapter;
    }

    /**
     * @param  list<AccountType>  $accountTypes  `[]` means every Account Type.
     * @param  JournalId|null  $excludeJournalId  Excludes one specific
     *                                            Journal from the aggregation entirely — used exclusively by
     *                                            {@see PeriodClosingService} to
     *                                            exclude a Period-closing Journal from its own "what needs to
     *                                            be closed" computation (its own docblock explains why); every
     *                                            other caller passes `null` and this parameter has no effect.
     * @return list<AccountBalance>
     *
     * @throws InvalidPersistedAccountTypeException if a persisted `account_type` value is not canonical.
     */
    public function aggregate(
        TenantId $tenantId,
        ?\DateTimeImmutable $financialDateFrom,
        \DateTimeImmutable $financialDateTo,
        array $accountTypes = [],
        ?JournalId $excludeJournalId = null,
    ): array {
        $accountRows = $this->fetchAccounts($tenantId, $accountTypes);
        $lineRows = $this->fetchPostedLines($tenantId, $financialDateFrom, $financialDateTo, null, $excludeJournalId);

        /** @var object{currency: string}|null $firstLine */
        $firstLine = $lineRows->first();
        $currency = $this->resolveCurrency($firstLine?->currency);
        $zero = Money::fromMinorUnits(MinorUnits::of('0'), $currency);

        /** @var array<string, array{debit: Money, credit: Money}> $totals */
        $totals = [];

        foreach ($lineRows as $row) {
            $totals[$row->account_id] ??= ['debit' => $zero, 'credit' => $zero];
            $amount = $this->money->fromPersisted((string) $row->amount, $row->currency);

            if ($row->direction === JournalDirection::Debit->name) {
                $totals[$row->account_id]['debit'] = $totals[$row->account_id]['debit']->add($amount);
            } else {
                $totals[$row->account_id]['credit'] = $totals[$row->account_id]['credit']->add($amount);
            }
        }

        $balances = [];

        foreach ($accountRows as $accountRow) {
            $totalsForAccount = $totals[$accountRow->account_id] ?? ['debit' => $zero, 'credit' => $zero];

            $balances[] = new AccountBalance(
                AccountId::of($accountRow->account_id),
                self::fromPersistedAccountType($accountRow->account_type),
                $totalsForAccount['debit'],
                $totalsForAccount['credit'],
            );
        }

        return $balances;
    }

    /**
     * A single Account's {@see NetBalance}
     * as of a given date (or bounded by `$financialDateFrom` too) —
     * used by {@see GeneralLedgerQuery} for an opening/closing balance,
     * where a single-Account result is needed rather than the full
     * Chart of Accounts {@see aggregate()} returns.
     */
    public function netBalanceForAccount(
        TenantId $tenantId,
        AccountId $accountId,
        ?\DateTimeImmutable $financialDateFrom,
        \DateTimeImmutable $financialDateTo,
    ): NetBalance {
        $lineRows = $this->fetchPostedLines($tenantId, $financialDateFrom, $financialDateTo, $accountId, null);
        /** @var object{currency: string}|null $firstLine */
        $firstLine = $lineRows->first();
        $currency = $this->resolveCurrency($firstLine?->currency);
        $zero = Money::fromMinorUnits(MinorUnits::of('0'), $currency);

        $totalDebit = $zero;
        $totalCredit = $zero;

        foreach ($lineRows as $row) {
            $amount = $this->money->fromPersisted((string) $row->amount, $row->currency);

            if ($row->direction === JournalDirection::Debit->name) {
                $totalDebit = $totalDebit->add($amount);
            } else {
                $totalCredit = $totalCredit->add($amount);
            }
        }

        return NetBalance::fromDebitCredit($totalDebit, $totalCredit);
    }

    /**
     * @param  list<AccountType>  $accountTypes
     * @return Collection<int, object{account_id: string, account_type: string}>
     */
    private function fetchAccounts(TenantId $tenantId, array $accountTypes): Collection
    {
        $query = $this->connection->table(self::ACCOUNT_TABLE)
            ->where('tenant_id', $tenantId->toString());

        if ($accountTypes !== []) {
            $query->whereIn('account_type', array_map(
                static fn (AccountType $type): string => $type->name,
                $accountTypes,
            ));
        }

        /** @var Collection<int, object{account_id: string, account_type: string}> $rows */
        $rows = $query->get(['account_id', 'account_type']);

        return $rows;
    }

    /**
     * @return Collection<int, object{account_id: string, direction: string, amount: int|string, currency: string}>
     */
    private function fetchPostedLines(TenantId $tenantId, ?\DateTimeImmutable $financialDateFrom, \DateTimeImmutable $financialDateTo, ?AccountId $accountId, ?JournalId $excludeJournalId): Collection
    {
        $query = $this->connection->table(self::LINE_TABLE)
            ->join(self::JOURNAL_TABLE, function ($join): void {
                $join->on(self::LINE_TABLE.'.tenant_id', '=', self::JOURNAL_TABLE.'.tenant_id')
                    ->on(self::LINE_TABLE.'.journal_id', '=', self::JOURNAL_TABLE.'.journal_id');
            })
            ->where(self::JOURNAL_TABLE.'.tenant_id', $tenantId->toString())
            ->where(self::JOURNAL_TABLE.'.state', self::POSTED_STATE)
            ->where(self::JOURNAL_TABLE.'.financial_date', '<=', $financialDateTo->format('Y-m-d'));

        if ($financialDateFrom !== null) {
            $query->where(self::JOURNAL_TABLE.'.financial_date', '>=', $financialDateFrom->format('Y-m-d'));
        }

        if ($accountId !== null) {
            $query->where(self::LINE_TABLE.'.account_id', $accountId->toString());
        }

        if ($excludeJournalId !== null) {
            $query->where(self::JOURNAL_TABLE.'.journal_id', '!=', $excludeJournalId->toString());
        }

        /** @var Collection<int, object{account_id: string, direction: string, amount: int|string, currency: string}> $rows */
        $rows = $query->get([
            self::LINE_TABLE.'.account_id',
            self::LINE_TABLE.'.direction',
            self::LINE_TABLE.'.amount',
            self::LINE_TABLE.'.currency',
        ]);

        return $rows;
    }

    /**
     * No Posted Journal Line exists yet for this Tenant in the
     * requested range when `$firstLineCurrency` is `null` — `'MYR'` is
     * the only Currency this codebase's registry currently issues
     * (AETS-003 §6), so it is the only legitimate choice for an
     * otherwise-empty aggregate's zero Money values, not a policy this
     * class invents.
     */
    private function resolveCurrency(?string $firstLineCurrency): Currency
    {
        return $firstLineCurrency === null ? Currency::of('MYR') : Currency::of($firstLineCurrency);
    }

    private static function fromPersistedAccountType(string $value): AccountType
    {
        foreach (AccountType::cases() as $case) {
            if ($case->name === $value) {
                return $case;
            }
        }

        throw InvalidPersistedAccountTypeException::forValue($value);
    }
}
