<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Accounting\Posting;

use App\Domain\Accounting\Posting\DraftJournalAssembler;
use App\Domain\Accounting\Posting\PostingCommand;
use App\Domain\Accounting\Posting\PostingCommandAccountValidator;
use App\Domain\Accounting\Posting\PostingCommandCandidateJournalResolver;
use App\Domain\Accounting\Posting\PostingCommandExistingDraftLineValidator;
use App\Domain\Accounting\Posting\PostingCommandIdempotencyResolver;
use App\Domain\Accounting\Posting\PostingCommandJournalExecutor;
use App\Domain\Accounting\Posting\PostingCommandJournalStateResolver;
use App\Domain\Accounting\Posting\PostingCommandLogicalEquivalence;
use App\Domain\Accounting\Posting\PostingCommandTransactionalExecutor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Architecture-only proof for `POST-T030`: "No code path in the
 * Posting Command intake or validation pipeline generates a Source
 * Fingerprint value itself for a manual command, to paper over
 * `POST-T029`'s requirement" (AETS-007 §6.2, §20; `POST-027`).
 *
 * **What this proves, and what it does not.** `SourceFingerprint` is
 * accepted by `PostingCommand` only as a caller-supplied,
 * already-derived value (§6.2: "this document does not invent a
 * Source Fingerprint derivation algorithm... deferred alongside
 * Idempotency Key derivation"). This test proves, by scanning every
 * class on the Posting pipeline's own call path, that none of them
 * ever calls `SourceFingerprint::of()` (or constructs one any other
 * way) to manufacture a value on the caller's behalf — the only way a
 * `PostingCommand` ever carries one is because a caller passed it in.
 *
 * This does **not** evidence `POST-T029` (the runtime rejection of a
 * caller-fabricated fingerprint for a command with no real external
 * source behind it) — that requires the still-deferred
 * requirement-detection policy (AETS-007 §6.2/§26: "determined by the
 * calling module... this document does not enumerate every business
 * context"), which does not exist in this codebase yet. `POST-T029`
 * remains open pending that future calling module.
 */
final class PostingCommandNeverGeneratesSourceFingerprintTest extends TestCase
{
    /**
     * @return array<string, array{class-string}>
     */
    public static function postingPipelineClasses(): array
    {
        $classes = [
            PostingCommand::class,
            PostingCommandTransactionalExecutor::class,
            PostingCommandJournalExecutor::class,
            PostingCommandAccountValidator::class,
            PostingCommandJournalStateResolver::class,
            PostingCommandCandidateJournalResolver::class,
            PostingCommandExistingDraftLineValidator::class,
            DraftJournalAssembler::class,
            PostingCommandIdempotencyResolver::class,
            PostingCommandLogicalEquivalence::class,
        ];

        $cases = [];
        foreach ($classes as $class) {
            $cases[$class] = [$class];
        }

        return $cases;
    }

    #[DataProvider('postingPipelineClasses')]
    public function test_never_generates_a_source_fingerprint_value(string $class): void
    {
        $reflection = new ReflectionClass($class);
        $source = file_get_contents((string) $reflection->getFileName());

        $this->assertIsString($source);
        $this->assertStringNotContainsString('SourceFingerprint::of(', $source, sprintf(
            '%s must never construct a SourceFingerprint itself — every value must originate from a caller, '.
            'never be fabricated by the pipeline to satisfy AETS-007 §6.2 (POST-027, POST-T030).',
            $class,
        ));
    }
}
