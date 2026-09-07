<script setup lang="ts">
definePageMeta({ middleware: 'auth' })

interface Account {
  id: string
  account_code: string
  account_name: string
  account_type: string
}

interface BankAccount {
  id: string
  linked_account_id: string
  bank_name: string
  account_number_last4: string | null
  active: boolean
}

interface BankTransaction {
  id: string
  transaction_date: string
  description: string
  amount: string
  direction: 'MoneyIn' | 'MoneyOut'
  balance: string | null
  reference: string
}

const { request } = useApi()

const accounts = ref<Account[]>([])
const bankAccounts = ref<BankAccount[]>([])
const loading = ref(true)

const linkedAccountId = ref('')
const bankName = ref('')
const accountNumberLast4 = ref('')
const registering = ref(false)
const registerError = ref<string | null>(null)

const selectedBankAccountId = ref<string | null>(null)
const transactions = ref<BankTransaction[]>([])
const importFile = ref<File | null>(null)
const importing = ref(false)
const importError = ref<string | null>(null)
const importSummary = ref<string | null>(null)

async function loadAll() {
  loading.value = true
  try {
    const [accountsData, bankAccountsData] = await Promise.all([
      request<{ data: Account[] }>('/api/v1/accounts'),
      request<{ data: BankAccount[] }>('/api/v1/bank-accounts'),
    ])
    accounts.value = accountsData.data
    bankAccounts.value = bankAccountsData.data
  } finally {
    loading.value = false
  }
}

async function onRegister() {
  registerError.value = null
  registering.value = true
  try {
    await request('/api/v1/bank-accounts', {
      method: 'POST',
      body: {
        linked_account_id: linkedAccountId.value,
        bank_name: bankName.value,
        account_number_last4: accountNumberLast4.value || null,
      },
    })
    bankName.value = ''
    accountNumberLast4.value = ''
    await loadAll()
  } catch {
    registerError.value =
      'Failed to register the bank account — the linked account must be type Asset.'
  } finally {
    registering.value = false
  }
}

async function selectBankAccount(bankAccountId: string) {
  selectedBankAccountId.value = bankAccountId
  importSummary.value = null
  const data = await request<{ data: BankTransaction[] }>(
    `/api/v1/bank-accounts/${bankAccountId}/transactions`,
  )
  transactions.value = data.data
}

function onFileChange(event: Event) {
  const target = event.target as HTMLInputElement
  importFile.value = target.files?.[0] ?? null
}

async function onImport() {
  if (!selectedBankAccountId.value || !importFile.value) return

  importError.value = null
  importSummary.value = null
  importing.value = true
  try {
    const formData = new FormData()
    formData.append('statement', importFile.value)

    const result = await request<{
      row_count: number
      inserted_count: number
      duplicate_count: number
      is_new_import: boolean
    }>(`/api/v1/bank-accounts/${selectedBankAccountId.value}/import`, {
      method: 'POST',
      body: formData,
    })

    importSummary.value = result.is_new_import
      ? `Imported ${result.inserted_count} new row(s), skipped ${result.duplicate_count} duplicate(s).`
      : 'This exact file was already imported — nothing new to add.'

    await selectBankAccount(selectedBankAccountId.value)
  } catch {
    importError.value =
      'Import failed — check the CSV header is exactly "date,description,amount,direction,balance,reference".'
  } finally {
    importing.value = false
  }
}

onMounted(loadAll)
</script>

