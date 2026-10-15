<script setup lang="ts">
definePageMeta({ middleware: 'auth' })
useHead({ title: 'Invoices' })

interface InvoiceLine {
  description: string
  quantity: number
  unit_price: string
  line_amount: string
  myinvois_tax_type: string | null
  myinvois_tax_rate: string | null
  myinvois_tax_amount: string | null
  myinvois_classification_code: string | null
  myinvois_unit_of_measure: string | null
}

interface LineDraft {
  description: string
  quantity: number
  unit_price: string
  myinvois_tax_type: string
  myinvois_tax_rate: string
  myinvois_tax_amount: string
  myinvois_classification_code: string
  myinvois_unit_of_measure: string
  myinvoisOpen: boolean
}

function newLineDraft(): LineDraft {
  return {
    description: '',
    quantity: 1,
    unit_price: '',
    myinvois_tax_type: '',
    myinvois_tax_rate: '',
    myinvois_tax_amount: '',
    myinvois_classification_code: '',
    myinvois_unit_of_measure: '',
    myinvoisOpen: false,
  }
}

/**
 * Only sends the five MyInvois fields when the Tax Type has actually
 * been picked — the backend (AETS-013 v0.1.0 §6.1) requires all five
 * together once any is present, so an untouched line (the common
 * case today, with no live MyInvois submission to use this for yet)
 * carries none of them, exactly as if the fields didn't exist.
 */
function lineDraftToPayload(line: LineDraft): Record<string, unknown> {
  const payload: Record<string, unknown> = {
    description: line.description,
    quantity: line.quantity,
    unit_price: normalizeMoney(line.unit_price),
  }

  if (line.myinvois_tax_type) {
    payload.myinvois_tax_type = line.myinvois_tax_type
    payload.myinvois_tax_rate = line.myinvois_tax_rate
    payload.myinvois_tax_amount = normalizeMoney(line.myinvois_tax_amount)
    payload.myinvois_classification_code = line.myinvois_classification_code
    payload.myinvois_unit_of_measure = line.myinvois_unit_of_measure
  }

  return payload
}

/** LHDN MyInvois's own Tax Type code table (AETS-013 v0.1.0 §8). */
const MYINVOIS_TAX_TYPE_OPTIONS = [
  { value: '01', label: '01 — Sales Tax' },
  { value: '02', label: '02 — Service Tax' },
  { value: '03', label: '03 — Tourism Tax' },
  { value: '04', label: '04 — High-Value Goods Tax' },
  { value: '05', label: '05 — Sales Tax on Low Value Goods' },
  { value: '06', label: '06 — Not Applicable' },
  { value: 'E', label: 'E — Tax exemption' },
]

