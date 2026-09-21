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
useHead({ title: 'Work Queue' })

interface TaskSummary {
  command_type: string
  amount: string
  description: string
}

interface Task {
  id: string
  state: string
  created_at: string
  completed_at: string | null
  result_journal_id: string | null
  failure_reason: string | null
  summary: TaskSummary | null
}

type QueueFilter = 'attention' | 'progress' | 'completed' | 'all'

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
const loading = ref(true)
const error = ref<string | null>(null)
const queueFilter = ref<QueueFilter>('attention')

const {
  scrollRef: queueFilterScrollRef,
  showLeftFade: showQueueFilterLeftFade,
  showRightFade: showQueueFilterRightFade,
  updateScrollFade: updateQueueFilterFade,
} = useHorizontalScrollFade()

const composerAnchorRef = ref<HTMLElement | null>(null)
const composerRef = ref<{
  selectTypeByKey: (key: string) => void
  focusAmount: () => void
  pickFile: () => void
} | null>(null)

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
const emptyFilterTitle = computed(() => {
  if (queueFilter.value === 'attention') return 'Nothing needs attention'
  if (queueFilter.value === 'progress') return 'Nothing in progress'
  if (queueFilter.value === 'completed') return 'Nothing completed yet'
  return 'No Tasks yet'
})

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
  schedulePolling()
}

/**
 * The same "a queue worker may still be acting on this" states as
 * `tasks/[id].vue` — polls the list quietly so a Task moving out of
 * Processing/Executing (or being picked up from Received) is reflected
 * without a manual refresh (HORE_MY_MASTER_CONTEXT.md §5, "progressive
 * responses").
 */
const livePollingStates = new Set(['Received', 'Processing', 'Executing'])
let pollHandle: ReturnType<typeof setInterval> | undefined
const isPolling = ref(false)

function schedulePolling() {
  if (pollHandle) return
  if (!tasks.value.some((task) => livePollingStates.has(task.state))) return

  isPolling.value = true
  pollHandle = setInterval(async () => {
    if (!tasks.value.some((task) => livePollingStates.has(task.state))) {
      clearInterval(pollHandle)
      pollHandle = undefined
      isPolling.value = false
      return
    }
    try {
      const data = await request<{ data: Task[] }>('/api/v1/tasks')
      tasks.value = data.data
    } catch {
      // Keep the last known list; the next tick tries again.
    }
  }, 3000)
}

onUnmounted(() => {
  if (pollHandle) clearInterval(pollHandle)
})

type TaskIconName =
  'receipt' | 'wallet' | 'bank' | 'building' | 'chart' | 'download' | 'send' | 'tasks'

const commandTypeIcon: Record<string, TaskIconName> = {
  Expense: 'receipt',
  Income: 'wallet',
  Transfer: 'bank',
  CapitalContribution: 'building',
  OwnerDrawing: 'chart',
}

function iconForTask(task: Task): TaskIconName {
  return commandTypeIcon[task.summary?.command_type ?? ''] ?? 'tasks'
}

/**
 * One-click shortcuts into the composer/nav below, named after
 * HORE_MY_MASTER_CONTEXT.md §5's own quick-action list. "Record
 * income/expense" and "Review transactions" both land on content
 * already on this page (the composer's type tabs, the Work Queue's own
 * filters) rather than duplicating it elsewhere.
 */
interface QuickAction {
  key: string
  label: string
  icon: 'receipt' | 'bank' | 'wallet' | 'send' | 'tasks' | 'chart'
  to?: string
  run?: () => void
}

function focusComposer(typeKey: string) {
  composerRef.value?.selectTypeByKey(typeKey)
  nextTick(() => {
    composerAnchorRef.value?.scrollIntoView({ behavior: 'smooth', block: 'start' })
    composerRef.value?.focusAmount()
  })
}

function quickUploadReceipt() {
  composerRef.value?.selectTypeByKey('expense')
  nextTick(() => {
    composerAnchorRef.value?.scrollIntoView({ behavior: 'smooth', block: 'start' })
    composerRef.value?.pickFile()
  })
}

