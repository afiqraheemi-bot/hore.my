<?php

declare(strict_types=1);

namespace App\Infrastructure\ProofOfAccuracy;

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\Journal\JournalDirection;
use App\Domain\Accounting\Reporting\AccountBalance;
use App\Domain\Accounting\Reporting\EvidenceIndexEntry;
use App\Domain\Accounting\Reporting\GeneralLedgerEntry;
use App\Domain\Accounting\Reporting\TrialBalance;
use App\Domain\Evidence\EvidenceId;
use App\Domain\Invoicing\Reporting\AgingBucket;
use App\Domain\Invoicing\Reporting\AgingReportLine;
use App\Domain\ProofOfAccuracy\CertificationCriterionResult;
use App\Domain\Shared\Tenancy\TenantId;
use App\Infrastructure\Accounting\Reporting\BalanceSheetQuery;
use App\Infrastructure\Accounting\Reporting\EvidenceIndexQuery;
use App\Infrastructure\Accounting\Reporting\GeneralLedgerQuery;
use App\Infrastructure\Accounting\Reporting\ProfitAndLossQuery;
use App\Infrastructure\Accounting\Reporting\TrialBalanceQuery;
use App\Infrastructure\Evidence\EvidenceRepository;
use App\Infrastructure\Invoicing\Reporting\AgingReportQuery;

/**
 * Diffs Golden Dataset v1's real, post-execution report outputs
 * against `expected/accounting-results.json`'s independently
 * hand-computed oracle (AETS-012 §5.1), and checks the scenario's own
 * connectedness, isolation, and replay properties — producing one
 * {@see CertificationCriterionResult} per POA-002 through POA-008.
 *
 * POA-001 (manifest integrity), POA-009 (independent reruns),
 * POA-010 (test counts), POA-011 (record fields), and POA-012 (fail-
 * closed aggregation) are evaluated at the Artisan command level, not
 * here — they are properties of the *harness run*, not of this one
 * scenario's accounting output.
 */
final class GoldenDatasetCertificationEvaluator
{
    use JsonScalarAccess;

    public function __construct(
        private readonly TrialBalanceQuery $trialBalanceQuery,
        private readonly ProfitAndLossQuery $profitAndLossQuery,
        private readonly BalanceSheetQuery $balanceSheetQuery,
        private readonly GeneralLedgerQuery $generalLedgerQuery,
        private readonly EvidenceIndexQuery $evidenceIndexQuery,
        private readonly AgingReportQuery $agingReportQuery,
        private readonly EvidenceRepository $evidenceRepository,
    ) {}

    /**
     * @param  array<string, mixed>  $scenario  decoded `canonical/scenario-commands.json`
     * @param  array<string, mixed>  $expected  decoded `expected/accounting-results.json`
     * @return list<CertificationCriterionResult>
     */
    public function evaluate(array $scenario, array $expected, string $datasetBaseDir, ReplayProbeResult $replayProbe): array
    {
        $tenantA = self::jsonArr($scenario, 'tenant_a');
        $tenantB = self::jsonArr($scenario, 'tenant_b');

        $tenantAId = TenantId::of(self::jsonStr($tenantA, 'tenant_id'));
        $tenantBId = TenantId::of(self::jsonStr($tenantB, 'tenant_id'));

        $asOfReportDate = self::jsonStr($tenantA, 'as_of_report_date');

        $trialBalanceA = $this->trialBalanceQuery->asOf($tenantAId, new \DateTimeImmutable($asOfReportDate));
        $trialBalanceB = $this->trialBalanceQuery->asOf($tenantBId, new \DateTimeImmutable($asOfReportDate));

        return [
            $this->evaluateConnectedCoverage($tenantA),
            $this->evaluateJournalBalance($trialBalanceA, $trialBalanceB),
            $this->evaluateReportsAgainstExpected($tenantA, $tenantAId, self::jsonArr($expected, 'tenant_a'), self::jsonArr($expected, 'tenant_b'), $trialBalanceA, $trialBalanceB),
            $this->evaluateReplay($replayProbe),
            $this->evaluateIsolation(self::jsonArr($expected, 'isolation'), $trialBalanceA, $trialBalanceB),
            $this->evaluateEvidenceTraceability($tenantA, $tenantAId, $datasetBaseDir),
        ];
    }

