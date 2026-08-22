<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useApi } from '../../api.js'
import { notifyError, notifySuccess } from '../../notify.js'
import { confirmAction } from '../../confirm.js'
import { tr } from '../../phrases.js'

const { api } = useApi()

const academicYears = ref([])
const sourceYear = ref('')
const targetYear = ref('')
const sections = ref([])
const targetSections = ref([])
const selectedSectionId = ref('')
const overrideTargetId = ref('')
const preview = ref(null)
const selectedIds = ref([])
const loading = ref(false)
const applying = ref(false)

// The next year usually has not been created yet, so offer it alongside the
// configured years rather than leaving the picker empty.
const targetYearOptions = computed(() => [
  ...new Set([
    ...academicYears.value.map((year) => year.name),
    nextYearAfter(sourceYear.value),
  ].filter(Boolean)),
].sort())

const isGraduation = computed(() => preview.value?.action === 'graduate')
const blocked = computed(() => Boolean(preview.value?.blocked_reason))
const canApply = computed(() =>
  preview.value && !blocked.value && selectedIds.value.length > 0 && !applying.value,
)

onMounted(async () => {
  try {
    academicYears.value = (await api('/academic-years')).data
    sourceYear.value = academicYears.value.find((year) => year.is_active)?.name
      ?? academicYears.value[0]?.name
      ?? ''
    targetYear.value = nextYearAfter(sourceYear.value)
  } catch (err) {
    notifyError(err.message)
  }
})

// 2026-2027 -> 2027-2028, so the admin rarely has to change it by hand.
function nextYearAfter(year) {
  const match = /^(\d{4})-(\d{4})$/.exec(year ?? '')
  if (!match) return year ?? ''
  const known = academicYears.value.map((row) => row.name)
  const guess = `${Number(match[1]) + 1}-${Number(match[2]) + 1}`

  return known.includes(guess) ? guess : (known.find((name) => name > year) ?? guess)
}

watch(sourceYear, async (year) => {
  selectedSectionId.value = ''
  preview.value = null
  if (!year) return
  targetYear.value = nextYearAfter(year)
  sections.value = (await api(`/promotions/context?academic_year=${encodeURIComponent(year)}`)).data
})

watch(targetYear, async (year) => {
  overrideTargetId.value = ''
  targetSections.value = year
    ? (await api(`/promotions/context?academic_year=${encodeURIComponent(year)}`)).data
    : []
  if (selectedSectionId.value) loadPreview()
})

watch(selectedSectionId, () => {
  preview.value = null
  overrideTargetId.value = ''
  if (selectedSectionId.value) loadPreview()
})

async function loadPreview() {
  if (!selectedSectionId.value || !targetYear.value) return
  loading.value = true

  try {
    const body = {
      course_section_id: Number(selectedSectionId.value),
      target_academic_year: targetYear.value,
    }
    if (overrideTargetId.value) body.target_section_id = Number(overrideTargetId.value)

    const response = await api('/promotions/preview', { method: 'POST', body: JSON.stringify(body) })
    preview.value = response.data
    selectedIds.value = response.data.students.map((student) => student.id)
  } catch (err) {
    notifyError(err.message)
    preview.value = null
  } finally {
    loading.value = false
  }
}

function toggleStudent(id) {
  selectedIds.value = selectedIds.value.includes(id)
    ? selectedIds.value.filter((value) => value !== id)
    : [...selectedIds.value, id]
}

function toggleAll() {
  selectedIds.value = selectedIds.value.length === preview.value.students.length
    ? []
    : preview.value.students.map((student) => student.id)
}

async function apply() {
  const count = selectedIds.value.length
  const confirmText = isGraduation.value
    ? `سيتم تخريج ${count} طالب من ${preview.value.source_section.class_name} ونقلهم إلى الأرشيف، ويمكنك استرجاعهم لاحقاً من صفحة أرشيف الطلبة. متابعة؟`
    : `سيتم نقل ${count} طالب من ${preview.value.source_section.class_name} إلى ${preview.value.target_section.class_name} للسنة ${targetYear.value}. متابعة؟`

  if (!await confirmAction(confirmText)) return

  applying.value = true

  try {
    const body = {
      course_section_id: Number(selectedSectionId.value),
      target_academic_year: targetYear.value,
      student_profile_ids: selectedIds.value,
    }
    if (overrideTargetId.value) body.target_section_id = Number(overrideTargetId.value)

    const response = await api('/promotions/apply', { method: 'POST', body: JSON.stringify(body) })
    notifySuccess(response.data.action === 'graduate'
      ? `تم تخريج ${response.data.moved} طالب وأرشفتهم.`
      : `تم نقل ${response.data.moved} طالب بنجاح.`)
    await loadPreview()
    sections.value = (await api(`/promotions/context?academic_year=${encodeURIComponent(sourceYear.value)}`)).data
  } catch (err) {
    notifyError(err.message)
  } finally {
    applying.value = false
  }
}
</script>

