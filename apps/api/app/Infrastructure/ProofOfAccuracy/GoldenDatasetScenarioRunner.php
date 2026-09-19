<?php

declare(strict_types=1);

namespace App\Infrastructure\ProofOfAccuracy;

use App\Domain\Accounting\ChartOfAccounts\Account;
use App\Domain\Accounting\ChartOfAccounts\AccountCode;
use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\ChartOfAccounts\AccountName;
use App\Domain\Accounting\ChartOfAccounts\AccountOrigin;
use App\Domain\Accounting\ChartOfAccounts\AccountType;
use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Accounting\Money\Currency;
use App\Domain\Accounting\Money\Money;
use App\Domain\Accounting\Posting\ActorReference;
use App\Domain\Accounting\Posting\EvidenceReference;
use App\Domain\Accounting\Posting\IdempotencyKey;
use App\Domain\Banking\BankAccount;
use App\Domain\Banking\BankAccountId;
use App\Domain\Banking\BankStatementImportService;
use App\Domain\Banking\MatchingService;
use App\Domain\Banking\ReconciliationService;
use App\Domain\Customers\Customer;
use App\Domain\Customers\CustomerId;
use App\Domain\Evidence\EvidenceUploadService;
use App\Domain\Invoicing\Invoice;
use App\Domain\Invoicing\InvoiceId;
use App\Domain\Invoicing\InvoiceIssuingService;
use App\Domain\Invoicing\InvoiceLine;
use App\Domain\Payments\AllocationService;
use App\Domain\Payments\PaymentId;
use App\Domain\Payments\PaymentRecordingService;
use App\Domain\Payments\RecordPaymentCommand;
use App\Domain\ProofOfAccuracy\ExecutedCommandRecord;
use App\Domain\Shared\Tenancy\TenantId;
use App\Domain\Transactions\Expense\ExpenseId;
use App\Domain\Transactions\Expense\ExpenseRecordingService;
use App\Domain\Transactions\Expense\RecordExpenseCommand;
use App\Domain\Transactions\Income\IncomeId;
use App\Domain\Transactions\Income\IncomeRecordingService;
use App\Domain\Transactions\Income\RecordIncomeCommand;
use App\Domain\Transactions\Transfer\RecordTransferCommand;
use App\Domain\Transactions\Transfer\TransferId;
use App\Domain\Transactions\Transfer\TransferRecordingService;
use App\Infrastructure\Accounting\ChartOfAccounts\AccountRepository;
use App\Infrastructure\Banking\BankAccountRepository;
use App\Infrastructure\Customers\CustomerRepository;
use App\Infrastructure\Invoicing\InvoiceRepository;

/**
 * Executes AETS-012's Golden Dataset v1 `canonical/scenario-commands.json`
 * through this codebase's own real domain services — never a direct
 * database insert (AETS-012 §6.3) — for both the connected Tenant A
 * scenario and the colliding Tenant B isolation scenario, recording one
 * {@see ExecutedCommandRecord} per step for the eventual
 * `CertificationRecord`.
 *
 * {@see run()} itself is one-shot per database (Account/BankAccount/
 * Customer creation has no idempotency key and is never meant to be
 * replayed) — it is meant to be called exactly once per fresh
 * migration, which is what makes two independent clean-state calls to
 * it comparable for POA-009. The narrower claim that specific
 * *transactional* commands (Expense/Income/Transfer/Invoice/Payment
 * recording, bank statement import) are themselves replay-safe via
 * their own idempotency key or file-hash dedup is proved separately by
 * {@see runReplayProbe()}, which re-submits a sample of already-run
 * commands against the same populated database (POA-006).
 */
final class GoldenDatasetScenarioRunner
{
    use JsonScalarAccess;

