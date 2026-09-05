<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Posting\Exception;

/**
 * Thrown when a Posting Command's own structural construction is
 * invalid — specifically, when one of its collection fields (proposed
 * Journal Lines, Evidence references) contains a member of the wrong
 * type (AETS-007 §5, §7, §11).
 *
 * This is deliberately the one exception `PostingCommand` throws for
 * every such structural violation, rather than a separate exception
 * per collection — both violations are the same kind of defect (a
 * collection member of the wrong type), and neither is a business
 * rule this class enforces beyond bare structural correctness. Every
 * other Posting Command requirement (tenant match, Account/Money/
 * balance validity, idempotency/duplicate handling, and so on) is a
 * future Posting Engine responsibility, not this exception's concern.
 */
final class InvalidPostingCommandException extends \InvalidArgumentException
{
    public static function forNonJournalLineMember(int $index): self
    {
        return new self(sprintf(
            'Proposed Journal Line at index %d is not a JournalLine instance.',
            $index,
        ));
    }

    public static function forNonStringEvidenceReference(int $index): self
    {
        return new self(sprintf(
            'Evidence reference at index %d is not a string.',
            $index,
        ));
    }
}
