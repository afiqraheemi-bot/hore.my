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
// `key` forces a full remount on navigation between two instances of
// this same dynamic route (e.g. `supersede()` below navigating from
// the superseded Task's own page to its correction's) — otherwise Vue
// Router reuses the existing component instance, `taskId` (captured
// once from the route below) would go stale, and the page would keep
// showing the *original* Task's data under the *new* URL.
definePageMeta({ middleware: 'auth', key: (route) => route.fullPath })
useHead({ title: 'Task' })

interface Account {
  id: string
  account_code: string
  account_name: string
  account_type: string
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

interface Draft {
  command_type: string
  amount: string
  transaction_date: string
  description: string
  evidence_reference: string | null
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
  supersedes_task_id: string | null
  proposal: Proposal | null
  draft: Draft | null
  transitions: Transition[]
}

interface AccountRule {
  primaryLabel: string
  primaryTypes: string[]
  secondaryLabel: string
  secondaryTypes: string[]
}

/**
 * Mirrors `index.vue`'s own `types` list exactly — the same
 * primary/secondary Account-type rule per Command type, reused here
 * for both the `NeedsInformation` completion form and the
 * `NeedsReview` edit/supersede form, so an Account picker never
 * offers a structurally wrong Account regardless of which flow
 * reaches it.
 */
const accountRules: Record<string, AccountRule> = {
  Expense: {
    primaryLabel: 'Category',
    primaryTypes: ['Expense'],
    secondaryLabel: 'Paid from',
    secondaryTypes: ['Asset'],
  },
  Income: {
    primaryLabel: 'Category',
    primaryTypes: ['Revenue'],
    secondaryLabel: 'Deposited to',
    secondaryTypes: ['Asset'],
  },
  Transfer: {
    primaryLabel: 'From account',
    primaryTypes: ['Asset', 'Liability'],
    secondaryLabel: 'To account',
    secondaryTypes: ['Asset', 'Liability'],
  },
  CapitalContribution: {
    primaryLabel: 'Cash account',
    primaryTypes: ['Asset'],
    secondaryLabel: "Owner's capital account",
    secondaryTypes: ['Equity'],
  },
  OwnerDrawing: {
    primaryLabel: 'Cash account',
    primaryTypes: ['Asset'],
    secondaryLabel: "Owner's capital account",
    secondaryTypes: ['Equity'],
  },
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

/** Mirrors `invoices/index.vue`'s own `pdfUrl()` — a same-site GET
 * carries the Sanctum SPA session cookie, so no blob/fetch plumbing
 * is needed to open the originally-uploaded receipt/document. */
function evidenceUrl(evidenceId: string): string {
  const config = useRuntimeConfig()
  const port = config.public.apiPort as string
  return `${window.location.protocol}//${window.location.hostname}:${port}/api/v1/evidence/${evidenceId}`
}

const task = ref<TaskDetail | null>(null)
const accounts = ref<Account[]>([])
const loading = ref(true)
const error = ref<string | null>(null)
const actionError = ref<string | null>(null)
const acting = ref(false)
const showRejectReason = ref(false)
const showCancelReason = ref(false)
const reason = ref('')

const provideInfoPrimaryAccountId = ref('')
const provideInfoSecondaryAccountId = ref('')

const showEditForm = ref(false)
const editAmount = ref('')
const editDate = ref('')
const editDescription = ref('')
const editPrimaryAccountId = ref('')
const editSecondaryAccountId = ref('')
const editReason = ref('')

const cancellableStates = ['Received', 'Processing', 'NeedsInformation', 'NeedsReview']

function accountLabel(id: string): string {
  const account = accounts.value.find((a) => a.id === id)
  return account ? account.account_name : id.slice(0, 8)
}

function accountOptions(types: string[]): { value: string; label: string }[] {
  return accounts.value
    .filter((a) => types.includes(a.account_type))
    .map((a) => ({ value: a.id, label: a.account_name }))
}

function ruleFor(commandType: string): AccountRule {
  return accountRules[commandType] ?? accountRules.Expense!
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
  schedulePolling()
}

/**
 * A Task in one of these states can advance on its own — a queue
 * worker is (or is about to be) acting on it — so this page polls
 * quietly rather than requiring a manual reload to notice
 * (HORE_MY_MASTER_CONTEXT.md §5, "progressive responses"). States that
 * only change on an authenticated human's own action here (NeedsReview,
 * NeedsInformation, Approved's stalled-resume case) are excluded: there
 * is nothing happening in the background to reveal.
 */
const pollingStates = new Set(['Received', 'Processing', 'Executing'])
let pollHandle: ReturnType<typeof setInterval> | undefined
const isPolling = ref(false)

function schedulePolling() {
  if (pollHandle || !task.value || !pollingStates.has(task.value.state)) return

  isPolling.value = true
  pollHandle = setInterval(async () => {
    if (!task.value || !pollingStates.has(task.value.state)) {
      clearInterval(pollHandle)
      pollHandle = undefined
      isPolling.value = false
      return
    }
    try {
      task.value = await request<TaskDetail>(`/api/v1/tasks/${taskId}`)
    } catch {
      // Keep the last known state; the next tick tries again.
    }
  }, 3000)
}

onUnmounted(() => {
  if (pollHandle) clearInterval(pollHandle)
})

async function approve() {
  actionError.value = null
  acting.value = true
  try {
    task.value = await request<TaskDetail>(`/api/v1/tasks/${taskId}/approve`, { method: 'POST' })
    schedulePolling()
  } catch {
    actionError.value = 'Could not approve this Task — it may have already been acted on.'
    await load()
  } finally {
    acting.value = false
  }
}

async function resume() {
  actionError.value = null
  acting.value = true
  try {
    task.value = await request<TaskDetail>(`/api/v1/tasks/${taskId}/resume`, { method: 'POST' })
    schedulePolling()
  } catch {
    actionError.value = 'Could not resume this Task.'
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

async function provideInformation() {
  actionError.value = null
  acting.value = true
  try {
    task.value = await request<TaskDetail>(`/api/v1/tasks/${taskId}/provide-information`, {
      method: 'POST',
      body: {
        primary_account_id: provideInfoPrimaryAccountId.value,
        secondary_account_id: provideInfoSecondaryAccountId.value,
      },
    })
  } catch {
    actionError.value = 'Could not complete this Task — check the accounts you chose.'
  } finally {
    acting.value = false
  }
}

function startEdit() {
  if (!task.value?.proposal) return

  editAmount.value = task.value.proposal.amount
  editDate.value = task.value.proposal.transaction_date
  editDescription.value = task.value.proposal.description
  editPrimaryAccountId.value = task.value.proposal.primary_account_id
  editSecondaryAccountId.value = task.value.proposal.secondary_account_id
  editReason.value = ''
  actionError.value = null
  showEditForm.value = true
}

async function supersede() {
  if (!task.value?.proposal) return

  actionError.value = null
  acting.value = true
  try {
    const correction = await request<TaskDetail>(`/api/v1/tasks/${taskId}/supersede`, {
      method: 'POST',
      headers: { 'Idempotency-Key': crypto.randomUUID() },
      body: {
        command_type: task.value.proposal.command_type,
        amount: normalizeMoney(editAmount.value),
        transaction_date: editDate.value,
        primary_account_id: editPrimaryAccountId.value,
        secondary_account_id: editSecondaryAccountId.value,
        description: editDescription.value,
        ...(editReason.value ? { reason: editReason.value } : {}),
      },
    })
    await navigateTo(`/tasks/${correction.id}`)
  } catch {
    actionError.value = 'Could not save this correction — check the amount and accounts.'
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
            <AppIcon name="chevron-left" :size="15" /> Back to Home
          </AppButton>
        </NuxtLink>
      </template>
    </PageHeader>

    <p v-if="loading" class="text-sm text-ink-tertiary">Loading…</p>
    <p v-else-if="error || !task" class="text-sm text-danger">{{ error ?? 'Task not found.' }}</p>

    <div v-else class="space-y-6">
      <AppCard>
        <div class="flex flex-wrap items-center justify-between gap-3">
          <p class="text-sm text-ink-tertiary">
            Submitted {{ new Date(task.created_at).toLocaleString() }}
          </p>
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
            <AppBadge :tone="stateTone[task.state] ?? 'neutral'">{{ task.state }}</AppBadge>
          </div>
        </div>
        <p v-if="task.supersedes_task_id" class="mt-3 text-sm text-ink-tertiary">
          Correction of
          <NuxtLink :to="`/tasks/${task.supersedes_task_id}`" class="text-accent underline">
            {{ task.supersedes_task_id.slice(0, 8) }}
          </NuxtLink>
        </p>
      </AppCard>

      <AppCard v-if="task.state === 'NeedsInformation' && task.draft">
        <h2 class="mb-1 text-sm font-semibold text-ink">Needs more information</h2>
        <p class="mb-3 text-sm text-ink-tertiary">
          This was saved without choosing accounts yet. Pick both to send it for review.
        </p>
        <dl class="mb-4 grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
          <div>
            <dt class="text-ink-tertiary">Type</dt>
            <dd class="text-ink">
              {{ commandTypeLabel[task.draft.command_type] ?? task.draft.command_type }}
            </dd>
          </div>
          <div>
            <dt class="text-ink-tertiary">Amount</dt>
            <dd class="text-lg font-semibold text-ink">RM{{ task.draft.amount }}</dd>
          </div>
          <div>
            <dt class="text-ink-tertiary">Date</dt>
            <dd class="text-ink">{{ task.draft.transaction_date }}</dd>
          </div>
          <div class="sm:col-span-2">
            <dt class="text-ink-tertiary">Description</dt>
            <dd class="text-ink">{{ task.draft.description }}</dd>
          </div>
          <div v-if="task.draft.evidence_reference">
            <dt class="text-ink-tertiary">Evidence</dt>
            <dd class="text-ink">
              <a
                :href="evidenceUrl(task.draft.evidence_reference)"
                target="_blank"
                rel="noopener"
                class="text-accent underline"
              >
                View evidence
              </a>
            </dd>
          </div>
        </dl>

        <p v-if="actionError" class="mb-3 text-sm text-danger">{{ actionError }}</p>

        <form class="grid grid-cols-1 gap-4 sm:grid-cols-2" @submit.prevent="provideInformation">
          <AppField :label="ruleFor(task.draft.command_type).primaryLabel">
            <AppSelect
              v-model="provideInfoPrimaryAccountId"
              :options="accountOptions(ruleFor(task.draft.command_type).primaryTypes)"
              :placeholder="
                ruleFor(task.draft.command_type).primaryLabel === 'Category'
                  ? 'Select a category'
                  : 'Select an account'
              "
              required
            />
          </AppField>
          <AppField :label="ruleFor(task.draft.command_type).secondaryLabel">
            <AppSelect
              v-model="provideInfoSecondaryAccountId"
              :options="accountOptions(ruleFor(task.draft.command_type).secondaryTypes)"
              placeholder="Select an account"
              required
            />
          </AppField>
          <div class="sm:col-span-2">
            <AppButton type="submit" variant="primary" :disabled="acting">
              {{ acting ? 'Submitting…' : 'Submit for review' }}
            </AppButton>
          </div>
        </form>
      </AppCard>

      <AppCard v-if="task.state === 'Superseded'">
        <p class="text-sm font-medium text-ink">This Task was superseded by a correction.</p>
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
          <div v-if="task.proposal.confidence !== null">
            <dt class="text-ink-tertiary">Confidence</dt>
            <dd class="text-ink">{{ Math.round(task.proposal.confidence * 100) }}%</dd>
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
          <div v-if="task.proposal.evidence_reference">
            <dt class="text-ink-tertiary">Evidence</dt>
            <dd class="text-ink">
              <a
                :href="evidenceUrl(task.proposal.evidence_reference)"
                target="_blank"
                rel="noopener"
                class="text-accent underline"
              >
                View evidence
              </a>
            </dd>
          </div>
        </dl>
      </AppCard>

      <AppCard v-if="task.state === 'Approved'">
        <p class="mb-1 text-sm font-medium text-ink">This Task did not finish confirming.</p>
        <p class="mb-3 text-sm text-ink-tertiary">
          It was confirmed but never started posting — likely an interrupted request. Resuming is
          safe: it will never post twice.
        </p>
        <p v-if="actionError" class="mb-3 text-sm text-danger">{{ actionError }}</p>
        <AppButton variant="primary" :disabled="acting" @click="approve">
          {{ acting ? 'Resuming…' : 'Resume' }}
        </AppButton>
      </AppCard>

      <AppCard v-if="task.state === 'Executing'">
        <p class="mb-1 text-sm font-medium text-ink">This Task did not finish confirming.</p>
        <p class="mb-3 text-sm text-ink-tertiary">
          It was approved but never reached a final result — likely an interrupted request. Resuming
          is safe: it will never post twice.
        </p>
        <p v-if="actionError" class="mb-3 text-sm text-danger">{{ actionError }}</p>
        <AppButton variant="primary" :disabled="acting" @click="resume">
          {{ acting ? 'Resuming…' : 'Resume' }}
        </AppButton>
      </AppCard>

      <AppCard v-if="task.state === 'Completed'">
        <div class="flex items-center gap-2 text-success">
          <AppIcon name="check" :size="16" />
          <p class="text-sm font-medium">Recorded to your books.</p>
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

        <div
          v-if="!showRejectReason && !showCancelReason && !showEditForm"
          class="flex flex-wrap gap-2"
        >
          <AppButton variant="primary" :disabled="acting" @click="approve">
            <AppIcon name="check" :size="15" /> {{ acting ? 'Confirming…' : 'Confirm and post' }}
          </AppButton>
          <AppButton variant="ghost" :disabled="acting" @click="startEdit">Edit</AppButton>
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

        <form
          v-if="showEditForm && task.proposal"
          class="grid grid-cols-1 gap-4 sm:grid-cols-2"
          @submit.prevent="supersede"
        >
          <AppField label="Amount">
            <input
              v-model="editAmount"
              aria-label="Corrected amount"
              required
              inputmode="decimal"
              class="h-10 w-full rounded-lg border border-border bg-surface px-3 text-sm text-ink"
            />
          </AppField>
          <AppField label="Date">
            <input
              v-model="editDate"
              aria-label="Corrected transaction date"
              type="date"
              required
              class="h-10 w-full rounded-lg border border-border bg-surface px-3 text-sm text-ink"
            />
          </AppField>
          <AppField :label="ruleFor(task.proposal.command_type).primaryLabel">
            <AppSelect
              v-model="editPrimaryAccountId"
              :options="accountOptions(ruleFor(task.proposal.command_type).primaryTypes)"
              :placeholder="
                ruleFor(task.proposal.command_type).primaryLabel === 'Category'
                  ? 'Select a category'
                  : 'Select an account'
              "
              required
            />
          </AppField>
          <AppField :label="ruleFor(task.proposal.command_type).secondaryLabel">
            <AppSelect
              v-model="editSecondaryAccountId"
              :options="accountOptions(ruleFor(task.proposal.command_type).secondaryTypes)"
              placeholder="Select an account"
              required
            />
          </AppField>
          <div class="sm:col-span-2">
            <AppField label="Description">
              <AppInput v-model="editDescription" required />
            </AppField>
          </div>
          <div class="sm:col-span-2">
            <AppField label="Reason for editing (optional)">
              <AppInput v-model="editReason" placeholder="Wrong account, typo, etc." />
            </AppField>
          </div>
          <div class="flex gap-2 sm:col-span-2">
            <AppButton type="submit" variant="primary" :disabled="acting">
              {{ acting ? 'Saving…' : 'Save correction' }}
            </AppButton>
            <AppButton variant="ghost" :disabled="acting" @click="showEditForm = false">
              Cancel
            </AppButton>
          </div>
        </form>
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
        <p class="mt-3 border-t border-border pt-3 font-mono text-xs text-ink-tertiary">
          Reference: {{ task.id }}
        </p>
      </AppCard>
    </div>
  </div>
</template>
