<script setup>
import { onMounted, ref, watch } from 'vue'
import { useApi } from '../../api.js'
import { notifyError, notifySuccess } from '../../notify.js'
import { confirmAction } from '../../confirm.js'
import { tr } from '../../phrases.js'

const { api } = useApi()

const termLabels = {
  'Quarter 1': 'الفصل الأول',
  'Quarter 2': 'الفصل الثاني',
  'Quarter 3': 'الفصل الثالث',
  'Quarter 4': 'الفصل الرابع',
}

const academicYears = ref([])
const selectedYear = ref('')
const terms = ref([])
const loading = ref(false)
const savingTerm = ref('')

onMounted(async () => {
  try {
    const response = await api('/academic-years')
    academicYears.value = response.data
    selectedYear.value = academicYears.value.find((year) => year.is_active)?.name
      ?? academicYears.value[0]?.name
      ?? ''
  } catch (err) {
    notifyError(err.message)
  }
})

watch(selectedYear, (year) => {
  if (year) load()
}, { immediate: true })

async function load() {
  if (!selectedYear.value) return
  loading.value = true

  try {
    const response = await api(`/term-windows?academic_year=${encodeURIComponent(selectedYear.value)}`)
    terms.value = response.data.terms
  } catch (err) {
    notifyError(err.message)
    terms.value = []
  } finally {
    loading.value = false
  }
}

async function toggle(term) {
  const opening = !term.is_open
  const confirmText = opening
    ? `سيتم فتح ${termLabels[term.term]}: يستطيع الأساتذة إدخال الدرجات، ويستطيع الطلبة وأولياء الأمور رؤيتها. متابعة؟`
    : `سيتم إغلاق ${termLabels[term.term]}: يتوقف الإدخال ويختفي عن الطلبة وأولياء الأمور. متابعة؟`

  if (!await confirmAction(confirmText)) return

  savingTerm.value = term.term

  try {
    await api('/term-windows', {
      method: 'PUT',
      body: JSON.stringify({
        academic_year: selectedYear.value,
        term: term.term,
        is_open: opening,
      }),
    })
    notifySuccess(opening
      ? `تم فتح ${termLabels[term.term]} للأساتذة والطلبة.`
      : `تم إغلاق ${termLabels[term.term]}.`)
    await load()
  } catch (err) {
    notifyError(err.message)
  } finally {
    savingTerm.value = ''
  }
}
</script>

<template>
  <div class="workspace">
    <article class="form-card">
      <h3>{{ tr('فتح وإغلاق الفصول الدراسية') }}</h3>
      <p class="muted">
        {{ tr('عند فتح فصل دراسي يستطيع الأساتذة إدخال درجاته، ويستطيع الطلبة وأولياء الأمور رؤيتها فور حفظها. الفصل المغلق لا يظهر للطلبة ولا يمكن الإدخال فيه.') }}
      </p>
    </article>

    <div class="crud-form">
      <label>{{ tr('السنة الدراسية') }}
        <select v-model="selectedYear">
          <option v-for="year in academicYears" :key="year.id" :value="year.name">{{ year.name }}</option>
        </select>
      </label>
    </div>

    <p v-if="loading" class="muted">{{ tr('جاري التحميل...') }}</p>

    <div v-else class="term-grid">
      <article
        v-for="term in terms"
        :key="term.term"
        class="term-card"
        :class="{ open: term.is_open }"
      >
        <header>
          <div>
            <strong>{{ termLabels[term.term] }}</strong>
            <small class="muted">{{ term.term }}</small>
          </div>
          <span class="pill" :class="term.is_open ? 'pill-success' : 'pill-muted'">
            {{ term.is_open ? tr('مفتوح') : tr('مغلق') }}
          </span>
        </header>

        <p v-if="term.is_open && term.opened_at" class="term-meta">
          {{ tr('فُتح') }}
          <template v-if="term.opened_by">{{ tr('بواسطة') }} {{ term.opened_by }}</template>
          {{ tr('بتاريخ') }} {{ term.opened_at }}
        </p>
        <p v-else-if="!term.is_open && term.closed_at" class="term-meta">
          {{ tr('أُغلق') }}
          <template v-if="term.closed_by">{{ tr('بواسطة') }} {{ term.closed_by }}</template>
          {{ tr('بتاريخ') }} {{ term.closed_at }}
        </p>
        <p v-else class="term-meta muted">{{ tr('لم يُفتح بعد.') }}</p>

        <button
          type="button"
          :class="term.is_open ? 'danger' : ''"
          :disabled="savingTerm === term.term"
          @click="toggle(term)"
        >
          <template v-if="savingTerm === term.term">{{ tr('جاري الحفظ...') }}</template>
          <template v-else>{{ term.is_open ? tr('🔒 إغلاق الفصل') : tr('🔓 فتح الفصل') }}</template>
        </button>
      </article>
    </div>
  </div>
</template>

<style scoped>
.term-grid {
  display: grid;
  gap: 14px;
  grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
}

.term-card {
  display: grid;
  gap: 10px;
  align-content: start;
  border: 1px solid var(--app-border);
  border-inline-start: 4px solid var(--app-border-strong);
  border-radius: 12px;
  background: var(--app-surface);
  padding: 16px;
}

.term-card.open {
  border-inline-start-color: #17b26a;
  background: #f6fef9;
}

.term-card header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 10px;
}

.term-card header small {
  display: block;
  font-size: 12px;
}

.term-meta {
  font-size: 12px;
  color: var(--app-text);
  margin: 0;
}

.pill {
  border-radius: 999px;
  padding: 3px 10px;
  font-size: 12px;
  font-weight: 800;
  white-space: nowrap;
}

.pill-success { background: var(--app-good-soft); color: var(--app-good-text); }
.pill-muted { background: var(--app-surface-muted); color: var(--app-text-muted); }
</style>
