<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Money\Exception;

/**
 * Thrown when an operation's result would require a negative Money or
 * MinorUnits value, which depends entirely on the still-deferred Money
 * sign policy (AETS-003 §25). This is not a claim that negative Money
 * is permanently invalid — only that this implementation does not yet
 * decide what it means, per this task's explicit instruction not to
 * resolve that policy.
 */
final class UnresolvedMoneySignPolicyException extends \InvalidArgumentException
{
    public static function forDecimalString(string $value): self
    {
        return new self(sprintf(
            'Value "%s" is a negative canonical decimal string. Money sign policy is not yet resolved; negative Money is not currently constructible.',
            $value,
        ));
    }

    public static function forSubtraction(): self
    {
        return new self(
            'This subtraction would produce a negative result. Money sign policy is not yet resolved; a negative Money result is not currently supported.',
        );
    }
}
