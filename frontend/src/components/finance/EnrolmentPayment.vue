<script setup>
import { computed, onMounted, ref } from 'vue'
import { useApi } from '../../api.js'
import { notifyError, notifySuccess } from '../../notify.js'
import { money } from './money.js'
import { pick } from '../../i18n.js'
import { tr } from '../../phrases.js'

/**
 * Shown once, right after a student is enrolled: raise their fees and take
 * whatever the guardian is paying today. Skipping is a first-class option — the
 * balance simply stays open and is collected later from Finance.
 */
const props = defineProps({
  studentId: { type: [Number, String], required: true },
  studentName: { type: String, default: '' },
  academicYear: { type: String, default: '' },
  canManageFees: { type: Boolean, default: false },
})

const emit = defineEmits(['done'])

const { api } = useApi()

const statement = ref(null)
const methods = ref([])
const loading = ref(true)
const busy = ref(false)
const method = ref('')
const paidOn = ref(new Date().toISOString().slice(0, 10))
const reference = ref('')
// fee id -> amount being paid now
const lines = ref({})

const payableFees = computed(() => (statement.value?.fees ?? []).filter((fee) => fee.outstanding > 0))
const total = computed(() =>
  Object.values(lines.value).reduce((sum, value) => sum + Number(value || 0), 0),
)
const nothingToPay = computed(() => !loading.value && payableFees.value.length === 0)

onMounted(async () => {
  try {
    // Raise this year's charges first; without them there is nothing to collect.
    if (props.canManageFees) {
      await api(`/finance/students/${props.studentId}/assign-fees`, {
        method: 'POST',
        body: JSON.stringify({ academic_year: props.academicYear }),
      })
    }

    const [account, setup] = await Promise.all([
      api(`/finance/students/${props.studentId}/account?academic_year=${encodeURIComponent(props.academicYear)}`),
      api('/finance/setup'),
    ])

    statement.value = account.data
    methods.value = setup.data.methods ?? []
    method.value = methods.value[0]?.name ?? ''
  } catch (err) {
    notifyError(err.message)
  } finally {
    loading.value = false
  }
})

function toggleFee(fee) {
  if (lines.value[fee.id]) {
    delete lines.value[fee.id]
  } else {
    lines.value[fee.id] = fee.outstanding
  }
}

function payAll() {
  lines.value = Object.fromEntries(payableFees.value.map((fee) => [fee.id, fee.outstanding]))
}

async function collect() {
  const amount = Number(total.value.toFixed(2))

  if (amount <= 0) {
    notifyError(pick('اختر رسماً واحداً على الأقل وحدد المبلغ المدفوع.', 'Select at least one fee and enter the amount paid.'))
    return
  }

  if (!method.value) {
    notifyError(pick('اختر طريقة الدفع.', 'Choose a payment method.'))
    return
  }

  busy.value = true

  try {
    const response = await api(`/finance/students/${props.studentId}/payments`, {
      method: 'POST',
      body: JSON.stringify({
        academic_year: props.academicYear,
        amount,
        method: method.value,
        reference: reference.value || null,
        paid_on: paidOn.value || null,
        allocations: Object.entries(lines.value)
          .filter(([, value]) => Number(value) > 0)
          .map(([id, value]) => ({ student_fee_id: Number(id), amount: Number(value) })),
      }),
    })

    notifySuccess(pick(`تم تسجيل الدفعة وإصدار الإيصال رقم ${response.data.payment.receipt_number}.`, `Payment recorded, receipt number ${response.data.payment.receipt_number}.`))
    emit('done')
  } catch (err) {
    notifyError(err.message)
  } finally {
    busy.value = false
  }
}

function skip() {
  notifySuccess(pick('تم تخطي الدفع. يبقى الرصيد مستحقاً ويمكن تحصيله لاحقاً من قسم المالية.', 'Payment skipped. The balance stays owed and can be collected later from Finance.'))
  emit('done')
}
</script>

