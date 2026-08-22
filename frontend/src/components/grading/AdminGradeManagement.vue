<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useApi } from '../../api.js'
import { notifyError, notifySuccess } from '../../notify.js'
import GradeEntryTable from './GradeEntryTable.vue'
import { confirmAction } from '../../confirm.js'
import { pick } from '../../i18n.js'
import { tr } from '../../phrases.js'

const { api } = useApi()

const terms = ['Quarter 1', 'Quarter 2', 'Quarter 3', 'Quarter 4']
const termLabels = {
  'Quarter 1': 'الفصل الدراسي الأول',
  'Quarter 2': 'الفصل الدراسي الثاني',
  'Quarter 3': 'الفصل الدراسي الثالث',
  'Quarter 4': 'الفصل الدراسي الرابع',
}

const academicYears = ref([])
const selectedYear = ref('')
const courses = ref([])
const loadingClasses = ref(false)

const openClassId = ref('')
const selectedCourse = ref(null)
const term = ref('Quarter 1')
const gradeData = ref(null)
const loadingSheet = ref(false)
const saving = ref(false)
const submitting = ref(false)
const unlocking = ref(false)

onMounted(async () => {
  try {
    const response = await api('/academic-years')
    academicYears.value = response.data
    selectedYear.value = academicYears.value.find((year) => year.is_active)?.name ?? academicYears.value[0]?.name ?? ''
  } catch (err) {
    notifyError(err.message)
  }
})

async function loadClasses() {
  if (!selectedYear.value) {
    courses.value = []
    return
  }

  loadingClasses.value = true
  try {
    const params = new URLSearchParams({ academic_year: selectedYear.value })
    const response = await api(`/grade-entry/context?${params.toString()}`)
    courses.value = response.data
  } catch (err) {
    notifyError(err.message)
    courses.value = []
  } finally {
    loadingClasses.value = false
  }
}

watch(selectedYear, () => {
  closeSheet()
  loadClasses()
}, { immediate: true })

function naturalKey(name) {
  const match = /^(\D*)(\d+)/.exec(name ?? '')
  return match ? [match[1], Number(match[2])] : [name ?? '', 0]
}

const classes = computed(() => {
  const groups = new Map()

  courses.value.forEach((course) => {
    const section = course.class_section
    const classId = section?.id ?? `no-section-${course.id}`
    if (!groups.has(classId)) {
      groups.set(classId, {
        id: classId,
        className: section?.class_name || section?.section_code || 'بدون اسم فصل',
        sectionCode: section?.section_code || '—',
        academicYear: section?.academic_year || selectedYear.value,
        subjects: [],
      })
    }
    groups.get(classId).subjects.push(course)
  })

  return Array.from(groups.values())
    .sort((a, b) => {
      const [aPrefix, aNum] = naturalKey(a.className)
      const [bPrefix, bNum] = naturalKey(b.className)
      if (aPrefix !== bPrefix) return aPrefix.localeCompare(bPrefix)
      return aNum - bNum
    })
})

function toggleClass(classId) {
  openClassId.value = openClassId.value === classId ? '' : classId
}

const modalClass = computed(() => classes.value.find((group) => group.id === openClassId.value) ?? null)

async function selectSubject(course) {
  selectedCourse.value = course
  openClassId.value = ''
  await loadSheet()
}

async function loadSheet() {
  if (!selectedCourse.value) return
  loadingSheet.value = true
  gradeData.value = null

  try {
    const params = new URLSearchParams({ term: term.value, academic_year: selectedYear.value })
    const response = await api(`/courses/${selectedCourse.value.id}/grade-entry?${params.toString()}`)
    gradeData.value = response.data
  } catch (err) {
    notifyError(err.message)
  } finally {
    loadingSheet.value = false
  }
}

watch(term, () => {
  if (selectedCourse.value) loadSheet()
})

function closeSheet() {
  selectedCourse.value = null
  gradeData.value = null
  openClassId.value = ''
}

async function saveScores(scores) {
  await api(`/courses/${selectedCourse.value.id}/grade-entry`, {
    method: 'PUT',
    body: JSON.stringify({ term: term.value, academic_year: selectedYear.value, scores }),
  })
}

