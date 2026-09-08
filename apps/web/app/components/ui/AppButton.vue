<script setup lang="ts">
withDefaults(
  defineProps<{
    variant?: 'primary' | 'secondary' | 'ghost' | 'danger'
    size?: 'sm' | 'md'
    type?: 'button' | 'submit'
    disabled?: boolean
  }>(),
  { variant: 'secondary', size: 'md', type: 'button', disabled: false },
)
</script>

<template>
  <button
    :type="type"
    :disabled="disabled"
    class="inline-flex items-center justify-center gap-1.5 rounded-full font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-50"
    :class="[
      size === 'sm' ? 'h-8 px-3 text-xs' : 'h-10 px-4 text-sm',
      variant === 'primary' && 'bg-accent text-accent-contrast hover:bg-accent-hover',
      variant === 'secondary' && 'border border-border bg-surface text-ink hover:bg-surface-hover',
      variant === 'ghost' && 'text-ink-secondary hover:bg-surface-hover hover:text-ink',
      variant === 'danger' && 'bg-danger text-white hover:opacity-90',
    ]"
  >
    <slot />
  </button>
</template>
