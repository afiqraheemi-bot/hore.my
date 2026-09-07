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

const name = ref('')
const email = ref('')
const phone = ref('')
const creating = ref(false)
const createError = ref<string | null>(null)

const editingId = ref<string | null>(null)
const editName = ref('')
const editEmail = ref('')
const editPhone = ref('')
const editAddress = ref('')
const editTaxId = ref('')
const editNotes = ref('')
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
  editAddress.value = customer.address ?? ''
  editTaxId.value = customer.tax_identification_number ?? ''
  editNotes.value = customer.notes ?? ''
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
        address: editAddress.value || undefined,
        tax_identification_number: editTaxId.value || undefined,
        notes: editNotes.value || undefined,
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
  <div class="space-y-6">
    <h1 class="text-xl font-semibold">Customers</h1>

    <form
      class="flex flex-wrap items-end gap-3 rounded border border-gray-200 bg-white p-4"
      @submit.prevent="onCreate"
    >
      <div>
        <label class="block text-xs font-medium text-gray-500">Name</label>
        <input
          v-model="name"
          required
          placeholder="Kedai Runcit Aminah"
          class="mt-1 w-56 rounded border border-gray-300 px-2 py-1"
        />
      </div>
      <div>
        <label class="block text-xs font-medium text-gray-500">Email</label>
        <input
          v-model="email"
          type="email"
          placeholder="optional"
          class="mt-1 w-56 rounded border border-gray-300 px-2 py-1"
        />
      </div>
      <div>
        <label class="block text-xs font-medium text-gray-500">Phone</label>
        <input
          v-model="phone"
          placeholder="optional"
          class="mt-1 w-40 rounded border border-gray-300 px-2 py-1"
        />
      </div>
      <button
        type="submit"
        :disabled="creating"
        class="rounded bg-gray-900 px-3 py-1.5 text-sm text-white disabled:opacity-50"
      >
        {{ creating ? 'Adding…' : 'Add customer' }}
      </button>
      <p v-if="createError" class="w-full text-sm text-red-700">{{ createError }}</p>
    </form>

    <p v-if="loading" class="text-sm text-gray-500">Loading…</p>
    <p v-else-if="error" class="text-sm text-red-700">{{ error }}</p>
    <table v-else class="w-full text-left text-sm">
      <thead>
        <tr class="border-b border-gray-200 text-gray-500">
          <th class="py-2">Name</th>
          <th class="py-2">Email</th>
          <th class="py-2">Phone</th>
          <th class="py-2">Status</th>
          <th class="py-2"></th>
        </tr>
      </thead>
      <tbody>
        <template v-for="customer in customers" :key="customer.id">
          <tr v-if="editingId !== customer.id" class="border-b border-gray-100">
            <td class="py-2">{{ customer.name }}</td>
            <td class="py-2">{{ customer.email ?? '—' }}</td>
            <td class="py-2">{{ customer.phone ?? '—' }}</td>
            <td class="py-2">{{ customer.active ? 'Active' : 'Inactive' }}</td>
            <td class="py-2 text-right">
              <button
                type="button"
                class="text-xs text-gray-600 underline hover:text-gray-900"
                @click="startEdit(customer)"
              >
                Edit
              </button>
            </td>
          </tr>
          <tr v-else class="border-b border-gray-100 bg-gray-50">
            <td class="py-2 pr-2">
              <input v-model="editName" class="w-full rounded border border-gray-300 px-2 py-1" />
            </td>
            <td class="py-2 pr-2">
              <input
                v-model="editEmail"
                type="email"
                class="w-full rounded border border-gray-300 px-2 py-1"
              />
            </td>
            <td class="py-2 pr-2">
              <input v-model="editPhone" class="w-full rounded border border-gray-300 px-2 py-1" />
            </td>
            <td class="py-2 pr-2">
              <label class="flex items-center gap-1 text-xs">
                <input v-model="editActive" type="checkbox" />
                Active
              </label>
            </td>
            <td class="space-x-2 py-2 text-right">
              <button
                type="button"
                :disabled="saving"
                class="rounded bg-gray-900 px-2 py-1 text-xs text-white disabled:opacity-50"
                @click="onSaveEdit(customer.id)"
              >
                Save
              </button>
              <button
                type="button"
                class="rounded border border-gray-300 px-2 py-1 text-xs"
                @click="cancelEdit"
              >
                Cancel
              </button>
            </td>
          </tr>
        </template>
        <tr v-if="customers.length === 0">
          <td colspan="5" class="py-4 text-center text-gray-400">No customers yet.</td>
        </tr>
      </tbody>
    </table>
    <p v-if="editError" class="text-sm text-red-700">{{ editError }}</p>
  </div>
</template>
