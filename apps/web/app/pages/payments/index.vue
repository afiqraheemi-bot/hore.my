<script setup lang="ts">
definePageMeta({ middleware: 'auth' })

interface Payment {
  id: string
  customer_id: string
  amount: string
  payment_date: string
  deposit_account_id: string
  receivable_account_id: string
  journal_id: string
  reference: string | null
  allocated_amount: string
  unallocated_amount: string
}

interface Allocation {
  id: string
  payment_id: string
  invoice_id: string
  amount: string
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

interface OutstandingInvoice {
  id: string
  invoice_number: string | null
  customer_id: string
  total_amount: string
  outstanding_balance: string
}

const { request } = useApi()

/**
 * AETS-017: a direct link to the Payment's own receipt PDF endpoint —
 * mirrors `invoices/index.vue`'s own identical `pdfUrl()` helper.
 */
function pdfUrl(paymentId: string): string {
  const config = useRuntimeConfig()
  const port = config.public.apiPort as string
  return `${window.location.protocol}//${window.location.hostname}:${port}/api/v1/payments/${paymentId}/pdf`
}

const payments = ref<Payment[]>([])
const customers = ref<Customer[]>([])
const accounts = ref<Account[]>([])
const outstandingInvoices = ref<OutstandingInvoice[]>([])
const allocationsByPayment = ref<Record<string, Allocation[]>>({})
const loading = ref(true)
const error = ref<string | null>(null)

const showForm = ref(false)
const customerId = ref('')
const amount = ref('')
const paymentDate = ref('')
const depositAccountId = ref('')
const receivableAccountId = ref('')
const reference = ref('')
const creating = ref(false)
const createError = ref<string | null>(null)

const allocatingPaymentId = ref<string | null>(null)
const allocateInvoiceId = ref('')
const allocateAmount = ref('')
const actionError = ref<string | null>(null)

const depositAccountOptions = computed(() =>
  accounts.value
    .filter((a) => a.account_type === 'Asset')
    .map((a) => ({ value: a.id, label: a.account_name })),
)
const receivableAccountOptions = depositAccountOptions
const customerOptions = computed(() => customers.value.map((c) => ({ value: c.id, label: c.name })))
const outstandingInvoiceOptions = computed(() =>
  outstandingInvoices.value.map((inv) => ({ value: inv.id, label: invoiceLabel(inv) })),
)

async function loadPayments() {
  loading.value = true
  error.value = null
  try {
    const data = await request<{ data: Payment[] }>('/api/v1/payments')
    payments.value = data.data
    await Promise.all(payments.value.map(loadAllocationsFor))
  } catch {
    error.value = 'Failed to load payments.'
  } finally {
    loading.value = false
  }
}

async function loadAllocationsFor(payment: Payment) {
  const data = await request<{ data: Allocation[] }>(`/api/v1/payments/${payment.id}/allocations`)
  allocationsByPayment.value[payment.id] = data.data
}

async function loadCustomers() {
  const data = await request<{ data: Customer[] }>('/api/v1/customers')
  customers.value = data.data
}

async function loadAccounts() {
  const data = await request<{ data: Account[] }>('/api/v1/accounts')
  accounts.value = data.data
}

async function loadOutstandingInvoices() {
  const data = await request<{ data: OutstandingInvoice[] }>('/api/v1/outstanding-invoices')
  outstandingInvoices.value = data.data
}

async function onCreate() {
  createError.value = null
  creating.value = true
  try {
    await request('/api/v1/payments', {
      method: 'POST',
      body: {
        customer_id: customerId.value,
        amount: normalizeMoney(amount.value),
        payment_date: paymentDate.value,
        deposit_account_id: depositAccountId.value,
        receivable_account_id: receivableAccountId.value,
        reference: reference.value || undefined,
      },
      headers: { 'Idempotency-Key': crypto.randomUUID() },
    })
    customerId.value = ''
    amount.value = ''
    paymentDate.value = ''
    depositAccountId.value = ''
    receivableAccountId.value = ''
    reference.value = ''
    showForm.value = false
    await Promise.all([loadPayments(), loadOutstandingInvoices()])
  } catch {
    createError.value = 'Failed to record payment — check the fields above.'
  } finally {
    creating.value = false
  }
}

function startAllocate(paymentId: string) {
  allocatingPaymentId.value = paymentId
  allocateInvoiceId.value = ''
  allocateAmount.value = ''
  actionError.value = null
}

async function onAllocate(paymentId: string) {
  actionError.value = null
  try {
    await request(`/api/v1/payments/${paymentId}/allocations`, {
      method: 'POST',
      body: { invoice_id: allocateInvoiceId.value, amount: normalizeMoney(allocateAmount.value) },
    })
    allocatingPaymentId.value = null
    await Promise.all([loadPayments(), loadOutstandingInvoices()])
  } catch {
    actionError.value = 'Failed to allocate — the amount may exceed the payment or invoice balance.'
  }
}

async function onDeallocate(allocationId: string) {
  actionError.value = null
  try {
    await request(`/api/v1/payment-allocations/${allocationId}`, { method: 'DELETE' })
    await Promise.all([loadPayments(), loadOutstandingInvoices()])
  } catch {
    actionError.value = 'Failed to remove the allocation.'
  }
}

function customerName(id: string): string {
  return customers.value.find((c) => c.id === id)?.name ?? id.slice(0, 8)
}

function invoiceLabel(invoice: OutstandingInvoice): string {
  return `${invoice.invoice_number ?? invoice.id.slice(0, 8)} — outstanding RM${invoice.outstanding_balance}`
}

onMounted(async () => {
  await Promise.all([loadPayments(), loadCustomers(), loadAccounts(), loadOutstandingInvoices()])
})
</script>

<template>
  <div>
    <PageHeader title="Payments">
      <template #actions>
        <AppButton variant="primary" @click="showForm = !showForm">
          <AppIcon name="plus" :size="15" /> Record payment
        </AppButton>
      </template>
    </PageHeader>

