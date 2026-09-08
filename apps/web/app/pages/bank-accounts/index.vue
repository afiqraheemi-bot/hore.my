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

interface MatchSuggestion {
  bank_transaction_id: string
  journal_id: string
  source_type: string
  rationale: string
}

interface Reconciliation {
  id: string
  period_start: string
  period_end: string
  opening_balance: string
  closing_balance: string
  state: 'Draft' | 'InReview' | 'Balanced' | 'Completed'
  completed_at: string | null
  difference: { amount: string; sign: 'Over' | 'Short' | null; is_zero: boolean } | null
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

const suggestions = ref<MatchSuggestion[]>([])
const confirmingId = ref<string | null>(null)
const matchError = ref<string | null>(null)
const matchMessage = ref<string | null>(null)

const reconciliations = ref<Reconciliation[]>([])
const periodStart = ref('')
const periodEnd = ref('')
const openingBalance = ref('')
const closingBalance = ref('')
const opening = ref(false)
const reconciliationError = ref<string | null>(null)
const transitioning = ref<string | null>(null)
const reopenReason = ref<Record<string, string>>({})

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
  matchMessage.value = null
  await Promise.all([
    loadTransactions(bankAccountId),
    loadSuggestions(bankAccountId),
    loadReconciliations(bankAccountId),
  ])
}

async function loadReconciliations(bankAccountId: string) {
  const data = await request<{ data: Reconciliation[] }>(
    `/api/v1/bank-accounts/${bankAccountId}/reconciliations`,
  )
  reconciliations.value = data.data
}

async function onOpenReconciliation() {
  if (!selectedBankAccountId.value) return

  reconciliationError.value = null
  opening.value = true
  try {
    await request(`/api/v1/bank-accounts/${selectedBankAccountId.value}/reconciliations`, {
      method: 'POST',
      body: {
        period_start: periodStart.value,
        period_end: periodEnd.value,
        opening_balance: openingBalance.value,
        closing_balance: closingBalance.value,
      },
    })
    periodStart.value = ''
    periodEnd.value = ''
    openingBalance.value = ''
    closingBalance.value = ''
    await loadReconciliations(selectedBankAccountId.value)
  } catch {
    reconciliationError.value = 'Failed to open the reconciliation — check the dates and amounts.'
  } finally {
    opening.value = false
  }
}

async function onTransition(
  reconciliationId: string,
  action: 'start-review' | 'mark-balanced' | 'complete',
) {
  if (!selectedBankAccountId.value) return

  reconciliationError.value = null
  transitioning.value = reconciliationId
  try {
    await request(`/api/v1/reconciliations/${reconciliationId}/${action}`, { method: 'POST' })
    await loadReconciliations(selectedBankAccountId.value)
  } catch {
    reconciliationError.value = 'That transition is not currently valid for this reconciliation.'
  } finally {
    transitioning.value = null
  }
}

async function onReopen(reconciliationId: string) {
  if (!selectedBankAccountId.value) return

  const reason = reopenReason.value[reconciliationId]
  if (!reason) return

  reconciliationError.value = null
  transitioning.value = reconciliationId
  try {
    await request(`/api/v1/reconciliations/${reconciliationId}/reopen`, {
      method: 'POST',
      body: { reason },
    })
    reopenReason.value[reconciliationId] = ''
    await loadReconciliations(selectedBankAccountId.value)
  } catch {
    reconciliationError.value = 'Failed to reopen the reconciliation.'
  } finally {
    transitioning.value = null
  }
}

async function loadTransactions(bankAccountId: string) {
  const data = await request<{ data: BankTransaction[] }>(
    `/api/v1/bank-accounts/${bankAccountId}/transactions`,
  )
  transactions.value = data.data
}

async function loadSuggestions(bankAccountId: string) {
  const data = await request<{ data: MatchSuggestion[] }>(
    `/api/v1/bank-accounts/${bankAccountId}/match-suggestions`,
  )
  suggestions.value = data.data
}

