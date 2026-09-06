<?php

declare(strict_types=1);

namespace App\Infrastructure\Accounting\Journal;

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\ChartOfAccounts\AccountOrigin;
use App\Domain\Accounting\ChartOfAccounts\AccountType;
use App\Domain\Accounting\ChartOfAccounts\Exception\InvalidAccountIdException;
use App\Domain\Accounting\Journal\CorrectionType;
use App\Domain\Accounting\Journal\Exception\InconsistentCorrectionMetadataException;
use App\Domain\Accounting\Journal\Exception\InconsistentPostedAtException;
use App\Domain\Accounting\Journal\Exception\InsufficientJournalLinesException;
use App\Domain\Accounting\Journal\Exception\InvalidJournalIdException;
use App\Domain\Accounting\Journal\Exception\MixedCurrencyJournalException;
use App\Domain\Accounting\Journal\Exception\UnbalancedJournalException;
use App\Domain\Accounting\Journal\Journal;
use App\Domain\Accounting\Journal\JournalDirection;
use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Accounting\Journal\JournalLine;
use App\Domain\Accounting\Journal\JournalState;
use App\Domain\Accounting\Money\Exception\InvalidCurrencyException;
use App\Domain\Accounting\Money\Exception\InvalidMinorUnitsException;
use App\Domain\Shared\Tenancy\Exception\InvalidTenantIdException;
use App\Domain\Shared\Tenancy\TenantId;
use App\Infrastructure\Accounting\ChartOfAccounts\AccountPersistenceAdapter;
use App\Infrastructure\Accounting\Journal\Exception\InvalidPersistedCorrectionTypeException;
use App\Infrastructure\Accounting\Journal\Exception\InvalidPersistedJournalDirectionException;
use App\Infrastructure\Accounting\Journal\Exception\InvalidPersistedJournalStateException;
use App\Infrastructure\Accounting\Money\MoneyPersistenceAdapter;

/**
 * Maps hore.my's Journal aggregate (header + Journal Lines) to and
 * from a persistence-safe representation (AETS-004 §6, §7, §9).
 *
 * This is the only code with knowledge of both the Journal domain
 * contract and the primitive shape a future storage layer would
 * persist — Journal and JournalLine themselves remain
 * persistence-agnostic, exactly as {@see AccountPersistenceAdapter}
 * already establishes for Account. It performs no I/O of its own: it
 * produces and consumes plain array shapes, leaving the actual
 * read/write to its caller. No production migration or repository
 * exists yet — this adapter defines the mapping contract those will
 * eventually rely on.
 *
 * **Header and lines, separated.** A Journal maps to two distinct
 * shapes: one header row ({@see toPersistedHeader()} — `tenant_id`,
 * `journal_id`, `state`) and a list of line rows
 * ({@see toPersistedLines()} — one per {@see JournalLine}). This
 * mirrors how a real schema would eventually store them, in two
 * tables, and keeps the write side as small as the read side
 * ({@see fromPersistedJournal()}) needs it to be — nothing here
 * invents a combined "everything in one array" shape no future schema
 * would actually use.
 *
 * **No LineId, stable positional order instead.** {@see JournalLine}
 * has no identifier of its own (deliberately — see its own docblock,
 * M3-T3) and this task does not invent one. Instead, each persisted
 * line row carries an explicit `line_position` — the line's 0-based
 * index within {@see Journal::lines()} at write time — and
 * {@see fromPersistedJournal()} sorts the supplied line rows by that
 * field before reconstructing, rather than trusting whatever order
 * they happen to arrive in (a real query result is not guaranteed to
 * preserve insertion order without an explicit `ORDER BY`). The final
 * choice of line identity/ordering for the production schema is
 * deferred to the migration task that actually creates one; this is a
 * minimal, adapter-confined stand-in for that task only.
 *
 * **Money.** Amount and Currency are mapped through
 * {@see MoneyPersistenceAdapter} exactly as it already exists — an
 * exact integer minor-units string plus an explicit Currency
 * identifier, never a float, never a signed amount, and never a
 * value inferred from Direction. {@see JournalDirection} is mapped
 * entirely separately, alongside the line, never folded into the
 * Money value the way a signed-amount representation would (AETS-004
 * §8: Money remains a non-negative magnitude; the sign is Direction).
 *
 * **State and Direction persistence.** AETS-004 does not lock a
 * database representation for either Journal state (§9) or Direction
 * (§8). Rather than convert either domain enum into a backed enum
 * merely to support persistence, this adapter privately translates
 * each to and from its own PHP enum case name
 * (`JournalState::Draft->name === 'Draft'`,
 * `JournalDirection::Debit->name === 'Debit'`, and so on) — the same
 * deterministic, self-describing pattern already established for
 * {@see AccountType}/{@see AccountOrigin}.
 * Both translations are confined entirely to this adapter; neither
 * domain enum is backed or aware of either translation.
 *
 * **Read path never trusts persisted data.** {@see fromPersistedJournal()}
 * reconstructs every value through its own Value Object's validated
 * factory (`TenantId::of()`, `JournalId::of()`, `AccountId::of()`,
 * {@see MoneyPersistenceAdapter::fromPersisted()}) and this adapter's
 * own state/direction translation, then reconstructs the Journal
 * itself through {@see Journal::reconstitute()} — never
 * `create(...)->post()`, which would misrepresent restoration of
 * already-decided state as a new posting decision. Because
 * `reconstitute()` re-validates every structural financial invariant
 * (minimum two lines, single Currency, exact balance), a corrupted or
 * truncated persisted Journal — even one whose row claims to already
 * be Posted — cannot silently re-enter the Domain as valid.
 */
