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

const primaryAccountOptions = computed(() =>
  accounts.value
    .filter((account) => activeType.value.primaryAccountTypes.includes(account.account_type))
    .map((account) => ({
      value: account.id,
      label: `${account.account_code} — ${account.account_name}`,
    })),
)

const secondaryAccountOptions = computed(() =>
  accounts.value
    .filter((account) => activeType.value.secondaryAccountTypes.includes(account.account_type))
    .map((account) => ({
      value: account.id,
      label: `${account.account_code} — ${account.account_name}`,
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
  <div class="mx-auto max-w-2xl space-y-8">
    <div class="pt-4 text-center">
      <h1 class="text-2xl font-semibold tracking-tight text-ink">
        {{ greeting }}<template v-if="user">, {{ user.name.split(' ')[0] }}</template>
      </h1>
      <p class="mt-1 text-sm text-ink-tertiary">What would you like to get done today?</p>
    </div>

    <AppCard :padded="false" class="overflow-hidden">
      <div
        class="flex items-center gap-2 overflow-x-auto border-b border-border bg-surface-secondary/60 px-3 py-2.5"
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

      <form class="space-y-4 p-4 sm:p-5" @submit.prevent="onSubmit" @focusin="expanded = true">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
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
          <input
            v-model="transactionDate"
            aria-label="Transaction date"
            type="date"
            required
            class="h-10 w-full rounded-lg border border-border bg-surface px-3 text-sm text-ink-secondary sm:w-auto"
          />
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

    <div>
      <div class="mb-3 flex items-center justify-between gap-3">
        <h2 class="text-sm font-medium text-ink-secondary">Work Queue</h2>
        <button
          v-if="!loading && tasks.length > 0"
          type="button"
          class="text-xs font-medium text-ink-tertiary hover:text-ink"
          @click="loadTasks"
        >
          Refresh
        </button>
      </div>

      <p v-if="loading" class="text-sm text-ink-tertiary">Loading…</p>
      <p v-else-if="error" class="text-sm text-danger">{{ error }}</p>
      <EmptyState
        v-else-if="tasks.length === 0"
        title="No Tasks yet"
        description="Submit one above. It will wait here for your review."
      />
      <ul v-else class="space-y-1.5">
        <li v-for="task in tasks" :key="task.id">
          <NuxtLink :to="`/tasks/${task.id}`" class="block rounded-2xl">
            <AppCard :padded="false" hoverable>
              <div class="flex items-center gap-3 px-4 py-3">
                <span
                  class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-surface-tertiary text-ink-secondary"
                >
                  <AppIcon name="tasks" :size="16" />
                </span>
                <div class="min-w-0 flex-1">
                  <p class="truncate text-sm font-medium text-ink">
                    Task {{ task.id.slice(0, 8) }}
                  </p>
                  <p class="text-xs text-ink-tertiary">
                    {{ new Date(task.created_at).toLocaleString() }}
                  </p>
                </div>
                <AppBadge :tone="stateTone[task.state] ?? 'neutral'">{{ task.state }}</AppBadge>
                <AppIcon name="chevron-left" :size="15" class="rotate-180 text-ink-tertiary" />
              </div>
            </AppCard>
          </NuxtLink>
        </li>
      </ul>
    </div>
  </div>
</template>
