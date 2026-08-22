<script setup>
import { computed, ref, watch } from 'vue'
import { useApi } from '../../api.js'
import { notifyError } from '../../notify.js'
import { tr } from '../../phrases.js'

const { api } = useApi()

const props = defineProps({
  // When set, the picker loads that teacher's current assignments.
  // When null (creating a teacher) it starts empty and just reports the selection.
  teacherId: { type: [Number, String], default: null },
})

const selected = defineModel({ type: Array, default: () => [] })

const classes = ref([])
const loading = ref(false)
const openClassIds = ref([])
const search = ref('')

const selectedSet = computed(() => new Set(selected.value.map(Number)))

const filteredClasses = computed(() => {
  const term = search.value.trim().toLowerCase()
  if (!term) return classes.value

  return classes.value
    .map((group) => ({
      ...group,
      subjects: group.subjects.filter((subject) =>
        [subject.name, subject.code, group.class_name].some((value) =>
          String(value ?? '').toLowerCase().includes(term),
        ),
      ),
    }))
    .filter((group) => group.subjects.length)
})

const selectedSubjects = computed(() =>
  classes.value.flatMap((group) =>
    group.subjects
      .filter((subject) => selectedSet.value.has(Number(subject.id)))
      .map((subject) => ({ ...subject, class_name: group.class_name })),
  ),
)

async function load() {
  loading.value = true

  try {
    const path = props.teacherId
      ? `/teachers/${props.teacherId}/courses`
      : '/subjects/catalogue'
    const response = await api(path)
    classes.value = response.data.classes
    if (props.teacherId) {
      selected.value = (response.data.assigned_course_ids ?? []).map(Number)
    }
  } catch (err) {
    notifyError(err.message)
  } finally {
    loading.value = false
  }
}

watch(() => props.teacherId, load, { immediate: true })

function toggleClass(classId) {
  openClassIds.value = openClassIds.value.includes(classId)
    ? openClassIds.value.filter((id) => id !== classId)
    : [...openClassIds.value, classId]
}

function toggleSubject(subjectId) {
  const id = Number(subjectId)
  selected.value = selectedSet.value.has(id)
    ? selected.value.filter((value) => Number(value) !== id)
    : [...selected.value, id]
}

function toggleWholeClass(group) {
  const ids = group.subjects.map((subject) => Number(subject.id))
  const allSelected = ids.every((id) => selectedSet.value.has(id))

  selected.value = allSelected
    ? selected.value.filter((value) => !ids.includes(Number(value)))
    : [...new Set([...selected.value.map(Number), ...ids])]
}

function selectedCountFor(group) {
  return group.subjects.filter((subject) => selectedSet.value.has(Number(subject.id))).length
}

// A subject has exactly one teacher, so picking one held by someone else moves it.
function takenFrom(subject) {
  if (!subject.teacher_id) return null
  if (props.teacherId && Number(subject.teacher_id) === Number(props.teacherId)) return null
  return subject.teacher_name
}

defineExpose({ reload: load })
</script>

<template>
  <div class="subject-picker">
    <div class="picker-toolbar">
      <input v-model="search" class="picker-search" :placeholder="tr('ابحث عن مادة أو فصل...')" />
      <span class="picker-count">{{ selected.length }} {{ tr('مادة مختارة') }}</span>
    </div>

    <p v-if="loading" class="muted">{{ tr('جاري تحميل المواد...') }}</p>
    <p v-else-if="!filteredClasses.length" class="muted">{{ tr('لا توجد مواد مطابقة.') }}</p>

    <div v-else class="picker-classes">
      <article v-for="group in filteredClasses" :key="group.id" class="picker-class">
        <header>
          <button type="button" class="picker-class-toggle" @click="toggleClass(group.id)">
            <span class="chevron">{{ openClassIds.includes(group.id) || search ? '▾' : '▸' }}</span>
            <strong>{{ group.class_name }}</strong>
            <small>{{ group.academic_year }} · {{ group.subjects.length }} {{ tr('مادة') }}</small>
          </button>
          <div class="picker-class-meta">
            <span v-if="selectedCountFor(group)" class="picker-badge">{{ selectedCountFor(group) }}</span>
            <button type="button" class="secondary compact" @click="toggleWholeClass(group)">
              {{ group.subjects.every((subject) => selectedSet.has(Number(subject.id))) ? tr('إلغاء الكل') : tr('اختيار الكل') }}
            </button>
          </div>
        </header>

        <ul v-if="openClassIds.includes(group.id) || search" class="picker-subjects">
          <li v-for="subject in group.subjects" :key="subject.id">
            <label :class="{ picked: selectedSet.has(Number(subject.id)) }">
              <input
                type="checkbox"
                :checked="selectedSet.has(Number(subject.id))"
                @change="toggleSubject(subject.id)"
              />
              <span class="subject-name">{{ subject.name }}</span>
              <small class="subject-code">{{ subject.code }}</small>
              <small v-if="takenFrom(subject)" class="subject-taken">
                {{ tr('حالياً مع') }} {{ takenFrom(subject) }} {{ tr('— سيتم نقلها') }}
              </small>
            </label>
          </li>
        </ul>
      </article>
    </div>

    <div v-if="selectedSubjects.length" class="picker-summary">
      <span
        v-for="subject in selectedSubjects"
        :key="`chosen-${subject.id}`"
        class="picker-chip"
      >
        {{ subject.class_name }} · {{ subject.name }}
        <button type="button" @click="toggleSubject(subject.id)" :aria-label="tr('إزالة')">×</button>
      </span>
    </div>
  </div>
