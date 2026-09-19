<script setup lang="ts">
/**
 * A read-only presentation of `/api/v1/dashboard`. The API composes
 * authoritative Reporting and Workspace queries inside one database
 * snapshot; this page never derives accounting totals independently.
 * Monetary values remain canonical decimal strings. BigInt minor units
 * are used only to scale chart pixels without binary floating point.
 */
definePageMeta({ middleware: 'auth' })

interface NetBalance {
  amount: string
  direction: 'Debit' | 'Credit' | null
}

interface CashFlowTrend {
  operating: NetBalance
  investing: NetBalance
  financing: NetBalance
  net_change: NetBalance
}

interface TrendPeriod {
  period_start: string
  period_end: string
  total_revenue: string
  total_expense: string
  net_income: string
  is_profit: boolean
  cash_flow: CashFlowTrend
}

interface DashboardSummary {
  generated_at: string
  as_of: string
  currency: 'MYR'
  current_period: TrendPeriod
  financial_position: { total_assets: string }
  attention: {
    task_count: number
    overdue_invoice_count: number
    overdue_invoice_total: string
  }
  trend: TrendPeriod[]
}

const { request } = useApi()

const loading = ref(true)
const error = ref<string | null>(null)
const dashboard = ref<DashboardSummary | null>(null)

async function loadDashboard() {
  loading.value = true
  error.value = null
  try {
    dashboard.value = await request<DashboardSummary>('/api/v1/dashboard')
  } catch {
    error.value = 'We could not load your financial overview.'
  } finally {
    loading.value = false
  }
}

const attentionCount = computed(() => {
  if (!dashboard.value) return 0
  return dashboard.value.attention.task_count + dashboard.value.attention.overdue_invoice_count
})

const chartMaximum = computed(() => {
  const values = (dashboard.value?.trend ?? []).flatMap((period) => [
    toMinorUnits(period.total_revenue),
    toMinorUnits(period.total_expense),
  ])
  return values.reduce((maximum, value) => (value > maximum ? value : maximum), 1n)
})

const hasTrendData = computed(() =>
  (dashboard.value?.trend ?? []).some(
    (period) => toMinorUnits(period.total_revenue) > 0n || toMinorUnits(period.total_expense) > 0n,
  ),
)

const cashFlowChartMaximum = computed(() => {
  const values = (dashboard.value?.trend ?? []).map((period) =>
    toMinorUnits(period.cash_flow.net_change.amount),
  )
  return values.reduce((maximum, value) => (value > maximum ? value : maximum), 1n)
})

const hasCashFlowData = computed(() =>
  (dashboard.value?.trend ?? []).some(
    (period) => toMinorUnits(period.cash_flow.net_change.amount) > 0n,
  ),
)

const BAR_AREA_HEIGHT = 136n
const CASH_FLOW_HALF_HEIGHT = 68n

function isInflow(balance: NetBalance): boolean {
  return balance.direction === 'Debit'
}

function isOutflow(balance: NetBalance): boolean {
  return balance.direction === 'Credit'
}

function cashBarHeight(balance: NetBalance): number {
  const minorUnits = toMinorUnits(balance.amount)
  if (minorUnits === 0n) return 0
  return Math.max(4, Number((minorUnits * CASH_FLOW_HALF_HEIGHT) / cashFlowChartMaximum.value))
}

function signedMyr(balance: NetBalance): string {
  const formatted = formatMyr(balance.amount)
  return isOutflow(balance) ? `−${formatted}` : formatted
}

function toMinorUnits(value: string): bigint {
  const match = /^(\d+)\.(\d{2})$/.exec(value)
  if (!match) return 0n
  return BigInt(`${match[1]}${match[2]}`)
}

function barHeight(value: string): number {
  const minorUnits = toMinorUnits(value)
  if (minorUnits === 0n) return 0
  return Math.max(4, Number((minorUnits * BAR_AREA_HEIGHT) / chartMaximum.value))
}

