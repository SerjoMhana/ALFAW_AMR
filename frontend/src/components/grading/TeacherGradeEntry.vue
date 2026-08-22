<script setup>
import { computed, onMounted, ref } from 'vue'
import { useApi } from '../../api.js'
import { notifyError, notifySuccess } from '../../notify.js'
import GradeEntryTable from './GradeEntryTable.vue'
import { pick } from '../../i18n.js'
import { tr } from '../../phrases.js'

const { api } = useApi()

const sections = ref([])
const academicYears = ref([])
const selectedSectionId = ref('')
const term = ref('Quarter 1')
const academicYear = ref('')
const gradeData = ref(null)
const loading = ref(false)
const saving = ref(false)
const submitting = ref(false)

const terms = ['Quarter 1', 'Quarter 2', 'Quarter 3', 'Quarter 4']

// Years to offer: the configured list when readable, otherwise whatever years
// the teacher's own subjects belong to.
const yearOptions = computed(() => [
  ...new Set([
    ...academicYears.value.map((year) => year.name),
    ...sections.value.map((course) => course.class_section?.academic_year),
  ].filter(Boolean)),
])

onMounted(async () => {
  // Settled rather than all: /academic-years needs settings.view, which teachers
  // lack, and that rejection must not take the course list down with it.
  const [contextResult, yearsResult] = await Promise.allSettled([
    api('/grade-entry/context'),
    api('/academic-years'),
  ])

  if (contextResult.status === 'fulfilled') {
    sections.value = contextResult.value.data
  } else {
    notifyError(contextResult.reason.message)
  }

  if (yearsResult.status === 'fulfilled') {
    academicYears.value = yearsResult.value.data
  }

  academicYear.value = academicYears.value.find((year) => year.is_active)?.name
    ?? yearOptions.value[0]
    ?? ''
})

function onSectionChange() {
  const course = sections.value.find((item) => String(item.id) === String(selectedSectionId.value))
  if (course) academicYear.value = course.class_section?.academic_year ?? ''
  gradeData.value = null
}

async function loadGradeEntry() {
  if (!selectedSectionId.value) return
  loading.value = true

  try {
    const params = new URLSearchParams({ term: term.value, academic_year: academicYear.value })
    const response = await api(`/courses/${selectedSectionId.value}/grade-entry?${params.toString()}`)
    gradeData.value = response.data
  } catch (err) {
    notifyError(err.message)
    gradeData.value = null
  } finally {
    loading.value = false
  }
}

async function saveScores(scores) {
  await api(`/courses/${selectedSectionId.value}/grade-entry`, {
    method: 'PUT',
    body: JSON.stringify({ term: term.value, academic_year: academicYear.value, scores }),
  })
}

async function handleSave(scores) {
  saving.value = true

  try {
    await saveScores(scores)
    notifySuccess(pick('تم حفظ الدرجات. يمكنك التعديل عليها لاحقاً.', 'Marks saved. You can still edit them.'))
    await loadGradeEntry()
  } catch (err) {
    notifyError(err.message)
  } finally {
    saving.value = false
  }
}

async function handleSubmit(scores) {
  submitting.value = true

  try {
    await saveScores(scores)
    await api(`/courses/${selectedSectionId.value}/grade-entry/submit`, {
      method: 'POST',
      body: JSON.stringify({ term: term.value, academic_year: academicYear.value }),
    })
    notifySuccess(pick('تم إرسال الدرجات نهائياً. لم يعد بإمكانك تعديلها.', 'Marks submitted. You can no longer change them.'))
    await loadGradeEntry()
  } catch (err) {
    notifyError(err.message)
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="workspace">
    <div class="crud-form">
      <label>Academic Year
        <select v-model="academicYear">
          <option value="">Select academic year</option>
          <option v-for="year in yearOptions" :key="year" :value="year">{{ year }}</option>
        </select>
      </label>
      <label>Term
        <select v-model="term">
          <option v-for="value in terms" :key="value">{{ value }}</option>
        </select>
      </label>
      <label>Course
        <select v-model="selectedSectionId" @change="onSectionChange">
          <option value="">Select course</option>
          <option v-for="course in sections" :key="course.id" :value="course.id">
            {{ course.class_section?.class_name || course.class_section?.section_code }} - {{ course.name }}
            {{ course.grade_tier ? `(${course.grade_tier.name})` : '(no grade tier configured)' }}
          </option>
        </select>
      </label>
      <button type="button" :disabled="!selectedSectionId || !academicYear" @click="loadGradeEntry">
        {{ loading ? 'Loading...' : 'Load Grade Entry' }}
      </button>
    </div>


    <p v-if="gradeData?.credits" class="sheet-credits">
      <strong>{{ tr('الساعات المعتمدة لهذه المادة:') }}</strong> {{ gradeData.credits.credits }}
      <template v-if="gradeData.credits.periods_per_week">
        · {{ gradeData.credits.periods_per_week }} {{ tr('حصص/أسبوع') }}
      </template>
    </p>

    <p v-if="gradeData?.credits?.reason" class="notice warn">
      ⚠ {{ gradeData.credits.reason }}
    </p>

    <GradeEntryTable
      v-if="gradeData"
      :categories="gradeData.categories"
      :students="gradeData.students"
      :saving="saving"
      :submitting="submitting"
      :submission="gradeData.submission"
      @save="handleSave"
      @submit="handleSubmit"
    />
  </div>
</template>

<style scoped>
.sheet-credits {
  color: var(--app-text);
  font-size: 13px;
}

.notice.warn {
  background: var(--app-warn-soft);
  border: 1px solid var(--app-warn-border);
  color: var(--app-warn-text);
}
</style>
