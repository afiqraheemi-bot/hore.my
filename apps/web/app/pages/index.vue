<script setup lang="ts">
/**
 * Home — the action-first workspace (HORE_MY_MASTER_CONTEXT.md §5) and
 * the authenticated default landing experience (ADR-0009, WTS-001).
 * Also reachable at `/tasks` via `alias` below — the *same* route, no
 * client-side redirect or loading flash; either URL renders this page
 * directly. Manual Entry remains available at `/manual-entry` as a
 * controlled, deterministic direct-posting fallback.
 *
 * The composer below intentionally mirrors `AppComposer.vue`'s own
 * five-type shape exactly (same fields, same accounting-side-neutral
 * primary/secondary naming, same real Evidence attachment) — the only
 * difference is *where* it submits: `POST /api/v1/tasks` (lands in
 * `NeedsReview`, awaiting Human Confirmation) instead of directly to
 * `/api/v1/expenses` etc. There is still no AI here — a human
 * describing what to record *is* the "Processing" step at this phase
 * (WTS-001 §3), synchronously.
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

interface TaskType {
  key: string
  label: string
  icon: 'receipt' | 'wallet' | 'bank' | 'building' | 'chart'
  commandType: string
  primaryAccountLabel: string
  primaryAccountTypes: string[]
  secondaryAccountLabel: string
  secondaryAccountTypes: string[]
}

type QueueFilter = 'attention' | 'active' | 'completed' | 'all'

// Mirrors AppComposer.vue's own five types and its 2026-09-11 audited
// primary/secondary account mapping exactly — see that component's
// own per-type comments for the real Debit/Credit side of each.
const types: TaskType[] = [
  {
    key: 'expense',
    label: 'Expense',
    icon: 'receipt',
    commandType: 'Expense',
    primaryAccountLabel: 'Expense account',
    primaryAccountTypes: ['Expense'],
    secondaryAccountLabel: 'Paid from',
    secondaryAccountTypes: ['Asset'],
  },
  {
    key: 'income',
    label: 'Income',
    icon: 'wallet',
    commandType: 'Income',
    primaryAccountLabel: 'Income account',
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
    key: 'capital',
    label: 'Capital contribution',
    icon: 'building',
    commandType: 'CapitalContribution',
    primaryAccountLabel: 'Cash account',
    primaryAccountTypes: ['Asset'],
    secondaryAccountLabel: 'Equity account',
    secondaryAccountTypes: ['Equity'],
  },
  {
    key: 'drawing',
    label: 'Owner drawing',
    icon: 'chart',
    commandType: 'OwnerDrawing',
    primaryAccountLabel: 'Cash account',
    primaryAccountTypes: ['Asset'],
    secondaryAccountLabel: 'Equity account',
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

const expanded = ref(false)
const activeType = ref<TaskType>(types[0]!)
const amount = ref('')
const transactionDate = ref(new Date().toISOString().slice(0, 10))
const primaryAccountId = ref('')
const secondaryAccountId = ref('')
const description = ref('')
const evidenceFile = ref<File | null>(null)
const submitting = ref(false)
const submitError = ref<string | null>(null)
const queueFilter = ref<QueueFilter>('attention')
const amountInput = ref<HTMLInputElement | null>(null)

const attentionStates = ['NeedsInformation', 'NeedsReview', 'Failed']
const activeStates = ['Received', 'Processing', 'Approved', 'Executing']
const completedStates = ['Completed', 'Rejected', 'Cancelled', 'Superseded']

const queueFilters: Array<{ key: QueueFilter; label: string }> = [
  { key: 'attention', label: 'Needs attention' },
  { key: 'active', label: 'In progress' },
  { key: 'completed', label: 'Completed' },
  { key: 'all', label: 'All tasks' },
]

const greeting = computed(() => {
  const hour = new Date().getHours()
  if (hour < 12) return 'Good morning'
  if (hour < 18) return 'Good afternoon'
  return 'Good evening'
})

const primaryAccountOptions = computed(() =>
  accounts.value
    .filter((a) => activeType.value.primaryAccountTypes.includes(a.account_type))
    .map((a) => ({ value: a.id, label: `${a.account_code} — ${a.account_name}` })),
)
const secondaryAccountOptions = computed(() =>
  accounts.value
    .filter((a) => activeType.value.secondaryAccountTypes.includes(a.account_type))
    .map((a) => ({ value: a.id, label: `${a.account_code} — ${a.account_name}` })),
)

const attentionCount = computed(
  () => tasks.value.filter((task) => attentionStates.includes(task.state)).length,
)
const activeCount = computed(
  () => tasks.value.filter((task) => activeStates.includes(task.state)).length,
)
const completedCount = computed(
  () => tasks.value.filter((task) => completedStates.includes(task.state)).length,
)
const filteredTasks = computed(() => {
  if (queueFilter.value === 'attention') {
    return tasks.value.filter((task) => attentionStates.includes(task.state))
  }
  if (queueFilter.value === 'active') {
    return tasks.value.filter((task) => activeStates.includes(task.state))
  }
  if (queueFilter.value === 'completed') {
    return tasks.value.filter((task) => completedStates.includes(task.state))
  }
  return tasks.value
})

const selectedFilterLabel = computed(
  () => queueFilters.find((filter) => filter.key === queueFilter.value)?.label ?? 'Tasks',
)

function stateLabel(state: string): string {
  return state.replace(/([a-z])([A-Z])/g, '$1 $2')
}

function stateDescription(task: Task): string {
  const descriptions: Record<string, string> = {
    Received: 'Waiting to be processed',
    Processing: 'Preparing a proposal',
    NeedsInformation: 'More information is required',
    NeedsReview: 'Review the proposal before posting',
    Approved: 'Approved and ready to resume',
    Executing: 'Accounting Core is processing this task',
    Completed: 'Posted successfully',
    Rejected: 'Proposal rejected',
    Failed: task.failure_reason ?? 'Accounting Core rejected this task',
    Cancelled: 'Task cancelled',
    Superseded: 'Replaced by a newer task',
  }

  return descriptions[task.state] ?? 'Open task details'
}

function taskTime(value: string): string {
  return new Intl.DateTimeFormat('en-MY', {
    day: 'numeric',
    month: 'short',
    hour: 'numeric',
    minute: '2-digit',
  }).format(new Date(value))
}

function filterCount(filter: QueueFilter): number {
  if (filter === 'attention') return attentionCount.value
  if (filter === 'active') return activeCount.value
  if (filter === 'completed') return completedCount.value
  return tasks.value.length
}

function openComposer(type?: TaskType) {
  if (type) selectType(type)
  expanded.value = true
  nextTick(() => amountInput.value?.focus())
}

async function loadTasks() {
  loading.value = true
  error.value = null
  try {
    const data = await request<{ data: Task[] }>('/api/v1/tasks')
    tasks.value = data.data
  } catch {
    error.value = 'Failed to load the Work Queue.'
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
  submitError.value = null
}

/**
 * Uploads the attached file (AETS-015) before submitting the Task —
 * mirrors `AppComposer.vue`'s own identical reasoning: an evidence
 * reference is only ever sent alongside a Task that genuinely has
 * that evidence already stored.
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
        primary_account_id: primaryAccountId.value,
        secondary_account_id: secondaryAccountId.value,
        description: description.value,
        ...(evidenceReference ? { evidence_reference: evidenceReference } : {}),
      },
    })
    amount.value = ''
    description.value = ''
    primaryAccountId.value = ''
    secondaryAccountId.value = ''
    evidenceFile.value = null
    expanded.value = false
    await loadTasks()
  } catch {
    submitError.value =
      'Could not submit this Task — check the amount format (e.g. 50.00), account selection, and attachment (max 10MB, image or PDF).'
  } finally {
    submitting.value = false
  }
}

onMounted(async () => {
  await Promise.all([loadTasks(), loadAccounts()])
})
</script>

<template>
  <div class="space-y-8 pb-10">
    <section class="pt-2" aria-labelledby="workspace-heading">
      <div class="flex flex-col justify-between gap-5 lg:flex-row lg:items-end">
        <div>
          <p class="mb-2 text-xs font-semibold uppercase tracking-[0.16em] text-ink-tertiary">
            Work Queue
          </p>
          <h1
            id="workspace-heading"
            class="max-w-2xl text-3xl font-semibold tracking-tight text-ink sm:text-4xl"
          >
            {{ greeting }}<template v-if="user">, {{ user.name.split(' ')[0] }}</template
            >. What would you like to get done?
          </h1>
          <p class="mt-3 max-w-2xl text-sm leading-6 text-ink-secondary">
            Create a task, review the proposed accounting impact, then confirm it. Nothing reaches
            the ledger without your approval.
          </p>
        </div>

        <div
          class="grid grid-cols-3 divide-x divide-border rounded-2xl border border-border bg-surface-secondary px-2 py-3 lg:min-w-[340px]"
        >
          <button type="button" class="px-3 text-left" @click="queueFilter = 'attention'">
            <span class="block text-xl font-semibold text-ink">{{ attentionCount }}</span>
            <span class="text-[11px] text-ink-tertiary">Need attention</span>
          </button>
          <button type="button" class="px-3 text-left" @click="queueFilter = 'active'">
            <span class="block text-xl font-semibold text-ink">{{ activeCount }}</span>
            <span class="text-[11px] text-ink-tertiary">In progress</span>
          </button>
          <button type="button" class="px-3 text-left" @click="queueFilter = 'completed'">
            <span class="block text-xl font-semibold text-ink">{{ completedCount }}</span>
            <span class="text-[11px] text-ink-tertiary">Finished</span>
          </button>
        </div>
      </div>
    </section>

    <section aria-labelledby="new-task-heading">
      <AppCard :padded="false" class="overflow-hidden border-border-strong shadow-sm">
        <div class="flex items-center justify-between border-b border-border px-4 py-3 sm:px-5">
          <div>
            <h2 id="new-task-heading" class="text-sm font-semibold text-ink">Create a new task</h2>
            <p class="mt-0.5 text-xs text-ink-tertiary">
              Start with the type and amount. Add the accounting details when ready.
            </p>
          </div>
          <span
            class="hidden rounded-full bg-success-soft px-2.5 py-1 text-[11px] font-medium text-success sm:inline-flex"
          >
            Human confirmation required
          </span>
        </div>

        <div
          class="grid grid-cols-2 gap-2 border-b border-border bg-surface-secondary/60 p-3 sm:grid-cols-5 sm:p-4"
        >
          <button
            v-for="type in types"
            :key="type.key"
            type="button"
            class="flex min-h-16 items-center gap-2 rounded-xl border px-3 py-2.5 text-left text-xs font-medium transition-all"
            :class="
              activeType.key === type.key
                ? 'border-accent bg-accent text-accent-contrast shadow-sm'
                : 'border-border bg-surface text-ink-secondary hover:border-border-strong hover:bg-surface-hover hover:text-ink'
            "
            @click="openComposer(type)"
          >
            <span
              class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg"
              :class="activeType.key === type.key ? 'bg-surface/10' : 'bg-surface-secondary'"
            >
              <AppIcon :name="type.icon" :size="16" />
            </span>
            <span class="leading-tight">{{ type.label }}</span>
          </button>
        </div>

        <form class="space-y-4 p-4 sm:p-5" @submit.prevent="onSubmit" @focusin="expanded = true">
          <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
            <div
              class="flex min-w-0 flex-1 items-baseline gap-3 rounded-xl bg-surface-secondary px-4 py-3"
            >
              <span class="text-sm font-semibold text-ink-secondary">MYR</span>
              <input
                ref="amountInput"
                v-model="amount"
                placeholder="0.00"
                required
                inputmode="decimal"
                aria-label="Amount"
                class="min-w-0 flex-1 border-0 bg-transparent p-0 text-3xl font-semibold tracking-tight text-ink placeholder:text-ink-tertiary focus:outline-none focus:ring-0"
              />
            </div>
            <label
              class="flex items-center gap-2 text-xs font-medium text-ink-secondary sm:flex-col sm:items-start"
            >
              Transaction date
              <input
                v-model="transactionDate"
                type="date"
                required
                class="h-11 rounded-xl border border-border bg-surface px-3 text-sm text-ink"
              />
            </label>
          </div>

          <div
            v-if="expanded"
            class="grid grid-cols-1 gap-4 border-t border-border pt-4 sm:grid-cols-2"
          >
            <AppField :label="activeType.primaryAccountLabel">
              <AppSelect
                v-model="primaryAccountId"
                :options="primaryAccountOptions"
                placeholder="Select an account"
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

          <p v-if="submitError" class="text-sm text-danger">{{ submitError }}</p>

          <div
            class="flex flex-col gap-3 border-t border-border pt-4 sm:flex-row sm:items-center sm:justify-between"
          >
            <p class="flex items-center gap-2 text-xs leading-5 text-ink-tertiary">
              <span
                class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-success-soft text-success"
                ><AppIcon name="check" :size="12"
              /></span>
              This creates a review task. It does not post a journal entry.
            </p>
            <AppButton
              type="submit"
              variant="primary"
              :disabled="submitting"
              class="justify-center sm:min-w-40"
            >
              <AppIcon name="send" :size="15" />
              {{ submitting ? 'Submitting…' : 'Submit for review' }}
            </AppButton>
          </div>
        </form>
      </AppCard>
    </section>

    <section aria-labelledby="queue-heading">
      <div class="mb-4 flex flex-col justify-between gap-3 sm:flex-row sm:items-end">
        <div>
          <h2 id="queue-heading" class="text-xl font-semibold tracking-tight text-ink">
            Your work
          </h2>
          <p class="mt-1 text-sm text-ink-tertiary">
            Tasks are ordered by their latest submission time.
          </p>
        </div>
        <div
          class="flex max-w-full gap-1 overflow-x-auto rounded-xl bg-surface-secondary p-1"
          role="tablist"
          aria-label="Filter Work Queue"
        >
          <button
            v-for="filter in queueFilters"
            :key="filter.key"
            type="button"
            role="tab"
            :aria-selected="queueFilter === filter.key"
            class="flex shrink-0 items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-medium transition-colors"
            :class="
              queueFilter === filter.key
                ? 'bg-surface text-ink shadow-sm'
                : 'text-ink-secondary hover:text-ink'
            "
            @click="queueFilter = filter.key"
          >
            {{ filter.label }}
            <span class="rounded-full bg-surface-tertiary px-1.5 py-0.5 text-[10px]">{{
              filterCount(filter.key)
            }}</span>
          </button>
        </div>
      </div>

      <div class="overflow-hidden rounded-2xl border border-border bg-surface">
        <div
          class="flex items-center justify-between border-b border-border bg-surface-secondary/50 px-4 py-3 sm:px-5"
        >
          <p class="text-xs font-semibold uppercase tracking-[0.12em] text-ink-tertiary">
            {{ selectedFilterLabel }}
          </p>
          <button
            type="button"
            class="text-xs font-medium text-ink-secondary hover:text-ink"
            @click="loadTasks"
          >
            Refresh
          </button>
        </div>

        <p v-if="loading" class="px-5 py-8 text-center text-sm text-ink-tertiary">
          Loading your tasks…
        </p>
        <p v-else-if="error" class="px-5 py-8 text-center text-sm text-danger">{{ error }}</p>
        <EmptyState
          v-else-if="tasks.length === 0"
          title="No Tasks yet"
          description="Create your first task above. It will wait here for your review."
        />
        <div v-else-if="filteredTasks.length === 0" class="px-5 py-10 text-center">
          <p class="text-sm font-medium text-ink">
            Nothing in {{ selectedFilterLabel.toLowerCase() }}
          </p>
          <p class="mt-1 text-xs text-ink-tertiary">
            Choose another filter to see the rest of your tasks.
          </p>
        </div>
        <div v-else class="divide-y divide-border">
          <NuxtLink
            v-for="task in filteredTasks"
            :key="task.id"
            :to="`/tasks/${task.id}`"
            class="group flex items-center gap-3 px-4 py-4 transition-colors hover:bg-surface-hover sm:px-5"
          >
            <span
              class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl"
              :class="
                task.state === 'Completed'
                  ? 'bg-success-soft text-success'
                  : attentionStates.includes(task.state)
                    ? 'bg-warning-soft text-warning'
                    : 'bg-surface-secondary text-ink-secondary'
              "
            >
              <AppIcon :name="task.state === 'Completed' ? 'check' : 'receipt'" :size="18" />
            </span>
            <div class="min-w-0 flex-1">
              <div class="flex flex-wrap items-center gap-2">
                <p class="text-sm font-semibold text-ink">Task {{ task.id.slice(0, 8) }}</p>
                <AppBadge :tone="stateTone[task.state] ?? 'neutral'">{{
                  stateLabel(task.state)
                }}</AppBadge>
              </div>
              <p class="mt-1 truncate text-sm text-ink-secondary">{{ stateDescription(task) }}</p>
              <p class="mt-1 text-xs text-ink-tertiary">
                Submitted {{ taskTime(task.created_at) }}
              </p>
            </div>
            <AppIcon
              name="chevron-left"
              :size="17"
              class="shrink-0 rotate-180 text-ink-tertiary transition-transform group-hover:translate-x-0.5 group-hover:text-ink"
            />
          </NuxtLink>
        </div>
      </div>
    </section>
  </div>
</template>
