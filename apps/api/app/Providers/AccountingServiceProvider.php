<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

/**
 * Container wiring for the Accounting module's Posting pipeline
 * (M4). This binds only the one interface Laravel's container cannot
 * resolve on its own — every collaborator on
 * `PostingCommandTransactionalExecutor::execute()`'s own call path
 * (`PostingCommandIdempotencyResolver`, `PostingCommandJournalExecutor`,
 * `PostingCommandAccountValidator`, `PostingCommandJournalStateResolver`,
 * `PostingCommandExistingDraftLineValidator`, `DraftJournalAssembler`,
 * `PostingCommandLogicalEquivalence`, `JournalRepository`,
 * `AccountRepository`, `PostingIdempotencyRepository`,
 * `PostingSourceFingerprintRepository`) declares only concrete-class
 * constructor dependencies, which the container already resolves via
 * reflection without any binding here.
 *
 * **Why `ConnectionInterface`, not a connection name.** Every
 * repository on the Posting pipeline is written against
 * `Illuminate\Database\ConnectionInterface`, deliberately never
 * against a specific connection name string, so the exact same
 * classes already used throughout M4's own real-PostgreSQL test suite
 * are what the container now wires for the running application too —
 * no parallel, container-only construction path is introduced.
 *
 * **What this does not do.** It does not introduce an HTTP route, a
 * console command, a queue job, or any other application entry point
 * — none exists yet for any module in this application. It does not
 * define a public application contract/facade for the Accounting
 * module as ADR-0001 §"module boundaries" eventually calls for — that
 * is a real architectural decision (the contract's shape) deliberately
 * left for the Founder/architecture owner, not invented here. This
 * provider only makes the already-existing, already-tested classes
 * resolvable through the container instead of requiring every future
 * caller to hand-wire the same ~10-class dependency graph this
 * module's own test suite already wires by hand.
 */
final class AccountingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ConnectionInterface::class, static fn (): ConnectionInterface => DB::connection());
    }
}
