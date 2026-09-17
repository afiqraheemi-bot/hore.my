<script setup lang="ts">
withDefaults(
  defineProps<{
    modelValue: string | number | undefined
    type?: string
    placeholder?: string
    required?: boolean
    min?: string | number
    step?: string
  }>(),
  { type: 'text', required: false, placeholder: undefined, min: undefined, step: undefined },
)

defineEmits<{ 'update:modelValue': [value: string] }>()
</script>

<template>
  <input
    :value="modelValue"
    :type="type"
    :placeholder="placeholder"
    :required="required"
    :min="min"
    :step="step"
    class="h-10 w-full min-w-0 rounded-xl border border-border bg-surface px-3 text-sm text-ink placeholder:text-ink-tertiary focus:border-accent"
    @input="$emit('update:modelValue', ($event.target as HTMLInputElement).value)"
  />
</template>
