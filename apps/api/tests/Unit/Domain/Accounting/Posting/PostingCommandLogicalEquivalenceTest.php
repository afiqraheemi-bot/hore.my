<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Accounting\Posting;

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\Journal\JournalDirection;
use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Accounting\Journal\JournalLine;
use App\Domain\Accounting\Money\Currency;
use App\Domain\Accounting\Money\Money;
use App\Domain\Accounting\Posting\ActorReference;
use App\Domain\Accounting\Posting\IdempotencyKey;
use App\Domain\Accounting\Posting\PostingCommand;
use App\Domain\Accounting\Posting\PostingCommandLogicalEquivalence;
use App\Domain\Accounting\Posting\SourceFingerprint;
use App\Domain\Accounting\Posting\SourceReference;
use App\Domain\Shared\Tenancy\TenantId;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Covers `PostingCommandLogicalEquivalence` — the pure, storage-free
 * comparison rule AETS-007 §6.1 defines for distinguishing a safe
 * replay from a conflicting reuse. This is a *prerequisite* for the
 * future idempotency/replay mechanism, not that mechanism itself:
 * these tests do not, and cannot, evidence `POST-T021`–`POST-T023`
 * (which require an actual stored prior command and real
 * replay/rejection execution) — only the comparison logic those
 * future tests will depend on. No dedicated ATS-007 test ID exists
 * for this comparator on its own.
 */
final class PostingCommandLogicalEquivalenceTest extends TestCase
{
    private PostingCommandLogicalEquivalence $equivalence;

    private TenantId $tenantA;

    private TenantId $tenantB;

    private JournalId $journalId;

