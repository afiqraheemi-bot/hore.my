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
 *
 * **One path, decided by data — not a mode you pick (2026-09-21,
 * Founder-directed follow-up to UX-01).** Earlier versions of this
 * component exposed a "Wait for my review" / "Post directly" toggle,
 * as if which one to use were a preference. It isn't: Work Queue was
 * never meant to be a mode — it's simply where anything lands that a
 * human hasn't yet made a complete, certain decision about. Whether
 * you filled in the whole form yourself, that decision is already
 * complete — it posts straight to the type's own REST endpoint
 * ({@see TransactionType.directEndpoint}), no extra confirmation
 * step, exactly like Manual Entry always did. Whether you defer
 * choosing accounts ("Not sure which accounts yet?"), that's
 * incomplete by definition — it saves as a Task
 * (`POST /api/v1/tasks`, `NeedsInformation`) and waits in Work Queue
 * until you finish it, at which point it still asks for one
 * "Confirm and post" (`tasks/[id].vue`) — the same
 * `NeedsInformation -> NeedsReview -> approve()` pipeline
 * {@see \App\Domain\Workspace\TaskService} already runs end-to-end
 * today specifically so a future AI-produced Proposal (uncertain by
 * construction, and forbidden by HORE_MY_MASTER_CONTEXT.md §11 from
 * ever writing to the ledger on its own) needs no new confirmation
 * machinery — only a new way to reach `NeedsReview`, alongside this
 * one. Deferring is the only door into that pipeline today; AI will
 * be the second one.
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
  icon: 'receipt' | 'wallet' | 'bank' | 'building' | 'chart' | 'download' | 'send'
  /** Posted here when accounts are chosen. */
  directEndpoint: string
  /** Sent to POST /api/v1/tasks as `command_type` when accounts are deferred. */
  commandType: string
  primaryAccountLabel: string
  /** Direct-post payload field name; the deferred Task payload always uses `primary_account_id`. */
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
    directEndpoint: '/api/v1/expenses',
    commandType: 'Expense',
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
    directEndpoint: '/api/v1/incomes',
    commandType: 'Income',
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
    directEndpoint: '/api/v1/transfers',
    commandType: 'Transfer',
    primaryAccountLabel: 'From account',
    primaryAccountKey: 'source_account_id',
    primaryAccountTypes: ['Asset', 'Liability'],
    secondaryAccountLabel: 'To account',
    secondaryAccountKey: 'destination_account_id',
    secondaryAccountTypes: ['Asset', 'Liability'],
  },
  // Loan received: Credit primary (Loan/Liability, source), Debit secondary (Cash, destination) — the same
  // TransferToPostingCommandTranslator as 'transfer' above, under a friendlier label: a loan received IS a
  // transfer from a Liability account into an Asset account, never a distinct Posting Command of its own.
  {
    key: 'loan-received',
    label: 'Loan received',
    icon: 'download',
    directEndpoint: '/api/v1/transfers',
    commandType: 'Transfer',
    primaryAccountLabel: 'Loan account',
    primaryAccountKey: 'source_account_id',
    primaryAccountTypes: ['Liability'],
    secondaryAccountLabel: 'Deposited to',
    secondaryAccountKey: 'destination_account_id',
    secondaryAccountTypes: ['Asset'],
  },
  // Loan repayment: Credit primary (Cash, source), Debit secondary (Loan/Liability, destination) — the reverse
  // of 'loan-received', same underlying Transfer.
  {
    key: 'loan-repayment',
    label: 'Loan repayment',
    icon: 'send',
    directEndpoint: '/api/v1/transfers',
    commandType: 'Transfer',
    primaryAccountLabel: 'Paid from',
    primaryAccountKey: 'source_account_id',
    primaryAccountTypes: ['Asset'],
    secondaryAccountLabel: 'Loan account',
    secondaryAccountKey: 'destination_account_id',
    secondaryAccountTypes: ['Liability'],
  },
  // Capital contribution: Debit primary (Cash), Credit secondary (Equity) — see OwnerEquityTransactionToPostingCommandTranslator (Contribution).
  {
    key: 'capital',
    label: 'Capital contribution',
    icon: 'building',
    directEndpoint: '/api/v1/capital-contributions',
    commandType: 'CapitalContribution',
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
    directEndpoint: '/api/v1/owner-drawings',
    commandType: 'OwnerDrawing',
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
const deferAccounts = ref(false)

const postureCopy = computed(() =>
  deferAccounts.value
    ? {
        submitLabel: 'Save for later',
        submittingLabel: 'Saving…',
        confirmedLabel: 'Saved.',
        footer: 'Saved without accounts — finish it anytime from Home, then confirm to post.',
        errorFallback: 'Could not save this. Check the amount and optional attachment.',
      }
    : {
        submitLabel: 'Record',
        submittingLabel: 'Recording…',
        confirmedLabel: 'Recorded.',
        footer: 'Recorded immediately — accurate and traceable.',
        errorFallback:
          'Could not record this — check the amount format (e.g. 50.00), account selection, and attachment (max 10MB, image or PDF).',
      },
)

const {
  scrollRef: typeTabsScrollRef,
  showLeftFade: showTypeTabsLeftFade,
  showRightFade: showTypeTabsRightFade,
  updateScrollFade: updateTypeTabsFade,
} = useHorizontalScrollFade()

const amount = ref('')
const transactionDate = ref(new Date().toISOString().slice(0, 10))
const compactTransactionDate = computed(() => {
  if (!transactionDate.value) return 'Date'

  return new Intl.DateTimeFormat('en-MY', { day: 'numeric', month: 'short' }).format(
    new Date(`${transactionDate.value}T00:00:00`),
  )
})
const fullTransactionDate = computed(() => {
  if (!transactionDate.value) return 'Date'

  return new Intl.DateTimeFormat('en-GB').format(new Date(`${transactionDate.value}T00:00:00`))
})
const primaryAccountId = ref('')
const secondaryAccountId = ref('')
const description = ref('')
const evidenceFile = ref<File | null>(null)
const submitting = ref(false)
const error = ref<string | null>(null)
const justCreated = ref(false)
const justCreatedLabel = ref('')

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
  deferAccounts.value = false
  error.value = null
}

