<script setup lang="ts">
definePageMeta({ middleware: 'auth' })

await navigateTo('/tasks', { replace: true })
</script>

<template>
  <p class="text-sm text-ink-tertiary">Opening your Work Queue…</p>
</template>
