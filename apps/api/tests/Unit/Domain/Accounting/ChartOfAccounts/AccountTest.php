<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Accounting\ChartOfAccounts;

use App\Domain\Accounting\ChartOfAccounts\Account;
use App\Domain\Accounting\ChartOfAccounts\AccountCode;
use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\ChartOfAccounts\AccountName;
use App\Domain\Accounting\ChartOfAccounts\AccountOrigin;
use App\Domain\Accounting\ChartOfAccounts\AccountType;
use App\Domain\Accounting\ChartOfAccounts\Exception\InvalidAccountHierarchyException;
use App\Domain\Accounting\ChartOfAccounts\NormalBalance;
use App\Domain\Shared\Tenancy\TenantId;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Covers the Account-level ATS-005 cases this revision (M2-T7)
 * supports, now that {@see AccountOrigin} (§15, §16) exists.
 * `COA-T001` is fully coverable (a Tenant is present). `COA-T002`'s
 * "immutable... for its lifetime" claim is still only partially
 * provable — no persistence exists yet to prove survival across a
 * write/read cycle. Indirect/transitive cycle detection (`COA-T035`,
 * `COA-T036`) is covered separately in
 * {@see AccountHierarchyPolicyTest},
 * since it requires graph context this Value Object alone cannot
 * provide. System Account protections (`COA-T048`–`COA-T052`) are
 * proven structurally here, per this class's own docblock, since the
 * current public API has no delete/change-Tenant/change-Type/
 * change-Code method for *any* Account to attempt in the first place.
 * `activate()`/`rename()`/code or type changes and persistence remain
 * out of scope — see M2-T7's report.
 */
final class AccountTest extends TestCase
{
    private function validTenantId(): TenantId
    {
        return TenantId::of('tenant-0001');
    }

    private function validId(): AccountId
    {
        return AccountId::of('account-0001');
    }

    private function validCode(): AccountCode
    {
        return AccountCode::of('1000');
    }

    private function validName(): AccountName
    {
        return AccountName::of('Cash / Bank');
    }

    /**
     * `AccountOrigin::UserCreated` is this test helper's own
     * convenience default (test scaffolding only) — {@see Account::create()}
     * itself has no default and requires the caller to state it
     * explicitly every time; that requirement is proven directly by
     * `test_every_create_parameter_is_required()`.
     */
    private function createAccount(
        ?TenantId $tenantId = null,
        ?AccountId $id = null,
        ?AccountCode $code = null,
        ?AccountName $name = null,
        AccountType $type = AccountType::Asset,
        bool $isPostingEligible = true,
        AccountOrigin $origin = AccountOrigin::UserCreated,
    ): Account {
        return Account::create(
            $tenantId ?? $this->validTenantId(),
            $id ?? $this->validId(),
            $code ?? $this->validCode(),
            $name ?? $this->validName(),
            $type,
            $isPostingEligible,
            $origin,
        );
    }

    /**
     * COA-T001: an Account can be validly constructed with a Tenant,
     * Account Code, Name, Account Type, and posting-eligibility state
     * all present.
     */
    public function test_valid_account_constructs_successfully(): void
    {
        $account = $this->createAccount();

        $this->assertInstanceOf(Account::class, $account);
    }

    /**
     * Explicit Tenant ownership: the Account reports exactly the
     * Tenant it was constructed with.
     */
    public function test_tenant_id_returns_the_constructed_tenant(): void
    {
        $tenantId = $this->validTenantId();

        $account = $this->createAccount(tenantId: $tenantId);

        $this->assertTrue($tenantId->equals($account->tenantId()));
    }

    /**
     * Stable AccountId: the Account reports exactly the identifier it
     * was constructed with.
     */
    public function test_id_returns_the_constructed_identifier(): void
    {
        $id = $this->validId();

        $account = $this->createAccount(id: $id);

        $this->assertTrue($id->equals($account->id()));
    }

    /**
     * Required AccountCode: the Account reports exactly the Code it
     * was constructed with.
     */
    public function test_code_returns_the_constructed_code(): void
    {
        $code = $this->validCode();

        $account = $this->createAccount(code: $code);

        $this->assertTrue($code->equals($account->code()));
    }

    /**
     * Required AccountName: the Account reports exactly the Name it
     * was constructed with.
     */
    public function test_name_returns_the_constructed_name(): void
    {
        $name = $this->validName();

        $account = $this->createAccount(name: $name);

        $this->assertTrue($name->equals($account->name()));
    }

    public function test_type_returns_the_constructed_type(): void
    {
        $account = $this->createAccount(type: AccountType::Liability);

        $this->assertSame(AccountType::Liability, $account->type());
    }

