<script setup lang="ts">
import type {
  AccountBalanceResult,
  AccountSummary,
  AgingReportResult,
  BalanceSheetResult,
  CashFlowLineResult,
  CashFlowResult,
  EvidenceIndexResult,
  GeneralLedgerResult,
  ProfitAndLossResult,
  ReportResult,
  ReportTab,
  TrialBalanceResult,
} from '~/types/reporting'

const props = defineProps<{
  report: ReportTab
  result: ReportResult
  accounts: AccountSummary[]
}>()

const trialBalance = computed(() =>
  props.report === 'Trial Balance' ? (props.result as TrialBalanceResult) : null,
)
const profitAndLoss = computed(() =>
  props.report === 'Profit & Loss' ? (props.result as ProfitAndLossResult) : null,
)
const balanceSheet = computed(() =>
  props.report === 'Balance Sheet' ? (props.result as BalanceSheetResult) : null,
)
const generalLedger = computed(() =>
  props.report === 'General Ledger' ? (props.result as GeneralLedgerResult) : null,
)
const evidenceIndex = computed(() =>
  props.report === 'Evidence Index' ? (props.result as EvidenceIndexResult) : null,
)
const agingReport = computed(() =>
  props.report === 'Aging Report' ? (props.result as AgingReportResult) : null,
)
const cashFlow = computed(() =>
  props.report === 'Cash Flow' ? (props.result as CashFlowResult) : null,
)

const accountLookup = computed(
  () => new Map(props.accounts.map((account) => [account.id, account] as const)),
)

const balanceSections = computed<{ title: string; lines: AccountBalanceResult[] }[]>(() => {
  if (trialBalance.value) return [{ title: 'Accounts', lines: trialBalance.value.lines }]
  if (profitAndLoss.value) {
    return [
      { title: 'Revenue', lines: profitAndLoss.value.revenue_lines },
      { title: 'Expenses', lines: profitAndLoss.value.expense_lines },
    ]
  }
  if (balanceSheet.value) {
    return [
      { title: 'Assets', lines: balanceSheet.value.asset_lines },
      { title: 'Liabilities', lines: balanceSheet.value.liability_lines },
      { title: 'Equity', lines: balanceSheet.value.equity_lines },
    ]
  }
  return []
})

const cashFlowSections = computed<{ title: string; lines: CashFlowLineResult[] }[]>(() => {
  if (!cashFlow.value) return []
  return [
    { title: 'Operating Activities', lines: cashFlow.value.operating_lines },
    { title: 'Investing Activities', lines: cashFlow.value.investing_lines },
    { title: 'Financing Activities', lines: cashFlow.value.financing_lines },
  ]
})

const evidenceSummary = computed(() => {
  const entries = evidenceIndex.value?.entries ?? []
  const attached = entries.filter((entry) => entry.has_evidence).length
  return { total: entries.length, attached, missing: entries.length - attached }
})

function money(value: string): string {
  return new Intl.NumberFormat('en-MY', {
    style: 'currency',
    currency: 'MYR',
    minimumFractionDigits: 2,
  }).format(Number(value))
}

function date(value: string): string {
  return new Intl.DateTimeFormat('en-MY', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
  }).format(new Date(`${value.slice(0, 10)}T00:00:00`))
}

function accountName(accountId: string): string {
  return accountLookup.value.get(accountId)?.account_name ?? 'Unknown account'
}

function accountCode(accountId: string): string | null {
  return accountLookup.value.get(accountId)?.account_code ?? null
}

function shortId(value: string): string {
  return value.length > 14 ? `${value.slice(0, 8)}…${value.slice(-4)}` : value
}

function words(value: string): string {
  return value.replaceAll('_', ' ').replace(/([a-z])([A-Z])/g, '$1 $2')
}
</script>