function resetForm() {
  amount.value = ''
  description.value = ''
  primaryAccountId.value = ''
  secondaryAccountId.value = ''
  deferAccounts.value = false
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

    if (deferAccounts.value) {
      await request('/api/v1/tasks', {
        method: 'POST',
        headers: { 'Idempotency-Key': generateUuid() },
        body: {
          command_type: activeType.value.commandType,
          amount: normalizeMoney(amount.value),
          transaction_date: transactionDate.value,
          description: description.value,
          ...(evidenceReference ? { evidence_reference: evidenceReference } : {}),
        },
      })
    } else {
      await request(activeType.value.directEndpoint, {
        method: 'POST',
        headers: { 'Idempotency-Key': generateUuid() },
        body: {
          amount: normalizeMoney(amount.value),
          transaction_date: transactionDate.value,
          [activeType.value.primaryAccountKey]: primaryAccountId.value,
          [activeType.value.secondaryAccountKey]: secondaryAccountId.value,
          description: description.value,
          ...(evidenceReference ? { evidence_reference: evidenceReference } : {}),
        },
      })
    }

    justCreatedLabel.value = postureCopy.value.confirmedLabel
    resetForm()
    justCreated.value = true
    setTimeout(() => (justCreated.value = false), 2500)
    emit('created')
  } catch {
    error.value = postureCopy.value.errorFallback
  } finally {
    submitting.value = false
  }
}

onMounted(loadAccounts)

const amountInputEl = ref<HTMLInputElement | null>(null)
const dropzoneEl = ref<{ pickFile: () => void } | null>(null)

defineExpose({
  /** Selects a type by key and expands the form — used by quick actions on the page embedding this composer. */
  selectTypeByKey(key: string) {
    const type = types.find((t) => t.key === key)
    if (type) selectType(type)
    open()
  },
  focusAmount() {
    amountInputEl.value?.focus()
  },
  pickFile() {
    dropzoneEl.value?.pickFile()
  },
})
</script>

