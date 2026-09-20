<script setup lang="ts">
/**
 * Quotation lifecycle UI (AETS-016): Draft -> Sent -> Accepted/
 * Rejected, and Accepted -> Converted (a new Draft Invoice). Mirrors
 * `invoices/index.vue`'s own single-page list+create pattern — a
 * Quotation never touches the ledger, so there is no "issue" action
 * here, only the lifecycle actions AETS-016 §4 defines.
 */
definePageMeta({ middleware: 'auth' })

interface QuotationLine {
  description: string
  quantity: number
  unit_price: string
  line_amount: string
}

interface Quotation {
  id: string
  customer_id: string
  quotation_number: string | null
  status: 'Draft' | 'Sent' | 'Accepted' | 'Rejected' | 'Converted'
  issue_date: string | null
  valid_until: string
  converted_invoice_id: string | null
  total_amount: string
  lines: QuotationLine[]
}

interface Customer {
  id: string
  name: string
}

interface Account {
  id: string
  account_code: string
  account_name: string
  account_type: string
}

const statusTone: Record<
  Quotation['status'],
  'neutral' | 'success' | 'danger' | 'warning' | 'accent'
> = {
  Draft: 'neutral',
  Sent: 'warning',
  Accepted: 'accent',
  Rejected: 'danger',
  Converted: 'success',
}

const { request } = useApi()

/**
 * AETS-017: a direct link to the Quotation's own PDF endpoint —
 * mirrors `invoices/index.vue`'s own identical `pdfUrl()` helper.
 */
function pdfUrl(quotationId: string): string {
  const config = useRuntimeConfig()
  const port = config.public.apiPort as string
  return `${window.location.protocol}//${window.location.hostname}:${port}/api/v1/quotations/${quotationId}/pdf`
}

const quotations = ref<Quotation[]>([])
const customers = ref<Customer[]>([])
const accounts = ref<Account[]>([])
const loading = ref(true)
const error = ref<string | null>(null)

const showForm = ref(false)
const customerId = ref('')
const validUntil = ref('')
const lineDrafts = ref<{ description: string; quantity: number; unit_price: string }[]>([
  { description: '', quantity: 1, unit_price: '' },
])
const creating = ref(false)
const createError = ref<string | null>(null)

const actionError = ref<string | null>(null)
const actingId = ref<string | null>(null)
const today = new Date().toISOString().slice(0, 10)
const issueDates = ref<Record<string, string>>({})

const convertingId = ref<string | null>(null)
const convertReceivableAccountId = ref('')
const convertRevenueAccountId = ref('')
const convertDueDate = ref('')

const customerOptions = computed(() => customers.value.map((c) => ({ value: c.id, label: c.name })))
const receivableAccountOptions = computed(() =>
  accounts.value
    .filter((a) => a.account_type === 'Asset')
    .map((a) => ({ value: a.id, label: a.account_name })),
)
const revenueAccountOptions = computed(() =>
  accounts.value
    .filter((a) => a.account_type === 'Revenue')
    .map((a) => ({ value: a.id, label: a.account_name })),
)

function issueDateFor(quotationId: string): string {
  return issueDates.value[quotationId] ?? today
}

async function loadQuotations() {
  loading.value = true
  error.value = null
  try {
    const data = await request<{ data: Quotation[] }>('/api/v1/quotations')
    quotations.value = data.data
  } catch {
    error.value = 'Failed to load quotations.'
  } finally {
    loading.value = false
  }
}

async function loadCustomers() {
  const data = await request<{ data: Customer[] }>('/api/v1/customers')
  customers.value = data.data
}

async function loadAccounts() {
  const data = await request<{ data: Account[] }>('/api/v1/accounts')
  accounts.value = data.data
}

function addLine() {
  lineDrafts.value.push({ description: '', quantity: 1, unit_price: '' })
}

function removeLine(index: number) {
  lineDrafts.value.splice(index, 1)
}

async function onCreate() {
  createError.value = null
  creating.value = true
  try {
    await request('/api/v1/quotations', {
      method: 'POST',
      body: {
        customer_id: customerId.value,
        valid_until: validUntil.value,
        lines: lineDrafts.value
          .filter((l) => l.description && l.unit_price)
          .map((l) => ({ ...l, unit_price: normalizeMoney(l.unit_price) })),
      },
    })
    customerId.value = ''
    validUntil.value = ''
    lineDrafts.value = [{ description: '', quantity: 1, unit_price: '' }]
    showForm.value = false
    await loadQuotations()
  } catch {
    createError.value = 'Failed to create quotation draft — check the fields above.'
  } finally {
    creating.value = false
  }
}

