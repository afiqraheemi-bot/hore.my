<script setup lang="ts">
/**
 * Home — the action-first workspace (HORE_MY_MASTER_CONTEXT.md §5) and
 * the authenticated default landing experience (ADR-0009, WTS-001).
 * Also reachable at `/tasks` and `/manual-entry` (no client-side
 * redirect or loading flash) — historical aliases to this same page;
 * kept only so old links/bookmarks still resolve.
 *
 * **One page, one path decided by data (2026-09-21, Founder-directed
 * follow-up to UX-01).** This used to expose a "Wait for my
 * review"/"Post directly" toggle, as if which to use were a user
 * preference. It isn't: AppComposer.vue now posts directly whenever
 * you filled in the whole form yourself, and only lands here, pinned
 * to the top of Recent activity, when you explicitly deferred
 * choosing accounts — that's where incomplete decisions wait, not a
 * mode to opt into. See
 * AppComposer.vue's own docblock for the full reasoning, including how
 * this same door is what a future AI-produced Proposal will use too.
 */
definePageMeta({ middleware: 'auth', alias: ['/tasks', '/manual-entry'] })
useHead({ title: 'Home' })

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

interface EvidenceEntry {
  journal_id: string
  financial_date: string
  posted_at: string
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

const activityEntries = ref<EvidenceEntry[]>([])
const activityLoading = ref(true)

// Caps the calm, at-a-glance list at a fixed height regardless of how
// much a tenant has recorded — "Show more" reveals the rest a page at
// a time rather than dumping every entry from the last 60 days at once.
const ACTIVITY_STEP = 8
const visibleActivityCount = ref(ACTIVITY_STEP)

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
      b.posted_at.localeCompare(a.posted_at),
    )
  } catch {
    error.value = 'Could not load your recent activity.'
  } finally {
    activityLoading.value = false
  }
}

const composerAnchorRef = ref<HTMLElement | null>(null)
const composerRef = ref<{
  selectTypeByKey: (key: string) => void
  focusAmount: () => void
  pickFile: () => void
} | null>(null)

/**
 * Quick actions collapse into one trigger that expands into a 3x2
 * tray (Founder-approved concept, 2026-09-21 — the "expanding tray"
 * prototype over a literal iOS-style radial burst: the same spring
 * delight, but labels stay legible instead of hover-only, which a
 * radial layout can't offer on a touch device).
 */
const quickActionsOpen = ref(false)

function closeQuickActions() {
  quickActionsOpen.value = false
}

function onQuickActionsKeydown(event: KeyboardEvent) {
  if (event.key === 'Escape') closeQuickActions()
}

const greeting = computed(() => {
  const hour = new Date().getHours()
  if (hour < 12) return 'Good morning'
  if (hour < 18) return 'Good afternoon'
  return 'Good evening'
})

