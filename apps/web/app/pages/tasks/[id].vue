<script setup lang="ts">
/**
 * Task Detail + Human Confirmation (ADR-0009, WTS-001) — a Task's full
 * lifecycle story reconstructed from its own transition audit trail
 * (TSK-001), and the one point in the whole flow where an AI-produced
 * Proposal (once AI Orchestration exists, WTS-004) would require an
 * authenticated human's explicit action before anything can post
 * (WTS-000 §5) — today, every Proposal is human-authored already, but
 * the confirmation step is not skipped for that reason.
 */
definePageMeta({ middleware: 'auth' })

interface Account {
  id: string
  account_code: string
  account_name: string
}

interface Proposal {
  id: string
  command_type: string
  amount: string
  transaction_date: string
  primary_account_id: string
  secondary_account_id: string
  description: string
  evidence_reference: string | null
  confidence: number | null
  producer_type: string
}

interface Transition {
  actor: string
  from_state: string | null
  to_state: string
  reason: string | null
  created_at: string
}

interface TaskDetail {
  id: string
  state: string
  created_at: string
  completed_at: string | null
  result_journal_id: string | null
  failure_reason: string | null
  proposal: Proposal | null
  transitions: Transition[]
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

const commandTypeLabel: Record<string, string> = {
  Expense: 'Expense',
  Income: 'Income',
  Transfer: 'Transfer',
  CapitalContribution: 'Capital contribution',
  OwnerDrawing: 'Owner drawing',
}

const route = useRoute()
const taskId = route.params.id as string

const { request } = useApi()

const task = ref<TaskDetail | null>(null)
const accounts = ref<Account[]>([])
const loading = ref(true)
const error = ref<string | null>(null)
const actionError = ref<string | null>(null)
const acting = ref(false)
const showRejectReason = ref(false)
const showCancelReason = ref(false)
const reason = ref('')

const cancellableStates = ['Received', 'Processing', 'NeedsInformation', 'NeedsReview']

function accountLabel(id: string): string {
  const account = accounts.value.find((a) => a.id === id)
  return account ? `${account.account_code} — ${account.account_name}` : id.slice(0, 8)
}

async function load() {
  loading.value = true
  error.value = null
  try {
    const [taskData] = await Promise.all([
      request<TaskDetail>(`/api/v1/tasks/${taskId}`),
      accounts.value.length === 0
        ? request<{ data: Account[] }>('/api/v1/accounts').then((d) => (accounts.value = d.data))
        : Promise.resolve(),
    ])
    task.value = taskData
  } catch {
    error.value = 'Failed to load this Task.'
  } finally {
    loading.value = false
  }
}

async function approve() {
  actionError.value = null
  acting.value = true
  try {
    task.value = await request<TaskDetail>(`/api/v1/tasks/${taskId}/approve`, { method: 'POST' })
  } catch {
    actionError.value = 'Could not approve this Task — it may have already been acted on.'
    await load()
  } finally {
    acting.value = false
  }
}

async function reject() {
  actionError.value = null
  acting.value = true
  try {
    task.value = await request<TaskDetail>(`/api/v1/tasks/${taskId}/reject`, {
      method: 'POST',
      body: { reason: reason.value },
    })
    showRejectReason.value = false
    reason.value = ''
  } catch {
    actionError.value = 'Could not reject this Task.'
  } finally {
    acting.value = false
  }
}

async function cancel() {
  actionError.value = null
  acting.value = true
  try {
    task.value = await request<TaskDetail>(`/api/v1/tasks/${taskId}/cancel`, {
      method: 'POST',
      body: { reason: reason.value },
    })
    showCancelReason.value = false
    reason.value = ''
  } catch {
    actionError.value = 'Could not cancel this Task.'
  } finally {
    acting.value = false
  }
}

onMounted(load)
</script>

<template>
  <div>
    <PageHeader title="Task">
      <template #actions>
        <NuxtLink to="/tasks">
          <AppButton variant="ghost">
            <AppIcon name="chevron-left" :size="15" /> Back to Work Queue
          </AppButton>
        </NuxtLink>
      </template>
    </PageHeader>

    <p v-if="loading" class="text-sm text-ink-tertiary">Loading…</p>
    <p v-else-if="error || !task" class="text-sm text-danger">{{ error ?? 'Task not found.' }}</p>

    <div v-else class="space-y-6">
      <AppCard>
        <div class="flex flex-wrap items-center justify-between gap-3">
          <div>
            <p class="font-mono text-xs text-ink-tertiary">{{ task.id }}</p>
            <p class="text-sm text-ink-tertiary">
              Submitted {{ new Date(task.created_at).toLocaleString() }}
            </p>
          </div>
          <AppBadge :tone="stateTone[task.state] ?? 'neutral'">{{ task.state }}</AppBadge>
        </div>
      </AppCard>