<template>
  <div class="space-y-4">
    <template v-if="trialBalance">
      <div class="grid gap-3 sm:grid-cols-3">
        <AppCard>
          <p class="text-xs font-medium uppercase tracking-wide text-ink-tertiary">Status</p>
          <div class="mt-2">
            <AppBadge :tone="trialBalance.is_balanced ? 'success' : 'danger'">
              {{ trialBalance.is_balanced ? 'Balanced' : 'Out of balance' }}
            </AppBadge>
          </div>
        </AppCard>
        <AppCard>
          <p class="text-xs font-medium uppercase tracking-wide text-ink-tertiary">Total debit</p>
          <p class="mt-1 text-xl font-semibold tabular-nums text-ink">
            {{ money(trialBalance.total_debit) }}
          </p>
        </AppCard>
        <AppCard>
          <p class="text-xs font-medium uppercase tracking-wide text-ink-tertiary">Total credit</p>
          <p class="mt-1 text-xl font-semibold tabular-nums text-ink">
            {{ money(trialBalance.total_credit) }}
          </p>
        </AppCard>
      </div>
      <p class="text-sm text-ink-tertiary">Balances as of {{ date(trialBalance.as_of) }}</p>
    </template>

    <template v-else-if="profitAndLoss">
      <div class="grid gap-3 sm:grid-cols-3">
        <AppCard>
          <p class="text-xs font-medium uppercase tracking-wide text-ink-tertiary">Revenue</p>
          <p class="mt-1 text-xl font-semibold tabular-nums text-ink">
            {{ money(profitAndLoss.total_revenue) }}
          </p>
        </AppCard>
        <AppCard>
          <p class="text-xs font-medium uppercase tracking-wide text-ink-tertiary">Expenses</p>
          <p class="mt-1 text-xl font-semibold tabular-nums text-ink">
            {{ money(profitAndLoss.total_expense) }}
          </p>
        </AppCard>
        <AppCard>
          <p class="text-xs font-medium uppercase tracking-wide text-ink-tertiary">
            {{ profitAndLoss.is_profit ? 'Net profit' : 'Net loss' }}
          </p>
          <p
            class="mt-1 text-xl font-semibold tabular-nums"
            :class="profitAndLoss.is_profit ? 'text-success' : 'text-danger'"
          >
            {{ money(profitAndLoss.net_income) }}
          </p>
        </AppCard>
      </div>
      <p class="text-sm text-ink-tertiary">
        {{ date(profitAndLoss.period_start) }} – {{ date(profitAndLoss.period_end) }}
      </p>
    </template>

    <template v-else-if="balanceSheet">
      <div class="grid gap-3 sm:grid-cols-3">
        <AppCard>
          <p class="text-xs font-medium uppercase tracking-wide text-ink-tertiary">Total assets</p>
          <p class="mt-1 text-xl font-semibold tabular-nums text-ink">
            {{ money(balanceSheet.total_assets) }}
          </p>
        </AppCard>
        <AppCard>
          <p class="text-xs font-medium uppercase tracking-wide text-ink-tertiary">
            Liabilities + equity
          </p>
          <p class="mt-1 text-xl font-semibold tabular-nums text-ink">
            {{ money(balanceSheet.total_liabilities_and_equity) }}
          </p>
        </AppCard>
        <AppCard>
          <p class="text-xs font-medium uppercase tracking-wide text-ink-tertiary">Status</p>
          <div class="mt-2">
            <AppBadge :tone="balanceSheet.is_balanced ? 'success' : 'danger'">
              {{ balanceSheet.is_balanced ? 'Balanced' : 'Out of balance' }}
            </AppBadge>
          </div>
        </AppCard>
      </div>
      <p class="text-sm text-ink-tertiary">Position as of {{ date(balanceSheet.as_of) }}</p>
    </template>

    <template v-else-if="cashFlow">
      <div class="grid gap-3 sm:grid-cols-3">
        <AppCard>
          <p class="text-xs font-medium uppercase tracking-wide text-ink-tertiary">
            Cash at period start
          </p>
          <p class="mt-1 text-xl font-semibold tabular-nums text-ink">
            {{ money(cashFlow.cash_at_period_start.amount) }}
          </p>
        </AppCard>
        <AppCard>
          <p class="text-xs font-medium uppercase tracking-wide text-ink-tertiary">
            Net change in cash
          </p>
          <p class="mt-1 text-xl font-semibold tabular-nums text-ink">
            {{ money(cashFlow.net_change_in_cash.amount) }}
            <span class="ml-1 text-xs text-ink-tertiary">
              {{ cashFlow.net_change_in_cash.direction ?? '—' }}
            </span>
          </p>
        </AppCard>
        <AppCard>
          <p class="text-xs font-medium uppercase tracking-wide text-ink-tertiary">
            Cash at period end
          </p>
          <p class="mt-1 text-xl font-semibold tabular-nums text-ink">
            {{ money(cashFlow.cash_at_period_end.amount) }}
          </p>
        </AppCard>
      </div>
      <p class="text-sm text-ink-tertiary">
        {{ date(cashFlow.period_start) }} – {{ date(cashFlow.period_end) }}
      </p>
    </template>

    <template v-if="cashFlowSections.length">
      <AppCard v-for="section in cashFlowSections" :key="section.title" :padded="false">
        <div class="border-b border-border px-4 py-3">
          <h2 class="text-sm font-semibold text-ink">{{ section.title }}</h2>
        </div>
        <div v-if="section.lines.length" class="overflow-x-auto">
          <table class="w-full min-w-[560px] text-left text-sm">
            <thead
              class="bg-surface-secondary/60 text-xs uppercase tracking-wide text-ink-tertiary"
            >
              <tr>
                <th class="px-4 py-3 font-medium">Account</th>
                <th class="px-4 py-3 text-right font-medium">Net cash flow</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-border">
              <tr v-for="line in section.lines" :key="line.account_id">
                <td class="px-4 py-3">
                  <p class="font-medium text-ink">{{ accountName(line.account_id) }}</p>
                  <p class="text-xs text-ink-tertiary">
                    {{ accountCode(line.account_id) ?? shortId(line.account_id) }}
                  </p>
                </td>
                <td class="px-4 py-3 text-right font-medium tabular-nums text-ink">
                  {{ money(line.net_cash_flow.amount) }}
                  <span class="ml-1 text-xs text-ink-tertiary">
                    {{ line.net_cash_flow.direction ?? '—' }}
                  </span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-else class="px-4 py-10 text-center text-sm text-ink-tertiary">
          No entries for this section.
        </div>
      </AppCard>
    </template>

    <template v-if="balanceSections.length">
      <AppCard v-for="section in balanceSections" :key="section.title" :padded="false">
        <div class="border-b border-border px-4 py-3">
          <h2 class="text-sm font-semibold text-ink">{{ section.title }}</h2>
        </div>
        <div v-if="section.lines.length" class="overflow-x-auto">
          <table class="w-full min-w-[680px] text-left text-sm">
            <thead
              class="bg-surface-secondary/60 text-xs uppercase tracking-wide text-ink-tertiary"
            >
              <tr>
                <th class="px-4 py-3 font-medium">Account</th>
                <th class="px-4 py-3 font-medium">Type</th>
                <th class="px-4 py-3 text-right font-medium">Debit</th>
                <th class="px-4 py-3 text-right font-medium">Credit</th>
                <th class="px-4 py-3 text-right font-medium">Balance</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-border">
              <tr v-for="line in section.lines" :key="line.account_id">
                <td class="px-4 py-3">
                  <p class="font-medium text-ink">{{ accountName(line.account_id) }}</p>
                  <p class="text-xs text-ink-tertiary">
                    {{ accountCode(line.account_id) ?? shortId(line.account_id) }}
                  </p>
                </td>
                <td class="px-4 py-3 text-ink-secondary">{{ line.account_type }}</td>
                <td class="px-4 py-3 text-right tabular-nums text-ink-secondary">
                  {{ money(line.total_debit) }}
                </td>
                <td class="px-4 py-3 text-right tabular-nums text-ink-secondary">
                  {{ money(line.total_credit) }}
                </td>
                <td class="px-4 py-3 text-right font-medium tabular-nums text-ink">
                  {{ money(line.net_balance.amount) }}
                  <span class="ml-1 text-xs text-ink-tertiary">
                    {{ line.net_balance.direction ?? '—' }}
                  </span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-else class="px-4 py-10 text-center text-sm text-ink-tertiary">
          No entries for this section.
        </div>
      </AppCard>
    </template>

    <template v-else-if="generalLedger">
      <div class="grid gap-3 sm:grid-cols-3">
        <AppCard>
          <p class="text-xs font-medium uppercase tracking-wide text-ink-tertiary">Account</p>
          <p class="mt-1 font-semibold text-ink">{{ accountName(generalLedger.account_id) }}</p>
          <p class="text-xs text-ink-tertiary">
            {{ accountCode(generalLedger.account_id) ?? shortId(generalLedger.account_id) }}
          </p>
        </AppCard>
        <AppCard>
          <p class="text-xs font-medium uppercase tracking-wide text-ink-tertiary">
            Opening balance
          </p>
          <p class="mt-1 text-xl font-semibold tabular-nums text-ink">
            {{ money(generalLedger.opening_balance.amount) }}
          </p>
          <p class="text-xs text-ink-tertiary">
            {{ generalLedger.opening_balance.direction ?? '—' }}
          </p>
        </AppCard>
        <AppCard>
          <p class="text-xs font-medium uppercase tracking-wide text-ink-tertiary">
            Closing balance
          </p>
          <p class="mt-1 text-xl font-semibold tabular-nums text-ink">
            {{ money(generalLedger.closing_balance.amount) }}
          </p>
          <p class="text-xs text-ink-tertiary">
            {{ generalLedger.closing_balance.direction ?? '—' }}
          </p>
        </AppCard>
      </div>
      <AppCard :padded="false">
        <div class="overflow-x-auto">
          <table class="w-full min-w-[760px] text-left text-sm">
            <thead
              class="bg-surface-secondary/60 text-xs uppercase tracking-wide text-ink-tertiary"
            >
              <tr>
                <th class="px-4 py-3 font-medium">Date</th>
                <th class="px-4 py-3 font-medium">Source</th>
                <th class="px-4 py-3 font-medium">Journal</th>
                <th class="px-4 py-3 text-right font-medium">Debit</th>
                <th class="px-4 py-3 text-right font-medium">Credit</th>
                <th class="px-4 py-3 text-center font-medium">Evidence</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-border">
              <tr v-for="entry in generalLedger.entries" :key="entry.journal_id">
                <td class="px-4 py-3 text-ink-secondary">{{ date(entry.financial_date) }}</td>
                <td class="px-4 py-3 font-medium capitalize text-ink">{{ words(entry.source) }}</td>
                <td class="px-4 py-3 font-mono text-xs text-ink-tertiary">
                  {{ shortId(entry.journal_id) }}
                </td>
                <td class="px-4 py-3 text-right tabular-nums text-ink">
                  {{ entry.direction === 'Debit' ? money(entry.amount) : '—' }}
                </td>
                <td class="px-4 py-3 text-right tabular-nums text-ink">
                  {{ entry.direction === 'Credit' ? money(entry.amount) : '—' }}
                </td>
                <td class="px-4 py-3 text-center">
                  <AppBadge :tone="entry.evidence_references.length ? 'success' : 'neutral'">
                    {{ entry.evidence_references.length || 'None' }}
                  </AppBadge>
                </td>
              </tr>
              <tr v-if="!generalLedger.entries.length">
                <td colspan="6" class="px-4 py-10 text-center text-ink-tertiary">
                  No ledger entries in this period.
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </AppCard>
    </template>

    <template v-else-if="evidenceIndex">
      <div class="grid gap-3 sm:grid-cols-3">
        <AppCard>
          <p class="text-xs font-medium uppercase tracking-wide text-ink-tertiary">Transactions</p>
          <p class="mt-1 text-xl font-semibold tabular-nums text-ink">
            {{ evidenceSummary.total }}
          </p>
        </AppCard>
        <AppCard>
          <p class="text-xs font-medium uppercase tracking-wide text-ink-tertiary">With evidence</p>
          <p class="mt-1 text-xl font-semibold tabular-nums text-success">
            {{ evidenceSummary.attached }}
          </p>
        </AppCard>
        <AppCard>
          <p class="text-xs font-medium uppercase tracking-wide text-ink-tertiary">
            Missing evidence
          </p>
          <p
            class="mt-1 text-xl font-semibold tabular-nums"
            :class="evidenceSummary.missing ? 'text-warning' : 'text-ink'"
          >
            {{ evidenceSummary.missing }}
          </p>
        </AppCard>
      </div>
      <AppCard :padded="false">
        <div class="overflow-x-auto">
          <table class="w-full min-w-[680px] text-left text-sm">
            <thead
              class="bg-surface-secondary/60 text-xs uppercase tracking-wide text-ink-tertiary"
            >
              <tr>
                <th class="px-4 py-3 font-medium">Date</th>
                <th class="px-4 py-3 font-medium">Source</th>
                <th class="px-4 py-3 font-medium">Journal</th>
                <th class="px-4 py-3 text-center font-medium">Evidence</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-border">
              <tr v-for="entry in evidenceIndex.entries" :key="entry.journal_id">
                <td class="px-4 py-3 text-ink-secondary">{{ date(entry.financial_date) }}</td>
                <td class="px-4 py-3 font-medium capitalize text-ink">{{ words(entry.source) }}</td>
                <td class="px-4 py-3 font-mono text-xs text-ink-tertiary">
                  {{ shortId(entry.journal_id) }}
                </td>
                <td class="px-4 py-3 text-center">
                  <AppBadge :tone="entry.has_evidence ? 'success' : 'warning'">
                    {{
                      entry.has_evidence
                        ? `${entry.evidence_references.length} attached`
                        : 'Missing'
                    }}
                  </AppBadge>
                </td>
              </tr>
              <tr v-if="!evidenceIndex.entries.length">
                <td colspan="4" class="px-4 py-10 text-center text-ink-tertiary">
                  No transactions in this period.
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </AppCard>
    </template>

    <template v-else-if="agingReport">
      <div class="grid grid-cols-2 gap-3 lg:grid-cols-3">
        <AppCard class="col-span-2 lg:col-span-1">
          <p class="text-xs font-medium uppercase tracking-wide text-ink-tertiary">
            Total outstanding
          </p>
          <p class="mt-1 text-xl font-semibold tabular-nums text-ink">
            {{ money(agingReport.grand_total) }}
          </p>
        </AppCard>
        <AppCard v-for="(amount, bucket) in agingReport.bucket_totals" :key="bucket">
          <p class="text-xs font-medium capitalize text-ink-tertiary">{{ words(bucket) }}</p>
          <p class="mt-1 font-semibold tabular-nums text-ink">{{ money(amount) }}</p>
        </AppCard>
      </div>
      <AppCard :padded="false">
        <div class="overflow-x-auto">
          <table class="w-full min-w-[680px] text-left text-sm">
            <thead
              class="bg-surface-secondary/60 text-xs uppercase tracking-wide text-ink-tertiary"
            >
              <tr>
                <th class="px-4 py-3 font-medium">Invoice</th>
                <th class="px-4 py-3 font-medium">Due date</th>
                <th class="px-4 py-3 font-medium">Age</th>
                <th class="px-4 py-3 text-right font-medium">Outstanding</th>
                <th class="px-4 py-3"><span class="sr-only">Actions</span></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-border">
              <tr v-for="line in agingReport.lines" :key="line.invoice_id">
                <td class="px-4 py-3">
                  <p class="font-medium text-ink">{{ line.invoice_number ?? 'Draft invoice' }}</p>
                  <p class="font-mono text-xs text-ink-tertiary">{{ shortId(line.invoice_id) }}</p>
                </td>
                <td class="px-4 py-3 text-ink-secondary">{{ date(line.due_date) }}</td>
                <td class="px-4 py-3 capitalize text-ink-secondary">{{ words(line.bucket) }}</td>
                <td class="px-4 py-3 text-right font-medium tabular-nums text-ink">
                  {{ money(line.outstanding_balance) }}
                </td>
                <td class="px-4 py-3 text-right">
                  <NuxtLink
                    :to="{ path: '/payments', query: { customer_id: line.customer_id } }"
                    class="text-sm font-medium text-accent hover:underline"
                  >
                    Record payment
                  </NuxtLink>
                </td>
              </tr>
              <tr v-if="!agingReport.lines.length">
                <td colspan="5" class="px-4 py-10 text-center text-ink-tertiary">
                  No outstanding invoices as of this date.
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </AppCard>
    </template>
  </div>
</template>