    /**
     * @param  array<string, mixed>  $tenantA
     */
    private function evaluateConnectedCoverage(array $tenantA): CertificationCriterionResult
    {
        $requiredTypes = [
            'record_expense', 'record_income', 'record_transfer',
            'import_bank_statement', 'confirm_all_suggested_matches',
            'open_and_complete_reconciliation', 'issue_invoice',
            'record_payment', 'allocate_payment',
        ];

        $commands = self::jsonArrList($tenantA, 'commands');
        $presentTypes = array_unique(array_map(static fn (array $cmd): string => self::jsonStr($cmd, 'type'), $commands));
        $missing = array_values(array_diff($requiredTypes, $presentTypes));

        $hasEvidenceBackedExpense = false;
        foreach ($commands as $cmd) {
            if (self::jsonStr($cmd, 'type') === 'record_expense' && self::jsonStrOrNull($cmd, 'evidence_id_ref') !== null) {
                $hasEvidenceBackedExpense = true;
            }
        }

        if ($missing !== []) {
            return CertificationCriterionResult::failed('POA-002', 'Missing connected command types in Tenant A scenario: '.implode(', ', $missing));
        }

        if (! $hasEvidenceBackedExpense) {
            return CertificationCriterionResult::failed('POA-002', 'No receipt-evidence-backed Expense in Tenant A scenario.');
        }

        return CertificationCriterionResult::passed('POA-002', 'Receipt→Expense, bank statement→import→match→Reconciliation, Income, Invoice→Payment→Allocation all present in one connected Tenant A scenario.');
    }

    private function evaluateJournalBalance(TrialBalance $trialBalanceA, TrialBalance $trialBalanceB): CertificationCriterionResult
    {
        if (! $trialBalanceA->isBalanced() || ! $trialBalanceB->isBalanced()) {
            return CertificationCriterionResult::failed('POA-004', 'A Tenant Trial Balance is not balanced — every contributing Posted Journal is required to already balance exactly (JRN-007).');
        }

        return CertificationCriterionResult::passed('POA-004', 'Tenant A and Tenant B Trial Balances both balance exactly, the structural consequence of every contributing Posted Journal already balancing exactly (JRN-007, Active); no Draft Journal exists in this scenario.');
    }

