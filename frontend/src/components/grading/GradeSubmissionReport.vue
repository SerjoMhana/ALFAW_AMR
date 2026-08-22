<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useApi } from '../../api.js'
import { notifyError } from '../../notify.js'
import { tr } from '../../phrases.js'

const { api, apiBlob } = useApi()

const terms = ['Quarter 1', 'Quarter 2', 'Quarter 3', 'Quarter 4']
const termLabels = {
  'Quarter 1': 'الفصل الأول (Quarter 1)',
  'Quarter 2': 'الفصل الثاني (Quarter 2)',
  'Quarter 3': 'الفصل الثالث (Quarter 3)',
  'Quarter 4': 'الفصل الرابع (Quarter 4)',
}
const statusLabels = {
  submitted: 'تم الإرسال',
  partial: 'بدأ ولم يرسل',
  not_started: 'لم يبدأ',
}

const academicYears = ref([])
const classes = ref([])
const filters = ref({
  academic_year: '',
  type: 'quarter',
  term: 'Quarter 1',
  semester: 1,
  class_section_id: '',
})

const report = ref(null)
const loading = ref(false)
const openTeacherIds = ref([])

const query = computed(() => {
  const params = new URLSearchParams({
    academic_year: filters.value.academic_year,
    type: filters.value.type,
  })
  if (filters.value.type === 'quarter') params.set('term', filters.value.term)
  else params.set('semester', String(filters.value.semester))
  if (filters.value.class_section_id) params.set('class_section_id', filters.value.class_section_id)

  return params
})

const pendingTeachers = computed(() => (report.value?.teachers ?? []).filter((t) => !t.is_complete))
const doneTeachers = computed(() => (report.value?.teachers ?? []).filter((t) => t.is_complete))

onMounted(async () => {
  try {
    const [yearsResponse, sectionsResponse] = await Promise.all([
      api('/academic-years'),
      api('/course-sections'),
    ])
    academicYears.value = yearsResponse.data
    classes.value = sectionsResponse.data
    filters.value.academic_year = academicYears.value.find((year) => year.is_active)?.name
      ?? academicYears.value[0]?.name
      ?? ''
  } catch (err) {
    notifyError(err.message)
  }
})

watch(() => filters.value.academic_year, (year) => {
  if (year) load()
})

async function load() {
  if (!filters.value.academic_year) return
  loading.value = true

  try {
    const response = await api(`/reports/grade-submissions?${query.value.toString()}`)
    report.value = response.data
    openTeacherIds.value = []
  } catch (err) {
    notifyError(err.message)
    report.value = null
  } finally {
    loading.value = false
  }
}

function toggleTeacher(teacherId) {
  openTeacherIds.value = openTeacherIds.value.includes(teacherId)
    ? openTeacherIds.value.filter((id) => id !== teacherId)
    : [...openTeacherIds.value, teacherId]
}

async function downloadPdf() {

  try {
    const blob = await apiBlob(`/reports/grade-submissions/pdf?${query.value.toString()}`)
    window.open(URL.createObjectURL(blob), '_blank')
  } catch (err) {
    notifyError(err.message)
  }
}

const classesForYear = computed(() =>
  classes.value.filter((section) => section.academic_year === filters.value.academic_year),
)
</script>

