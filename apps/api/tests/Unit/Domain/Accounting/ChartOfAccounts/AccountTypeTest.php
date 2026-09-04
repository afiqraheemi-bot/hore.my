<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Accounting\ChartOfAccounts;

use App\Domain\Accounting\ChartOfAccounts\AccountType;
use App\Domain\Accounting\ChartOfAccounts\NormalBalance;
use PHPUnit\Framework\TestCase;
use ReflectionEnum;

/**
 * Covers the ATS-005 Account Type Tests and Normal Balance Tests
 * relevant to {@see AccountType} alone (COA-T009–COA-T022 — the
 * `AccountType` → `NormalBalance` mapping is owned by
 * {@see AccountType::normalBalance()}, so its tests live here rather
 * than in {@see NormalBalanceTest}). Account, Account Code, hierarchy,
 * posting eligibility, account status, and persistence do not exist
 * yet, so every test here is scoped to what the two bare enums and
 * their mapping method can prove on their own, per AETS-005 §10–§11.
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
     * property beyond every PHP enum case's implicit `name`, no own
     * method beyond the single required {@see AccountType::normalBalance()}
     * (plus the enum's own built-in `cases()`), no trait, and no
     * interface.
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
        $ownMethodNames = array_values(array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            array_filter(
                $reflection->getMethods(),
                static fn (\ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === AccountType::class
                    && ! in_array($method->getName(), $builtInEnumMethods, true),
            ),
        ));
        $this->assertSame(['normalBalance'], $ownMethodNames);
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

    /**
     * COA-T016: Asset -> Debit.
     */
    public function test_asset_maps_to_debit(): void
    {
        $this->assertSame(NormalBalance::Debit, AccountType::Asset->normalBalance());
    }

    /**
     * COA-T017: Expense -> Debit.
     */
    public function test_expense_maps_to_debit(): void
    {
        $this->assertSame(NormalBalance::Debit, AccountType::Expense->normalBalance());
    }

    /**
     * COA-T018: Liability -> Credit.
     */
    public function test_liability_maps_to_credit(): void
    {
        $this->assertSame(NormalBalance::Credit, AccountType::Liability->normalBalance());
    }

    /**
     * COA-T019: Equity -> Credit.
     */
    public function test_equity_maps_to_credit(): void
    {
        $this->assertSame(NormalBalance::Credit, AccountType::Equity->normalBalance());
    }

    /**
     * COA-T020: Revenue -> Credit.
     */
    public function test_revenue_maps_to_credit(): void
    {
        $this->assertSame(NormalBalance::Credit, AccountType::Revenue->normalBalance());
    }

    /**
     * Every AccountType MUST map to exactly one NormalBalance
     * (`COA-005`) — swept across every case, not just the five
     * hand-picked examples above.
     */
    public function test_every_account_type_maps_to_exactly_one_normal_balance(): void
    {
        foreach (AccountType::cases() as $accountType) {
            $normalBalance = $accountType->normalBalance();

            $this->assertInstanceOf(NormalBalance::class, $normalBalance);
        }
    }

    /**
     * The mapping is deterministic: calling `normalBalance()` on the
     * same case, any number of times, always returns the identical
     * NormalBalance instance.
     */
    public function test_mapping_is_deterministic(): void
    {
        $first = AccountType::Asset->normalBalance();
        $second = AccountType::Asset->normalBalance();

        $this->assertSame($first, $second);
    }

    /**
     * COA-T021 / COA-T022: Normal Balance cannot be independently
     * overridden, and cannot consult, infer, or influence a Journal
     * Line's Direction — both proven by the same structural fact.
     * `normalBalance()` takes no parameters, so its result depends
     * solely on `$this` (the AccountType case itself) and returns only
     * a {@see NormalBalance}, never a Journal-related type; combined
     * with AccountType declaring no property
     * (`test_enum_has_no_properties_methods_traits_or_interfaces`),
     * there is no way to construct an AccountType/NormalBalance pairing
     * other than through this method's fixed `match` expression, and no
     * surface through which any Journal Line Direction could reach it
     * (AETS-004 §8, not implemented here).
     */
    public function test_normal_balance_cannot_be_independently_overridden(): void
    {
        $reflection = new ReflectionEnum(AccountType::class);
        $method = $reflection->getMethod('normalBalance');

        $this->assertCount(0, $method->getParameters());
        $this->assertSame(NormalBalance::class, (string) $method->getReturnType());
    }
}
