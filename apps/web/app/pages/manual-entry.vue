<script setup lang="ts">
/**
 * Explicit Manual Entry fallback. The default landing experience is
 * the review-first Work Queue; this page preserves the established
 * deterministic direct-entry workflow for users who intentionally
 * choose it.
 */
definePageMeta({ middleware: 'auth' })

interface EvidenceEntry {
  journal_id: string
  financial_date: string
  source: string
  has_evidence: boolean
  evidence_references: string[]
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
}

function describeSource(source: string): {
  label: string
  icon: 'receipt' | 'wallet' | 'bank' | 'building' | 'chart'
} {
  const prefix = source.split(':')[0] ?? ''
  return SOURCE_LABELS[prefix] ?? { label: prefix || 'Record', icon: 'chart' }
}

function formatRelativeDate(dateString: string): string {
  const date = new Date(`${dateString}T00:00:00`)
  const today = new Date()
  today.setHours(0, 0, 0, 0)
  const diffDays = Math.round((today.getTime() - date.getTime()) / 86_400_000)
  if (diffDays === 0) return 'Today'
  if (diffDays === 1) return 'Yesterday'
  if (diffDays > 1 && diffDays < 7) return `${diffDays} days ago`
  return date.toLocaleDateString('en-MY', { day: 'numeric', month: 'short', year: 'numeric' })
}

const { request } = useApi()
const { user } = useAuth()

const entries = ref<EvidenceEntry[]>([])
const loading = ref(true)

async function loadActivity() {
  loading.value = true
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

    <AppComposer @created="loadActivity" />

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
        <li v-for="entry in entries" :key="entry.journal_id">
          <AppCard :padded="false" hoverable>
            <div class="flex items-center gap-3 px-4 py-3">
              <span
                class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-surface-tertiary text-ink-secondary"
              >
                <AppIcon :name="describeSource(entry.source).icon" :size="16" />
              </span>
              <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-medium text-ink">
                  {{ describeSource(entry.source).label }}
                </p>
                <p class="text-xs text-ink-tertiary">
                  {{ formatRelativeDate(entry.financial_date) }}
                </p>
              </div>
              <AppBadge v-if="entry.has_evidence" tone="success">
                <AppIcon name="paperclip" :size="11" /> Evidence
              </AppBadge>
              <AppBadge v-else tone="neutral">No evidence</AppBadge>
            </div>
          </AppCard>
        </li>
      </ul>
    </div>
  </div>
</template>
