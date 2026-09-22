<script setup lang="ts">
definePageMeta({ middleware: 'auth' })
useHead({ title: 'Bank Accounts' })

/**
 * `$fetch` (ofetch) throws a `FetchError` whose own `.data` is the
 * parsed JSON response body — for a 422 from
 * `BankStatementImportController`, that is
 * `{"message": "<the real MalformedBankStatementException reason>"}`.
 * Falls back to a generic message only when the response truly
 * carried none (a network failure, not a validation rejection) —
 * never silently substitutes a guessed reason for a real one.
 */
function extractApiErrorMessage(error: unknown): string {
  if (
    error !== null &&
    typeof error === 'object' &&
    'data' in error &&
    error.data !== null &&
    typeof error.data === 'object' &&
    'message' in error.data &&
    typeof error.data.message === 'string'
  ) {
    return error.data.message
  }

  return 'Import failed. Please try again.'
}

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
  matched: boolean
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

const RECONCILIATION_TONE: Record<
  Reconciliation['state'],
  'neutral' | 'warning' | 'success' | 'accent'
> = {
  Draft: 'neutral',
  InReview: 'warning',
  Balanced: 'success',
  Completed: 'accent',
}

const { request } = useApi()

const accounts = ref<Account[]>([])
const bankAccounts = ref<BankAccount[]>([])
const loading = ref(true)

const showRegisterForm = ref(false)
const linkedAccountId = ref('')
const bankName = ref('')
const accountNumberLast4 = ref('')
const registering = ref(false)
const registerError = ref<string | null>(null)

const linkedAccountOptions = computed(() =>
  accounts.value.map((a) => ({
    value: a.id,
    label: a.account_name,
  })),
)

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

// "Record directly" (Work Queue's own gap: importing a statement only
// ever produced *evidence* to match against something already
// recorded — never a shortcut to record it in the first place, so an
// unmatched row with nothing else in the books to match against had
// no path forward except re-typing it from scratch via Manual Entry).
// Pre-fills amount/date straight from the BankTransaction (must stay
// exact — see recordAccountOptions's own note on why); the user only
// ever picks the one account the bank side doesn't already fix.
type RecordType = 'Expense' | 'Income' | 'Transfer'
const recordingTransactionId = ref<string | null>(null)
const recordType = ref<RecordType>('Expense')
const recordDescription = ref('')
const recordAccountId = ref('')
const recording = ref(false)
const recordError = ref<string | null>(null)

const recordingTransaction = computed(() =>
  transactions.value.find((t) => t.id === recordingTransactionId.value),
)

const recordTypeOptions = computed((): { value: RecordType; label: string }[] =>
  recordingTransaction.value?.direction === 'MoneyOut'
    ? [
        { value: 'Expense', label: 'Expense' },
        { value: 'Transfer', label: 'Transfer' },
      ]
    : [
        { value: 'Income', label: 'Income' },
        { value: 'Transfer', label: 'Transfer' },
      ],
)

const recordAccountLabel = computed(() =>
  recordType.value === 'Transfer' ? 'Other account' : 'Category',
)

const recordAccountOptions = computed(() => {
  const allowedTypes =
    recordType.value === 'Expense'
      ? ['Expense']
      : recordType.value === 'Income'
        ? ['Revenue']
        : ['Asset', 'Liability']
  const bankAccount = bankAccounts.value.find((b) => b.id === selectedBankAccountId.value)

  // The bank's own linked Account never appears as the *user's* pick —
  // only relevant for Transfer (Expense/Income accounts are a
  // different Account Type entirely and would never coincide with it
  // anyway), where it would otherwise offer an invalid transfer to
  // itself.
  return accounts.value
    .filter((a) => allowedTypes.includes(a.account_type) && a.id !== bankAccount?.linked_account_id)
    .map((a) => ({ value: a.id, label: a.account_name }))
})

function startRecording(transaction: BankTransaction) {
  recordingTransactionId.value = transaction.id
  recordType.value = transaction.direction === 'MoneyOut' ? 'Expense' : 'Income'
  recordDescription.value = transaction.description
  recordAccountId.value = ''
  recordError.value = null
}

