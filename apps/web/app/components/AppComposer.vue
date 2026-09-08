<script setup lang="ts">
/**
 * "Satu composer utama" (HORE_MY_MASTER_CONTEXT.md §5) — the one
 * primary way to record a transaction, replacing the five separate
 * /expenses/new, /incomes/new, /transfers/new, /capital-contributions/new,
 * /owner-drawings/new pages this app previously scattered the same
 * shape of form across. Each of those five commands shares an
 * identical payload shape (amount, transaction_date, two account
 * references, description) with only field names, allowed Account
 * Types, and the target endpoint differing — codified once below as
 * data, not five near-duplicate components.
 *
 * **Honest about what it is not.** This is a structured quick-entry
 * composer, not natural-language input — no AI/OCR exists yet in this
 * codebase (HORE_MY_MASTER_CONTEXT.md §19 gates that until Proof of
 * Accuracy passes). The "type" chips below are the composer's own
 * progressive-disclosure mechanism, not an AI classification.
 */
interface Account {
  id: string
  account_code: string
  account_name: string
  account_type: string
}

interface TransactionType {
  key: string
  label: string
  icon: 'receipt' | 'wallet' | 'bank' | 'building' | 'chart'
  endpoint: string
  debitLabel: string
  debitKey: string
  debitTypes: string[]
  creditLabel: string
  creditKey: string
  creditTypes: string[]
}

const types: TransactionType[] = [
  {
    key: 'expense',
    label: 'Expense',
    icon: 'receipt',
    endpoint: '/api/v1/expenses',
    debitLabel: 'Expense account',
    debitKey: 'expense_account_id',
    debitTypes: ['Expense'],
    creditLabel: 'Paid from',
    creditKey: 'payment_account_id',
    creditTypes: ['Asset'],
  },
  {
    key: 'income',
    label: 'Income',
    icon: 'wallet',
    endpoint: '/api/v1/incomes',
    debitLabel: 'Income account',
    debitKey: 'income_account_id',
    debitTypes: ['Revenue'],
    creditLabel: 'Deposited to',
    creditKey: 'deposit_account_id',
    creditTypes: ['Asset'],
  },
  {
    key: 'transfer',
    label: 'Transfer',
    icon: 'bank',
    endpoint: '/api/v1/transfers',
    debitLabel: 'From account',
    debitKey: 'source_account_id',
    debitTypes: ['Asset', 'Liability'],
    creditLabel: 'To account',
    creditKey: 'destination_account_id',
    creditTypes: ['Asset', 'Liability'],
  },
  {
    key: 'capital',
    label: 'Capital contribution',
    icon: 'building',
    endpoint: '/api/v1/capital-contributions',
    debitLabel: 'Cash account',
    debitKey: 'cash_account_id',
    debitTypes: ['Asset'],
    creditLabel: 'Equity account',
    creditKey: 'equity_account_id',
    creditTypes: ['Equity'],
  },
  {
    key: 'drawing',
    label: 'Owner drawing',
    icon: 'chart',
    endpoint: '/api/v1/owner-drawings',
    debitLabel: 'Cash account',
    debitKey: 'cash_account_id',
    debitTypes: ['Asset'],
    creditLabel: 'Equity account',
    creditKey: 'equity_account_id',
    creditTypes: ['Equity'],
  },
]

const emit = defineEmits<{ created: [] }>()

const { request } = useApi()

const accounts = ref<Account[]>([])
const expanded = ref(false)
const activeType = ref<TransactionType>(types[0]!)

const amount = ref('')
const transactionDate = ref(new Date().toISOString().slice(0, 10))
const debitAccountId = ref('')
const creditAccountId = ref('')
const description = ref('')
const submitting = ref(false)
const error = ref<string | null>(null)
const justCreated = ref(false)

const debitOptions = computed(() =>
  accounts.value
    .filter((a) => activeType.value.debitTypes.includes(a.account_type))
    .map((a) => ({ value: a.id, label: `${a.account_code} — ${a.account_name}` })),
)
const creditOptions = computed(() =>
  accounts.value
    .filter((a) => activeType.value.creditTypes.includes(a.account_type))
    .map((a) => ({ value: a.id, label: `${a.account_code} — ${a.account_name}` })),
)

