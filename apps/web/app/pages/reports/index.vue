<script setup lang="ts">
definePageMeta({ middleware: 'auth' })

interface Account {
  id: string
  account_code: string
  account_name: string
}

const { request } = useApi()

const tabs = [
  'Trial Balance',
  'Profit & Loss',
  'Balance Sheet',
  'General Ledger',
  'Evidence Index',
  'Aging Report',
] as const
const activeTab = ref<(typeof tabs)[number]>('Trial Balance')

const today = new Date().toISOString().slice(0, 10)
const monthStart = `${today.slice(0, 7)}-01`

const asOf = ref(today)
const periodStart = ref(monthStart)
const periodEnd = ref(today)
const accounts = ref<Account[]>([])
const selectedAccountId = ref('')

const loading = ref(false)
const error = ref<string | null>(null)
// eslint-disable-next-line @typescript-eslint/no-explicit-any
const result = ref<any>(null)

async function loadAccounts() {
  const data = await request<{ data: Account[] }>('/api/v1/accounts')
  accounts.value = data.data
  if (!selectedAccountId.value && data.data.length > 0) {
    selectedAccountId.value = data.data[0]!.id
  }
}

const reportEndpoints: Record<(typeof tabs)[number], string> = {
  'Trial Balance': '/api/v1/reports/trial-balance',
  'Profit & Loss': '/api/v1/reports/profit-and-loss',
  'Balance Sheet': '/api/v1/reports/balance-sheet',
  'General Ledger': '/api/v1/reports/general-ledger',
  'Evidence Index': '/api/v1/reports/evidence-index',
  'Aging Report': '/api/v1/reports/aging',
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

async function runReport() {
  loading.value = true
  error.value = null
  result.value = null
  try {
    result.value = await request(reportEndpoints[activeTab.value], { query: currentReportQuery() })
  } catch {
    error.value = 'Failed to load this report.'
  } finally {
    loading.value = false
  }
}

const exporting = ref(false)

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

onMounted(async () => {
  await loadAccounts()
  await runReport()
})

async function selectTab(tab: (typeof tabs)[number]) {
  activeTab.value = tab
  await runReport()
}
</script>

<template>
  <div>
    <PageHeader title="Reports" />

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

    <AppCard class="mb-4">
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
        <AppButton variant="primary" @click="runReport">Run</AppButton>
        <AppButton :disabled="exporting" @click="downloadCsv">
          <AppIcon name="download" :size="14" /> {{ exporting ? 'Exporting…' : 'CSV' }}
        </AppButton>
      </div>
    </AppCard>

    <p v-if="loading" class="text-sm text-ink-tertiary">Loading…</p>
    <p v-else-if="error" class="text-sm text-danger">{{ error }}</p>
    <AppCard v-else-if="result" :padded="false">
      <pre class="overflow-x-auto p-4 text-xs text-ink-secondary">{{
        JSON.stringify(result, null, 2)
      }}</pre>
    </AppCard>
  </div>
</template>