    public function __construct(
        private readonly AccountRepository $accountRepository,
        private readonly BankAccountRepository $bankAccountRepository,
        private readonly CustomerRepository $customerRepository,
        private readonly InvoiceRepository $invoiceRepository,
        private readonly EvidenceUploadService $evidenceUploadService,
        private readonly ExpenseRecordingService $expenseRecordingService,
        private readonly IncomeRecordingService $incomeRecordingService,
        private readonly TransferRecordingService $transferRecordingService,
        private readonly BankStatementImportService $bankStatementImportService,
        private readonly MatchingService $matchingService,
        private readonly ReconciliationService $reconciliationService,
        private readonly InvoiceIssuingService $invoiceIssuingService,
        private readonly PaymentRecordingService $paymentRecordingService,
        private readonly AllocationService $allocationService,
    ) {}

    /**
     * @param  array<string, mixed>  $scenario  decoded `canonical/scenario-commands.json`
     * @return list<ExecutedCommandRecord>
     */
    public function run(array $scenario, string $datasetBaseDir, ActorReference $actor): array
    {
        $currency = Currency::of(self::jsonStr($scenario, 'currency'));
        $records = [];

        $this->runTenant(self::jsonArr($scenario, 'tenant_a'), $currency, $datasetBaseDir, $actor, $records);
        $this->runTenant(self::jsonArr($scenario, 'tenant_b'), $currency, $datasetBaseDir, $actor, $records);

        return $records;
    }

    /**
     * Re-submits Tenant A's first `record_expense` and first
     * `import_bank_statement` commands a second time, against the
     * *same* database {@see run()} already populated — proof that
     * this codebase's own idempotency-key and file-hash dedup (BNK-004)
     * make an exact replay a deterministic no-op rather than a
     * duplicate (POA-006). Must only be called after {@see run()} has
     * already executed once against the same database.
     *
     * @param  array<string, mixed>  $scenario  decoded `canonical/scenario-commands.json`
     */
    public function runReplayProbe(array $scenario, string $datasetBaseDir, ActorReference $actor): ReplayProbeResult
    {
        $currency = Currency::of(self::jsonStr($scenario, 'currency'));
        $tenant = self::jsonArr($scenario, 'tenant_a');
        $tenantId = TenantId::of(self::jsonStr($tenant, 'tenant_id'));

        /** @var array<string, EvidenceReference> $evidenceReferencesBySlug */
        $evidenceReferencesBySlug = [];

        if (isset($tenant['evidence'])) {
            $e = self::jsonArr($tenant, 'evidence');
            $sourceArtifact = self::jsonStr($e, 'source_artifact');
            // Re-uploading is not itself the replay under test here —
            // only the transactional commands below are — so this just
            // rebuilds the EvidenceReference the first upload already
            // produced, by re-uploading the byte-identical file
            // (Evidence has no idempotency key of its own; a second
            // upload legitimately creates a second Evidence row, which
            // is irrelevant to whether *record_expense* itself dedupes
            // on its own IdempotencyKey below).
            $contents = file_get_contents($datasetBaseDir.'/'.$sourceArtifact);
            if ($contents !== false) {
                $evidence = $this->evidenceUploadService->upload($tenantId, self::jsonStr($e, 'original_filename'), self::jsonStr($e, 'mime_type'), $contents, $actor);
                $evidenceReferencesBySlug[self::jsonStr($e, 'evidence_id')] = EvidenceReference::of($evidence->id()->toString());
            }
        }

        $records = [];

        /** @var ?array<string, mixed> $firstExpenseCmd */
        $firstExpenseCmd = null;
        /** @var ?array<string, mixed> $firstImportCmd */
        $firstImportCmd = null;

        foreach (self::jsonArrList($tenant, 'commands') as $cmd) {
            if ($firstExpenseCmd === null && self::jsonStr($cmd, 'type') === 'record_expense') {
                $firstExpenseCmd = $cmd;
            }

            if ($firstImportCmd === null && self::jsonStr($cmd, 'type') === 'import_bank_statement') {
                $firstImportCmd = $cmd;
            }
        }

        $expenseWasReplay = null;
        $importWasReplay = null;

        if ($firstExpenseCmd !== null) {
            $expenseIdRef = self::jsonStrOrNull($firstExpenseCmd, 'evidence_id_ref');
            $evidenceReference = $expenseIdRef !== null ? ($evidenceReferencesBySlug[$expenseIdRef] ?? null) : null;
            $expenseId = self::jsonStr($firstExpenseCmd, 'expense_id');

            $result = $this->expenseRecordingService->record(new RecordExpenseCommand(
                ExpenseId::of($expenseId),
                JournalId::of(self::jsonStr($firstExpenseCmd, 'journal_id')),
                IdempotencyKey::of(self::jsonStr($firstExpenseCmd, 'idempotency_key')),
                $tenantId,
                $actor,
                Money::fromDecimalString(self::jsonStr($firstExpenseCmd, 'amount'), $currency),
                new \DateTimeImmutable(self::jsonStr($firstExpenseCmd, 'transaction_date')),
                AccountId::of(self::jsonStr($firstExpenseCmd, 'expense_account_id')),
                AccountId::of(self::jsonStr($firstExpenseCmd, 'payment_account_id')),
                self::jsonStr($firstExpenseCmd, 'description'),
                $evidenceReference,
            ));

            $expenseWasReplay = $result->isReplay();
            $records[] = new ExecutedCommandRecord("poa:replay_probe:record_expense:{$expenseId}:replay={$this->boolStr($expenseWasReplay)}", 0);
        }

        if ($firstImportCmd !== null) {
            $sourceArtifact = self::jsonStr($firstImportCmd, 'source_artifact');
            $linkedAccountId = self::jsonStr($firstImportCmd, 'linked_account_id');
            $fileContent = file_get_contents($datasetBaseDir.'/'.$sourceArtifact);

            if ($fileContent !== false) {
                $bankAccountId = BankAccountId::of($linkedAccountId.'-bank-account');

                $result = $this->bankStatementImportService->import(
                    $tenantId,
                    $bankAccountId,
                    basename($sourceArtifact),
                    $fileContent,
                    $currency,
                );

                $importWasReplay = $result->isReplay();
                $records[] = new ExecutedCommandRecord("poa:replay_probe:import_bank_statement:{$linkedAccountId}:replay={$this->boolStr($importWasReplay)}", 0);
            }
        }

        return new ReplayProbeResult($records, $expenseWasReplay, $importWasReplay);
    }

