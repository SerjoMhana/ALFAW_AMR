<script setup>
import { computed, onMounted, ref } from 'vue'
import { useApi } from '../../api.js'
import { notifyError, notifySuccess } from '../../notify.js'
import { STATUS_LABELS, STATUS_TONES, money } from './money.js'
import { confirmAction } from '../../confirm.js'
import { pick } from '../../i18n.js'
import { tr } from '../../phrases.js'

const props = defineProps({
  can: { type: Function, required: true },
})

const { api } = useApi()

const search = ref('')
const results = ref([])
const selected = ref(null)
const statement = ref(null)
const setup = ref({ templates: [], methods: [] })
const academicYears = ref([])
const academicYear = ref('')
const loading = ref(false)
const busy = ref(false)
const searchTimer = ref(null)

const payment = ref({ amount: '', method: '', reference: '', paid_on: '', notes: '' })
const allocationMode = ref('auto')
const manualLines = ref({})
const discountDraft = ref(null)

const unpaidFees = computed(() =>
  (statement.value?.fees ?? []).filter((row) => row.outstanding > 0),
)
const manualTotal = computed(() =>
  Object.values(manualLines.value).reduce((sum, value) => sum + Number(value || 0), 0),
)

onMounted(async () => {
  try {
    const [setupResponse, years] = await Promise.all([api('/finance/setup'), api('/academic-years')])
    setup.value = setupResponse.data
    academicYears.value = years.data
    academicYear.value = academicYears.value.find((year) => year.is_active)?.name
      ?? academicYears.value[0]?.name
      ?? ''
  } catch (err) {
    notifyError(err.message)
  }
})

function searchDebounced() {
  if (searchTimer.value) clearTimeout(searchTimer.value)
  searchTimer.value = setTimeout(runSearch, 300)
}

async function runSearch() {
  try {
    const params = new URLSearchParams({ search: search.value })
    results.value = (await api(`/finance/students?${params.toString()}`)).data
  } catch (err) {
    notifyError(err.message)
  }
}

async function selectStudent(student) {
  selected.value = student
  results.value = []
  search.value = ''
  await loadStatement()
}

async function loadStatement() {
  if (!selected.value) return
  loading.value = true

  try {
    const params = new URLSearchParams({ academic_year: academicYear.value })
    statement.value = (await api(`/finance/students/${selected.value.id}/account?${params.toString()}`)).data
    resetPaymentForm()
  } catch (err) {
    notifyError(err.message)
    statement.value = null
  } finally {
    loading.value = false
  }
}

function resetPaymentForm() {
  payment.value = { amount: '', method: '', reference: '', paid_on: '', notes: '' }
  allocationMode.value = 'auto'
  manualLines.value = {}
}

async function assignFees() {
  if (!await confirmAction(pick(`سيتم إسناد رسوم ${academicYear.value} لهذا الطالب.`, `This student will be charged the ${academicYear.value} fees.`))) return
  busy.value = true

  try {
    const response = await api(`/finance/students/${selected.value.id}/assign-fees`, {
      method: 'POST',
      body: JSON.stringify({ academic_year: academicYear.value }),
    })
    statement.value = response.data.statement
    notifySuccess(pick(`تم إسناد ${response.data.summary.fees_created} رسم.`, `${response.data.summary.fees_created} fees raised.`))
  } catch (err) {
    notifyError(err.message)
  } finally {
    busy.value = false
  }
}

function startDiscount(fee) {
  discountDraft.value = { fee, reason: '', percentage: '', amount: '' }
}

async function submitDiscount() {
  const draft = discountDraft.value
  if (!draft) return
  busy.value = true

  const payload = { reason: draft.reason }
  if (draft.percentage) payload.percentage = Number(draft.percentage)
  else payload.amount = Number(draft.amount)

  try {
    await api(`/finance/fees/${draft.fee.id}/discount-requests`, {
      method: 'POST',
      body: JSON.stringify(payload),
    })
    notifySuccess(pick('تم إرسال طلب الخصم للاعتماد.', 'Discount request sent for approval.'))
    discountDraft.value = null
    await loadStatement()
  } catch (err) {
    notifyError(err.message)
  } finally {
    busy.value = false
  }
}

