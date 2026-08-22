<script setup>
import { computed, reactive, watch } from 'vue'
import { confirmAction } from '../../confirm.js'
import { pick } from '../../i18n.js'
import { tr } from '../../phrases.js'

const props = defineProps({
  categories: { type: Array, default: () => [] },
  students: { type: Array, default: () => [] },
  saving: { type: Boolean, default: false },
  submitting: { type: Boolean, default: false },
  submission: { type: Object, default: null },
})

const emit = defineEmits(['save', 'submit'])

const canEdit = computed(() => props.submission?.can_edit ?? true)
const isLocked = computed(() => props.submission?.is_locked ?? false)
const canSubmit = computed(() => props.submission?.can_submit ?? true)
const busy = computed(() => props.saving || props.submitting)

const cellKey = (studentProfileId, gradingItemId) => `${studentProfileId}:${gradingItemId}`

const scores = reactive({})
const errors = reactive({})

function seedScores() {
  Object.keys(scores).forEach((key) => delete scores[key])
  Object.keys(errors).forEach((key) => delete errors[key])

  props.students.forEach((row) => {
    const studentProfileId = row.student_profile.id
    entryItems.value.forEach((item) => {
      const key = cellKey(studentProfileId, item.id)
      scores[key] = row.scores?.[item.id] ?? null
    })
  })
}

const entryItems = computed(() =>
  props.categories.flatMap((category) => category.items.filter((item) => !item.is_total_field)),
)

const totalItems = computed(() =>
  props.categories.flatMap((category) => category.items.filter((item) => item.is_total_field)),
)

watch(() => [props.categories, props.students], seedScores, { immediate: true, deep: true })

// Mirrors GradeCalculationService::calculateCategoryAverage on the backend:
// only items that have a score entered count toward the category average,
// so a partially-graded category isn't unfairly diluted by blank items.
function categoryStats(studentProfileId, category) {
  const items = category.items.filter((item) => !item.is_total_field)
  const scoredItems = items.filter((item) => {
    const value = scores[cellKey(studentProfileId, item.id)]
    return value !== null && value !== '' && value !== undefined
  })

  if (!scoredItems.length) return { total: null, maxPossible: 0 }

  const total = scoredItems.reduce((sum, item) => sum + Number(scores[cellKey(studentProfileId, item.id)]), 0)
  const maxPossible = scoredItems.reduce((sum, item) => sum + Number(item.max_score), 0)

  return { total, maxPossible }
}

function categoryTotal(studentProfileId, category) {
  return categoryStats(studentProfileId, category).total
}

function grandTotal(studentProfileId) {
  let sum = 0
  let hasAny = false

  props.categories.forEach((category) => {
    const { total, maxPossible } = categoryStats(studentProfileId, category)
    const weight = Number(category.weight_percentage)

    if (total !== null && maxPossible > 0) {
      hasAny = true
      sum += (total / maxPossible) * weight
    }
  })

  return hasAny ? Math.round(sum * 100) / 100 : null
}

function onInput(studentProfileId, item, event) {
  const raw = event.target.value
  const key = cellKey(studentProfileId, item.id)

  if (raw === '') {
    scores[key] = null
    delete errors[key]
    return
  }

  const value = Number(raw)
  scores[key] = value

  if (Number.isNaN(value) || value < 0) {
    errors[key] = 'درجة غير صحيحة'
  } else if (value > Number(item.max_score)) {
    errors[key] = `الحد ${item.max_score}`
  } else {
    delete errors[key]
  }
}

const hasErrors = computed(() => Object.keys(errors).length > 0)

function collectScores() {
  const payload = []
  props.students.forEach((row) => {
    const studentProfileId = row.student_profile.id
    entryItems.value.forEach((item) => {
      payload.push({
        student_profile_id: studentProfileId,
        grading_item_id: item.id,
        score_obtained: scores[cellKey(studentProfileId, item.id)],
      })
    })
  })

  return payload
}

function save() {
  if (hasErrors.value || !canEdit.value) return
  emit('save', collectScores())
}

// Submitting saves first, then locks — so the teacher never loses unsaved edits.
async function submit() {
  if (hasErrors.value || !canEdit.value || !canSubmit.value) return

  const confirmed = await confirmAction(
    pick(
      'بعد الإرسال لن تتمكن من تعديل درجات هذه المادة لهذا الفصل الدراسي.',
      'Once submitted you will not be able to change this subject’s marks for this term.',
    ),
    { danger: true, confirmLabel: pick('إرسال نهائي', 'Submit finally') },
  )

  if (!confirmed) return

  emit('submit', collectScores())
}
</script>

