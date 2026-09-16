<script setup lang="ts">
/**
 * A visual summary layer over the existing Reporting endpoints
 * (AETS-009) — Trial Balance / Profit & Loss / Balance Sheet already
 * compute everything shown here; this page adds no new report, no
 * new Query, no new business logic. It calls the same
 * `/api/v1/reports/profit-and-loss` and `/api/v1/reports/balance-sheet`
 * endpoints the Reports page already uses, once per month for the
 * trend chart, purely as an HTTP-layer presentation choice.
 *
 * "Cashflow" here means the Income-vs-Expense trend a micro-SME
 * actually wants to see month to month — not AETS-009 §2.2's still-
 * deferred formal Cash Flow Statement (RPT-003), which requires an
 * operating/investing/financing Account classification this codebase
 * does not have. This page never claims to be that report.
 */
definePageMeta({ middleware: 'auth' })

interface MonthTotals {
  key: string
  label: string
  periodStart: string
  periodEnd: string
  revenue: number
  expense: number
  net: number
}

const { request } = useApi()

const loading = ref(true)
const error = ref<string | null>(null)

const months = ref<MonthTotals[]>([])
const totalAssets = ref<number | null>(null)

function monthRange(monthsAgo: number): { start: string; end: string; label: string; key: string } {
  const now = new Date()
  const year = now.getFullYear()
  const month = now.getMonth() - monthsAgo

  const first = new Date(year, month, 1)
  const lastOfMonth = new Date(year, month + 1, 0)
  const end = monthsAgo === 0 && lastOfMonth > now ? now : lastOfMonth

  return {
    start: first.toISOString().slice(0, 10),
    end: end.toISOString().slice(0, 10),
    label: first.toLocaleDateString('en-MY', { month: 'short' }),
    key: first.toISOString().slice(0, 7),
  }
}

async function loadDashboard() {
  loading.value = true
  error.value = null
  try {
    const ranges = [5, 4, 3, 2, 1, 0].map(monthRange)

    const [profitAndLossResults, balanceSheet] = await Promise.all([
      Promise.all(
        ranges.map((range) =>
          request<{ total_revenue: string; total_expense: string; net_income: string }>(
            '/api/v1/reports/profit-and-loss',
            { query: { period_start: range.start, period_end: range.end } },
          ),
        ),
      ),
      request<{ total_assets: string }>('/api/v1/reports/balance-sheet', {
        query: { as_of: new Date().toISOString().slice(0, 10) },
      }),
    ])

    months.value = ranges.map((range, i) => ({
      key: range.key,
      label: range.label,
      periodStart: range.start,
      periodEnd: range.end,
      revenue: Number(profitAndLossResults[i]!.total_revenue),
      expense: Number(profitAndLossResults[i]!.total_expense),
      net: Number(profitAndLossResults[i]!.net_income),
    }))
    totalAssets.value = Number(balanceSheet.total_assets)
  } catch {
    error.value = 'Could not load the dashboard right now.'
  } finally {
    loading.value = false
  }
}

const currentMonth = computed(() => months.value[months.value.length - 1] ?? null)

const chartMax = computed(() => {
  const values = months.value.flatMap((m) => [m.revenue, m.expense])
  const max = Math.max(1, ...values)
  return max
})

const BAR_AREA_HEIGHT = 140

function barHeight(value: number): number {
  if (chartMax.value === 0) return 0
  return Math.max(value > 0 ? 4 : 0, Math.round((value / chartMax.value) * BAR_AREA_HEIGHT))
}

function formatMyr(value: number): string {
  return value.toLocaleString('en-MY', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

onMounted(loadDashboard)
</script>

<template>
  <div>
    <PageHeader title="Dashboard" />

    <p v-if="loading" class="text-sm text-ink-tertiary">Loading…</p>
    <p v-else-if="error" class="text-sm text-danger">{{ error }}</p>

    <template v-else>
      <div class="mb-6 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <AppCard>
          <p class="text-xs font-medium text-ink-tertiary">Income this month</p>
          <p class="mt-1 text-xl font-semibold text-success">
            RM{{ formatMyr(currentMonth?.revenue ?? 0) }}
          </p>
        </AppCard>
        <AppCard>
          <p class="text-xs font-medium text-ink-tertiary">Expenses this month</p>
          <p class="mt-1 text-xl font-semibold text-danger">
            RM{{ formatMyr(currentMonth?.expense ?? 0) }}
          </p>
        </AppCard>
        <AppCard>
          <p class="text-xs font-medium text-ink-tertiary">Net this month</p>
          <p
            class="mt-1 text-xl font-semibold"
            :class="(currentMonth?.net ?? 0) >= 0 ? 'text-success' : 'text-danger'"
          >
            RM{{ formatMyr(currentMonth?.net ?? 0) }}
          </p>
        </AppCard>
        <AppCard>
          <p class="text-xs font-medium text-ink-tertiary">Total assets today</p>
          <p class="mt-1 text-xl font-semibold text-ink">RM{{ formatMyr(totalAssets ?? 0) }}</p>
        </AppCard>
      </div>

      <AppCard>
        <div class="mb-4 flex items-center justify-between">
          <h2 class="text-sm font-medium text-ink-secondary">
            Income vs. expenses — last 6 months
          </h2>
          <div class="flex items-center gap-3 text-xs text-ink-tertiary">
            <span class="flex items-center gap-1.5">
              <span class="h-2.5 w-2.5 rounded-full bg-success" /> Income
            </span>
            <span class="flex items-center gap-1.5">
              <span class="h-2.5 w-2.5 rounded-full bg-danger" /> Expenses
            </span>
          </div>
        </div>

        <EmptyState
          v-if="months.every((m) => m.revenue === 0 && m.expense === 0)"
          title="Nothing recorded in the last 6 months"
          description="Record an Expense or Income to see your trend here."
        />
        <div v-else class="flex items-end gap-6 overflow-x-auto pb-1">
          <div
            v-for="month in months"
            :key="month.key"
            class="flex min-w-[56px] flex-col items-center gap-2"
          >
            <div class="flex items-end gap-1" :style="{ height: `${BAR_AREA_HEIGHT}px` }">
              <div
                class="w-3.5 rounded-t bg-success transition-all"
                :style="{ height: `${barHeight(month.revenue)}px` }"
                :title="`Income — ${month.label}: RM${formatMyr(month.revenue)}`"
              />
              <div
                class="w-3.5 rounded-t bg-danger transition-all"
                :style="{ height: `${barHeight(month.expense)}px` }"
                :title="`Expenses — ${month.label}: RM${formatMyr(month.expense)}`"
              />
            </div>
            <span class="text-xs text-ink-tertiary">{{ month.label }}</span>
          </div>
        </div>
      </AppCard>
    </template>
  </div>
</template>
