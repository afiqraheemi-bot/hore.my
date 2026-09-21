<script setup lang="ts">
definePageMeta({ middleware: 'guest', layout: false })
useHead({ title: 'Log in' })

const { login } = useAuth()
const { init: initTheme } = useTheme()
const router = useRouter()

const email = ref('')
const password = ref('')
const error = ref<string | null>(null)
const submitting = ref(false)

async function onSubmit() {
  error.value = null
  submitting.value = true
  try {
    await login(email.value, password.value)
    await router.push('/')
  } catch {
    error.value = 'These credentials do not match our records.'
  } finally {
    submitting.value = false
  }
}

onMounted(initTheme)
</script>

<template>
  <div class="flex min-h-dvh items-center justify-center bg-surface px-4">
    <div class="w-full max-w-sm">
      <div class="mb-8 flex flex-col items-center gap-2">
        <span
          class="flex h-10 w-10 items-center justify-center rounded-xl bg-accent text-lg font-bold text-accent-contrast"
          >H</span
        >
        <h1 class="text-lg font-semibold text-ink">Welcome back</h1>
      </div>
      <form class="space-y-4" @submit.prevent="onSubmit">
        <p v-if="error" class="rounded-xl bg-danger-soft px-3 py-2 text-sm text-danger">
          {{ error }}
        </p>
        <AppField label="Email">
          <AppInput v-model="email" type="email" required autocomplete="username" />
        </AppField>
        <AppField label="Password">
          <AppInput v-model="password" type="password" required autocomplete="current-password" />
        </AppField>
        <AppButton type="submit" variant="primary" :disabled="submitting" class="w-full">
          {{ submitting ? 'Logging in…' : 'Log in' }}
        </AppButton>
        <p class="text-center text-sm text-ink-tertiary">
          No account?
          <NuxtLink to="/register" class="font-medium text-accent hover:underline"
            >Register</NuxtLink
          >
        </p>
      </form>
    </div>
  </div>
</template>
