<?php

declare(strict_types=1);

namespace App\Domain\Accounting\ChartOfAccounts;

use App\Domain\Accounting\ChartOfAccounts\Exception\InvalidAccountHierarchyException;

/**
 * Detects a direct or transitive cycle a proposed parent assignment
 * would create (AETS-005 §12, `COA-006`) — the one hierarchy check
 * {@see Account::withParent()} deliberately cannot perform on its own,
 * since it requires walking the wider ancestry chain, not just the
 * two Account instances directly involved.
 *
 * This is the smallest possible domain service for that purpose: a
 * single stateless method operating entirely on an in-memory list of
 * already-known {@see Account} instances the caller supplies. It never
 * loads an Account itself, holds no repository, and knows nothing
 * about persistence.
 *
 * **Fails closed.** An ancestor beyond the supplied `$knownAccounts`
 * is not proof of anything — it is simply unprovable. Rather than
 * treat that gap as "probably safe," this policy rejects the
 * assignment outright whenever `$proposedParent`'s ancestry cannot be
 * walked all the way to a genuine root (an Account with no parent)
 * using only `$knownAccounts`. A caller that wants an assignment
 * accepted must supply a complete-enough ancestry chain to prove it.
 */
final class AccountHierarchyPolicy
{
    /**
     * Assert that assigning `$proposedParent` as `$child`'s parent
     * would not make `$child` its own ancestor, directly or
     * transitively, by walking `$proposedParent`'s own ancestry chain
     * through `$knownAccounts` all the way to a genuine root.
     *
     * @param  list<Account>  $knownAccounts  every Account whose parent
     *                                        pointer might need to be walked to prove the assignment
     *                                        cycle-free — supplied by the caller from already-loaded,
     *                                        in-memory Account instances.
     *
     * @throws InvalidAccountHierarchyException if the assignment would
     *                                          create a direct or transitive cycle, or if the ancestry
     *                                          chain cannot be fully proven cycle-free from
     *                                          `$knownAccounts` alone.
     */
    public static function assertParentAssignmentIsCycleFree(
        Account $child,
        Account $proposedParent,
        array $knownAccounts,
    ): void {
        $visited = [$child->id()->toString() => true];
        $current = $proposedParent;

        while (true) {
            $currentIdString = $current->id()->toString();

            if (isset($visited[$currentIdString])) {
                throw InvalidAccountHierarchyException::forCycle($child->id());
            }

            $visited[$currentIdString] = true;

            $parentId = $current->parentId();

            if ($parentId === null) {
                // A genuine root — every step from the proposed parent
                // up to here is now proven cycle-free.
                return;
            }

            $next = self::findById($parentId, $knownAccounts);

            if ($next === null) {
                // The chain continues beyond the known accounts: this
                // cannot be proven cycle-free, so it fails closed
                // rather than being accepted as safe by assumption.
                throw InvalidAccountHierarchyException::forIncompleteAncestryContext($child->id(), $parentId);
            }

            $current = $next;
        }
    }

    /**
     * @param  list<Account>  $knownAccounts
     */
    private static function findById(AccountId $id, array $knownAccounts): ?Account
    {
        foreach ($knownAccounts as $account) {
            if ($account->id()->equals($id)) {
                return $account;
            }
        }

        return null;
    }
}
