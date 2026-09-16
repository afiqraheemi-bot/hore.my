<script setup lang="ts">
withDefaults(defineProps<{ title: string; description?: string; bordered?: boolean }>(), {
  description: undefined,
  bordered: true,
})
</script>

<template>
  <div
    class="flex flex-col items-center justify-center gap-1 py-12 text-center"
    :class="bordered && 'rounded-2xl border border-dashed border-border'"
  >
    <p class="text-sm font-medium text-ink">{{ title }}</p>
    <p v-if="description" class="max-w-sm text-sm text-ink-tertiary">{{ description }}</p>
    <div v-if="$slots.action" class="mt-3">
      <slot name="action" />
    </div>
  </div>
</template>