interface Invoice {
  id: string
  customer_id: string
  invoice_number: string | null
  status: 'Draft' | 'Issued'
  issue_date: string | null
  due_date: string
  receivable_account_id: string
  revenue_account_id: string
  journal_id: string | null
  total_amount: string
  lines: InvoiceLine[]
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

const { request } = useApi()
const route = useRoute()

/**
 * AETS-017: a direct link to the Invoice's own PDF endpoint — a plain
 * top-level `<a>` navigation carries the Sanctum SPA session cookie
 * exactly like any other same-site GET (SameSite=Lax), so no blob/
 * fetch plumbing is needed here, mirroring `useApi()`'s own API-origin
 * derivation (window's own hostname, only the port configurable).
 */
function pdfUrl(invoiceId: string): string {
  const config = useRuntimeConfig()
  const port = config.public.apiPort as string
  return `${window.location.protocol}//${window.location.hostname}:${port}/api/v1/invoices/${invoiceId}/pdf`
}

const invoices = ref<Invoice[]>([])
const customers = ref<Customer[]>([])
const accounts = ref<Account[]>([])
const loading = ref(true)
const error = ref<string | null>(null)

// `?new=1` (the Work Queue's "Create invoice" quick action) opens the
// form immediately, matching the same query-driven-deep-link
// convention already used by /reports?tab= and /payments?customer_id=.
const showForm = ref(route.query.new !== undefined)
const customerId = ref('')
const dueDate = ref('')
const receivableAccountId = ref('')
const revenueAccountId = ref('')
const lineDrafts = ref<LineDraft[]>([newLineDraft()])
const creating = ref(false)
const createError = ref<string | null>(null)

const issuingId = ref<string | null>(null)
const actionError = ref<string | null>(null)
const today = new Date().toISOString().slice(0, 10)
const issueDates = ref<Record<string, string>>({})

function issueDateFor(invoiceId: string): string {
  return issueDates.value[invoiceId] ?? today
}

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

async function loadInvoices() {
  loading.value = true
  error.value = null
  try {
    const data = await request<{ data: Invoice[] }>('/api/v1/invoices')
    invoices.value = data.data
  } catch {
    error.value = 'Failed to load invoices.'
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
  lineDrafts.value.push(newLineDraft())
}

function removeLine(index: number) {
  lineDrafts.value.splice(index, 1)
}

async function onCreate() {
  createError.value = null
  creating.value = true
  try {
    await request('/api/v1/invoices', {
      method: 'POST',
      body: {
        customer_id: customerId.value,
        due_date: dueDate.value,
        receivable_account_id: receivableAccountId.value,
        revenue_account_id: revenueAccountId.value,
        lines: lineDrafts.value
          .filter((l) => l.description && l.unit_price)
          .map((l) => lineDraftToPayload(l)),
      },
    })
    customerId.value = ''
    dueDate.value = ''
    receivableAccountId.value = ''
    revenueAccountId.value = ''
    lineDrafts.value = [newLineDraft()]
    showForm.value = false
    await loadInvoices()
  } catch {
    createError.value = 'Failed to create invoice draft — check the fields above.'
  } finally {
    creating.value = false
  }
}

async function onIssue(invoiceId: string) {
  actionError.value = null
  issuingId.value = invoiceId
  try {
    await request(`/api/v1/invoices/${invoiceId}/issue`, {
      method: 'POST',
      body: { issue_date: issueDateFor(invoiceId) },
      headers: { 'Idempotency-Key': generateUuid() },
    })
    await loadInvoices()
  } catch {
    actionError.value =
      'Failed to issue the invoice — check it has at least one line, and that the issue date ' +
      '(the date box next to Issue) is not after the due date.'
  } finally {
    issuingId.value = null
  }
}

async function onDelete(invoiceId: string) {
  actionError.value = null
  try {
    await request(`/api/v1/invoices/${invoiceId}`, { method: 'DELETE' })
    await loadInvoices()
  } catch {
    actionError.value = 'Failed to delete the invoice — only a Draft can be deleted.'
  }
}

function customerName(id: string): string {
  return customers.value.find((c) => c.id === id)?.name ?? id.slice(0, 8)
}

// `?highlight=<invoice_id>` (Quotations' own "View invoice" link, once
// it converts) — scrolls to and rings the specific Invoice within
// this flat list, rather than dropping the user onto the standalone
// Details page: seeing it in context of the rest of the list is the
// preferred flow here (Founder feedback, 2026-09-21).
const highlightedInvoiceId = computed(() =>
  typeof route.query.highlight === 'string' ? route.query.highlight : null,
)

onMounted(async () => {
  await Promise.all([loadInvoices(), loadCustomers(), loadAccounts()])

  if (highlightedInvoiceId.value) {
    await nextTick()
    document
      .getElementById(`invoice-${highlightedInvoiceId.value}`)
      ?.scrollIntoView({ behavior: 'smooth', block: 'center' })
  }
})
</script>

<template>
  <div>
    <PageHeader title="Invoices">
      <template #actions>
        <AppButton variant="primary" @click="showForm = !showForm">
          <AppIcon name="plus" :size="15" /> New invoice
        </AppButton>
      </template>
    </PageHeader>

    <AppCard v-if="showForm" class="mb-6">
      <form class="space-y-4" @submit.prevent="onCreate">
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <AppField label="Customer">
            <AppSelect
              v-model="customerId"
              :options="customerOptions"
              placeholder="Select a customer"
              required
            />
          </AppField>
          <AppField label="Due date">
            <AppInput v-model="dueDate" type="date" required />
          </AppField>
          <AppField label="Receivable account">
            <AppSelect
              v-model="receivableAccountId"
              :options="receivableAccountOptions"
              placeholder="Select an Asset account"
              required
            />
          </AppField>
          <AppField label="Revenue account">
            <AppSelect
              v-model="revenueAccountId"
              :options="revenueAccountOptions"
              placeholder="Select a Revenue account"
              required
            />
          </AppField>
        </div>

        <div class="space-y-2">
          <p class="text-xs font-medium text-ink-secondary">Lines</p>
          <div
            v-for="(line, i) in lineDrafts"
            :key="i"
            class="space-y-1.5 rounded-xl border border-border/60 p-2"
          >
            <div class="flex flex-wrap items-end gap-2">
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

            <button
              type="button"
              class="text-[11px] text-ink-tertiary hover:text-ink"
              @click="line.myinvoisOpen = !line.myinvoisOpen"
            >
              {{ line.myinvoisOpen ? 'Hide MyInvois details' : 'Add MyInvois details (optional)' }}
            </button>

            <div v-if="line.myinvoisOpen" class="grid grid-cols-2 gap-2 sm:grid-cols-5">
              <AppSelect
                v-model="line.myinvois_tax_type"
                :options="MYINVOIS_TAX_TYPE_OPTIONS"
                placeholder="Tax type"
              />
              <AppInput v-model="line.myinvois_tax_rate" placeholder="Tax rate %" />
              <AppInput v-model="line.myinvois_tax_amount" placeholder="Tax amount" />
              <AppInput
                v-model="line.myinvois_classification_code"
                placeholder="Classification code"
              />
              <AppInput v-model="line.myinvois_unit_of_measure" placeholder="Unit (e.g. EA)" />
            </div>
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
    <EmptyState
      v-else-if="invoices.length === 0"
      title="No invoices yet"
      description="Create your first invoice to start billing customers."
    />
    <div v-else class="space-y-2">
      <AppCard
        v-for="invoice in invoices"
        :id="`invoice-${invoice.id}`"
        :key="invoice.id"
        :class="
          highlightedInvoiceId === invoice.id
            ? 'ring-2 ring-accent ring-offset-2 ring-offset-surface'
            : ''
        "
      >
        <div class="flex flex-wrap items-center justify-between gap-3">
          <div class="min-w-0">
            <div class="flex items-center gap-2">
              <p class="font-medium text-ink">{{ invoice.invoice_number ?? 'Draft' }}</p>
              <AppBadge :tone="invoice.status === 'Issued' ? 'success' : 'neutral'">{{
                invoice.status
              }}</AppBadge>
            </div>
            <p class="mt-0.5 text-sm text-ink-tertiary">
              {{ customerName(invoice.customer_id) }} · Due {{ invoice.due_date }}
            </p>
          </div>
          <div class="flex flex-wrap items-center gap-3">
            <p class="text-lg font-semibold text-ink">RM{{ invoice.total_amount }}</p>
            <template v-if="invoice.status === 'Draft'">
              <input
                :value="issueDateFor(invoice.id)"
                type="date"
                class="h-9 rounded-lg border border-border bg-surface px-2 text-xs text-ink-secondary"
                @input="issueDates[invoice.id] = ($event.target as HTMLInputElement).value"
              />
              <AppButton
                size="sm"
                variant="primary"
                :disabled="issuingId === invoice.id"
                @click="onIssue(invoice.id)"
              >
                Issue
              </AppButton>
              <AppButton size="sm" variant="ghost" @click="onDelete(invoice.id)">
                <AppIcon name="trash" :size="14" />
              </AppButton>
            </template>
            <a :href="pdfUrl(invoice.id)" target="_blank" rel="noopener">
              <AppButton size="sm" variant="ghost">
                <AppIcon name="download" :size="14" /> PDF
              </AppButton>
            </a>
            <NuxtLink :to="`/invoices/${invoice.id}`">
              <AppButton size="sm" variant="ghost">Details</AppButton>
            </NuxtLink>
          </div>
        </div>
        <p
          v-if="invoice.lines.length > 0"
          class="mt-3 border-t border-border pt-3 text-xs text-ink-tertiary"
        >
          {{ invoice.lines.length }} {{ invoice.lines.length === 1 ? 'item' : 'items' }}
        </p>
      </AppCard>
    </div>
  </div>
</template>
