<script setup lang="ts">
definePageMeta({ middleware: 'auth' })

interface Customer {
  id: string
  name: string
  email: string | null
  phone: string | null
  address: string | null
  tax_identification_number: string | null
  notes: string | null
  active: boolean
}

const { request } = useApi()

const customers = ref<Customer[]>([])
const loading = ref(true)
const error = ref<string | null>(null)

const showForm = ref(false)
const name = ref('')
const email = ref('')
const phone = ref('')
const creating = ref(false)
const createError = ref<string | null>(null)

const editingId = ref<string | null>(null)
const editName = ref('')
const editEmail = ref('')
const editPhone = ref('')
const editActive = ref(true)
const saving = ref(false)
const editError = ref<string | null>(null)

async function loadCustomers() {
  loading.value = true
  error.value = null
  try {
    const data = await request<{ data: Customer[] }>('/api/v1/customers')
    customers.value = data.data
  } catch {
    error.value = 'Failed to load customers.'
  } finally {
    loading.value = false
  }
}

async function onCreate() {
  createError.value = null
  creating.value = true
  try {
    await request('/api/v1/customers', {
      method: 'POST',
      body: {
        name: name.value,
        email: email.value || undefined,
        phone: phone.value || undefined,
      },
    })
    name.value = ''
    email.value = ''
    phone.value = ''
    showForm.value = false
    await loadCustomers()
  } catch {
    createError.value = 'Failed to create customer — check the name and email.'
  } finally {
    creating.value = false
  }
}

function startEdit(customer: Customer) {
  editingId.value = customer.id
  editName.value = customer.name
  editEmail.value = customer.email ?? ''
  editPhone.value = customer.phone ?? ''
  editActive.value = customer.active
  editError.value = null
}

function cancelEdit() {
  editingId.value = null
}

async function onSaveEdit(customerId: string) {
  editError.value = null
  saving.value = true
  try {
    await request(`/api/v1/customers/${customerId}`, {
      method: 'PUT',
      body: {
        name: editName.value,
        email: editEmail.value || undefined,
        phone: editPhone.value || undefined,
        active: editActive.value,
      },
    })
    editingId.value = null
    await loadCustomers()
  } catch {
    editError.value = 'Failed to save changes — check the name and email.'
  } finally {
    saving.value = false
  }
}

onMounted(loadCustomers)
</script>

<template>
  <div>
    <PageHeader title="Customers">
      <template #actions>
        <AppButton variant="primary" @click="showForm = !showForm">
          <AppIcon name="plus" :size="15" /> New customer
        </AppButton>
      </template>
    </PageHeader>

    <AppCard v-if="showForm" class="mb-6">
      <form class="flex flex-wrap items-end gap-3" @submit.prevent="onCreate">
        <div class="w-56">
          <AppField label="Name">
            <AppInput v-model="name" required placeholder="Kedai Runcit Aminah" />
          </AppField>
        </div>
        <div class="w-56">
          <AppField label="Email">
            <AppInput v-model="email" type="email" placeholder="optional" />
          </AppField>
        </div>
        <div class="w-40">
          <AppField label="Phone">
            <AppInput v-model="phone" placeholder="optional" />
          </AppField>
        </div>
        <AppButton type="submit" variant="primary" :disabled="creating">
          {{ creating ? 'Adding…' : 'Add' }}
        </AppButton>
        <p v-if="createError" class="w-full text-sm text-danger">{{ createError }}</p>
      </form>
    </AppCard>

    <p v-if="loading" class="text-sm text-ink-tertiary">Loading…</p>
    <p v-else-if="error" class="text-sm text-danger">{{ error }}</p>
    <EmptyState v-else-if="customers.length === 0" title="No customers yet" />
    <div v-else class="space-y-2">
      <AppCard v-for="customer in customers" :key="customer.id">
        <template v-if="editingId !== customer.id">
          <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="min-w-0">
              <p class="truncate text-sm font-medium text-ink">{{ customer.name }}</p>
              <p class="text-xs text-ink-tertiary">
                {{ customer.email ?? 'No email'
                }}<template v-if="customer.phone"> · {{ customer.phone }}</template>
              </p>
            </div>
            <div class="flex items-center gap-2">
              <AppBadge :tone="customer.active ? 'success' : 'neutral'">
                {{ customer.active ? 'Active' : 'Inactive' }}
              </AppBadge>
              <AppButton size="sm" variant="ghost" @click="startEdit(customer)">Edit</AppButton>
            </div>
          </div>
        </template>
        <template v-else>
          <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <AppField label="Name">
              <AppInput v-model="editName" />
            </AppField>
            <AppField label="Email">
              <AppInput v-model="editEmail" type="email" />
            </AppField>
            <AppField label="Phone">
              <AppInput v-model="editPhone" />
            </AppField>
            <label class="flex items-center gap-2 self-end pb-2 text-sm text-ink-secondary">
              <input
                v-model="editActive"
                type="checkbox"
                class="h-4 w-4 rounded border-border text-accent focus:ring-accent"
              />
              Active
            </label>
          </div>
          <p v-if="editError" class="mt-2 text-sm text-danger">{{ editError }}</p>
          <div class="mt-3 flex items-center gap-2">
            <AppButton
              size="sm"
              variant="primary"
              :disabled="saving"
              @click="onSaveEdit(customer.id)"
            >
              {{ saving ? 'Saving…' : 'Save' }}
            </AppButton>
            <AppButton size="sm" variant="ghost" @click="cancelEdit">Cancel</AppButton>
          </div>
        </template>
      </AppCard>
    </div>
  </div>
</template>
