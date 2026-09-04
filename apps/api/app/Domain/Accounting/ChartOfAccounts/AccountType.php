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
 * trait, and no interface is added beyond that guarantee. The one
 * method this enum declares, {@see normalBalance()}, exists because
 * AETS-005 §11 requires Normal Balance to be derived exclusively from
 * Account Type, never independently settable — a `match` expression
 * here is the only way to guarantee that.
 *
 * Account, Account Code, hierarchy, posting rules, and persistence are
 * all deliberately out of scope here — see AETS-005 §12 onward and
 * M2-T1/M2-T2's reports.
 */
enum AccountType
{
    case Asset;
    case Liability;
    case Equity;
    case Revenue;
    case Expense;

    /**
     * The canonical Normal Balance for this Account Type (AETS-005
     * §11, `COA-005`) — Asset/Expense → Debit, Liability/Equity/Revenue
     * → Credit. Always exactly one value per Account Type; there is no
     * way to construct an AccountType/NormalBalance pairing other than
     * through this method, so no inconsistent pairing can exist.
     *
     * This has no relationship to, and never determines, a Journal
     * Line's own explicit Direction (AETS-004 §8; AETS-005 §11) — it
     * classifies the Account, nothing else.
     */
    public function normalBalance(): NormalBalance
    {
        return match ($this) {
            self::Asset, self::Expense => NormalBalance::Debit,
            self::Liability, self::Equity, self::Revenue => NormalBalance::Credit,
        };
    }
}