async function onSend(quotationId: string) {
  actionError.value = null
  actingId.value = quotationId
  try {
    await request(`/api/v1/quotations/${quotationId}/send`, {
      method: 'POST',
      body: { issue_date: issueDateFor(quotationId) },
    })
    await loadQuotations()
  } catch {
    actionError.value = 'Failed to send the quotation — it may be empty.'
  } finally {
    actingId.value = null
  }
}

async function onAccept(quotationId: string) {
  actionError.value = null
  actingId.value = quotationId
  try {
    await request(`/api/v1/quotations/${quotationId}/accept`, { method: 'POST' })
    await loadQuotations()
  } catch {
    actionError.value = 'Failed to accept the quotation.'
  } finally {
    actingId.value = null
  }
}

async function onReject(quotationId: string) {
  actionError.value = null
  actingId.value = quotationId
  try {
    await request(`/api/v1/quotations/${quotationId}/reject`, { method: 'POST' })
    await loadQuotations()
  } catch {
    actionError.value = 'Failed to reject the quotation.'
  } finally {
    actingId.value = null
  }
}

async function onDelete(quotationId: string) {
  actionError.value = null
  try {
    await request(`/api/v1/quotations/${quotationId}`, { method: 'DELETE' })
    await loadQuotations()
  } catch {
    actionError.value = 'Failed to delete the quotation — only a Draft can be deleted.'
  }
}

function startConvert(quotationId: string) {
  actionError.value = null
  convertReceivableAccountId.value = ''
  convertRevenueAccountId.value = ''
  convertDueDate.value = today
  convertingId.value = quotationId
}

async function onConvert(quotationId: string) {
  actionError.value = null
  actingId.value = quotationId
  try {
    await request(`/api/v1/quotations/${quotationId}/convert-to-invoice`, {
      method: 'POST',
      body: {
        receivable_account_id: convertReceivableAccountId.value,
        revenue_account_id: convertRevenueAccountId.value,
        due_date: convertDueDate.value,
      },
    })
    convertingId.value = null
    await loadQuotations()
  } catch {
    actionError.value = 'Failed to convert this quotation — check the accounts chosen.'
  } finally {
    actingId.value = null
  }
}

function customerName(id: string): string {
  return customers.value.find((c) => c.id === id)?.name ?? id.slice(0, 8)
}

onMounted(async () => {
  await Promise.all([loadQuotations(), loadCustomers(), loadAccounts()])
})
</script>

