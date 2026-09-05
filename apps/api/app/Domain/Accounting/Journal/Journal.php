<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Journal;

use App\Domain\Accounting\Journal\Exception\InconsistentCorrectionMetadataException;
use App\Domain\Accounting\Journal\Exception\InsufficientJournalLinesException;
use App\Domain\Accounting\Journal\Exception\InvalidReplacementTargetException;
use App\Domain\Accounting\Journal\Exception\InvalidReversalTargetException;
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
 * **Correction chain (M5, AETS-004 §16, §17).** A Journal that is a
 * Reversal or a Replacement additionally carries a {@see CorrectionType}
 * and the `JournalId` of the Journal it corrects (§6: "an ordinary
 * Journal carries none"). {@see reverse()} is the *only* way to
 * produce a Reversal — it derives every line automatically from this
 * Journal's own lines (neutralized: same Account, same exact Money,
 * opposite Direction), so `JRN-018` is guaranteed structurally, never
 * by trusting a caller-supplied line set. {@see createReplacement()}
 * is the *only* way to produce a Replacement — its lines remain
 * caller-supplied (the corrected effect is not something this class
 * can derive), but the correction-chain reference and the
 * Reversal-only target rule (`JRN-020`) are enforced here, not left to
 * a caller.
 *
 * Deliberately absent from this revision: Actor/Source/Evidence,
 * Audit Event, Outbox, persistence, repository, and any migration —
 * none of them are part of what AETS-004 §6/§7 defines a Journal's
 * construction-time shape to be.
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

    private readonly ?CorrectionType $correctionType;

    private readonly ?JournalId $correctedJournalId;

    /**
     * @param  list<JournalLine>  $lines
     */
    private function __construct(
        TenantId $tenantId,
        JournalId $id,
        array $lines,
        JournalState $state,
        ?CorrectionType $correctionType,
        ?JournalId $correctedJournalId,
    ) {
        $this->tenantId = $tenantId;
        $this->id = $id;
        $this->lines = $lines;
        $this->state = $state;
        $this->correctionType = $correctionType;
        $this->correctedJournalId = $correctedJournalId;
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
        return self::assembleValidated($tenantId, $id, $lines, JournalState::Draft, null, null);
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
     * @throws InconsistentCorrectionMetadataException if `$correctionType`
     *                                                 and `$correctedJournalId` disagree on whether this Journal
     *                                                 is a correction (M5) — one present without the other.
     */
    public static function reconstitute(
        TenantId $tenantId,
        JournalId $id,
        array $lines,
        JournalState $state,
        ?CorrectionType $correctionType = null,
        ?JournalId $correctedJournalId = null,
    ): self {
        return self::assembleValidated($tenantId, $id, $lines, $state, $correctionType, $correctedJournalId);
    }

    /**
     * The sole Draft -> Posted transition (AETS-004 §9). Returns a
     * *new* Journal instance in the Posted state; this instance is
     * completely unaffected and remains Draft. TenantId, JournalId,
     * the Journal Line list, and any correction-chain metadata (M5)
     * are carried over exactly — the same {@see JournalLine} instances,
     * same order, no line added, removed, or replaced.
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

        return new self(
            $this->tenantId,
            $this->id,
            $this->lines,
            JournalState::Posted,
            $this->correctionType,
            $this->correctedJournalId,
        );
    }

    /**
     * Produce this Journal's Reversal (AETS-004 §16) — the *only*
     * legitimate way to create one. `$newJournalId` is supplied by the
     * caller, never generated here: exactly the same convention
     * {@see create()} already establishes for a fresh identifier — no
     * self-generation policy exists in this codebase for `JournalId`
     * (AETS-004 §11), and this method does not invent one.
     *
     * **Neutrality is structural, not caller-trusted (`JRN-018`).**
     * Every line of the returned Reversal is derived automatically
     * from `$this->lines()` — same Account, same exact Money, opposite
     * Direction — in the same order. There is no parameter through
     * which a caller could supply a different line set; this is what
     * makes exact neutralization a guarantee of this method's own
     * structure, not a discipline a caller must uphold correctly.
     *
     * The returned Journal is Draft — posting it (and persisting it
     * atomically alongside its own idempotency mapping) remains a
     * separate, later concern this method has no part in, exactly as
     * {@see create()} itself never posts or persists.
     *
     * @throws InvalidReversalTargetException if this Journal is not
     *                                        currently Posted, or is itself already a correction
     *                                        (a Reversal or a Replacement).
     */
    public function reverse(JournalId $newJournalId): self
    {
        if ($this->state !== JournalState::Posted) {
            throw InvalidReversalTargetException::forNotPosted($this->id);
        }

        if ($this->correctionType !== null) {
            throw InvalidReversalTargetException::forAlreadyACorrection($this->id);
        }

        $neutralizedLines = array_map(
            static fn (JournalLine $line): JournalLine => JournalLine::create(
                $line->accountId(),
                $line->money(),
                $line->direction() === JournalDirection::Debit ? JournalDirection::Credit : JournalDirection::Debit,
            ),
            $this->lines,
        );

        return self::assembleValidated(
            $this->tenantId,
            $newJournalId,
            $neutralizedLines,
            JournalState::Draft,
            CorrectionType::Reversal,
            $this->id,
        );
    }

    /**
     * Produce a Replacement referencing `$reversal` (AETS-004 §17) —
     * the *only* legitimate way to create one. Unlike {@see reverse()},
     * a Replacement's lines are **not** derived automatically: they are
     * the caller-supplied corrected accounting effect (§17 — "the
     * corrected accounting effect"), since no domain rule can derive
     * what the *correct* amount should have been. What this method
     * does enforce, rather than leaving to a caller, is the
     * correction-chain reference itself and its one legitimate target:
     * a Replacement MUST reference a Reversal (`JRN-020`), never the
     * Original directly and never another Replacement.
     *
     * `$newJournalId` is caller-supplied, for the same reason
     * {@see reverse()}'s own docblock already states.
     *
     * @param  list<JournalLine>  $lines  The caller-supplied corrected
     *                                    Journal Lines.
     *
     * @throws InvalidReplacementTargetException if `$reversal` is not
     *                                           itself a Reversal, or is not currently Posted.
     * @throws InsufficientJournalLinesException if fewer than two
     *                                           lines are given.
     * @throws MixedCurrencyJournalException if the lines do not all
     *                                       share the same Currency.
     * @throws UnbalancedJournalException if total Debit Money does
     *                                    not exactly equal total Credit Money.
     */
    public static function createReplacement(TenantId $tenantId, JournalId $newJournalId, array $lines, self $reversal): self
    {
        if ($reversal->correctionType !== CorrectionType::Reversal) {
            throw InvalidReplacementTargetException::forNotAReversal($reversal->id);
        }

        if ($reversal->state !== JournalState::Posted) {
            throw InvalidReplacementTargetException::forReversalNotPosted($reversal->id);
        }

        return self::assembleValidated(
            $tenantId,
            $newJournalId,
            $lines,
            JournalState::Draft,
            CorrectionType::Replacement,
            $reversal->id,
        );
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
     * The kind of correction this Journal represents, or `null` if it
     * is an ordinary Journal (M5, AETS-004 §6).
     */
    public function correctionType(): ?CorrectionType
    {
        return $this->correctionType;
    }

    /**
     * The `JournalId` this Journal corrects — the Original for a
     * Reversal, or the Reversal for a Replacement — or `null` if this
     * is an ordinary Journal (M5, AETS-004 §6). Always present exactly
     * when {@see correctionType()} is non-`null`, never independently.
     */
    public function correctedJournalId(): ?JournalId
    {
        return $this->correctedJournalId;
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
     * @throws InconsistentCorrectionMetadataException
     */
    private static function assembleValidated(
        TenantId $tenantId,
        JournalId $id,
        array $lines,
        JournalState $state,
        ?CorrectionType $correctionType,
        ?JournalId $correctedJournalId,
    ): self {
        if (count($lines) < self::MINIMUM_LINE_COUNT) {
            throw InsufficientJournalLinesException::forCount(count($lines));
        }

        self::assertSingleCurrency($lines);

        if (($correctionType === null) !== ($correctedJournalId === null)) {
            throw InconsistentCorrectionMetadataException::forJournalId($id);
        }

        $journal = new self($tenantId, $id, $lines, $state, $correctionType, $correctedJournalId);

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