<template>
  <div class="modal-backdrop">
    <article class="student-modal enrolment-payment">
      <div class="permission-card-header">
        <div>
          <h3>{{ tr('دفع رسوم التسجيل') }}</h3>
          <p class="muted">
            {{ tr('تم حفظ بيانات') }} <strong>{{ studentName }}</strong>{{ tr('. حدد ما دفعه ولي الأمر اليوم، أو تخطَّ هذه الخطوة.') }}
          </p>
        </div>
      </div>

      <p v-if="loading" class="muted">{{ tr('جاري تجهيز رسوم الطالب...') }}</p>

      <p v-else-if="nothingToPay" class="notice warn">
        {{ tr('لا توجد رسوم مستحقة على هذا الطالب.') }}
        <template v-if="!statement?.fees?.length">
          {{ tr('لم تُعرَّف قوالب رسوم لصفه في هذه السنة — يمكنك إعدادها من المالية ← إعداد الرسوم.') }}
        </template>
      </p>

      <template v-else>
        <div class="stats-grid">
          <article class="stat-card stat-blue">
            <span>{{ tr('إجمالي المستحق') }}</span><strong>{{ money(statement.totals.outstanding) }}</strong>
          </article>
          <article class="stat-card stat-green">
            <span>{{ tr('المحدد للدفع الآن') }}</span><strong>{{ money(total) }}</strong>
          </article>
        </div>

        <div class="permission-card-header">
          <p class="muted">{{ tr('اختر الرسوم المدفوعة — ويمكنك تعديل المبلغ لدفعة جزئية.') }}</p>
          <button type="button" class="secondary compact" @click="payAll">{{ tr('تحديد الكل') }}</button>
        </div>

        <div class="table-wrap">
          <table>
            <thead>
              <tr><th></th><th>{{ tr('الرسم') }}</th><th>{{ tr('الفئة') }}</th><th>{{ tr('الاستحقاق') }}</th><th>{{ tr('المتبقي') }}</th><th>{{ tr('المدفوع الآن') }}</th></tr>
            </thead>
            <tbody>
              <tr v-for="fee in payableFees" :key="fee.id">
                <td>
                  <input type="checkbox" :checked="lines[fee.id] !== undefined" @change="toggleFee(fee)" />
                </td>
                <td>{{ fee.name }}</td>
                <td>{{ fee.category }}</td>
                <td>{{ fee.due_date || '—' }}</td>
                <td>{{ money(fee.outstanding) }}</td>
                <td>
                  <input
                    v-if="lines[fee.id] !== undefined"
                    v-model="lines[fee.id]"
                    type="number"
                    step="0.01"
                    min="0"
                    :max="fee.outstanding"
                  />
                  <span v-else class="muted">—</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="form-grid">
          <label>{{ tr('طريقة الدفع') }}
            <select v-model="method">
              <option v-for="row in methods" :key="row.id" :value="row.name">{{ row.name }}</option>
            </select>
          </label>
          <label>{{ tr('تاريخ الدفع') }} <input v-model="paidOn" type="date" /></label>
          <label>{{ tr('رقم المرجع (اختياري)') }} <input v-model="reference" /></label>
        </div>
      </template>

      <div class="actions">
        <button v-if="!nothingToPay" type="button" :disabled="busy || total <= 0" @click="collect">
          {{ busy ? tr('جاري التسجيل...') : tr('💵 تسجيل الدفعة وإصدار الإيصال') }}
        </button>
        <button type="button" class="secondary" :disabled="busy" @click="skip">
          {{ nothingToPay ? tr('متابعة') : tr('تخطي — لم يدفع شيئاً') }}
        </button>
      </div>
    </article>
  </div>
</template>

<style scoped>
.enrolment-payment { width: min(900px, 100%); }

.notice.warn {
  background: var(--app-warn-soft);
  border: 1px solid var(--app-warn-border);
  color: var(--app-warn-text);
}

.table-wrap input[type='number'] { width: 110px; }
</style>
