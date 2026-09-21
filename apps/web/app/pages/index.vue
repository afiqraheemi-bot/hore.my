<script setup lang="ts">
/**
 * Home — the action-first workspace (HORE_MY_MASTER_CONTEXT.md §5) and
 * the authenticated default landing experience (ADR-0009, WTS-001).
 * Also reachable at `/tasks` (no client-side redirect or loading
 * flash) and at `/manual-entry` — the same page, opened with the
 * composer's "Post directly" posture preselected instead of "Wait for
 * my review" — via `alias` below.
 *
 * **One page, not two (UX-01 follow-up, 2026-09-21).** This used to be
 * two separate pages/nav entries with near-identical composers; once
 * AppComposer.vue grew an in-place mode toggle, keeping a second page
 * around was itself the redundancy the original audit finding named —
 * a returning owner reasonably asked "if it's merged, why are there
 * still two things in the sidebar?". `initialMode` below only picks
 * which posture the composer *opens* in; the toggle inside it still
 * switches freely either way without navigating.
 */
definePageMeta({ middleware: 'auth', alias: ['/tasks', '/manual-entry'] })
useHead({ title: 'Work Queue' })

const initialMode = useRoute().path === '/manual-entry' ? 'direct' : 'review'

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

interface EvidenceEntry {
  journal_id: string
  financial_date: string
  source: string
  amount: string
  has_evidence: boolean
  evidence_references: string[]
  description: string | null
}

const SOURCE_LABELS: Record<
  string,
  { label: string; icon: 'receipt' | 'wallet' | 'bank' | 'building' | 'chart' }
> = {
  expense: { label: 'Expense', icon: 'receipt' },
  income: { label: 'Income', icon: 'wallet' },
  transfer: { label: 'Transfer', icon: 'bank' },
  invoice: { label: 'Invoice issued', icon: 'receipt' },
  payment: { label: 'Payment received', icon: 'wallet' },
  'owner-equity': { label: 'Owner equity', icon: 'building' },
  'period-closing': { label: 'Books closed', icon: 'chart' },
}

function describeSource(source: string): {
  label: string
  icon: 'receipt' | 'wallet' | 'bank' | 'building' | 'chart'
} {
  const prefix = source.split(':')[0] ?? ''
  return SOURCE_LABELS[prefix] ?? { label: prefix || 'Record', icon: 'chart' }
}

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

/** Mirrors `invoices/index.vue`'s own `pdfUrl()` — a same-site GET
 * carries the Sanctum SPA session cookie, so no blob/fetch plumbing
 * is needed to open the originally-uploaded receipt/document. */
function evidenceUrl(evidenceId: string): string {
  const config = useRuntimeConfig()
  const port = config.public.apiPort as string
  return `${window.location.protocol}//${window.location.hostname}:${port}/api/v1/evidence/${evidenceId}`
}

const tasks = ref<Task[]>([])
const loading = ref(true)
const error = ref<string | null>(null)
const queueFilter = ref<QueueFilter>('attention')

const activityEntries = ref<EvidenceEntry[]>([])
const activityLoading = ref(true)

// Caps the calm, at-a-glance list at a fixed height regardless of how
// much a tenant has recorded — "Show more" reveals the rest a page at
// a time rather than dumping every entry from the last 60 days at once.
const ACTIVITY_STEP = 8
const visibleActivityCount = ref(ACTIVITY_STEP)
const visibleActivityEntries = computed(() =>
  activityEntries.value.slice(0, visibleActivityCount.value),
)

async function loadActivity() {
  activityLoading.value = true
  visibleActivityCount.value = ACTIVITY_STEP
  try {
    const periodEnd = new Date().toISOString().slice(0, 10)
    const periodStart = new Date(Date.now() - 60 * 86_400_000).toISOString().slice(0, 10)
    const data = await request<{ entries: EvidenceEntry[] }>('/api/v1/reports/evidence-index', {
      query: { period_start: periodStart, period_end: periodEnd },
    })
    activityEntries.value = [...data.entries].sort((a, b) =>
      b.financial_date.localeCompare(a.financial_date),
    )
  } finally {
    activityLoading.value = false
  }
}

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

async function onComposerCreated() {
  await Promise.all([loadTasks(), loadActivity()])
}

onMounted(() => {
  loadTasks()
  loadActivity()
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
      <AppComposer ref="composerRef" :mode="initialMode" @created="onComposerCreated" />
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
          class="pointer-events-none absolute inset-y-1 left-1 w-8 rounded-l-xl bg-gradient-to-r from-[rgb(var(--shadow-color)/0.14)] to-transparent"
        />
        <div
          v-show="showQueueFilterRightFade"
          class="pointer-events-none absolute inset-y-1 right-1 w-8 rounded-r-xl bg-gradient-to-l from-[rgb(var(--shadow-color)/0.14)] to-transparent"
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

    <section aria-labelledby="recent-activity-heading">
      <h2 id="recent-activity-heading" class="mb-4 text-xl font-semibold tracking-tight text-ink">
        Recent activity
      </h2>

      <p v-if="activityLoading" class="text-sm text-ink-tertiary">Loading…</p>
      <EmptyState
        v-else-if="activityEntries.length === 0"
        title="Nothing recorded in the last 60 days"
        description="Postings from either mode above — reviewed or direct — show up here once they've landed in your books."
      />
      <ul v-else class="space-y-1.5">
        <li v-for="entry in visibleActivityEntries" :key="entry.journal_id">
          <AppCard :padded="false" hoverable>
            <div class="flex items-center gap-3 px-4 py-3">
              <span
                class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-surface-tertiary text-ink-secondary"
              >
                <AppIcon :name="describeSource(entry.source).icon" :size="16" />
              </span>
              <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-medium text-ink">
                  {{ entry.description || describeSource(entry.source).label }}
                </p>
                <p class="truncate text-xs text-ink-tertiary">
                  <template v-if="entry.description"
                    >{{ describeSource(entry.source).label }} · </template
                  >{{ formatRelativeDate(entry.financial_date) }}
                </p>
              </div>
              <p class="shrink-0 text-sm font-medium text-ink">RM{{ entry.amount }}</p>
              <a
                v-if="entry.evidence_references.length > 0"
                :href="evidenceUrl(entry.evidence_references[0]!)"
                target="_blank"
                rel="noopener"
              >
                <AppBadge tone="success">
                  <AppIcon name="paperclip" :size="11" /> Evidence
                </AppBadge>
              </a>
            </div>
          </AppCard>
        </li>
      </ul>

      <button
        v-if="visibleActivityCount < activityEntries.length"
        type="button"
        class="mt-3 w-full text-center text-sm font-medium text-ink-tertiary hover:text-ink"
        @click="visibleActivityCount += ACTIVITY_STEP"
      >
        Show {{ Math.min(ACTIVITY_STEP, activityEntries.length - visibleActivityCount) }} more
      </button>
    </section>
  </div>
</template>