    /**
     * COA-T005: Normal Balance is exactly the canonical value the
     * Account Type derives — proven across every Account Type.
     */
    public function test_normal_balance_is_derived_from_type(): void
    {
        foreach (AccountType::cases() as $type) {
            $account = $this->createAccount(type: $type);

            $this->assertSame($type->normalBalance(), $account->normalBalance());
        }
    }

    /**
     * COA-T005: Normal Balance cannot be independently supplied —
     * proven structurally, since `create()` declares no Normal Balance
     * parameter at all.
     */
    public function test_normal_balance_cannot_be_independently_supplied(): void
    {
        $reflection = new ReflectionClass(Account::class);
        $method = $reflection->getMethod('create');

        $parameterTypes = array_map(
            static fn (\ReflectionParameter $parameter): ?string => $parameter->getType() instanceof \ReflectionNamedType
                ? $parameter->getType()->getName()
                : null,
            $method->getParameters(),
        );

        $this->assertNotContains(NormalBalance::class, $parameterTypes);
    }

    /**
     * Every `create()` parameter is required: Tenant, identifier,
     * Code, Name, Account Type, posting-eligibility state, and Origin
     * each have no default value, so none can be omitted or left
     * undefined (`COA-T004`, `COA-T006`, plus explicit Tenant/Code/
     * Name/Origin presence) — Origin is never silently assumed as
     * System or UserCreated (M2-T7).
     */
    public function test_every_create_parameter_is_required(): void
    {
        $reflection = new ReflectionClass(Account::class);
        $method = $reflection->getMethod('create');

        $this->assertCount(7, $method->getParameters());

        foreach ($method->getParameters() as $parameter) {
            $this->assertFalse(
                $parameter->isOptional(),
                sprintf('Parameter "%s" must be required.', $parameter->getName()),
            );
            $this->assertFalse(
                $parameter->isDefaultValueAvailable(),
                sprintf('Parameter "%s" must have no default value.', $parameter->getName()),
            );
        }

        $parameterTypes = array_map(
            static fn (\ReflectionParameter $parameter): ?string => $parameter->getType() instanceof \ReflectionNamedType
                ? $parameter->getType()->getName()
                : null,
            $method->getParameters(),
        );

        $this->assertSame(
            [TenantId::class, AccountId::class, AccountCode::class, AccountName::class, AccountType::class, 'bool', AccountOrigin::class],
            $parameterTypes,
        );
    }

    /**
     * A new Account is always Active at creation.
     */
    public function test_new_account_is_active(): void
    {
        $account = $this->createAccount();

        $this->assertTrue($account->isActive());
    }

    /**
     * `create()`'s own semantics are unchanged by `reconstitute()`'s
     * existence: a genuinely new Account still always starts Active
     * and parentless.
     */
    public function test_create_still_always_produces_an_active_parentless_account(): void
    {
        $account = $this->createAccount();

        $this->assertTrue($account->isActive());
        $this->assertNull($account->parentId());
    }

    /**
     * Reconstitution of a previously-persisted Active Account restores
     * every field exactly: Tenant, identifier, Code, Name, Type, and
     * the Active state itself.
     */
    public function test_reconstitute_restores_an_active_account(): void
    {
        $tenantId = $this->validTenantId();
        $id = $this->validId();
        $code = $this->validCode();
        $name = $this->validName();

        $account = Account::reconstitute($tenantId, $id, $code, $name, AccountType::Asset, true, true, AccountOrigin::UserCreated, null);

        $this->assertTrue($tenantId->equals($account->tenantId()));
        $this->assertTrue($id->equals($account->id()));
        $this->assertTrue($code->equals($account->code()));
        $this->assertTrue($name->equals($account->name()));
        $this->assertSame(AccountType::Asset, $account->type());
        $this->assertTrue($account->isActive());
    }

    /**
     * Reconstitution of a previously-persisted Inactive Account
     * restores the Inactive state exactly — proving `reconstitute()`
     * does not force every Account back to Active the way `create()`
     * does.
     */
    public function test_reconstitute_restores_an_inactive_account(): void
    {
        $account = Account::reconstitute($this->validTenantId(), $this->validId(), $this->validCode(), $this->validName(), AccountType::Asset, false, true, AccountOrigin::UserCreated, null);

        $this->assertFalse($account->isActive());
    }

    /**
     * Reconstitution with a persisted parentId restores it exactly.
     */
    public function test_reconstitute_restores_a_parent_id(): void
    {
        $parentId = AccountId::of('account-parent');

        $account = Account::reconstitute($this->validTenantId(), $this->validId(), $this->validCode(), $this->validName(), AccountType::Asset, true, true, AccountOrigin::UserCreated, $parentId);

        $this->assertNotNull($account->parentId());
        $this->assertTrue($parentId->equals($account->parentId()));
    }

