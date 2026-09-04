<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Journal;

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\ChartOfAccounts\NormalBalance;
use App\Domain\Accounting\Money\Money;

/**
 * A single line of a Journal (AETS-004 §7, §8): exactly one Account
 * reference, exactly one Money amount, and exactly one Direction —
 * never more, never fewer of any of the three, and never both
 * directions or neither.
 *
 * **Magnitude-with-direction, never signed Money.** `$money` is
 * always the non-negative magnitude {@see Money} itself already
 * guarantees at construction (AETS-003 §9; Money rejects a negative
 * decimal string or MinorUnits value outright) — this class performs
 * no additional sign validation because none is needed, and it never
 * negates, signs, or otherwise embeds polarity into the Money value.
 * The accounting sign is carried entirely by `$direction`
 * ({@see JournalDirection}), exactly as AETS-004 §8 requires.
 *
 * **Direction is never inferred.** `$direction` is a required
 * constructor argument, always exactly what the caller supplies —
 * this class contains no logic that derives, defaults, or cross-checks
 * it from an Account's Account Type, an Account's Normal Balance, or
 * the sign of `$money` (there is none to inspect, since Money is never
 * signed). {@see JournalDirection}'s own docblock explains why it is a
 * distinct type from {@see NormalBalance},
 * never reused or converted; this class does not weaken that
 * separation by adding a conversion of its own.
 *
 * **Deliberately minimal.** Exposes only construction and the three
 * accessors AETS-004 §7 needs (`accountId()`, `money()`,
 * `direction()`), plus identity-free value equality. No
 * `isDebit()`/`isCredit()`/`signedAmount()`/`applyNormalBalance()` or
 * similar convenience method exists — deriving a boolean, a signed
 * number, or a Normal-Balance-relative answer from a Direction is a
 * concern for whatever future code actually needs it (balance
 * validation, a Posting Engine), not for this Value Object. No
 * mutation API exists either: every property is immutable for the
 * line's lifetime, matching AETS-004 §5's "Journal Line's Money is
 * immutable" requirement (`JRN-T010`).
 *
 * Line ordering/numbering, memo, description, other metadata, Tenant,
 * and {@see JournalId} are all deliberately absent — none of them are
 * part of what AETS-004 §7 defines a Journal Line to be at the
 * construction level this task implements; they remain a concern for
 * whatever future Journal aggregate or Posting Engine needs them.
 */
final class JournalLine
{
    private readonly AccountId $accountId;

    private readonly Money $money;

    private readonly JournalDirection $direction;

    private function __construct(AccountId $accountId, Money $money, JournalDirection $direction)
    {
        $this->accountId = $accountId;
        $this->money = $money;
        $this->direction = $direction;
    }

    /**
     * Construct a JournalLine from an Account reference, a Money
     * magnitude, and a Direction — all three required, none inferred,
     * none defaulted.
     */
    public static function create(AccountId $accountId, Money $money, JournalDirection $direction): self
    {
        return new self($accountId, $money, $direction);
    }

    public function accountId(): AccountId
    {
        return $this->accountId;
    }

    public function money(): Money
    {
        return $this->money;
    }

    public function direction(): JournalDirection
    {
        return $this->direction;
    }

    /**
     * Value equality: true iff the same Account reference, the same
     * exact Money (amount and Currency), and the same Direction.
     */
    public function equals(self $other): bool
    {
        return $this->accountId->equals($other->accountId)
            && $this->money->equals($other->money)
            && $this->direction === $other->direction;
    }
}