function formatMyr(value: string): string {
  const match = /^(\d+)\.(\d{2})$/.exec(value)
  if (!match) return `RM${value}`
  return `RM${match[1]!.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}.${match[2]}`
}

function formatDate(value: string): string {
  return new Intl.DateTimeFormat('en-MY', {
    day: 'numeric',
    month: 'long',
    year: 'numeric',
  }).format(new Date(`${value}T00:00:00`))
}

function monthLabel(value: string): string {
  return new Intl.DateTimeFormat('en-US', { month: 'short' }).format(new Date(`${value}T00:00:00`))
}

function formatGeneratedAt(value: string): string {
  return new Intl.DateTimeFormat('en-MY', {
    hour: 'numeric',
    minute: '2-digit',
    timeZone: 'Asia/Kuala_Lumpur',
  }).format(new Date(value))
}

function formatMinorUnits(value: bigint): string {
  const whole = value / 100n
  const fraction = String(value % 100n).padStart(2, '0')
  return formatMyr(`${whole}.${fraction}`)
}

onMounted(loadDashboard)
</script>

<template>
  <div class="mx-auto max-w-4xl space-y-6 sm:space-y-8">
    <header class="pt-2 text-center sm:pt-4">
      <h1 class="text-2xl font-semibold tracking-tight text-ink sm:text-3xl">
        Your financial overview
      </h1>
      <p v-if="dashboard" class="mt-2 text-sm text-ink-tertiary">
        As of {{ formatDate(dashboard.as_of) }} · Updated
        {{ formatGeneratedAt(dashboard.generated_at) }}
      </p>
      <p v-else class="mt-2 text-sm text-ink-tertiary">
        A clear view of your business, grounded in posted records.
      </p>
    </header>

    <template v-if="loading">
      <div class="animate-pulse space-y-4" aria-label="Loading financial overview">
        <div class="h-28 rounded-3xl bg-surface-tertiary" />
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
          <div v-for="item in 4" :key="item" class="h-28 rounded-2xl bg-surface-tertiary" />
        </div>
        <div class="h-64 rounded-3xl bg-surface-tertiary" />
      </div>
    </template>

    <AppCard v-else-if="error" class="py-10 text-center">
      <h2 class="text-base font-semibold text-ink">Dashboard unavailable</h2>
      <p class="mt-1 text-sm text-ink-tertiary">{{ error }}</p>
      <AppButton class="mt-4" variant="primary" @click="loadDashboard">Try again</AppButton>
    </AppCard>

    <template v-else-if="dashboard">
      <section aria-labelledby="month-performance-heading">
        <div class="mb-3 flex items-end justify-between gap-4">
          <div>
            <h2 id="month-performance-heading" class="text-base font-semibold text-ink">
              This month
            </h2>
            <p class="mt-0.5 text-xs text-ink-tertiary">
              {{ formatDate(dashboard.current_period.period_start) }} –
              {{ formatDate(dashboard.current_period.period_end) }}
            </p>
          </div>
          <NuxtLink to="/reports" class="text-sm font-medium text-ink-secondary hover:text-ink">
            View details
          </NuxtLink>
        </div>

        <AppCard
          :padded="false"
          class="overflow-hidden rounded-[1.5rem] border-border-strong shadow-[0_12px_35px_rgb(var(--shadow-color)/0.06)]"
        >
          <div class="grid grid-cols-2 gap-px bg-border lg:grid-cols-4">
            <div class="bg-surface p-4 sm:p-5">
              <p class="text-xs font-medium text-ink-tertiary">
                {{ dashboard.current_period.is_profit ? 'Net profit' : 'Net loss' }}
              </p>
              <p
                class="mt-1.5 text-xl font-semibold tabular-nums sm:text-2xl"
                :class="dashboard.current_period.is_profit ? 'text-success' : 'text-danger'"
              >
                {{ formatMyr(dashboard.current_period.net_income) }}
              </p>
            </div>
            <div class="bg-surface p-4 sm:p-5">
              <p class="text-xs font-medium text-ink-tertiary">Income</p>
              <p class="mt-1.5 text-xl font-semibold tabular-nums text-ink sm:text-2xl">
                {{ formatMyr(dashboard.current_period.total_revenue) }}
              </p>
            </div>
            <div class="bg-surface p-4 sm:p-5">
              <p class="text-xs font-medium text-ink-tertiary">Expenses</p>
              <p class="mt-1.5 text-xl font-semibold tabular-nums text-ink sm:text-2xl">
                {{ formatMyr(dashboard.current_period.total_expense) }}
              </p>
            </div>
            <div class="bg-surface p-4 sm:p-5">
              <p class="text-xs font-medium text-ink-tertiary">Total assets</p>
              <p class="mt-1.5 text-xl font-semibold tabular-nums text-ink sm:text-2xl">
                {{ formatMyr(dashboard.financial_position.total_assets) }}
              </p>
              <p class="mt-1 text-[11px] text-ink-tertiary">Financial position today</p>
            </div>
          </div>
        </AppCard>
      </section>

      <section aria-labelledby="attention-heading">
        <AppCard :padded="false" class="overflow-hidden rounded-[1.5rem] border-border-strong">
          <div class="flex items-center justify-between gap-4 px-5 py-4">
            <h2 id="attention-heading" class="text-sm font-semibold text-ink">
              Needs your attention
            </h2>
            <span
              v-if="attentionCount"
              class="rounded-full bg-warning-soft px-2.5 py-1 text-xs font-medium tabular-nums text-warning"
            >
              {{ attentionCount }}
            </span>
            <span v-else class="flex items-center gap-1.5 text-xs font-medium text-success">
              <AppIcon name="check" :size="14" /> All caught up
            </span>
          </div>

          <div v-if="attentionCount" class="divide-y divide-border border-t border-border">
            <NuxtLink
              v-if="dashboard.attention.task_count"
              to="/?filter=attention"
              class="flex items-center justify-between gap-4 px-5 py-3.5 transition-colors hover:bg-surface-hover"
            >
              <span class="text-sm text-ink">Tasks to review</span>
              <span class="flex items-center gap-2 text-sm tabular-nums text-ink-secondary">
                {{ dashboard.attention.task_count }}
                <AppIcon name="chevron-left" :size="14" class="rotate-180" />
              </span>
            </NuxtLink>
            <NuxtLink
              v-if="dashboard.attention.overdue_invoice_count"
              :to="{ path: '/reports', query: { tab: 'Aging Report' } }"
              class="flex items-center justify-between gap-4 px-5 py-3.5 transition-colors hover:bg-surface-hover"
            >
              <span class="text-sm text-ink">Overdue invoices</span>
              <span class="flex items-center gap-2 text-sm tabular-nums text-ink-secondary">
                {{ formatMyr(dashboard.attention.overdue_invoice_total) }}
                <AppIcon name="chevron-left" :size="14" class="rotate-180" />
              </span>
            </NuxtLink>
          </div>
        </AppCard>
      </section>

      <section aria-labelledby="trend-heading">
        <AppCard
          class="rounded-[1.5rem] border-border-strong p-5 shadow-[0_12px_35px_rgb(var(--shadow-color)/0.06)] sm:p-6"
        >
          <div class="mb-5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
              <h2 id="trend-heading" class="text-base font-semibold text-ink">
                Income and expenses
              </h2>
              <p class="mt-0.5 text-xs text-ink-tertiary">Last six calendar months</p>
            </div>
            <div class="flex items-center gap-4 text-xs text-ink-tertiary" aria-hidden="true">
              <span class="flex items-center gap-1.5">
                <span class="h-2.5 w-2.5 rounded-full bg-success" /> Income
              </span>
              <span class="flex items-center gap-1.5">
                <span class="h-2.5 w-2.5 rounded-sm bg-ink-tertiary" /> Expenses
              </span>
            </div>
          </div>

          <EmptyState
            v-if="!hasTrendData"
            :bordered="false"
            title="Nothing recorded in the last 6 months"
            description="Record an Expense or Income to see your trend here."
          />
          <template v-else>
            <div class="flex gap-2" aria-labelledby="trend-heading">
              <div
                class="flex h-[136px] w-14 shrink-0 flex-col justify-between pb-0.5 text-right text-[10px] tabular-nums text-ink-tertiary"
                aria-hidden="true"
              >
                <span>{{ formatMinorUnits(chartMaximum) }}</span>
                <span>RM0</span>
              </div>
              <div class="grid min-w-0 flex-1 grid-cols-6 gap-1.5 sm:gap-3">
                <div
                  v-for="period in dashboard.trend"
                  :key="period.period_start"
                  class="flex min-w-0 flex-col items-center gap-2"
                >
                  <div
                    class="flex h-[136px] w-full items-end justify-center gap-1 border-b border-border"
                  >
                    <div
                      class="w-2.5 rounded-t bg-success transition-all sm:w-3.5"
                      :style="{ height: `${barHeight(period.total_revenue)}px` }"
                      :aria-label="`${monthLabel(period.period_start)} income ${formatMyr(period.total_revenue)}`"
                      role="img"
                      tabindex="0"
                    />
                    <div
                      class="w-2.5 rounded-t-sm bg-ink-tertiary transition-all sm:w-3.5"
                      :style="{ height: `${barHeight(period.total_expense)}px` }"
                      :aria-label="`${monthLabel(period.period_start)} expenses ${formatMyr(period.total_expense)}`"
                      role="img"
                      tabindex="0"
                    />
                  </div>
                  <span class="truncate text-[11px] text-ink-tertiary sm:text-xs">
                    {{ monthLabel(period.period_start) }}
                  </span>
                </div>
              </div>
            </div>

            <details class="mt-5 border-t border-border pt-4">
              <summary class="cursor-pointer text-sm font-medium text-ink-secondary">
                View exact monthly figures
              </summary>
              <div class="mt-3 overflow-x-auto">
                <table class="w-full min-w-[520px] text-left text-sm">
                  <thead class="text-xs uppercase tracking-wide text-ink-tertiary">
                    <tr>
                      <th class="py-2 font-medium">Month</th>
                      <th class="py-2 text-right font-medium">Income</th>
                      <th class="py-2 text-right font-medium">Expenses</th>
                      <th class="py-2 text-right font-medium">Profit / loss</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-border">
                    <tr v-for="period in dashboard.trend" :key="`table-${period.period_start}`">
                      <td class="py-2.5 text-ink-secondary">
                        {{ monthLabel(period.period_start) }}
                      </td>
                      <td class="py-2.5 text-right tabular-nums text-ink">
                        {{ formatMyr(period.total_revenue) }}
                      </td>
                      <td class="py-2.5 text-right tabular-nums text-ink">
                        {{ formatMyr(period.total_expense) }}
                      </td>
                      <td class="py-2.5 text-right font-medium tabular-nums text-ink">
                        {{ period.is_profit ? '' : '−' }}{{ formatMyr(period.net_income) }}
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </details>
          </template>
        </AppCard>
      </section>

      <section aria-labelledby="cash-flow-heading">
        <AppCard
          class="rounded-[1.5rem] border-border-strong p-5 shadow-[0_12px_35px_rgb(var(--shadow-color)/0.06)] sm:p-6"
        >
          <div class="mb-5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
              <h2 id="cash-flow-heading" class="text-base font-semibold text-ink">Cash flow</h2>
              <p class="mt-0.5 text-xs text-ink-tertiary">
                Net change in cash, last six calendar months
              </p>
            </div>
            <NuxtLink
              to="/reports"
              class="flex items-center gap-4 text-xs text-ink-tertiary hover:text-ink"
              aria-hidden="true"
            >
              <span class="flex items-center gap-1.5">
                <span class="h-2.5 w-2.5 rounded-full bg-success" /> Cash in
              </span>
              <span class="flex items-center gap-1.5">
                <span class="h-2.5 w-2.5 rounded-sm bg-danger" /> Cash out
              </span>
            </NuxtLink>
          </div>

          <EmptyState
            v-if="!hasCashFlowData"
            :bordered="false"
            title="No cash movement recorded yet"
            description="Cash flow tracks Accounts linked to a Bank Account. Link one under Bank Accounts to see this chart."
          />
          <template v-else>
            <div class="flex gap-2" aria-labelledby="cash-flow-heading">
              <div
                class="flex h-[136px] w-14 shrink-0 flex-col justify-between pb-0.5 text-right text-[10px] tabular-nums text-ink-tertiary"
                aria-hidden="true"
              >
                <span>{{ formatMinorUnits(cashFlowChartMaximum) }}</span>
                <span>RM0</span>
                <span>−{{ formatMinorUnits(cashFlowChartMaximum) }}</span>
              </div>
              <div class="grid min-w-0 flex-1 grid-cols-6 gap-1.5 sm:gap-3">
                <div
                  v-for="period in dashboard.trend"
                  :key="period.period_start"
                  class="flex min-w-0 flex-col items-center gap-2"
                >
                  <div class="flex h-[136px] w-full flex-col justify-center">
                    <div
                      class="flex h-[68px] w-full items-end justify-center border-b border-border"
                    >
                      <div
                        v-if="isInflow(period.cash_flow.net_change)"
                        class="w-4 rounded-t bg-success transition-all sm:w-5"
                        :style="{ height: `${cashBarHeight(period.cash_flow.net_change)}px` }"
                        :aria-label="`${monthLabel(period.period_start)} net cash in ${formatMyr(period.cash_flow.net_change.amount)}`"
                        role="img"
                        tabindex="0"
                      />
                    </div>
                    <div class="flex h-[68px] w-full items-start justify-center">
                      <div
                        v-if="isOutflow(period.cash_flow.net_change)"
                        class="w-4 rounded-b bg-danger transition-all sm:w-5"
                        :style="{ height: `${cashBarHeight(period.cash_flow.net_change)}px` }"
                        :aria-label="`${monthLabel(period.period_start)} net cash out ${formatMyr(period.cash_flow.net_change.amount)}`"
                        role="img"
                        tabindex="0"
                      />
                    </div>
                  </div>
                  <span class="truncate text-[11px] text-ink-tertiary sm:text-xs">
                    {{ monthLabel(period.period_start) }}
                  </span>
                </div>
              </div>
            </div>

            <details class="mt-5 border-t border-border pt-4">
              <summary class="cursor-pointer text-sm font-medium text-ink-secondary">
                View exact monthly figures
              </summary>
              <div class="mt-3 overflow-x-auto">
                <table class="w-full min-w-[560px] text-left text-sm">
                  <thead class="text-xs uppercase tracking-wide text-ink-tertiary">
                    <tr>
                      <th class="py-2 font-medium">Month</th>
                      <th class="py-2 text-right font-medium">Operating</th>
                      <th class="py-2 text-right font-medium">Investing</th>
                      <th class="py-2 text-right font-medium">Financing</th>
                      <th class="py-2 text-right font-medium">Net change</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-border">
                    <tr
                      v-for="period in dashboard.trend"
                      :key="`cash-table-${period.period_start}`"
                    >
                      <td class="py-2.5 text-ink-secondary">
                        {{ monthLabel(period.period_start) }}
                      </td>
                      <td class="py-2.5 text-right tabular-nums text-ink">
                        {{ signedMyr(period.cash_flow.operating) }}
                      </td>
                      <td class="py-2.5 text-right tabular-nums text-ink">
                        {{ signedMyr(period.cash_flow.investing) }}
                      </td>
                      <td class="py-2.5 text-right tabular-nums text-ink">
                        {{ signedMyr(period.cash_flow.financing) }}
                      </td>
                      <td
                        class="py-2.5 text-right font-medium tabular-nums"
                        :class="isOutflow(period.cash_flow.net_change) ? 'text-danger' : 'text-ink'"
                      >
                        {{ signedMyr(period.cash_flow.net_change) }}
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </details>
          </template>
        </AppCard>
      </section>
    </template>
  </div>
</template>
