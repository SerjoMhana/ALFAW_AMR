<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useApi } from '../../api.js'
import { notifyError, notifySuccess } from '../../notify.js'
import { pick } from '../../i18n.js'
import { tr } from '../../phrases.js'

/**
 * The school register: one class, one day at a time, kept by the office.
 *
 * The month view alongside it is the same grid that gets printed — blank for
 * the supervisors to fill in by hand through the month, and filled once the
 * paper has come back and been entered here.
 */
const { api, apiBlob } = useApi()

const sections = ref([])
const sectionId = ref('')
const date = ref(new Date().toISOString().slice(0, 10))
const rows = ref([])
const loadingDay = ref(false)
const saving = ref(false)

const month = ref(new Date().getMonth() + 1)
const year = ref(new Date().getFullYear())
const sheet = ref(null)
const loadingMonth = ref(false)
const printing = ref('')
const view = ref('day')

const statuses = [
  { value: 'present', ar: 'حاضر', en: 'Present' },
  { value: 'absent', ar: 'غائب', en: 'Absent' },
  { value: 'late', ar: 'متأخر', en: 'Late' },
  { value: 'excused', ar: 'بعذر', en: 'Excused' },
]

const monthNames = computed(() => Array.from({ length: 12 }, (_, i) => ({
  value: i + 1,
  label: new Date(2000, i, 1).toLocaleString(undefined, { month: 'long' }),
})))

const dayTotals = computed(() => {
  const totals = { present: 0, absent: 0, late: 0, excused: 0, unmarked: 0 }

  for (const row of rows.value) {
    if (row.status) totals[row.status] += 1
    else totals.unmarked += 1
  }

  return totals
})

onMounted(loadSections)

watch([sectionId, date], () => {
  if (sectionId.value) loadDay()
})

watch([sectionId, month, year], () => {
  if (sectionId.value && view.value === 'month') loadMonth()
})

watch(view, (value) => {
  if (value === 'month' && sectionId.value && !sheet.value) loadMonth()
})

async function loadSections() {
  try {
    const response = await api('/course-sections')
    sections.value = response.data ?? response
    if (!sectionId.value && sections.value.length) sectionId.value = sections.value[0].id
  } catch (err) {
    notifyError(err.message)
  }
}

async function loadDay() {
  loadingDay.value = true

  try {
    const response = await api(`/course-sections/${sectionId.value}/attendance?date=${date.value}`)
    rows.value = response.students.map((entry) => ({
      student_profile_id: entry.student_profile.id,
      name: entry.student_profile.full_name || entry.student_profile.user?.name,
      admission_no: entry.student_profile.admission_no || entry.student_profile.student_number,
      // Left blank rather than assumed present: an unmarked day should look
      // unmarked, not like a class that all turned up.
      status: entry.record?.status ?? '',
      notes: entry.record?.notes ?? '',
    }))
  } catch (err) {
    notifyError(err.message)
    rows.value = []
  } finally {
    loadingDay.value = false
  }
}

function markAll(status) {
  rows.value.forEach((row) => { row.status = status })
}

async function saveDay() {
  const marked = rows.value.filter((row) => row.status)

  if (!marked.length) {
    notifyError(pick('لم تُحدَّد حالة أي طالب.', 'No student has been marked yet.'))

    return
  }

  saving.value = true

  try {
    await api(`/course-sections/${sectionId.value}/attendance`, {
      method: 'PUT',
      body: JSON.stringify({
        attendance_date: date.value,
        records: marked.map((row) => ({
          student_profile_id: row.student_profile_id,
          status: row.status,
          notes: row.notes || null,
        })),
      }),
    })
    notifySuccess(pick('تم حفظ حضور اليوم.', 'The day has been saved.'))
    if (sheet.value) await loadMonth()
  } catch (err) {
    notifyError(err.message)
  } finally {
    saving.value = false
  }
}

async function loadMonth() {
  loadingMonth.value = true

  try {
    const response = await api(
      `/course-sections/${sectionId.value}/attendance/month?year=${year.value}&month=${month.value}`,
    )
    sheet.value = response.data
  } catch (err) {
    notifyError(err.message)
    sheet.value = null
  } finally {
    loadingMonth.value = false
  }
}

