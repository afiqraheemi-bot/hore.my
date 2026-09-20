<script setup lang="ts">
import type { AccountSummary, ReportResult, ReportTab } from '~/types/reporting'

definePageMeta({ middleware: 'auth' })

const { request } = useApi()
const route = useRoute()

const tabs: ReportTab[] = [
  'Trial Balance',
  'Profit & Loss',
  'Balance Sheet',
  'General Ledger',
  'Evidence Index',
  'Aging Report',
  'Cash Flow',
]

// Deep-linkable from elsewhere in the app (e.g. the dashboard's
// "Overdue invoices" attention item) via ?tab=<ReportTab> — falls
// back to Trial Balance for a missing or unrecognized value rather
// than landing on a blank/mismatched tab.
const requestedTab = route.query.tab
const initialTab = tabs.find((tab) => tab === requestedTab) ?? 'Trial Balance'
const activeTab = ref<ReportTab>(initialTab)

const today = new Date().toISOString().slice(0, 10)
const monthStart = `${today.slice(0, 7)}-01`

const asOf = ref(today)
const periodStart = ref(monthStart)
const periodEnd = ref(today)
const accounts = ref<AccountSummary[]>([])
const selectedAccountId = ref('')

const loading = ref(false)
const error = ref<string | null>(null)
const result = ref<ReportResult | null>(null)

async function loadAccounts() {
  const data = await request<{ data: AccountSummary[] }>('/api/v1/accounts')
  accounts.value = data.data
  if (!selectedAccountId.value && data.data.length > 0) {
    selectedAccountId.value = data.data[0]!.id
  }
}

const reportEndpoints: Record<ReportTab, string> = {
  'Trial Balance': '/api/v1/reports/trial-balance',
  'Profit & Loss': '/api/v1/reports/profit-and-loss',
  'Balance Sheet': '/api/v1/reports/balance-sheet',
  'General Ledger': '/api/v1/reports/general-ledger',
  'Evidence Index': '/api/v1/reports/evidence-index',
  'Aging Report': '/api/v1/reports/aging',
  'Cash Flow': '/api/v1/reports/cash-flow',
}

function currentReportQuery(): Record<string, string> {
  if (
    activeTab.value === 'Trial Balance' ||
    activeTab.value === 'Balance Sheet' ||
    activeTab.value === 'Aging Report'
  ) {
    return { as_of: asOf.value }
  }
  if (activeTab.value === 'General Ledger') {
    return {
      account_id: selectedAccountId.value,
      period_start: periodStart.value,
      period_end: periodEnd.value,
    }
  }
  return { period_start: periodStart.value, period_end: periodEnd.value }
}

let runReportGeneration = 0

/**
 * Guards against a stale response landing after a newer `runReport()`
 * call has already started (e.g. the initial onMounted load for the
 * default tab resolving after the user has already switched tabs) —
 * without it, an older report's shape can be written into `result`
 * after `activeTab` has already moved on, crashing the viewer on a
 * field the new tab's type doesn't have.
 */
async function runReport() {
  const generation = ++runReportGeneration
  loading.value = true
  error.value = null
  result.value = null
  try {
    const data = await request<ReportResult>(reportEndpoints[activeTab.value], {
      query: currentReportQuery(),
    })
    if (generation !== runReportGeneration) return
    result.value = data
  } catch {
    if (generation !== runReportGeneration) return
    error.value = 'Failed to load this report.'
  } finally {
    if (generation === runReportGeneration) {
      loading.value = false
    }
  }
}

const exporting = ref(false)
const exportingCompliancePack = ref(false)
const compliancePackError = ref<string | null>(null)

/**
 * AETS-009 §19: a ZIP of Trial Balance / Profit & Loss / Balance
 * Sheet / Aging / Evidence Index CSVs for the currently-set period —
 * "compliance-ready" per Master Context §7's own definition
 * (organized, consistent, traceable, exportable), never a guarantee
 * that any audit, tax filing, or submission will be accepted.
 */
async function downloadCompliancePack() {
  exportingCompliancePack.value = true
  compliancePackError.value = null
  try {
    const blob = await request<Blob>('/api/v1/reports/compliance-pack', {
      query: { period_start: periodStart.value, period_end: periodEnd.value },
      responseType: 'blob',
    })
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = `compliance-pack-${periodStart.value}-to-${periodEnd.value}.zip`
    link.click()
    URL.revokeObjectURL(url)
  } catch {
    compliancePackError.value = 'Failed to export the Compliance Pack.'
  } finally {
    exportingCompliancePack.value = false
  }
}

