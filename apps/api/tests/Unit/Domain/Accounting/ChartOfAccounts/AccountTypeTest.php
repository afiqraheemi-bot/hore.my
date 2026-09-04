<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Accounting\ChartOfAccounts;

use App\Domain\Accounting\ChartOfAccounts\AccountType;
use PHPUnit\Framework\TestCase;
use ReflectionEnum;

/**
 * Covers the ATS-005 Account Type Tests relevant to {@see AccountType}
 * alone (COA-T009–COA-T015). AccountType is the only Chart of Accounts
 * concept implemented by M2-T1 — Normal Balance, Account, Account Code,
 * hierarchy, posting eligibility, and persistence do not exist yet, so
 * every test here is scoped to what a bare PHP enum can prove on its
 * own, per AETS-005 §10.
 */
final class AccountTypeTest extends TestCase
{
    /**
     * COA-T009: Asset is a valid, constructible Account Type.
     */
    public function test_asset_is_a_valid_account_type(): void
    {
        $this->assertInstanceOf(AccountType::class, AccountType::Asset);
    }

    /**
     * COA-T010: Liability is a valid, constructible Account Type.
     */
    public function test_liability_is_a_valid_account_type(): void
    {
        $this->assertInstanceOf(AccountType::class, AccountType::Liability);
    }

    /**
     * COA-T011: Equity is a valid, constructible Account Type.
     */
    public function test_equity_is_a_valid_account_type(): void
    {
        $this->assertInstanceOf(AccountType::class, AccountType::Equity);
    }

    /**
     * COA-T012: Revenue is a valid, constructible Account Type.
     */
    public function test_revenue_is_a_valid_account_type(): void
    {
        $this->assertInstanceOf(AccountType::class, AccountType::Revenue);
    }

    /**
     * COA-T013: Expense is a valid, constructible Account Type.
     */
    public function test_expense_is_a_valid_account_type(): void
    {
        $this->assertInstanceOf(AccountType::class, AccountType::Expense);
    }

    /**
     * COA-T004 / AETS-005 §10: the enum defines exactly these five
     * canonical types — no more, no fewer, in the order AETS-005 lists
     * them.
     */
    public function test_defines_exactly_the_five_canonical_types(): void
    {
        $this->assertSame(
            [
                AccountType::Asset,
                AccountType::Liability,
                AccountType::Equity,
                AccountType::Revenue,
                AccountType::Expense,
            ],
            AccountType::cases(),
        );
    }

    /**
     * COA-T014: an unsupported Account Type is rejected — for a native
     * PHP enum, this is a structural guarantee rather than a runtime
     * check. The case set is closed at compile time: no expression can
     * ever produce an `AccountType` instance outside `cases()`, so
     * "rejection" is proven by confirming the case set is exactly the
     * five canonical types (`test_defines_exactly_the_five_canonical_types`)
     * and that the enum is unbacked, so no arbitrary string/int value
     * can be coerced into one via `from()`/`tryFrom()` either (those
     * methods do not exist on an unbacked enum at all).
     */
    public function test_unbacked_enum_admits_no_unsupported_value(): void
    {
        $reflection = new ReflectionEnum(AccountType::class);

        $this->assertFalse($reflection->isBacked());
        $this->assertFalse($reflection->hasMethod('from'));
        $this->assertFalse($reflection->hasMethod('tryFrom'));
    }

    /**
     * COA-T015 (partial — scoped to what AccountType alone can prove):
     * PHP enum cases are singletons, so two references to the same
     * Account Type are always identical, never a copy that could drift.
     * This is the underlying language guarantee Account's own future
     * "Account Type is immutable once assigned" behavior will rely on;
     * the full claim (an existing Account's Type cannot change) is not
     * testable until Account itself exists — see M2-T1's report.
     */
    public function test_case_identity_is_stable(): void
    {
        $a = AccountType::Asset;
        $b = AccountType::Asset;

        $this->assertSame($a, $b);
    }

    /**
     * Architectural constraints this task requires directly: no
     * property beyond every PHP enum case's implicit `name`, no extra
     * public method beyond the enum's own built-in `cases()`, no trait,
     * and no interface.
     */
    public function test_enum_has_no_properties_methods_traits_or_interfaces(): void
    {
        $reflection = new ReflectionEnum(AccountType::class);

        $propertyNames = array_map(
            static fn (\ReflectionProperty $property): string => $property->getName(),
            $reflection->getProperties(),
        );
        $this->assertSame(['name'], $propertyNames);

        $this->assertSame([], $reflection->getTraitNames());
        // Every unbacked PHP enum implicitly implements UnitEnum — a
        // language-level guarantee, not an interface this source
        // declares. No other interface is present.
        $this->assertSame(['UnitEnum'], $reflection->getInterfaceNames());

        $builtInEnumMethods = ['cases', 'from', 'tryFrom'];
        $ownMethods = array_filter(
            $reflection->getMethods(),
            static fn (\ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === AccountType::class
                && ! in_array($method->getName(), $builtInEnumMethods, true),
        );
        $this->assertSame([], $ownMethods);
    }

    /**
     * Framework independence: no Illuminate/Eloquent dependency
     * anywhere in the file, matching the Money domain's own established
     * scan pattern.
     */
    public function test_has_no_framework_dependency(): void
    {
        $reflection = new ReflectionEnum(AccountType::class);
        $source = file_get_contents((string) $reflection->getFileName());

        $this->assertIsString($source);
        $this->assertStringNotContainsString('Illuminate\\', $source);
        $this->assertStringNotContainsString('Eloquent', $source);
    }
}
