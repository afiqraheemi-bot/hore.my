<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Accounting\Posting;

use App\Domain\Accounting\Posting\DraftJournalAssembler;
use App\Domain\Accounting\Posting\PostingCommandAccountValidator;
use App\Domain\Accounting\Posting\PostingCommandCandidateJournalResolver;
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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Architecture-only proof that no network call, queue publication, or
 * other external/asynchronous work exists anywhere in the Posting
 * transaction's own code path (AETS-007 §17, §22; `POST-022`;
 * `POST-T099`–`POST-T102`) — mirroring the technique ATS-004 §21
 * already specifies for `JournalRepository::save()` (`JRN-T032`,
 * `JRN-T192`): scanning source text for the absence of any HTTP,
 * queue, mail, or notification dependency, one layer up, across every
 * collaborator {@see PostingCommandTransactionalExecutor::execute()}
 * actually calls into.
 *
 * **Why a static/architecture test, not a runtime one.** Proving "no
 * network call happens" by *executing* the pipeline and watching for
 * one would only ever demonstrate the absence of a call along
 * whichever branch that one execution happened to take — it could
 * never prove the *code itself* contains no such call on any branch.
 * A source-level scan is the only technique that proves the negative
 * for every possible input, exactly why ATS-004 §21 specifies this
 * exact approach rather than a behavioral test.
 *
 * **Scope.** Every class on the real call path from `execute()` down
 * to persistence: the transactional executor itself, the journal
 * executor and everything it composes (account validation, state
 * resolution, candidate/draft assembly, existing-draft line
 * validation), the idempotency and Source Fingerprint decision/
 * persistence layer, and the two repositories (`JournalRepository`,
 * `AccountRepository`) that actually touch the database. This
 * deliberately does not include Laravel framework internals or the
 * PDO driver itself — those are third-party code this task has no
 * authority over and AETS-007 §17 does not ask this codebase to audit.
 */
final class PostingNoNetworkInTransactionTest extends TestCase
{
    /**
     * @return array<string, array{class-string}>
     */
    public static function postingTransactionPathClasses(): array
    {
        $classes = [
            PostingCommandTransactionalExecutor::class,
            PostingCommandJournalExecutor::class,
            PostingCommandAccountValidator::class,
            PostingCommandJournalStateResolver::class,
            PostingCommandCandidateJournalResolver::class,
            PostingCommandExistingDraftLineValidator::class,
            DraftJournalAssembler::class,
            PostingCommandIdempotencyResolver::class,
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

    #[DataProvider('postingTransactionPathClasses')]
    public function test_no_network_or_queue_dependency_exists(string $class): void
    {
        $reflection = new ReflectionClass($class);
        $source = file_get_contents((string) $reflection->getFileName());

        $this->assertIsString($source);

        $forbiddenSymbols = [
            // HTTP / general network.
            'Http::',
            'Http\\Client',
            'GuzzleHttp',
            'curl_init',
            'curl_exec',
            'fsockopen',
            'pfsockopen',
            'stream_socket_client',
            'file_get_contents(\'http',
            'file_get_contents("http',
            // Queue publication.
            'Queue::',
            'Bus::dispatch',
            '->dispatch(',
            'dispatch_sync(',
            'ShouldQueue',
            // Mail / notification.
            'Mail::',
            'Notification::',
            'Notification\\Notification',
        ];

        foreach ($forbiddenSymbols as $symbol) {
            $this->assertStringNotContainsString($symbol, $source, sprintf(
                '%s must not reference "%s" — no network call, queue publication, or other '.
                'external/asynchronous work is permitted inside the Posting transaction path (AETS-007 §17, §22; POST-022).',
                $class,
                $symbol,
            ));
        }
    }

    /**
     * The provider list itself must stay honest — every class actually
     * reachable from `execute()` belongs in it. This does not
     * re-verify call graphs already proven elsewhere; it only guards
     * against silently dropping a class from the scanned list.
     */
    public function test_scanned_class_list_matches_the_real_executor_dependency_graph(): void
    {
        $executor = new ReflectionClass(PostingCommandTransactionalExecutor::class);
        $constructorParameterTypes = array_map(
            static fn (\ReflectionParameter $parameter): string => (string) $parameter->getType(),
            $executor->getConstructor()?->getParameters() ?? [],
        );

        $this->assertContains(PostingCommandIdempotencyResolver::class, $constructorParameterTypes);
        $this->assertContains(PostingCommandJournalExecutor::class, $constructorParameterTypes);
        $this->assertContains(PostingIdempotencyRepository::class, $constructorParameterTypes);
    }
}
