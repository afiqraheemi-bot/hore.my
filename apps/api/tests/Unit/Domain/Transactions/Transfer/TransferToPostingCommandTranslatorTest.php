<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Transactions\Transfer;

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\Journal\JournalDirection;
use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Accounting\Money\Currency;
use App\Domain\Accounting\Money\Money;
use App\Domain\Accounting\Posting\ActorReference;
use App\Domain\Accounting\Posting\EvidenceReference;
use App\Domain\Accounting\Posting\IdempotencyKey;
use App\Domain\Shared\Tenancy\TenantId;
use App\Domain\Transactions\Transfer\RecordTransferCommand;
use App\Domain\Transactions\Transfer\TransferId;
use App\Domain\Transactions\Transfer\TransferToPostingCommandTranslator;
use PHPUnit\Framework\TestCase;

/**
 * Covers `TransferToPostingCommandTranslator` (M14) — the sole bridge
 * between a `RecordTransferCommand` (Transactions domain) and a
 * `PostingCommand` (Accounting Core). Proves the fixed accounting
 * mapping (Debit Destination Account, Credit Source Account), that no
 * Source Fingerprint is ever supplied, and that the Source reference is
 * self-referential and traceable, never fabricated.
 */
final class TransferToPostingCommandTranslatorTest extends TestCase
{
    private TransferToPostingCommandTranslator $translator;

    private TenantId $tenantId;

    private Money $amount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->translator = new TransferToPostingCommandTranslator;
        $this->tenantId = TenantId::of('tenant-0001');
        $this->amount = Money::fromDecimalString('75.50', Currency::of('MYR'));
    }

    public function test_produces_a_debit_destination_credit_source_pair(): void
    {
        $postingCommand = $this->translator->translate($this->makeCommand());

        $lines = $postingCommand->lines();
        $this->assertCount(2, $lines);

        $this->assertTrue($lines[0]->accountId()->equals(AccountId::of('account-petty-cash')));
        $this->assertSame(JournalDirection::Debit, $lines[0]->direction());
        $this->assertTrue($lines[0]->money()->equals($this->amount));

        $this->assertTrue($lines[1]->accountId()->equals(AccountId::of('account-bank')));
        $this->assertSame(JournalDirection::Credit, $lines[1]->direction());
        $this->assertTrue($lines[1]->money()->equals($this->amount));
    }

    public function test_journal_is_balanced_by_construction(): void
    {
        $postingCommand = $this->translator->translate($this->makeCommand());

        $lines = $postingCommand->lines();

        $this->assertTrue($lines[0]->money()->equals($lines[1]->money()));
        $this->assertNotSame($lines[0]->direction(), $lines[1]->direction());
    }

    public function test_financial_date_is_the_transfers_own_transaction_date(): void
    {
        $command = $this->makeCommand();
        $postingCommand = $this->translator->translate($command);

        $this->assertSame($command->transactionDate(), $postingCommand->financialDate());
        $this->assertSame('2026-09-07', $postingCommand->financialDate()->format('Y-m-d'));
    }

    public function test_carries_the_same_idempotency_key_tenant_actor_and_journal_id(): void
    {
        $command = $this->makeCommand();
        $postingCommand = $this->translator->translate($command);

        $this->assertTrue($postingCommand->idempotencyKey()->equals($command->idempotencyKey()));
        $this->assertTrue($postingCommand->tenantId()->equals($command->tenantId()));
        $this->assertTrue($postingCommand->actor()->equals($command->actor()));
        $this->assertTrue($postingCommand->journalId()->equals($command->journalId()));
    }

    public function test_never_supplies_a_source_fingerprint(): void
    {
        $postingCommand = $this->translator->translate($this->makeCommand());

        $this->assertNull($postingCommand->sourceFingerprint());
    }

    public function test_never_supplies_a_source_fingerprint_even_when_evidence_is_present(): void
    {
        $postingCommand = $this->translator->translate($this->makeCommand(EvidenceReference::of('evidence-0001')));

        $this->assertNull($postingCommand->sourceFingerprint());
    }

    public function test_source_reference_is_self_referential_to_the_transfer(): void
    {
        $command = $this->makeCommand();
        $postingCommand = $this->translator->translate($command);

        $this->assertSame('transfer:'.$command->transferId()->toString(), $postingCommand->source()->toString());
    }

    public function test_no_evidence_reference_produces_an_empty_evidence_list(): void
    {
        $postingCommand = $this->translator->translate($this->makeCommand());

        $this->assertSame([], $postingCommand->evidenceReferences());
    }

    public function test_evidence_reference_is_carried_through_to_the_posting_command(): void
    {
        $postingCommand = $this->translator->translate($this->makeCommand(EvidenceReference::of('evidence-0001')));

        $this->assertSame(['evidence-0001'], $postingCommand->evidenceReferences());
    }

    private function makeCommand(?EvidenceReference $evidenceReference = null): RecordTransferCommand
    {
        return new RecordTransferCommand(
            TransferId::of('transfer-0001'),
            JournalId::of('journal-0001'),
            IdempotencyKey::of('key-0001'),
            $this->tenantId,
            ActorReference::of('actor-0001'),
            $this->amount,
            new \DateTimeImmutable('2026-09-07'),
            AccountId::of('account-bank'),
            AccountId::of('account-petty-cash'),
            'Move float to petty cash',
            $evidenceReference,
        );
    }
}