async function downloadCsv() {
  exporting.value = true
  error.value = null
  try {
    const csv = await request<string>(reportEndpoints[activeTab.value], {
      query: { ...currentReportQuery(), format: 'csv' },
    })
    const blob = new Blob([csv], { type: 'text/csv' })
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = `${activeTab.value.toLowerCase().replace(/[^a-z0-9]+/g, '-')}.csv`
    link.click()
    URL.revokeObjectURL(url)
  } catch {
    error.value = 'Failed to export this report as CSV.'
  } finally {
    exporting.value = false
  }
}

const exportingXlsx = ref(false)

/** AETS-009 §20: the identical report data as `downloadCsv()`, as a real XLSX workbook. */
async function downloadXlsx() {
  exportingXlsx.value = true
  error.value = null
  try {
    const blob = await request<Blob>(reportEndpoints[activeTab.value], {
      query: { ...currentReportQuery(), format: 'xlsx' },
      responseType: 'blob',
    })
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = `${activeTab.value.toLowerCase().replace(/[^a-z0-9]+/g, '-')}.xlsx`
    link.click()
    URL.revokeObjectURL(url)
  } catch {
    error.value = 'Failed to export this report as XLSX.'
  } finally {
    exportingXlsx.value = false
  }
}

const exportingPdf = ref(false)

/**
 * AETS-009 §21 (extended v1.9.0): a formatted PDF for every report —
 * a "loan-ready" statement for Profit & Loss, Balance Sheet, and Cash
 * Flow; a row-per-record table for Trial Balance, General Ledger,
 * Aging Report, and Evidence Index.
 */
async function downloadPdf() {
  exportingPdf.value = true
  error.value = null
  try {
    const blob = await request<Blob>(reportEndpoints[activeTab.value], {
      query: { ...currentReportQuery(), format: 'pdf' },
      responseType: 'blob',
    })
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = `${activeTab.value.toLowerCase().replace(/[^a-z0-9]+/g, '-')}.pdf`
    link.click()
    URL.revokeObjectURL(url)
  } catch {
    error.value = 'Failed to export this report as PDF.'
  } finally {
    exportingPdf.value = false
  }
}

interface PeriodWatermark {
  closed_through_date: string | null
  closing_journal_id: string | null
  closed_at: string | null
}

const periodWatermark = ref<PeriodWatermark | null>(null)
const equityAccounts = computed(() => accounts.value.filter((a) => a.account_type === 'Equity'))
const closeThroughDate = ref(today)
const retainedEarningsAccountId = ref('')
const closingPeriod = ref(false)
const closePeriodError = ref<string | null>(null)
const closePeriodSuccess = ref<string | null>(null)

async function loadPeriodWatermark() {
  periodWatermark.value = await request<PeriodWatermark>('/api/v1/periods/current')
}

/**
 * AETS-014: closing a Period is a one-way, ever-advancing commitment —
 * there is no "reopen" (§2.2, deliberately deferred). The native
 * `confirm()` dialog is a deliberately minimal safeguard: this is a
 * rare, high-stakes action, not a everyday form submit, and this
 * codebase has no existing modal/dialog component to reuse instead of
 * building one solely for this.
 */
async function closePeriod() {
  if (!retainedEarningsAccountId.value) return
  const confirmed = confirm(
    `Close the books through ${closeThroughDate.value}? This cannot be undone — every Revenue and Expense Account will be zeroed into the selected Retained Earnings Account, and no Journal dated on or before this date can be posted afterward.`,
  )
  if (!confirmed) return

  closingPeriod.value = true
  closePeriodError.value = null
  closePeriodSuccess.value = null
  try {
    const response = await request<{ closed_through_date: string; is_newly_closed: boolean }>(
      '/api/v1/periods/close',
      {
        method: 'POST',
        body: {
          closed_through_date: closeThroughDate.value,
          retained_earnings_account_id: retainedEarningsAccountId.value,
        },
        headers: { 'Idempotency-Key': crypto.randomUUID() },
      },
    )
    closePeriodSuccess.value = response.is_newly_closed
      ? `Books closed through ${response.closed_through_date}.`
      : `Already closed through ${response.closed_through_date}.`
    await loadPeriodWatermark()
  } catch {
    closePeriodError.value =
      'Could not close the period. Check the date is after the current watermark and the account is an Equity account with Revenue/Expense activity to close.'
  } finally {
    closingPeriod.value = false
  }
}

onMounted(async () => {
  await loadAccounts()
  await loadPeriodWatermark()
  await runReport()
})

async function selectTab(tab: ReportTab) {
  activeTab.value = tab
  await runReport()
}
</script>