async function handleSave(scores) {
  if (!selectedCourse.value) return
  saving.value = true

  try {
    await saveScores(scores)
    notifySuccess(pick('تم حفظ الدرجات بنجاح.', 'Marks saved.'))
    await loadSheet()
  } catch (err) {
    notifyError(err.message)
  } finally {
    saving.value = false
  }
}

async function handleSubmit(scores) {
  if (!selectedCourse.value) return
  submitting.value = true

  try {
    await saveScores(scores)
    await api(`/courses/${selectedCourse.value.id}/grade-entry/submit`, {
      method: 'POST',
      body: JSON.stringify({ term: term.value, academic_year: selectedYear.value }),
    })
    notifySuccess(pick('تم إرسال الدرجات نهائياً وقفل الكشف.', 'Marks submitted and the sheet is locked.'))
    await loadSheet()
  } catch (err) {
    notifyError(err.message)
  } finally {
    submitting.value = false
  }
}

async function unlockSheet() {
  if (!selectedCourse.value) return
  if (!await confirmAction(pick('سيتم إعادة فتح الكشف ليتمكن الأستاذ من تعديل الدرجات مجدداً.', 'The sheet will reopen so the teacher can edit the marks again.'))) return

  unlocking.value = true

  try {
    await api(`/courses/${selectedCourse.value.id}/grade-entry/unlock`, {
      method: 'POST',
      body: JSON.stringify({ term: term.value, academic_year: selectedYear.value }),
    })
    notifySuccess(pick('تم إعادة فتح الكشف. يستطيع الأستاذ التعديل الآن.', 'Sheet reopened. The teacher can edit it now.'))
    await loadSheet()
  } catch (err) {
    notifyError(err.message)
  } finally {
    unlocking.value = false
  }
}
</script>

