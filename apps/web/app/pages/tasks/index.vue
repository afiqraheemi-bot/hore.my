<script setup lang="ts">
/**
 * Work Queue (ADR-0009, WTS-001) — the Task-based flow's own entry
 * point and the authenticated default landing experience. Manual
 * Entry remains available at `/manual-entry` as a controlled fallback;
 * changing the landing route does not remove its direct-posting path.
 *
 * The composer below intentionally mirrors `AppComposer.vue`'s own
 * five-type shape exactly (same fields, same accounting-side-neutral
 * primary/secondary naming) — the only difference is *where* it
 * submits: `POST /api/v1/tasks` (lands in `NeedsReview`, awaiting
 * Human Confirmation) instead of directly to `/api/v1/expenses` etc.
 * There is still no AI here — a human describing what to record *is*
 * the "Processing" step at this phase (WTS-001 §3), synchronously.
 */
definePageMeta({ middleware: 'auth' })

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
const submitting = ref(false)
const submitError = ref<string | null>(null)

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

async function onSubmit() {
  submitError.value = null
  submitting.value = true
  try {
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
      },
    })
    amount.value = ''
    description.value = ''
    primaryAccountId.value = ''
    secondaryAccountId.value = ''
    expanded.value = false
    await loadTasks()
  } catch {
    submitError.value =
      'Could not submit this Task — check the amount format (e.g. 50.00) and account selection.'
  } finally {
    submitting.value = false
  }
}

onMounted(async () => {
  await Promise.all([loadTasks(), loadAccounts()])
})
</script>

<template>
  <div>
    <PageHeader
      title="Work Queue"
      description="Submit a Task, review its Proposal, and confirm before anything posts."
    />

    <AppCard :padded="false" class="mb-6 overflow-hidden">
      <div
        class="flex items-center gap-2 overflow-x-auto border-b border-border bg-surface-secondary/60 px-3 py-2"
      >
        <button
          v-for="type in types"
          :key="type.key"
          type="button"
          class="flex items-center gap-1.5 whitespace-nowrap rounded-full px-3 py-1.5 text-xs font-medium transition-colors"
          :class="
            activeType.key === type.key
              ? 'bg-accent text-accent-contrast'
              : 'bg-surface text-ink-secondary hover:bg-surface-hover hover:text-ink'
          "
          @click="
            () => {
              selectType(type)
              expanded = true
            }
          "
        >
          <AppIcon :name="type.icon" :size="14" />
          {{ type.label }}
        </button>
      </div>

      <form class="space-y-3 p-4" @submit.prevent="onSubmit" @focusin="expanded = true">
        <div class="flex items-center gap-3">
          <span class="text-lg font-medium text-ink-tertiary">RM</span>
          <input
            v-model="amount"
            placeholder="0.00"
            required
            inputmode="decimal"
            class="w-full border-0 bg-transparent p-0 text-2xl font-semibold text-ink placeholder:text-ink-tertiary focus:outline-none focus:ring-0"
          />
          <input
            v-model="transactionDate"
            type="date"
            required
            class="h-9 shrink-0 rounded-lg border border-border bg-surface px-2 text-sm text-ink-secondary"
          />
        </div>

        <div v-if="expanded" class="grid grid-cols-1 gap-3 sm:grid-cols-2">
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
        </div>

        <p v-if="submitError" class="text-sm text-danger">{{ submitError }}</p>

        <div class="flex items-center justify-between">
          <p class="text-xs text-ink-tertiary">
            Submits a Task for review — nothing posts until you confirm it below.
          </p>
          <AppButton type="submit" variant="primary" :disabled="submitting">
            <AppIcon name="send" :size="15" />
            {{ submitting ? 'Submitting…' : 'Submit for review' }}
          </AppButton>
        </div>
      </form>
    </AppCard>

    <p v-if="loading" class="text-sm text-ink-tertiary">Loading…</p>
    <p v-else-if="error" class="text-sm text-danger">{{ error }}</p>
    <EmptyState
      v-else-if="tasks.length === 0"
      title="No Tasks yet"
      description="Submit one above — it lands here awaiting your review."
    />
    <div v-else class="space-y-2">
      <NuxtLink v-for="task in tasks" :key="task.id" :to="`/tasks/${task.id}`">
        <AppCard hoverable>
          <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="min-w-0">
              <p class="font-mono text-xs text-ink-tertiary">{{ task.id.slice(0, 8) }}</p>
              <p class="text-sm text-ink-tertiary">
                Submitted {{ new Date(task.created_at).toLocaleString() }}
              </p>
            </div>
            <AppBadge :tone="stateTone[task.state] ?? 'neutral'">{{ task.state }}</AppBadge>
          </div>
        </AppCard>
      </NuxtLink>
    </div>
  </div>
</template>