async function collect() {
  const amount = Number(payment.value.amount)

  if (!amount || amount <= 0) {
    notifyError(pick('أدخل مبلغاً صحيحاً.', 'Enter a valid amount.'))
    return
  }

  if (allocationMode.value === 'manual' && Math.abs(manualTotal.value - amount) > 0.001) {
    notifyError(pick('مجموع التخصيص اليدوي لا يساوي المبلغ المدفوع.', 'The manual allocation does not add up to the amount paid.'))
    return
  }

  busy.value = true
  const body = {
    academic_year: academicYear.value,
    amount,
    method: payment.value.method,
    reference: payment.value.reference || null,
    paid_on: payment.value.paid_on || null,
    notes: payment.value.notes || null,
  }

  if (allocationMode.value === 'manual') {
    body.allocations = Object.entries(manualLines.value)
      .filter(([, value]) => Number(value) > 0)
      .map(([id, value]) => ({ student_fee_id: Number(id), amount: Number(value) }))
  }

  try {
    const response = await api(`/finance/students/${selected.value.id}/payments`, {
      method: 'POST',
      body: JSON.stringify(body),
    })
    statement.value = response.data.statement
    notifySuccess(pick(`تم تسجيل الدفعة وإصدار الإيصال رقم ${response.data.payment.receipt_number}.`, `Payment recorded, receipt number ${response.data.payment.receipt_number}.`))
    resetPaymentForm()
  } catch (err) {
    notifyError(err.message)
  } finally {
    busy.value = false
  }
}

