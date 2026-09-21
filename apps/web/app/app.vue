<script setup lang="ts">
useHead({
  titleTemplate: (chunk) => (chunk ? `${chunk} · hore.my` : 'hore.my'),
})
</script>

<template>
  <div>
    <NuxtRouteAnnouncer />
    <NuxtLayout>
      <NuxtPage />
    </NuxtLayout>
  </div>
</template>
