<?php

declare(strict_types=1);

namespace App\Domain\Workspace;

/**
 * The Accounting Command an approved Proposal is translated into
 * (WTS-001 §5, TSK-003) — exactly the five business-specific Posting
 * Commands manual entry already produces (see `AppComposer.vue`'s own
 * `TransactionType` list and each corresponding `Store*Request`/
 * `*Controller` pair). A Proposal never introduces a sixth shape of
 * its own; adding a new Accounting Command type here requires the same
 * Accounting Core work manual entry would require regardless of this
 * module.
 */
enum CommandType
{
    case Expense;
    case Income;
    case Transfer;
    case CapitalContribution;
    case OwnerDrawing;

    /**
     * @throws \ValueError if `$name` is not one of this enum's own
     *                     case names — callers are expected to have
     *                     already validated it (for example, via
     *                     `StoreTaskRequest`'s `Rule::in()`).
     */
    public static function fromName(string $name): self
    {
        foreach (self::cases() as $case) {
            if ($case->name === $name) {
                return $case;
            }
        }

        throw new \ValueError(sprintf('"%s" is not a valid CommandType name.', $name));
    }
}
