<script setup>
import { onMounted, ref } from 'vue'
import { useApi } from '../../api.js'
import { notifyError, notifySuccess } from '../../notify.js'
import GradeEntryTable from './GradeEntryTable.vue'
import { pick } from '../../i18n.js'
import { tr } from '../../phrases.js'

const { api } = useApi()

const sections = ref([])
const selectedSectionId = ref('')
const term = ref('')
const academicYear = ref('')
const gradeData = ref(null)
const loading = ref(false)
const saving = ref(false)
const submitting = ref(false)

const terms = ['Quarter 1', 'Quarter 2', 'Quarter 3', 'Quarter 4']

onMounted(async () => {
  try {
    const response = await api('/grade-entry/context')
    sections.value = response.data
    // The admin's active year is authoritative. Teachers neither choose nor
    // override it; switching the year in Settings changes this automatically.
    academicYear.value = response.academic_year
      ?? sections.value[0]?.class_section?.academic_year
      ?? ''
  } catch (err) {
    notifyError(err.message)
  }
})

function onTermChange() {
  selectedSectionId.value = ''
  gradeData.value = null
}

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
    <p class="active-year-line">
      <strong>{{ tr('السنة الدراسية المفعّلة:') }}</strong>
      {{ academicYear || tr('لا توجد سنة مفعّلة') }}
    </p>
    <div class="crud-form">
      <label>{{ tr('الكورتر') }}
        <select v-model="term" @change="onTermChange">
          <option value="">{{ tr('اختر الكورتر أولاً') }}</option>
          <option v-for="value in terms" :key="value" :value="value">{{ value }}</option>
        </select>
      </label>
      <label>{{ tr('الفصل والمادة') }}
        <select v-model="selectedSectionId" :disabled="!term" @change="onSectionChange">
          <option value="">{{ term ? tr('اختر الفصل والمادة') : tr('اختر الكورتر أولاً') }}</option>
          <option v-for="course in sections" :key="course.id" :value="course.id">
            {{ course.class_section?.class_name || course.class_section?.section_code }} — {{ course.name }}
          </option>
        </select>
      </label>
      <button type="button" :disabled="!term || !selectedSectionId || !academicYear" @click="loadGradeEntry">
        {{ loading ? tr('جاري التحميل...') : tr('عرض سجل الدرجات') }}
      </button>
    </div>

    <p v-if="academicYear && !sections.length" class="notice warn">
      {{ tr('لا توجد مواد مرتبطة بحسابك في السنة الدراسية المفعّلة.') }}
    </p>


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

.active-year-line {
  margin: 0;
  color: var(--app-text);
}

.notice.warn {
  background: var(--app-warn-soft);
  border: 1px solid var(--app-warn-border);
  color: var(--app-warn-text);
}
</style>
