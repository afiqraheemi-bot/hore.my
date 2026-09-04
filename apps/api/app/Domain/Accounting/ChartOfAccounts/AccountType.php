<?php

declare(strict_types=1);

namespace App\Domain\Accounting\ChartOfAccounts;

/**
 * The five canonical double-entry Account Types (AETS-005 §10, `COA-004`)
 * — the minimum categories double-entry accounting requires. No
 * sub-types are introduced (no "Current Asset" vs. "Fixed Asset"
 * distinction); this enum represents exactly these five categories and
 * nothing else.
 *
 * Deliberately unbacked: AETS-005 does not specify a canonical string
 * or persisted representation for Account Type (that is a still-future
 * persistence-mapping decision), so no backing value is invented here.
 * PHP's own enum semantics already make every case immutable and
 * closed to unsupported values without further code — no property, no
 * method, no trait, and no interface is added beyond that guarantee.
 *
 * Normal Balance, Account, Account Code, hierarchy, posting rules, and
 * persistence are all deliberately out of scope here — see AETS-005
 * §11 onward and M2-T1's report.
 */
enum AccountType
{
    case Asset;
    case Liability;
    case Equity;
    case Revenue;
    case Expense;
}