<template>
  <div class="workspace">
    <article class="form-card">
      <h3>{{ tr('نقل الطلبة إلى الصف التالي') }}</h3>
      <p class="muted">
        {{ tr('اختر الفصل الحالي والسنة الدراسية الجديدة، وسينتقل الطلبة إلى الصف الذي يليه. طلبة الصف الثاني عشر يتم تخريجهم وأرشفتهم، ويمكنك استرجاعهم في أي وقت من صفحة أرشيف الطلبة.') }}
      </p>
    </article>

    <div class="crud-form">
      <label>{{ tr('السنة الدراسية الحالية') }}
        <select v-model="sourceYear">
          <option v-for="year in academicYears" :key="year.id" :value="year.name">{{ year.name }}</option>
        </select>
      </label>
      <label>{{ tr('الفصل الحالي') }}
        <select v-model="selectedSectionId">
          <option value="">{{ tr('اختر الفصل') }}</option>
          <option v-for="section in sections" :key="section.id" :value="section.id">
            {{ section.class_name }} ({{ section.students_count }} {{ tr('طالب)') }}
          </option>
        </select>
      </label>
      <label>{{ tr('السنة الدراسية الجديدة') }}
        <select v-model="targetYear">
          <option v-for="year in targetYearOptions" :key="year" :value="year">{{ year }}</option>
        </select>
      </label>
      <label v-if="preview && !isGraduation">{{ tr('الفصل الهدف') }}
        <select v-model="overrideTargetId" @change="loadPreview">
          <option value="">
            {{ preview.target_section ? `تلقائي (${preview.target_section.class_name})` : tr('اختر الفصل') }}
          </option>
          <option v-for="section in targetSections" :key="section.id" :value="section.id">
            {{ section.class_name }}
          </option>
        </select>
      </label>
    </div>

    <p v-if="loading" class="muted">{{ tr('جاري تحميل قائمة الطلبة...') }}</p>

    <template v-else-if="preview">
      <p v-if="blocked" class="notice error">{{ preview.blocked_reason }}</p>

      <p v-else-if="isGraduation" class="notice warn">
        {{ tr('🎓 طلبة') }} {{ preview.source_section.class_name }} {{ tr('في الصف النهائي — سيتم تخريجهم وأرشفتهم بدل نقلهم.') }}
      </p>

      <p v-else class="notice success">
        {{ tr('سينتقل الطلبة من') }} <strong>{{ preview.source_section.class_name }}</strong>
        {{ tr('إلى') }} <strong>{{ preview.target_section.class_name }}</strong>
        {{ tr('للسنة الدراسية') }} <strong>{{ targetYear }}</strong>.
      </p>

      <p v-if="!preview.students.length" class="muted">{{ tr('لا يوجد طلبة نشطون في هذا الفصل.') }}</p>

      <div v-else class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>
                <input
                  type="checkbox"
                  :checked="selectedIds.length === preview.students.length"
                  @change="toggleAll"
                />
              </th>
              <th>{{ tr('اسم الطالب') }}</th>
              <th>{{ tr('رقم الطالب') }}</th>
              <th>{{ tr('الفصل الحالي') }}</th>
              <th>{{ isGraduation ? tr('الإجراء') : tr('الفصل الجديد') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="student in preview.students" :key="student.id">
              <td>
                <input
                  type="checkbox"
                  :checked="selectedIds.includes(student.id)"
                  @change="toggleStudent(student.id)"
                />
              </td>
              <td>{{ student.name }}</td>
              <td>{{ student.admission_no }}</td>
              <td>{{ student.current_class }}</td>
              <td>
                <span v-if="isGraduation" class="pill pill-warn">{{ tr('🎓 تخرج وأرشفة') }}</span>
                <span v-else-if="student.target_class" class="pill pill-success">{{ student.target_class }}</span>
                <span v-else class="muted">—</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="preview.students.length" class="actions">
        <button type="button" :class="{ danger: isGraduation }" :disabled="!canApply" @click="apply">
          <template v-if="applying">{{ tr('جاري التنفيذ...') }}</template>
          <template v-else-if="isGraduation">{{ tr('🎓 تخريج') }} {{ selectedIds.length }} {{ tr('طالب وأرشفتهم') }}</template>
          <template v-else>{{ tr('⬆ نقل') }} {{ selectedIds.length }} {{ tr('طالب') }}</template>
        </button>
      </div>
    </template>
  </div>
</template>

<style scoped>
.pill {
  border-radius: 999px;
  padding: 3px 10px;
  font-size: 12px;
  font-weight: 800;
  white-space: nowrap;
}

.pill-success { background: var(--app-good-soft); color: var(--app-good-text); }
.pill-warn { background: var(--app-warn-soft); color: var(--app-warn-text); }

.notice.warn {
  background: var(--app-warn-soft);
  border: 1px solid var(--app-warn-border);
  color: var(--app-warn-text);
}
</style>
