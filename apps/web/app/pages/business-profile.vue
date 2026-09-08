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
const financialYearStartMonth = ref(1)
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
      financialYearStartMonth.value = data.data.financial_year_start_month
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
        financial_year_start_month: financialYearStartMonth.value,
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
  <div class="max-w-lg space-y-4">
    <h1 class="text-xl font-semibold">Business profile</h1>
    <p class="text-sm text-gray-600">
      Tell us about your business. Saving this for the first time sets up a starter Chart of
      Accounts automatically.
    </p>

    <div v-if="loading" class="text-sm text-gray-500">Loading…</div>
    <form
      v-else
      class="space-y-3 rounded border border-gray-200 bg-white p-4"
      @submit.prevent="onSubmit"
    >
      <p v-if="error" class="rounded bg-red-50 px-3 py-2 text-sm text-red-700">{{ error }}</p>
      <p v-if="savedMessage" class="rounded bg-green-50 px-3 py-2 text-sm text-green-700">
        {{ savedMessage }}
      </p>
      <p v-if="isComplete === false" class="rounded bg-yellow-50 px-3 py-2 text-sm text-yellow-800">
        Profile saved but not yet complete — registration number and TIN are still missing.
      </p>

      <div>
        <label class="block text-sm font-medium text-gray-700">Legal / business name</label>
        <input
          v-model="legalName"
          required
          class="mt-1 w-full rounded border border-gray-300 px-3 py-2"
        />
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700">Registration number (SSM)</label>
        <input
          v-model="registrationNumber"
          class="mt-1 w-full rounded border border-gray-300 px-3 py-2"
        />
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700"
          >Tax Identification Number (TIN)</label
        >
        <input v-model="tin" class="mt-1 w-full rounded border border-gray-300 px-3 py-2" />
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700">Address line 1</label>
        <input
          v-model="addressLine1"
          required
          class="mt-1 w-full rounded border border-gray-300 px-3 py-2"
        />
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700">Address line 2</label>
        <input
          v-model="addressLine2"
          class="mt-1 w-full rounded border border-gray-300 px-3 py-2"
        />
      </div>
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="block text-sm font-medium text-gray-700">City</label>
          <input
            v-model="city"
            required
            class="mt-1 w-full rounded border border-gray-300 px-3 py-2"
          />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Postcode</label>
          <input
            v-model="postcode"
            required
            maxlength="5"
            class="mt-1 w-full rounded border border-gray-300 px-3 py-2"
          />
        </div>
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700">State</label>
        <select
          v-model="state"
          required
          class="mt-1 w-full rounded border border-gray-300 px-3 py-2"
        >
          <option value="" disabled>Select a state</option>
          <option v-for="s in STATES" :key="s" :value="s">{{ s }}</option>
        </select>
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700">Business type</label>
        <select
          v-model="businessType"
          required
          class="mt-1 w-full rounded border border-gray-300 px-3 py-2"
        >
          <option value="" disabled>Select a business type</option>
          <option v-for="t in BUSINESS_TYPES" :key="t" :value="t">{{ t }}</option>
        </select>
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700">Financial year start month</label>
        <select
          v-model.number="financialYearStartMonth"
          required
          class="mt-1 w-full rounded border border-gray-300 px-3 py-2"
        >
          <option v-for="m in 12" :key="m" :value="m">{{ m }}</option>
        </select>
      </div>
      <button
        type="submit"
        :disabled="submitting"
        class="w-full rounded bg-gray-900 px-3 py-2 text-white disabled:opacity-50"
      >
        {{ submitting ? 'Saving…' : 'Save business profile' }}
      </button>
    </form>

    <button class="text-sm text-gray-600 underline" @click="router.push('/accounts')">
      Skip for now — go to Accounts
    </button>
  </div>
</template>
