<script setup lang="ts">
definePageMeta({ middleware: 'guest', layout: false })

const { register } = useAuth()
const router = useRouter()

const name = ref('')
const email = ref('')
const password = ref('')
const passwordConfirmation = ref('')
const error = ref<string | null>(null)
const submitting = ref(false)

async function onSubmit() {
  error.value = null
  submitting.value = true
  try {
    await register(name.value, email.value, password.value, passwordConfirmation.value)
    await router.push('/accounts')
  } catch {
    error.value =
      'Registration failed — check the email is not already taken and the password is at least 8 characters.'
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="flex min-h-screen items-center justify-center bg-gray-50">
    <form
      class="w-full max-w-sm space-y-4 rounded border border-gray-200 bg-white p-6"
      @submit.prevent="onSubmit"
    >
      <h1 class="text-lg font-semibold">Register</h1>
      <p v-if="error" class="rounded bg-red-50 px-3 py-2 text-sm text-red-700">{{ error }}</p>
      <div>
        <label class="block text-sm font-medium text-gray-700">Name</label>
        <input
          v-model="name"
          type="text"
          required
          class="mt-1 w-full rounded border border-gray-300 px-3 py-2"
        />
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700">Email</label>
        <input
          v-model="email"
          type="email"
          required
          class="mt-1 w-full rounded border border-gray-300 px-3 py-2"
        />
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700">Password</label>
        <input
          v-model="password"
          type="password"
          required
          minlength="8"
          class="mt-1 w-full rounded border border-gray-300 px-3 py-2"
        />
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700">Confirm password</label>
        <input
          v-model="passwordConfirmation"
          type="password"
          required
          class="mt-1 w-full rounded border border-gray-300 px-3 py-2"
        />
      </div>
      <button
        type="submit"
        :disabled="submitting"
        class="w-full rounded bg-gray-900 px-3 py-2 text-white disabled:opacity-50"
      >
        {{ submitting ? 'Registering…' : 'Register' }}
      </button>
      <p class="text-center text-sm text-gray-600">
        Already have an account?
        <NuxtLink to="/login" class="font-medium text-gray-900 underline">Log in</NuxtLink>
      </p>
    </form>
  </div>
</template>
