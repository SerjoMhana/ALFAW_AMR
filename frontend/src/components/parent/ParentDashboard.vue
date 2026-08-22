<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useApi } from '../../api.js'
import { notifyError } from '../../notify.js'
import StudentGradeCards from '../shared/StudentGradeCards.vue'
import { STATUS_LABELS, STATUS_TONES, money } from '../finance/money.js'
import { tr } from '../../phrases.js'

const { api, apiBlob } = useApi()

const termLabels = {
  'Quarter 1': 'الفصل الأول',
  'Quarter 2': 'الفصل الثاني',
  'Quarter 3': 'الفصل الثالث',
  'Quarter 4': 'الفصل الرابع',
}

const children = ref([])
const selectedChildId = ref('')
const selectedTerm = ref('')
const grades = ref(null)
const reportCards = ref([])
const balance = ref(null)
const loading = ref(false)

const selectedChild = computed(() =>
  children.value.find((child) => String(child.id) === String(selectedChildId.value)) ?? null,
)
const openTerms = computed(() => grades.value?.open_terms ?? selectedChild.value?.open_terms ?? [])

onMounted(async () => {
  try {
    const response = await api('/parent/children')
    children.value = response.data
    selectedChildId.value = children.value[0]?.id ?? ''
  } catch (err) {
    notifyError(err.message)
  }
})

watch(selectedChildId, async (childId) => {
  grades.value = null
  reportCards.value = []
  balance.value = null
  if (!childId) return

  // Default to the first quarter the admin has opened for this child.
  selectedTerm.value = selectedChild.value?.open_terms?.[0] ?? ''
  await Promise.all([loadGrades(), loadReportCards(), loadBalance()])
})

async function loadBalance() {
  if (!selectedChildId.value) return

  try {
    const response = await api(`/parent/children/${selectedChildId.value}/balance`)
    balance.value = response.data
  } catch (err) {
    notifyError(err.message)
    balance.value = null
  }
}

watch(selectedTerm, (term) => {
  if (term && selectedChildId.value) loadGrades()
})

async function loadGrades() {
  if (!selectedChildId.value || !selectedTerm.value) return
  loading.value = true

  try {
    const params = new URLSearchParams({ term: selectedTerm.value })
    const response = await api(`/parent/children/${selectedChildId.value}/grades?${params.toString()}`)
    grades.value = response.data
  } catch (err) {
    notifyError(err.message)
    grades.value = null
  } finally {
    loading.value = false
  }
}

async function loadReportCards() {
  if (!selectedChildId.value) return

  try {
    const response = await api(`/parent/children/${selectedChildId.value}/report-cards`)
    reportCards.value = response.data
  } catch (err) {
    notifyError(err.message)
  }
}

async function downloadReportCard(publication) {

  try {
    const blob = await apiBlob(`/parent/children/${selectedChildId.value}/report-cards/${publication.id}/pdf`)
    window.open(URL.createObjectURL(blob), '_blank')
  } catch (err) {
    notifyError(err.message)
  }
}
</script>