function cancelRecording() {
  recordingTransactionId.value = null
}

function selectRecordType(type: RecordType) {
  recordType.value = type
  recordAccountId.value = ''
}

async function onRecordDirect() {
  const transaction = recordingTransaction.value
  const bankAccount = bankAccounts.value.find((b) => b.id === selectedBankAccountId.value)
  if (!transaction || !bankAccount || !recordAccountId.value) return

  recording.value = true
  recordError.value = null
  try {
    const isOut = transaction.direction === 'MoneyOut'
    const shared = {
      amount: transaction.amount,
      transaction_date: transaction.transaction_date,
      description: recordDescription.value,
    }

    const endpoint =
      recordType.value === 'Expense'
        ? '/api/v1/expenses'
        : recordType.value === 'Income'
          ? '/api/v1/incomes'
          : '/api/v1/transfers'

    const body =
      recordType.value === 'Expense'
        ? {
            ...shared,
            expense_account_id: recordAccountId.value,
            payment_account_id: bankAccount.linked_account_id,
          }
        : recordType.value === 'Income'
          ? {
              ...shared,
              income_account_id: recordAccountId.value,
              deposit_account_id: bankAccount.linked_account_id,
            }
          : {
              ...shared,
              source_account_id: isOut ? bankAccount.linked_account_id : recordAccountId.value,
              destination_account_id: isOut ? recordAccountId.value : bankAccount.linked_account_id,
            }

    const created = await request<{ journal_id: string }>(endpoint, {
      method: 'POST',
      headers: { 'Idempotency-Key': generateUuid() },
      body,
    })

    let message: string
    try {
      await request(`/api/v1/bank-transactions/${transaction.id}/confirm-match`, {
        method: 'POST',
        body: { journal_id: created.journal_id },
      })
      message = 'Recorded and matched.'
    } catch {
      // The entry now exists — a Posted Journal is never rolled back —
      // but automatic matching failed (a genuine race, most likely).
      // suggestFor() will find this same Journal as a valid candidate
      // on its own, so the normal manual-confirm path below still
      // works; only the automatic shortcut didn't complete.
      message =
        'Recorded, but automatic matching failed — check Match suggestions below to confirm it manually.'
    }

    recordingTransactionId.value = null

    // `selectBankAccount()` itself resets `matchMessage` (its own
    // stale-message-clearing behaviour) — so the just-computed message
    // is re-applied after it, not before, mirroring `onImport()`'s own
    // identical `importSummary` ordering.
    if (selectedBankAccountId.value) await selectBankAccount(selectedBankAccountId.value)
    matchMessage.value = message
  } catch {
    recordError.value = 'Failed to record this transaction. Check the account and try again.'
  } finally {
    recording.value = false
  }
}

const reconciliations = ref<Reconciliation[]>([])
const showReconciliationForm = ref(false)
const periodStart = ref('')
const periodEnd = ref('')
const openingBalance = ref('')
const closingBalance = ref('')
const opening = ref(false)
const reconciliationError = ref<string | null>(null)
const transitioning = ref<string | null>(null)
const reopenReason = ref<Record<string, string>>({})

function linkedAccountName(bankAccount: BankAccount): string {
  return accounts.value.find((a) => a.id === bankAccount.linked_account_id)?.account_name ?? '—'
}

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
    showRegisterForm.value = false
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
        opening_balance: normalizeMoney(openingBalance.value),
        closing_balance: normalizeMoney(closingBalance.value),
      },
    })
    periodStart.value = ''
    periodEnd.value = ''
    openingBalance.value = ''
    closingBalance.value = ''
    showReconciliationForm.value = false
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

    const summary = result.is_new_import
      ? `Imported ${result.inserted_count} new row(s), skipped ${result.duplicate_count} duplicate(s).`
      : 'This exact file was already imported — nothing new to add.'
    importFile.value = null

    // `selectBankAccount()` itself resets `importSummary` (its own
    // stale-message-clearing behaviour when switching accounts) — so
    // the just-computed summary is re-applied after it, not before.
    await selectBankAccount(selectedBankAccountId.value)
    importSummary.value = summary
  } catch (error) {
    importError.value = extractApiErrorMessage(error)
  } finally {
    importing.value = false
  }
}

