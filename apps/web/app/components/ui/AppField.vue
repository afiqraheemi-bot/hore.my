<script setup lang="ts">
defineProps<{ label: string }>()
</script>

<template>
  <label class="block">
    <span class="mb-1 block text-xs font-medium text-ink-secondary">{{ label }}</span>
    <slot />
  </label>
</template>
