<script setup>
import { onMounted, ref } from 'vue'
import { useApi } from '../../api.js'
import { notifyError, notifySuccess } from '../../notify.js'
import { money } from './money.js'
import { confirmAction } from '../../confirm.js'
import { tr } from '../../phrases.js'

const { api } = useApi()

const requests = ref([])
const loading = ref(false)
const busy = ref('')

onMounted(load)

async function load() {
  loading.value = true

  try {
    requests.value = (await api('/finance/discount-requests')).data
  } catch (err) {
    notifyError(err.message)
  } finally {
    loading.value = false
  }
}

async function decide(request, action) {
  const verb = action === 'approve' ? 'اعتماد' : 'رفض'
  if (!await confirmAction(`${verb} ${money(request.amount)} — ${request.fee_name} — ${request.student}`)) return

  busy.value = request.id

  try {
    await api(`/finance/discount-requests/${request.id}/${action}`, { method: 'POST' })
    notifySuccess(action === 'approve' ? 'تم اعتماد الخصم وتحديث الأقساط.' : 'تم رفض الطلب.')
    await load()
  } catch (err) {
    notifyError(err.message)
  } finally {
    busy.value = ''
  }
}
</script>

<template>
  <div class="workspace">
    <article class="form-card">
      <h3>{{ tr('اعتماد الخصومات') }}</h3>
      <p class="muted">
        {{ tr('طلبات الخصم اليدوية تنتظر هنا. لا يمكن لمن طلب الخصم أن يعتمده بنفسه. الاعتماد يعيد احتساب أقساط الطالب تلقائياً.') }}
      </p>
    </article>

    <p v-if="loading" class="muted">{{ tr('جاري التحميل...') }}</p>
    <p v-else-if="!requests.length" class="muted">{{ tr('لا توجد طلبات خصم معلقة.') }}</p>

    <div v-else class="table-wrap">
      <table>
        <thead>
          <tr><th>{{ tr('الطالب') }}</th><th>{{ tr('الرسم') }}</th><th>{{ tr('قيمة الرسم') }}</th><th>{{ tr('الخصم') }}</th><th>{{ tr('السبب') }}</th><th>{{ tr('مقدّم الطلب') }}</th><th>{{ tr('التاريخ') }}</th><th></th></tr>
        </thead>
        <tbody>
          <tr v-for="request in requests" :key="request.id">
            <td>{{ request.student }}</td>
            <td>{{ request.fee_name }}</td>
            <td>{{ money(request.fee_amount) }}</td>
            <td>
              <strong>{{ money(request.amount) }}</strong>
              <template v-if="request.percentage"> ({{ request.percentage }}%)</template>
            </td>
            <td>{{ request.reason }}</td>
            <td>{{ request.requested_by || '—' }}</td>
            <td>{{ request.requested_at }}</td>
            <td>
              <div class="actions">
                <button type="button" :disabled="busy === request.id" @click="decide(request, 'approve')">{{ tr('اعتماد') }}</button>
                <button type="button" class="danger compact" :disabled="busy === request.id" @click="decide(request, 'reject')">
                  {{ tr('رفض') }}
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