async function voidReceipt(receipt) {
  const reason = window.prompt('سبب إلغاء الإيصال (يُسجَّل في سجل المراجعة):')
  if (!reason) return

  busy.value = true

  try {
    const response = await api(`/finance/payments/${receipt.id}/void`, {
      method: 'POST',
      body: JSON.stringify({ reason }),
    })
    statement.value = response.data.statement
    notifySuccess(pick('تم إلغاء الإيصال وعكس التخصيص. الإيصال الأصلي محفوظ في السجل.', 'Receipt voided and its allocation reversed. The original stays on record.'))
  } catch (err) {
    notifyError(err.message)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="workspace">
    <article class="form-card">
      <h3>{{ tr('حسابات الطلبة') }}</h3>
      <div class="crud-form">
        <label>{{ tr('بحث عن طالب') }}
          <input
            v-model="search"
            :placeholder="tr('الاسم أو رقم الطالب أو الرقم الوطني...')"
            @input="searchDebounced"
          />
        </label>
        <label>{{ tr('السنة الدراسية') }}
          <select v-model="academicYear" @change="loadStatement">
            <option v-for="year in academicYears" :key="year.id" :value="year.name">{{ year.name }}</option>
          </select>
        </label>
      </div>

      <div v-if="results.length" class="search-results">
        <button
          v-for="student in results"
          :key="student.id"
          type="button"
          class="result-row"
          @click="selectStudent(student)"
        >
          <strong>{{ student.name }}</strong>
          <small>{{ student.admission_no }} · {{ student.class_name || student.grade_level }}</small>
        </button>
      </div>
    </article>

    <p v-if="!selected" class="muted">{{ tr('ابحث عن طالب لعرض حسابه المالي.') }}</p>
    <p v-else-if="loading" class="muted">{{ tr('جاري تحميل كشف الحساب...') }}</p>

    <template v-else-if="statement">
      <article class="form-card">
        <div class="permission-card-header">
          <div>
            <h3>{{ statement.student.name }}</h3>
            <p class="muted">{{ statement.student.admission_no }} · {{ statement.student.grade_level }} · {{ statement.academic_year }}</p>
          </div>
          <button v-if="can('finance.fees.manage')" type="button" :disabled="busy" @click="assignFees">
            {{ tr('إسناد رسوم السنة') }}
          </button>
        </div>

        <div class="stats-grid">
          <article class="stat-card"><span>{{ tr('الإجمالي') }}</span><strong>{{ money(statement.totals.gross) }}</strong></article>
          <article class="stat-card stat-green"><span>{{ tr('الخصومات') }}</span><strong>{{ money(statement.totals.discounts) }}</strong></article>
          <article class="stat-card stat-blue"><span>{{ tr('الصافي') }}</span><strong>{{ money(statement.totals.net) }}</strong></article>
          <article class="stat-card stat-green"><span>{{ tr('المدفوع') }}</span><strong>{{ money(statement.totals.paid) }}</strong></article>
          <article class="stat-card stat-rose"><span>{{ tr('المتبقي') }}</span><strong>{{ money(statement.totals.outstanding) }}</strong></article>
        </div>

        <p v-if="statement.totals.overdue > 0" class="notice error">
          {{ tr('⚠ متأخرات مستحقة:') }} {{ money(statement.totals.overdue) }}
        </p>
      </article>

      <article class="form-card">
        <h3>{{ tr('الرسوم والخصومات') }}</h3>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>{{ tr('الرسم') }}</th><th>{{ tr('الفئة') }}</th><th>{{ tr('الاستحقاق') }}</th><th>{{ tr('المبلغ') }}</th><th>{{ tr('الخصم') }}</th>
                <th>{{ tr('الصافي') }}</th><th>{{ tr('المدفوع') }}</th><th>{{ tr('المتبقي') }}</th><th>{{ tr('الحالة') }}</th><th></th>
              </tr>
            </thead>
            <tbody>
              <template v-for="fee in statement.fees" :key="fee.id">
                <tr>
                  <td>{{ fee.name }}</td>
                  <td>{{ fee.category }}</td>
                  <td>{{ fee.due_date || '—' }}</td>
                  <td>{{ money(fee.amount) }}</td>
                  <td>{{ money(fee.discount_total) }}</td>
                  <td><strong>{{ money(fee.net) }}</strong></td>
                  <td>{{ money(fee.paid_amount) }}</td>
                  <td>{{ money(fee.outstanding) }}</td>
                  <td><span class="pill" :class="STATUS_TONES[fee.status]">{{ STATUS_LABELS[fee.status] }}</span></td>
                  <td>
                    <button
                      v-if="can('finance.discounts.request')"
                      type="button"
                      class="secondary compact"
                      @click="startDiscount(fee)"
                    >
                      {{ tr('طلب خصم') }}
                    </button>
                  </td>
                </tr>
                <tr v-for="discount in fee.discounts" :key="`d-${discount.id}`" class="discount-row">
                  <td colspan="10">
                    <span class="pill" :class="discount.status === 'approved' ? 'tone-good' : discount.status === 'pending' ? 'tone-warn' : 'tone-bad'">
                      {{ discount.status === 'approved' ? tr('معتمد') : discount.status === 'pending' ? tr('بانتظار الاعتماد') : tr('مرفوض') }}
                    </span>
                    {{ discount.reason }} — {{ money(discount.amount) }}
                    <template v-if="discount.percentage"> ({{ discount.percentage }}%)</template>
                    <small v-if="discount.approved_by" class="muted"> {{ tr('· اعتمده') }} {{ discount.approved_by }}</small>
                  </td>
                </tr>
              </template>
              <tr v-if="!statement.fees.length"><td colspan="10" class="muted">{{ tr('لم تُسند رسوم لهذا الطالب بعد.') }}</td></tr>
            </tbody>
          </table>
        </div>
      </article>


      <article v-if="can('finance.payments.record') && statement.totals.outstanding > 0" class="form-card">
        <h3>{{ tr('تحصيل دفعة') }}</h3>
        <div class="form-grid">
          <label>{{ tr('المبلغ') }} <input v-model="payment.amount" type="number" step="0.01" min="0.01" /></label>
          <label>{{ tr('طريقة الدفع') }}
            <select v-model="payment.method">
              <option v-for="method in setup.methods" :key="method.id" :value="method.name">
                {{ method.name }}
              </option>
            </select>
          </label>
          <label>{{ tr('رقم المرجع (اختياري)') }} <input v-model="payment.reference" /></label>
          <label>{{ tr('تاريخ الدفع') }} <input v-model="payment.paid_on" type="date" /></label>
        </div>

        <div class="allocation-modes">
          <label class="checkbox-label">
            <input v-model="allocationMode" type="radio" value="auto" /> {{ tr('تخصيص آلي (الأقدم استحقاقاً أولاً)') }}
          </label>
          <label class="checkbox-label">
            <input v-model="allocationMode" type="radio" value="manual" /> {{ tr('تخصيص يدوي') }}
          </label>
        </div>

        <div v-if="allocationMode === 'manual'" class="table-wrap">
          <table>
            <thead><tr><th>{{ tr('الرسم') }}</th><th>{{ tr('المتبقي') }}</th><th>{{ tr('المبلغ المخصص') }}</th></tr></thead>
            <tbody>
              <tr v-for="row in unpaidFees" :key="row.id">
                <td>{{ row.name }} <small class="muted">({{ row.due_date || "بلا تاريخ" }})</small></td>
                <td>{{ money(row.outstanding) }}</td>
                <td>
                  <input
                    v-model="manualLines[row.id]"
                    type="number"
                    step="0.01"
                    min="0"
                    :max="row.outstanding"
                    placeholder="0"
                  />
                </td>
              </tr>
            </tbody>
          </table>
          <p class="muted" style="padding: 8px">
            {{ tr('مجموع التخصيص:') }} {{ money(manualTotal) }} {{ tr('/ المبلغ:') }} {{ money(payment.amount || 0) }}
          </p>
        </div>

        <div class="actions">
          <button type="button" :disabled="busy" @click="collect">
            {{ busy ? tr('جاري التسجيل...') : tr('💵 تسجيل الدفعة وإصدار الإيصال') }}
          </button>
        </div>
      </article>

      <article class="form-card">
        <h3>{{ tr('الإيصالات') }}</h3>
        <p class="muted">{{ tr('الإيصال لا يُعدَّل ولا يُحذف. الخطأ يُصحَّح بإلغاء يُبقي الأصل في السجل.') }}</p>
        <div class="table-wrap">
          <table>
            <thead><tr><th>{{ tr('رقم الإيصال') }}</th><th>{{ tr('التاريخ') }}</th><th>{{ tr('المبلغ') }}</th><th>{{ tr('الطريقة') }}</th><th>{{ tr('المستلم') }}</th><th>{{ tr('الحالة') }}</th><th></th></tr></thead>
            <tbody>
              <tr v-for="receipt in statement.payments" :key="receipt.id" :class="{ voided: receipt.is_voided }">
                <td>#{{ receipt.receipt_number }}</td>
                <td>{{ receipt.paid_on }}</td>
                <td>{{ money(receipt.amount) }}</td>
                <td>{{ receipt.method }}</td>
                <td>{{ receipt.received_by || '—' }}</td>
                <td>
                  <span v-if="receipt.is_voided" class="pill tone-bad">{{ tr('ملغى') }}</span>
                  <span v-else class="pill tone-good">{{ tr('سليم') }}</span>
                </td>
                <td>
                  <button
                    v-if="!receipt.is_voided && can('finance.payments.void')"
                    type="button"
                    class="danger compact"
                    :disabled="busy"
                    @click="voidReceipt(receipt)"
                  >
                    {{ tr('إلغاء') }}
                  </button>
                </td>
              </tr>
              <tr v-if="!statement.payments.length"><td colspan="7" class="muted">{{ tr('لا توجد إيصالات.') }}</td></tr>
            </tbody>
          </table>
        </div>
      </article>
    </template>

    <div v-if="discountDraft" class="modal-backdrop" @click.self="discountDraft = null">
      <article class="student-modal">
        <div class="permission-card-header">
          <h3>{{ tr('طلب خصم على «') }}{{ discountDraft.fee.name }}»</h3>
          <button type="button" class="secondary compact" @click="discountDraft = null">{{ tr('إغلاق') }}</button>
        </div>
        <p class="muted">{{ tr('الطلب يبقى معلقاً حتى يعتمده صاحب صلاحية الاعتماد.') }}</p>
        <div class="form-grid">
          <label>{{ tr('السبب') }} <input v-model="discountDraft.reason" required /></label>
          <label>{{ tr('النسبة %') }} <input v-model="discountDraft.percentage" type="number" step="0.01" min="0" max="100" /></label>
          <label>{{ tr('أو مبلغ ثابت') }} <input v-model="discountDraft.amount" type="number" step="0.01" min="0" /></label>
        </div>
        <div class="actions">
          <button
            type="button"
            :disabled="busy || !discountDraft.reason || (!discountDraft.percentage && !discountDraft.amount)"
            @click="submitDiscount"
          >
            {{ tr('إرسال الطلب') }}
          </button>
        </div>
      </article>
    </div>
  </div>
</template>

<style scoped>
.search-results {
  display: grid;
  gap: 4px;
  margin-top: 10px;
  max-height: 260px;
  overflow-y: auto;
}

.result-row {
  display: grid;
  gap: 2px;
  text-align: start;
  background: var(--app-surface);
  border: 1px solid var(--app-border);
  border-radius: 8px;
  padding: 9px 11px;
  cursor: pointer;
  color: inherit;
}

.result-row:hover { background: var(--app-surface-muted); }
.result-row small { color: var(--app-text-muted); }

.allocation-modes {
  display: flex;
  flex-wrap: wrap;
  gap: 16px;
}

.discount-row td {
  background: var(--app-surface-muted);
  font-size: 12px;
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

tr.voided td { opacity: 0.55; text-decoration: line-through; }
tr.voided td:last-child { text-decoration: none; }
</style>