    private Currency $myr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->equivalence = new PostingCommandLogicalEquivalence;
        $this->tenantA = TenantId::of('tenant-0001');
        $this->tenantB = TenantId::of('tenant-0002');
        $this->journalId = JournalId::of('journal-0001');
        $this->myr = Currency::of('MYR');
    }

    /**
     * An identical logical payload is equivalent.
     */
    public function test_same_exact_payload_is_equivalent(): void
    {
        $left = $this->makeCommand($this->tenantA, $this->journalId, [
            $this->debitLine('account-cash', '100.00'),
            $this->creditLine('account-income', '100.00'),
        ]);
        $right = $this->makeCommand($this->tenantA, $this->journalId, [
            $this->debitLine('account-cash', '100.00'),
            $this->creditLine('account-income', '100.00'),
        ]);

        $this->assertTrue($this->equivalence->equivalent($left, $right));
    }

    /**
     * A different TenantId is not equivalent.
     */
    public function test_different_tenant_is_not_equivalent(): void
    {
        $left = $this->makeCommand($this->tenantA, $this->journalId, $this->balancedLines());
        $right = $this->makeCommand($this->tenantB, $this->journalId, $this->balancedLines());

        $this->assertFalse($this->equivalence->equivalent($left, $right));
    }

    /**
     * A different proposed JournalId is not equivalent.
     */
    public function test_different_journal_id_is_not_equivalent(): void
    {
        $left = $this->makeCommand($this->tenantA, JournalId::of('journal-0001'), $this->balancedLines());
        $right = $this->makeCommand($this->tenantA, JournalId::of('journal-0002'), $this->balancedLines());

        $this->assertFalse($this->equivalence->equivalent($left, $right));
    }

    /**
     * A different Journal Line count is not equivalent.
     */
    public function test_different_line_count_is_not_equivalent(): void
    {
        $left = $this->makeCommand($this->tenantA, $this->journalId, [
            $this->debitLine('account-cash', '100.00'),
            $this->creditLine('account-income', '100.00'),
        ]);
        $right = $this->makeCommand($this->tenantA, $this->journalId, [
            $this->debitLine('account-cash', '60.00'),
            $this->debitLine('account-other', '40.00'),
            $this->creditLine('account-income', '100.00'),
        ]);

        $this->assertFalse($this->equivalence->equivalent($left, $right));
    }

    /**
     * The same lines, reordered, is not equivalent — order is part of
     * what "same logical request" means.
     */
    public function test_reordered_lines_are_not_equivalent(): void
    {
        $left = $this->makeCommand($this->tenantA, $this->journalId, [
            $this->debitLine('account-cash', '10.00'),
            $this->debitLine('account-other', '20.00'),
            $this->creditLine('account-income', '30.00'),
        ]);
        $right = $this->makeCommand($this->tenantA, $this->journalId, [
            $this->debitLine('account-other', '20.00'),
            $this->debitLine('account-cash', '10.00'),
            $this->creditLine('account-income', '30.00'),
        ]);

        $this->assertFalse($this->equivalence->equivalent($left, $right));
    }

    /**
     * A different AccountId for an otherwise-matching line is not
     * equivalent.
     */
    public function test_account_id_difference_is_not_equivalent(): void
    {
        $left = $this->makeCommand($this->tenantA, $this->journalId, [
            $this->debitLine('account-cash', '100.00'),
            $this->creditLine('account-income', '100.00'),
        ]);
        $right = $this->makeCommand($this->tenantA, $this->journalId, [
            $this->debitLine('account-other', '100.00'),
            $this->creditLine('account-income', '100.00'),
        ]);

        $this->assertFalse($this->equivalence->equivalent($left, $right));
    }

    /**
     * A different Direction for an otherwise-matching line is not
     * equivalent.
     */
    public function test_direction_difference_is_not_equivalent(): void
    {
        $left = $this->makeCommand($this->tenantA, $this->journalId, [
            $this->debitLine('account-cash', '100.00'),
            $this->creditLine('account-income', '100.00'),
        ]);
        $right = $this->makeCommand($this->tenantA, $this->journalId, [
            $this->creditLine('account-cash', '100.00'),
            $this->debitLine('account-income', '100.00'),
        ]);

        $this->assertFalse($this->equivalence->equivalent($left, $right));
    }

    /**
     * A different Money amount for an otherwise-matching line is not
     * equivalent.
     */
    public function test_amount_difference_is_not_equivalent(): void
    {
        $left = $this->makeCommand($this->tenantA, $this->journalId, [
            $this->debitLine('account-cash', '100.00'),
            $this->creditLine('account-income', '100.00'),
        ]);
        $right = $this->makeCommand($this->tenantA, $this->journalId, [
            $this->debitLine('account-cash', '150.00'),
            $this->creditLine('account-income', '150.00'),
        ]);

        $this->assertFalse($this->equivalence->equivalent($left, $right));
    }

    /**
     * A different Currency for an otherwise-matching line is not
     * equivalent — proven via the same reflection-based
     * `currencyOtherThanMyr()` technique already established
     * elsewhere in this suite, since `Currency`'s registry currently
     * supports only `MYR`.
     */
    public function test_currency_difference_is_not_equivalent(): void
    {
        $left = $this->makeCommand($this->tenantA, $this->journalId, [
            $this->debitLine('account-cash', '100.00'),
            $this->creditLine('account-income', '100.00'),
        ]);

        $otherCurrency = $this->currencyOtherThanMyr();
        $right = $this->makeCommand($this->tenantA, $this->journalId, [
            JournalLine::create(AccountId::of('account-cash'), Money::fromDecimalString('100.00', $otherCurrency), JournalDirection::Debit),
            JournalLine::create(AccountId::of('account-income'), Money::fromDecimalString('100.00', $otherCurrency), JournalDirection::Credit),
        ]);

        $this->assertFalse($this->equivalence->equivalent($left, $right));
    }

    /**
     * An Actor difference alone does not affect equivalence — Actor
     * is not part of AETS-007 §6.1's logical-payload definition.
     */
    public function test_actor_difference_alone_remains_equivalent(): void
    {
        $left = $this->makeCommand($this->tenantA, $this->journalId, $this->balancedLines(), actor: ActorReference::of('actor-0001'));
        $right = $this->makeCommand($this->tenantA, $this->journalId, $this->balancedLines(), actor: ActorReference::of('actor-0002'));

        $this->assertTrue($this->equivalence->equivalent($left, $right));
    }

    /**
     * A Source difference alone does not affect equivalence.
     */
    public function test_source_difference_alone_remains_equivalent(): void
    {
        $left = $this->makeCommand($this->tenantA, $this->journalId, $this->balancedLines(), source: SourceReference::of('source-0001'));
        $right = $this->makeCommand($this->tenantA, $this->journalId, $this->balancedLines(), source: SourceReference::of('source-0002'));

        $this->assertTrue($this->equivalence->equivalent($left, $right));
    }

    /**
     * A Source Fingerprint difference alone does not affect
     * equivalence.
     */
    public function test_source_fingerprint_difference_alone_remains_equivalent(): void
    {
        $left = $this->makeCommand($this->tenantA, $this->journalId, $this->balancedLines(), sourceFingerprint: SourceFingerprint::of('fp-0001'));
        $right = $this->makeCommand($this->tenantA, $this->journalId, $this->balancedLines(), sourceFingerprint: SourceFingerprint::of('fp-0002'));

        $this->assertTrue($this->equivalence->equivalent($left, $right));
    }

    /**
     * An Evidence-reference difference alone does not affect
     * equivalence.
     */
    public function test_evidence_reference_difference_alone_remains_equivalent(): void
    {
        $left = $this->makeCommand($this->tenantA, $this->journalId, $this->balancedLines(), evidenceReferences: ['evidence-0001']);
        $right = $this->makeCommand($this->tenantA, $this->journalId, $this->balancedLines(), evidenceReferences: ['evidence-0002', 'evidence-0003']);

        $this->assertTrue($this->equivalence->equivalent($left, $right));
    }

    /**
     * An Idempotency Key difference alone does not affect the
     * comparator's result — scoping two commands to the same
     * idempotency key is the future caller's own responsibility,
     * never this comparator's (see this class's own docblock).
     */
    public function test_idempotency_key_difference_alone_does_not_affect_the_result(): void
    {
        $left = $this->makeCommand($this->tenantA, $this->journalId, $this->balancedLines(), idempotencyKey: IdempotencyKey::of('key-0001'));
        $right = $this->makeCommand($this->tenantA, $this->journalId, $this->balancedLines(), idempotencyKey: IdempotencyKey::of('key-0002'));

        $this->assertTrue($this->equivalence->equivalent($left, $right));
    }

    /**
     * The comparator never references `IdempotencyKey` in its own
     * source — confirming, structurally, that idempotency scoping is
     * never this class's concern.
     */
    public function test_never_references_idempotency_key_in_its_own_logic(): void
    {
        $reflection = new ReflectionClass(PostingCommandLogicalEquivalence::class);
        $source = file_get_contents((string) $reflection->getFileName());

        $this->assertIsString($source);
        $this->assertStringNotContainsString('idempotencyKey()', $source);
        $this->assertStringNotContainsString('IdempotencyKey::', $source);
    }

    /**
     * @return list<JournalLine>
     */
    private function balancedLines(): array
    {
        return [
            $this->debitLine('account-cash', '100.00'),
            $this->creditLine('account-income', '100.00'),
        ];
    }

    /**
     * @param  list<JournalLine>  $lines
     */
    private function makeCommand(
        TenantId $tenantId,
        JournalId $journalId,
        array $lines,
        ?ActorReference $actor = null,
        ?SourceReference $source = null,
        ?SourceFingerprint $sourceFingerprint = null,
        array $evidenceReferences = [],
        ?IdempotencyKey $idempotencyKey = null,
    ): PostingCommand {
        return new PostingCommand(
            $idempotencyKey ?? IdempotencyKey::of('key-0001'),
            $tenantId,
            $actor ?? ActorReference::of('actor-0001'),
            $source ?? SourceReference::of('source-0001'),
            $journalId,
            $lines,
            $sourceFingerprint,
            $evidenceReferences,
        );
    }

    private function debitLine(string $accountId, string $amount): JournalLine
    {
        return JournalLine::create(AccountId::of($accountId), Money::fromDecimalString($amount, $this->myr), JournalDirection::Debit);
    }

    private function creditLine(string $accountId, string $amount): JournalLine
    {
        return JournalLine::create(AccountId::of($accountId), Money::fromDecimalString($amount, $this->myr), JournalDirection::Credit);
    }

    private function currencyOtherThanMyr(): Currency
    {
        $reflection = new ReflectionClass(Currency::class);
        $other = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('identifier')->setValue($other, 'XXX');
        $reflection->getProperty('scale')->setValue($other, 2);

        return $other;
    }
}