<template>
  <div class="workspace grade-management">

    <template v-if="!selectedCourse">
      <div class="crud-form">
        <label>{{ tr('السنة الدراسية') }}
          <select v-model="selectedYear">
            <option value="">{{ tr('اختر السنة الدراسية') }}</option>
            <option v-for="year in academicYears" :key="year.id" :value="year.name">{{ year.name }}</option>
          </select>
        </label>
      </div>

      <template v-if="selectedYear">
        <p v-if="loadingClasses" class="muted">{{ tr('جاري تحميل الفصول...') }}</p>

        <div v-else-if="!classes.length" class="table-wrap">
          <p class="muted" style="padding: 16px">{{ tr('لا توجد فصول مسجلة لهذه السنة الدراسية (') }}{{ selectedYear }}).</p>
        </div>

        <div v-else class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>{{ tr('الفصل') }}</th>
                <th>{{ tr('رمز الفصل') }}</th>
                <th>{{ tr('السنة الدراسية') }}</th>
                <th>{{ tr('عدد المواد') }}</th>
                <th>{{ tr('المواد') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="group in classes" :key="group.id">
                <td>{{ group.className }}</td>
                <td>{{ group.sectionCode }}</td>
                <td>{{ group.academicYear }}</td>
                <td>{{ group.subjects.length }}</td>
                <td class="class-actions">
                  <button type="button" class="add-class-button" @click="toggleClass(group.id)" :aria-label="`${tr('عرض مواد')} ${group.className}`">+</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>

      <div v-if="modalClass" class="modal-backdrop" @click.self="openClassId = ''">
        <article class="student-modal">
          <div class="permission-card-header">
            <div>
              <h3>{{ tr('مواد') }} {{ modalClass.className }}</h3>
              <p class="muted">{{ modalClass.sectionCode }} · {{ modalClass.academicYear }}</p>
            </div>
            <button type="button" class="secondary compact" @click="openClassId = ''">{{ tr('إغلاق') }}</button>
          </div>
          <p v-if="!modalClass.subjects.length" class="muted">{{ tr('لا توجد مواد لهذا الفصل.') }}</p>
          <div v-else class="subject-modal-list">
            <button
              v-for="course in modalClass.subjects"
              :key="course.id"
              type="button"
              class="dropdown-item"
              @click="selectSubject(course)"
            >
              <span class="subject-name">{{ course.name }}</span>
              <small>{{ course.code }} <template v-if="course.teacher?.name">• {{ course.teacher.name }}</template></small>
            </button>
          </div>
        </article>
      </div>
    </template>

    <div v-if="selectedCourse" class="panel grade-sheet-panel">
      <div class="permission-card-header">
        <h3>
          {{ selectedCourse.class_section?.class_name || tr('بدون اسم فصل') }}
          — {{ selectedCourse.name }}
        </h3>
        <button type="button" class="secondary compact" @click="closeSheet">{{ tr('← العودة للفصول') }}</button>
      </div>

      <div class="sheet-meta">
        <span><strong>{{ tr('المادة:') }}</strong> {{ selectedCourse.code }} - {{ selectedCourse.name }}</span>
        <span><strong>{{ tr('الأستاذ:') }}</strong> {{ selectedCourse.teacher?.name || '—' }}</span>
        <span><strong>{{ tr('الفئة الدراسية:') }}</strong> {{ selectedCourse.grade_tier?.name || tr('غير محددة') }}</span>
        <span v-if="gradeData?.credits">
          <strong>{{ tr('الساعات المعتمدة:') }}</strong> {{ gradeData.credits.credits }}
          <template v-if="gradeData.credits.periods_per_week">
            ({{ gradeData.credits.periods_per_week }} {{ tr('حصص/أسبوع)') }}
          </template>
        </span>
        <label>
          <strong>{{ tr('الفصل الدراسي:') }}</strong>
          <select v-model="term">
            <option v-for="value in terms" :key="value" :value="value">{{ termLabels[value] }}</option>
          </select>
        </label>
        <button
          v-if="gradeData?.submission?.can_unlock"
          type="button"
          class="secondary compact"
          :disabled="unlocking"
          @click="unlockSheet"
        >
          {{ unlocking ? tr('جاري إعادة الفتح...') : tr('🔓 إعادة فتح للأستاذ') }}
        </button>
      </div>

      <p v-if="gradeData?.credits?.reason" class="notice warn">
        ⚠ {{ gradeData.credits.reason }}
      </p>

      <p v-if="loadingSheet" class="muted">{{ tr('جاري تحميل كشف الدرجات...') }}</p>

      <GradeEntryTable
        v-else-if="gradeData"
        :categories="gradeData.categories"
        :students="gradeData.students"
        :saving="saving"
        :submitting="submitting"
        :submission="gradeData.submission"
        @save="handleSave"
        @submit="handleSubmit"
      />
    </div>
  </div>
</template>

<style scoped>
.class-actions {
  text-align: end;
  position: relative;
}

.add-class-button {
  width: 34px;
  height: 34px;
  border-radius: 8px;
  border: 1px solid var(--app-border);
  background: var(--app-accent);
  color: var(--app-surface);
  font-size: 22px;
  font-weight: 900;
  line-height: 1;
  cursor: pointer;
}

.add-class-button:hover {
  background: #3641f5;
}

.grade-management .table-wrap {
  overflow: visible;
}

.subject-modal-list {
  display: grid;
  gap: 4px;
}

.dropdown-item {
  display: grid;
  gap: 2px;
  text-align: start;
  background: var(--app-surface);
  /* Buttons default to white text app-wide, which would be invisible here. */
  color: var(--app-text-strong);
  border: 1px solid var(--app-border);
  border-radius: 6px;
  padding: 9px 10px;
  cursor: pointer;
}

.dropdown-item:hover {
  background: var(--app-surface-muted);
  border-color: var(--app-accent);
  color: var(--app-text-strong);
}

.dropdown-item .subject-name {
  font-weight: 800;
}

.dropdown-item small {
  color: var(--app-text-muted);
}

.grade-sheet-panel {
  display: grid;
  gap: 12px;
}

.sheet-meta {
  display: flex;
  flex-wrap: wrap;
  gap: 18px;
  align-items: center;
  color: var(--app-text);
}

.sheet-meta select {
  margin-inline-start: 6px;
}

.muted.compact {
  padding: 8px 10px;
  font-size: 12px;
}
</style>