    /**
     * @param  array<string, mixed>  $tenant
     * @param  list<ExecutedCommandRecord>  $records
     */
    private function runTenant(array $tenant, Currency $currency, string $baseDir, ActorReference $actor, array &$records): void
    {
        $tenantId = TenantId::of(self::jsonStr($tenant, 'tenant_id'));

        foreach (self::jsonArrList($tenant, 'accounts') as $a) {
            $accountId = self::jsonStr($a, 'account_id');
            $this->accountRepository->save(Account::create(
                $tenantId,
                AccountId::of($accountId),
                AccountCode::of(self::jsonStr($a, 'code')),
                AccountName::of(self::jsonStr($a, 'name')),
                self::accountType(self::jsonStr($a, 'type')),
                true,
                AccountOrigin::UserCreated,
            ));
            $records[] = new ExecutedCommandRecord("poa:create_account:{$accountId}", 0);
        }

        /** @var array<string, BankAccountId> $bankAccountIdsByLinkedAccount */
        $bankAccountIdsByLinkedAccount = [];

        foreach (self::jsonArrList($tenant, 'bank_accounts') as $ba) {
            $linkedAccountId = self::jsonStr($ba, 'linked_account_id');
            $bankAccountId = BankAccountId::of($linkedAccountId.'-bank-account');
            $this->bankAccountRepository->save(BankAccount::register(
                $bankAccountId,
                $tenantId,
                AccountId::of($linkedAccountId),
                self::jsonStr($ba, 'bank_name'),
                null,
            ));
            $bankAccountIdsByLinkedAccount[$linkedAccountId] = $bankAccountId;
            $records[] = new ExecutedCommandRecord("poa:register_bank_account:{$linkedAccountId}", 0);
        }

        foreach (self::jsonArrList($tenant, 'customers') as $c) {
            $customerId = self::jsonStr($c, 'customer_id');
            $this->customerRepository->save(Customer::register(
                CustomerId::of($customerId),
                $tenantId,
                self::jsonStr($c, 'name'),
                null,
                null,
                null,
                null,
                null,
            ));
            $records[] = new ExecutedCommandRecord("poa:register_customer:{$customerId}", 0);
        }

        /** @var array<string, EvidenceReference> $evidenceReferencesBySlug */
        $evidenceReferencesBySlug = [];

        if (isset($tenant['evidence'])) {
            $e = self::jsonArr($tenant, 'evidence');
            $sourceArtifact = self::jsonStr($e, 'source_artifact');
            $evidenceId = self::jsonStr($e, 'evidence_id');
            $contents = file_get_contents($baseDir.'/'.$sourceArtifact);

            if ($contents === false) {
                throw new \RuntimeException("Could not read evidence source artifact: {$sourceArtifact}");
            }

            $evidence = $this->evidenceUploadService->upload($tenantId, self::jsonStr($e, 'original_filename'), self::jsonStr($e, 'mime_type'), $contents, $actor);
            $evidenceReferencesBySlug[$evidenceId] = EvidenceReference::of($evidence->id()->toString());
            $records[] = new ExecutedCommandRecord("poa:upload_evidence:{$evidenceId}", 0);
        }

        foreach (self::jsonArrList($tenant, 'commands') as $cmd) {
            $this->runCommand($tenantId, $currency, $cmd, $baseDir, $actor, $bankAccountIdsByLinkedAccount, $evidenceReferencesBySlug, $records);
        }
    }

