<?php

declare(strict_types=1);

namespace App\Infrastructure\Accounting\Journal\Exception;

use App\Domain\Accounting\Journal\JournalId;
use App\Infrastructure\Accounting\Journal\JournalRepository;

/**
 * Thrown when {@see JournalRepository::save()} is given a Journal that
 * would rewrite already-persisted history: either an existing Journal
 * already Posted (terminal — AETS-004 §9, §15; `COA-014`-equivalent
 * for Journal), or an existing Draft Journal whose TenantId or exact
 * Journal Line set (Account, Money, Currency, Direction, and order)
 * disagrees with what is already persisted under the same JournalId.
 *
 * Neither is a database-constraint failure — nothing in the schema
 * forbids either write on its own. This is a repository-owned guard
 * against becoming a backdoor around domain invariants: the current
 * Journal domain exposes no line-mutation API and no Posted -> Draft
 * transition, so persistence must not invent either. The attempted
 * write never reaches the database and the existing row(s) are left
 * exactly as they were.
 */
final class ImmutableJournalStateException extends \RuntimeException
{
    public static function forField(JournalId $journalId, string $fieldName): self
    {
        return new self(sprintf(
            'Journal "%s" already exists with a different %s. '
            .'This repository does not persist changes to immutable Journal state.',
            $journalId->toString(),
            $fieldName,
        ));
    }

    public static function forPostedJournal(JournalId $journalId): self
    {
        return new self(sprintf(
            'Journal "%s" is already Posted. Posted Journal history is immutable '
            .'and cannot be persisted again through this repository.',
            $journalId->toString(),
        ));
    }
}