<template>
  <div>
    <PageHeader title="Reports">
      <template #actions>
        <AppButton :disabled="exportingCompliancePack" @click="downloadCompliancePack">
          <AppIcon name="download" :size="14" />
          {{ exportingCompliancePack ? 'Exporting…' : 'Compliance Pack' }}
        </AppButton>
      </template>
    </PageHeader>
    <p v-if="compliancePackError" class="mb-3 text-sm text-danger">{{ compliancePackError }}</p>

    <div class="mb-4 flex flex-wrap gap-1 border-b border-border">
      <button
        v-for="tab in tabs"
        :key="tab"
        type="button"
        class="border-b-2 px-3 py-2 text-sm font-medium transition-colors"
        :class="
          activeTab === tab
            ? 'border-accent text-ink'
            : 'border-transparent text-ink-tertiary hover:text-ink'
        "
        @click="selectTab(tab)"
      >
        {{ tab }}
      </button>
    </div>

    <AppCard class="mb-5">
      <div class="flex flex-wrap items-end gap-3">
        <div
          v-if="
            activeTab === 'Trial Balance' ||
            activeTab === 'Balance Sheet' ||
            activeTab === 'Aging Report'
          "
        >
          <AppField label="As of">
            <AppInput v-model="asOf" type="date" />
          </AppField>
        </div>
        <template
          v-if="
            activeTab !== 'Trial Balance' &&
            activeTab !== 'Balance Sheet' &&
            activeTab !== 'Aging Report'
          "
        >
          <div>
            <AppField label="Period start">
              <AppInput v-model="periodStart" type="date" />
            </AppField>
          </div>
          <div>
            <AppField label="Period end">
              <AppInput v-model="periodEnd" type="date" />
            </AppField>
          </div>
        </template>
        <div v-if="activeTab === 'General Ledger'" class="w-full sm:w-64">
          <AppField label="Account">
            <AppSelect
              v-model="selectedAccountId"
              :options="
                accounts.map((a) => ({
                  value: a.id,
                  label: `${a.account_code} — ${a.account_name}`,
                }))
              "
            />
          </AppField>
        </div>
        <AppButton variant="primary" @click="runReport">Run report</AppButton>
        <div class="flex flex-wrap items-center gap-2 sm:ml-auto">
          <span class="mr-1 text-xs font-medium uppercase tracking-wide text-ink-tertiary">
            Download
          </span>
          <AppButton size="sm" :disabled="exporting" @click="downloadCsv">
            <AppIcon name="download" :size="14" /> {{ exporting ? 'Exporting…' : 'CSV' }}
          </AppButton>
          <AppButton size="sm" :disabled="exportingXlsx" @click="downloadXlsx">
            <AppIcon name="download" :size="14" />
            {{ exportingXlsx ? 'Exporting…' : 'Excel' }}
          </AppButton>
          <AppButton size="sm" :disabled="exportingPdf" @click="downloadPdf">
            <AppIcon name="download" :size="14" />
            {{ exportingPdf ? 'Exporting…' : 'PDF' }}
          </AppButton>
        </div>
      </div>
    </AppCard>

    <p v-if="loading" class="text-sm text-ink-tertiary">Loading…</p>
    <p v-else-if="error" class="text-sm text-danger">{{ error }}</p>
    <ReportViewer v-else-if="result" :report="activeTab" :result="result" :accounts="accounts" />

    <AppCard class="mt-8">
      <h2 class="text-sm font-semibold text-ink">Close Period</h2>
      <p class="mt-1 text-sm text-ink-tertiary">
        Zeroes every Revenue and Expense Account into a Retained Earnings Account and permanently
        forbids posting on or before the closed date. This cannot be undone.
      </p>
      <p class="mt-3 text-sm text-ink-secondary">
        Books currently closed through:
        <span class="font-medium text-ink">
          {{ periodWatermark?.closed_through_date ?? 'never' }}
        </span>
      </p>

      <div class="mt-4 flex flex-wrap items-end gap-3">
        <div>
          <AppField label="Close through">
            <AppInput v-model="closeThroughDate" type="date" />
          </AppField>
        </div>
        <div class="w-full sm:w-64">
          <AppField label="Retained Earnings account (Equity)">
            <AppSelect
              v-model="retainedEarningsAccountId"
              placeholder="Select an Equity account"
              :options="
                equityAccounts.map((a) => ({
                  value: a.id,
                  label: `${a.account_code} — ${a.account_name}`,
                }))
              "
            />
          </AppField>
        </div>
        <AppButton
          variant="danger"
          :disabled="closingPeriod || !retainedEarningsAccountId"
          @click="closePeriod"
        >
          {{ closingPeriod ? 'Closing…' : 'Close Period' }}
        </AppButton>
      </div>

      <p v-if="equityAccounts.length === 0" class="mt-3 text-sm text-warning">
        No Equity account exists yet — create one in Chart of Accounts first.
      </p>
      <p v-if="closePeriodError" class="mt-3 text-sm text-danger">{{ closePeriodError }}</p>
      <p v-if="closePeriodSuccess" class="mt-3 text-sm text-success">{{ closePeriodSuccess }}</p>
    </AppCard>
  </div>
</template>