async function loadAccounts() {
  const data = await request<{ data: Account[] }>('/api/v1/accounts')
  accounts.value = data.data
}

function open() {
  expanded.value = true
}

function selectType(type: TransactionType) {
  activeType.value = type
  debitAccountId.value = ''
  creditAccountId.value = ''
  error.value = null
}

function resetForm() {
  amount.value = ''
  description.value = ''
  debitAccountId.value = ''
  creditAccountId.value = ''
}

async function onSubmit() {
  error.value = null
  submitting.value = true
  try {
    await request(activeType.value.endpoint, {
      method: 'POST',
      headers: { 'Idempotency-Key': crypto.randomUUID() },
      body: {
        amount: amount.value,
        transaction_date: transactionDate.value,
        [activeType.value.debitKey]: debitAccountId.value,
        [activeType.value.creditKey]: creditAccountId.value,
        description: description.value,
      },
    })
    resetForm()
    justCreated.value = true
    setTimeout(() => (justCreated.value = false), 2500)
    emit('created')
  } catch {
    error.value =
      'Could not record this — check the amount format (e.g. 50.00) and account selection.'
  } finally {
    submitting.value = false
  }
}

onMounted(loadAccounts)
</script>

<template>
  <AppCard :padded="false" class="overflow-hidden">
    <div class="flex items-center gap-2 border-b border-border bg-surface-secondary/60 px-3 py-2">
      <button
        v-for="type in types"
        :key="type.key"
        type="button"
        class="flex items-center gap-1.5 whitespace-nowrap rounded-full px-3 py-1.5 text-xs font-medium transition-colors"
        :class="
          activeType.key === type.key
            ? 'bg-accent text-accent-contrast'
            : 'bg-surface text-ink-secondary hover:bg-surface-hover hover:text-ink'
        "
        @click="
          () => {
            selectType(type)
            open()
          }
        "
      >
        <AppIcon :name="type.icon" :size="14" />
        {{ type.label }}
      </button>
    </div>

    <form class="space-y-3 p-4" @submit.prevent="onSubmit" @focusin="open">
      <div class="flex items-center gap-3">
        <span class="text-lg font-medium text-ink-tertiary">RM</span>
        <input
          v-model="amount"
          placeholder="0.00"
          required
          inputmode="decimal"
          class="w-full border-0 bg-transparent p-0 text-2xl font-semibold text-ink placeholder:text-ink-tertiary focus:outline-none focus:ring-0"
        />
        <input
          v-model="transactionDate"
          type="date"
          required
          class="h-9 shrink-0 rounded-lg border border-border bg-surface px-2 text-sm text-ink-secondary"
        />
      </div>

      <div v-if="expanded" class="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <AppField :label="activeType.debitLabel">
          <AppSelect
            v-model="debitAccountId"
            :options="debitOptions"
            placeholder="Select an account"
            required
          />
        </AppField>
        <AppField :label="activeType.creditLabel">
          <AppSelect
            v-model="creditAccountId"
            :options="creditOptions"
            placeholder="Select an account"
            required
          />
        </AppField>
        <div class="sm:col-span-2">
          <AppField label="Description">
            <AppInput v-model="description" placeholder="What was this for?" required />
          </AppField>
        </div>
      </div>

      <p v-if="error" class="text-sm text-danger">{{ error }}</p>
      <p v-if="justCreated" class="flex items-center gap-1.5 text-sm text-success">
        <AppIcon name="check" :size="14" /> Recorded.
      </p>

      <div class="flex items-center justify-between">
        <p class="text-xs text-ink-tertiary">Posts a balanced double-entry Journal immediately.</p>
        <AppButton type="submit" variant="primary" :disabled="submitting">
          <AppIcon name="send" :size="15" />
          {{ submitting ? 'Recording…' : 'Record' }}
        </AppButton>
      </div>
    </form>
  </AppCard>
</template>
