<script setup lang="ts">
withDefaults(defineProps<{ tone?: 'neutral' | 'success' | 'danger' | 'warning' | 'accent' }>(), {
  tone: 'neutral',
})
</script>

<template>
  <span
    class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium"
    :class="[
      tone === 'neutral' && 'bg-surface-tertiary text-ink-secondary',
      tone === 'success' && 'bg-success-soft text-success',
      tone === 'danger' && 'bg-danger-soft text-danger',
      tone === 'warning' && 'bg-warning-soft text-warning',
      tone === 'accent' && 'bg-accent-soft text-accent',
    ]"
  >
    <slot />
  </span>
</template>