<template>
  <div class="workspace">
    <div class="crud-form">
      <label>{{ tr('السنة الدراسية') }}
        <select v-model="filters.academic_year">
          <option v-for="year in academicYears" :key="year.id" :value="year.name">{{ year.name }}</option>
        </select>
      </label>

      <label>{{ tr('نوع التقرير') }}
        <select v-model="filters.type">
          <option value="quarter">{{ tr('حسب الكوارتر') }}</option>
          <option value="semester">{{ tr('حسب السيمستر') }}</option>
        </select>
      </label>

      <label v-if="filters.type === 'quarter'">{{ tr('الفترة') }}
        <select v-model="filters.term">
          <option v-for="value in terms" :key="value" :value="value">{{ termLabels[value] }}</option>
        </select>
      </label>

      <label v-else>{{ tr('السيمستر') }}
        <select v-model.number="filters.semester">
          <option :value="1">{{ tr('السيمستر الأول (كوارتر 1 + 2)') }}</option>
          <option :value="2">{{ tr('السيمستر الثاني (كوارتر 3 + 4)') }}</option>
        </select>
      </label>

      <label>{{ tr('الفصل') }}
        <select v-model="filters.class_section_id">
          <option value="">{{ tr('كل الفصول') }}</option>
          <option v-for="section in classesForYear" :key="section.id" :value="section.id">
            {{ section.class_name || section.section_code }}
          </option>
        </select>
      </label>

      <button type="button" :disabled="loading || !filters.academic_year" @click="load">
        {{ loading ? tr('جاري التحميل...') : tr('عرض التقرير') }}
      </button>
      <button type="button" class="secondary" :disabled="!report" @click="downloadPdf">
        {{ tr('طباعة PDF') }}
      </button>
    </div>


    <template v-if="report">
      <div class="stats-grid">
        <article class="stat-card stat-blue">
          <span>{{ tr('إجمالي المواد') }}</span><strong>{{ report.summary.total }}</strong>
        </article>
        <article class="stat-card stat-green">
          <span>{{ tr('تم الإرسال') }}</span><strong>{{ report.summary.submitted }}</strong>
        </article>
        <article class="stat-card stat-amber">
          <span>{{ tr('بدأ ولم يرسل') }}</span><strong>{{ report.summary.partial }}</strong>
        </article>
        <article class="stat-card stat-rose">
          <span>{{ tr('لم يبدأ') }}</span><strong>{{ report.summary.not_started }}</strong>
        </article>
        <article class="stat-card stat-violet">
          <span>{{ tr('نسبة الإنجاز') }}</span><strong>{{ report.summary.completion_percent }}%</strong>
        </article>
      </div>

      <article class="form-card">
        <h3>{{ tr('أساتذة لم يكملوا الإدخال (') }}{{ pendingTeachers.length }})</h3>

        <p v-if="!pendingTeachers.length" class="muted">
          {{ tr('ممتاز — جميع الأساتذة أرسلوا درجات كل موادهم لهذه الفترة.') }}
        </p>

        <div v-else class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>{{ tr('الأستاذ') }}</th>
                <th>{{ tr('المتبقي') }}</th>
                <th>{{ tr('نسبة الإنجاز') }}</th>
                <th>{{ tr('المواد المتبقية') }}</th>
              </tr>
            </thead>
            <tbody>
              <template v-for="teacher in pendingTeachers" :key="`pending-${teacher.teacher_id}`">
                <tr>
                  <td><strong>{{ teacher.teacher_name }}</strong></td>
                  <td><span class="pill pill-danger">{{ teacher.pending_count }} {{ tr('من') }} {{ teacher.total }}</span></td>
                  <td>{{ teacher.completion_percent }}%</td>
                  <td>
                    <button type="button" class="secondary compact" @click="toggleTeacher(teacher.teacher_id)">
                      {{ openTeacherIds.includes(teacher.teacher_id) ? tr('إخفاء التفاصيل') : tr('عرض التفاصيل') }}
                    </button>
                  </td>
                </tr>
                <tr v-if="openTeacherIds.includes(teacher.teacher_id)">
                  <td colspan="4" class="detail-cell">
                    <table class="inner-table">
                      <thead>
                        <tr><th>{{ tr('المادة') }}</th><th>{{ tr('الفصل') }}</th><th>{{ tr('الفترة') }}</th><th>{{ tr('الحالة') }}</th></tr>
                      </thead>
                      <tbody>
                        <tr v-for="row in teacher.pending" :key="`${row.course_id}-${row.term}`">
                          <td>{{ row.subject_name }} <small class="muted">{{ row.subject_code }}</small></td>
                          <td>{{ row.class_name }}</td>
                          <td>{{ row.term }}</td>
                          <td>
                            <span class="pill" :class="row.status === 'partial' ? 'pill-warn' : 'pill-danger'">
                              {{ statusLabels[row.status] }}
                            </span>
                          </td>
                        </tr>
                      </tbody>
                    </table>
                  </td>
                </tr>
              </template>
            </tbody>
          </table>
        </div>
      </article>

      <article class="form-card">
        <h3>{{ tr('أساتذة أكملوا الإدخال (') }}{{ doneTeachers.length }})</h3>

        <p v-if="!doneTeachers.length" class="muted">{{ tr('لا يوجد أستاذ أكمل جميع مواده بعد.') }}</p>

        <div v-else class="table-wrap">
          <table>
            <thead>
              <tr><th>{{ tr('الأستاذ') }}</th><th>{{ tr('المواد المرسلة') }}</th><th>{{ tr('المواد') }}</th></tr>
            </thead>
            <tbody>
              <tr v-for="teacher in doneTeachers" :key="`done-${teacher.teacher_id}`">
                <td><strong>{{ teacher.teacher_name }}</strong></td>
                <td><span class="pill pill-success">{{ teacher.submitted_count }} / {{ teacher.total }}</span></td>
                <td class="muted">
                  {{ [...new Set(teacher.submitted.map((row) => `${row.subject_name} (${row.class_name})`))].join('، ') }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </article>

      <article v-if="report.unassigned.length" class="form-card">
        <h3>{{ tr('مواد بدون أستاذ (') }}{{ report.unassigned.length }})</h3>
        <p class="muted">{{ tr('هذه المواد غير مسندة لأي أستاذ، لذلك لن يدخل أحد درجاتها.') }}</p>
        <div class="table-wrap">
          <table>
            <thead>
              <tr><th>{{ tr('المادة') }}</th><th>{{ tr('الفصل') }}</th><th>{{ tr('الفترة') }}</th><th>{{ tr('الحالة') }}</th></tr>
            </thead>
            <tbody>
              <tr v-for="row in report.unassigned" :key="`un-${row.course_id}-${row.term}`">
                <td>{{ row.subject_name }} <small class="muted">{{ row.subject_code }}</small></td>
                <td>{{ row.class_name }}</td>
                <td>{{ row.term }}</td>
                <td><span class="pill pill-danger">{{ statusLabels[row.status] }}</span></td>
              </tr>
            </tbody>
          </table>
        </div>
      </article>
    </template>
  </div>
</template>

<style scoped>
.pill {
  display: inline-block;
  border-radius: 999px;
  padding: 3px 10px;
  font-size: 12px;
  font-weight: 800;
  white-space: nowrap;
}

.pill-success { background: var(--app-good-soft); color: var(--app-good-text); }
.pill-warn { background: var(--app-warn-soft); color: var(--app-warn-text); }
.pill-danger { background: var(--app-danger-soft); color: var(--app-danger-text); }

.detail-cell { background: var(--app-surface-muted); padding: 10px 14px; }

.inner-table { width: 100%; border-collapse: collapse; }
.inner-table th,
.inner-table td { padding: 6px 8px; text-align: start; border-bottom: 1px solid var(--app-border); }
.inner-table th { font-size: 12px; color: var(--app-text-muted); }
.inner-table tr:last-child td { border-bottom: 0; }
</style>