<template>
  <AppCard
    :padded="false"
    class="overflow-hidden rounded-[1.5rem] border-border-strong shadow-[0_12px_35px_rgb(var(--shadow-color)/0.06)]"
  >
    <div class="relative border-b border-border">
      <div
        ref="typeTabsScrollRef"
        class="flex items-center gap-2 overflow-x-auto bg-surface-secondary/40 px-4 py-2.5 sm:px-5"
        @scroll="updateTypeTabsFade"
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
      <div
        v-show="showTypeTabsLeftFade"
        class="pointer-events-none absolute inset-y-0 left-0 w-11 bg-gradient-to-r from-surface-secondary from-30% via-[rgb(var(--shadow-color)/0.16)] via-60% to-transparent"
      />
      <div
        v-show="showTypeTabsRightFade"
        class="pointer-events-none absolute inset-y-0 right-0 w-11 bg-gradient-to-l from-surface-secondary from-30% via-[rgb(var(--shadow-color)/0.16)] via-60% to-transparent"
      />
    </div>

    <form class="space-y-4 p-4 sm:p-5" @submit.prevent="onSubmit" @focusin="open">
      <div class="flex items-center gap-3">
        <div class="flex min-w-0 flex-1 items-center gap-3">
          <span class="text-lg font-medium text-ink-tertiary">RM</span>
          <input
            ref="amountInputEl"
            v-model="amount"
            aria-label="Amount"
            placeholder="0.00"
            required
            inputmode="decimal"
            class="min-w-0 flex-1 border-0 bg-transparent p-0 text-2xl font-semibold text-ink placeholder:text-ink-tertiary focus:outline-none focus:ring-0"
          />
        </div>
        <label
          class="relative flex h-10 shrink-0 cursor-pointer items-center gap-1.5 rounded-lg border border-border bg-surface px-2.5 text-sm font-medium text-ink-secondary focus-within:border-accent focus-within:ring-2 focus-within:ring-accent/15 sm:px-3"
        >
          <span class="sm:hidden">{{ compactTransactionDate }}</span>
          <span class="hidden sm:inline">{{ fullTransactionDate }}</span>
          <AppIcon name="calendar" :size="16" />
          <input
            v-model="transactionDate"
            aria-label="Transaction date"
            type="date"
            required
            class="absolute inset-0 cursor-pointer opacity-0 focus:outline-none"
          />
        </label>
      </div>

      <div
        v-if="expanded"
        class="grid grid-cols-1 gap-4 border-t border-border pt-4 sm:grid-cols-2"
      >
        <template v-if="!deferAccounts">
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
            <button
              type="button"
              class="text-xs font-medium text-ink-tertiary underline hover:text-ink"
              @click="deferAccounts = true"
            >
              Not sure which accounts yet? Decide later.
            </button>
          </div>
        </template>
        <div v-else class="sm:col-span-2">
          <p class="text-sm text-ink-secondary">
            No accounts chosen yet — this will wait on Home for you to decide.
          </p>
          <button
            type="button"
            class="mt-1 text-xs font-medium text-ink-tertiary underline hover:text-ink"
            @click="deferAccounts = false"
          >
            Choose accounts now
          </button>
        </div>
        <div class="sm:col-span-2">
          <AppField label="Description">
            <AppInput v-model="description" placeholder="What was this for?" required />
          </AppField>
        </div>
        <div class="sm:col-span-2">
          <AppField label="Receipt or invoice (optional)">
            <AppDropzone
              ref="dropzoneEl"
              v-model="evidenceFile"
              accept="image/jpeg,image/png,image/webp,application/pdf"
              hint="JPG, PNG, WEBP, or PDF — up to 10MB"
            />
          </AppField>
        </div>
      </div>

      <p v-if="error" class="text-sm text-danger">{{ error }}</p>
      <p v-if="justCreated" class="flex items-center gap-1.5 text-sm text-success">
        <AppIcon name="check" :size="14" /> {{ justCreatedLabel }}
      </p>

      <div
        class="flex flex-col gap-3 border-t border-border pt-4 sm:flex-row sm:items-center sm:justify-between"
      >
        <p class="text-xs leading-5 text-ink-tertiary">
          {{ postureCopy.footer }}
        </p>
        <AppButton type="submit" variant="primary" :disabled="submitting" class="justify-center">
          <AppIcon name="send" :size="15" />
          {{ submitting ? postureCopy.submittingLabel : postureCopy.submitLabel }}
        </AppButton>
      </div>
    </form>
  </AppCard>
</template>
