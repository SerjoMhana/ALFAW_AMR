<script setup>
import { computed, onMounted, ref } from 'vue'
import { useApi } from '../../api.js'
import { notifyError, notifySuccess } from '../../notify.js'
import { confirmAction, confirmDelete } from '../../confirm.js'
import { pick } from '../../i18n.js'
import { tr } from '../../phrases.js'
import { money } from './money.js'

const props = defineProps({ can: { type: Function, required: true } })

/**
 * Cash advances — العهد.
 *
 * The school hands someone money, they come back with receipts, and the advance
 * closes on what those receipts add up to. The closing figure is never typed:
 * it is the difference between what was given and what was spent, so nobody can
 * sign off an amount they cannot account for.
 */
const { api } = useApi()

const advances = ref([])
const totals = ref({ open_count: 0, open_amount: 0, open_outstanding: 0 })
const methods = ref([])
const staff = ref([])
const statusFilter = ref('open')
const search = ref('')
const loading = ref(false)
const busy = ref('')

const selected = ref(null)
const issuing = ref(false)
const form = ref(emptyForm())
const expense = ref(emptyExpense())

const canManage = computed(() => props.can('finance.advances.manage'))
const canSettle = computed(() => props.can('finance.advances.settle'))

const statusLabels = {
  open: { ar: 'مفتوحة', en: 'Open' },
  settled: { ar: 'مقفلة', en: 'Settled' },
  cancelled: { ar: 'ملغاة', en: 'Cancelled' },
}

const statusTones = { open: 'tone-warn', settled: 'tone-good', cancelled: 'tone-muted' }

function emptyForm() {
  return {
    academic_year: '',
    holder_id: '',
    holder_name: '',
    purpose: '',
    amount: '',
    method: '',
    reference: '',
    issued_on: new Date().toISOString().slice(0, 10),
    notes: '',
  }
}

function emptyExpense() {
  return { description: '', amount: '', spent_on: new Date().toISOString().slice(0, 10), reference: '' }
}

onMounted(load)

async function load() {
  loading.value = true

  try {
    const params = new URLSearchParams()
    if (statusFilter.value) params.set('status', statusFilter.value)
    if (search.value.trim()) params.set('search', search.value.trim())

    const response = await api(`/finance/advances?${params}`)
    advances.value = response.data
    totals.value = response.totals
    methods.value = response.methods
    staff.value = response.staff

    if (!form.value.method && methods.value.length) form.value.method = methods.value[0]
  } catch (err) {
    notifyError(err.message)
  } finally {
    loading.value = false
  }
}

async function openAdvance(advance) {
  busy.value = `open-${advance.id}`

  try {
    selected.value = (await api(`/finance/advances/${advance.id}`)).data
  } catch (err) {
    notifyError(err.message)
  } finally {
    busy.value = ''
  }
}

// Choosing a member of staff fills the name, but a name may also be typed for
// someone with no account — a driver, a contractor.
function onHolderChange() {
  const person = staff.value.find((row) => String(row.id) === String(form.value.holder_id))
  if (person) form.value.holder_name = person.name
}

async function issue() {
  if (!form.value.holder_name.trim() || !form.value.purpose.trim() || !form.value.amount) {
    notifyError(pick('أكمل اسم المستلم والغرض والمبلغ.', 'Fill in the holder, the purpose and the amount.'))

    return
  }

  issuing.value = true

  try {
    const response = await api('/finance/advances', {
      method: 'POST',
      body: JSON.stringify({
        ...form.value,
        holder_id: form.value.holder_id || null,
        amount: Number(form.value.amount),
      }),
    })
    notifySuccess(pick('تم صرف العهدة.', 'The advance was issued.'))
    form.value = { ...emptyForm(), academic_year: form.value.academic_year, method: form.value.method }
    selected.value = response.data
    await load()
  } catch (err) {
    notifyError(err.message)
  } finally {
    issuing.value = false
  }
}

async function addExpense() {
  if (!expense.value.description.trim() || !expense.value.amount) {
    notifyError(pick('أكمل بيان الصرف والمبلغ.', 'Fill in what was bought and how much.'))

    return
  }

  busy.value = 'expense'

  try {
    const response = await api(`/finance/advances/${selected.value.id}/expenses`, {
      method: 'POST',
      body: JSON.stringify({ ...expense.value, amount: Number(expense.value.amount) }),
    })
    selected.value = response.data
    expense.value = emptyExpense()
    notifySuccess(pick('تم تسجيل الصرف.', 'The spending was recorded.'))
    await load()
  } catch (err) {
    notifyError(err.message)
  } finally {
    busy.value = ''
  }
}

