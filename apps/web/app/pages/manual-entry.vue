<script setup lang="ts">
/**
 * Explicit Manual Entry fallback. The default landing experience is
 * the review-first Work Queue; this page opens the same AppComposer
 * with mode="direct" preselected for users who intentionally choose
 * the deterministic direct-posting workflow — the composer's own
 * toggle lets them switch back to review-first without leaving the
 * page (UX-01, 2026-09-21 UI/UX audit).
 */
definePageMeta({ middleware: 'auth' })
useHead({ title: 'Manual Entry' })

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

const entries = ref<EvidenceEntry[]>([])
const loading = ref(true)

// Caps the calm, at-a-glance list at a fixed height regardless of how
// much a tenant has recorded — "Show more" reveals the rest a page at
// a time rather than dumping every entry from the last 60 days at once.
const VISIBLE_STEP = 8
const visibleCount = ref(VISIBLE_STEP)
const visibleEntries = computed(() => entries.value.slice(0, visibleCount.value))

async function loadActivity() {
  loading.value = true
  visibleCount.value = VISIBLE_STEP
  try {
    const periodEnd = new Date().toISOString().slice(0, 10)
    const periodStart = new Date(Date.now() - 60 * 86_400_000).toISOString().slice(0, 10)
    const data = await request<{ entries: EvidenceEntry[] }>('/api/v1/reports/evidence-index', {
      query: { period_start: periodStart, period_end: periodEnd },
    })
    entries.value = [...data.entries].sort((a, b) =>
      b.financial_date.localeCompare(a.financial_date),
    )
  } finally {
    loading.value = false
  }
}

const greeting = computed(() => {
  const hour = new Date().getHours()
  if (hour < 12) return 'Good morning'
  if (hour < 18) return 'Good afternoon'
  return 'Good evening'
})

onMounted(loadActivity)
</script>

<template>
  <div class="mx-auto max-w-3xl space-y-10">
    <div class="pt-8 text-center sm:pt-12">
      <h1 class="text-2xl font-semibold tracking-tight text-ink">
        {{ greeting }}<template v-if="user">, {{ user.name.split(' ')[0] }}</template>
      </h1>
      <p class="mt-1 text-sm text-ink-tertiary">What happened in your business today?</p>
    </div>

    <AppComposer mode="direct" @created="loadActivity" />

    <div>
      <h2 class="mb-3 text-sm font-medium text-ink-secondary">Recent activity</h2>

      <p v-if="loading" class="text-sm text-ink-tertiary">Loading…</p>
      <EmptyState
        v-else-if="entries.length === 0"
        :bordered="false"
        title="Nothing recorded in the last 60 days"
        description="Use the composer above to record your first expense, income, or transfer."
      />
      <ul v-else class="space-y-1.5">
        <li v-for="entry in visibleEntries" :key="entry.journal_id">
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
        v-if="visibleCount < entries.length"
        type="button"
        class="mt-3 w-full text-center text-sm font-medium text-ink-tertiary hover:text-ink"
        @click="visibleCount += VISIBLE_STEP"
      >
        Show {{ Math.min(VISIBLE_STEP, entries.length - visibleCount) }} more
      </button>
    </div>
  </div>
</template>