</template>

<style scoped>
.subject-picker {
  display: grid;
  gap: 10px;
}

.picker-toolbar {
  display: flex;
  gap: 10px;
  align-items: center;
  flex-wrap: wrap;
}

.picker-search {
  flex: 1 1 220px;
  min-width: 0;
}

.picker-count {
  font-size: 12px;
  font-weight: 800;
  color: var(--app-accent);
  background: var(--app-accent-soft);
  border-radius: 999px;
  padding: 5px 11px;
  white-space: nowrap;
}

.picker-classes {
  display: grid;
  gap: 8px;
  max-height: 340px;
  overflow-y: auto;
  padding-inline-end: 4px;
}

.picker-class {
  border: 1px solid var(--app-border);
  border-radius: 8px;
  background: var(--app-surface);
}

.picker-class > header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  padding: 8px 10px;
}

.picker-class-toggle {
  display: flex;
  align-items: baseline;
  gap: 8px;
  background: transparent;
  border: 0;
  padding: 0;
  cursor: pointer;
  text-align: start;
  color: inherit;
  min-width: 0;
}

.picker-class-toggle small {
  color: var(--app-text-muted);
}

.chevron {
  color: var(--app-text-muted);
}

.picker-class-meta {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-shrink: 0;
}

.picker-badge {
  background: var(--app-accent);
  color: var(--app-surface);
  border-radius: 999px;
  font-size: 11px;
  font-weight: 800;
  padding: 2px 8px;
}

.picker-subjects {
  list-style: none;
  margin: 0;
  padding: 0 10px 10px;
  display: grid;
  gap: 4px;
}

.picker-subjects label {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 7px 9px;
  border: 1px solid transparent;
  border-radius: 6px;
  cursor: pointer;
  flex-wrap: wrap;
}

.picker-subjects label:hover {
  background: var(--app-surface-muted);
}

.picker-subjects label.picked {
  background: var(--app-accent-soft);
  border-color: #c7d2fe;
}

.picker-subjects input[type='checkbox'] {
  width: auto;
  margin: 0;
}

.subject-name {
  font-weight: 700;
}

.subject-code {
  color: var(--app-text-muted);
}

.subject-taken {
  color: var(--app-warn-text);
  background: var(--app-warn-soft);
  border-radius: 4px;
  padding: 1px 6px;
}

.picker-summary {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  border-top: 1px dashed var(--app-border);
  padding-top: 10px;
}

.picker-chip {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  background: var(--app-surface-muted);
  border-radius: 999px;
  padding: 4px 6px 4px 11px;
  font-size: 12px;
}

.picker-chip button {
  background: var(--app-border-strong);
  color: var(--app-text);
  border: 0;
  border-radius: 999px;
  width: 18px;
  height: 18px;
  line-height: 1;
  padding: 0;
  cursor: pointer;
  font-size: 13px;
}

.picker-chip button:hover {
  background: var(--app-danger-text);
  color: var(--app-surface);
}

.notice.compact {
  padding: 8px 10px;
  font-size: 12px;
}
</style>
