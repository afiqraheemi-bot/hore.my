<script setup lang="ts">
definePageMeta({ middleware: 'guest', layout: false })

const { register } = useAuth()
const { init: initTheme } = useTheme()
const router = useRouter()

const name = ref('')
const email = ref('')
const password = ref('')
const passwordConfirmation = ref('')
const termsAccepted = ref(false)
const error = ref<string | null>(null)
const submitting = ref(false)

async function onSubmit() {
  error.value = null
  submitting.value = true
  try {
    await register(
      name.value,
      email.value,
      password.value,
      passwordConfirmation.value,
      termsAccepted.value,
    )
    await router.push('/business-profile')
  } catch {
    error.value =
      'Registration failed — check the email is not already taken, the password is at least 8 characters, and you have accepted the terms.'
  } finally {
    submitting.value = false
  }
}

onMounted(initTheme)
</script>

<template>
  <div class="flex min-h-dvh items-center justify-center bg-surface px-4 py-10">
    <div class="w-full max-w-sm">
      <div class="mb-8 flex flex-col items-center gap-2">
        <span
          class="flex h-10 w-10 items-center justify-center rounded-xl bg-accent text-lg font-bold text-accent-contrast"
          >H</span
        >
        <h1 class="text-lg font-semibold text-ink">Create your account</h1>
      </div>
      <form class="space-y-4" @submit.prevent="onSubmit">
        <p v-if="error" class="rounded-xl bg-danger-soft px-3 py-2 text-sm text-danger">
          {{ error }}
        </p>
        <AppField label="Name">
          <AppInput v-model="name" required autocomplete="name" />
        </AppField>
        <AppField label="Email">
          <AppInput v-model="email" type="email" required autocomplete="username" />
        </AppField>
        <AppField label="Password">
          <AppInput
            v-model="password"
            type="password"
            required
            minlength="8"
            autocomplete="new-password"
          />
        </AppField>
        <AppField label="Confirm password">
          <AppInput
            v-model="passwordConfirmation"
            type="password"
            required
            autocomplete="new-password"
          />
        </AppField>
        <label class="flex items-start gap-2 text-sm text-ink-secondary">
          <input
            v-model="termsAccepted"
            type="checkbox"
            required
            class="mt-0.5 h-4 w-4 rounded border-border text-accent focus:ring-accent"
          />
          <span>I agree to the Terms of Service and Privacy Notice.</span>
        </label>
        <AppButton type="submit" variant="primary" :disabled="submitting" class="w-full">
          {{ submitting ? 'Registering…' : 'Register' }}
        </AppButton>
        <p class="text-center text-sm text-ink-tertiary">
          Already have an account?
          <NuxtLink to="/login" class="font-medium text-accent hover:underline">Log in</NuxtLink>
        </p>
      </form>
    </div>
  </div>
</template>
