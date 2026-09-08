<script setup lang="ts">
withDefaults(defineProps<{ padded?: boolean; hoverable?: boolean }>(), {
  padded: true,
  hoverable: false,
})
</script>

<template>
  <div
    class="rounded-2xl border border-border bg-surface"
    :class="[padded && 'p-4', hoverable && 'transition-colors hover:bg-surface-hover']"
  >
    <slot />
  </div>
</template>
