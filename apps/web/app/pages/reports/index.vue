<script setup lang="ts">
import type { AccountSummary, ReportResult, ReportTab } from '~/types/reporting'

definePageMeta({ middleware: 'auth' })

const { request } = useApi()

const tabs: ReportTab[] = [
  'Trial Balance',
  'Profit & Loss',
  'Balance Sheet',
  'General Ledger',
  'Evidence Index',
  'Aging Report',
  'Cash Flow',
]
const activeTab = ref<ReportTab>('Trial Balance')

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
 * AETS-009 §21: a formatted, "loan-ready" statement PDF — Profit &
 * Loss and Balance Sheet only, the two reports a bank or accountant
 * actually reviews as a statement rather than a data export.
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

onMounted(async () => {
  await loadAccounts()
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
          <AppButton
            v-if="
              activeTab === 'Profit & Loss' ||
              activeTab === 'Balance Sheet' ||
              activeTab === 'Cash Flow'
            "
            size="sm"
            :disabled="exportingPdf"
            @click="downloadPdf"
          >
            <AppIcon name="download" :size="14" />
            {{ exportingPdf ? 'Exporting…' : 'PDF' }}
          </AppButton>
        </div>
      </div>
    </AppCard>

    <p v-if="loading" class="text-sm text-ink-tertiary">Loading…</p>
    <p v-else-if="error" class="text-sm text-danger">{{ error }}</p>
    <ReportViewer v-else-if="result" :report="activeTab" :result="result" :accounts="accounts" />
  </div>
</template>
