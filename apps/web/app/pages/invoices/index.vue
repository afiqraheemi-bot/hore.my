<script setup lang="ts">
definePageMeta({ middleware: 'auth' })

interface InvoiceLine {
  description: string
  quantity: number
  unit_price: string
  line_amount: string
}

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

const invoices = ref<Invoice[]>([])
const customers = ref<Customer[]>([])
const accounts = ref<Account[]>([])
const loading = ref(true)
const error = ref<string | null>(null)

const customerId = ref('')
const dueDate = ref('')
const receivableAccountId = ref('')
const revenueAccountId = ref('')
const lineDrafts = ref<{ description: string; quantity: number; unit_price: string }[]>([
  { description: '', quantity: 1, unit_price: '' },
])
const creating = ref(false)
const createError = ref<string | null>(null)

const issuingId = ref<string | null>(null)
const actionError = ref<string | null>(null)

const receivableAccounts = computed(() => accounts.value.filter((a) => a.account_type === 'Asset'))
const revenueAccounts = computed(() => accounts.value.filter((a) => a.account_type === 'Revenue'))

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
  lineDrafts.value.push({ description: '', quantity: 1, unit_price: '' })
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
        lines: lineDrafts.value.filter((l) => l.description && l.unit_price),
      },
    })
    customerId.value = ''
    dueDate.value = ''
    receivableAccountId.value = ''
    revenueAccountId.value = ''
    lineDrafts.value = [{ description: '', quantity: 1, unit_price: '' }]
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
      headers: { 'Idempotency-Key': crypto.randomUUID() },
    })
    await loadInvoices()
  } catch {
    actionError.value = 'Failed to issue the invoice — it may be empty or already issued.'
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

onMounted(async () => {
  await Promise.all([loadInvoices(), loadCustomers(), loadAccounts()])
})
</script>

<template>
  <div class="space-y-6">
    <h1 class="text-xl font-semibold">Invoices</h1>

    <form
      class="space-y-3 rounded border border-gray-200 bg-white p-4"
      @submit.prevent="onCreate"
    >
      <div class="flex flex-wrap items-end gap-3">
        <div>
          <label class="block text-xs font-medium text-gray-500">Customer</label>
          <select v-model="customerId" required class="mt-1 w-56 rounded border border-gray-300 px-2 py-1">
            <option value="" disabled>Select a customer</option>
            <option v-for="c in customers" :key="c.id" :value="c.id">{{ c.name }}</option>
          </select>
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-500">Due date</label>
          <input v-model="dueDate" type="date" required class="mt-1 rounded border border-gray-300 px-2 py-1" />
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-500">Receivable account</label>
          <select v-model="receivableAccountId" required class="mt-1 w-56 rounded border border-gray-300 px-2 py-1">
            <option value="" disabled>Select an Asset account</option>
            <option v-for="a in receivableAccounts" :key="a.id" :value="a.id">
              {{ a.account_code }} — {{ a.account_name }}
            </option>
          </select>
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-500">Revenue account</label>
          <select v-model="revenueAccountId" required class="mt-1 w-56 rounded border border-gray-300 px-2 py-1">
            <option value="" disabled>Select a Revenue account</option>
            <option v-for="a in revenueAccounts" :key="a.id" :value="a.id">
              {{ a.account_code }} — {{ a.account_name }}
            </option>
          </select>
        </div>
      </div>

      <div class="space-y-2">
        <label class="block text-xs font-medium text-gray-500">Lines</label>
        <div v-for="(line, i) in lineDrafts" :key="i" class="flex items-end gap-2">
          <input
            v-model="line.description"
            placeholder="Description"
            class="w-64 rounded border border-gray-300 px-2 py-1 text-sm"
          />
          <input
            v-model.number="line.quantity"
            type="number"
            min="1"
            placeholder="Qty"
            class="w-20 rounded border border-gray-300 px-2 py-1 text-sm"
          />
          <input
            v-model="line.unit_price"
            placeholder="Unit price"
            class="w-28 rounded border border-gray-300 px-2 py-1 text-sm"
          />
          <button
            type="button"
            class="text-xs text-gray-500 underline"
            @click="removeLine(i)"
          >
            Remove
          </button>
        </div>
        <button type="button" class="text-xs text-gray-700 underline" @click="addLine">+ Add line</button>
      </div>

      <button
        type="submit"
        :disabled="creating"
        class="rounded bg-gray-900 px-3 py-1.5 text-sm text-white disabled:opacity-50"
      >
        {{ creating ? 'Saving…' : 'Save as Draft' }}
      </button>
      <p v-if="createError" class="text-sm text-red-700">{{ createError }}</p>
    </form>

    <p v-if="actionError" class="text-sm text-red-700">{{ actionError }}</p>

    <p v-if="loading" class="text-sm text-gray-500">Loading…</p>
    <p v-else-if="error" class="text-sm text-red-700">{{ error }}</p>
    <div v-else class="space-y-3">
      <div
        v-for="invoice in invoices"
        :key="invoice.id"
        class="rounded border border-gray-200 bg-white p-4 text-sm"
      >
        <div class="flex flex-wrap items-center justify-between gap-2">
          <div>
            <span class="font-medium">{{ invoice.invoice_number ?? 'Draft' }}</span>
            <span class="ml-2 text-gray-600">{{ customerName(invoice.customer_id) }}</span>
            <span class="ml-2 rounded bg-gray-100 px-1.5 py-0.5 text-xs font-medium text-gray-700">
              {{ invoice.status }}
            </span>
            <span class="ml-2 text-gray-500">Due {{ invoice.due_date }}</span>
            <span class="ml-2 font-medium">RM{{ invoice.total_amount }}</span>
          </div>
          <div class="flex gap-2">
            <button
              v-if="invoice.status === 'Draft'"
              type="button"
              :disabled="issuingId === invoice.id"
              class="rounded bg-gray-900 px-2 py-1 text-xs text-white disabled:opacity-50"
              @click="onIssue(invoice.id)"
            >
              Issue
            </button>
            <button
              v-if="invoice.status === 'Draft'"
              type="button"
              class="rounded border border-gray-300 px-2 py-1 text-xs"
              @click="onDelete(invoice.id)"
            >
              Delete
            </button>
          </div>
        </div>
        <ul v-if="invoice.lines.length > 0" class="mt-2 space-y-1 text-xs text-gray-600">
          <li v-for="(line, i) in invoice.lines" :key="i">
            {{ line.description }} — {{ line.quantity }} × RM{{ line.unit_price }} = RM{{ line.line_amount }}
          </li>
        </ul>
      </div>
      <p v-if="invoices.length === 0" class="text-sm text-gray-400">No invoices yet.</p>
    </div>
  </div>
</template>
