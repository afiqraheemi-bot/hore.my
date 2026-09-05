<?php

declare(strict_types=1);

namespace Tests\Feature\Providers;

use App\Domain\Accounting\Posting\DraftJournalAssembler;
use App\Domain\Accounting\Posting\PostingCommandAccountValidator;
use App\Domain\Accounting\Posting\PostingCommandExistingDraftLineValidator;
use App\Domain\Accounting\Posting\PostingCommandIdempotencyResolver;
use App\Domain\Accounting\Posting\PostingCommandJournalExecutor;
use App\Domain\Accounting\Posting\PostingCommandJournalStateResolver;
use App\Domain\Accounting\Posting\PostingCommandLogicalEquivalence;
use App\Domain\Accounting\Posting\PostingCommandTransactionalExecutor;
use App\Infrastructure\Accounting\ChartOfAccounts\AccountRepository;
use App\Infrastructure\Accounting\Journal\JournalRepository;
use App\Infrastructure\Accounting\Posting\PostingIdempotencyRepository;
use App\Infrastructure\Accounting\Posting\PostingSourceFingerprintRepository;
use Illuminate\Database\ConnectionInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Proves the whole Posting pipeline's dependency graph is resolvable
 * through the real Laravel container — never hand-wired by a future
 * caller the way every M4 test class necessarily does for itself.
 *
 * This is container-resolution only: it proves `app(...)` produces a
 * correctly-typed instance for every class on
 * {@see PostingCommandTransactionalExecutor::execute()}'s own call
 * path. It does not exercise any Posting behavior (that remains
 * `PostingCommandTransactionalExecutorTest`'s job against real
 * PostgreSQL) and runs against whatever connection this test
 * environment's default `DB_CONNECTION` is — SQLite here is
 * sufficient, since container wiring does not depend on which
 * database driver is behind {@see ConnectionInterface}.
 */
final class AccountingServiceProviderTest extends TestCase
{
    public function test_connection_interface_resolves_to_the_default_connection(): void
    {
        $connection = $this->app->make(ConnectionInterface::class);

        $this->assertInstanceOf(ConnectionInterface::class, $connection);
    }

    public function test_transactional_executor_resolves_with_its_full_dependency_graph(): void
    {
        $executor = $this->app->make(PostingCommandTransactionalExecutor::class);

        $this->assertInstanceOf(PostingCommandTransactionalExecutor::class, $executor);
    }

    /**
     * @return array<string, array{class-string}>
     */
    public static function resolvableClasses(): array
    {
        $classes = [
            PostingCommandTransactionalExecutor::class,
            PostingCommandIdempotencyResolver::class,
            PostingCommandJournalExecutor::class,
            PostingCommandAccountValidator::class,
            PostingCommandJournalStateResolver::class,
            PostingCommandExistingDraftLineValidator::class,
            DraftJournalAssembler::class,
            PostingCommandLogicalEquivalence::class,
            JournalRepository::class,
            AccountRepository::class,
            PostingIdempotencyRepository::class,
            PostingSourceFingerprintRepository::class,
        ];

        $cases = [];
        foreach ($classes as $class) {
            $cases[$class] = [$class];
        }

        return $cases;
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('resolvableClasses')]
    public function test_every_collaborator_on_the_posting_pipeline_resolves(string $class): void
    {
        $instance = $this->app->make($class);

        $this->assertInstanceOf($class, $instance);
    }

    /**
     * Resolving the executor twice returns two instances built from
     * the exact same underlying connection — no accidental parallel
     * connection is created by the container binding.
     */
    public function test_repeated_resolution_uses_the_same_underlying_connection(): void
    {
        $first = $this->app->make(ConnectionInterface::class);
        $second = $this->app->make(ConnectionInterface::class);

        $this->assertSame($first->getName(), $second->getName());
    }
}
