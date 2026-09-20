<script setup lang="ts">
/**
 * Read-only Quotation detail (AETS-016) — mirrors `tasks/[id].vue`'s
 * own list+detail split: the list card keeps every lifecycle action
 * (Send/Accept/Reject/Convert/Delete/PDF) for one-click use, this page
 * exists only to show the full line-item breakdown without bloating
 * every card in a long list.
 */
definePageMeta({ middleware: 'auth', key: (route) => route.fullPath })

interface QuotationLine {
  description: string
  quantity: number
  unit_price: string
  line_amount: string
}

interface QuotationDetail {
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

const statusTone: Record<
  QuotationDetail['status'],
  'neutral' | 'success' | 'danger' | 'warning' | 'accent'
> = {
  Draft: 'neutral',
  Sent: 'warning',
  Accepted: 'accent',
  Rejected: 'danger',
  Converted: 'success',
}

const { request } = useApi()
const route = useRoute()
const quotationId = route.params.id as string

const quotation = ref<QuotationDetail | null>(null)
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
      request<QuotationDetail>(`/api/v1/quotations/${quotationId}`),
      request<{ data: Customer[] }>('/api/v1/customers'),
    ])
    quotation.value = detail
    customers.value = customerData.data
  } catch {
    error.value = 'Failed to load this quotation.'
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div>
    <PageHeader title="Quotation">
      <template #actions>
        <NuxtLink to="/quotations">
          <AppButton variant="ghost">
            <AppIcon name="chevron-left" :size="15" /> Back to Quotations
          </AppButton>
        </NuxtLink>
      </template>
    </PageHeader>

    <p v-if="loading" class="text-sm text-ink-tertiary">Loading…</p>
    <p v-else-if="error || !quotation" class="text-sm text-danger">
      {{ error ?? 'Quotation not found.' }}
    </p>

    <div v-else class="space-y-6">
      <AppCard>
        <div class="flex flex-wrap items-center justify-between gap-3">
          <div>
            <div class="flex items-center gap-2">
              <p class="text-lg font-semibold text-ink">
                {{ quotation.quotation_number ?? 'Draft quotation' }}
              </p>
              <AppBadge :tone="statusTone[quotation.status]">{{ quotation.status }}</AppBadge>
            </div>
            <p class="mt-0.5 text-sm text-ink-tertiary">
              {{ customerName(quotation.customer_id) }} · Valid until {{ quotation.valid_until }}
            </p>
          </div>
          <p class="text-2xl font-semibold text-ink">RM{{ quotation.total_amount }}</p>
        </div>

        <NuxtLink
          v-if="quotation.status === 'Converted' && quotation.converted_invoice_id"
          :to="`/invoices?highlight=${quotation.converted_invoice_id}`"
          class="mt-3 inline-block text-sm text-accent underline"
        >
          View invoice
        </NuxtLink>
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
                v-for="(line, i) in quotation.lines"
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
    </div>
  </div>
</template>