async function print(filled) {
  printing.value = filled ? 'filled' : 'blank'

  try {
    const blob = await apiBlob(
      `/course-sections/${sectionId.value}/attendance/month.pdf`
      + `?year=${year.value}&month=${month.value}${filled ? '&filled=1' : ''}`,
    )
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = `attendance-${year.value}-${String(month.value).padStart(2, '0')}${filled ? '' : '-blank'}.pdf`
    link.click()
    URL.revokeObjectURL(url)
  } catch (err) {
    notifyError(err.message)
  } finally {
    printing.value = ''
  }
}

function statusLabel(value) {
  const found = statuses.find((status) => status.value === value)

  return found ? pick(found.ar, found.en) : ''
}
</script>

<template>
  <div class="workspace">
    <article class="panel">
      <div class="permission-card-header">
        <div>
          <h3>{{ tr('الحضور والغياب') }}</h3>
          <p class="muted">
            {{ tr('سجّل حضور كل فصل يوماً بيوم، واطبع كشف الشهر ليستعمله المشرفون ثم أدخل ما سجّلوه.') }}
          </p>
        </div>
        <div class="actions">
          <button type="button" :class="view === 'day' ? '' : 'secondary'" @click="view = 'day'">
            {{ tr('تسجيل اليوم') }}
          </button>
          <button type="button" :class="view === 'month' ? '' : 'secondary'" @click="view = 'month'">
            {{ tr('كشف الشهر') }}
          </button>
        </div>
      </div>

      <div class="crud-form">
        <label class="grow">{{ tr('الفصل') }}
          <select v-model="sectionId">
            <option value="">{{ tr('اختر الفصل') }}</option>
            <option v-for="section in sections" :key="section.id" :value="section.id">
              {{ section.class_name || section.section_code }} — {{ section.academic_year }}
            </option>
          </select>
        </label>

        <label v-if="view === 'day'">{{ tr('التاريخ') }}
          <input v-model="date" type="date" />
        </label>

        <template v-else>
          <label>{{ tr('الشهر') }}
            <select v-model.number="month">
              <option v-for="row in monthNames" :key="row.value" :value="row.value">{{ row.label }}</option>
            </select>
          </label>
          <label>{{ tr('السنة') }}
            <input v-model.number="year" type="number" min="2000" max="2100" />
          </label>
        </template>
      </div>
    </article>

    <!-- ------------------------------ one day ------------------------------ -->
    <template v-if="view === 'day'">
      <p v-if="loadingDay" class="muted">{{ tr('جاري التحميل...') }}</p>

      <template v-else-if="sectionId">
        <article v-if="rows.length" class="panel">
          <div class="crud-form">
            <button type="button" class="secondary" @click="markAll('present')">{{ tr('الجميع حاضر') }}</button>
            <button type="button" class="secondary" @click="markAll('absent')">{{ tr('الجميع غائب') }}</button>
            <button type="button" class="secondary" @click="markAll('')">{{ tr('مسح التحديد') }}</button>
            <span class="muted">
              {{ tr('حاضر') }}: <strong>{{ dayTotals.present }}</strong> ·
              {{ tr('غائب') }}: <strong>{{ dayTotals.absent }}</strong> ·
              {{ tr('بلا تحديد') }}: <strong>{{ dayTotals.unmarked }}</strong>
            </span>
          </div>

          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>#</th>
                  <th>{{ tr('الطالب') }}</th>
                  <th>{{ tr('رقم القبول') }}</th>
                  <th>{{ tr('الحالة') }}</th>
                  <th>{{ tr('ملاحظات') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(row, index) in rows" :key="row.student_profile_id">
                  <td>{{ index + 1 }}</td>
                  <td>{{ row.name }}</td>
                  <td>{{ row.admission_no }}</td>
                  <td>
                    <div class="status-picker">
                      <button
                        v-for="status in statuses"
                        :key="status.value"
                        type="button"
                        :class="['status-chip', status.value, row.status === status.value ? 'on' : '']"
                        @click="row.status = row.status === status.value ? '' : status.value"
                      >
                        {{ pick(status.ar, status.en) }}
                      </button>
                    </div>
                  </td>
                  <td><input v-model="row.notes" :placeholder="tr('اختياري')" /></td>
                </tr>
              </tbody>
            </table>
          </div>

          <div class="actions">
            <button type="button" :disabled="saving" @click="saveDay">
              {{ saving ? tr('جاري الحفظ...') : tr('حفظ حضور اليوم') }}
            </button>
          </div>
        </article>

        <p v-else class="muted">{{ tr('لا يوجد طلبة مسجلون في هذا الفصل.') }}</p>
      </template>
    </template>

    <!-- ------------------------------ the month ------------------------------ -->
    <template v-else>
      <article class="panel">
        <div class="actions">
          <button type="button" :disabled="!sectionId || printing === 'blank'" @click="print(false)">
            {{ printing === 'blank' ? tr('جاري التجهيز...') : tr('طباعة كشف فارغ للمشرفين') }}
          </button>
          <button type="button" class="secondary" :disabled="!sectionId || printing === 'filled'" @click="print(true)">
            {{ printing === 'filled' ? tr('جاري التجهيز...') : tr('طباعة الكشف معبّأً') }}
          </button>
        </div>
        <p class="muted">
          {{ tr('الكشف الفارغ يحمل أسماء الطلبة وأيام الشهر ليُملأ بخط اليد. الكشف المعبّأ يعرض ما أُدخل في المنظومة مع مجاميع كل طالب.') }}
        </p>
      </article>

      <p v-if="loadingMonth" class="muted">{{ tr('جاري التحميل...') }}</p>

      <article v-else-if="sheet" class="panel">
        <h3>{{ sheet.section.name }} — {{ sheet.month_label }}</h3>

        <div class="table-wrap">
          <table class="month-grid">
            <thead>
              <tr>
                <th>#</th>
                <th class="name-col">{{ tr('الطالب') }}</th>
                <th
                  v-for="day in sheet.days"
                  :key="day.date"
                  :class="{ weekend: day.is_weekend }"
                >{{ day.day }}</th>
                <th>{{ tr('غياب') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="student in sheet.students" :key="student.student_profile_id">
                <td>{{ student.no }}</td>
                <td class="name-col">{{ student.name }}</td>
                <td
                  v-for="day in sheet.days"
                  :key="day.date"
                  :class="['day-cell', { weekend: day.is_weekend, absent: student.marks[day.date]?.status === 'absent' }]"
                  :title="student.marks[day.date] ? statusLabel(student.marks[day.date].status) : ''"
                >{{ student.marks[day.date]?.mark ?? '' }}</td>
                <td><strong>{{ student.totals.absent }}</strong></td>
              </tr>
              <tr v-if="!sheet.students.length">
                <td :colspan="sheet.days.length + 3" class="muted">
                  {{ tr('لا يوجد طلبة مسجلون في هذا الفصل.') }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <p class="muted">
          {{ tr('الأيام المسجّلة') }}: <strong>{{ sheet.recorded_dates.length }}</strong>
        </p>
      </article>
    </template>
  </div>
</template>

<style scoped>
.status-picker {
  display: flex;
  gap: 0.3rem;
  flex-wrap: wrap;
}

.status-chip {
  padding: 0.2rem 0.6rem;
  border-radius: 999px;
  font-size: 0.75rem;
  font-weight: 600;
  background: var(--app-surface-muted);
  color: var(--app-text-muted);
  border: 1px solid var(--app-border);
  box-shadow: none;
}

.status-chip:hover { background: var(--app-border); }

.status-chip.on.present { background: var(--app-good-soft); color: var(--app-good-text); border-color: transparent; }
.status-chip.on.absent { background: var(--app-danger-soft); color: var(--app-danger-text); border-color: transparent; }
.status-chip.on.late { background: var(--app-warn-soft); color: var(--app-warn-text); border-color: transparent; }
.status-chip.on.excused { background: var(--app-accent-soft); color: var(--app-accent); border-color: transparent; }

.month-grid { min-width: 900px; }
.month-grid th, .month-grid td { padding: 0.35rem 0.2rem; text-align: center; font-size: 0.78rem; }
.month-grid .name-col { text-align: start; min-width: 150px; white-space: nowrap; }
.month-grid .weekend { background: var(--app-surface-muted); color: var(--app-text-muted); }
.month-grid .day-cell { font-weight: 700; }
.month-grid .day-cell.absent { color: var(--app-danger-text); }
</style>
