<?php

declare(strict_types=1);

namespace App\Domain\Banking\Exception;

use App\Domain\Banking\BankTransactionId;

/**
 * Thrown when confirming a Match for a BankTransaction that already has
 * one (M18, SRS §10.5: "Baris sumber bank yang sama tidak boleh dipost
 * dua kali kepada peristiwa sama" — the same bank source row must never
 * be posted twice to the same event, generalized here to *any* event).
 */
final class BankTransactionAlreadyMatchedException extends \RuntimeException
{
    public static function forBankTransaction(BankTransactionId $bankTransactionId): self
    {
        return new self(sprintf(
            'BankTransaction "%s" is already matched to a Journal.',
            $bankTransactionId->toString(),
        ));
    }
}