<template>
  <div class="space-y-6">
    <h1 class="text-xl font-semibold">Bank accounts</h1>

    <form
      class="flex flex-wrap items-end gap-3 rounded border border-gray-200 bg-white p-4"
      @submit.prevent="onRegister"
    >
      <div>
        <label class="block text-xs font-medium text-gray-500">Linked account (Asset)</label>
        <select
          v-model="linkedAccountId"
          required
          class="mt-1 rounded border border-gray-300 px-2 py-1"
        >
          <option value="" disabled>Select an account</option>
          <option v-for="account in accounts" :key="account.id" :value="account.id">
            {{ account.account_code }} — {{ account.account_name }} ({{ account.account_type }})
          </option>
        </select>
      </div>
      <div>
        <label class="block text-xs font-medium text-gray-500">Bank name</label>
        <input
          v-model="bankName"
          required
          placeholder="Maybank"
          class="mt-1 w-40 rounded border border-gray-300 px-2 py-1"
        />
      </div>
      <div>
        <label class="block text-xs font-medium text-gray-500">Last 4 digits</label>
        <input
          v-model="accountNumberLast4"
          maxlength="4"
          placeholder="1234"
          class="mt-1 w-20 rounded border border-gray-300 px-2 py-1"
        />
      </div>
      <button
        type="submit"
        :disabled="registering"
        class="rounded bg-gray-900 px-3 py-1.5 text-sm text-white disabled:opacity-50"
      >
        {{ registering ? 'Registering…' : 'Register bank account' }}
      </button>
      <p v-if="registerError" class="w-full text-sm text-red-700">{{ registerError }}</p>
    </form>

    <p v-if="loading" class="text-sm text-gray-500">Loading…</p>
    <template v-else>
      <div class="flex flex-wrap gap-2">
        <button
          v-for="bankAccount in bankAccounts"
          :key="bankAccount.id"
          class="rounded border px-3 py-1.5 text-sm"
          :class="
            selectedBankAccountId === bankAccount.id
              ? 'border-gray-900 bg-gray-900 text-white'
              : 'border-gray-300 bg-white text-gray-700'
          "
          @click="selectBankAccount(bankAccount.id)"
        >
          {{ bankAccount.bank_name }}
          <span v-if="bankAccount.account_number_last4">···{{ bankAccount.account_number_last4 }}</span>
        </button>
        <p v-if="bankAccounts.length === 0" class="text-sm text-gray-400">
          No bank accounts registered yet.
        </p>
      </div>

      <div v-if="selectedBankAccountId" class="space-y-4 rounded border border-gray-200 bg-white p-4">
        <div class="flex flex-wrap items-end gap-3">
          <div>
            <label class="block text-xs font-medium text-gray-500">
              Statement CSV (date,description,amount,direction,balance,reference)
            </label>
            <input type="file" accept=".csv,text/csv" class="mt-1 text-sm" @change="onFileChange" />
          </div>
          <button
            type="button"
            :disabled="importing || !importFile"
            class="rounded bg-gray-900 px-3 py-1.5 text-sm text-white disabled:opacity-50"
            @click="onImport"
          >
            {{ importing ? 'Importing…' : 'Import statement' }}
          </button>
        </div>
        <p v-if="importError" class="text-sm text-red-700">{{ importError }}</p>
        <p v-if="importSummary" class="text-sm text-green-700">{{ importSummary }}</p>

        <table class="w-full text-left text-sm">
          <thead>
            <tr class="border-b border-gray-200 text-gray-500">
              <th class="py-2">Date</th>
              <th class="py-2">Description</th>
              <th class="py-2">Amount</th>
              <th class="py-2">Direction</th>
              <th class="py-2">Balance</th>
              <th class="py-2">Reference</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="transaction in transactions" :key="transaction.id" class="border-b border-gray-100">
              <td class="py-2">{{ transaction.transaction_date }}</td>
              <td class="py-2">{{ transaction.description }}</td>
              <td class="py-2">{{ transaction.amount }}</td>
              <td class="py-2">{{ transaction.direction === 'MoneyIn' ? 'In' : 'Out' }}</td>
              <td class="py-2">{{ transaction.balance ?? '—' }}</td>
              <td class="py-2">{{ transaction.reference || '—' }}</td>
            </tr>
            <tr v-if="transactions.length === 0">
              <td colspan="6" class="py-4 text-center text-gray-400">No transactions imported yet.</td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
  </div>
</template>
