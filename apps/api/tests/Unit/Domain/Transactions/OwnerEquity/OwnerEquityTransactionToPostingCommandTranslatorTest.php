<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Transactions\OwnerEquity;

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\Journal\JournalDirection;
use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Accounting\Money\Currency;
use App\Domain\Accounting\Money\Money;
use App\Domain\Accounting\Posting\ActorReference;
use App\Domain\Accounting\Posting\EvidenceReference;
use App\Domain\Accounting\Posting\IdempotencyKey;
use App\Domain\Shared\Tenancy\TenantId;
use App\Domain\Transactions\OwnerEquity\OwnerEquityMovementType;
use App\Domain\Transactions\OwnerEquity\OwnerEquityTransactionId;
use App\Domain\Transactions\OwnerEquity\OwnerEquityTransactionToPostingCommandTranslator;
use App\Domain\Transactions\OwnerEquity\RecordOwnerEquityTransactionCommand;
use PHPUnit\Framework\TestCase;

/**
 * Covers `OwnerEquityTransactionToPostingCommandTranslator` (M15) — the
 * sole bridge between a `RecordOwnerEquityTransactionCommand`
 * (Transactions domain) and a `PostingCommand` (Accounting Core).
 * Proves the fixed, movement-type-dependent accounting mapping (a
 * Contribution and a Drawing are exact reverses of each other), that no
 * Source Fingerprint is ever supplied, and that the Source reference is
 * self-referential and traceable regardless of movement type.
 */
final class OwnerEquityTransactionToPostingCommandTranslatorTest extends TestCase
{
    private OwnerEquityTransactionToPostingCommandTranslator $translator;

    private TenantId $tenantId;

    private Money $amount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->translator = new OwnerEquityTransactionToPostingCommandTranslator;
        $this->tenantId = TenantId::of('tenant-0001');
        $this->amount = Money::fromDecimalString('500.00', Currency::of('MYR'));
    }

    public function test_a_contribution_debits_cash_and_credits_equity(): void
    {
        $postingCommand = $this->translator->translate($this->makeCommand(OwnerEquityMovementType::Contribution));

        $lines = $postingCommand->lines();
        $this->assertCount(2, $lines);

        $this->assertTrue($lines[0]->accountId()->equals(AccountId::of('account-bank')));
        $this->assertSame(JournalDirection::Debit, $lines[0]->direction());
        $this->assertTrue($lines[0]->money()->equals($this->amount));

        $this->assertTrue($lines[1]->accountId()->equals(AccountId::of('account-owner-capital')));
        $this->assertSame(JournalDirection::Credit, $lines[1]->direction());
        $this->assertTrue($lines[1]->money()->equals($this->amount));
    }

    public function test_a_drawing_debits_equity_and_credits_cash(): void
    {
        $postingCommand = $this->translator->translate($this->makeCommand(OwnerEquityMovementType::Drawing));

        $lines = $postingCommand->lines();
        $this->assertCount(2, $lines);

        $this->assertTrue($lines[0]->accountId()->equals(AccountId::of('account-owner-capital')));
        $this->assertSame(JournalDirection::Debit, $lines[0]->direction());
        $this->assertTrue($lines[0]->money()->equals($this->amount));

        $this->assertTrue($lines[1]->accountId()->equals(AccountId::of('account-bank')));
        $this->assertSame(JournalDirection::Credit, $lines[1]->direction());
        $this->assertTrue($lines[1]->money()->equals($this->amount));
    }

    public function test_journal_is_balanced_by_construction_for_both_movement_types(): void
    {
        foreach (OwnerEquityMovementType::cases() as $movementType) {
            $postingCommand = $this->translator->translate($this->makeCommand($movementType));
            $lines = $postingCommand->lines();

            $this->assertTrue($lines[0]->money()->equals($lines[1]->money()));
            $this->assertNotSame($lines[0]->direction(), $lines[1]->direction());
        }
    }

    public function test_financial_date_is_the_commands_own_transaction_date(): void
    {
        $command = $this->makeCommand(OwnerEquityMovementType::Contribution);
        $postingCommand = $this->translator->translate($command);

        $this->assertSame($command->transactionDate(), $postingCommand->financialDate());
        $this->assertSame('2026-09-07', $postingCommand->financialDate()->format('Y-m-d'));
    }

    public function test_carries_the_same_idempotency_key_tenant_actor_and_journal_id(): void
    {
        $command = $this->makeCommand(OwnerEquityMovementType::Drawing);
        $postingCommand = $this->translator->translate($command);

        $this->assertTrue($postingCommand->idempotencyKey()->equals($command->idempotencyKey()));
        $this->assertTrue($postingCommand->tenantId()->equals($command->tenantId()));
        $this->assertTrue($postingCommand->actor()->equals($command->actor()));
        $this->assertTrue($postingCommand->journalId()->equals($command->journalId()));
    }

    public function test_never_supplies_a_source_fingerprint(): void
    {
        $postingCommand = $this->translator->translate($this->makeCommand(OwnerEquityMovementType::Contribution));

        $this->assertNull($postingCommand->sourceFingerprint());
    }

    public function test_source_reference_is_self_referential_regardless_of_movement_type(): void
    {
        foreach (OwnerEquityMovementType::cases() as $movementType) {
            $command = $this->makeCommand($movementType);
            $postingCommand = $this->translator->translate($command);

            $this->assertSame('owner-equity:'.$command->transactionId()->toString(), $postingCommand->source()->toString());
        }
    }

    public function test_no_evidence_reference_produces_an_empty_evidence_list(): void
    {
        $postingCommand = $this->translator->translate($this->makeCommand(OwnerEquityMovementType::Contribution));

        $this->assertSame([], $postingCommand->evidenceReferences());
    }

    public function test_evidence_reference_is_carried_through_to_the_posting_command(): void
    {
        $postingCommand = $this->translator->translate(
            $this->makeCommand(OwnerEquityMovementType::Contribution, EvidenceReference::of('evidence-0001')),
        );

        $this->assertSame(['evidence-0001'], $postingCommand->evidenceReferences());
    }

    private function makeCommand(OwnerEquityMovementType $movementType, ?EvidenceReference $evidenceReference = null): RecordOwnerEquityTransactionCommand
    {
        return new RecordOwnerEquityTransactionCommand(
            OwnerEquityTransactionId::of('owner-equity-0001'),
            JournalId::of('journal-0001'),
            IdempotencyKey::of('key-0001'),
            $this->tenantId,
            ActorReference::of('actor-0001'),
            $movementType,
            $this->amount,
            new \DateTimeImmutable('2026-09-07'),
            AccountId::of('account-owner-capital'),
            AccountId::of('account-bank'),
            'Owner equity movement',
            $evidenceReference,
        );
    }
}