    <AppCard v-if="showForm" class="mb-6">
      <form class="flex flex-wrap items-end gap-3" @submit.prevent="onCreate">
        <div class="w-full sm:w-48">
          <AppField label="Customer">
            <AppSelect
              v-model="customerId"
              :options="customerOptions"
              placeholder="Select"
              required
            />
          </AppField>
        </div>
        <div class="w-full sm:w-28">
          <AppField label="Amount">
            <AppInput v-model="amount" placeholder="300.00" required />
          </AppField>
        </div>
        <div>
          <AppField label="Payment date">
            <AppInput v-model="paymentDate" type="date" required />
          </AppField>
        </div>
        <div class="w-full sm:w-48">
          <AppField label="Deposit account">
            <AppSelect
              v-model="depositAccountId"
              :options="depositAccountOptions"
              placeholder="Select an Asset account"
              required
            />
          </AppField>
        </div>
        <div class="w-full sm:w-48">
          <AppField label="Receivable account">
            <AppSelect
              v-model="receivableAccountId"
              :options="receivableAccountOptions"
              placeholder="Select an Asset account"
              required
            />
          </AppField>
        </div>
        <div class="w-full sm:w-32">
          <AppField label="Reference">
            <AppInput v-model="reference" placeholder="optional" />
          </AppField>
        </div>
        <AppButton type="submit" variant="primary" :disabled="creating">
          {{ creating ? 'Recording…' : 'Record' }}
        </AppButton>
        <p v-if="createError" class="w-full text-sm text-danger">{{ createError }}</p>
      </form>
    </AppCard>

    <p v-if="actionError" class="mb-3 text-sm text-danger">{{ actionError }}</p>

    <p v-if="loading" class="text-sm text-ink-tertiary">Loading…</p>
    <p v-else-if="error" class="text-sm text-danger">{{ error }}</p>
    <EmptyState v-else-if="payments.length === 0" title="No payments yet" />
    <div v-else class="space-y-2">
      <AppCard v-for="payment in payments" :key="payment.id">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <div class="min-w-0">
            <div class="flex items-center gap-2">
              <p class="text-lg font-semibold text-ink">RM{{ payment.amount }}</p>
              <AppBadge tone="neutral">Unallocated RM{{ payment.unallocated_amount }}</AppBadge>
            </div>
            <p class="text-sm text-ink-tertiary">
              {{ customerName(payment.customer_id) }} · {{ payment.payment_date }}
              <template v-if="payment.reference"> · {{ payment.reference }}</template>
            </p>
          </div>
          <div class="flex flex-wrap items-center gap-2">
            <a :href="pdfUrl(payment.id)" target="_blank" rel="noopener">
              <AppButton size="sm" variant="ghost">
                <AppIcon name="download" :size="14" /> Receipt
              </AppButton>
            </a>
            <AppButton
              v-if="payment.unallocated_amount !== '0.00'"
              size="sm"
              variant="primary"
              @click="startAllocate(payment.id)"
            >
              Allocate
            </AppButton>
          </div>
        </div>

        <ul
          v-if="(allocationsByPayment[payment.id] ?? []).length > 0"
          class="mt-3 space-y-1.5 border-t border-border pt-3"
        >
          <li
            v-for="allocation in allocationsByPayment[payment.id]"
            :key="allocation.id"
            class="flex items-center justify-between text-xs text-ink-secondary"
          >
            <span>Invoice {{ allocation.invoice_id.slice(0, 8) }} — RM{{ allocation.amount }}</span>
            <button
              type="button"
              class="text-ink-tertiary hover:text-danger"
              @click="onDeallocate(allocation.id)"
            >
              <AppIcon name="x" :size="13" />
            </button>
          </li>
        </ul>

        <div
          v-if="allocatingPaymentId === payment.id"
          class="mt-3 flex flex-wrap items-end gap-2 rounded-xl bg-surface-secondary p-3"
        >
          <div class="w-full sm:w-64">
            <AppField label="Invoice">
              <AppSelect
                v-model="allocateInvoiceId"
                :options="outstandingInvoiceOptions"
                placeholder="Select an outstanding invoice"
              />
            </AppField>
          </div>
          <div class="w-full sm:w-28">
            <AppField label="Amount">
              <AppInput v-model="allocateAmount" placeholder="100.00" />
            </AppField>
          </div>
          <AppButton size="sm" variant="primary" @click="onAllocate(payment.id)">Confirm</AppButton>
          <AppButton size="sm" variant="ghost" @click="allocatingPaymentId = null"
            >Cancel</AppButton
          >
        </div>
      </AppCard>
    </div>
  </div>
</template>
