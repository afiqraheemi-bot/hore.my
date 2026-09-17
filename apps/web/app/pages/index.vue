<script setup lang="ts">
/**
 * Home — the action-first workspace (HORE_MY_MASTER_CONTEXT.md §5) and
 * the authenticated default landing experience (ADR-0009, WTS-001).
 * Also reachable at `/tasks` via `alias` below — the same route, with
 * no client-side redirect or loading flash. Manual Entry remains the
 * controlled, deterministic direct-posting fallback.
 *
 * This structured composer submits a Task to `NeedsReview`; it never
 * posts directly. An authenticated human must inspect and confirm the
 * Proposal before Accounting Core receives a posting command.
 */
definePageMeta({ middleware: 'auth', alias: '/tasks' })

interface Account {
  id: string
  account_code: string
  account_name: string
  account_type: string
}

interface Task {
  id: string
  state: string
  created_at: string
  completed_at: string | null
  result_journal_id: string | null
  failure_reason: string | null
}

type QueueFilter = 'attention' | 'progress' | 'completed' | 'all'

interface TaskType {
  key: string
  label: string
  icon: 'receipt' | 'wallet' | 'bank' | 'building' | 'chart' | 'download' | 'send'
  commandType: string
  primaryAccountLabel: string
  primaryAccountTypes: string[]
  secondaryAccountLabel: string
  secondaryAccountTypes: string[]
}

const types: TaskType[] = [
  {
    key: 'expense',
    label: 'Expense',
    icon: 'receipt',
    commandType: 'Expense',
    primaryAccountLabel: 'Category',
    primaryAccountTypes: ['Expense'],
    secondaryAccountLabel: 'Paid from',
    secondaryAccountTypes: ['Asset'],
  },
  {
    key: 'income',
    label: 'Income',
    icon: 'wallet',
    commandType: 'Income',
    primaryAccountLabel: 'Category',
    primaryAccountTypes: ['Revenue'],
    secondaryAccountLabel: 'Deposited to',
    secondaryAccountTypes: ['Asset'],
  },
  {
    key: 'transfer',
    label: 'Transfer',
    icon: 'bank',
    commandType: 'Transfer',
    primaryAccountLabel: 'From account',
    primaryAccountTypes: ['Asset', 'Liability'],
    secondaryAccountLabel: 'To account',
    secondaryAccountTypes: ['Asset', 'Liability'],
  },
  {
    key: 'loan-received',
    label: 'Loan received',
    icon: 'download',
    commandType: 'Transfer',
    primaryAccountLabel: 'Loan account',
    primaryAccountTypes: ['Liability'],
    secondaryAccountLabel: 'Deposited to',
    secondaryAccountTypes: ['Asset'],
  },
  {
    key: 'loan-repayment',
    label: 'Loan repayment',
    icon: 'send',
    commandType: 'Transfer',
    primaryAccountLabel: 'Paid from',
    primaryAccountTypes: ['Asset'],
    secondaryAccountLabel: 'Loan account',
    secondaryAccountTypes: ['Liability'],
  },
  {
    key: 'capital',
    label: 'Capital contribution',
    icon: 'building',
    commandType: 'CapitalContribution',
    primaryAccountLabel: 'Cash account',
    primaryAccountTypes: ['Asset'],
    secondaryAccountLabel: "Owner's capital account",
    secondaryAccountTypes: ['Equity'],
  },
  {
    key: 'drawing',
    label: 'Owner drawing',
    icon: 'chart',
    commandType: 'OwnerDrawing',
    primaryAccountLabel: 'Cash account',
    primaryAccountTypes: ['Asset'],
    secondaryAccountLabel: "Owner's capital account",
    secondaryAccountTypes: ['Equity'],
  },
]

