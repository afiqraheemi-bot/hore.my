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

const payments = ref<Payment[]>([])
const customers = ref<Customer[]>([])
const accounts = ref<Account[]>([])
const outstandingInvoices = ref<OutstandingInvoice[]>([])
const allocationsByPayment = ref<Record<string, Allocation[]>>({})
const loading = ref(true)
const error = ref<string | null>(null)

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

const depositAccounts = computed(() => accounts.value.filter((a) => a.account_type === 'Asset'))
const receivableAccounts = computed(() => accounts.value.filter((a) => a.account_type === 'Asset'))

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
        amount: amount.value,
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
      body: { invoice_id: allocateInvoiceId.value, amount: allocateAmount.value },
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
  <div class="space-y-6">
    <h1 class="text-xl font-semibold">Payments</h1>

    <form
      class="flex flex-wrap items-end gap-3 rounded border border-gray-200 bg-white p-4"
      @submit.prevent="onCreate"
    >
      <div>
        <label class="block text-xs font-medium text-gray-500">Customer</label>
        <select v-model="customerId" required class="mt-1 w-48 rounded border border-gray-300 px-2 py-1">
          <option value="" disabled>Select</option>
          <option v-for="c in customers" :key="c.id" :value="c.id">{{ c.name }}</option>
        </select>
      </div>
      <div>
        <label class="block text-xs font-medium text-gray-500">Amount</label>
        <input v-model="amount" placeholder="300.00" required class="mt-1 w-28 rounded border border-gray-300 px-2 py-1" />
      </div>
      <div>
        <label class="block text-xs font-medium text-gray-500">Payment date</label>
        <input v-model="paymentDate" type="date" required class="mt-1 rounded border border-gray-300 px-2 py-1" />
      </div>
      <div>
        <label class="block text-xs font-medium text-gray-500">Deposit account</label>
        <select v-model="depositAccountId" required class="mt-1 w-48 rounded border border-gray-300 px-2 py-1">
          <option value="" disabled>Select an Asset account</option>
          <option v-for="a in depositAccounts" :key="a.id" :value="a.id">{{ a.account_code }} — {{ a.account_name }}</option>
        </select>
      </div>
      <div>
        <label class="block text-xs font-medium text-gray-500">Receivable account</label>
        <select v-model="receivableAccountId" required class="mt-1 w-48 rounded border border-gray-300 px-2 py-1">
          <option value="" disabled>Select an Asset account</option>
          <option v-for="a in receivableAccounts" :key="a.id" :value="a.id">{{ a.account_code }} — {{ a.account_name }}</option>
        </select>
      </div>
      <div>
        <label class="block text-xs font-medium text-gray-500">Reference</label>
        <input v-model="reference" placeholder="optional" class="mt-1 w-32 rounded border border-gray-300 px-2 py-1" />
      </div>
      <button
        type="submit"
        :disabled="creating"
        class="rounded bg-gray-900 px-3 py-1.5 text-sm text-white disabled:opacity-50"
      >
        {{ creating ? 'Recording…' : 'Record payment' }}
      </button>
      <p v-if="createError" class="w-full text-sm text-red-700">{{ createError }}</p>
    </form>

    <p v-if="actionError" class="text-sm text-red-700">{{ actionError }}</p>

    <p v-if="loading" class="text-sm text-gray-500">Loading…</p>
    <p v-else-if="error" class="text-sm text-red-700">{{ error }}</p>
    <div v-else class="space-y-3">
      <div
        v-for="payment in payments"
        :key="payment.id"
        class="rounded border border-gray-200 bg-white p-4 text-sm"
      >
        <div class="flex flex-wrap items-center justify-between gap-2">
          <div>
            <span class="font-medium">RM{{ payment.amount }}</span>
            <span class="ml-2 text-gray-600">{{ customerName(payment.customer_id) }}</span>
            <span class="ml-2 text-gray-500">{{ payment.payment_date }}</span>
            <span v-if="payment.reference" class="ml-2 text-gray-400">({{ payment.reference }})</span>
            <span class="ml-2 rounded bg-gray-100 px-1.5 py-0.5 text-xs font-medium text-gray-700">
              Unallocated: RM{{ payment.unallocated_amount }}
            </span>
          </div>
          <button
            v-if="payment.unallocated_amount !== '0.00'"
            type="button"
            class="rounded bg-gray-900 px-2 py-1 text-xs text-white"
            @click="startAllocate(payment.id)"
          >
            Allocate
          </button>
        </div>

        <ul v-if="(allocationsByPayment[payment.id] ?? []).length > 0" class="mt-2 space-y-1 text-xs text-gray-600">
          <li v-for="allocation in allocationsByPayment[payment.id]" :key="allocation.id" class="flex items-center gap-2">
            <span>Invoice {{ allocation.invoice_id.slice(0, 8) }} — RM{{ allocation.amount }}</span>
            <button type="button" class="text-red-600 underline" @click="onDeallocate(allocation.id)">Remove</button>
          </li>
        </ul>

        <div v-if="allocatingPaymentId === payment.id" class="mt-3 flex items-end gap-2 rounded border border-gray-200 bg-gray-50 p-2">
          <div>
            <label class="block text-xs font-medium text-gray-500">Invoice</label>
            <select v-model="allocateInvoiceId" class="mt-1 w-56 rounded border border-gray-300 px-2 py-1 text-xs">
              <option value="" disabled>Select an outstanding invoice</option>
              <option v-for="inv in outstandingInvoices" :key="inv.id" :value="inv.id">{{ invoiceLabel(inv) }}</option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-500">Amount</label>
            <input v-model="allocateAmount" placeholder="100.00" class="mt-1 w-24 rounded border border-gray-300 px-2 py-1 text-xs" />
          </div>
          <button type="button" class="rounded bg-gray-900 px-2 py-1 text-xs text-white" @click="onAllocate(payment.id)">
            Confirm
          </button>
          <button type="button" class="rounded border border-gray-300 px-2 py-1 text-xs" @click="allocatingPaymentId = null">
            Cancel
          </button>
        </div>
      </div>
      <p v-if="payments.length === 0" class="text-sm text-gray-400">No payments yet.</p>
    </div>
  </div>
</template>
