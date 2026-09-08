<script setup lang="ts">
withDefaults(
  defineProps<{
    modelValue: string
    options: { value: string; label: string }[]
    placeholder?: string
    required?: boolean
  }>(),
  { required: false, placeholder: undefined },
)

defineEmits<{ 'update:modelValue': [value: string] }>()
</script>

<template>
  <select
    :value="modelValue"
    :required="required"
    class="h-10 w-full rounded-xl border border-border bg-surface px-3 text-sm text-ink focus:border-accent"
    @change="$emit('update:modelValue', ($event.target as HTMLSelectElement).value)"
  >
    <option v-if="placeholder" value="" disabled>{{ placeholder }}</option>
    <option v-for="option in options" :key="option.value" :value="option.value">
      {{ option.label }}
    </option>
  </select>
</template>
