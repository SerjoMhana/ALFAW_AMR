<script setup>
import { onMounted, ref } from 'vue'
import { useApi } from '../../api.js'
import { notifyError } from '../../notify.js'
import { PERIOD_LABELS, money } from './money.js'
import { tr } from '../../phrases.js'

const { api } = useApi()

const report = ref('outstanding')
const academicYears = ref([])
const academicYear = ref('')
const classes = ref([])
const classId = ref('')
const from = ref('')
const to = ref('')
const period = ref('day')
const data = ref(null)
const loading = ref(false)

const bucketLabels = {
  current: 'غير متأخر',
  week: 'متأخر أسبوع',
  month: 'متأخر شهر',
  quarter: 'متأخر 3 أشهر',
  older: 'أكثر من 3 أشهر',
}

onMounted(async () => {
  try {
    const [years, classList] = await Promise.all([api('/academic-years'), api('/finance/classes')])
    academicYears.value = years.data
    classes.value = classList.data
    academicYear.value = academicYears.value.find((year) => year.is_active)?.name
      ?? academicYears.value[0]?.name
      ?? ''

    const today = new Date().toISOString().slice(0, 10)
    from.value = today
    to.value = today

    await run()
  } catch (err) {
    notifyError(err.message)
  }
})

/**
 * Snaps the date range to the current month or year and groups by it, which is
 * what an accountant reaches for most often.
 */
function setRange(scope) {
  const now = new Date()
  const start = scope === 'year' ? new Date(now.getFullYear(), 0, 1) : new Date(now.getFullYear(), now.getMonth(), 1)
  const end = scope === 'year' ? new Date(now.getFullYear(), 11, 31) : new Date(now.getFullYear(), now.getMonth() + 1, 0)

  from.value = start.toISOString().slice(0, 10)
  to.value = end.toISOString().slice(0, 10)
  period.value = scope === 'year' ? 'month' : 'day'
  run()
}