const stateTone: Record<string, 'neutral' | 'success' | 'danger' | 'warning' | 'accent'> = {
  Received: 'neutral',
  Processing: 'neutral',
  NeedsInformation: 'warning',
  NeedsReview: 'warning',
  Approved: 'accent',
  Executing: 'accent',
  Completed: 'success',
  Rejected: 'danger',
  Failed: 'danger',
  Cancelled: 'danger',
  Superseded: 'danger',
}

const { request } = useApi()
const { user } = useAuth()

const tasks = ref<Task[]>([])
const accounts = ref<Account[]>([])
const loading = ref(true)
const error = ref<string | null>(null)
const queueFilter = ref<QueueFilter>('attention')

const expanded = ref(false)
const activeType = ref<TaskType>(types[0]!)

const {
  scrollRef: typeTabsScrollRef,
  showLeftFade: showTypeTabsLeftFade,
  showRightFade: showTypeTabsRightFade,
  updateScrollFade: updateTypeTabsFade,
} = useHorizontalScrollFade()
const {
  scrollRef: queueFilterScrollRef,
  showLeftFade: showQueueFilterLeftFade,
  showRightFade: showQueueFilterRightFade,
  updateScrollFade: updateQueueFilterFade,
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
const deferAccounts = ref(false)
const description = ref('')
const evidenceFile = ref<File | null>(null)
const submitting = ref(false)
const submitError = ref<string | null>(null)

const greeting = computed(() => {
  const hour = new Date().getHours()
  if (hour < 12) return 'Good morning'
  if (hour < 18) return 'Good afternoon'
  return 'Good evening'
})

const attentionStates = new Set(['NeedsInformation', 'NeedsReview', 'Failed'])
const progressStates = new Set(['Received', 'Processing', 'Approved', 'Executing'])
const completedStates = new Set(['Completed'])

const queueFilters: { key: QueueFilter; label: string }[] = [
  { key: 'attention', label: 'Needs attention' },
  { key: 'progress', label: 'In progress' },
  { key: 'completed', label: 'Completed' },
  { key: 'all', label: 'All tasks' },
]

function tasksForFilter(filter: QueueFilter): Task[] {
  if (filter === 'attention') return tasks.value.filter((task) => attentionStates.has(task.state))
  if (filter === 'progress') return tasks.value.filter((task) => progressStates.has(task.state))
  if (filter === 'completed') return tasks.value.filter((task) => completedStates.has(task.state))
  return tasks.value
}

const filteredTasks = computed(() => tasksForFilter(queueFilter.value))
const selectedFilterLabel = computed(
  () => queueFilters.find((filter) => filter.key === queueFilter.value)?.label ?? 'All tasks',
)
const emptyFilterTitle = computed(() => {
  if (queueFilter.value === 'attention') return 'Nothing needs attention'
  if (queueFilter.value === 'progress') return 'Nothing in progress'
  if (queueFilter.value === 'completed') return 'Nothing completed yet'
  return 'No Tasks yet'
})

const primaryAccountOptions = computed(() =>
  accounts.value
    .filter((account) => activeType.value.primaryAccountTypes.includes(account.account_type))
    .map((account) => ({
      value: account.id,
      label: account.account_name,
    })),
)

const secondaryAccountOptions = computed(() =>
  accounts.value
    .filter((account) => activeType.value.secondaryAccountTypes.includes(account.account_type))
    .map((account) => ({
      value: account.id,
      label: account.account_name,
    })),
)

async function loadTasks() {
  loading.value = true
  error.value = null
  try {
    const data = await request<{ data: Task[] }>('/api/v1/tasks')
    tasks.value = data.data
  } catch {
    error.value = 'Could not load your Work Queue.'
  } finally {
    loading.value = false
  }
}

async function loadAccounts() {
  const data = await request<{ data: Account[] }>('/api/v1/accounts')
  accounts.value = data.data
}

function selectType(type: TaskType) {
  activeType.value = type
  primaryAccountId.value = ''
  secondaryAccountId.value = ''
  deferAccounts.value = false
  submitError.value = null
  expanded.value = true
}

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
  submitError.value = null
  submitting.value = true

  try {
    const evidenceReference = await uploadEvidenceIfAttached()

    await request('/api/v1/tasks', {
      method: 'POST',
      headers: { 'Idempotency-Key': crypto.randomUUID() },
      body: {
        command_type: activeType.value.commandType,
        amount: amount.value,
        transaction_date: transactionDate.value,
        ...(deferAccounts.value
          ? {}
          : {
              primary_account_id: primaryAccountId.value,
              secondary_account_id: secondaryAccountId.value,
            }),
        description: description.value,
        ...(evidenceReference ? { evidence_reference: evidenceReference } : {}),
      },
    })

    amount.value = ''
    description.value = ''
    primaryAccountId.value = ''
    secondaryAccountId.value = ''
    deferAccounts.value = false
    evidenceFile.value = null
    expanded.value = false
    await loadTasks()
  } catch {
    submitError.value =
      'Could not submit this Task. Check the amount, accounts, and optional attachment.'
  } finally {
    submitting.value = false
  }
}