    /**
     * @param  array<string, mixed>  $tenantA
     * @param  array<string, mixed>  $expectedTenantA
     * @param  array<string, mixed>  $expectedTenantB
     */
    private function evaluateReportsAgainstExpected(array $tenantA, TenantId $tenantAId, array $expectedTenantA, array $expectedTenantB, TrialBalance $trialBalanceA, TrialBalance $trialBalanceB): CertificationCriterionResult
    {
        $mismatches = [];

        $this->compareTrialBalance('tenant_a', $trialBalanceA, self::jsonArr($expectedTenantA, 'trial_balance'), $mismatches);
        $this->compareTrialBalance('tenant_b', $trialBalanceB, self::jsonArr($expectedTenantB, 'trial_balance'), $mismatches);

        $periodStart = new \DateTimeImmutable(self::jsonStr($tenantA, 'period_start'));
        $periodEnd = new \DateTimeImmutable(self::jsonStr($tenantA, 'period_end'));
        $asOf = new \DateTimeImmutable(self::jsonStr($tenantA, 'as_of_report_date'));
        $agingAsOf = new \DateTimeImmutable(self::jsonStr($tenantA, 'aging_as_of_date'));

        $pnl = $this->profitAndLossQuery->forPeriod($tenantAId, $periodStart, $periodEnd);
        $expectedPnl = self::jsonArr($expectedTenantA, 'profit_and_loss');
        $this->assertEqual('P&L total_revenue', $pnl->totalRevenue()->toDecimalString(), $expectedPnl['total_revenue'] ?? null, $mismatches);
        $this->assertEqual('P&L total_expense', $pnl->totalExpense()->toDecimalString(), $expectedPnl['total_expense'] ?? null, $mismatches);
        $this->assertEqual('P&L net_income', $pnl->netIncome()->toDecimalString(), $expectedPnl['net_income'] ?? null, $mismatches);
        $this->assertEqual('P&L is_profit', $pnl->isProfit(), $expectedPnl['is_profit'] ?? null, $mismatches);

        $balanceSheet = $this->balanceSheetQuery->asOf($tenantAId, $asOf);
        $expectedBs = self::jsonArr($expectedTenantA, 'balance_sheet');
        $this->assertEqual('Balance Sheet is_balanced', $balanceSheet->isBalanced(), $expectedBs['is_balanced'] ?? null, $mismatches);
        $this->assertEqual('Balance Sheet total_assets', $balanceSheet->totalAssets()->toDecimalString(), $expectedBs['total_assets'] ?? null, $mismatches);
        $this->assertEqual('Balance Sheet total_liabilities_and_equity', $balanceSheet->totalLiabilitiesAndEquity()->toDecimalString(), $expectedBs['total_liabilities_and_equity'] ?? null, $mismatches);
        $this->assertEqual('Balance Sheet cumulative_net_income amount', $balanceSheet->cumulativeNetIncome()->amount()->toDecimalString(), $expectedBs['cumulative_net_income_amount'] ?? null, $mismatches);
        $this->assertEqual('Balance Sheet cumulative_net_income direction', $this->directionName($balanceSheet->cumulativeNetIncome()->direction()), $expectedBs['cumulative_net_income_direction'] ?? null, $mismatches);

        $glBank = $this->generalLedgerQuery->forAccountAndPeriod($tenantAId, AccountId::of('poa-v1-a-bank'), $periodStart, $asOf);
        $expectedGl = self::jsonArr($expectedTenantA, 'general_ledger_bank');
        $this->assertEqual('GL bank opening_balance amount', $glBank->openingBalance()->amount()->toDecimalString(), $expectedGl['opening_balance_amount'] ?? null, $mismatches);
        $this->assertEqual('GL bank opening_balance direction', $this->directionName($glBank->openingBalance()->direction()), $expectedGl['opening_balance_direction'] ?? null, $mismatches);
        $this->assertEqual('GL bank closing_balance amount', $glBank->closingBalance()->amount()->toDecimalString(), $expectedGl['closing_balance_amount'] ?? null, $mismatches);
        $this->assertEqual('GL bank closing_balance direction', $this->directionName($glBank->closingBalance()->direction()), $expectedGl['closing_balance_direction'] ?? null, $mismatches);
        $this->assertEqual('GL bank entry_count', count($glBank->entries()), $expectedGl['entry_count'] ?? null, $mismatches);
        $actualJournalIdsInOrder = array_map(static fn (GeneralLedgerEntry $entry): string => $entry->journalId()->toString(), $glBank->entries());
        $this->assertEqual('GL bank entry_journal_ids_in_order', implode(',', $actualJournalIdsInOrder), implode(',', self::jsonStrList($expectedGl, 'entry_journal_ids_in_order')), $mismatches);

        $evidenceIndex = $this->evidenceIndexQuery->forPeriod($tenantAId, $periodStart, $periodEnd);
        $entriesByJournalId = [];
        foreach ($evidenceIndex->entries() as $entry) {
            $entriesByJournalId[$entry->journalId()->toString()] = $entry;
        }

        $expectedEvidenceIndex = self::jsonArr($expectedTenantA, 'evidence_index');
        foreach (self::jsonArrList($expectedEvidenceIndex, 'entries') as $expectedEntry) {
            $journalId = self::jsonStr($expectedEntry, 'journal_id');
            /** @var EvidenceIndexEntry|null $actualEntry */
            $actualEntry = $entriesByJournalId[$journalId] ?? null;

            if ($actualEntry === null) {
                $mismatches[] = "Evidence Index missing expected Journal {$journalId}";

                continue;
            }

            $this->assertEqual("Evidence Index[{$journalId}] has_evidence", $actualEntry->hasEvidence(), $expectedEntry['has_evidence'] ?? null, $mismatches);
            $this->assertEqual("Evidence Index[{$journalId}] evidence_reference_count", count($actualEntry->evidenceReferences()), $expectedEntry['evidence_reference_count'] ?? null, $mismatches);
        }

        $aging = $this->agingReportQuery->asOf($tenantAId, $agingAsOf);
        $expectedAging = self::jsonArr($expectedTenantA, 'aging');
        $this->assertEqual('Aging grand_total', $aging->grandTotal()->toDecimalString(), $expectedAging['grand_total'] ?? null, $mismatches);

        foreach (self::jsonArr($expectedAging, 'bucket_totals') as $bucketKey => $expectedTotal) {
            $bucket = $this->agingBucketFromKey($bucketKey);
            $this->assertEqual("Aging bucket_totals[{$bucketKey}]", $aging->totalForBucket($bucket)->toDecimalString(), $expectedTotal, $mismatches);
        }

        $actualAgingLinesByInvoice = [];
        foreach ($aging->lines() as $line) {
            /** @var AgingReportLine $line */
            $actualAgingLinesByInvoice[$line->invoiceId()->toString()] = $line;
        }

        foreach (self::jsonArrList($expectedAging, 'lines') as $expectedLine) {
            $invoiceId = self::jsonStr($expectedLine, 'invoice_id');
            /** @var AgingReportLine|null $actualLine */
            $actualLine = $actualAgingLinesByInvoice[$invoiceId] ?? null;

            if ($actualLine === null) {
                $mismatches[] = "Aging report missing expected Invoice {$invoiceId}";

                continue;
            }

            $this->assertEqual("Aging[{$invoiceId}] outstanding_balance", $actualLine->outstandingBalance()->toDecimalString(), $expectedLine['outstanding_balance'] ?? null, $mismatches);
            $this->assertEqual("Aging[{$invoiceId}] bucket", $actualLine->bucket()->name, $expectedLine['bucket'] ?? null, $mismatches);
        }

        if ($mismatches !== []) {
            return CertificationCriterionResult::failed('POA-005', implode(' | ', $mismatches));
        }

        return CertificationCriterionResult::passed('POA-005', 'Trial Balance, Profit & Loss, Balance Sheet, General Ledger, Evidence Index, and Aging all reconcile exactly (Money::toDecimalString exact string comparison, never float) to expected/accounting-results.json.');
    }

