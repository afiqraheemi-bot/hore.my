<script setup lang="ts">
definePageMeta({ middleware: 'auth' })

interface Account {
  id: string
  account_code: string
  account_name: string
  account_type: string
  active: boolean
  posting_eligible: boolean
}

const TYPE_TONE: Record<string, 'success' | 'danger' | 'accent' | 'warning' | 'neutral'> = {
  Asset: 'success',
  Liability: 'danger',
  Equity: 'accent',
  Revenue: 'success',
  Expense: 'warning',
}

const { request } = useApi()

const accounts = ref<Account[]>([])
const loading = ref(true)
const error = ref<string | null>(null)

const showForm = ref(false)
const accountCode = ref('')
const accountName = ref('')
const accountType = ref('Asset')
const creating = ref(false)
const createError = ref<string | null>(null)

const accountTypeOptions = ['Asset', 'Liability', 'Equity', 'Revenue', 'Expense'].map((t) => ({
  value: t,
  label: t,
}))

async function loadAccounts() {
  loading.value = true
  error.value = null
  try {
    const data = await request<{ data: Account[] }>('/api/v1/accounts')
    accounts.value = data.data
  } catch {
    error.value = 'Failed to load accounts.'
  } finally {
    loading.value = false
  }
}

async function onCreate() {
  createError.value = null
  creating.value = true
  try {
    await request('/api/v1/accounts', {
      method: 'POST',
      body: {
        account_code: accountCode.value,
        account_name: accountName.value,
        account_type: accountType.value,
      },
    })
    accountCode.value = ''
    accountName.value = ''
    showForm.value = false
    await loadAccounts()
  } catch {
    createError.value = 'Failed to create account — the code may already be in use.'
  } finally {
    creating.value = false
  }
}

onMounted(loadAccounts)
</script>

<template>
  <div>
    <PageHeader title="Chart of Accounts" description="Every Account your Journals can post to.">
      <template #actions>
        <AppButton variant="primary" @click="showForm = !showForm">
          <AppIcon name="plus" :size="15" /> New account
        </AppButton>
      </template>
    </PageHeader>

    <AppCard v-if="showForm" class="mb-6">
      <form class="flex flex-wrap items-end gap-3" @submit.prevent="onCreate">
        <div class="w-28">
          <AppField label="Code">
            <AppInput v-model="accountCode" required />
          </AppField>
        </div>
        <div class="w-56">
          <AppField label="Name">
            <AppInput v-model="accountName" required />
          </AppField>
        </div>
        <div class="w-40">
          <AppField label="Type">
            <AppSelect v-model="accountType" :options="accountTypeOptions" />
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
    <EmptyState
      v-else-if="accounts.length === 0"
      title="No accounts yet"
      description="Add your first account to start recording transactions."
    />
    <div v-else class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
      <AppCard v-for="account in accounts" :key="account.id">
        <div class="flex items-start justify-between gap-2">
          <div class="min-w-0">
            <p class="truncate text-sm font-medium text-ink">{{ account.account_name }}</p>
            <p class="text-xs text-ink-tertiary">{{ account.account_code }}</p>
          </div>
          <AppBadge :tone="TYPE_TONE[account.account_type] ?? 'neutral'">{{
            account.account_type
          }}</AppBadge>
        </div>
        <div class="mt-3 flex items-center gap-1.5">
          <span
            class="h-1.5 w-1.5 rounded-full"
            :class="account.active ? 'bg-success' : 'bg-ink-tertiary'"
          />
          <span class="text-xs text-ink-tertiary">{{
            account.active ? 'Active' : 'Inactive'
          }}</span>
        </div>
      </AppCard>
    </div>
  </div>
</template>