    /**
     * Reconstitution without a persisted parentId restores no parent —
     * hierarchy remains optional through this path too.
     */
    public function test_reconstitute_without_a_parent_id_restores_no_parent(): void
    {
        $account = Account::reconstitute($this->validTenantId(), $this->validId(), $this->validCode(), $this->validName(), AccountType::Asset, true, true, AccountOrigin::UserCreated, null);

        $this->assertNull($account->parentId());
    }

    /**
     * COA-005: Normal Balance is derived exactly the same way through
     * `reconstitute()` as through `create()` — across every Account
     * Type — never restored as an independently persisted value.
     */
    public function test_reconstitute_derives_normal_balance_from_type(): void
    {
        foreach (AccountType::cases() as $type) {
            $account = Account::reconstitute($this->validTenantId(), $this->validId(), $this->validCode(), $this->validName(), $type, true, true, AccountOrigin::UserCreated, null);

            $this->assertSame($type->normalBalance(), $account->normalBalance());
        }
    }

    /**
     * The caller cannot supply Normal Balance to `reconstitute()` —
     * proven structurally, since the method declares no Normal Balance
     * parameter at all, exactly like `create()`.
     */
    public function test_reconstitute_has_no_caller_suppliable_normal_balance(): void
    {
        $reflection = new ReflectionClass(Account::class);
        $method = $reflection->getMethod('reconstitute');

        $parameterTypes = array_map(
            static fn (\ReflectionParameter $parameter): ?string => $parameter->getType() instanceof \ReflectionNamedType
                ? $parameter->getType()->getName()
                : null,
            $method->getParameters(),
        );

        $this->assertNotContains(NormalBalance::class, $parameterTypes);
    }

    /**
     * Reconstitution preserves the configured posting-eligibility flag
     * exactly, and the effective `isPostingAllowed()` answer still
     * combines it with Active state — `active && postingEligible` —
     * exactly as it does for an Account built via `create()`.
     */
    public function test_reconstitute_preserves_posting_eligibility_and_effective_posting_allowed(): void
    {
        $activeEligible = Account::reconstitute($this->validTenantId(), $this->validId(), $this->validCode(), $this->validName(), AccountType::Asset, true, true, AccountOrigin::UserCreated, null);
        $activeIneligible = Account::reconstitute($this->validTenantId(), $this->validId(), $this->validCode(), $this->validName(), AccountType::Asset, true, false, AccountOrigin::UserCreated, null);
        $inactiveEligible = Account::reconstitute($this->validTenantId(), $this->validId(), $this->validCode(), $this->validName(), AccountType::Asset, false, true, AccountOrigin::UserCreated, null);

        $this->assertTrue($activeEligible->isPostingAllowed());
        $this->assertFalse($activeIneligible->isPostingAllowed());
        $this->assertFalse($inactiveEligible->isPostingAllowed());
    }

    /**
     * Identity equality is unaffected by which factory produced an
     * Account: a `create()`d Account and a `reconstitute()`d Account
     * sharing the same identifier are equal.
     */
    public function test_reconstitute_identity_equality_matches_create(): void
    {
        $id = $this->validId();

        $created = $this->createAccount(id: $id);
        $reconstituted = Account::reconstitute($this->validTenantId(), $id, AccountCode::of('9999'), AccountName::of('Different Name'), AccountType::Liability, false, false, AccountOrigin::UserCreated, null);

        $this->assertTrue($created->equals($reconstituted));
    }

    /**
     * `reconstitute()` performs no hierarchy validation of its own —
     * it never calls {@see AccountHierarchyPolicy} and accepts no
     * `$knownAccounts`, since restoring a persisted `parentId` is not a
     * business decision, only a replay of one already made. This is
     * proven structurally: the method has exactly nine parameters and
     * none of them is a `$knownAccounts`-shaped array.
     */
    public function test_reconstitute_has_no_knownaccounts_parameter(): void
    {
        $reflection = new ReflectionClass(Account::class);
        $method = $reflection->getMethod('reconstitute');

        $this->assertCount(9, $method->getParameters());

        $parameterNames = array_map(
            static fn (\ReflectionParameter $parameter): string => $parameter->getName(),
            $method->getParameters(),
        );
        $this->assertNotContains('knownAccounts', $parameterNames);
    }

    /**
     * `withParent()`'s own hierarchy validation is completely
     * unaffected by `reconstitute()`'s existence: it still requires
     * `$knownAccounts` and still rejects self-parenting exactly as
     * before.
     */
    public function test_with_parent_validation_is_unchanged_by_reconstitute(): void
    {
        $account = Account::reconstitute($this->validTenantId(), $this->validId(), $this->validCode(), $this->validName(), AccountType::Asset, true, true, AccountOrigin::UserCreated, null);

        $this->expectException(InvalidAccountHierarchyException::class);

        $account->withParent($account, [$account]);
    }