    /**
     * @param  array<string, mixed>  $cmd
     * @param  array<string, BankAccountId>  $bankAccountIdsByLinkedAccount
     * @param  array<string, EvidenceReference>  $evidenceReferencesBySlug
     * @param  list<ExecutedCommandRecord>  $records
     */
    private function runCommand(
        TenantId $tenantId,
        Currency $currency,
        array $cmd,
        string $baseDir,
        ActorReference $actor,
        array $bankAccountIdsByLinkedAccount,
        array $evidenceReferencesBySlug,
        array &$records,
    ): void {
        $type = self::jsonStr($cmd, 'type');

        match ($type) {
            'record_expense' => $this->handleRecordExpense($tenantId, $currency, $cmd, $actor, $evidenceReferencesBySlug, $records),
            'record_income' => $this->handleRecordIncome($tenantId, $currency, $cmd, $actor, $records),
            'record_transfer' => $this->handleRecordTransfer($tenantId, $currency, $cmd, $actor, $records),
            'import_bank_statement' => $this->handleImportBankStatement($tenantId, $currency, $cmd, $baseDir, $bankAccountIdsByLinkedAccount, $records),
            'confirm_all_suggested_matches' => $this->handleConfirmAllSuggestedMatches($tenantId, $cmd, $actor, $bankAccountIdsByLinkedAccount, $records),
            'open_and_complete_reconciliation' => $this->handleOpenAndCompleteReconciliation($tenantId, $currency, $cmd, $bankAccountIdsByLinkedAccount, $records),
            'issue_invoice' => $this->handleIssueInvoice($tenantId, $currency, $cmd, $actor, $records),
            'record_payment' => $this->handleRecordPayment($tenantId, $currency, $cmd, $actor, $records),
            'allocate_payment' => $this->handleAllocatePayment($tenantId, $currency, $cmd, $records),
            default => throw new \RuntimeException("Unknown Golden Dataset command type: {$type}"),
        };
    }

