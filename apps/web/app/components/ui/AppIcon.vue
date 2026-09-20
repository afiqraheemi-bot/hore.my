<script setup lang="ts">
/**
 * A small, hand-drawn icon set (outline style, 24x24, 1.5px stroke) —
 * no icon-library dependency. Covers exactly what this app's
 * navigation, composer, and card actions need; add a new `<path>`
 * here rather than pulling in a whole package for one glyph.
 */
const props = defineProps<{
  name:
    | 'menu'
    | 'chevron-left'
    | 'sun'
    | 'moon'
    | 'system'
    | 'home'
    | 'dashboard'
    | 'receipt'
    | 'bank'
    | 'chart'
    | 'users'
    | 'building'
    | 'wallet'
    | 'plus'
    | 'send'
    | 'paperclip'
    | 'x'
    | 'trash'
    | 'check'
    | 'tasks'
    | 'chevron-down'
    | 'logout'
    | 'download'
    | 'refresh'
    | 'calendar'
    | 'search'
    | 'dots'
    | 'alert'
  size?: number
}>()

const size = props.size ?? 20
</script>

<template>
  <svg
    :width="size"
    :height="size"
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    stroke-width="1.75"
    stroke-linecap="round"
    stroke-linejoin="round"
    aria-hidden="true"
  >
    <template v-if="name === 'menu'">
      <path d="M3 6h18M3 12h18M3 18h18" />
    </template>
    <template v-else-if="name === 'chevron-left'">
      <path d="M15 18l-6-6 6-6" />
    </template>
    <template v-else-if="name === 'chevron-down'">
      <path d="M6 9l6 6 6-6" />
    </template>
    <template v-else-if="name === 'sun'">
      <circle cx="12" cy="12" r="4" />
      <path
        d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"
      />
    </template>
    <template v-else-if="name === 'moon'">
      <path d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z" />
    </template>
    <template v-else-if="name === 'system'">
      <rect x="3" y="4" width="18" height="13" rx="2" />
      <path d="M8 21h8M12 17v4" />
    </template>
    <template v-else-if="name === 'home'">
      <path d="M3 11.5L12 4l9 7.5" />
      <path d="M5 10v9a1 1 0 001 1h4v-6h4v6h4a1 1 0 001-1v-9" />
    </template>
    <template v-else-if="name === 'dashboard'">
      <rect x="3" y="3" width="8" height="8" rx="1.5" />
      <rect x="13" y="3" width="8" height="5" rx="1.5" />
      <rect x="13" y="10" width="8" height="11" rx="1.5" />
      <rect x="3" y="13" width="8" height="8" rx="1.5" />
    </template>
    <template v-else-if="name === 'receipt'">
      <path d="M6 3h12v18l-2.5-1.5L13 21l-2.5-1.5L8 21l-2-1.5V3z" />
      <path d="M9 8h6M9 12h6" />
    </template>
    <template v-else-if="name === 'bank'">
      <path d="M3 10l9-6 9 6" />
      <path d="M5 10v9M9 10v9M15 10v9M19 10v9" />
      <path d="M3 21h18" />
    </template>
    <template v-else-if="name === 'chart'">
      <path d="M4 20V10M11 20V4M18 20v-7" />
      <path d="M2 20h20" />
    </template>
    <template v-else-if="name === 'users'">
      <circle cx="9" cy="8" r="3.25" />
      <path d="M3.5 19a5.5 5.5 0 0111 0" />
      <path d="M16 8.5a2.75 2.75 0 110 5.5M20.5 19a4.5 4.5 0 00-4-4.45" />
    </template>
    <template v-else-if="name === 'building'">
      <rect x="4" y="3" width="16" height="18" rx="1" />
      <path d="M9 8h1M14 8h1M9 12h1M14 12h1M9 16h1M14 16h1" />
    </template>
    <template v-else-if="name === 'wallet'">
      <rect x="3" y="6" width="18" height="13" rx="2" />
      <path d="M3 10h18" />
      <circle cx="16.5" cy="14" r="1" />
    </template>
    <template v-else-if="name === 'plus'">
      <path d="M12 5v14M5 12h14" />
    </template>
    <template v-else-if="name === 'send'">
      <path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z" />
    </template>
    <template v-else-if="name === 'paperclip'">
      <path
        d="M21 12.5l-8.5 8.5a4.5 4.5 0 01-6.36-6.36L14.5 6.28a3 3 0 014.24 4.24L10.4 18.86a1.5 1.5 0 01-2.12-2.12l7.07-7.07"
      />
    </template>
    <template v-else-if="name === 'x'">
      <path d="M18 6L6 18M6 6l12 12" />
    </template>
    <template v-else-if="name === 'trash'">
      <path d="M4 7h16M9 7V4h6v3M6 7l1 13a1 1 0 001 1h8a1 1 0 001-1l1-13" />
    </template>
    <template v-else-if="name === 'check'">
      <path d="M5 12l5 5L20 7" />
    </template>
    <template v-else-if="name === 'tasks'">
      <rect x="4" y="3" width="16" height="18" rx="2" />
      <path d="M8 8l1.25 1.25L11.5 7M13.5 8.5H17" />
      <path d="M8 14l1.25 1.25L11.5 13M13.5 14.5H17" />
    </template>
    <template v-else-if="name === 'logout'">
      <path d="M9 21H5a1 1 0 01-1-1V4a1 1 0 011-1h4" />
      <path d="M16 17l5-5-5-5M21 12H9" />
    </template>
    <template v-else-if="name === 'download'">
      <path d="M12 3v13M7 11l5 5 5-5" />
      <path d="M5 21h14" />
    </template>
    <template v-else-if="name === 'refresh'">
      <path d="M3 12a9 9 0 0115.5-6.36M21 12a9 9 0 01-15.5 6.36" />
      <path d="M18 3v5h-5M6 21v-5h5" />
    </template>
    <template v-else-if="name === 'calendar'">
      <rect x="3" y="5" width="18" height="16" rx="2" />
      <path d="M8 3v4M16 3v4M3 10h18" />
    </template>
    <template v-else-if="name === 'search'">
      <circle cx="11" cy="11" r="7" />
      <path d="M21 21l-4.3-4.3" />
    </template>
    <template v-else-if="name === 'dots'">
      <circle cx="5" cy="12" r="1.25" fill="currentColor" stroke="none" />
      <circle cx="12" cy="12" r="1.25" fill="currentColor" stroke="none" />
      <circle cx="19" cy="12" r="1.25" fill="currentColor" stroke="none" />
    </template>
    <template v-else-if="name === 'alert'">
      <path
        d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"
      />
      <line x1="12" y1="9" x2="12" y2="13" />
      <line x1="12" y1="17" x2="12.01" y2="17" />
    </template>
  </svg>
</template>