async function onConfirmMatch(suggestion: MatchSuggestion) {
  if (!selectedBankAccountId.value) return

  matchError.value = null
  matchMessage.value = null
  confirmingId.value = suggestion.bank_transaction_id
  try {
    await request(`/api/v1/bank-transactions/${suggestion.bank_transaction_id}/confirm-match`, {
      method: 'POST',
      body: { journal_id: suggestion.journal_id },
    })
    matchMessage.value = 'Match confirmed.'
    await selectBankAccount(selectedBankAccountId.value)
  } catch {
    matchError.value = 'Failed to confirm the match — it may no longer be a valid candidate.'
  } finally {
    confirmingId.value = null
  }
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
          <span v-if="bankAccount.account_number_last4"
            >···{{ bankAccount.account_number_last4 }}</span
          >
        </button>
        <p v-if="bankAccounts.length === 0" class="text-sm text-gray-400">
          No bank accounts registered yet.
        </p>
      </div>

      <div
        v-if="selectedBankAccountId"
        class="space-y-4 rounded border border-gray-200 bg-white p-4"
      >
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
            <tr
              v-for="transaction in transactions"
              :key="transaction.id"
              class="border-b border-gray-100"
            >
              <td class="py-2">{{ transaction.transaction_date }}</td>
              <td class="py-2">{{ transaction.description }}</td>
              <td class="py-2">{{ transaction.amount }}</td>
              <td class="py-2">{{ transaction.direction === 'MoneyIn' ? 'In' : 'Out' }}</td>
              <td class="py-2">{{ transaction.balance ?? '—' }}</td>
              <td class="py-2">{{ transaction.reference || '—' }}</td>
            </tr>
            <tr v-if="transactions.length === 0">
              <td colspan="6" class="py-4 text-center text-gray-400">
                No transactions imported yet.
              </td>
            </tr>
          </tbody>
        </table>

        <div class="space-y-2">
          <h2 class="text-sm font-semibold text-gray-700">Match suggestions</h2>
          <p v-if="matchError" class="text-sm text-red-700">{{ matchError }}</p>
          <p v-if="matchMessage" class="text-sm text-green-700">{{ matchMessage }}</p>
          <ul class="space-y-2">
            <li
              v-for="suggestion in suggestions"
              :key="suggestion.bank_transaction_id + suggestion.journal_id"
              class="flex items-center justify-between rounded border border-gray-200 px-3 py-2 text-sm"
            >
              <div>
                <span class="rounded bg-gray-100 px-1.5 py-0.5 text-xs font-medium text-gray-700">{{
                  suggestion.source_type
                }}</span>
                <span class="ml-2 text-gray-700">{{ suggestion.rationale }}</span>
              </div>
              <button
                type="button"
                :disabled="confirmingId === suggestion.bank_transaction_id"
                class="rounded bg-gray-900 px-2.5 py-1 text-xs text-white disabled:opacity-50"
                @click="onConfirmMatch(suggestion)"
              >
                {{
                  confirmingId === suggestion.bank_transaction_id ? 'Confirming…' : 'Confirm match'
                }}
              </button>
            </li>
            <li v-if="suggestions.length === 0" class="text-sm text-gray-400">
              No unmatched suggestions right now.
            </li>
          </ul>
        </div>

        <div class="space-y-3 border-t border-gray-200 pt-4">
          <h2 class="text-sm font-semibold text-gray-700">Reconciliations</h2>
          <form
            class="flex flex-wrap items-end gap-3 rounded border border-gray-200 bg-gray-50 p-3"
            @submit.prevent="onOpenReconciliation"
          >
            <div>
              <label class="block text-xs font-medium text-gray-500">Period start</label>
              <input
                v-model="periodStart"
                type="date"
                required
                class="mt-1 rounded border border-gray-300 px-2 py-1 text-sm"
              />
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-500">Period end</label>
              <input
                v-model="periodEnd"
                type="date"
                required
                class="mt-1 rounded border border-gray-300 px-2 py-1 text-sm"
              />
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-500">Opening balance</label>
              <input
                v-model="openingBalance"
                placeholder="1000.00"
                required
                class="mt-1 w-28 rounded border border-gray-300 px-2 py-1 text-sm"
              />
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-500">Closing balance</label>
              <input
                v-model="closingBalance"
                placeholder="1500.00"
                required
                class="mt-1 w-28 rounded border border-gray-300 px-2 py-1 text-sm"
              />
            </div>
            <button
              type="submit"
              :disabled="opening"
              class="rounded bg-gray-900 px-3 py-1.5 text-sm text-white disabled:opacity-50"
            >
              {{ opening ? 'Opening…' : 'Open reconciliation' }}
            </button>
          </form>
          <p v-if="reconciliationError" class="text-sm text-red-700">{{ reconciliationError }}</p>

          <div
            v-for="reconciliation in reconciliations"
            :key="reconciliation.id"
            class="space-y-2 rounded border border-gray-200 p-3 text-sm"
          >
            <div class="flex flex-wrap items-center justify-between gap-2">
              <div>
                <span class="font-medium"
                  >{{ reconciliation.period_start }} → {{ reconciliation.period_end }}</span
                >
                <span
                  class="ml-2 rounded bg-gray-100 px-1.5 py-0.5 text-xs font-medium text-gray-700"
                  >{{ reconciliation.state }}</span
                >
                <span
                  v-if="reconciliation.difference"
                  class="ml-2 text-xs"
                  :class="reconciliation.difference.is_zero ? 'text-green-700' : 'text-red-700'"
                >
                  {{
                    reconciliation.difference.is_zero
                      ? 'Balanced (RM0.00)'
                      : `Difference: RM${reconciliation.difference.amount} (${reconciliation.difference.sign})`
                  }}
                </span>
              </div>
              <div class="flex gap-2">
                <button
                  v-if="reconciliation.state === 'Draft'"
                  type="button"
                  :disabled="transitioning === reconciliation.id"
                  class="rounded border border-gray-300 px-2 py-1 text-xs"
                  @click="onTransition(reconciliation.id, 'start-review')"
                >
                  Start review
                </button>
                <button
                  v-if="reconciliation.state === 'InReview'"
                  type="button"
                  :disabled="transitioning === reconciliation.id"
                  class="rounded border border-gray-300 px-2 py-1 text-xs"
                  @click="onTransition(reconciliation.id, 'mark-balanced')"
                >
                  Mark balanced
                </button>
                <button
                  v-if="reconciliation.state === 'Balanced'"
                  type="button"
                  :disabled="transitioning === reconciliation.id"
                  class="rounded border border-gray-300 px-2 py-1 text-xs"
                  @click="onTransition(reconciliation.id, 'complete')"
                >
                  Complete
                </button>
              </div>
            </div>
            <div v-if="reconciliation.state === 'Completed'" class="flex items-center gap-2">
              <input
                v-model="reopenReason[reconciliation.id]"
                placeholder="Reason for reopening"
                class="w-64 rounded border border-gray-300 px-2 py-1 text-xs"
              />
              <button
                type="button"
                :disabled="transitioning === reconciliation.id || !reopenReason[reconciliation.id]"
                class="rounded border border-gray-300 px-2 py-1 text-xs disabled:opacity-50"
                @click="onReopen(reconciliation.id)"
              >
                Reopen
              </button>
            </div>
          </div>
          <p v-if="reconciliations.length === 0" class="text-sm text-gray-400">
            No reconciliations opened yet.
          </p>
        </div>
      </div>
    </template>
  </div>
</template>