    /**
     * @param  array<string, mixed>  $cmd
     * @param  array<string, EvidenceReference>  $evidenceReferencesBySlug
     * @param  list<ExecutedCommandRecord>  $records
     */
    private function handleRecordExpense(TenantId $tenantId, Currency $currency, array $cmd, ActorReference $actor, array $evidenceReferencesBySlug, array &$records): void
    {
        $evidenceIdRef = self::jsonStrOrNull($cmd, 'evidence_id_ref');
        $evidenceReference = $evidenceIdRef !== null ? ($evidenceReferencesBySlug[$evidenceIdRef] ?? null) : null;
        $expenseId = self::jsonStr($cmd, 'expense_id');

        $result = $this->expenseRecordingService->record(new RecordExpenseCommand(
            ExpenseId::of($expenseId),
            JournalId::of(self::jsonStr($cmd, 'journal_id')),
            IdempotencyKey::of(self::jsonStr($cmd, 'idempotency_key')),
            $tenantId,
            $actor,
            Money::fromDecimalString(self::jsonStr($cmd, 'amount'), $currency),
            new \DateTimeImmutable(self::jsonStr($cmd, 'transaction_date')),
            AccountId::of(self::jsonStr($cmd, 'expense_account_id')),
            AccountId::of(self::jsonStr($cmd, 'payment_account_id')),
            self::jsonStr($cmd, 'description'),
            $evidenceReference,
        ));

        $records[] = new ExecutedCommandRecord("poa:record_expense:{$expenseId}:replay={$this->boolStr($result->isReplay())}", 0);
    }

    /**
     * @param  array<string, mixed>  $cmd
     * @param  list<ExecutedCommandRecord>  $records
     */
    private function handleRecordIncome(TenantId $tenantId, Currency $currency, array $cmd, ActorReference $actor, array &$records): void
    {
        $incomeId = self::jsonStr($cmd, 'income_id');

        $result = $this->incomeRecordingService->record(new RecordIncomeCommand(
            IncomeId::of($incomeId),
            JournalId::of(self::jsonStr($cmd, 'journal_id')),
            IdempotencyKey::of(self::jsonStr($cmd, 'idempotency_key')),
            $tenantId,
            $actor,
            Money::fromDecimalString(self::jsonStr($cmd, 'amount'), $currency),
            new \DateTimeImmutable(self::jsonStr($cmd, 'transaction_date')),
            AccountId::of(self::jsonStr($cmd, 'income_account_id')),
            AccountId::of(self::jsonStr($cmd, 'deposit_account_id')),
            self::jsonStr($cmd, 'description'),
        ));

        $records[] = new ExecutedCommandRecord("poa:record_income:{$incomeId}:replay={$this->boolStr($result->isReplay())}", 0);
    }

    /**
     * @param  array<string, mixed>  $cmd
     * @param  list<ExecutedCommandRecord>  $records
     */
    private function handleRecordTransfer(TenantId $tenantId, Currency $currency, array $cmd, ActorReference $actor, array &$records): void
    {
        $transferId = self::jsonStr($cmd, 'transfer_id');

        $this->transferRecordingService->record(new RecordTransferCommand(
            TransferId::of($transferId),
            JournalId::of(self::jsonStr($cmd, 'journal_id')),
            IdempotencyKey::of(self::jsonStr($cmd, 'idempotency_key')),
            $tenantId,
            $actor,
            Money::fromDecimalString(self::jsonStr($cmd, 'amount'), $currency),
            new \DateTimeImmutable(self::jsonStr($cmd, 'transaction_date')),
            AccountId::of(self::jsonStr($cmd, 'source_account_id')),
            AccountId::of(self::jsonStr($cmd, 'destination_account_id')),
            self::jsonStr($cmd, 'description'),
        ));

        $records[] = new ExecutedCommandRecord("poa:record_transfer:{$transferId}", 0);
    }

