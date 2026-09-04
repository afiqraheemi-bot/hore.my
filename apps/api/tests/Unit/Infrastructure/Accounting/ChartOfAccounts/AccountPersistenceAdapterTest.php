<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Accounting\ChartOfAccounts;

use App\Domain\Accounting\ChartOfAccounts\Account;
use App\Domain\Accounting\ChartOfAccounts\AccountCode;
use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\ChartOfAccounts\AccountName;
use App\Domain\Accounting\ChartOfAccounts\AccountType;
use App\Domain\Accounting\ChartOfAccounts\Exception\InvalidAccountCodeException;
use App\Domain\Accounting\ChartOfAccounts\Exception\InvalidAccountIdException;
use App\Domain\Accounting\ChartOfAccounts\Exception\InvalidAccountNameException;
use App\Domain\Shared\Tenancy\Exception\InvalidTenantIdException;
use App\Domain\Shared\Tenancy\TenantId;
use App\Infrastructure\Accounting\ChartOfAccounts\AccountPersistenceAdapter;
use App\Infrastructure\Accounting\ChartOfAccounts\Exception\InvalidPersistedAccountTypeException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Feature\Infrastructure\Accounting\ChartOfAccounts\AccountPersistenceAdapterIntegrationTest;

/**
 * Adapter-contract tests for {@see AccountPersistenceAdapter} (M2-T6):
 * pure unit-level coverage of the mapping logic, with no database
 * dependency. Real PostgreSQL round-trip and constraint proof (tenant-
 * scoped Account Code uniqueness in particular) is covered separately
 * by {@see AccountPersistenceAdapterIntegrationTest}.
 */
final class AccountPersistenceAdapterTest extends TestCase
{
    private AccountPersistenceAdapter $adapter;