      <AppCard v-if="task.proposal">
        <h2 class="mb-3 text-sm font-semibold text-ink">Proposal</h2>
        <dl class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
          <div>
            <dt class="text-ink-tertiary">Type</dt>
            <dd class="text-ink">
              {{ commandTypeLabel[task.proposal.command_type] ?? task.proposal.command_type }}
            </dd>
          </div>
          <div>
            <dt class="text-ink-tertiary">Amount</dt>
            <dd class="text-lg font-semibold text-ink">RM{{ task.proposal.amount }}</dd>
          </div>
          <div>
            <dt class="text-ink-tertiary">Date</dt>
            <dd class="text-ink">{{ task.proposal.transaction_date }}</dd>
          </div>
          <div>
            <dt class="text-ink-tertiary">Producer</dt>
            <dd class="text-ink">{{ task.proposal.producer_type }}</dd>
          </div>
          <div>
            <dt class="text-ink-tertiary">Primary account</dt>
            <dd class="text-ink">{{ accountLabel(task.proposal.primary_account_id) }}</dd>
          </div>
          <div>
            <dt class="text-ink-tertiary">Secondary account</dt>
            <dd class="text-ink">{{ accountLabel(task.proposal.secondary_account_id) }}</dd>
          </div>
          <div class="sm:col-span-2">
            <dt class="text-ink-tertiary">Description</dt>
            <dd class="text-ink">{{ task.proposal.description }}</dd>
          </div>
        </dl>
      </AppCard>

      <AppCard v-if="task.state === 'Completed'">
        <div class="flex items-center gap-2 text-success">
          <AppIcon name="check" :size="16" />
          <p class="text-sm font-medium">Posted a balanced Journal.</p>
        </div>
        <p class="mt-1 font-mono text-xs text-ink-tertiary">{{ task.result_journal_id }}</p>
      </AppCard>

      <AppCard v-if="task.state === 'Failed'">
        <p class="text-sm font-medium text-danger">Accounting Core rejected this Task.</p>
        <p class="mt-1 text-sm text-ink-secondary">{{ task.failure_reason }}</p>
      </AppCard>

      <AppCard v-if="task.state === 'NeedsReview'">
        <h2 class="mb-1 text-sm font-semibold text-ink">Human Confirmation</h2>
        <p class="mb-3 text-sm text-ink-tertiary">
          Nothing has posted yet. Confirming will submit exactly the Proposal above as a real
          accounting entry.
        </p>

        <p v-if="actionError" class="mb-3 text-sm text-danger">{{ actionError }}</p>

        <div v-if="!showRejectReason && !showCancelReason" class="flex flex-wrap gap-2">
          <AppButton variant="primary" :disabled="acting" @click="approve">
            <AppIcon name="check" :size="15" /> {{ acting ? 'Confirming…' : 'Confirm and post' }}
          </AppButton>
          <AppButton variant="ghost" :disabled="acting" @click="showRejectReason = true">
            Reject
          </AppButton>
        </div>

        <div v-if="showRejectReason" class="flex flex-wrap items-end gap-2">
          <div class="w-full sm:w-72">
            <AppField label="Reason for rejecting">
              <AppInput v-model="reason" placeholder="Wrong account, duplicate, etc." required />
            </AppField>
          </div>
          <AppButton variant="danger" :disabled="acting || !reason" @click="reject"
            >Reject</AppButton
          >
          <AppButton variant="ghost" :disabled="acting" @click="showRejectReason = false"
            >Back</AppButton
          >
        </div>
      </AppCard>

      <AppCard v-if="cancellableStates.includes(task.state) && task.state !== 'NeedsReview'">
        <div v-if="!showCancelReason">
          <AppButton variant="ghost" :disabled="acting" @click="showCancelReason = true"
            >Cancel this Task</AppButton
          >
        </div>
        <div v-else class="flex flex-wrap items-end gap-2">
          <div class="w-full sm:w-72">
            <AppField label="Reason for cancelling">
              <AppInput v-model="reason" placeholder="No longer needed" required />
            </AppField>
          </div>
          <AppButton variant="danger" :disabled="acting || !reason" @click="cancel"
            >Cancel Task</AppButton
          >
          <AppButton variant="ghost" :disabled="acting" @click="showCancelReason = false"
            >Back</AppButton
          >
        </div>
      </AppCard>

      <AppCard>
        <h2 class="mb-3 text-sm font-semibold text-ink">History</h2>
        <ol class="space-y-2">
          <li
            v-for="(transition, index) in task.transitions"
            :key="index"
            class="flex items-center justify-between gap-3 text-sm"
          >
            <span class="text-ink">
              <template v-if="transition.from_state">{{ transition.from_state }} →</template>
              {{ transition.to_state }}
            </span>
            <span v-if="transition.reason" class="text-ink-tertiary">{{ transition.reason }}</span>
            <span class="shrink-0 text-xs text-ink-tertiary">{{
              new Date(transition.created_at).toLocaleString()
            }}</span>
          </li>
        </ol>
      </AppCard>
    </div>
  </div>
</template>
