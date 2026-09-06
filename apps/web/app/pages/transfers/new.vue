<script setup lang="ts">
definePageMeta({ middleware: 'auth' })

interface Account {
  id: string
  account_code: string
  account_name: string
  account_type: string
}

const { request } = useApi()
const router = useRouter()

const accounts = ref<Account[]>([])
const amount = ref('')
const transactionDate = ref(new Date().toISOString().slice(0, 10))
const sourceAccountId = ref('')
const destinationAccountId = ref('')
const description = ref('')
const submitting = ref(false)
const error = ref<string | null>(null)

async function loadAccounts() {
  const data = await request<{ data: Account[] }>('/api/v1/accounts')
  accounts.value = data.data
}

async function onSubmit() {
  error.value = null
  submitting.value = true
  try {
    await request('/api/v1/transfers', {
      method: 'POST',
      headers: { 'Idempotency-Key': crypto.randomUUID() },
      body: {
        amount: amount.value,
        transaction_date: transactionDate.value,
        source_account_id: sourceAccountId.value,
        destination_account_id: destinationAccountId.value,
        description: description.value,
      },
    })
    await router.push('/reports')
  } catch {
    error.value =
      'Failed to record the transfer — check the amount format (e.g. 150.00), that the two accounts differ, and that both are Asset or Liability accounts.'
  } finally {
    submitting.value = false
  }
}

onMounted(loadAccounts)
</script>

<template>
  <div class="max-w-md space-y-4">
    <h1 class="text-xl font-semibold">Record a transfer</h1>
    <form class="space-y-3 rounded border border-gray-200 bg-white p-4" @submit.prevent="onSubmit">
      <p v-if="error" class="rounded bg-red-50 px-3 py-2 text-sm text-red-700">{{ error }}</p>
      <div>
        <label class="block text-sm font-medium text-gray-700">Amount (MYR)</label>
        <input
          v-model="amount"
          placeholder="150.00"
          required
          class="mt-1 w-full rounded border border-gray-300 px-3 py-2"
        />
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700">Transaction date</label>
        <input
          v-model="transactionDate"
          type="date"
          required
          class="mt-1 w-full rounded border border-gray-300 px-3 py-2"
        />
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700">From (source account)</label>
        <select
          v-model="sourceAccountId"
          required
          class="mt-1 w-full rounded border border-gray-300 px-3 py-2"
        >
          <option value="" disabled>Select an account</option>
          <option v-for="account in accounts" :key="account.id" :value="account.id">
            {{ account.account_code }} — {{ account.account_name }} ({{ account.account_type }})
          </option>
        </select>
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700">To (destination account)</label>
        <select
          v-model="destinationAccountId"
          required
          class="mt-1 w-full rounded border border-gray-300 px-3 py-2"
        >
          <option value="" disabled>Select an account</option>
          <option v-for="account in accounts" :key="account.id" :value="account.id">
            {{ account.account_code }} — {{ account.account_name }} ({{ account.account_type }})
          </option>
        </select>
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700">Description</label>
        <input
          v-model="description"
          required
          class="mt-1 w-full rounded border border-gray-300 px-3 py-2"
        />
      </div>
      <button
        type="submit"
        :disabled="submitting"
        class="w-full rounded bg-gray-900 px-3 py-2 text-white disabled:opacity-50"
      >
        {{ submitting ? 'Recording…' : 'Record transfer' }}
      </button>
    </form>
  </div>
</template>