async function removeExpense(row) {
  const ok = await confirmDelete(pick(
    `سيُحذف بند «${row.description}» بمبلغ ${money(row.amount)}.`,
    `"${row.description}" for ${money(row.amount)} will be removed.`,
  ))
  if (!ok) return

  try {
    const response = await api(`/finance/advance-expenses/${row.id}`, { method: 'DELETE' })
    selected.value = response.data
    await load()
  } catch (err) {
    notifyError(err.message)
  }
}

async function settle() {
  const outstanding = selected.value.outstanding
  const detail = outstanding > 0
    ? pick(
      `المتبقي ${money(outstanding)} يُسترد من المستلم عند الإقفال.`,
      `${money(outstanding)} is collected back from the holder on closing.`,
    )
    : outstanding < 0
      ? pick(
        `صُرف بزيادة ${money(Math.abs(outstanding))}، وسيُسجَّل مستحقاً للمستلم.`,
        `${money(Math.abs(outstanding))} was overspent and is recorded as owed to the holder.`,
      )
      : pick('الصرف يساوي العهدة تماماً.', 'The spending matches the advance exactly.')

  const ok = await confirmAction(
    pick(
      `إقفال العهدة رقم ${selected.value.advance_number} باسم ${selected.value.holder_name}.`,
      `Closing advance ${selected.value.advance_number} held by ${selected.value.holder_name}.`,
    ),
    { detail, confirmLabel: pick('إقفال العهدة', 'Close it') },
  )
  if (!ok) return

  busy.value = 'settle'

  try {
    const response = await api(`/finance/advances/${selected.value.id}/settle`, { method: 'POST' })
    selected.value = response.data
    notifySuccess(response.message)
    await load()
  } catch (err) {
    notifyError(err.message)
  } finally {
    busy.value = ''
  }
}

async function reopen() {
  const ok = await confirmAction(pick(
    'سيُعاد فتح العهدة وتُلغى أرقام التسوية المسجّلة.',
    'The advance reopens and the settlement figures are cleared.',
  ))
  if (!ok) return

  try {
    const response = await api(`/finance/advances/${selected.value.id}/reopen`, { method: 'POST' })
    selected.value = response.data
    notifySuccess(response.message)
    await load()
  } catch (err) {
    notifyError(err.message)
  }
}

async function cancel() {
  const ok = await confirmAction(
    pick('إلغاء عهدة صُرفت بالخطأ.', 'Cancelling an advance issued by mistake.'),
    {
      detail: pick(
        'لا يصح الإلغاء إلا قبل تسجيل أي صرف منها.',
        'Only possible before anything has been spent against it.',
      ),
      danger: true,
      confirmLabel: pick('إلغاء العهدة', 'Cancel it'),
    },
  )
  if (!ok) return

  try {
    const response = await api(`/finance/advances/${selected.value.id}/cancel`, {
      method: 'POST',
      body: JSON.stringify({ reason: null }),
    })
    selected.value = response.data
    notifySuccess(response.message)
    await load()
  } catch (err) {
    notifyError(err.message)
  }
}

function statusLabel(status) {
  const found = statusLabels[status]

  return found ? pick(found.ar, found.en) : status
}
</script>

