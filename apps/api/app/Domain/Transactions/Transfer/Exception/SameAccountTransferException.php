<?php

declare(strict_types=1);

namespace App\Domain\Transactions\Transfer\Exception;

use App\Domain\Accounting\ChartOfAccounts\AccountId;

/**
 * Thrown when a `RecordTransferCommand`'s Source Account and
 * Destination Account are the same Account (M14).
 *
 * A Transfer moving value from an Account back into itself is not a
 * transfer at all — it would produce a Posting Command with two
 * opposite-direction lines against the same Account for the same
 * amount, a self-cancelling entry that changes nothing yet still
 * consumes a Journal. This is rejected before translation, not merely
 * left to net out silently.
 */
final class SameAccountTransferException extends \RuntimeException
{
    public static function forAccount(AccountId $accountId): self
    {
        return new self(sprintf(
            'Account "%s" cannot be both the Source Account and the Destination Account of a Transfer.',
            $accountId->toString(),
        ));
    }
}