<template>
  <div>
    <PageHeader title="Quotations">
      <template #actions>
        <AppButton variant="primary" @click="showForm = !showForm">
          <AppIcon name="plus" :size="15" /> New quotation
        </AppButton>
      </template>
    </PageHeader>

    <AppCard v-if="showForm" class="mb-6">
      <form class="space-y-4" @submit.prevent="onCreate">
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <AppField label="Customer">
            <AppSelect
              v-model="customerId"
              :options="customerOptions"
              placeholder="Select a customer"
              required
            />
          </AppField>
          <AppField label="Valid until">
            <AppInput v-model="validUntil" type="date" required />
          </AppField>
        </div>

        <div class="space-y-2">
          <p class="text-xs font-medium text-ink-secondary">Lines</p>
          <div v-for="(line, i) in lineDrafts" :key="i" class="flex flex-wrap items-end gap-2">
            <div class="min-w-[10rem] flex-1">
              <AppInput v-model="line.description" placeholder="Description" />
            </div>
            <div class="w-20">
              <AppInput v-model.number="line.quantity" type="number" min="1" placeholder="Qty" />
            </div>
            <div class="w-28">
              <AppInput v-model="line.unit_price" placeholder="Unit price" />
            </div>
            <button
              type="button"
              class="mb-2.5 text-ink-tertiary hover:text-danger"
              @click="removeLine(i)"
            >
              <AppIcon name="trash" :size="16" />
            </button>
          </div>
          <AppButton size="sm" variant="ghost" type="button" @click="addLine">
            <AppIcon name="plus" :size="13" /> Add line
          </AppButton>
        </div>

        <p v-if="createError" class="text-sm text-danger">{{ createError }}</p>
        <AppButton type="submit" variant="primary" :disabled="creating">
          {{ creating ? 'Saving…' : 'Save as Draft' }}
        </AppButton>
      </form>
    </AppCard>

    <p v-if="actionError" class="mb-3 text-sm text-danger">{{ actionError }}</p>

    <p v-if="loading" class="text-sm text-ink-tertiary">Loading…</p>
    <p v-else-if="error" class="text-sm text-danger">{{ error }}</p>
    <EmptyState v-else-if="quotations.length === 0" title="No quotations yet" />
    <div v-else class="space-y-2">
      <AppCard v-for="quotation in quotations" :key="quotation.id">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <div class="min-w-0">
            <div class="flex items-center gap-2">
              <p class="font-medium text-ink">
                {{ quotation.quotation_number ?? 'Draft quotation' }}
              </p>
              <AppBadge :tone="statusTone[quotation.status]">{{ quotation.status }}</AppBadge>
            </div>
            <p class="mt-0.5 text-sm text-ink-tertiary">
              {{ customerName(quotation.customer_id) }} · Valid until {{ quotation.valid_until }}
            </p>
          </div>
          <div class="flex flex-wrap items-center gap-3">
            <p class="text-lg font-semibold text-ink">RM{{ quotation.total_amount }}</p>

            <template v-if="quotation.status === 'Draft'">
              <input
                :value="issueDateFor(quotation.id)"
                type="date"
                class="h-9 rounded-lg border border-border bg-surface px-2 text-xs text-ink-secondary"
                @input="issueDates[quotation.id] = ($event.target as HTMLInputElement).value"
              />
              <AppButton
                size="sm"
                variant="primary"
                :disabled="actingId === quotation.id"
                @click="onSend(quotation.id)"
              >
                Send
              </AppButton>
              <AppButton size="sm" variant="ghost" @click="onDelete(quotation.id)">
                <AppIcon name="trash" :size="14" />
              </AppButton>
            </template>

            <template v-else-if="quotation.status === 'Sent'">
              <AppButton
                size="sm"
                variant="primary"
                :disabled="actingId === quotation.id"
                @click="onAccept(quotation.id)"
              >
                Mark accepted
              </AppButton>
              <AppButton
                size="sm"
                variant="ghost"
                :disabled="actingId === quotation.id"
                @click="onReject(quotation.id)"
              >
                Reject
              </AppButton>
            </template>

            <template v-else-if="quotation.status === 'Accepted' && convertingId !== quotation.id">
              <AppButton size="sm" variant="primary" @click="startConvert(quotation.id)">
                Convert to invoice
              </AppButton>
              <AppButton
                size="sm"
                variant="ghost"
                :disabled="actingId === quotation.id"
                @click="onReject(quotation.id)"
              >
                Reject
              </AppButton>
            </template>

            <template
              v-else-if="quotation.status === 'Converted' && quotation.converted_invoice_id"
            >
              <NuxtLink :to="`/invoices?highlight=${quotation.converted_invoice_id}`">
                <AppButton size="sm" variant="ghost">View invoice</AppButton>
              </NuxtLink>
            </template>

            <a :href="pdfUrl(quotation.id)" target="_blank" rel="noopener">
              <AppButton size="sm" variant="ghost">
                <AppIcon name="download" :size="14" /> PDF
              </AppButton>
            </a>
            <NuxtLink :to="`/quotations/${quotation.id}`">
              <AppButton size="sm" variant="ghost">Details</AppButton>
            </NuxtLink>
          </div>
        </div>

        <div
          v-if="convertingId === quotation.id"
          class="mt-3 grid grid-cols-1 gap-3 border-t border-border pt-3 sm:grid-cols-3"
        >
          <AppField label="Receivable account">
            <AppSelect
              v-model="convertReceivableAccountId"
              :options="receivableAccountOptions"
              placeholder="Select an Asset account"
              required
            />
          </AppField>
          <AppField label="Revenue account">
            <AppSelect
              v-model="convertRevenueAccountId"
              :options="revenueAccountOptions"
              placeholder="Select a Revenue account"
              required
            />
          </AppField>
          <AppField label="Invoice due date">
            <AppInput v-model="convertDueDate" type="date" required />
          </AppField>
          <div class="flex gap-2 sm:col-span-3">
            <AppButton
              size="sm"
              variant="primary"
              :disabled="actingId === quotation.id"
              @click="onConvert(quotation.id)"
            >
              {{ actingId === quotation.id ? 'Converting…' : 'Create Draft Invoice' }}
            </AppButton>
            <AppButton size="sm" variant="ghost" @click="convertingId = null">Cancel</AppButton>
          </div>
        </div>

        <p
          v-if="quotation.lines.length > 0"
          class="mt-3 border-t border-border pt-3 text-xs text-ink-tertiary"
        >
          {{ quotation.lines.length }} {{ quotation.lines.length === 1 ? 'item' : 'items' }}
        </p>
      </AppCard>
    </div>
  </div>
</template>
