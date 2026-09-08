<script setup lang="ts">
const { init: initSidebar } = useSidebar()
const { init: initTheme } = useTheme()

onMounted(() => {
  initTheme()
  initSidebar()
})
</script>

<template>
  <div class="flex h-screen overflow-hidden bg-surface">
    <AppSidebar />
    <main class="flex-1 overflow-y-auto">
      <div class="mx-auto max-w-5xl px-6 py-8">
        <slot />
      </div>
    </main>
  </div>
</template>