<template>
  <div class="workspace">

    <p v-if="!children.length" class="muted">
      {{ tr('لا يوجد أبناء مرتبطون بهذا الحساب. يرجى مراجعة إدارة المدرسة.') }}
    </p>

    <template v-else>
      <article class="form-card">
        <h3>{{ tr('الأبناء') }}</h3>
        <div class="child-list">
          <button
            v-for="child in children"
            :key="child.id"
            type="button"
            class="child-chip"
            :class="{ active: String(child.id) === String(selectedChildId) }"
            @click="selectedChildId = child.id"
          >
            <strong>{{ child.name }}</strong>
            <small>{{ child.admission_no }} · {{ child.class_name || child.grade_level }}</small>
          </button>
        </div>
      </article>

      <template v-if="selectedChild">
        <div class="crud-form">
          <label>{{ tr('الفصل الدراسي') }}
            <select v-model="selectedTerm">
              <option v-for="term in openTerms" :key="term" :value="term">
                {{ termLabels[term] || term }}
              </option>
            </select>
          </label>
        </div>

        <p v-if="!openTerms.length" class="notice warn">
          {{ tr('لم تفتح إدارة المدرسة أي فصل دراسي بعد. ستظهر الدرجات هنا فور فتحه.') }}
        </p>

        <template v-else>
          <p v-if="loading" class="muted">{{ tr('جاري تحميل الدرجات...') }}</p>

          <template v-else-if="grades">
            <article class="form-card">
              <h3>{{ tr('درجات') }} {{ grades.student.name }} — {{ termLabels[grades.term] || grades.term }}</h3>
              <StudentGradeCards :courses="grades.courses" />
            </article>
          </template>
        </template>

        <article v-if="balance" class="form-card">
          <h3>{{ tr('الحساب المالي') }}</h3>

          <p v-if="balance.totals.overdue > 0" class="notice error">
            {{ tr('⚠ يوجد مبلغ متأخر قدره') }} {{ money(balance.totals.overdue) }}{{ tr('. يرجى مراجعة إدارة المدرسة.') }}
          </p>

          <div class="stats-grid">
            <article class="stat-card stat-blue">
              <span>{{ tr('إجمالي الرسوم') }}</span><strong>{{ money(balance.totals.net) }}</strong>
            </article>
            <article class="stat-card stat-green">
              <span>{{ tr('المدفوع') }}</span><strong>{{ money(balance.totals.paid) }}</strong>
            </article>
            <article class="stat-card stat-rose">
              <span>{{ tr('المتبقي') }}</span><strong>{{ money(balance.totals.outstanding) }}</strong>
            </article>
          </div>

          <template v-if="balance.fees.length">
            <h3>{{ tr('تفاصيل الرسوم') }}</h3>
            <div class="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>{{ tr('الرسم') }}</th><th>{{ tr('الفئة') }}</th><th>{{ tr('الاستحقاق') }}</th><th>{{ tr('المبلغ') }}</th>
                    <th>{{ tr('الخصم') }}</th><th>{{ tr('الصافي') }}</th><th>{{ tr('المدفوع') }}</th><th>{{ tr('المتبقي') }}</th><th>{{ tr('الحالة') }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="(fee, index) in balance.fees" :key="index">
                    <td>{{ fee.name }}</td>
                    <td>{{ fee.category }}</td>
                    <td>{{ fee.due_date || '—' }}</td>
                    <td>{{ money(fee.amount) }}</td>
                    <td>{{ money(fee.discount_total) }}</td>
                    <td><strong>{{ money(fee.net) }}</strong></td>
                    <td>{{ money(fee.paid_amount) }}</td>
                    <td>{{ money(fee.outstanding) }}</td>
                    <td><span class="pill" :class="STATUS_TONES[fee.status]">{{ STATUS_LABELS[fee.status] }}</span></td>
                  </tr>
                </tbody>
              </table>
            </div>
          </template>

          <template v-if="balance.receipts.length">
            <h3>{{ tr('الإيصالات') }}</h3>
            <div class="table-wrap">
              <table>
                <thead><tr><th>{{ tr('رقم الإيصال') }}</th><th>{{ tr('التاريخ') }}</th><th>{{ tr('المبلغ') }}</th><th>{{ tr('الطريقة') }}</th></tr></thead>
                <tbody>
                  <tr v-for="receipt in balance.receipts" :key="receipt.id">
                    <td>#{{ receipt.receipt_number }}</td>
                    <td>{{ receipt.paid_on }}</td>
                    <td>{{ money(receipt.amount) }}</td>
                    <td>{{ receipt.method }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </template>
        </article>

        <article v-if="reportCards.length" class="form-card">
          <h3>{{ tr('كشوف الدرجات المتاحة') }}</h3>
          <div class="table-wrap">
            <table>
              <thead>
                <tr><th>{{ tr('الفترة') }}</th><th>{{ tr('الفصل') }}</th><th>{{ tr('تاريخ النشر') }}</th><th></th></tr>
              </thead>
              <tbody>
                <tr v-for="publication in reportCards" :key="publication.id">
                  <td>{{ termLabels[publication.period] || publication.period }}</td>
                  <td>{{ publication.course_section?.class_name || publication.course_section?.section_code }}</td>
                  <td>{{ publication.published_at }}</td>
                  <td>
                    <button type="button" class="secondary compact" @click="downloadReportCard(publication)">
                      {{ tr('تحميل PDF') }}
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </article>
      </template>
    </template>
  </div>
</template>

<style scoped>
.child-list {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
}

.child-chip {
  display: grid;
  gap: 2px;
  text-align: start;
  border: 1px solid var(--app-border);
  border-radius: 10px;
  background: var(--app-surface);
  padding: 10px 14px;
  cursor: pointer;
  color: inherit;
}

.child-chip:hover { background: var(--app-surface-muted); }

.child-chip.active {
  border-color: var(--app-accent);
  background: var(--app-accent-soft);
}

.child-chip small { color: var(--app-text-muted); font-size: 12px; }

.notice.warn {
  background: var(--app-warn-soft);
  border: 1px solid var(--app-warn-border);
  color: var(--app-warn-text);
}

.pill {
  display: inline-block;
  border-radius: 999px;
  padding: 2px 10px;
  font-size: 11px;
  font-weight: 800;
}

.tone-good { background: var(--app-good-soft); color: var(--app-good-text); }
.tone-warn { background: var(--app-warn-soft); color: var(--app-warn-text); }
.tone-bad { background: var(--app-danger-soft); color: var(--app-danger-text); }
.tone-muted { background: var(--app-surface-muted); color: var(--app-text); }
</style>