    public function test_posting_eligible_true_is_honored(): void
    {
        $account = $this->createAccount(isPostingEligible: true);

        $this->assertTrue($account->isPostingAllowed());
    }

    public function test_posting_eligible_false_is_honored(): void
    {
        $account = $this->createAccount(isPostingEligible: false);

        $this->assertFalse($account->isPostingAllowed());
    }

    /**
     * AETS-005 §13: an Inactive Account reports posting not allowed,
     * even though it was created posting-eligible.
     */
    public function test_inactive_account_reports_posting_not_allowed(): void
    {
        $account = $this->createAccount(isPostingEligible: true)->deactivate();

        $this->assertFalse($account->isPostingAllowed());
    }

    /**
     * `isPostingEligible()` reports the configured value independent
     * of Active state — unlike `isPostingAllowed()`, deactivating an
     * Account does not change what `isPostingEligible()` reports (M2-T6:
     * this is exactly the distinct fact a persistence adapter needs to
     * round-trip an Account exactly).
     */
    public function test_is_posting_eligible_reports_configuration_independent_of_active_state(): void
    {
        $eligible = $this->createAccount(isPostingEligible: true);
        $ineligible = $this->createAccount(isPostingEligible: false);

        $this->assertTrue($eligible->isPostingEligible());
        $this->assertFalse($ineligible->isPostingEligible());

        $deactivatedEligible = $eligible->deactivate();

        $this->assertTrue($deactivatedEligible->isPostingEligible());
        $this->assertFalse($deactivatedEligible->isPostingAllowed());
    }

    /**
     * `deactivate()` returns a new Account instance, distinct from the
     * original — and the original is left untouched.
     */
    public function test_deactivate_returns_a_new_instance(): void
    {
        $original = $this->createAccount();

        $deactivated = $original->deactivate();

        $this->assertNotSame($original, $deactivated);
        $this->assertTrue($original->isActive());
        $this->assertFalse($deactivated->isActive());
    }

    /**
     * `deactivate()` preserves Tenant, identifier, Code, Name, Type,
     * and Normal Balance unchanged — only the Active state (and,
     * derived from it, posting-allowed) changes.
     */
    public function test_deactivate_preserves_identity_and_domain_fields(): void
    {
        $original = $this->createAccount(isPostingEligible: true);

        $deactivated = $original->deactivate();

        $this->assertTrue($original->tenantId()->equals($deactivated->tenantId()));
        $this->assertTrue($original->id()->equals($deactivated->id()));
        $this->assertTrue($original->code()->equals($deactivated->code()));
        $this->assertTrue($original->name()->equals($deactivated->name()));
        $this->assertSame($original->type(), $deactivated->type());
        $this->assertSame($original->normalBalance(), $deactivated->normalBalance());
    }

    /**
     * Repeated deactivation is deterministic: deactivating an
     * already-Inactive Account produces another Account equal in every
     * observable respect — idempotent in effect, even though a new
     * instance is returned each time.
     */
    public function test_repeated_deactivation_is_deterministic(): void
    {
        $account = $this->createAccount(isPostingEligible: true);

        $deactivatedOnce = $account->deactivate();
        $deactivatedTwice = $deactivatedOnce->deactivate();

        $this->assertFalse($deactivatedOnce->isActive());
        $this->assertFalse($deactivatedTwice->isActive());
        $this->assertFalse($deactivatedOnce->isPostingAllowed());
        $this->assertFalse($deactivatedTwice->isPostingAllowed());
        $this->assertTrue($deactivatedOnce->id()->equals($deactivatedTwice->id()));
        $this->assertTrue($deactivatedOnce->equals($deactivatedTwice));
    }

    /**
     * COA-T008: an Account's public shape exposes no mutable
     * authoritative balance field or accessor, at construction or
     * thereafter — proven by an exact inventory of the public API and
     * of every declared property.
     */
    public function test_exposes_only_the_required_public_api(): void
    {
        $reflection = new ReflectionClass(Account::class);

        $publicMethodNames = array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            $reflection->getMethods(\ReflectionMethod::IS_PUBLIC),
        );

        $this->assertSame(
            ['create', 'reconstitute', 'tenantId', 'id', 'code', 'name', 'type', 'normalBalance', 'isActive', 'isPostingAllowed', 'isPostingEligible', 'origin', 'parentId', 'deactivate', 'withParent', 'equals'],
            $publicMethodNames,
        );

