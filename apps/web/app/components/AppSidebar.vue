<script setup lang="ts">
import type { AppIcon as AppIconType } from '#components'

const { collapsed, toggle } = useSidebar()
const { user, tenant, logout } = useAuth()
const router = useRouter()
const route = useRoute()

interface NavItem {
  to: string
  label: string
  icon: InstanceType<typeof AppIconType>['$props']['name']
}

const primaryNav: NavItem[] = [
  { to: '/', label: 'Home', icon: 'home' },
  { to: '/invoices', label: 'Invoices', icon: 'receipt' },
  { to: '/payments', label: 'Payments', icon: 'wallet' },
  { to: '/bank-accounts', label: 'Bank', icon: 'bank' },
  { to: '/reports', label: 'Reports', icon: 'chart' },
]

const secondaryNav: NavItem[] = [
  { to: '/accounts', label: 'Chart of Accounts', icon: 'building' },
  { to: '/customers', label: 'Customers', icon: 'users' },
  { to: '/business-profile', label: 'Business Profile', icon: 'building' },
]

function isActive(to: string): boolean {
  return to === '/' ? route.path === '/' : route.path.startsWith(to)
}

async function handleLogout() {
  await logout()
  router.push('/login')
}
</script>

<template>
  <aside
    class="flex h-full flex-col border-r border-border bg-surface-secondary transition-[width] duration-150"
    :class="collapsed ? 'w-[68px]' : 'w-64'"
  >
    <div
      class="flex h-14 items-center px-3"
      :class="collapsed ? 'justify-center' : 'justify-between'"
    >
      <NuxtLink v-if="!collapsed" to="/" class="flex items-center gap-2 px-1">
        <span
          class="flex h-7 w-7 items-center justify-center rounded-lg bg-accent text-sm font-bold text-accent-contrast"
          >H</span
        >
        <span class="text-sm font-semibold text-ink">hore.my</span>
      </NuxtLink>
      <button
        type="button"
        class="flex h-8 w-8 items-center justify-center rounded-lg text-ink-secondary hover:bg-surface-hover hover:text-ink"
        :aria-label="collapsed ? 'Expand sidebar' : 'Collapse sidebar'"
        @click="toggle"
      >
        <AppIcon :name="collapsed ? 'menu' : 'chevron-left'" :size="18" />
      </button>
    </div>

    <nav class="flex-1 space-y-4 overflow-y-auto px-2 py-2">
      <div class="space-y-0.5">
        <NuxtLink
          v-for="item in primaryNav"
          :key="item.to"
          :to="item.to"
          class="flex items-center gap-3 rounded-xl px-2.5 py-2 text-sm font-medium transition-colors"
          :class="
            isActive(item.to)
              ? 'bg-accent-soft text-accent'
              : 'text-ink-secondary hover:bg-surface-hover hover:text-ink'
          "
          :title="collapsed ? item.label : undefined"
        >
          <AppIcon :name="item.icon" :size="18" class="shrink-0" />
          <span v-if="!collapsed" class="truncate">{{ item.label }}</span>
        </NuxtLink>
      </div>

      <div class="space-y-0.5 border-t border-border pt-3">
        <NuxtLink
          v-for="item in secondaryNav"
          :key="item.to"
          :to="item.to"
          class="flex items-center gap-3 rounded-xl px-2.5 py-2 text-sm font-medium transition-colors"
          :class="
            isActive(item.to)
              ? 'bg-accent-soft text-accent'
              : 'text-ink-secondary hover:bg-surface-hover hover:text-ink'
          "
          :title="collapsed ? item.label : undefined"
        >
          <AppIcon :name="item.icon" :size="18" class="shrink-0" />
          <span v-if="!collapsed" class="truncate">{{ item.label }}</span>
        </NuxtLink>
      </div>
    </nav>

    <div class="border-t border-border p-2">
      <div v-if="!collapsed && user" class="mb-2 flex items-center justify-between gap-2 px-1">
        <div class="min-w-0">
          <p class="truncate text-xs font-medium text-ink">{{ user.email }}</p>
          <p v-if="tenant" class="truncate text-[11px] text-ink-tertiary">
            Tenant {{ tenant.id.slice(0, 8) }}
          </p>
        </div>
        <ThemeToggle />
      </div>
      <button
        type="button"
        class="flex w-full items-center gap-3 rounded-xl px-2.5 py-2 text-sm font-medium text-ink-secondary transition-colors hover:bg-surface-hover hover:text-ink"
        :title="collapsed ? 'Log out' : undefined"
        @click="handleLogout"
      >
        <AppIcon name="logout" :size="18" class="shrink-0" />
        <span v-if="!collapsed">Log out</span>
      </button>
    </div>
  </aside>
</template>