onMounted(loadAll)
</script>

<template>
  <div>
    <PageHeader title="Bank accounts">
      <template #actions>
        <AppButton variant="primary" @click="showRegisterForm = !showRegisterForm">
          <AppIcon name="plus" :size="15" /> Register bank account
        </AppButton>
      </template>
    </PageHeader>

    <AppCard v-if="showRegisterForm" class="mb-6">
      <form class="flex flex-wrap items-end gap-3" @submit.prevent="onRegister">
        <div class="w-full sm:w-64">
          <AppField label="Linked account (Asset)">
            <AppSelect
              v-model="linkedAccountId"
              :options="linkedAccountOptions"
              placeholder="Select an account"
              required
            />
          </AppField>
        </div>
        <div class="w-full sm:w-40">
          <AppField label="Bank name">
            <AppInput v-model="bankName" required placeholder="Maybank" />
          </AppField>
        </div>
        <div class="w-full sm:w-24">
          <AppField label="Last 4 digits">
            <AppInput v-model="accountNumberLast4" maxlength="4" placeholder="1234" />
          </AppField>
        </div>
        <AppButton type="submit" variant="primary" :disabled="registering">
          {{ registering ? 'Registering…' : 'Register' }}
        </AppButton>
        <p v-if="registerError" class="w-full text-sm text-danger">{{ registerError }}</p>
      </form>
    </AppCard>

    <p v-if="loading" class="text-sm text-ink-tertiary">Loading…</p>
    <template v-else>
      <EmptyState
        v-if="bankAccounts.length === 0"
        title="No bank accounts registered yet"
        description="Register one above to import statements and reconcile against it."
      />
      <div v-else class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
        <button
          v-for="bankAccount in bankAccounts"
          :key="bankAccount.id"
          type="button"
          class="rounded-2xl border p-4 text-left transition-colors"
          :class="
            selectedBankAccountId === bankAccount.id
              ? 'border-accent bg-accent-soft'
              : 'border-border bg-surface hover:bg-surface-hover'
          "
          @click="selectBankAccount(bankAccount.id)"
        >
          <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
              <p class="truncate text-sm font-medium text-ink">{{ bankAccount.bank_name }}</p>
              <p class="truncate text-xs text-ink-tertiary">
                {{ linkedAccountName(bankAccount)
                }}<template v-if="bankAccount.account_number_last4">
                  · ···{{ bankAccount.account_number_last4 }}</template
                >
              </p>
            </div>
            <AppIcon name="bank" :size="16" class="shrink-0 text-ink-tertiary" />
          </div>
          <div class="mt-3 flex items-center gap-1.5">
            <span
              class="h-1.5 w-1.5 rounded-full"
              :class="bankAccount.active ? 'bg-success' : 'bg-ink-tertiary'"
            />
            <span class="text-xs text-ink-tertiary">{{
              bankAccount.active ? 'Active' : 'Inactive'
            }}</span>
          </div>
        </button>
      </div>

      <div v-if="selectedBankAccountId" class="mt-6 space-y-6">
        <AppCard>
          <p class="mb-3 text-sm font-medium text-ink">Import statement</p>
          <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
            <div class="flex-1">
              <AppDropzone
                v-model="importFile"
                accept=".csv,text/csv,.xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,.pdf,application/pdf"
                hint="CSV/XLSX: date,description,amount,direction,balance,reference — or a Maybank PDF statement"
              />
            </div>
            <AppButton variant="primary" :disabled="importing || !importFile" @click="onImport">
              {{ importing ? 'Importing…' : 'Import' }}
            </AppButton>
          </div>
          <p v-if="importError" class="mt-2 text-sm text-danger">{{ importError }}</p>
          <p v-if="importSummary" class="mt-2 text-sm text-success">{{ importSummary }}</p>
        </AppCard>

        <div>
          <h2 class="mb-2 text-sm font-medium text-ink-secondary">Transactions</h2>
          <p v-if="recordError" class="mb-2 text-sm text-danger">{{ recordError }}</p>
          <EmptyState v-if="transactions.length === 0" title="No transactions imported yet" />
          <div v-else class="space-y-1.5">
            <AppCard v-for="transaction in transactions" :key="transaction.id" :padded="false">
              <div class="flex items-start gap-3 px-4 py-2.5">
                <span
                  class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full"
                  :class="
                    transaction.direction === 'MoneyIn'
                      ? 'bg-success-soft text-success'
                      : 'bg-danger-soft text-danger'
                  "
                >
                  <AppIcon
                    :name="transaction.direction === 'MoneyIn' ? 'download' : 'send'"
                    :size="13"
                  />
                </span>
                <div class="min-w-0 flex-1">
                  <p class="truncate text-sm text-ink">{{ transaction.description }}</p>
                  <p class="line-clamp-2 text-xs text-ink-tertiary">
                    {{ transaction.transaction_date
                    }}<template v-if="transaction.reference">
                      · {{ transaction.reference }}</template
                    >
                  </p>
                </div>
                <p
                  class="shrink-0 text-sm font-medium"
                  :class="transaction.direction === 'MoneyIn' ? 'text-success' : 'text-danger'"
                >
                  {{ transaction.direction === 'MoneyIn' ? '+' : '−' }}RM{{ transaction.amount }}
                </p>
                <AppButton
                  v-if="!transaction.matched"
                  size="sm"
                  variant="ghost"
                  @click="
                    recordingTransactionId === transaction.id
                      ? cancelRecording()
                      : startRecording(transaction)
                  "
                >
                  {{ recordingTransactionId === transaction.id ? 'Cancel' : 'Record directly' }}
                </AppButton>
              </div>

              <div
                v-if="recordingTransactionId === transaction.id"
                class="space-y-3 border-t border-border px-4 py-3"
              >
                <div class="grid grid-cols-2 gap-3 text-sm">
                  <div>
                    <p class="text-xs text-ink-tertiary">Amount</p>
                    <p class="text-ink">RM{{ transaction.amount }}</p>
                  </div>
                  <div>
                    <p class="text-xs text-ink-tertiary">Date</p>
                    <p class="text-ink">{{ transaction.transaction_date }}</p>
                  </div>
                </div>
                <AppField label="Description">
                  <AppInput v-model="recordDescription" />
                </AppField>
                <div class="flex gap-2">
                  <button
                    v-for="option in recordTypeOptions"
                    :key="option.value"
                    type="button"
                    class="rounded-full px-3 py-1.5 text-xs font-medium transition-colors"
                    :class="
                      recordType === option.value
                        ? 'bg-accent text-accent-contrast'
                        : 'bg-surface-secondary text-ink-secondary hover:bg-surface-hover'
                    "
                    @click="selectRecordType(option.value)"
                  >
                    {{ option.label }}
                  </button>
                </div>
                <AppField :label="recordAccountLabel">
                  <AppSelect
                    v-model="recordAccountId"
                    :options="recordAccountOptions"
                    placeholder="Select an account"
                  />
                </AppField>
                <AppButton
                  variant="primary"
                  :disabled="recording || !recordAccountId"
                  @click="onRecordDirect"
                >
                  {{ recording ? 'Recording…' : 'Record' }}
                </AppButton>
              </div>
            </AppCard>
          </div>
        </div>

        <div>
          <h2 class="mb-2 text-sm font-medium text-ink-secondary">Match suggestions</h2>
          <p v-if="matchError" class="mb-2 text-sm text-danger">{{ matchError }}</p>
          <p v-if="matchMessage" class="mb-2 text-sm text-success">{{ matchMessage }}</p>
          <EmptyState v-if="suggestions.length === 0" title="No unmatched suggestions right now" />
          <div v-else class="space-y-1.5">
            <AppCard
              v-for="suggestion in suggestions"
              :key="suggestion.bank_transaction_id + suggestion.journal_id"
            >
              <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                  <AppBadge tone="accent">{{ suggestion.source_type }}</AppBadge>
                  <p class="mt-1 text-sm text-ink-secondary">{{ suggestion.rationale }}</p>
                </div>
                <AppButton
                  size="sm"
                  variant="primary"
                  :disabled="confirmingId === suggestion.bank_transaction_id"
                  @click="onConfirmMatch(suggestion)"
                >
                  {{
                    confirmingId === suggestion.bank_transaction_id
                      ? 'Confirming…'
                      : 'Confirm match'
                  }}
                </AppButton>
              </div>
            </AppCard>
          </div>
        </div>

        <div class="border-t border-border pt-6">
          <div class="mb-2 flex items-center justify-between">
            <h2 class="text-sm font-medium text-ink-secondary">Reconciliations</h2>
            <AppButton
              size="sm"
              variant="ghost"
              @click="showReconciliationForm = !showReconciliationForm"
            >
              <AppIcon name="plus" :size="14" /> Open reconciliation
            </AppButton>
          </div>

          <AppCard v-if="showReconciliationForm" class="mb-3">
            <form class="flex flex-wrap items-end gap-3" @submit.prevent="onOpenReconciliation">
              <div>
                <AppField label="Period start">
                  <AppInput v-model="periodStart" type="date" required />
                </AppField>
              </div>
              <div>
                <AppField label="Period end">
                  <AppInput v-model="periodEnd" type="date" required />
                </AppField>
              </div>
              <div class="w-full sm:w-28">
                <AppField label="Opening balance">
                  <AppInput v-model="openingBalance" placeholder="1000.00" required />
                </AppField>
              </div>
              <div class="w-full sm:w-28">
                <AppField label="Closing balance">
                  <AppInput v-model="closingBalance" placeholder="1500.00" required />
                </AppField>
              </div>
              <AppButton type="submit" variant="primary" :disabled="opening">
                {{ opening ? 'Opening…' : 'Open' }}
              </AppButton>
            </form>
          </AppCard>
          <p v-if="reconciliationError" class="mb-2 text-sm text-danger">
            {{ reconciliationError }}
          </p>

          <EmptyState v-if="reconciliations.length === 0" title="No reconciliations opened yet" />
          <div v-else class="space-y-2">
            <AppCard v-for="reconciliation in reconciliations" :key="reconciliation.id">
              <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="flex items-center gap-2">
                  <span class="text-sm font-medium text-ink"
                    >{{ reconciliation.period_start }} → {{ reconciliation.period_end }}</span
                  >
                  <AppBadge :tone="RECONCILIATION_TONE[reconciliation.state]">{{
                    reconciliation.state
                  }}</AppBadge>
                  <span
                    v-if="reconciliation.difference"
                    class="text-xs font-medium"
                    :class="reconciliation.difference.is_zero ? 'text-success' : 'text-danger'"
                  >
                    {{
                      reconciliation.difference.is_zero
                        ? 'Balanced (RM0.00)'
                        : `Difference: RM${reconciliation.difference.amount} (${reconciliation.difference.sign})`
                    }}
                  </span>
                </div>
                <div class="flex gap-2">
                  <AppButton
                    v-if="reconciliation.state === 'Draft'"
                    size="sm"
                    :disabled="transitioning === reconciliation.id"
                    @click="onTransition(reconciliation.id, 'start-review')"
                  >
                    Start review
                  </AppButton>
                  <AppButton
                    v-if="reconciliation.state === 'InReview'"
                    size="sm"
                    :disabled="transitioning === reconciliation.id"
                    @click="onTransition(reconciliation.id, 'mark-balanced')"
                  >
                    Mark balanced
                  </AppButton>
                  <AppButton
                    v-if="reconciliation.state === 'Balanced'"
                    size="sm"
                    :disabled="transitioning === reconciliation.id"
                    @click="onTransition(reconciliation.id, 'complete')"
                  >
                    Complete
                  </AppButton>
                </div>
              </div>
              <div v-if="reconciliation.state === 'Completed'" class="mt-2 flex items-center gap-2">
                <div class="w-full sm:w-64">
                  <AppInput
                    v-model="reopenReason[reconciliation.id]"
                    placeholder="Reason for reopening"
                  />
                </div>
                <AppButton
                  size="sm"
                  :disabled="
                    transitioning === reconciliation.id || !reopenReason[reconciliation.id]
                  "
                  @click="onReopen(reconciliation.id)"
                >
                  Reopen
                </AppButton>
              </div>
            </AppCard>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>