onMounted(async () => {
  await Promise.all([loadTasks(), loadAccounts()])
})
</script>

<template>
  <div class="mx-auto max-w-3xl space-y-10">
    <div class="pt-8 text-center sm:pt-12">
      <h1 class="text-2xl font-semibold tracking-tight text-ink">
        {{ greeting }}<template v-if="user">, {{ user.name.split(' ')[0] }}</template>
      </h1>
      <p class="mt-1 text-sm text-ink-tertiary">What would you like to get done today?</p>
    </div>

    <AppCard
      :padded="false"
      class="overflow-hidden rounded-[1.5rem] border-border-strong shadow-[0_12px_35px_rgb(var(--shadow-color)/0.06)]"
    >
      <div class="relative border-b border-border">
        <div
          ref="typeTabsScrollRef"
          class="flex items-center gap-2 overflow-x-auto bg-surface-secondary/40 px-3 py-2.5"
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
            @click="selectType(type)"
          >
            <AppIcon :name="type.icon" :size="14" />
            {{ type.label }}
          </button>
        </div>
        <div
          v-show="showTypeTabsLeftFade"
          class="pointer-events-none absolute inset-y-0 left-0 w-8 bg-gradient-to-r from-surface-secondary to-transparent"
        />
        <div
          v-show="showTypeTabsRightFade"
          class="pointer-events-none absolute inset-y-0 right-0 w-8 bg-gradient-to-l from-surface-secondary to-transparent"
        />
      </div>

      <form class="space-y-4 p-4 sm:p-5" @submit.prevent="onSubmit" @focusin="expanded = true">
        <div class="flex items-center gap-3">
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
              No accounts chosen yet — this will wait for you to decide.
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
                v-model="evidenceFile"
                accept="image/jpeg,image/png,image/webp,application/pdf"
                hint="JPG, PNG, WEBP, or PDF — up to 10MB"
              />
            </AppField>
          </div>
        </div>

        <p v-if="submitError" class="text-sm text-danger">{{ submitError }}</p>

        <div
          class="flex flex-col gap-3 border-t border-border pt-4 sm:flex-row sm:items-center sm:justify-between"
        >
          <p class="text-xs leading-5 text-ink-tertiary">
            Nothing posts until you review and confirm the Proposal.
          </p>
          <AppButton type="submit" variant="primary" :disabled="submitting" class="justify-center">
            <AppIcon name="send" :size="15" />
            {{ submitting ? 'Submitting…' : 'Submit for review' }}
          </AppButton>
        </div>
      </form>
    </AppCard>

    <section aria-labelledby="your-work-heading">
      <div class="mb-4">
        <h2 id="your-work-heading" class="text-xl font-semibold tracking-tight text-ink">
          Your work
        </h2>
        <p class="mt-1 text-sm text-ink-tertiary">
          Tasks are ordered by their latest submission time.
        </p>
      </div>

      <div class="relative mb-4">
        <div
          ref="queueFilterScrollRef"
          class="flex gap-1 overflow-x-auto rounded-2xl bg-surface-secondary p-1"
          role="tablist"
          aria-label="Filter work queue"
          @scroll="updateQueueFilterFade"
        >
          <button
            v-for="filter in queueFilters"
            :key="filter.key"
            type="button"
            role="tab"
            :aria-selected="queueFilter === filter.key"
            class="flex min-h-11 flex-1 shrink-0 items-center justify-center gap-2 whitespace-nowrap rounded-xl px-3 text-sm font-medium transition-colors sm:min-w-0"
            :class="
              queueFilter === filter.key
                ? 'bg-surface text-ink shadow-sm'
                : 'text-ink-secondary hover:bg-surface-hover hover:text-ink'
            "
            @click="queueFilter = filter.key"
          >
            <span>{{ filter.label }}</span>
            <span
              class="inline-flex min-w-6 items-center justify-center rounded-full bg-surface-tertiary px-1.5 py-0.5 text-xs tabular-nums text-ink-secondary"
            >
              {{ tasksForFilter(filter.key).length }}
            </span>
          </button>
        </div>
        <div
          v-show="showQueueFilterLeftFade"
          class="pointer-events-none absolute inset-y-1 left-1 w-8 rounded-l-xl bg-gradient-to-r from-surface-secondary to-transparent"
        />
        <div
          v-show="showQueueFilterRightFade"
          class="pointer-events-none absolute inset-y-1 right-1 w-8 rounded-r-xl bg-gradient-to-l from-surface-secondary to-transparent"
        />
      </div>

      <div class="overflow-hidden rounded-[1.5rem] border border-border bg-surface">
        <div
          class="flex min-h-14 items-center justify-between gap-3 border-b border-border px-4 sm:px-5"
        >
          <h3 class="text-xs font-semibold uppercase tracking-[0.14em] text-ink-tertiary">
            {{ selectedFilterLabel }}
          </h3>
          <button
            v-if="!loading"
            type="button"
            class="text-sm font-medium text-ink-secondary hover:text-ink"
            @click="loadTasks"
          >
            Refresh
          </button>
        </div>

        <div v-if="loading" class="px-5 py-12 text-center text-sm text-ink-tertiary">Loading…</div>
        <div v-else-if="error" class="px-5 py-12 text-center text-sm text-danger">
          {{ error }}
        </div>
        <EmptyState
          v-else-if="tasks.length === 0"
          :bordered="false"
          title="No Tasks yet"
          description="Submit one above. It will wait here for your review."
          class="min-h-48"
        />
        <EmptyState
          v-else-if="filteredTasks.length === 0"
          :bordered="false"
          :title="emptyFilterTitle"
          description="Choose another filter to see the rest of your tasks."
          class="min-h-48"
        />
        <ul v-else class="divide-y divide-border">
          <li v-for="task in filteredTasks" :key="task.id">
            <NuxtLink
              :to="`/tasks/${task.id}`"
              class="flex items-center gap-3 px-4 py-3 transition-colors hover:bg-surface-hover sm:px-5"
            >
              <span
                class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-surface-tertiary text-ink-secondary"
              >
                <AppIcon name="tasks" :size="16" />
              </span>
              <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-medium text-ink">Task {{ task.id.slice(0, 8) }}</p>
                <p class="text-xs text-ink-tertiary">
                  {{ new Date(task.created_at).toLocaleString() }}
                </p>
              </div>
              <AppBadge :tone="stateTone[task.state] ?? 'neutral'">{{ task.state }}</AppBadge>
              <AppIcon name="chevron-left" :size="15" class="rotate-180 text-ink-tertiary" />
            </NuxtLink>
          </li>
        </ul>
      </div>
    </section>
  </div>
</template>
