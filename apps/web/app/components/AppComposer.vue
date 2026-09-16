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
 *
 * **Real evidence attachment (AETS-015, 2026-09-16).** The optional
 * receipt/invoice file is uploaded to a real endpoint
 * (`POST /api/v1/evidence`) *before* the transaction itself is
 * recorded — never a decorative attachment, and never sent as a
 * fabricated placeholder reference. Uploading happens first so a
 * transaction is only ever recorded with an `evidence_reference` that
 * genuinely, already resolves to stored Evidence.
 */
interface Account {
  id: string
  account_code: string
  account_name: string
  account_type: string
}

/**
 * `primaryAccount*`/`secondaryAccount*` are deliberately
 * accounting-side-neutral (2026-09-11 audit remediation: an earlier
 * version of this file named these `debitLabel`/`creditLabel`, which
 * was wrong for three of the five types below — Income actually
 * credits its "primary" field's account, Transfer's "From account"
 * is actually the credit side, and Owner Drawing's own Cash account
 * is the credit side too, opposite of Capital Contribution despite
 * sharing the identical field shape). "Primary"/"secondary" only ever
 * means "first field shown"/"second field shown" — never a claim
 * about which side of the Journal either one posts to. The real
 * Debit/Credit mapping for each type is documented once, per type,
 * below; the payload keys sent to each endpoint (`expense_account_id`,
 * `income_account_id`, etc.) are what actually determines posting —
 * this naming confusion never reached the ledger itself, only this
 * file's own internal variable names.
 */
interface TransactionType {
  key: string
  label: string
  icon: 'receipt' | 'wallet' | 'bank' | 'building' | 'chart'
  endpoint: string
  primaryAccountLabel: string
  primaryAccountKey: string
  primaryAccountTypes: string[]
  secondaryAccountLabel: string
  secondaryAccountKey: string
  secondaryAccountTypes: string[]
}

const types: TransactionType[] = [
  // Expense: Debit primary (Expense), Credit secondary (Payment) — see ExpenseToPostingCommandTranslator.
  {
    key: 'expense',
    label: 'Expense',
    icon: 'receipt',
    endpoint: '/api/v1/expenses',
    primaryAccountLabel: 'Category',
    primaryAccountKey: 'expense_account_id',
    primaryAccountTypes: ['Expense'],
    secondaryAccountLabel: 'Paid from',
    secondaryAccountKey: 'payment_account_id',
    secondaryAccountTypes: ['Asset'],
  },
  // Income: Credit primary (Income), Debit secondary (Deposit) — see IncomeToPostingCommandTranslator.
  {
    key: 'income',
    label: 'Income',
    icon: 'wallet',
    endpoint: '/api/v1/incomes',
    primaryAccountLabel: 'Category',
    primaryAccountKey: 'income_account_id',
    primaryAccountTypes: ['Revenue'],
    secondaryAccountLabel: 'Deposited to',
    secondaryAccountKey: 'deposit_account_id',
    secondaryAccountTypes: ['Asset'],
  },
  // Transfer: Credit primary (From/Source), Debit secondary (To/Destination) — see TransferToPostingCommandTranslator.
  {
    key: 'transfer',
    label: 'Transfer',
    icon: 'bank',
    endpoint: '/api/v1/transfers',
    primaryAccountLabel: 'From account',
    primaryAccountKey: 'source_account_id',
    primaryAccountTypes: ['Asset', 'Liability'],
    secondaryAccountLabel: 'To account',
    secondaryAccountKey: 'destination_account_id',
    secondaryAccountTypes: ['Asset', 'Liability'],
  },
  // Capital contribution: Debit primary (Cash), Credit secondary (Equity) — see OwnerEquityTransactionToPostingCommandTranslator (Contribution).
  {
    key: 'capital',
    label: 'Capital contribution',
    icon: 'building',
    endpoint: '/api/v1/capital-contributions',
    primaryAccountLabel: 'Cash account',
    primaryAccountKey: 'cash_account_id',
    primaryAccountTypes: ['Asset'],
    secondaryAccountLabel: "Owner's capital account",
    secondaryAccountKey: 'equity_account_id',
    secondaryAccountTypes: ['Equity'],
  },
  // Owner drawing: Credit primary (Cash), Debit secondary (Equity) — the reverse of Capital Contribution despite the identical field shape — see OwnerEquityTransactionToPostingCommandTranslator (Drawing).
  {
    key: 'drawing',
    label: 'Owner drawing',
    icon: 'chart',
    endpoint: '/api/v1/owner-drawings',
    primaryAccountLabel: 'Cash account',
    primaryAccountKey: 'cash_account_id',
    primaryAccountTypes: ['Asset'],
    secondaryAccountLabel: "Owner's capital account",
    secondaryAccountKey: 'equity_account_id',
    secondaryAccountTypes: ['Equity'],
  },
]

const emit = defineEmits<{ created: [] }>()

const { request } = useApi()

const accounts = ref<Account[]>([])
const expanded = ref(false)
const activeType = ref<TransactionType>(types[0]!)

const amount = ref('')
const transactionDate = ref(new Date().toISOString().slice(0, 10))
const primaryAccountId = ref('')
const secondaryAccountId = ref('')
const description = ref('')
const evidenceFile = ref<File | null>(null)
const submitting = ref(false)
const error = ref<string | null>(null)
const justCreated = ref(false)

