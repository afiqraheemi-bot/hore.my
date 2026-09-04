<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Journal;

use App\Domain\Accounting\Journal\Exception\InsufficientJournalLinesException;
use App\Domain\Accounting\Journal\Exception\JournalAlreadyPostedException;
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
 * **Not the Posting Engine.** This revision (M3-T6) adds
 * {@see post()}: the sole Draft -> Posted transition, as a pure
 * domain state change. It performs no I/O of any kind — no database
 * write, no transaction, no network call, no Audit Event, no Outbox
 * publish, no idempotency-key handling. Those are the actual Posting
 * Command's concerns (§10–§14, §18, §19) — the deterministic
 * validation-and-persistence orchestration, Tenant/Actor/Evidence
 * checks, and atomic effect a future Posting Engine task owns.
 * {@see post()} only represents that a Journal already known to be
 * valid has had its lifecycle state advanced; it decides nothing about
 * *whether* posting should be allowed beyond the one check this class
 * can make on its own (§9): the Journal must currently be Draft.
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
 * **Reconstitution, not persistence.** This revision (M3-T7) adds
 * {@see reconstitute()}: the domain-owned counterpart to
 * {@see create()} a future persistence adapter uses to load an
 * *existing* Journal — Draft or Posted — without forcing it through
 * `create(...)->post()`, which would misrepresent restoration of
 * already-decided state as a new posting decision. See
 * {@see reconstitute()}'s own docblock for why it still re-validates
 * every structural financial invariant rather than trusting persisted
 * data.
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
     * (`JRN-007`) — see {@see isBalanced()}. Identical to the
     * validation {@see reconstitute()} performs; only the resulting
     * state differs.
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
        return self::assembleValidated($tenantId, $id, $lines, JournalState::Draft);
    }

    /**
     * Reconstruct an already-existing Journal from previously-persisted
     * state (M3-T7) — the domain-owned counterpart to {@see create()}
     * a future persistence adapter uses to load a Journal that may
     * already be Draft or Posted, instead of forcing persistence code
     * through `create(...)->post()` to arrive at a Posted instance.
     * That would be wrong for a reason beyond convenience:
     * reconstitution restores an already-decided, already-persisted
     * fact, it is not a new business posting decision — {@see post()}
     * remains the *only* method that represents that decision, and
     * this method never calls it.
     *
     * **Persisted data is not trusted.** Unlike a typical
     * reconstitution counterpart that skips re-validating what
     * construction already checked, this method validates the exact
     * same structural financial invariants {@see create()} does —
     * at least two Journal Lines (`JRN-002`), a single shared Currency
     * (`JRN-011`), and exact Debit/Credit balance (`JRN-007`) — every
     * time. A corrupted, truncated, or otherwise malformed persisted
     * Journal Line set MUST NOT silently re-enter the Domain as valid
     * merely because it came from storage; only `$state` is restored
     * exactly as given, never re-derived or second-guessed.
     *
     * Performs no I/O of any kind — no database read, no query, no
     * network call — and produces no Audit Event, Outbox publish, or
     * idempotency-key effect. It is not a Posting Command and must
     * never be mistaken for one.
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
    public static function reconstitute(TenantId $tenantId, JournalId $id, array $lines, JournalState $state): self
    {
        return self::assembleValidated($tenantId, $id, $lines, $state);
    }

    /**
     * The sole Draft -> Posted transition (AETS-004 §9). Returns a
     * *new* Journal instance in the Posted state; this instance is
     * completely unaffected and remains Draft. TenantId, JournalId,
     * and the Journal Line list are carried over exactly — the same
     * {@see JournalLine} instances, same order, no line added,
     * removed, or replaced.
     *
     * Posted is terminal: only a Draft Journal may transition. Calling
     * this on a Journal that is already Posted throws
     * {@see JournalAlreadyPostedException} rather than silently
     * re-posting or silently accepting the call as a no-op
     * (`JRN-T024`) — there is no path back to Draft, and no path to
     * Posted a second time.
     *
     * This method performs no I/O: no persistence, no database
     * transaction, no network call, no Audit Event, no Outbox publish,
     * no idempotency-key check. Those belong to the future Posting
     * Engine, which is expected to call this method only after its own
     * validation pipeline (§11) has already succeeded.
     *
     * @throws JournalAlreadyPostedException if this Journal is already
     *                                       Posted.
     */
    public function post(): self
    {
        if ($this->state === JournalState::Posted) {
            throw JournalAlreadyPostedException::forJournal();
        }

        return new self($this->tenantId, $this->id, $this->lines, JournalState::Posted);
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
     * The shared validation-and-assembly path {@see create()} and
     * {@see reconstitute()} both funnel through — the only difference
     * between a newly created Journal and a reconstituted one is which
     * `JournalState` is supplied; every structural financial invariant
     * is enforced identically either way.
     *
     * @param  list<JournalLine>  $lines
     *
     * @throws InsufficientJournalLinesException
     * @throws MixedCurrencyJournalException
     * @throws UnbalancedJournalException
     */
    private static function assembleValidated(TenantId $tenantId, JournalId $id, array $lines, JournalState $state): self
    {
        if (count($lines) < self::MINIMUM_LINE_COUNT) {
            throw InsufficientJournalLinesException::forCount(count($lines));
        }

        self::assertSingleCurrency($lines);

        $journal = new self($tenantId, $id, $lines, $state);

        if (! $journal->isBalanced()) {
            throw UnbalancedJournalException::forDifference();
        }

        return $journal;
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
