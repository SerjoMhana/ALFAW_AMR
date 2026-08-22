<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { pick } from '../../i18n.js'
import { tr } from '../../phrases.js'

/**
 * A dropdown that takes several answers.
 *
 * A grid of checkboxes is readable for four options and a wall for forty, so
 * the list stays folded away until it is wanted and reports itself in one line
 * when it is not.
 */
const props = defineProps({
  modelValue: { type: Array, default: () => [] },
  options: { type: Array, required: true },
  placeholder: { type: String, default: '' },
  // Below this many options the search box is just another thing to look at.
  searchFrom: { type: Number, default: 8 },
  disabled: { type: Boolean, default: false },
})

const emit = defineEmits(['update:modelValue'])

const open = ref(false)
const search = ref('')
const root = ref(null)

const selected = computed(() => props.options.filter((option) => props.modelValue.includes(option.value)))

const visible = computed(() => {
  const term = search.value.trim().toLowerCase()
  if (!term) return props.options

  return props.options.filter((option) => String(option.label).toLowerCase().includes(term))
})

const summary = computed(() => {
  if (!selected.value.length) return props.placeholder || tr('اختر من القائمة')
  // Naming two is a reminder; naming nine is a paragraph.
  if (selected.value.length <= 2) return selected.value.map((option) => option.label).join('، ')

  return pick(`${selected.value.length} عناصر مختارة`, `${selected.value.length} selected`)
})

function toggle(value) {
  const next = props.modelValue.includes(value)
    ? props.modelValue.filter((item) => item !== value)
    : [...props.modelValue, value]

  emit('update:modelValue', next)
}

function selectAll() {
  emit('update:modelValue', visible.value.map((option) => option.value))
}

function clear() {
  emit('update:modelValue', [])
}

function onOutside(event) {
  if (open.value && root.value && !root.value.contains(event.target)) open.value = false
}

function onKey(event) {
  if (event.key === 'Escape') open.value = false
}

watch(open, (value) => {
  if (!value) search.value = ''
})

onMounted(() => {
  document.addEventListener('click', onOutside)
  document.addEventListener('keydown', onKey)
})

onUnmounted(() => {
  document.removeEventListener('click', onOutside)
  document.removeEventListener('keydown', onKey)
})
</script>

<template>
  <div ref="root" class="multi-select">
    <button
      type="button"
      class="secondary multi-select-toggle"
      :disabled="disabled"
      :aria-expanded="open"
      @click="open = !open"
    >
      <span :class="{ muted: !selected.length }">{{ summary }}</span>
      <span class="multi-select-caret" :class="{ open }">▾</span>
    </button>

    <div v-if="open" class="multi-select-panel">
      <div class="multi-select-tools">
        <input
          v-if="options.length >= searchFrom"
          v-model="search"
          type="search"
          :placeholder="tr('بحث')"
        />
        <button type="button" class="secondary compact" @click="selectAll">{{ tr('تحديد الكل') }}</button>
        <button type="button" class="secondary compact" @click="clear">{{ tr('إلغاء الكل') }}</button>
      </div>

      <div class="multi-select-list">
        <label v-for="option in visible" :key="option.value" class="checkbox-label">
          <input
            type="checkbox"
            :checked="modelValue.includes(option.value)"
            @change="toggle(option.value)"
          />
          <span class="multi-select-label">{{ option.label }}</span>
          <span v-if="option.hint" class="multi-select-hint">{{ option.hint }}</span>
        </label>

        <p v-if="!visible.length" class="muted">{{ tr('لا توجد نتائج مطابقة.') }}</p>
      </div>
    </div>
  </div>
</template>

<style scoped>
.multi-select { position: relative; }

.multi-select-toggle {
  width: 100%;
  justify-content: space-between;
  height: 2.75rem;
  font-weight: 500;
}

.multi-select-caret { transition: transform 150ms ease; font-size: 0.8rem; }
.multi-select-caret.open { transform: rotate(180deg); }

.multi-select-panel {
  position: absolute;
  z-index: 40;
  inset-inline: 0;
  margin-top: 0.35rem;
  padding: 0.6rem;
  display: grid;
  gap: 0.5rem;
  border: 1px solid var(--app-border);
  border-radius: 0.75rem;
  background: var(--app-surface);
  box-shadow: 0 16px 32px rgb(16 24 40 / 18%);
}

.multi-select-tools {
  display: flex;
  gap: 0.4rem;
  align-items: center;
}

.multi-select-tools input { height: 2.25rem; }

.multi-select-list {
  display: grid;
  gap: 0.15rem;
  /* Tall enough to scan, short enough not to swallow the page. */
  max-height: 15rem;
  overflow-y: auto;
}

.multi-select-list .checkbox-label {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.35rem 0.5rem;
  border-radius: 0.5rem;
  cursor: pointer;
}

.multi-select-list .checkbox-label:hover { background: var(--app-surface-muted); }

.multi-select-label { flex: 1; }

.multi-select-hint {
  font-size: 0.72rem;
  color: var(--app-text-muted);
}
</style>