const primaryAccountOptions = computed(() =>
  accounts.value
    .filter((a) => activeType.value.primaryAccountTypes.includes(a.account_type))
    .map((a) => ({ value: a.id, label: a.account_name })),
)
const secondaryAccountOptions = computed(() =>
  accounts.value
    .filter((a) => activeType.value.secondaryAccountTypes.includes(a.account_type))
    .map((a) => ({ value: a.id, label: a.account_name })),
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
  primaryAccountId.value = ''
  secondaryAccountId.value = ''
  error.value = null
}

function resetForm() {
  amount.value = ''
  description.value = ''
  primaryAccountId.value = ''
  secondaryAccountId.value = ''
  evidenceFile.value = null
}

/**
 * Uploads the attached file (AETS-015) before recording the
 * transaction itself, never after — an evidence reference is only
 * ever sent alongside a transaction that genuinely has that evidence
 * already stored, never a placeholder filled in later.
 */
async function uploadEvidenceIfAttached(): Promise<string | undefined> {
  if (!evidenceFile.value) return undefined

  const formData = new FormData()
  formData.append('file', evidenceFile.value)

  const uploaded = await request<{ id: string }>('/api/v1/evidence', {
    method: 'POST',
    body: formData,
  })
  return uploaded.id
}

async function onSubmit() {
  error.value = null
  submitting.value = true
  try {
    const evidenceReference = await uploadEvidenceIfAttached()

    await request(activeType.value.endpoint, {
      method: 'POST',
      headers: { 'Idempotency-Key': crypto.randomUUID() },
      body: {
        amount: amount.value,
        transaction_date: transactionDate.value,
        [activeType.value.primaryAccountKey]: primaryAccountId.value,
        [activeType.value.secondaryAccountKey]: secondaryAccountId.value,
        description: description.value,
        ...(evidenceReference ? { evidence_reference: evidenceReference } : {}),
      },
    })
    resetForm()
    justCreated.value = true
    setTimeout(() => (justCreated.value = false), 2500)
    emit('created')
  } catch {
    error.value =
      'Could not record this — check the amount format (e.g. 50.00), account selection, and attachment (max 10MB, image or PDF).'
  } finally {
    submitting.value = false
  }
}

onMounted(loadAccounts)
</script>

<template>
  <AppCard
    :padded="false"
    class="overflow-hidden rounded-[1.5rem] border-border-strong shadow-[0_12px_35px_rgb(var(--shadow-color)/0.06)]"
  >
    <div
      class="flex items-center gap-2 overflow-x-auto border-b border-border bg-surface-secondary/40 px-3 py-2.5"
    >
      <button
        v-for="type in types"
        :key="type.key"
        type="button"
        class="flex min-h-9 shrink-0 items-center gap-1.5 whitespace-nowrap rounded-full px-3 py-1.5 text-xs font-medium transition-colors"
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

    <form class="space-y-4 p-4 sm:p-5" @submit.prevent="onSubmit" @focusin="open">
      <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
        <div class="flex min-w-0 flex-1 items-center gap-3">
          <span class="text-lg font-medium text-ink-tertiary">RM</span>
          <input
            v-model="amount"
            aria-label="Amount"
            placeholder="0.00"
            required
            inputmode="decimal"
            class="min-w-0 flex-1 border-0 bg-transparent p-0 text-2xl font-semibold text-ink placeholder:text-ink-tertiary focus:outline-none focus:ring-0"
          />
        </div>
        <input
          v-model="transactionDate"
          aria-label="Transaction date"
          type="date"
          required
          class="h-10 w-full rounded-lg border border-border bg-surface px-3 text-sm text-ink-secondary sm:w-auto"
        />
      </div>

      <div
        v-if="expanded"
        class="grid grid-cols-1 gap-4 border-t border-border pt-4 sm:grid-cols-2"
      >
        <AppField :label="activeType.primaryAccountLabel">
          <AppSelect
            v-model="primaryAccountId"
            :options="primaryAccountOptions"
            :placeholder="
              activeType.primaryAccountLabel === 'Category'
                ? 'Select a category'
                : 'Select an account'
            "
            required
          />
        </AppField>
        <AppField :label="activeType.secondaryAccountLabel">
          <AppSelect
            v-model="secondaryAccountId"
            :options="secondaryAccountOptions"
            placeholder="Select an account"
            required
          />
        </AppField>
        <div class="sm:col-span-2">
          <AppField label="Description">
            <AppInput v-model="description" placeholder="What was this for?" required />
          </AppField>
        </div>
        <div class="sm:col-span-2">
          <AppField label="Receipt or invoice (optional)">
            <AppDropzone
              v-model="evidenceFile"
              accept="image/jpeg,image/png,image/webp,application/pdf"
              hint="JPG, PNG, WEBP, or PDF — up to 10MB"
            />
          </AppField>
        </div>
      </div>

      <p v-if="error" class="text-sm text-danger">{{ error }}</p>
      <p v-if="justCreated" class="flex items-center gap-1.5 text-sm text-success">
        <AppIcon name="check" :size="14" /> Recorded.
      </p>

      <div
        class="flex flex-col gap-3 border-t border-border pt-4 sm:flex-row sm:items-center sm:justify-between"
      >
        <p class="text-xs leading-5 text-ink-tertiary">
          Recorded immediately — accurate and traceable.
        </p>
        <AppButton type="submit" variant="primary" :disabled="submitting" class="justify-center">
          <AppIcon name="send" :size="15" />
          {{ submitting ? 'Recording…' : 'Record' }}
        </AppButton>
      </div>
    </form>
  </AppCard>
</template>
