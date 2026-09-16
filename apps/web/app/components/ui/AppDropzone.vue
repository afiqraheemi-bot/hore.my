<script setup lang="ts">
/**
 * Real drag-and-drop / paste file attachment (HORE_MY_MASTER_CONTEXT.md
 * §5) — a pure, upload-agnostic file picker. It emits the picked
 * `File` and nothing else; the caller decides where (if anywhere) it
 * actually gets uploaded. As of AETS-015 (2026-09-16), two real
 * endpoints exist for this to feed: bank statement CSV import
 * (`/bank-accounts/{id}/import`) and generic Evidence attachment
 * (`POST /evidence`, wired into `AppComposer.vue`). Still not reused
 * where no real upload target exists.
 */
const props = withDefaults(
  defineProps<{ modelValue: File | null; accept?: string; hint?: string }>(),
  { accept: undefined, hint: undefined },
)
const emit = defineEmits<{ 'update:modelValue': [file: File | null] }>()

const isDragging = ref(false)
const inputRef = ref<HTMLInputElement | null>(null)

function pickFile() {
  inputRef.value?.click()
}

function onFileInput(event: Event) {
  const file = (event.target as HTMLInputElement).files?.[0] ?? null
  emit('update:modelValue', file)
}

function onDrop(event: DragEvent) {
  isDragging.value = false
  const file = event.dataTransfer?.files?.[0]
  if (file) emit('update:modelValue', file)
}

function onPaste(event: ClipboardEvent) {
  const file = Array.from(event.clipboardData?.files ?? [])[0]
  if (file) emit('update:modelValue', file)
}

function clear() {
  emit('update:modelValue', null)
  if (inputRef.value) inputRef.value.value = ''
}
</script>

<template>
  <div
    class="flex flex-col items-center justify-center gap-1 rounded-2xl border-2 border-dashed px-4 py-6 text-center transition-colors"
    :class="
      isDragging ? 'border-accent bg-accent-soft' : 'border-border hover:border-border-strong'
    "
    tabindex="0"
    role="button"
    :aria-label="hint ?? 'Attach a file'"
    @dragover.prevent="isDragging = true"
    @dragleave.prevent="isDragging = false"
    @drop.prevent="onDrop"
    @paste="onPaste"
    @click="pickFile"
    @keydown.enter="pickFile"
  >
    <input ref="inputRef" type="file" :accept="props.accept" class="hidden" @change="onFileInput" />
    <template v-if="modelValue">
      <div class="flex items-center gap-2 text-sm text-ink">
        <AppIcon name="paperclip" :size="16" />
        <span class="max-w-[16rem] truncate">{{ modelValue.name }}</span>
        <button type="button" class="text-ink-tertiary hover:text-danger" @click.stop="clear">
          <AppIcon name="x" :size="14" />
        </button>
      </div>
    </template>
    <template v-else>
      <AppIcon name="paperclip" :size="18" class="text-ink-tertiary" />
      <p class="text-sm text-ink-secondary">Drop a file, paste, or click to browse</p>
      <p v-if="hint" class="text-xs text-ink-tertiary">{{ hint }}</p>
    </template>
  </div>
</template>