    /**
     * @param  array<string, mixed>  $cmd
     * @param  array<string, BankAccountId>  $bankAccountIdsByLinkedAccount
     * @param  list<ExecutedCommandRecord>  $records
     */
    private function handleImportBankStatement(TenantId $tenantId, Currency $currency, array $cmd, string $baseDir, array $bankAccountIdsByLinkedAccount, array &$records): void
    {
        $sourceArtifact = self::jsonStr($cmd, 'source_artifact');
        $linkedAccountId = self::jsonStr($cmd, 'linked_account_id');
        $fileContent = file_get_contents($baseDir.'/'.$sourceArtifact);

        if ($fileContent === false) {
            throw new \RuntimeException("Could not read bank statement source artifact: {$sourceArtifact}");
        }

        $bankAccountId = $bankAccountIdsByLinkedAccount[$linkedAccountId];

        $result = $this->bankStatementImportService->import(
            $tenantId,
            $bankAccountId,
            basename($sourceArtifact),
            $fileContent,
            $currency,
        );

        $records[] = new ExecutedCommandRecord("poa:import_bank_statement:{$linkedAccountId}:replay={$this->boolStr($result->isReplay())}", 0);
    }

    /**
     * @param  array<string, mixed>  $cmd
     * @param  array<string, BankAccountId>  $bankAccountIdsByLinkedAccount
     * @param  list<ExecutedCommandRecord>  $records
     */
    private function handleConfirmAllSuggestedMatches(TenantId $tenantId, array $cmd, ActorReference $actor, array $bankAccountIdsByLinkedAccount, array &$records): void
    {
        $linkedAccountId = self::jsonStr($cmd, 'linked_account_id');
        $bankAccountId = $bankAccountIdsByLinkedAccount[$linkedAccountId];

        $candidates = $this->matchingService->suggestFor($tenantId, $bankAccountId);

        foreach ($candidates as $candidate) {
            $this->matchingService->confirm($tenantId, $candidate->bankTransactionId(), $candidate->journalId(), $actor);
            $records[] = new ExecutedCommandRecord("poa:confirm_match:{$linkedAccountId}:{$candidate->bankTransactionId()->toString()}", 0);
        }
    }

    /**
     * @param  array<string, mixed>  $cmd
     * @param  array<string, BankAccountId>  $bankAccountIdsByLinkedAccount
     * @param  list<ExecutedCommandRecord>  $records
     */
    private function handleOpenAndCompleteReconciliation(TenantId $tenantId, Currency $currency, array $cmd, array $bankAccountIdsByLinkedAccount, array &$records): void
    {
        $linkedAccountId = self::jsonStr($cmd, 'linked_account_id');
        $reconciliationLabel = self::jsonStr($cmd, 'reconciliation_id');
        $bankAccountId = $bankAccountIdsByLinkedAccount[$linkedAccountId];

        $reconciliation = $this->reconciliationService->open(
            $tenantId,
            $bankAccountId,
            new \DateTimeImmutable(self::jsonStr($cmd, 'period_start')),
            new \DateTimeImmutable(self::jsonStr($cmd, 'period_end')),
            Money::fromDecimalString(self::jsonStr($cmd, 'opening_balance'), $currency),
            Money::fromDecimalString(self::jsonStr($cmd, 'closing_balance'), $currency),
        );
        $records[] = new ExecutedCommandRecord("poa:open_reconciliation:{$reconciliationLabel}", 0);

        $this->reconciliationService->startReview($tenantId, $reconciliation->id());
        $records[] = new ExecutedCommandRecord("poa:start_review_reconciliation:{$reconciliationLabel}", 0);

        $this->reconciliationService->markBalanced($tenantId, $reconciliation->id());
        $records[] = new ExecutedCommandRecord("poa:mark_balanced_reconciliation:{$reconciliationLabel}", 0);

        $this->reconciliationService->complete($tenantId, $reconciliation->id());
        $records[] = new ExecutedCommandRecord("poa:complete_reconciliation:{$reconciliationLabel}", 0);
    }

