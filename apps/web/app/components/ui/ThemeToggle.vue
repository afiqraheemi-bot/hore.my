<script setup lang="ts">
const { preference, setPreference } = useTheme()

const options: {
  value: 'light' | 'dark' | 'system'
  icon: 'sun' | 'moon' | 'system'
  label: string
}[] = [
  { value: 'light', icon: 'sun', label: 'Light' },
  { value: 'dark', icon: 'moon', label: 'Dark' },
  { value: 'system', icon: 'system', label: 'System' },
]
</script>

<template>
  <div
    class="inline-flex items-center gap-0.5 rounded-full border border-border bg-surface-secondary p-0.5"
    role="radiogroup"
    aria-label="Theme"
  >
    <button
      v-for="option in options"
      :key="option.value"
      type="button"
      role="radio"
      :aria-checked="preference === option.value"
      :title="option.label"
      class="flex h-7 w-7 items-center justify-center rounded-full transition-colors"
      :class="
        preference === option.value
          ? 'bg-surface text-ink shadow-sm'
          : 'text-ink-tertiary hover:text-ink'
      "
      @click="setPreference(option.value)"
    >
      <AppIcon :name="option.icon" :size="15" />
    </button>
  </div>
</template>
