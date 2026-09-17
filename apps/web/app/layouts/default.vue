<script setup lang="ts">
// `h-dvh`, not `h-screen` (100vh): iOS Safari's address bar shows and
// hides as the page scrolls, so 100vh is sized for whichever state
// was current when the page last loaded — a client-side navigation
// never re-triggers that calculation, so the shell can render clipped
// (or the inner scroll container's bounds mismatch what's visually on
// screen, reading as scrolling that never quite finishes) until a
// hard reload forces Safari to recompute. `dvh` tracks the real
// visible viewport continuously instead.
const { init: initSidebar, openMobile } = useSidebar()
const { init: initTheme } = useTheme()

onMounted(() => {
  initTheme()
  initSidebar()
})
</script>

<template>
  <div class="flex h-dvh overflow-hidden bg-surface">
    <AppSidebar />
    <div class="flex min-w-0 flex-1 flex-col">
      <header class="flex h-14 shrink-0 items-center border-b border-border px-4 md:hidden">
        <button
          type="button"
          class="flex h-9 w-9 items-center justify-center rounded-lg text-ink-secondary hover:bg-surface-hover hover:text-ink"
          aria-label="Open menu"
          @click="openMobile"
        >
          <AppIcon name="menu" :size="20" />
        </button>
        <span
          class="ml-2 flex h-6 w-6 items-center justify-center rounded-md bg-accent text-xs font-bold text-accent-contrast"
          >H</span
        >
        <span class="ml-1.5 text-sm font-semibold text-ink">hore.my</span>
      </header>
      <main class="flex-1 overflow-y-auto">
        <div class="mx-auto max-w-5xl px-4 py-6 sm:px-6 sm:py-8">
          <slot />
        </div>
      </main>
    </div>
  </div>
</template>