    /**
     * @param  array<string, mixed>  $cmd
     * @param  list<ExecutedCommandRecord>  $records
     */
    private function handleIssueInvoice(TenantId $tenantId, Currency $currency, array $cmd, ActorReference $actor, array &$records): void
    {
        $invoiceIdValue = self::jsonStr($cmd, 'invoice_id');
        $invoiceId = InvoiceId::of($invoiceIdValue);

        /** @var list<InvoiceLine> $lines */
        $lines = array_map(
            static fn (array $line): InvoiceLine => InvoiceLine::of(
                self::jsonStr($line, 'description'),
                self::jsonInt($line, 'quantity'),
                Money::fromDecimalString(self::jsonStr($line, 'unit_price'), $currency),
            ),
            self::jsonArrList($cmd, 'lines'),
        );

        $this->invoiceRepository->save(Invoice::draft(
            $invoiceId,
            $tenantId,
            CustomerId::of(self::jsonStr($cmd, 'customer_id')),
            new \DateTimeImmutable(self::jsonStr($cmd, 'due_date')),
            AccountId::of(self::jsonStr($cmd, 'receivable_account_id')),
            AccountId::of(self::jsonStr($cmd, 'revenue_account_id')),
            $lines,
            $currency,
        ));
        $records[] = new ExecutedCommandRecord("poa:draft_invoice:{$invoiceIdValue}", 0);

        $result = $this->invoiceIssuingService->issue(
            $tenantId,
            $invoiceId,
            JournalId::of(self::jsonStr($cmd, 'journal_id')),
            IdempotencyKey::of(self::jsonStr($cmd, 'idempotency_key')),
            $actor,
            new \DateTimeImmutable(self::jsonStr($cmd, 'issue_date')),
        );

        $records[] = new ExecutedCommandRecord("poa:issue_invoice:{$invoiceIdValue}:replay={$this->boolStr($result->isReplay())}", 0);
    }

    /**
     * @param  array<string, mixed>  $cmd
     * @param  list<ExecutedCommandRecord>  $records
     */
    private function handleRecordPayment(TenantId $tenantId, Currency $currency, array $cmd, ActorReference $actor, array &$records): void
    {
        $paymentId = self::jsonStr($cmd, 'payment_id');

        $result = $this->paymentRecordingService->record(new RecordPaymentCommand(
            PaymentId::of($paymentId),
            JournalId::of(self::jsonStr($cmd, 'journal_id')),
            IdempotencyKey::of(self::jsonStr($cmd, 'idempotency_key')),
            $tenantId,
            $actor,
            CustomerId::of(self::jsonStr($cmd, 'customer_id')),
            Money::fromDecimalString(self::jsonStr($cmd, 'amount'), $currency),
            new \DateTimeImmutable(self::jsonStr($cmd, 'payment_date')),
            AccountId::of(self::jsonStr($cmd, 'deposit_account_id')),
            AccountId::of(self::jsonStr($cmd, 'receivable_account_id')),
            self::jsonStrOrNull($cmd, 'reference'),
        ));

        $records[] = new ExecutedCommandRecord("poa:record_payment:{$paymentId}:replay={$this->boolStr($result->isReplay())}", 0);
    }

    /**
     * @param  array<string, mixed>  $cmd
     * @param  list<ExecutedCommandRecord>  $records
     */
    private function handleAllocatePayment(TenantId $tenantId, Currency $currency, array $cmd, array &$records): void
    {
        $paymentId = self::jsonStr($cmd, 'payment_id');
        $invoiceId = self::jsonStr($cmd, 'invoice_id');

        $this->allocationService->allocate(
            $tenantId,
            PaymentId::of($paymentId),
            InvoiceId::of($invoiceId),
            Money::fromDecimalString(self::jsonStr($cmd, 'amount'), $currency),
        );

        $records[] = new ExecutedCommandRecord("poa:allocate_payment:{$paymentId}:{$invoiceId}", 0);
    }

    private function boolStr(bool $value): string
    {
        return $value ? 'true' : 'false';
    }

    private static function accountType(string $type): AccountType
    {
        return match ($type) {
            'Asset' => AccountType::Asset,
            'Liability' => AccountType::Liability,
            'Equity' => AccountType::Equity,
            'Revenue' => AccountType::Revenue,
            'Expense' => AccountType::Expense,
            default => throw new \RuntimeException("Unknown AccountType: {$type}"),
        };
    }
}
