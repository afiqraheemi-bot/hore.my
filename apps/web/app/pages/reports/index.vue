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

async function runReport() {
  loading.value = true
  error.value = null
  result.value = null
  try {
    if (activeTab.value === 'Trial Balance') {
      result.value = await request(`/api/v1/reports/trial-balance`, {
        query: { as_of: asOf.value },
      })
    } else if (activeTab.value === 'Profit & Loss') {
      result.value = await request(`/api/v1/reports/profit-and-loss`, {
        query: { period_start: periodStart.value, period_end: periodEnd.value },
      })
    } else if (activeTab.value === 'Balance Sheet') {
      result.value = await request(`/api/v1/reports/balance-sheet`, {
        query: { as_of: asOf.value },
      })
    } else if (activeTab.value === 'Aging Report') {
      result.value = await request(`/api/v1/reports/aging`, {
        query: { as_of: asOf.value },
      })
    } else if (activeTab.value === 'General Ledger') {
      result.value = await request(`/api/v1/reports/general-ledger`, {
        query: {
          account_id: selectedAccountId.value,
          period_start: periodStart.value,
          period_end: periodEnd.value,
        },
      })
    } else {
      result.value = await request(`/api/v1/reports/evidence-index`, {
        query: { period_start: periodStart.value, period_end: periodEnd.value },
      })
    }
  } catch {
    error.value = 'Failed to load this report.'
  } finally {
    loading.value = false
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
  <div class="space-y-4">
    <h1 class="text-xl font-semibold">Reports</h1>

    <div class="flex flex-wrap gap-2 border-b border-gray-200 text-sm">
      <button
        v-for="tab in tabs"
        :key="tab"
        class="border-b-2 px-3 py-2"
        :class="
          activeTab === tab ? 'border-gray-900 font-medium' : 'border-transparent text-gray-500'
        "
        @click="selectTab(tab)"
      >
        {{ tab }}
      </button>
    </div>

    <div class="flex flex-wrap items-end gap-3 rounded border border-gray-200 bg-white p-4">
      <div v-if="activeTab === 'Trial Balance' || activeTab === 'Balance Sheet' || activeTab === 'Aging Report'">
        <label class="block text-xs font-medium text-gray-500">As of</label>
        <input v-model="asOf" type="date" class="mt-1 rounded border border-gray-300 px-2 py-1" />
      </div>
      <template
        v-if="
          activeTab !== 'Trial Balance' &&
          activeTab !== 'Balance Sheet' &&
          activeTab !== 'Aging Report'
        "
      >
        <div>
          <label class="block text-xs font-medium text-gray-500">Period start</label>
          <input
            v-model="periodStart"
            type="date"
            class="mt-1 rounded border border-gray-300 px-2 py-1"
          />
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-500">Period end</label>
          <input
            v-model="periodEnd"
            type="date"
            class="mt-1 rounded border border-gray-300 px-2 py-1"
          />
        </div>
      </template>
      <div v-if="activeTab === 'General Ledger'">
        <label class="block text-xs font-medium text-gray-500">Account</label>
        <select v-model="selectedAccountId" class="mt-1 rounded border border-gray-300 px-2 py-1">
          <option v-for="account in accounts" :key="account.id" :value="account.id">
            {{ account.account_code }} — {{ account.account_name }}
          </option>
        </select>
      </div>
      <button class="rounded bg-gray-900 px-3 py-1.5 text-sm text-white" @click="runReport">
        Run
      </button>
    </div>

    <p v-if="loading" class="text-sm text-gray-500">Loading…</p>
    <p v-else-if="error" class="text-sm text-red-700">{{ error }}</p>
    <pre
      v-else-if="result"
      class="overflow-x-auto rounded border border-gray-200 bg-white p-4 text-xs"
      >{{ JSON.stringify(result, null, 2) }}</pre>
  </div>
</template>