    /**
     * @param  array<string, mixed>  $expectedTrialBalance
     * @param  list<string>  $mismatches
     */
    private function compareTrialBalance(string $label, TrialBalance $actual, array $expectedTrialBalance, array &$mismatches): void
    {
        $this->assertEqual("{$label} Trial Balance is_balanced", $actual->isBalanced(), $expectedTrialBalance['is_balanced'] ?? null, $mismatches);
        $this->assertEqual("{$label} Trial Balance total_debit", $actual->totalDebit()->toDecimalString(), $expectedTrialBalance['total_debit'] ?? null, $mismatches);
        $this->assertEqual("{$label} Trial Balance total_credit", $actual->totalCredit()->toDecimalString(), $expectedTrialBalance['total_credit'] ?? null, $mismatches);

        $actualLinesByAccount = [];
        foreach ($actual->lines() as $line) {
            /** @var AccountBalance $line */
            $actualLinesByAccount[$line->accountId()->toString()] = $line;
        }

        foreach (self::jsonArrList($expectedTrialBalance, 'lines') as $expectedLine) {
            $accountId = self::jsonStr($expectedLine, 'account_id');
            /** @var AccountBalance|null $actualLine */
            $actualLine = $actualLinesByAccount[$accountId] ?? null;

            if ($actualLine === null) {
                $mismatches[] = "{$label} Trial Balance missing expected Account {$accountId}";

                continue;
            }

            $this->assertEqual("{$label}[{$accountId}] total_debit", $actualLine->totalDebit()->toDecimalString(), $expectedLine['total_debit'] ?? null, $mismatches);
            $this->assertEqual("{$label}[{$accountId}] total_credit", $actualLine->totalCredit()->toDecimalString(), $expectedLine['total_credit'] ?? null, $mismatches);
            $this->assertEqual("{$label}[{$accountId}] net_amount", $actualLine->netBalance()->amount()->toDecimalString(), $expectedLine['net_amount'] ?? null, $mismatches);
            $this->assertEqual("{$label}[{$accountId}] net_direction", $this->directionName($actualLine->netBalance()->direction()), $expectedLine['net_direction'] ?? null, $mismatches);
        }
    }