function quickReviewTransactions() {
  queueFilter.value = 'attention'
  nextTick(() => {
    document
      .getElementById('your-work-heading')
      ?.scrollIntoView({ behavior: 'smooth', block: 'start' })
  })
}

const quickActions: QuickAction[] = [
  { key: 'upload-receipt', label: 'Upload receipt', icon: 'receipt', run: quickUploadReceipt },
  { key: 'import-bank', label: 'Import bank statement', icon: 'bank', to: '/bank-accounts' },
  {
    key: 'record',
    label: 'New transaction',
    icon: 'wallet',
    run: () => focusComposer('income'),
  },
  { key: 'create-invoice', label: 'Create invoice', icon: 'send', to: '/invoices?new=1' },
  { key: 'review', label: 'Review transactions', icon: 'tasks', run: quickReviewTransactions },
  { key: 'reports', label: 'View reports', icon: 'chart', to: '/reports' },
]

onMounted(loadTasks)
</script>

<template>
  <div class="mx-auto max-w-3xl space-y-10">
    <div class="pt-8 text-center sm:pt-12">
      <h1 class="text-2xl font-semibold tracking-tight text-ink">
        {{ greeting }}<template v-if="user">, {{ user.name.split(' ')[0] }}</template>
      </h1>
      <p class="mt-1 text-sm text-ink-tertiary">What would you like to get done today?</p>
    </div>

    <nav aria-label="Quick actions" class="flex flex-wrap justify-center gap-2">
      <template v-for="action in quickActions" :key="action.key">
        <NuxtLink
          v-if="action.to"
          :to="action.to"
          class="flex min-h-9 items-center gap-1.5 whitespace-nowrap rounded-full border border-border bg-surface px-3 py-1.5 text-xs font-medium text-ink-secondary transition-colors hover:border-border-strong hover:bg-surface-hover hover:text-ink"
        >
          <AppIcon :name="action.icon" :size="14" />
          {{ action.label }}
        </NuxtLink>
        <button
          v-else
          type="button"
          class="flex min-h-9 items-center gap-1.5 whitespace-nowrap rounded-full border border-border bg-surface px-3 py-1.5 text-xs font-medium text-ink-secondary transition-colors hover:border-border-strong hover:bg-surface-hover hover:text-ink"
          @click="action.run?.()"
        >
          <AppIcon :name="action.icon" :size="14" />
          {{ action.label }}
        </button>
      </template>
    </nav>

    <div ref="composerAnchorRef">
      <AppComposer ref="composerRef" mode="review" @created="loadTasks" />
    </div>

    <section aria-labelledby="your-work-heading">
      <div class="mb-4 flex items-center justify-between gap-3">
        <h2 id="your-work-heading" class="text-xl font-semibold tracking-tight text-ink">
          Your work
        </h2>
        <div class="flex items-center gap-2">
          <span
            v-if="isPolling"
            class="relative flex h-2 w-2"
            role="status"
            aria-label="Updating automatically"
          >
            <span
              class="absolute inline-flex h-full w-full animate-ping rounded-full bg-accent opacity-75"
            />
            <span class="relative inline-flex h-2 w-2 rounded-full bg-accent" />
          </span>
          <button
            v-if="!loading"
            type="button"
            aria-label="Refresh"
            class="flex h-7 w-7 items-center justify-center rounded-full text-ink-tertiary transition-colors hover:bg-surface-hover hover:text-ink"
            @click="loadTasks"
          >
            <AppIcon name="refresh" :size="14" />
          </button>
        </div>
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
                <AppIcon :name="iconForTask(task)" :size="16" />
              </span>
              <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-medium text-ink">
                  {{ task.summary?.description || 'Task' }}
                </p>
                <p class="truncate text-xs text-ink-tertiary">
                  <template v-if="task.summary">RM{{ task.summary.amount }} · </template
                  >{{ formatRelativeDate(task.created_at.slice(0, 10)) }}
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
