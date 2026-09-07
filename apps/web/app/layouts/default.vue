<script setup lang="ts">
const { user, tenant, logout } = useAuth()
const router = useRouter()

async function handleLogout() {
  await logout()
  router.push('/login')
}
</script>

<template>
  <div class="min-h-screen bg-gray-50 text-gray-900">
    <header class="border-b border-gray-200 bg-white">
      <div class="mx-auto flex max-w-4xl items-center justify-between px-4 py-3">
        <nav class="flex items-center gap-4 text-sm font-medium">
          <span class="text-base font-semibold">hore.my QA harness</span>
          <NuxtLink to="/business-profile" class="text-gray-600 hover:text-gray-900"
            >Business Profile</NuxtLink
          >
          <NuxtLink to="/accounts" class="text-gray-600 hover:text-gray-900">Accounts</NuxtLink>
          <NuxtLink to="/bank-accounts" class="text-gray-600 hover:text-gray-900">Bank</NuxtLink>
          <NuxtLink to="/expenses/new" class="text-gray-600 hover:text-gray-900"
            >+ Expense</NuxtLink
          >
          <NuxtLink to="/incomes/new" class="text-gray-600 hover:text-gray-900">+ Income</NuxtLink>
          <NuxtLink to="/transfers/new" class="text-gray-600 hover:text-gray-900"
            >+ Transfer</NuxtLink
          >
          <NuxtLink to="/capital-contributions/new" class="text-gray-600 hover:text-gray-900"
            >+ Capital</NuxtLink
          >
          <NuxtLink to="/owner-drawings/new" class="text-gray-600 hover:text-gray-900"
            >+ Drawing</NuxtLink
          >
          <NuxtLink to="/reports" class="text-gray-600 hover:text-gray-900">Reports</NuxtLink>
        </nav>
        <div v-if="user" class="flex items-center gap-3 text-sm text-gray-600">
          <span>{{ user.email }}</span>
          <span v-if="tenant" class="text-gray-400">· Tenant {{ tenant.id.slice(0, 8) }}</span>
          <button
            class="rounded border border-gray-300 px-2 py-1 text-gray-700 hover:bg-gray-100"
            @click="handleLogout"
          >
            Log out
          </button>
        </div>
      </div>
    </header>
    <main class="mx-auto max-w-4xl px-4 py-6">
      <slot />
    </main>
  </div>
</template>
