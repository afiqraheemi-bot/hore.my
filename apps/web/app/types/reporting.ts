export type ReportTab =
  | 'Trial Balance'
  | 'Profit & Loss'
  | 'Balance Sheet'
  | 'General Ledger'
  | 'Evidence Index'
  | 'Aging Report'
  | 'Cash Flow'

export interface AccountSummary {
  id: string
  account_code: string
  account_name: string
  account_type: string
}

export interface NetBalanceResult {
  amount: string
  direction: 'Debit' | 'Credit' | null
}

export interface AccountBalanceResult {
  account_id: string
  account_type: string
  total_debit: string
  total_credit: string
  net_balance: NetBalanceResult
}

export interface TrialBalanceResult {
  as_of: string
  is_balanced: boolean
  total_debit: string
  total_credit: string
  lines: AccountBalanceResult[]
}

export interface ProfitAndLossResult {
  period_start: string
  period_end: string
  total_revenue: string
  total_expense: string
  net_income: string
  is_profit: boolean
  revenue_lines: AccountBalanceResult[]
  expense_lines: AccountBalanceResult[]
}

export interface BalanceSheetResult {
  as_of: string
  is_balanced: boolean
  total_assets: string
  total_liabilities_and_equity: string
  cumulative_net_income: NetBalanceResult
  asset_lines: AccountBalanceResult[]
  liability_lines: AccountBalanceResult[]
  equity_lines: AccountBalanceResult[]
}

export interface GeneralLedgerEntryResult {
  journal_id: string
  financial_date: string
  posted_at: string
  amount: string
  direction: 'Debit' | 'Credit'
  source: string
  evidence_references: string[]
}

export interface GeneralLedgerResult {
  account_id: string
  period_start: string
  period_end: string
  opening_balance: NetBalanceResult
  closing_balance: NetBalanceResult
  entries: GeneralLedgerEntryResult[]
}

export interface EvidenceIndexEntryResult {
  journal_id: string
  financial_date: string
  source: string
  has_evidence: boolean
  evidence_references: string[]
}

export interface EvidenceIndexResult {
  period_start: string
  period_end: string
  entries: EvidenceIndexEntryResult[]
}

export interface AgingLineResult {
  invoice_id: string
  invoice_number: string | null
  customer_id: string
  due_date: string
  outstanding_balance: string
  bucket: string
}

export interface AgingReportResult {
  as_of: string
  grand_total: string
  bucket_totals: {
    current: string
    overdue_1_to_30: string
    overdue_31_to_60: string
    overdue_61_to_90: string
    overdue_91_plus: string
  }
  lines: AgingLineResult[]
}

export interface CashFlowLineResult {
  account_id: string
  net_cash_flow: NetBalanceResult
}

export interface CashFlowResult {
  period_start: string
  period_end: string
  operating_lines: CashFlowLineResult[]
  operating_total: NetBalanceResult
  investing_lines: CashFlowLineResult[]
  investing_total: NetBalanceResult
  financing_lines: CashFlowLineResult[]
  financing_total: NetBalanceResult
  net_change_in_cash: NetBalanceResult
  cash_at_period_start: NetBalanceResult
  cash_at_period_end: NetBalanceResult
}

export type ReportResult =
  | TrialBalanceResult
  | ProfitAndLossResult
  | BalanceSheetResult
  | GeneralLedgerResult
  | EvidenceIndexResult
  | AgingReportResult
  | CashFlowResult