        $propertyNames = array_map(
            static fn (\ReflectionProperty $property): string => $property->getName(),
            $reflection->getProperties(),
        );

        $this->assertSame(
            ['tenantId', 'id', 'code', 'name', 'type', 'normalBalance', 'active', 'postingEligible', 'origin', 'parentId'],
            $propertyNames,
        );
    }

    /**
     * Account is immutable: every property is readonly and no public
     * mutator method exists — `deactivate()` returns a new instance
     * rather than mutating in place (already proven directly by
     * `test_deactivate_returns_a_new_instance`); no `activate()`,
     * `rename()`, or code/type-changing method exists at all.
     */
    public function test_account_is_immutable(): void
    {
        $reflection = new ReflectionClass(Account::class);

        foreach ($reflection->getProperties() as $property) {
            $this->assertTrue(
                $property->isReadOnly(),
                sprintf('Property "%s" must be readonly.', $property->getName()),
            );
        }

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $this->assertStringStartsNotWith(
                'set',
                $method->getName(),
                sprintf('Public method "%s" must not be a mutator.', $method->getName()),
            );
        }
    }

    /**
     * No native database ID semantics: no public method returns a
     * native PHP int as the canonical representation of anything.
     */
    public function test_no_native_int_canonical_accessor(): void
    {
        $reflection = new ReflectionClass(Account::class);

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $returnType = $method->getReturnType();

            if ($returnType instanceof \ReflectionNamedType) {
                $this->assertNotSame(
                    'int',
                    $returnType->getName(),
                    sprintf('Public method "%s" must not return a native int.', $method->getName()),
                );
            }
        }
    }

    public function test_equals_same_identifier_is_equal(): void
    {
        $id = $this->validId();

        $a = $this->createAccount(id: $id);
        $b = Account::create($this->validTenantId(), $id, AccountCode::of('1100'), AccountName::of('Cash / Bank (renamed)'), AccountType::Asset, true, AccountOrigin::UserCreated);

        $this->assertTrue($a->equals($b));
    }

    public function test_equals_different_identifier_is_not_equal(): void
    {
        $a = $this->createAccount(id: AccountId::of('account-0001'));
        $b = $this->createAccount(id: AccountId::of('account-0002'));

        $this->assertFalse($a->equals($b));
    }

    /**
     * COA-T032 / COA-T038: a freshly created Account has no parent —
     * hierarchy is optional and not required for any Account.
     */
    public function test_new_account_has_no_parent(): void
    {
        $account = $this->createAccount();

        $this->assertNull($account->parentId());
    }

    /**
     * COA-T033: an Account MAY be assigned exactly one parent Account
     * belonging to the same Tenant.
     */
    public function test_with_parent_accepts_a_valid_same_tenant_parent(): void
    {
        $tenantId = $this->validTenantId();
        $parent = $this->createAccount(tenantId: $tenantId, id: AccountId::of('account-parent'));
        $child = $this->createAccount(tenantId: $tenantId, id: AccountId::of('account-child'));

        $result = $child->withParent($parent, [$parent, $child]);

        $this->assertInstanceOf(Account::class, $result);
    }

    /**
     * Parent reference exact round-trip: `parentId()` returns exactly
     * the assigned parent's identifier.
     */
    public function test_with_parent_round_trips_the_parent_identifier(): void
    {
        $tenantId = $this->validTenantId();
        $parentId = AccountId::of('account-parent');
        $parent = $this->createAccount(tenantId: $tenantId, id: $parentId);
        $child = $this->createAccount(tenantId: $tenantId, id: AccountId::of('account-child'));

        $result = $child->withParent($parent, [$parent, $child]);

        $this->assertNotNull($result->parentId());
        $this->assertTrue($parentId->equals($result->parentId()));
    }

    /**
     * COA-T034: a direct self-cycle (an Account specified as its own
     * parent) is rejected — via the public `withParent()` API, with a
     * `$knownAccounts` set that would otherwise be sufficient, proving
     * this specific rejection is not merely a side effect of an
     * incomplete context.
     */
    public function test_with_parent_rejects_self_parenting(): void
    {
        $account = $this->createAccount();

        $this->expectException(InvalidAccountHierarchyException::class);

        $account->withParent($account, [$account]);
    }

    /**
     * COA-T037: a parent/child relationship across two different
     * Tenants is rejected.
     */
    public function test_with_parent_rejects_a_cross_tenant_parent(): void
    {
        $parent = $this->createAccount(tenantId: TenantId::of('tenant-0002'), id: AccountId::of('account-parent'));
        $child = $this->createAccount(tenantId: TenantId::of('tenant-0001'), id: AccountId::of('account-child'));

        $this->expectException(InvalidAccountHierarchyException::class);

        $child->withParent($parent, [$parent, $child]);
    }

    /**
     * COA-T035 (Account-level): an indirect, transitive cycle is
     * rejected through the public `withParent()` API itself, not only
     * through {@see AccountHierarchyPolicy} called directly — proving
     * the public path cannot bypass the graph check.
     */
    public function test_with_parent_rejects_an_indirect_cycle(): void
    {
        $tenantId = $this->validTenantId();
        $a = $this->createAccount(tenantId: $tenantId, id: AccountId::of('account-a'));
        $b = $this->createAccount(tenantId: $tenantId, id: AccountId::of('account-b'))->withParent($a, [$a]);
        $c = $this->createAccount(tenantId: $tenantId, id: AccountId::of('account-c'))->withParent($b, [$a, $b]);

        $this->expectException(InvalidAccountHierarchyException::class);

        $a->withParent($c, [$a, $b, $c]);
    }

    /**
     * COA-T036 (Account-level): a deep, valid, cycle-free hierarchy
     * (four levels) is accepted through the public `withParent()` API.
     */
    public function test_with_parent_accepts_a_deep_valid_hierarchy(): void
    {
        $tenantId = $this->validTenantId();
        $level1 = $this->createAccount(tenantId: $tenantId, id: AccountId::of('account-level-1'));
        $level2 = $this->createAccount(tenantId: $tenantId, id: AccountId::of('account-level-2'))->withParent($level1, [$level1]);
        $level3 = $this->createAccount(tenantId: $tenantId, id: AccountId::of('account-level-3'))->withParent($level2, [$level1, $level2]);
        $level4 = $this->createAccount(tenantId: $tenantId, id: AccountId::of('account-level-4'));

        $result = $level4->withParent($level3, [$level1, $level2, $level3, $level4]);

        $this->assertTrue($level3->id()->equals($result->parentId()));
    }

    /**
     * Incomplete ancestry context is rejected — fails closed. The
     * proposed parent has a parent of its own that is not included in
     * `$knownAccounts`, so the assignment cannot be proven cycle-free
     * and MUST NOT be accepted as safe by assumption.
     */
    public function test_with_parent_rejects_an_incomplete_ancestry_context(): void
    {
        $tenantId = $this->validTenantId();
        $unknownAncestor = $this->createAccount(tenantId: $tenantId, id: AccountId::of('account-unknown-ancestor'));
        $parent = $this->createAccount(tenantId: $tenantId, id: AccountId::of('account-parent'))->withParent($unknownAncestor, [$unknownAncestor]);
        $child = $this->createAccount(tenantId: $tenantId, id: AccountId::of('account-child'));

        $this->expectException(InvalidAccountHierarchyException::class);

        // Deliberately omits $unknownAncestor from the supplied context.
        $child->withParent($parent, [$parent, $child]);
    }

    /**
     * Public parent assignment cannot bypass cycle validation: there
     * is no overload, default, or alternate public method that attaches
     * a parent without `$knownAccounts` — `withParent()` is the only
     * public API that mutates the parent reference, and its
     * `$knownAccounts` parameter is required (no default value), so a
     * caller cannot omit the graph check even accidentally.
     */
    public function test_with_parent_cannot_be_called_without_supplying_known_accounts(): void
    {
        $reflection = new ReflectionClass(Account::class);

        $publicMethodNames = array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            $reflection->getMethods(\ReflectionMethod::IS_PUBLIC),
        );
        $parentMutatingMethods = array_filter(
            $publicMethodNames,
            static fn (string $name): bool => str_contains(strtolower($name), 'parent'),
        );
        $this->assertSame(['parentId', 'withParent'], array_values($parentMutatingMethods));

        $method = $reflection->getMethod('withParent');
        $parameters = $method->getParameters();

        $this->assertCount(2, $parameters);
        $this->assertSame('knownAccounts', $parameters[1]->getName());
        $this->assertFalse($parameters[1]->isOptional());
        $this->assertFalse($parameters[1]->isDefaultValueAvailable());
    }

    /**
     * `withParent()` returns a new instance and never mutates the
     * original — the original Account remains parentless afterward.
     */
    public function test_with_parent_returns_a_new_instance_and_does_not_mutate_the_original(): void
    {
        $tenantId = $this->validTenantId();
        $parent = $this->createAccount(tenantId: $tenantId, id: AccountId::of('account-parent'));
        $child = $this->createAccount(tenantId: $tenantId, id: AccountId::of('account-child'));

        $result = $child->withParent($parent, [$parent, $child]);

        $this->assertNotSame($child, $result);
        $this->assertNull($child->parentId());
    }

    /**
     * Hierarchy mutation does not alter unrelated Account fields: every
     * field other than the parent reference is preserved exactly.
     */
    public function test_with_parent_preserves_unrelated_fields(): void
    {
        $tenantId = $this->validTenantId();
        $parent = $this->createAccount(tenantId: $tenantId, id: AccountId::of('account-parent'));
        $child = $this->createAccount(tenantId: $tenantId, id: AccountId::of('account-child'), type: AccountType::Liability, isPostingEligible: false);

        $result = $child->withParent($parent, [$parent, $child]);

        $this->assertTrue($child->tenantId()->equals($result->tenantId()));
        $this->assertTrue($child->id()->equals($result->id()));
        $this->assertTrue($child->code()->equals($result->code()));
        $this->assertTrue($child->name()->equals($result->name()));
        $this->assertSame($child->type(), $result->type());
        $this->assertSame($child->normalBalance(), $result->normalBalance());
        $this->assertSame($child->isActive(), $result->isActive());
        $this->assertSame($child->isPostingAllowed(), $result->isPostingAllowed());
    }

    /**
     * COA-T053 (structure): System is a valid, constructible Account
     * Origin.
     */
    public function test_system_origin_is_constructible(): void
    {
        $account = $this->createAccount(origin: AccountOrigin::System);

        $this->assertSame(AccountOrigin::System, $account->origin());
    }

    /**
     * COA-T053: UserCreated is a valid, constructible Account Origin —
     * "a User-Created Account can be created within a Tenant through
     * the ordinary account-creation path."
     */
    public function test_user_created_origin_is_constructible(): void
    {
        $account = $this->createAccount(origin: AccountOrigin::UserCreated);

        $this->assertSame(AccountOrigin::UserCreated, $account->origin());
    }

    /**
     * An Account explicitly carries exactly one Origin — never both,
     * never neither, and never silently defaulted by `Account` itself
     * (only this test suite's own helper has a convenience default;
     * see `test_every_create_parameter_is_required()`).
     */
    public function test_account_explicitly_carries_one_origin(): void
    {
        $system = $this->createAccount(id: AccountId::of('account-system'), origin: AccountOrigin::System);
        $userCreated = $this->createAccount(id: AccountId::of('account-user'), origin: AccountOrigin::UserCreated);

        $this->assertSame(AccountOrigin::System, $system->origin());
        $this->assertSame(AccountOrigin::UserCreated, $userCreated->origin());
    }

    /**
     * Origin is preserved through `deactivate()`.
     */
    public function test_origin_preserved_through_deactivate(): void
    {
        $account = $this->createAccount(origin: AccountOrigin::System)->deactivate();

        $this->assertSame(AccountOrigin::System, $account->origin());
    }

    /**
     * Origin is preserved through `withParent()`.
     */
    public function test_origin_preserved_through_with_parent(): void
    {
        $tenantId = $this->validTenantId();
        $parent = $this->createAccount(tenantId: $tenantId, id: AccountId::of('account-parent'));
        $child = $this->createAccount(tenantId: $tenantId, id: AccountId::of('account-child'), origin: AccountOrigin::System)
            ->withParent($parent, [$parent]);

        $this->assertSame(AccountOrigin::System, $child->origin());
    }

    /**
     * Origin is preserved through `reconstitute()` — restored exactly,
     * for both canonical values.
     */
    public function test_origin_preserved_through_reconstitute(): void
    {
        $system = Account::reconstitute($this->validTenantId(), $this->validId(), $this->validCode(), $this->validName(), AccountType::Asset, true, true, AccountOrigin::System, null);
        $userCreated = Account::reconstitute($this->validTenantId(), $this->validId(), $this->validCode(), $this->validName(), AccountType::Asset, true, true, AccountOrigin::UserCreated, null);

        $this->assertSame(AccountOrigin::System, $system->origin());
        $this->assertSame(AccountOrigin::UserCreated, $userCreated->origin());
    }

    /**
     * COA-T052: a System Account cannot be reassigned to a different
     * Tenant through any current public API — proven structurally: no
     * method on `Account` accepts or sets a new `TenantId` after
     * construction (`tenantId()` is a read-only accessor; `create()`
     * and `reconstitute()` are the only places a `TenantId` is ever
     * supplied, and both are construction, not mutation).
     */
    public function test_system_account_cannot_change_tenant_through_any_current_public_api(): void
    {
        $reflection = new ReflectionClass(Account::class);

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if (in_array($method->getName(), ['create', 'reconstitute'], true)) {
                continue;
            }

            $parameterTypes = array_map(
                static fn (\ReflectionParameter $parameter): ?string => $parameter->getType() instanceof \ReflectionNamedType
                    ? $parameter->getType()->getName()
                    : null,
                $method->getParameters(),
            );
            $this->assertNotContains(
                TenantId::class,
                $parameterTypes,
                sprintf('Method "%s" must not accept a TenantId (would allow cross-tenant reassignment).', $method->getName()),
            );
        }
    }

    /**
     * COA-T049: a System Account's Account Type cannot be changed
     * through any current public API — proven structurally: no method
     * on `Account` accepts an `AccountType` after construction (`type()`
     * is a read-only accessor; `create()` and `reconstitute()` are the
     * only places an `AccountType` is ever supplied).
     */
    public function test_system_account_cannot_change_type_through_any_current_public_api(): void
    {
        $reflection = new ReflectionClass(Account::class);

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if (in_array($method->getName(), ['create', 'reconstitute'], true)) {
                continue;
            }

            $parameterTypes = array_map(
                static fn (\ReflectionParameter $parameter): ?string => $parameter->getType() instanceof \ReflectionNamedType
                    ? $parameter->getType()->getName()
                    : null,
                $method->getParameters(),
            );
            $this->assertNotContains(
                AccountType::class,
                $parameterTypes,
                sprintf('Method "%s" must not accept an AccountType (would allow retyping).', $method->getName()),
            );
        }
    }

    /**
     * COA-T048: a System Account's Account Code cannot be changed
     * through any current public API — proven structurally: no method
     * on `Account` accepts an `AccountCode` after construction (`code()`
     * is a read-only accessor; `create()` and `reconstitute()` are the
     * only places an `AccountCode` is ever supplied).
     */
    public function test_system_account_cannot_change_code_through_any_current_public_api(): void
    {
        $reflection = new ReflectionClass(Account::class);

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if (in_array($method->getName(), ['create', 'reconstitute'], true)) {
                continue;
            }

            $parameterTypes = array_map(
                static fn (\ReflectionParameter $parameter): ?string => $parameter->getType() instanceof \ReflectionNamedType
                    ? $parameter->getType()->getName()
                    : null,
                $method->getParameters(),
            );
            $this->assertNotContains(
                AccountCode::class,
                $parameterTypes,
                sprintf('Method "%s" must not accept an AccountCode (would allow re-coding).', $method->getName()),
            );
        }
    }

    /**
     * COA-T050: a System Account's Normal Balance cannot be altered by
     * any user-facing operation — it is protected transitively through
     * Account Type's own immutability (`test_system_account_cannot_change_type_through_any_current_public_api()`),
     * since `normalBalance()` is always the value `AccountType::normalBalance()`
     * derives and is never itself a settable field.
     */
    public function test_system_account_normal_balance_has_no_settable_path(): void
    {
        $reflection = new ReflectionClass(Account::class);

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $parameterTypes = array_map(
                static fn (\ReflectionParameter $parameter): ?string => $parameter->getType() instanceof \ReflectionNamedType
                    ? $parameter->getType()->getName()
                    : null,
                $method->getParameters(),
            );
            $this->assertNotContains(NormalBalance::class, $parameterTypes);
        }
    }

    /**
     * COA-T051: no delete API exists that could silently delete a
     * System Account (or any Account) — proven by an exact inventory
     * of the public API, which contains no method named or shaped like
     * a deletion operation.
     */
    public function test_no_delete_api_exists(): void
    {
        $reflection = new ReflectionClass(Account::class);

        $publicMethodNames = array_map(
            static fn (\ReflectionMethod $method): string => strtolower($method->getName()),
            $reflection->getMethods(\ReflectionMethod::IS_PUBLIC),
        );

        foreach (['delete', 'remove', 'destroy', 'purge'] as $forbiddenMethodName) {
            $this->assertNotContains($forbiddenMethodName, $publicMethodNames);
        }
    }

    /**
     * COA-T054: a User-Created Account still obeys the canonical
     * Account Type -> Normal Balance rule, across every canonical
     * Type — it carries no special authority to deviate from it.
     */
    public function test_user_created_account_obeys_canonical_type_to_normal_balance_rule(): void
    {
        foreach (AccountType::cases() as $type) {
            $account = $this->createAccount(type: $type, origin: AccountOrigin::UserCreated);

            $this->assertSame($type->normalBalance(), $account->normalBalance());
        }
    }

    /**
     * Framework independence: no Illuminate/Eloquent dependency
     * anywhere in the file.
     */
    public function test_has_no_framework_dependency(): void
    {
        $reflection = new ReflectionClass(Account::class);
        $source = file_get_contents((string) $reflection->getFileName());

        $this->assertIsString($source);
        $this->assertStringNotContainsString('Illuminate\\', $source);
        $this->assertStringNotContainsString('Eloquent', $source);
    }
}