async function loadTasks() {
  loading.value = true
  error.value = null
  try {
    const data = await request<{ data: Task[] }>('/api/v1/tasks')
    tasks.value = data.data
  } catch {
    error.value = 'Could not load your recent activity.'
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
 * One unified, calm feed (Founder-directed simplification, 2026-09-21)
 * — replaces the previous two-section, five-tab "Your work" +
 * "Recent activity" layout. Recent activity (real postings) is the
 * primary content; anything still needing you pins to the top with a
 * status badge instead of living in a separate tab system.
 *
 * A `Completed` Task is the one state deliberately excluded — its own
 * Journal already appears via `activityEntries` (the Evidence Index),
 * so including both would show the same event twice. Every other dead
 * -end state (`Rejected` / `Failed` / `Cancelled` / `Superseded`)
 * still appears, muted into the same chronological list as real
 * postings rather than pinned at the top — resolved, so it doesn't
 * need your attention, but never silently gone (CTO call, 2026-09-21:
 * "nothing hidden" matters at least as much as "calm" for a financial
 * app someone might later ask "wait, what happened to that one?"
 * about).
 */
interface TaskFeedItem {
  kind: 'task'
  id: string
  state: string
  date: string
  /** Full-precision timestamp used only for ordering — `date` (day-only) can't tell same-day items apart. */
  sortAt: string
  description: string
  amount: string | null
  icon: TaskIconName
}

interface ActivityFeedItem {
  kind: 'activity'
  journalId: string
  date: string
  /** Full-precision timestamp used only for ordering — `date` (day-only) can't tell same-day items apart. */
  sortAt: string
  description: string
  amount: string
  icon: TaskIconName
  evidenceId: string | null
}

type FeedItem = TaskFeedItem | ActivityFeedItem

const pendingStates = new Set([
  'NeedsInformation',
  'NeedsReview',
  'Received',
  'Processing',
  'Approved',
  'Executing',
])
const resolvedTaskStates = new Set(['Rejected', 'Failed', 'Cancelled', 'Superseded'])

function toTaskFeedItem(task: Task): TaskFeedItem {
  return {
    kind: 'task',
    id: task.id,
    state: task.state,
    date: task.created_at.slice(0, 10),
    sortAt: task.created_at,
    description: task.summary?.description || 'Task',
    amount: task.summary?.amount ?? null,
    icon: iconForTask(task),
  }
}

const pendingItems = computed<TaskFeedItem[]>(() =>
  tasks.value
    .filter((task) => pendingStates.has(task.state))
    .map(toTaskFeedItem)
    .sort((a, b) => b.sortAt.localeCompare(a.sortAt)),
)

// activityEntries is already sorted newest-first by loadActivity();
// resolved (dead-end) Tasks are merged in and the combined list
// re-sorted by the real posted/created timestamp (not the day-only
// `date`) so same-day entries interleave in actual chronological
// order instead of whatever order the underlying queries returned.
const settledItems = computed<FeedItem[]>(() => {
  const resolvedTasks: FeedItem[] = tasks.value
    .filter((task) => resolvedTaskStates.has(task.state))
    .map(toTaskFeedItem)
  const activity: FeedItem[] = activityEntries.value.map((entry) => ({
    kind: 'activity',
    journalId: entry.journal_id,
    date: entry.financial_date,
    sortAt: entry.posted_at,
    description: entry.description || describeSource(entry.source).label,
    amount: entry.amount,
    icon: describeSource(entry.source).icon,
    evidenceId: entry.evidence_references[0] ?? null,
  }))

  return [...resolvedTasks, ...activity].sort((a, b) => b.sortAt.localeCompare(a.sortAt))
})

const visibleSettledItems = computed(() => settledItems.value.slice(0, visibleActivityCount.value))

async function refreshFeed() {
  await Promise.all([loadTasks(), loadActivity()])
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
  closeQuickActions()
  composerRef.value?.selectTypeByKey(typeKey)
  nextTick(() => {
    composerAnchorRef.value?.scrollIntoView({ behavior: 'smooth', block: 'start' })
    composerRef.value?.focusAmount()
  })
}

function quickUploadReceipt() {
  closeQuickActions()
  composerRef.value?.selectTypeByKey('expense')
  nextTick(() => {
    composerAnchorRef.value?.scrollIntoView({ behavior: 'smooth', block: 'start' })
    composerRef.value?.pickFile()
  })
}

function quickReviewTransactions() {
  closeQuickActions()
  nextTick(() => {
    document
      .getElementById('activity-heading')
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

onMounted(() => {
  loadTasks()
  loadActivity()
  window.addEventListener('keydown', onQuickActionsKeydown)
})

onUnmounted(() => {
  window.removeEventListener('keydown', onQuickActionsKeydown)
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

    <!-- A true floating action button (Founder feedback, 2026-09-21:
         sitting inline between the greeting and the composer made it
         "static" — anchored to one fixed spot in the document instead
         of actually floating). Fixed to the viewport corner, so it
         stays put and reachable regardless of scroll position, and no
         longer eats a row of vertical space on a page that's supposed
         to feel calm. -->
    <button
      v-if="quickActionsOpen"
      type="button"
      aria-hidden="true"
      tabindex="-1"
      class="fixed inset-0 z-40 cursor-default"
      @click="closeQuickActions"
    />

    <div class="fixed bottom-6 right-6 z-50 sm:bottom-8 sm:right-8">
      <Transition
        enter-active-class="transition duration-200 ease-[cubic-bezier(.34,1.56,.64,1)]"
        enter-from-class="opacity-0 scale-90 translate-y-2"
        enter-to-class="opacity-100 scale-100 translate-y-0"
        leave-active-class="transition duration-150 ease-in"
        leave-from-class="opacity-100 scale-100"
        leave-to-class="opacity-0 scale-95"
      >
        <div
          v-if="quickActionsOpen"
          role="menu"
          aria-label="Quick actions"
          class="absolute bottom-full right-0 mb-3 grid w-72 grid-cols-3 gap-1 rounded-2xl border border-border bg-surface p-2 shadow-xl shadow-black/10"
        >
          <template v-for="action in quickActions" :key="action.key">
            <NuxtLink
              v-if="action.to"
              :to="action.to"
              role="menuitem"
              class="flex flex-col items-center gap-1.5 rounded-xl px-2 py-3 text-center transition-colors hover:bg-surface-hover"
              @click="closeQuickActions"
            >
              <span
                class="flex h-9 w-9 items-center justify-center rounded-lg bg-surface-tertiary text-ink-secondary"
              >
                <AppIcon :name="action.icon" :size="16" />
              </span>
              <span class="text-[11px] leading-tight text-ink-secondary">{{ action.label }}</span>
            </NuxtLink>
            <button
              v-else
              type="button"
              role="menuitem"
              class="flex flex-col items-center gap-1.5 rounded-xl px-2 py-3 text-center transition-colors hover:bg-surface-hover"
              @click="action.run?.()"
            >
              <span
                class="flex h-9 w-9 items-center justify-center rounded-lg bg-surface-tertiary text-ink-secondary"
              >
                <AppIcon :name="action.icon" :size="16" />
              </span>
              <span class="text-[11px] leading-tight text-ink-secondary">{{ action.label }}</span>
            </button>
          </template>
        </div>
      </Transition>

      <button
        type="button"
        aria-label="Quick actions"
        :aria-expanded="quickActionsOpen"
        class="flex h-14 w-14 items-center justify-center rounded-full bg-accent text-accent-contrast shadow-lg shadow-black/20 transition-transform hover:scale-105 active:scale-95"
        @click="quickActionsOpen = !quickActionsOpen"
      >
        <AppIcon
          name="plus"
          :size="22"
          class="transition-transform duration-300"
          :class="quickActionsOpen && 'rotate-45'"
        />
      </button>
    </div>

    <div ref="composerAnchorRef">
      <AppComposer ref="composerRef" @created="refreshFeed" />
    </div>

    <section aria-labelledby="activity-heading">
      <div class="mb-4 flex items-center justify-between gap-3">
        <h2 id="activity-heading" class="text-xl font-semibold tracking-tight text-ink">
          Recent activity
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
            v-if="!loading && !activityLoading"
            type="button"
            aria-label="Refresh"
            class="flex h-7 w-7 items-center justify-center rounded-full text-ink-tertiary transition-colors hover:bg-surface-hover hover:text-ink"
            @click="refreshFeed"
          >
            <AppIcon name="refresh" :size="14" />
          </button>
        </div>
      </div>

      <div class="overflow-hidden rounded-[1.5rem] border border-border bg-surface">
        <div
          v-if="loading || activityLoading"
          class="px-5 py-12 text-center text-sm text-ink-tertiary"
        >
          Loading…
        </div>
        <div v-else-if="error" class="px-5 py-12 text-center text-sm text-danger">
          {{ error }}
        </div>
        <EmptyState
          v-else-if="pendingItems.length === 0 && settledItems.length === 0"
          :bordered="false"
          title="Nothing recorded yet"
          description="Use the composer above to record your first expense, income, or transfer."
          class="min-h-48"
        />
        <ul v-else class="divide-y divide-border">
          <li v-for="item in pendingItems" :key="`pending-${item.id}`">
            <NuxtLink
              :to="`/tasks/${item.id}`"
              class="flex items-center gap-3 px-4 py-3 transition-colors hover:bg-surface-hover sm:px-5"
            >
              <span
                class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-surface-tertiary text-ink-secondary"
              >
                <AppIcon :name="item.icon" :size="16" />
              </span>
              <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-medium text-ink">{{ item.description }}</p>
                <p class="truncate text-xs text-ink-tertiary">
                  <template v-if="item.amount">RM{{ item.amount }} · </template
                  >{{ formatRelativeDate(item.date) }}
                </p>
              </div>
              <AppBadge :tone="stateTone[item.state] ?? 'neutral'">{{ item.state }}</AppBadge>
              <AppIcon name="chevron-left" :size="15" class="rotate-180 text-ink-tertiary" />
            </NuxtLink>
          </li>
          <li
            v-for="item in visibleSettledItems"
            :key="item.kind === 'task' ? `task-${item.id}` : `activity-${item.journalId}`"
          >
            <!-- A resolved dead end (Rejected/Failed/Cancelled/Superseded) —
                 muted, never pinned like a pending Task, but still visible
                 and still clickable through to why (CTO call, 2026-09-21). -->
            <NuxtLink
              v-if="item.kind === 'task'"
              :to="`/tasks/${item.id}`"
              class="flex items-center gap-3 px-4 py-3 opacity-60 transition-opacity hover:bg-surface-hover hover:opacity-100 sm:px-5"
            >
              <span
                class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-surface-tertiary text-ink-secondary"
              >
                <AppIcon :name="item.icon" :size="16" />
              </span>
              <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-medium text-ink">{{ item.description }}</p>
                <p class="truncate text-xs text-ink-tertiary">
                  <template v-if="item.amount">RM{{ item.amount }} · </template
                  >{{ formatRelativeDate(item.date) }}
                </p>
              </div>
              <AppBadge :tone="stateTone[item.state] ?? 'neutral'">{{ item.state }}</AppBadge>
              <AppIcon name="chevron-left" :size="15" class="rotate-180 text-ink-tertiary" />
            </NuxtLink>
            <div v-else class="flex items-center gap-3 px-4 py-3 sm:px-5">
              <span
                class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-surface-tertiary text-ink-secondary"
              >
                <AppIcon :name="item.icon" :size="16" />
              </span>
              <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-medium text-ink">{{ item.description }}</p>
                <p class="truncate text-xs text-ink-tertiary">
                  {{ formatRelativeDate(item.date) }}
                </p>
              </div>
              <p class="shrink-0 text-sm font-medium text-ink">RM{{ item.amount }}</p>
              <a
                v-if="item.evidenceId"
                :href="evidenceUrl(item.evidenceId)"
                target="_blank"
                rel="noopener"
              >
                <AppBadge tone="success">
                  <AppIcon name="paperclip" :size="11" /> Evidence
                </AppBadge>
              </a>
            </div>
          </li>
        </ul>
      </div>

      <button
        v-if="visibleActivityCount < settledItems.length"
        type="button"
        class="mt-3 w-full text-center text-sm font-medium text-ink-tertiary hover:text-ink"
        @click="visibleActivityCount += ACTIVITY_STEP"
      >
        Show {{ Math.min(ACTIVITY_STEP, settledItems.length - visibleActivityCount) }} more
      </button>
    </section>
  </div>
</template>
