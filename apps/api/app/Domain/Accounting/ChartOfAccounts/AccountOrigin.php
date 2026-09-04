<?php

declare(strict_types=1);

namespace App\Domain\Accounting\ChartOfAccounts;

/**
 * How an Account came to exist (AETS-005 §15, §16) — exactly the two
 * canonical origins AETS-005 names, nothing else:
 *
 * - **System** — created by deterministic, hore.my-owned tenant-setup
 *   logic, not directly by a user through the ordinary account-creation
 *   path (§15). Carries additional protections (§15, `COA-013`,
 *   `COA-014`, `COA-016`) — see {@see Account}'s own docblock for how
 *   those protections are structurally guaranteed today.
 * - **UserCreated** — created through the ordinary, Tenant-scoped
 *   account-creation path available to an authorized Actor (§16). Uses
 *   the same canonical Account Type/Normal Balance rules as any
 *   Account, and carries no special authority to override System
 *   Account semantics.
 *
 * Deliberately unbacked, for the same reason as {@see AccountType} and
 * {@see NormalBalance}: AETS-005 specifies no canonical persisted
 * representation, so no backing value is invented here.
 *
 * No default Chart of Accounts, Account Code numbering, or
 * country/tax-specific account semantics are introduced by this type
 * — it names provenance only.
 */
enum AccountOrigin
{
    case System;
    case UserCreated;
}