<template>
  <div class="workspace">
    <article class="panel">
      <div class="permission-card-header">
        <div>
          <h3>{{ tr('العهد') }}</h3>
          <p class="muted">
            {{ tr('صرف مبلغ لموظف لينفقه لصالح المدرسة، ثم إقفاله بما يقابله من فواتير وما تبقّى من نقد.') }}
          </p>
        </div>
        <div class="advance-totals">
          <span class="muted">{{ tr('عهد مفتوحة') }}: <strong>{{ totals.open_count }}</strong></span>
          <span class="muted">{{ tr('قيمتها') }}: <strong>{{ money(totals.open_amount) }}</strong></span>
          <span class="muted">{{ tr('غير مسوّى') }}: <strong>{{ money(totals.open_outstanding) }}</strong></span>
        </div>
      </div>

      <div class="crud-form">
        <label>{{ tr('الحالة') }}
          <select v-model="statusFilter" @change="load">
            <option value="">{{ tr('الكل') }}</option>
            <option value="open">{{ tr('مفتوحة') }}</option>
            <option value="settled">{{ tr('مقفلة') }}</option>
            <option value="cancelled">{{ tr('ملغاة') }}</option>
          </select>
        </label>
        <label class="grow">{{ tr('بحث') }}
          <input v-model="search" :placeholder="tr('اسم المستلم أو الغرض')" @keyup.enter="load" />
        </label>
        <button type="button" class="secondary" @click="load">{{ tr('تطبيق') }}</button>
      </div>
    </article>

    <!-- ------------------------------ issuing ------------------------------ -->
    <article v-if="canManage" class="panel">
      <h3>{{ tr('صرف عهدة جديدة') }}</h3>
      <div class="crud-form">
        <label>{{ tr('السنة الدراسية') }} <input v-model="form.academic_year" placeholder="2026-2027" /></label>
        <label>{{ tr('المستلم من الموظفين') }}
          <select v-model="form.holder_id" @change="onHolderChange">
            <option value="">{{ tr('بدون حساب') }}</option>
            <option v-for="person in staff" :key="person.id" :value="person.id">{{ person.name }}</option>
          </select>
        </label>
        <label class="grow">{{ tr('اسم المستلم') }} <input v-model="form.holder_name" /></label>
        <label class="grow">{{ tr('الغرض') }} <input v-model="form.purpose" /></label>
        <label>{{ tr('المبلغ') }} <input v-model="form.amount" type="number" step="0.01" min="0.01" /></label>
        <label>{{ tr('طريقة الصرف') }}
          <select v-model="form.method">
            <option v-for="method in methods" :key="method" :value="method">{{ method }}</option>
          </select>
        </label>
        <label>{{ tr('المرجع') }} <input v-model="form.reference" :placeholder="tr('رقم شيك مثلاً')" /></label>
        <label>{{ tr('تاريخ الصرف') }} <input v-model="form.issued_on" type="date" /></label>
        <label class="grow">{{ tr('ملاحظات') }} <input v-model="form.notes" /></label>
      </div>
      <div class="actions">
        <button type="button" :disabled="issuing" @click="issue">
          {{ issuing ? tr('جاري الحفظ...') : tr('صرف العهدة') }}
        </button>
      </div>
    </article>

    <!-- -------------------------------- list -------------------------------- -->
    <p v-if="loading" class="muted">{{ tr('جاري التحميل...') }}</p>
    <p v-else-if="!advances.length" class="muted">{{ tr('لا توجد عهد مطابقة.') }}</p>

    <div v-else class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>{{ tr('الرقم') }}</th>
            <th>{{ tr('المستلم') }}</th>
            <th>{{ tr('الغرض') }}</th>
            <th>{{ tr('المبلغ') }}</th>
            <th>{{ tr('المصروف') }}</th>
            <th>{{ tr('غير مسوّى') }}</th>
            <th>{{ tr('التاريخ') }}</th>
            <th>{{ tr('الحالة') }}</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="advance in advances" :key="advance.id">
            <td>{{ advance.advance_number }}</td>
            <td>{{ advance.holder_name }}</td>
            <td>{{ advance.purpose }}</td>
            <td>{{ money(advance.amount) }}</td>
            <td>{{ money(advance.spent) }}</td>
            <td :class="{ 'owed-back': advance.outstanding < 0 }">
              {{ advance.status === 'open' ? money(advance.outstanding) : '—' }}
            </td>
            <td>{{ advance.issued_on }}</td>
            <td><span :class="['pill', statusTones[advance.status]]">{{ statusLabel(advance.status) }}</span></td>
            <td>
              <button
                type="button"
                class="secondary compact"
                :disabled="busy === `open-${advance.id}`"
                @click="openAdvance(advance)"
              >{{ tr('تفاصيل') }}</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- ------------------------------- detail ------------------------------- -->
    <div v-if="selected" class="modal-backdrop" @click.self="selected = null">
      <article class="student-modal">
        <div class="permission-card-header">
          <div>
            <h3>{{ tr('عهدة رقم') }} {{ selected.advance_number }} — {{ selected.holder_name }}</h3>
            <p class="muted">
              {{ selected.purpose }} · {{ selected.issued_on }} · {{ selected.method }}
              <template v-if="selected.issued_by"> · {{ tr('صرفها') }} {{ selected.issued_by }}</template>
            </p>
          </div>
          <button type="button" class="secondary compact" @click="selected = null">{{ tr('إغلاق') }}</button>
        </div>

        <div class="advance-summary">
          <div><span class="muted">{{ tr('المبلغ') }}</span><strong>{{ money(selected.amount) }}</strong></div>
          <div><span class="muted">{{ tr('المصروف') }}</span><strong>{{ money(selected.spent) }}</strong></div>
          <div v-if="selected.status === 'open'">
            <span class="muted">{{ selected.outstanding < 0 ? tr('مستحق للمستلم') : tr('غير مسوّى') }}</span>
            <strong :class="{ 'owed-back': selected.outstanding < 0 }">
              {{ money(Math.abs(selected.outstanding)) }}
            </strong>
          </div>
          <div v-else-if="selected.returned_amount > 0">
            <span class="muted">{{ tr('المرتجع') }}</span><strong>{{ money(selected.returned_amount) }}</strong>
          </div>
          <div v-else-if="selected.reimbursed_amount > 0">
            <span class="muted">{{ tr('صُرف للمستلم') }}</span><strong>{{ money(selected.reimbursed_amount) }}</strong>
          </div>
          <div>
            <span class="muted">{{ tr('الحالة') }}</span>
            <span :class="['pill', statusTones[selected.status]]">{{ statusLabel(selected.status) }}</span>
          </div>
        </div>

        <p v-if="selected.status === 'settled'" class="muted">
          {{ tr('أُقفلت في') }} {{ selected.settled_at }}
          <template v-if="selected.settled_by"> {{ tr('بواسطة') }} {{ selected.settled_by }}</template>
        </p>

        <!-- ---------------------------- spending ---------------------------- -->
        <h4>{{ tr('بنود الصرف') }}</h4>
        <div v-if="selected.expenses.length" class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>{{ tr('البيان') }}</th>
                <th>{{ tr('المبلغ') }}</th>
                <th>{{ tr('التاريخ') }}</th>
                <th>{{ tr('المرجع') }}</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in selected.expenses" :key="row.id">
                <td>{{ row.description }}</td>
                <td>{{ money(row.amount) }}</td>
                <td>{{ row.spent_on }}</td>
                <td>{{ row.reference || '—' }}</td>
                <td>
                  <button
                    v-if="canManage && selected.status === 'open'"
                    type="button"
                    class="danger compact"
                    @click="removeExpense(row)"
                  >{{ tr('حذف') }}</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <p v-else class="muted">{{ tr('لم يُسجَّل أي صرف بعد.') }}</p>

        <div v-if="canManage && selected.status === 'open'" class="crud-form">
          <label class="grow">{{ tr('البيان') }} <input v-model="expense.description" /></label>
          <label>{{ tr('المبلغ') }} <input v-model="expense.amount" type="number" step="0.01" min="0.01" /></label>
          <label>{{ tr('التاريخ') }} <input v-model="expense.spent_on" type="date" /></label>
          <label>{{ tr('المرجع') }} <input v-model="expense.reference" :placeholder="tr('رقم الفاتورة')" /></label>
          <button type="button" :disabled="busy === 'expense'" @click="addExpense">{{ tr('إضافة بند') }}</button>
        </div>

        <div class="actions">
          <button
            v-if="canSettle && selected.status === 'open'"
            type="button"
            :disabled="busy === 'settle'"
            @click="settle"
          >{{ tr('إقفال العهدة') }}</button>
          <button
            v-if="canSettle && selected.status === 'settled'"
            type="button"
            class="secondary"
            @click="reopen"
          >{{ tr('إعادة فتح') }}</button>
          <button
            v-if="canManage && selected.status === 'open' && !selected.expenses.length"
            type="button"
            class="danger"
            @click="cancel"
          >{{ tr('إلغاء العهدة') }}</button>
        </div>
      </article>
    </div>
  </div>
</template>

<style scoped>
.advance-totals {
  display: flex;
  flex-wrap: wrap;
  gap: 1rem;
  align-items: center;
}

.advance-summary {
  display: grid;
  gap: 0.75rem;
  grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
  padding: 0.85rem;
  border: 1px solid var(--app-border);
  border-radius: 0.9rem;
  background: var(--app-surface-muted);
}

.advance-summary > div { display: grid; gap: 0.2rem; }
.advance-summary strong { font-size: 1.05rem; }

/* Overspent: the school owes the holder, which is the opposite of a debt. */
.owed-back { color: var(--app-danger-text); }

.tone-good { background: var(--app-good-soft); color: var(--app-good-text); }
.tone-warn { background: var(--app-warn-soft); color: var(--app-warn-text); }
.tone-muted { background: var(--app-surface-muted); color: var(--app-text-muted); }
</style>
