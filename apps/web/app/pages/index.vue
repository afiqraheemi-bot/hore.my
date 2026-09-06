<script setup lang="ts">
definePageMeta({ layout: false })

const { user, fetchMe } = useAuth()
const router = useRouter()

await fetchMe()
await router.replace(user.value ? '/accounts' : '/login')
</script>

<template>
  <div />
</template>