<template>
  <div class="grade-entry-table-wrap">
    <p v-if="isLocked" class="lock-banner" :class="{ 'lock-banner-admin': canEdit }">
      <strong>{{ tr('🔒 تم إرسال الدرجات') }}</strong>
      <span>
        {{ tr('أُرسلت') }}
        <template v-if="submission?.submitted_by">{{ tr('بواسطة') }} {{ submission.submitted_by }}</template>
        <template v-if="submission?.submitted_at"> {{ tr('بتاريخ') }} {{ submission.submitted_at }}</template>.
        <template v-if="canEdit">
          {{ tr('يمكنك بصفتك إدارياً التعديل أو إعادة فتح الكشف للأستاذ.') }}
        </template>
        <template v-else>
          {{ tr('لا يمكن تعديلها. راجع الإدارة لإعادة فتحها.') }}
        </template>
      </span>
    </p>

    <p v-if="!students.length" class="muted">{{ tr('لا يوجد طلاب نشطون مسجلون في هذا الفصل.') }}</p>

    <div v-else class="table-wrap">
      <table class="grade-table">
        <thead>
          <tr>
            <th rowspan="2">{{ tr('اسم الطالب') }}</th>
            <th rowspan="2">{{ tr('رقم الطالب') }}</th>
            <template v-for="category in categories" :key="`cat-${category.id}`">
              <th :colspan="category.items.length" class="category-header">
                {{ category.name }} ({{ category.weight_percentage }}%)
              </th>
            </template>
            <th rowspan="2">{{ tr('المجموع / 100') }}</th>
          </tr>
          <tr>
            <template v-for="category in categories" :key="`items-${category.id}`">
              <th v-for="item in category.items" :key="item.id" :class="{ computed: item.is_total_field }">
                {{ item.name }}<br /><small>/{{ item.max_score }}</small>
              </th>
            </template>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in students" :key="row.student_profile.id">
            <td>{{ row.student_profile.full_name || row.student_profile.user?.name }}</td>
            <td>{{ row.student_profile.admission_no || row.student_profile.student_number }}</td>
            <template v-for="category in categories" :key="`row-${row.student_profile.id}-${category.id}`">
              <td v-for="item in category.items" :key="item.id">
                <span v-if="item.is_total_field" class="computed-cell">
                  {{ categoryTotal(row.student_profile.id, category) ?? '—' }}
                </span>
                <input
                  v-else
                  type="number"
                  min="0"
                  :max="item.max_score"
                  step="0.01"
                  :disabled="!canEdit"
                  :value="scores[cellKey(row.student_profile.id, item.id)]"
                  :class="{ 'score-error': errors[cellKey(row.student_profile.id, item.id)] }"
                  @input="onInput(row.student_profile.id, item, $event)"
                />
                <small v-if="errors[cellKey(row.student_profile.id, item.id)]" class="score-error-text">
                  {{ errors[cellKey(row.student_profile.id, item.id)] }}
                </small>
              </td>
            </template>
            <td class="computed-cell grand-total">{{ grandTotal(row.student_profile.id) ?? '—' }} / 100</td>
          </tr>
        </tbody>
      </table>
    </div>

    <div v-if="canEdit" class="actions">
      <button type="button" class="secondary" :disabled="busy || hasErrors || !students.length" @click="save">
        {{ saving ? tr('جاري الحفظ...') : tr('حفظ') }}
      </button>
      <button
        v-if="canSubmit"
        type="button"
        class="submit-grades"
        :disabled="busy || hasErrors || !students.length"
        @click="submit"
      >
        {{ submitting ? tr('جاري الإرسال...') : tr('إرسال نهائي') }}
      </button>
      <span class="muted compact-hint">
        {{ tr('«حفظ» يبقي الدرجات قابلة للتعديل، و«إرسال نهائي» يقفلها.') }}
      </span>
      <span v-if="hasErrors" class="notice error compact">{{ tr('صحح الدرجات غير الصحيحة قبل الحفظ.') }}</span>
    </div>
  </div>
</template>

<style scoped>
.grade-entry-table-wrap {
  display: grid;
  gap: 12px;
}

.grade-table th.category-header {
  text-align: center;
  background: #ecf3ff;
}

.grade-table th.computed,
.grade-table td .computed-cell {
  color: var(--app-text-muted);
}

.grade-table input[type='number'] {
  width: 72px;
  padding: 6px 7px;
}

.grade-table input.score-error {
  border-color: var(--app-danger);
  box-shadow: 0 0 0 3px rgba(240, 68, 56, 0.1);
}

.score-error-text {
  color: var(--app-danger);
  display: block;
}

.grand-total {
  font-weight: 900;
  color: var(--app-accent);
}

.notice.compact {
  padding: 8px 10px;
  font-size: 12px;
}

.grade-table input[type='number']:disabled {
  background: var(--app-surface-muted);
  color: var(--app-text-muted);
  cursor: not-allowed;
}

.lock-banner {
  display: grid;
  gap: 3px;
  padding: 11px 14px;
  border-radius: 10px;
  border: 1px solid var(--app-warn-border);
  background: var(--app-warn-soft);
  color: var(--app-warn-text);
  font-size: 13px;
}

.lock-banner-admin {
  border-color: #b2ddff;
  background: #eff8ff;
  color: #175cd3;
}

.submit-grades {
  background: #079455;
}

.submit-grades:hover:not(:disabled) {
  background: var(--app-good-text);
}

.compact-hint {
  font-size: 12px;
}
</style>