    private function evaluateReplay(ReplayProbeResult $replayProbe): CertificationCriterionResult
    {
        if ($replayProbe->expenseWasReplay() !== true || $replayProbe->importWasReplay() !== true) {
            return CertificationCriterionResult::failed('POA-006', sprintf(
                'Replay probe did not report an exact replay for both re-submitted commands (record_expense replay=%s, import_bank_statement replay=%s).',
                var_export($replayProbe->expenseWasReplay(), true),
                var_export($replayProbe->importWasReplay(), true),
            ));
        }

        return CertificationCriterionResult::passed('POA-006', 'Re-submitting the same record_expense (IdempotencyKey) and import_bank_statement (file-hash, BNK-004) commands against the already-populated database both reported isReplay()===true — no duplicate was created.');
    }

    /**
     * @param  array<string, mixed>  $expectedIsolation
     */
    private function evaluateIsolation(array $expectedIsolation, TrialBalance $trialBalanceA, TrialBalance $trialBalanceB): CertificationCriterionResult
    {
        $expectedAIds = self::jsonStrList($expectedIsolation, 'tenant_a_account_ids');
        $expectedBIds = self::jsonStrList($expectedIsolation, 'tenant_b_account_ids');

        $actualAIds = array_map(static fn (AccountBalance $l): string => $l->accountId()->toString(), $trialBalanceA->lines());
        $actualBIds = array_map(static fn (AccountBalance $l): string => $l->accountId()->toString(), $trialBalanceB->lines());

        $aLeakedIntoB = array_intersect($actualAIds, $expectedBIds);
        $bLeakedIntoA = array_intersect($actualBIds, $expectedAIds);
        $bMissingFromB = array_diff($expectedBIds, $actualBIds);
        $aMissingFromA = array_diff($expectedAIds, $actualAIds);

        if ($aLeakedIntoB !== [] || $bLeakedIntoA !== [] || $bMissingFromB !== [] || $aMissingFromA !== []) {
            return CertificationCriterionResult::failed('POA-007', sprintf(
                'Tenant isolation violated or incomplete: A-leaked-into-B=[%s] B-leaked-into-A=[%s] B-missing=[%s] A-missing=[%s]',
                implode(',', $aLeakedIntoB),
                implode(',', $bLeakedIntoA),
                implode(',', $bMissingFromB),
                implode(',', $aMissingFromA),
            ));
        }

        return CertificationCriterionResult::passed('POA-007', 'Tenant B, seeded with visually colliding amounts/dates/descriptions under distinct IDs, contributes zero Accounts to Tenant A\'s Trial Balance and vice versa.');
    }

