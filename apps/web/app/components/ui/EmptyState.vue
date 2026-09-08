<script setup lang="ts">
defineProps<{ title: string; description?: string }>()
</script>

<template>
  <div
    class="flex flex-col items-center justify-center gap-1 rounded-2xl border border-dashed border-border py-14 text-center"
  >
    <p class="text-sm font-medium text-ink">{{ title }}</p>
    <p v-if="description" class="max-w-sm text-sm text-ink-tertiary">{{ description }}</p>
    <div v-if="$slots.action" class="mt-3">
      <slot name="action" />
    </div>
  </div>
</template>
