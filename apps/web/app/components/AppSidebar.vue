<script setup lang="ts">
import type { AppIcon as AppIconType } from '#components'

const { collapsed, toggle, mobileOpen, closeMobile } = useSidebar()
const { user, tenant, logout } = useAuth()
const { canInstall, promptInstall } = useInstallPrompt()
const router = useRouter()
const route = useRoute()

interface NavItem {
  to: string
  label: string
  icon: InstanceType<typeof AppIconType>['$props']['name']
}

const primaryNav: NavItem[] = [
  { to: '/dashboard', label: 'Dashboard', icon: 'dashboard' },
  { to: '/', label: 'Work Queue', icon: 'home' },
  { to: '/manual-entry', label: 'Manual Entry', icon: 'plus' },
  { to: '/quotations', label: 'Quotations', icon: 'send' },
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

// On mobile the sidebar is a drawer — always full width, never
// icon-only — so `collapsed` (a desktop-only preference) is ignored
// below `md:`. Navigating closes the drawer automatically, matching
// every native mobile nav pattern.
function onNavigate() {
  closeMobile()
}

// The desktop collapse toggle also closes the mobile drawer first, so
// a resize while the drawer is open never leaves both states active
// at once.
function toggleDesktopSidebar() {
  closeMobile()
  toggle()
}
</script>

<template>
  <Teleport to="body">
    <div
      v-if="mobileOpen"
      class="fixed inset-0 z-40 bg-black/40 md:hidden"
      aria-hidden="true"
      @click="closeMobile"
    />
  </Teleport>

  <aside
    class="fixed inset-y-0 left-0 z-50 flex h-full flex-col border-r border-border bg-surface-secondary transition-transform duration-200 md:static md:z-auto md:translate-x-0 md:transition-[width]"
    :class="[
      mobileOpen ? 'translate-x-0' : '-translate-x-full',
      collapsed ? 'w-64 md:w-[68px]' : 'w-64',
    ]"
  >
    <div
      class="flex h-14 items-center px-3"
      :class="collapsed ? 'justify-between md:justify-center' : 'justify-between'"
    >
      <NuxtLink
        v-if="!collapsed || mobileOpen"
        to="/"
        class="flex items-center gap-2 px-1"
        :class="collapsed && 'md:hidden'"
        @click="onNavigate"
      >
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
        @click="toggleDesktopSidebar"
      >
        <AppIcon
          name="chevron-left"
          :size="18"
          class="hidden md:block"
          :class="collapsed && 'md:rotate-180'"
        />
        <AppIcon name="x" :size="18" class="md:hidden" />
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
          @click="onNavigate"
        >
          <AppIcon :name="item.icon" :size="18" class="shrink-0" />
          <span
            v-if="!collapsed || mobileOpen"
            class="truncate"
            :class="collapsed && 'md:hidden'"
            >{{ item.label }}</span
          >
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
          @click="onNavigate"
        >
          <AppIcon :name="item.icon" :size="18" class="shrink-0" />
          <span
            v-if="!collapsed || mobileOpen"
            class="truncate"
            :class="collapsed && 'md:hidden'"
            >{{ item.label }}</span
          >
        </NuxtLink>
      </div>
    </nav>

    <div class="border-t border-border p-2">
      <div
        v-if="(!collapsed || mobileOpen) && user"
        class="mb-2 flex items-center justify-between gap-2 px-1"
        :class="collapsed && 'md:hidden'"
      >
        <div class="min-w-0">
          <p class="truncate text-xs font-medium text-ink">{{ user.email }}</p>
          <p v-if="tenant" class="truncate text-[11px] text-ink-tertiary">
            Tenant {{ tenant.id.slice(0, 8) }}
          </p>
        </div>
        <ThemeToggle />
      </div>
      <button
        v-if="canInstall"
        type="button"
        class="flex w-full items-center gap-3 rounded-xl px-2.5 py-2 text-sm font-medium text-ink-secondary transition-colors hover:bg-surface-hover hover:text-ink"
        :title="collapsed ? 'Install app' : undefined"
        @click="promptInstall"
      >
        <AppIcon name="download" :size="18" class="shrink-0" />
        <span v-if="!collapsed || mobileOpen" :class="collapsed && 'md:hidden'">Install app</span>
      </button>
      <button
        type="button"
        class="flex w-full items-center gap-3 rounded-xl px-2.5 py-2 text-sm font-medium text-ink-secondary transition-colors hover:bg-surface-hover hover:text-ink"
        :title="collapsed ? 'Log out' : undefined"
        @click="handleLogout"
      >
        <AppIcon name="logout" :size="18" class="shrink-0" />
        <span v-if="!collapsed || mobileOpen" :class="collapsed && 'md:hidden'">Log out</span>
      </button>
    </div>
  </aside>
</template>
