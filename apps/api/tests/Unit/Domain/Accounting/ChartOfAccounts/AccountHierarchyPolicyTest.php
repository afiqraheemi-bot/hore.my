<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Accounting\ChartOfAccounts;

use App\Domain\Accounting\ChartOfAccounts\Account;
use App\Domain\Accounting\ChartOfAccounts\AccountCode;
use App\Domain\Accounting\ChartOfAccounts\AccountHierarchyPolicy;
use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\ChartOfAccounts\AccountName;
use App\Domain\Accounting\ChartOfAccounts\AccountType;
use App\Domain\Accounting\ChartOfAccounts\Exception\InvalidAccountHierarchyException;
use App\Domain\Shared\Tenancy\TenantId;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Covers {@see AccountHierarchyPolicy} — the indirect/transitive
 * cycle-detection behavior {@see AccountTest}
 * cannot exercise on its own (`COA-T035`, `COA-T036`), since it
 * requires graph context beyond the two Account instances directly
 * involved. Every test here uses only in-memory Account relationships
 * — no persistence, no repository.
 */
final class AccountHierarchyPolicyTest extends TestCase
{
    private TenantId $tenantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = TenantId::of('tenant-0001');
    }

    private function makeAccount(string $id): Account
    {
        return Account::create(
            $this->tenantId,
            AccountId::of($id),
            AccountCode::of($id),
            AccountName::of($id),
            AccountType::Asset,
            true,
        );
    }

    /**
     * A parent with no ancestors at all is trivially cycle-free.
     */
    public function test_parent_with_no_ancestors_is_cycle_free(): void
    {
        $child = $this->makeAccount('account-child');
        $parent = $this->makeAccount('account-parent');

        AccountHierarchyPolicy::assertParentAssignmentIsCycleFree($child, $parent, [$child, $parent]);

        $this->addToAssertionCount(1);
    }

    /**
     * COA-T034 (policy-level): a direct self-cycle is rejected.
     */
    public function test_direct_self_cycle_is_rejected(): void
    {
        $account = $this->makeAccount('account-a');

        $this->expectException(InvalidAccountHierarchyException::class);

        AccountHierarchyPolicy::assertParentAssignmentIsCycleFree($account, $account, [$account]);
    }

    /**
     * COA-T035: an indirect, transitive cycle (A -> B -> C -> A) is
     * rejected. Here, A already has parent B, B already has parent C;
     * assigning C's parent to A would close the loop.
     */
    public function test_indirect_transitive_cycle_is_rejected(): void
    {
        $a = $this->makeAccount('account-a');
        $b = $this->makeAccount('account-b')->withParent($a, [$a]);
        $c = $this->makeAccount('account-c')->withParent($b, [$a, $b]);

        $this->expectException(InvalidAccountHierarchyException::class);

        AccountHierarchyPolicy::assertParentAssignmentIsCycleFree($a, $c, [$a, $b, $c]);
    }

    /**
     * COA-T036: a deep, valid, cycle-free hierarchy (four levels) is
     * accepted.
     */
    public function test_deep_valid_hierarchy_is_accepted(): void
    {
        $level1 = $this->makeAccount('account-level-1');
        $level2 = $this->makeAccount('account-level-2')->withParent($level1, [$level1]);
        $level3 = $this->makeAccount('account-level-3')->withParent($level2, [$level1, $level2]);
        $level4 = $this->makeAccount('account-level-4');

        AccountHierarchyPolicy::assertParentAssignmentIsCycleFree($level4, $level3, [$level1, $level2, $level3, $level4]);

        $this->addToAssertionCount(1);
    }

    /**
     * Fails closed: a parent chain that continues beyond the supplied
     * known-accounts list cannot be proven cycle-free, so it is
     * rejected outright — an incomplete context is never accepted as
     * proof of safety.
     */
    public function test_chain_beyond_known_accounts_is_rejected_as_unprovable(): void
    {
        $unknownAncestor = $this->makeAccount('account-unknown-ancestor');
        $child = $this->makeAccount('account-child');
        $parent = $this->makeAccount('account-parent')->withParent($unknownAncestor, [$unknownAncestor]);

        $this->expectException(InvalidAccountHierarchyException::class);

        // Deliberately omits $unknownAncestor from the supplied context.
        AccountHierarchyPolicy::assertParentAssignmentIsCycleFree($child, $parent, [$child, $parent]);
    }

    /**
     * A parent whose ancestry is fully known and terminates at a
     * genuine root (no parent) is proven cycle-free and accepted.
     */
    public function test_fully_known_chain_terminating_at_a_root_is_accepted(): void
    {
        $root = $this->makeAccount('account-root');
        $child = $this->makeAccount('account-child');
        $parent = $this->makeAccount('account-parent')->withParent($root, [$root]);

        AccountHierarchyPolicy::assertParentAssignmentIsCycleFree($child, $parent, [$root, $child, $parent]);

        $this->addToAssertionCount(1);
    }

    /**
     * Framework independence: no Illuminate/Eloquent dependency
     * anywhere in the file.
     */
    public function test_has_no_framework_dependency(): void
    {
        $reflection = new ReflectionClass(AccountHierarchyPolicy::class);
        $source = file_get_contents((string) $reflection->getFileName());

        $this->assertIsString($source);
        $this->assertStringNotContainsString('Illuminate\\', $source);
        $this->assertStringNotContainsString('Eloquent', $source);
    }

    /**
     * No repository or persistence dependency: this policy exposes
     * only its single static assertion method (plus the private
     * lookup helper it uses internally), and never extends or
     * implements any database-related type.
     */
    public function test_has_no_repository_or_persistence_dependency(): void
    {
        $reflection = new ReflectionClass(AccountHierarchyPolicy::class);

        $this->assertFalse($reflection->getParentClass());
        $this->assertSame([], $reflection->getInterfaceNames());

        $publicMethodNames = array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            $reflection->getMethods(\ReflectionMethod::IS_PUBLIC),
        );

        $this->assertSame(['assertParentAssignmentIsCycleFree'], $publicMethodNames);
    }
}