async function run() {
  // Nothing to report on before the school has an academic year; asking anyway
  // just bounces back a validation error the admin cannot act on.
  if (report.value !== 'collections' && !academicYear.value) {
    data.value = null
    return
  }

  loading.value = true
  data.value = null

  try {
    if (report.value === 'collections') {
      const params = new URLSearchParams({ from: from.value, to: to.value, period: period.value })
      data.value = (await api(`/finance/reports/collections?${params.toString()}`)).data
    } else {
      const params = new URLSearchParams({ academic_year: academicYear.value })
      if (report.value === 'outstanding' && classId.value) params.set('course_section_id', classId.value)
      data.value = (await api(`/finance/reports/${report.value}?${params.toString()}`)).data
    }
  } catch (err) {
    notifyError(err.message)
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="workspace">
    <article class="form-card">
      <h3>{{ tr('التقارير المالية') }}</h3>
      <div class="crud-form">
        <label>{{ tr('التقرير') }}
          <select v-model="report" @change="run">
            <option value="outstanding">{{ tr('الأرصدة غير المسددة') }}</option>
            <option value="aged">{{ tr('تقرير المتأخرات') }}</option>
            <option value="collections">{{ tr('المقبوضات خلال فترة') }}</option>
          </select>
        </label>

        <template v-if="report !== 'collections'">
          <label>{{ tr('السنة الدراسية') }}
            <select v-model="academicYear" @change="run">
              <option v-for="year in academicYears" :key="year.id" :value="year.name">{{ year.name }}</option>
            </select>
          </label>
          <label v-if="report === 'outstanding'">{{ tr('الفصل') }}
            <select v-model="classId" @change="run">
              <option value="">{{ tr('كل الفصول') }}</option>
              <option v-for="row in classes" :key="row.id" :value="row.id">
                {{ row.class_name || row.section_code }} ({{ row.academic_year }})
              </option>
            </select>
          </label>
        </template>

        <template v-else>
          <label>{{ tr('من') }} <input v-model="from" type="date" @change="run" /></label>
          <label>{{ tr('إلى') }} <input v-model="to" type="date" @change="run" /></label>
          <label>{{ tr('التجميع') }}
            <select v-model="period" @change="run">
              <option v-for="(label, key) in PERIOD_LABELS" :key="key" :value="key">{{ label }}</option>
            </select>
          </label>
          <button type="button" class="secondary" @click="setRange('month')">{{ tr('هذا الشهر') }}</button>
          <button type="button" class="secondary" @click="setRange('year')">{{ tr('هذه السنة') }}</button>
        </template>
      </div>
    </article>

    <p v-if="loading" class="muted">{{ tr('جاري إعداد التقرير...') }}</p>

    <p v-else-if="report !== 'collections' && !academicYear" class="notice warn">
      {{ tr('لا توجد سنة دراسية بعد. أضف سنة من الإعدادات العامة قبل عرض التقارير المالية.') }}
    </p>

    <template v-else-if="data">
      <template v-if="report === 'outstanding'">
        <div class="stats-grid">
          <article class="stat-card stat-rose">
            <span>{{ tr('إجمالي غير المسدد') }}</span><strong>{{ money(data.totals.outstanding) }}</strong>
          </article>
          <article class="stat-card stat-blue">
            <span>{{ tr('عدد الطلبة') }}</span><strong>{{ data.totals.students }}</strong>
          </article>
        </div>
        <div class="table-wrap">
          <table>
            <thead><tr><th>{{ tr('الطالب') }}</th><th>{{ tr('رقم الطالب') }}</th><th>{{ tr('الصف') }}</th><th>{{ tr('الإجمالي') }}</th><th>{{ tr('المدفوع') }}</th><th>{{ tr('المتبقي') }}</th></tr></thead>
            <tbody>
              <tr v-for="row in data.rows" :key="row.student_profile_id">
                <td>{{ row.name }}</td>
                <td>{{ row.admission_no }}</td>
                <td>{{ row.grade_level }}</td>
                <td>{{ money(row.total) }}</td>
                <td>{{ money(row.paid) }}</td>
                <td><strong>{{ money(row.outstanding) }}</strong></td>
              </tr>
              <tr v-if="!data.rows.length"><td colspan="6" class="muted">{{ tr('لا توجد أرصدة غير مسددة.') }}</td></tr>
            </tbody>
          </table>
        </div>
      </template>

      <template v-else-if="report === 'aged'">
        <div class="stats-grid">
          <article v-for="(amount, bucket) in data.buckets" :key="bucket" class="stat-card">
            <span>{{ bucketLabels[bucket] }}</span><strong>{{ money(amount) }}</strong>
          </article>
        </div>
        <div class="table-wrap">
          <table>
            <thead><tr><th>{{ tr('الطالب') }}</th><th>{{ tr('رقم الطالب') }}</th><th>{{ tr('المتأخر') }}</th><th>{{ tr('أقصى تأخير (يوم)') }}</th></tr></thead>
            <tbody>
              <tr v-for="row in data.students" :key="row.student_profile_id">
                <td>{{ row.name }}</td>
                <td>{{ row.admission_no }}</td>
                <td><strong>{{ money(row.overdue) }}</strong></td>
                <td>{{ row.days_late }}</td>
              </tr>
              <tr v-if="!data.students.length"><td colspan="4" class="muted">{{ tr('لا توجد متأخرات.') }}</td></tr>
            </tbody>
          </table>
        </div>
      </template>

      <template v-else>
        <div class="stats-grid">
          <article class="stat-card stat-green">
            <span>{{ tr('إجمالي المقبوضات') }}</span><strong>{{ money(data.totals.collected) }}</strong>
          </article>
          <article class="stat-card stat-blue"><span>{{ tr('عدد الإيصالات') }}</span><strong>{{ data.totals.receipts }}</strong></article>
          <article class="stat-card stat-rose"><span>{{ tr('إيصالات ملغاة') }}</span><strong>{{ data.totals.voided }}</strong></article>
        </div>

        <article v-if="data.series?.length" class="form-card">
          <h3>{{ tr('المقبوضات') }} {{ PERIOD_LABELS[data.period] }}</h3>
          <div class="table-wrap">
            <table>
              <thead><tr><th>{{ tr('الفترة') }}</th><th>{{ tr('عدد الإيصالات') }}</th><th>{{ tr('المحصّل') }}</th></tr></thead>
              <tbody>
                <tr v-for="row in data.series" :key="row.label">
                  <td>{{ row.label }}</td>
                  <td>{{ row.receipts }}</td>
                  <td><strong>{{ money(row.collected) }}</strong></td>
                </tr>
              </tbody>
            </table>
          </div>
        </article>

        <article v-if="Object.keys(data.by_cashier ?? {}).length" class="form-card">
          <h3>{{ tr('حسب الموظف المستلم') }}</h3>
          <div class="stats-grid">
            <article v-for="(amount, cashier) in data.by_cashier" :key="cashier" class="stat-card">
              <span>{{ cashier }}</span><strong>{{ money(amount) }}</strong>
            </article>
          </div>
        </article>

        <article v-if="Object.keys(data.by_method).length" class="form-card">
          <h3>{{ tr('حسب طريقة الدفع') }}</h3>
          <div class="stats-grid">
            <article v-for="(amount, method) in data.by_method" :key="method" class="stat-card">
              <span>{{ method }}</span><strong>{{ money(amount) }}</strong>
            </article>
          </div>
        </article>

        <div class="table-wrap">
          <table>
            <thead><tr><th>{{ tr('الإيصال') }}</th><th>{{ tr('التاريخ') }}</th><th>{{ tr('الطالب') }}</th><th>{{ tr('المبلغ') }}</th><th>{{ tr('الطريقة') }}</th><th>{{ tr('المستلم') }}</th><th>{{ tr('الحالة') }}</th></tr></thead>
            <tbody>
              <tr v-for="row in data.rows" :key="row.id" :class="{ voided: row.is_voided }">
                <td>#{{ row.receipt_number }}</td>
                <td>{{ row.paid_on }}</td>
                <td>{{ row.student }}</td>
                <td>{{ money(row.amount) }}</td>
                <td>{{ row.method }}</td>
                <td>{{ row.received_by || '—' }}</td>
                <td>{{ row.is_voided ? tr('ملغى') : tr('سليم') }}</td>
              </tr>
              <tr v-if="!data.rows.length"><td colspan="7" class="muted">{{ tr('لا توجد مقبوضات في هذه الفترة.') }}</td></tr>
            </tbody>
          </table>
        </div>
      </template>
    </template>
  </div>
</template>

<style scoped>
tr.voided td { opacity: 0.55; text-decoration: line-through; }

.notice.warn {
  background: var(--app-warn-soft);
  border: 1px solid var(--app-warn-border);
  color: var(--app-warn-text);
}
</style>