final class JournalPersistenceAdapter
{
    private readonly MoneyPersistenceAdapter $money;

    public function __construct()
    {
        $this->money = new MoneyPersistenceAdapter;
    }

    /**
     * Extract a Journal's persistence-safe header representation,
     * including its correction-chain (M5) — `null` for both fields on
     * an ordinary Journal, both present together for a Reversal or
     * Replacement, exactly as {@see Journal}'s own domain invariant
     * already guarantees.
     *
     * @return array{tenant_id: string, journal_id: string, state: string, correction_type: string|null, corrected_journal_id: string|null, financial_date: string, posted_at: string|null}
     */
    public function toPersistedHeader(Journal $journal): array
    {
        return [
            'tenant_id' => $journal->tenantId()->toString(),
            'journal_id' => $journal->id()->toString(),
            'state' => self::toPersistedJournalState($journal->state()),
            'correction_type' => $journal->correctionType() === null
                ? null
                : self::toPersistedCorrectionType($journal->correctionType()),
            'corrected_journal_id' => $journal->correctedJournalId()?->toString(),
            'financial_date' => $journal->financialDate()->format('Y-m-d'),
            'posted_at' => $journal->postedAt()?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Extract a Journal's Lines as persistence-safe rows, one per
     * line, in their exact original order — `line_position` is that
     * order made explicit, since {@see JournalLine} carries no
     * identifier of its own.
     *
     * @return list<array{journal_id: string, line_position: int, account_id: string, amount: string, currency: string, direction: string}>
     */
    public function toPersistedLines(Journal $journal): array
    {
        $journalId = $journal->id()->toString();
        $rows = [];

        foreach ($journal->lines() as $position => $line) {
            $rows[] = [
                'journal_id' => $journalId,
                'line_position' => $position,
                'account_id' => $line->accountId()->toString(),
                'amount' => $this->money->toPersistedAmount($line->money()),
                'currency' => $this->money->toPersistedCurrency($line->money()),
                'direction' => self::toPersistedJournalDirection($line->direction()),
            ];
        }

        return $rows;
    }

    /**
     * Reconstruct a Journal from a persisted header row and its line
     * rows, through {@see Journal::reconstitute()} and every Value
     * Object's own validated factory — a raw database value is never
     * treated as trusted domain state. Line rows are sorted by
     * `line_position` before reconstruction, regardless of the order
     * they are supplied in.
     *
     * @param  array{tenant_id: string, journal_id: string, state: string, correction_type: string|null, corrected_journal_id: string|null, financial_date: string, posted_at: string|null}  $header
     * @param  list<array{journal_id: string, line_position: int, account_id: string, amount: string, currency: string, direction: string}>  $lines
     *
     * @throws InvalidTenantIdException if `tenant_id` is not canonical.
     * @throws InvalidJournalIdException if `journal_id` or `corrected_journal_id` is not canonical.
     * @throws InvalidAccountIdException if a line's `account_id` is not canonical.
     * @throws InvalidMinorUnitsException if a line's `amount` is not a canonical non-negative integer numeral.
     * @throws InvalidCurrencyException if a line's `currency` is not a supported canonical identifier.
     * @throws InvalidPersistedJournalStateException if `state` is not a canonical Journal state.
     * @throws InvalidPersistedCorrectionTypeException if `correction_type` is not a canonical CorrectionType.
     * @throws InvalidPersistedJournalDirectionException if a line's `direction` is not a canonical Direction.
     * @throws InsufficientJournalLinesException if fewer than two lines are given.
     * @throws MixedCurrencyJournalException if the lines do not all share the same Currency.
     * @throws UnbalancedJournalException if total Debit Money does not exactly equal total Credit Money.
     * @throws InconsistentCorrectionMetadataException if `correction_type` and `corrected_journal_id` disagree on whether this Journal is a correction.
     * @throws InconsistentPostedAtException if `state` and `posted_at` disagree (M8).
     */
    public function fromPersistedJournal(array $header, array $lines): Journal
    {
        $tenantId = TenantId::of($header['tenant_id']);
        $journalId = JournalId::of($header['journal_id']);
        $state = self::fromPersistedJournalState($header['state']);
        $correctionType = $header['correction_type'] === null
            ? null
            : self::fromPersistedCorrectionType($header['correction_type']);
        $correctedJournalId = $header['corrected_journal_id'] === null
            ? null
            : JournalId::of($header['corrected_journal_id']);
        $financialDate = new \DateTimeImmutable($header['financial_date']);
        $postedAt = $header['posted_at'] === null
            ? null
            : new \DateTimeImmutable($header['posted_at']);

        $orderedLines = $lines;
        usort($orderedLines, static fn (array $a, array $b): int => $a['line_position'] <=> $b['line_position']);

        $journalLines = array_map(
            fn (array $row): JournalLine => JournalLine::create(
                AccountId::of($row['account_id']),
                $this->money->fromPersisted($row['amount'], $row['currency']),
                self::fromPersistedJournalDirection($row['direction']),
            ),
            $orderedLines,
        );

        return Journal::reconstitute($tenantId, $journalId, $journalLines, $state, $financialDate, $correctionType, $correctedJournalId, $postedAt);
    }

    private static function toPersistedJournalState(JournalState $state): string
    {
        return $state->name;
    }

    private static function fromPersistedJournalState(string $value): JournalState
    {
        foreach (JournalState::cases() as $case) {
            if ($case->name === $value) {
                return $case;
            }
        }

        throw InvalidPersistedJournalStateException::forValue($value);
    }

    private static function toPersistedCorrectionType(CorrectionType $type): string
    {
        return $type->name;
    }

    private static function fromPersistedCorrectionType(string $value): CorrectionType
    {
        foreach (CorrectionType::cases() as $case) {
            if ($case->name === $value) {
                return $case;
            }
        }

        throw InvalidPersistedCorrectionTypeException::forValue($value);
    }

    private static function toPersistedJournalDirection(JournalDirection $direction): string
    {
        return $direction->name;
    }

    private static function fromPersistedJournalDirection(string $value): JournalDirection
    {
        foreach (JournalDirection::cases() as $case) {
            if ($case->name === $value) {
                return $case;
            }
        }

        throw InvalidPersistedJournalDirectionException::forValue($value);
    }
}