    private TenantId $tenantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adapter = new AccountPersistenceAdapter;
        $this->tenantId = TenantId::of('tenant-0001');
    }

    private function makeAccount(
        bool $active = true,
        bool $isPostingEligible = true,
        AccountType $type = AccountType::Asset,
        ?AccountId $parentId = null,
    ): Account {
        $account = Account::create(
            $this->tenantId,
            AccountId::of('account-0001'),
            AccountCode::of('1000'),
            AccountName::of('Cash / Bank'),
            $type,
            $isPostingEligible,
        );

        if (! $active) {
            $account = $account->deactivate();
        }

        if ($parentId !== null) {
            $parent = Account::create($this->tenantId, $parentId, AccountCode::of('9000'), AccountName::of('Parent'), $type, false);
            $account = $account->withParent($parent, [$parent, $account]);
        }

        return $account;
    }

    /**
     * Account -> persisted representation: every field maps exactly.
     */
    public function test_account_maps_to_its_persisted_row(): void
    {
        $account = $this->makeAccount();

        $row = $this->adapter->toPersistedRow($account);

        $this->assertSame('tenant-0001', $row['tenant_id']);
        $this->assertSame('account-0001', $row['account_id']);
        $this->assertSame('1000', $row['account_code']);
        $this->assertSame('Cash / Bank', $row['account_name']);
        $this->assertSame('Asset', $row['account_type']);
        $this->assertTrue($row['active']);
        $this->assertTrue($row['posting_eligible']);
        $this->assertNull($row['parent_id']);
    }

    /**
     * Persisted representation -> Account: every field maps back
     * exactly.
     */
    public function test_persisted_row_maps_back_to_an_account(): void
    {
        $account = $this->adapter->fromPersistedRow([
            'tenant_id' => 'tenant-0001',
            'account_id' => 'account-0001',
            'account_code' => '1000',
            'account_name' => 'Cash / Bank',
            'account_type' => 'Asset',
            'active' => true,
            'posting_eligible' => true,
            'parent_id' => null,
        ]);

        $this->assertTrue($this->tenantId->equals($account->tenantId()));
        $this->assertSame('account-0001', $account->id()->toString());
        $this->assertSame('1000', $account->code()->toString());
        $this->assertSame('Cash / Bank', $account->name()->toString());
        $this->assertSame(AccountType::Asset, $account->type());
        $this->assertTrue($account->isActive());
        $this->assertTrue($account->isPostingEligible());
        $this->assertNull($account->parentId());
    }

    /**
     * Exact round-trip for every field.
     */
    public function test_write_then_read_round_trip_is_exact(): void
    {
        $original = $this->makeAccount(isPostingEligible: false, type: AccountType::Liability);

        $reconstructed = $this->adapter->fromPersistedRow($this->adapter->toPersistedRow($original));

        $this->assertTrue($original->equals($reconstructed));
        $this->assertTrue($original->tenantId()->equals($reconstructed->tenantId()));
        $this->assertTrue($original->code()->equals($reconstructed->code()));
        $this->assertTrue($original->name()->equals($reconstructed->name()));
        $this->assertSame($original->type(), $reconstructed->type());
        $this->assertSame($original->normalBalance(), $reconstructed->normalBalance());
        $this->assertSame($original->isActive(), $reconstructed->isActive());
        $this->assertSame($original->isPostingEligible(), $reconstructed->isPostingEligible());
    }

    /**
     * Active round-trips exactly.
     */
    public function test_active_round_trips(): void
    {
        $account = $this->makeAccount(active: true);

        $reconstructed = $this->adapter->fromPersistedRow($this->adapter->toPersistedRow($account));

        $this->assertTrue($reconstructed->isActive());
    }

    /**
     * Inactive round-trips exactly.
     */
    public function test_inactive_round_trips(): void
    {
        $account = $this->makeAccount(active: false);

        $reconstructed = $this->adapter->fromPersistedRow($this->adapter->toPersistedRow($account));

        $this->assertFalse($reconstructed->isActive());
    }

    /**
     * Posting-eligible true round-trips exactly.
     */
    public function test_posting_eligible_true_round_trips(): void
    {
        $account = $this->makeAccount(isPostingEligible: true);

        $reconstructed = $this->adapter->fromPersistedRow($this->adapter->toPersistedRow($account));

        $this->assertTrue($reconstructed->isPostingEligible());
    }

    /**
     * Posting-eligible false round-trips exactly.
     */
    public function test_posting_eligible_false_round_trips(): void
    {
        $account = $this->makeAccount(isPostingEligible: false);

        $reconstructed = $this->adapter->fromPersistedRow($this->adapter->toPersistedRow($account));

        $this->assertFalse($reconstructed->isPostingEligible());
    }

    /**
     * Posting-eligible configuration round-trips exactly even for an
     * Inactive Account — the exact scenario `isPostingAllowed()` alone
     * cannot distinguish (M2-T6).
     */
    public function test_posting_eligible_round_trips_for_an_inactive_account(): void
    {
        $account = $this->makeAccount(active: false, isPostingEligible: true);

        $reconstructed = $this->adapter->fromPersistedRow($this->adapter->toPersistedRow($account));

        $this->assertFalse($reconstructed->isActive());
        $this->assertTrue($reconstructed->isPostingEligible());
        $this->assertFalse($reconstructed->isPostingAllowed());
    }

    /**
     * A present parentId round-trips exactly.
     */
    public function test_parent_id_present_round_trips(): void
    {
        $parentId = AccountId::of('account-parent');
        $account = $this->makeAccount(parentId: $parentId);

        $reconstructed = $this->adapter->fromPersistedRow($this->adapter->toPersistedRow($account));

        $this->assertNotNull($reconstructed->parentId());
        $this->assertTrue($parentId->equals($reconstructed->parentId()));
    }

    /**
     * An absent parentId round-trips exactly as absent.
     */
    public function test_parent_id_absent_round_trips(): void
    {
        $account = $this->makeAccount();

        $reconstructed = $this->adapter->fromPersistedRow($this->adapter->toPersistedRow($account));

        $this->assertNull($reconstructed->parentId());
    }

    /**
     * Account Type round-trips exactly, across every canonical type.
     */
    public function test_account_type_round_trips_for_every_type(): void
    {
        foreach (AccountType::cases() as $type) {
            $account = $this->makeAccount(type: $type);

            $reconstructed = $this->adapter->fromPersistedRow($this->adapter->toPersistedRow($account));

            $this->assertSame($type, $reconstructed->type());
        }
    }

    /**
     * Normal Balance is correctly re-derived on read, across every
     * Account Type — never read from a persisted field.
     */
    public function test_normal_balance_is_correctly_re_derived_on_read(): void
    {
        foreach (AccountType::cases() as $type) {
            $account = $this->makeAccount(type: $type);

            $reconstructed = $this->adapter->fromPersistedRow($this->adapter->toPersistedRow($account));

            $this->assertSame($type->normalBalance(), $reconstructed->normalBalance());
        }
    }

    /**
     * `COA-005`: no Normal Balance persistence field exists at all —
     * the persisted row shape has no key for it.
     */
    public function test_persisted_row_has_no_normal_balance_field(): void
    {
        $row = $this->adapter->toPersistedRow($this->makeAccount());

        $this->assertArrayNotHasKey('normal_balance', $row);
        $this->assertArrayNotHasKey('normalBalance', $row);
    }

    /**
     * `COA-012`: no monetary balance persistence field exists — the
     * persisted row shape carries no balance, debit total, or credit
     * total key.
     */
    public function test_persisted_row_has_no_monetary_balance_field(): void
    {
        $row = $this->adapter->toPersistedRow($this->makeAccount());

        foreach (array_keys($row) as $key) {
            $this->assertStringNotContainsStringIgnoringCase('balance', (string) $key);
            $this->assertStringNotContainsStringIgnoringCase('debit', (string) $key);
            $this->assertStringNotContainsStringIgnoringCase('credit', (string) $key);
        }
    }

    /**
     * A malformed Account Type is rejected.
     */
    public function test_malformed_account_type_is_rejected(): void
    {
        $this->expectException(InvalidPersistedAccountTypeException::class);

        $this->adapter->fromPersistedRow([
            'tenant_id' => 'tenant-0001',
            'account_id' => 'account-0001',
            'account_code' => '1000',
            'account_name' => 'Cash / Bank',
            'account_type' => 'NotARealType',
            'active' => true,
            'posting_eligible' => true,
            'parent_id' => null,
        ]);
    }

    /**
     * A malformed TenantId is rejected via TenantId's own existing
     * validation — never silently accepted.
     */
    public function test_malformed_tenant_id_is_rejected(): void
    {
        $this->expectException(InvalidTenantIdException::class);

        $this->adapter->fromPersistedRow([
            'tenant_id' => '',
            'account_id' => 'account-0001',
            'account_code' => '1000',
            'account_name' => 'Cash / Bank',
            'account_type' => 'Asset',
            'active' => true,
            'posting_eligible' => true,
            'parent_id' => null,
        ]);
    }

    /**
     * A malformed AccountId is rejected via AccountId's own existing
     * validation.
     */
    public function test_malformed_account_id_is_rejected(): void
    {
        $this->expectException(InvalidAccountIdException::class);

        $this->adapter->fromPersistedRow([
            'tenant_id' => 'tenant-0001',
            'account_id' => '',
            'account_code' => '1000',
            'account_name' => 'Cash / Bank',
            'account_type' => 'Asset',
            'active' => true,
            'posting_eligible' => true,
            'parent_id' => null,
        ]);
    }

    /**
     * A malformed AccountCode is rejected via AccountCode's own
     * existing validation.
     */
    public function test_malformed_account_code_is_rejected(): void
    {
        $this->expectException(InvalidAccountCodeException::class);

        $this->adapter->fromPersistedRow([
            'tenant_id' => 'tenant-0001',
            'account_id' => 'account-0001',
            'account_code' => '',
            'account_name' => 'Cash / Bank',
            'account_type' => 'Asset',
            'active' => true,
            'posting_eligible' => true,
            'parent_id' => null,
        ]);
    }

    /**
     * A malformed AccountName is rejected via AccountName's own
     * existing validation.
     */
    public function test_malformed_account_name_is_rejected(): void
    {
        $this->expectException(InvalidAccountNameException::class);

        $this->adapter->fromPersistedRow([
            'tenant_id' => 'tenant-0001',
            'account_id' => 'account-0001',
            'account_code' => '1000',
            'account_name' => '',
            'account_type' => 'Asset',
            'active' => true,
            'posting_eligible' => true,
            'parent_id' => null,
        ]);
    }

    /**
     * A malformed parentId is rejected via AccountId's own existing
     * validation, exactly like the primary AccountId.
     */
    public function test_malformed_parent_id_is_rejected(): void
    {
        $this->expectException(InvalidAccountIdException::class);

        $this->adapter->fromPersistedRow([
            'tenant_id' => 'tenant-0001',
            'account_id' => 'account-0001',
            'account_code' => '1000',
            'account_name' => 'Cash / Bank',
            'account_type' => 'Asset',
            'active' => true,
            'posting_eligible' => true,
            'parent_id' => '   ',
        ]);
    }

    /**
     * Reconstruction never performs a business parent assignment: it
     * succeeds even when the "parent" referenced does not itself exist
     * anywhere — proving `fromPersistedRow()` uses `Account::reconstitute()`,
     * not `withParent()`, and therefore never consults
     * `AccountHierarchyPolicy`.
     */
    public function test_reconstruction_does_not_perform_a_business_parent_assignment(): void
    {
        $account = $this->adapter->fromPersistedRow([
            'tenant_id' => 'tenant-0001',
            'account_id' => 'account-0001',
            'account_code' => '1000',
            'account_name' => 'Cash / Bank',
            'account_type' => 'Asset',
            'active' => true,
            'posting_eligible' => true,
            'parent_id' => 'account-nonexistent-parent',
        ]);

        $this->assertNotNull($account->parentId());
        $this->assertSame('account-nonexistent-parent', $account->parentId()->toString());
    }

    /**
     * Domain independence: the adapter never touches Illuminate,
     * Eloquent, or any other framework/database type — it is the only
     * code aware of the persisted row shape, per its own docblock.
     */
    public function test_has_no_framework_dependency(): void
    {
        $reflection = new ReflectionClass(AccountPersistenceAdapter::class);
        $source = file_get_contents((string) $reflection->getFileName());

        $this->assertIsString($source);
        $this->assertStringNotContainsString('Illuminate\\', $source);
        $this->assertStringNotContainsString('Eloquent', $source);
    }

    /**
     * `AccountType` itself remains unbacked and untouched by the
     * persistence translation — the adapter owns the string mapping,
     * not the domain enum.
     */
    public function test_account_type_remains_unbacked(): void
    {
        $reflection = new \ReflectionEnum(AccountType::class);

        $this->assertFalse($reflection->isBacked());
    }
}