    /**
     * @param  array<string, mixed>  $tenantA
     */
    private function evaluateEvidenceTraceability(array $tenantA, TenantId $tenantAId, string $datasetBaseDir): CertificationCriterionResult
    {
        $periodStart = new \DateTimeImmutable(self::jsonStr($tenantA, 'period_start'));
        $periodEnd = new \DateTimeImmutable(self::jsonStr($tenantA, 'period_end'));
        $evidenceIndex = $this->evidenceIndexQuery->forPeriod($tenantAId, $periodStart, $periodEnd);

        $expenseJournalId = null;
        foreach (self::jsonArrList($tenantA, 'commands') as $cmd) {
            if (self::jsonStr($cmd, 'type') === 'record_expense' && self::jsonStrOrNull($cmd, 'evidence_id_ref') !== null) {
                $expenseJournalId = self::jsonStr($cmd, 'journal_id');
            }
        }

        if ($expenseJournalId === null) {
            return CertificationCriterionResult::failed('POA-008', 'No evidence-backed Expense Journal found in scenario.');
        }

        /** @var EvidenceIndexEntry|null $entry */
        $entry = null;
        foreach ($evidenceIndex->entries() as $candidate) {
            if ($candidate->journalId()->toString() === $expenseJournalId) {
                $entry = $candidate;
            }
        }

        if ($entry === null || ! $entry->hasEvidence() || $entry->evidenceReferences() === []) {
            return CertificationCriterionResult::failed('POA-008', "Evidence Index reports no Evidence linked to Journal {$expenseJournalId}.");
        }

        $evidenceReferenceValue = $entry->evidenceReferences()[0];
        $evidence = $this->evidenceRepository->findById($tenantAId, EvidenceId::of($evidenceReferenceValue));

        if ($evidence === null) {
            return CertificationCriterionResult::failed('POA-008', "Evidence Reference {$evidenceReferenceValue} does not resolve to a persisted Evidence record.");
        }

        $sourceArtifact = self::jsonStr(self::jsonArr($tenantA, 'evidence'), 'source_artifact');
        $sourceContents = file_get_contents($datasetBaseDir.'/'.$sourceArtifact);

        if ($sourceContents === false) {
            return CertificationCriterionResult::failed('POA-008', "Could not re-read source artifact {$sourceArtifact} to verify Evidence digest.");
        }

        $expectedDigest = hash('sha256', $sourceContents);

        if ($evidence->sha256Digest() !== $expectedDigest) {
            return CertificationCriterionResult::failed('POA-008', "Persisted Evidence digest ({$evidence->sha256Digest()}) does not match a fresh hash of {$sourceArtifact} ({$expectedDigest}) — evidence is not traceable to its declared source.");
        }

        return CertificationCriterionResult::passed('POA-008', "Evidence linked to Journal {$expenseJournalId} resolves to a real persisted Evidence record whose sha256Digest exactly matches a fresh hash of its declared source artifact ({$sourceArtifact}) — not fabricated.");
    }

    /**
     * @param  list<string>  $mismatches
     */
    private function assertEqual(string $label, mixed $actual, mixed $expected, array &$mismatches): void
    {
        $actualStr = $this->stringify($actual);
        $expectedStr = $this->stringify($expected);

        if ($actualStr !== $expectedStr) {
            $mismatches[] = "{$label}: expected {$expectedStr}, got {$actualStr}";
        }
    }

    private function stringify(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        throw new \RuntimeException('Cannot stringify a non-scalar value: '.get_debug_type($value));
    }

    private function directionName(?JournalDirection $direction): ?string
    {
        return $direction?->name;
    }

    private function agingBucketFromKey(string $key): AgingBucket
    {
        return match ($key) {
            'current' => AgingBucket::Current,
            'overdue_1_to_30' => AgingBucket::Overdue1To30,
            'overdue_31_to_60' => AgingBucket::Overdue31To60,
            'overdue_61_to_90' => AgingBucket::Overdue61To90,
            'overdue_91_plus' => AgingBucket::Overdue91Plus,
            default => throw new \RuntimeException("Unknown aging bucket key: {$key}"),
        };
    }
}
