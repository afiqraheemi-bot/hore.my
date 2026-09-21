<script setup lang="ts">
/**
 * Read-only Invoice detail — mirrors `quotations/[id].vue`'s own
 * list+detail split, and additionally surfaces payment history and
 * the live outstanding balance for an Issued Invoice (M21 data the
 * backend now resolves in `InvoiceController::show()`, previously
 * visible nowhere in the UI).
 */
definePageMeta({ middleware: 'auth', key: (route) => route.fullPath })
useHead({ title: 'Invoice' })

interface InvoiceLine {
  description: string
  quantity: number
  unit_price: string
  line_amount: string
}

interface InvoicePayment {
  allocation_id: string
  payment_id: string
  amount: string
  payment_date: string
  reference: string | null
}

interface InvoiceDetail {
  id: string
  customer_id: string
  invoice_number: string | null
  status: 'Draft' | 'Issued'
  issue_date: string | null
  due_date: string
  total_amount: string
  lines: InvoiceLine[]
  outstanding_balance?: string
  payments?: InvoicePayment[]
}

interface Customer {
  id: string
  name: string
}

const { request } = useApi()
const route = useRoute()
const invoiceId = route.params.id as string

/** Mirrors `index.vue`'s own `pdfUrl()` — a same-site GET carries the
 * Sanctum SPA session cookie, so no blob/fetch plumbing is needed. */
function pdfUrl(): string {
  const config = useRuntimeConfig()
  const port = config.public.apiPort as string
  return `${window.location.protocol}//${window.location.hostname}:${port}/api/v1/invoices/${invoiceId}/pdf`
}

const invoice = ref<InvoiceDetail | null>(null)
const customers = ref<Customer[]>([])
const loading = ref(true)
const error = ref<string | null>(null)

function customerName(id: string): string {
  return customers.value.find((c) => c.id === id)?.name ?? id.slice(0, 8)
}

onMounted(async () => {
  loading.value = true
  error.value = null
  try {
    const [detail, customerData] = await Promise.all([
      request<InvoiceDetail>(`/api/v1/invoices/${invoiceId}`),
      request<{ data: Customer[] }>('/api/v1/customers'),
    ])
    invoice.value = detail
    customers.value = customerData.data
  } catch {
    error.value = 'Failed to load this invoice.'
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div>
    <PageHeader title="Invoice">
      <template #actions>
        <NuxtLink to="/invoices">
          <AppButton variant="ghost">
            <AppIcon name="chevron-left" :size="15" /> Back to Invoices
          </AppButton>
        </NuxtLink>
      </template>
    </PageHeader>

    <p v-if="loading" class="text-sm text-ink-tertiary">Loading…</p>
    <p v-else-if="error || !invoice" class="text-sm text-danger">
      {{ error ?? 'Invoice not found.' }}
    </p>

    <div v-else class="space-y-6">
      <AppCard>
        <div class="flex flex-wrap items-center justify-between gap-3">
          <div>
            <div class="flex items-center gap-2">
              <p class="text-lg font-semibold text-ink">{{ invoice.invoice_number ?? 'Draft' }}</p>
              <AppBadge :tone="invoice.status === 'Issued' ? 'success' : 'neutral'">{{
                invoice.status
              }}</AppBadge>
            </div>
            <p class="mt-0.5 text-sm text-ink-tertiary">
              {{ customerName(invoice.customer_id) }} · Due {{ invoice.due_date }}
            </p>
          </div>
          <div class="flex items-center gap-3">
            <p class="text-2xl font-semibold text-ink">RM{{ invoice.total_amount }}</p>
            <a :href="pdfUrl()" target="_blank" rel="noopener">
              <AppButton size="sm" variant="ghost">
                <AppIcon name="download" :size="14" /> PDF
              </AppButton>
            </a>
          </div>
        </div>
      </AppCard>

      <AppCard>
        <p class="mb-3 text-sm font-medium text-ink-secondary">Lines</p>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="border-b border-border text-left text-xs text-ink-tertiary">
                <th class="pb-2 font-medium">Description</th>
                <th class="pb-2 text-right font-medium">Qty</th>
                <th class="pb-2 text-right font-medium">Unit price</th>
                <th class="pb-2 text-right font-medium">Amount</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="(line, i) in invoice.lines"
                :key="i"
                class="border-b border-border last:border-0"
              >
                <td class="py-2 text-ink">{{ line.description }}</td>
                <td class="py-2 text-right text-ink-tertiary">{{ line.quantity }}</td>
                <td class="py-2 text-right text-ink-tertiary">RM{{ line.unit_price }}</td>
                <td class="py-2 text-right text-ink">RM{{ line.line_amount }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </AppCard>

      <AppCard v-if="invoice.status === 'Issued'">
        <div class="mb-3 flex items-center justify-between">
          <p class="text-sm font-medium text-ink-secondary">Payments</p>
          <p class="text-sm text-ink-tertiary">
            Outstanding:
            <span class="font-semibold text-ink">RM{{ invoice.outstanding_balance }}</span>
          </p>
        </div>
        <EmptyState
          v-if="!invoice.payments || invoice.payments.length === 0"
          title="No payments recorded yet"
        />
        <div v-else class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="border-b border-border text-left text-xs text-ink-tertiary">
                <th class="pb-2 font-medium">Date</th>
                <th class="pb-2 font-medium">Reference</th>
                <th class="pb-2 text-right font-medium">Amount</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="payment in invoice.payments"
                :key="payment.allocation_id"
                class="border-b border-border last:border-0"
              >
                <td class="py-2 text-ink-tertiary">{{ payment.payment_date }}</td>
                <td class="py-2 text-ink-tertiary">{{ payment.reference ?? '—' }}</td>
                <td class="py-2 text-right text-ink">RM{{ payment.amount }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </AppCard>
    </div>
  </div>
</template>
