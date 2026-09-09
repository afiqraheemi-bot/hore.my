<script setup lang="ts">
definePageMeta({ middleware: 'auth' })

const BUSINESS_TYPES = ['Retail', 'FoodAndBeverage', 'Services', 'Trading', 'Other']
const STATES = [
  'Johor',
  'Kedah',
  'Kelantan',
  'Melaka',
  'Negeri Sembilan',
  'Pahang',
  'Perak',
  'Perlis',
  'Pulau Pinang',
  'Sabah',
  'Sarawak',
  'Selangor',
  'Terengganu',
  'WP Kuala Lumpur',
  'WP Labuan',
  'WP Putrajaya',
]

const businessTypeOptions = BUSINESS_TYPES.map((t) => ({ value: t, label: t }))
const stateOptions = STATES.map((s) => ({ value: s, label: s }))
const monthOptions = Array.from({ length: 12 }, (_, i) => ({
  value: String(i + 1),
  label: new Date(2000, i, 1).toLocaleString('en-MY', { month: 'long' }),
}))

interface BusinessProfileData {
  legal_name: string
  registration_number: string | null
  tin: string | null
  address_line1: string
  address_line2: string | null
  city: string
  state: string
  postcode: string
  business_type: string
  financial_year_start_month: number
  timezone: string
  is_complete: boolean
}

const { request } = useApi()
const router = useRouter()

const legalName = ref('')
const registrationNumber = ref('')
const tin = ref('')
const addressLine1 = ref('')
const addressLine2 = ref('')
const city = ref('')
const state = ref('')
const postcode = ref('')
const businessType = ref('')
const financialYearStartMonth = ref('1')
const isComplete = ref<boolean | null>(null)
const submitting = ref(false)
const loading = ref(true)
const error = ref<string | null>(null)
const savedMessage = ref<string | null>(null)

async function loadProfile() {
  loading.value = true
  try {
    const data = await request<{ data: BusinessProfileData | null }>('/api/v1/business-profile')
    if (data.data) {
      legalName.value = data.data.legal_name
      registrationNumber.value = data.data.registration_number ?? ''
      tin.value = data.data.tin ?? ''
      addressLine1.value = data.data.address_line1
      addressLine2.value = data.data.address_line2 ?? ''
      city.value = data.data.city
      state.value = data.data.state
      postcode.value = data.data.postcode
      businessType.value = data.data.business_type
      financialYearStartMonth.value = String(data.data.financial_year_start_month)
      isComplete.value = data.data.is_complete
    }
  } finally {
    loading.value = false
  }
}

async function onSubmit() {
  error.value = null
  savedMessage.value = null
  submitting.value = true
  try {
    const data = await request<{ data: BusinessProfileData }>('/api/v1/business-profile', {
      method: 'PUT',
      body: {
        legal_name: legalName.value,
        registration_number: registrationNumber.value || null,
        tin: tin.value || null,
        address_line1: addressLine1.value,
        address_line2: addressLine2.value || null,
        city: city.value,
        state: state.value,
        postcode: postcode.value,
        business_type: businessType.value,
        financial_year_start_month: Number(financialYearStartMonth.value),
      },
    })
    isComplete.value = data.data.is_complete
    savedMessage.value = 'Business profile saved. A starter Chart of Accounts is ready.'
  } catch {
    error.value =
      'Failed to save the business profile — check every required field is filled in correctly.'
  } finally {
    submitting.value = false
  }
}

onMounted(loadProfile)
</script>

<template>
  <div class="mx-auto max-w-xl">
    <PageHeader
      title="Business profile"
      description="Saving this for the first time sets up a starter Chart of Accounts automatically."
    />

    <p v-if="loading" class="text-sm text-ink-tertiary">Loading…</p>
    <AppCard v-else>
      <form class="space-y-4" @submit.prevent="onSubmit">
        <p v-if="error" class="rounded-xl bg-danger-soft px-3 py-2 text-sm text-danger">
          {{ error }}
        </p>
        <p
          v-if="savedMessage"
          class="flex items-center gap-1.5 rounded-xl bg-success-soft px-3 py-2 text-sm text-success"
        >
          <AppIcon name="check" :size="14" /> {{ savedMessage }}
        </p>
        <p
          v-if="isComplete === false"
          class="rounded-xl bg-warning-soft px-3 py-2 text-sm text-warning"
        >
          Profile saved but not yet complete — registration number and TIN are still missing.
        </p>

        <AppField label="Legal / business name">
          <AppInput v-model="legalName" required />
        </AppField>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <AppField label="Registration number (SSM)">
            <AppInput v-model="registrationNumber" />
          </AppField>
          <AppField label="Tax Identification Number (TIN)">
            <AppInput v-model="tin" />
          </AppField>
        </div>
        <AppField label="Address line 1">
          <AppInput v-model="addressLine1" required />
        </AppField>
        <AppField label="Address line 2">
          <AppInput v-model="addressLine2" />
        </AppField>
        <div class="grid grid-cols-2 gap-4">
          <AppField label="City">
            <AppInput v-model="city" required />
          </AppField>
          <AppField label="Postcode">
            <AppInput v-model="postcode" required maxlength="5" />
          </AppField>
        </div>
        <AppField label="State">
          <AppSelect
            v-model="state"
            :options="stateOptions"
            placeholder="Select a state"
            required
          />
        </AppField>
        <AppField label="Business type">
          <AppSelect
            v-model="businessType"
            :options="businessTypeOptions"
            placeholder="Select a business type"
            required
          />
        </AppField>
        <AppField label="Financial year start month">
          <AppSelect v-model="financialYearStartMonth" :options="monthOptions" required />
        </AppField>
        <AppButton type="submit" variant="primary" :disabled="submitting" class="w-full">
          {{ submitting ? 'Saving…' : 'Save business profile' }}
        </AppButton>
      </form>
    </AppCard>

    <button
      type="button"
      class="mt-3 text-sm text-ink-tertiary hover:text-ink hover:underline"
      @click="router.push('/')"
    >
      Skip for now — go to Work Queue
    </button>
  </div>
</template>
