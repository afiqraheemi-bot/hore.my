<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Journal;

use App\Domain\Accounting\Journal\Exception\InsufficientJournalLinesException;
use App\Domain\Accounting\Journal\Exception\MixedCurrencyJournalException;
use App\Domain\Accounting\Journal\Exception\UnbalancedJournalException;
use App\Domain\Accounting\Money\MinorUnits;
use App\Domain\Accounting\Money\Money;
use App\Domain\Shared\Tenancy\TenantId;

/**
 * The Journal aggregate root (AETS-004 §6): the record of one
 * accounting effect, owning exactly its own {@see JournalLine}s.
 *
 * This revision (M3-T5) implements construction only — a Journal is
 * assembled and fully validated in one atomic step, then handed back
 * immutable. AETS-004 §9 explicitly leaves open "whether a Draft
 * Journal is ever separately persisted before posting, or is
 * assembled transiently from a Posting Command and validated in one
 * step" as two equally valid implementation choices; this class takes
 * the second: {@see create()} is the *only* construction path, there
 * is no line-adding/removing API, and every construction invariant
 * (line count, Currency consistency, exact balance) is enforced before
 * a Journal instance can exist at all. A successfully constructed
 * Journal is therefore always Draft, always balanced, and always
 * single-Currency — there is no intermediate, partially-assembled, or
 * unbalanced state this type can ever represent.
 *
 * **Not the Posting Engine.** {@see JournalState::Posted} is a valid
 * enum case, but nothing in this class can produce one: there is no
 * `post()` method and no other path to a Posted Journal. The
 * Draft -> Posted transition — the actual Posting Command, its
 * validation pipeline (§11), idempotency (§14), Tenant/Actor/Evidence
 * checks (§18, §19), and atomic persistence (§13) — is deliberately
 * left for a future task, so posting lifecycle is not mixed into
 * aggregate construction here.
 *
 * **Balance validation, at the aggregate level, not inferred.**
 * {@see isBalanced()} sums Debit Journal Lines' Money and Credit
 * Journal Lines' Money separately — never negating a Money value to
 * fold both sides into one signed sum, since Money's exact `add`
 * (AETS-003 §11) is defined for non-negative magnitudes only (AETS-004
 * §8) — and compares the two totals for exact equality via Money's
 * own comparison (AETS-003 §11–§12), never native numeric comparison,
 * never float, never a tolerance window. Direction is read exactly as
 * each {@see JournalLine} already carries it; nothing here derives,
 * defaults, or cross-checks Direction from an Account's Account Type
 * or Normal Balance — this class has no dependency on either.
 *
 * **Currency consistency.** Every Journal Line must share exactly one
 * Currency (AETS-004 §12, `JRN-011`); a mixed-Currency Journal is
 * rejected at construction,
 * before any balance computation is even attempted (comparing or
 * summing Money of different Currency is not just wrong, it is
 * something {@see Money} itself refuses to do at all, AETS-003 §6,
 * `MON-006`).
 *
 * Deliberately absent from this revision: Reversal, Replacement,
 * Actor/Source/Evidence, Audit Event, Outbox, persistence, repository,
 * and any migration — none of them are part of what AETS-004 §6/§7
 * defines a Journal's construction-time shape to be.
 */
final class Journal
{
    /**
     * A Journal's minimum line count (AETS-004 §7, `JRN-002`) — a
     * single-sided entry cannot balance. Final for the current
     * specification baseline; no maximum is defined or invented here.
     */
    private const MINIMUM_LINE_COUNT = 2;

    private readonly TenantId $tenantId;

    private readonly JournalId $id;

    /**
     * @var list<JournalLine>
     */
    private readonly array $lines;

    private readonly JournalState $state;

    /**
     * @param  list<JournalLine>  $lines
     */
    private function __construct(TenantId $tenantId, JournalId $id, array $lines, JournalState $state)
    {
        $this->tenantId = $tenantId;
        $this->id = $id;
        $this->lines = $lines;
        $this->state = $state;
    }

    /**
     * Construct a new Journal. Always Draft — there is no parameter
     * for state, and no path to a Posted Journal from here (AETS-004
     * §9: only a successful Posting Command may produce one).
     *
     * Validates, in order, before any Journal instance is returned:
     * at least two Journal Lines (`JRN-002`); every line sharing
     * exactly one Currency (`JRN-011`); and exact Debit/Credit balance
     * (`JRN-007`) — see {@see isBalanced()}.
     *
     * @param  list<JournalLine>  $lines
     *
     * @throws InsufficientJournalLinesException if fewer than two
     *                                           lines are given.
     * @throws MixedCurrencyJournalException if the lines do not all
     *                                       share the same Currency.
     * @throws UnbalancedJournalException if total Debit Money does
     *                                    not exactly equal total Credit Money.
     */
    public static function create(TenantId $tenantId, JournalId $id, array $lines): self
    {
        if (count($lines) < self::MINIMUM_LINE_COUNT) {
            throw InsufficientJournalLinesException::forCount(count($lines));
        }

        self::assertSingleCurrency($lines);

        $journal = new self($tenantId, $id, $lines, JournalState::Draft);

        if (! $journal->isBalanced()) {
            throw UnbalancedJournalException::forDifference();
        }

        return $journal;
    }

    public function tenantId(): TenantId
    {
        return $this->tenantId;
    }

    public function id(): JournalId
    {
        return $this->id;
    }

    /**
     * This Journal's lines, exactly as constructed, in their original
     * order — the returned array is a plain PHP array (itself a value,
     * copied on return) of the same {@see JournalLine} instances,
     * which are themselves immutable; nothing about mutating the
     * returned array or inspecting a line can alter this Journal's own
     * state.
     *
     * @return list<JournalLine>
     */
    public function lines(): array
    {
        return $this->lines;
    }

    public function state(): JournalState
    {
        return $this->state;
    }

    /**
     * Whether total Debit Money exactly equals total Credit Money
     * (`JRN-007`) — computed fresh from `$this->lines` every call,
     * via Money's own exact addition and equality, never a cached
     * flag. For any Journal this class was able to construct, this
     * always returns `true`, since {@see create()} enforces the same
     * check as a construction invariant; it is exposed here because
     * this is the actual validation logic, not a separate claim about
     * it.
     */
    public function isBalanced(): bool
    {
        $currency = $this->lines[0]->money()->currency();
        $zero = Money::fromMinorUnits(MinorUnits::of('0'), $currency);

        $totalDebit = $zero;
        $totalCredit = $zero;

        foreach ($this->lines as $line) {
            if ($line->direction() === JournalDirection::Debit) {
                $totalDebit = $totalDebit->add($line->money());
            } else {
                $totalCredit = $totalCredit->add($line->money());
            }
        }

        return $totalDebit->equals($totalCredit);
    }

    /**
     * Identity equality: true iff both Journals share the same
     * {@see JournalId}. Not value equality over every field.
     */
    public function equals(self $other): bool
    {
        return $this->id->equals($other->id);
    }

    /**
     * @param  list<JournalLine>  $lines
     *
     * @throws MixedCurrencyJournalException
     */
    private static function assertSingleCurrency(array $lines): void
    {
        $firstCurrency = $lines[0]->money()->currency();

        foreach ($lines as $line) {
            if (! $line->money()->currency()->equals($firstCurrency)) {
                throw MixedCurrencyJournalException::forMismatch();
            }
        }
    }
}
