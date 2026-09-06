<script setup lang="ts">
definePageMeta({ middleware: 'auth' })

interface Account {
  id: string
  account_code: string
  account_name: string
  account_type: string
  active: boolean
  posting_eligible: boolean
}

const { request } = useApi()

const accounts = ref<Account[]>([])
const loading = ref(true)
const error = ref<string | null>(null)

const accountCode = ref('')
const accountName = ref('')
const accountType = ref('Asset')
const creating = ref(false)
const createError = ref<string | null>(null)

const accountTypes = ['Asset', 'Liability', 'Equity', 'Revenue', 'Expense']

async function loadAccounts() {
  loading.value = true
  error.value = null
  try {
    const data = await request<{ data: Account[] }>('/api/v1/accounts')
    accounts.value = data.data
  } catch {
    error.value = 'Failed to load accounts.'
  } finally {
    loading.value = false
  }
}

async function onCreate() {
  createError.value = null
  creating.value = true
  try {
    await request('/api/v1/accounts', {
      method: 'POST',
      body: {
        account_code: accountCode.value,
        account_name: accountName.value,
        account_type: accountType.value,
      },
    })
    accountCode.value = ''
    accountName.value = ''
    await loadAccounts()
  } catch {
    createError.value = 'Failed to create account — the code may already be in use.'
  } finally {
    creating.value = false
  }
}

onMounted(loadAccounts)
</script>

<template>
  <div class="space-y-6">
    <h1 class="text-xl font-semibold">Chart of Accounts</h1>

    <form
      class="flex flex-wrap items-end gap-3 rounded border border-gray-200 bg-white p-4"
      @submit.prevent="onCreate"
    >
      <div>
        <label class="block text-xs font-medium text-gray-500">Code</label>
        <input
          v-model="accountCode"
          required
          class="mt-1 w-28 rounded border border-gray-300 px-2 py-1"
        />
      </div>
      <div>
        <label class="block text-xs font-medium text-gray-500">Name</label>
        <input
          v-model="accountName"
          required
          class="mt-1 w-48 rounded border border-gray-300 px-2 py-1"
        />
      </div>
      <div>
        <label class="block text-xs font-medium text-gray-500">Type</label>
        <select v-model="accountType" class="mt-1 rounded border border-gray-300 px-2 py-1">
          <option v-for="type in accountTypes" :key="type" :value="type">{{ type }}</option>
        </select>
      </div>
      <button
        type="submit"
        :disabled="creating"
        class="rounded bg-gray-900 px-3 py-1.5 text-sm text-white disabled:opacity-50"
      >
        {{ creating ? 'Adding…' : 'Add account' }}
      </button>
      <p v-if="createError" class="w-full text-sm text-red-700">{{ createError }}</p>
    </form>

    <p v-if="loading" class="text-sm text-gray-500">Loading…</p>
    <p v-else-if="error" class="text-sm text-red-700">{{ error }}</p>
    <table v-else class="w-full text-left text-sm">
      <thead>
        <tr class="border-b border-gray-200 text-gray-500">
          <th class="py-2">Code</th>
          <th class="py-2">Name</th>
          <th class="py-2">Type</th>
          <th class="py-2">Status</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="account in accounts" :key="account.id" class="border-b border-gray-100">
          <td class="py-2">{{ account.account_code }}</td>
          <td class="py-2">{{ account.account_name }}</td>
          <td class="py-2">{{ account.account_type }}</td>
          <td class="py-2">{{ account.active ? 'Active' : 'Inactive' }}</td>
        </tr>
        <tr v-if="accounts.length === 0">
          <td colspan="4" class="py-4 text-center text-gray-400">No accounts yet.</td>
        </tr>
      </tbody>
    </table>
  </div>
</template>
