<script setup>
import { computed } from 'vue'
import { tr } from '../../phrases.js'

const props = defineProps({
  courses: { type: Array, default: () => [] },
})

const sorted = computed(() =>
  [...props.courses].sort((a, b) => (a.course?.name ?? '').localeCompare(b.course?.name ?? '')),
)

function gradeTone(grade) {
  if (grade === null || grade === undefined) return 'tone-none'
  if (grade >= 80) return 'tone-good'
  if (grade >= 60) return 'tone-fair'
  return 'tone-poor'
}
</script>

<template>
  <p v-if="!sorted.length" class="muted">{{ tr('لا توجد مواد لعرضها في هذه الفترة.') }}</p>

  <div v-else class="grade-cards">
    <article v-for="item in sorted" :key="`${item.course.id}-${item.section?.id}`" class="grade-card">
      <header>
        <div>
          <strong>{{ item.course.name }}</strong>
          <small class="muted">
            {{ item.course.code }}
            <template v-if="item.teacher?.name"> · {{ item.teacher.name }}</template>
          </small>
        </div>
        <span class="grade-total" :class="gradeTone(item.report?.final_grade)">
          {{ item.report?.final_grade ?? '—' }} / 100
        </span>
      </header>

      <div v-if="item.report?.category_breakdown?.length" class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>{{ tr('الفئة') }}</th>
              <th>{{ tr('الوزن') }}</th>
              <th>{{ tr('النسبة') }}</th>
              <th>{{ tr('النقاط') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="category in item.report.category_breakdown" :key="category.category_id">
              <td>{{ category.category_name }}</td>
              <td>{{ category.weight }}%</td>
              <td>
                <span v-if="category.average_percent !== null">{{ category.average_percent }}%</span>
                <span v-else class="muted">{{ tr('لم تُرصد') }}</span>
              </td>
              <td>{{ category.weighted_points }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <p v-if="item.report?.missing_scores?.length" class="missing">
        {{ tr('بانتظار رصد:') }} {{ item.report.missing_scores.join('، ') }}
      </p>
    </article>
  </div>
</template>

<style scoped>
.grade-cards {
  display: grid;
  gap: 14px;
  grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
}

.grade-card {
  display: grid;
  gap: 10px;
  align-content: start;
  border: 1px solid var(--app-border);
  border-radius: 12px;
  background: var(--app-surface);
  padding: 16px;
}

.grade-card header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
}

.grade-card header small {
  display: block;
  font-size: 12px;
}

.grade-total {
  font-weight: 900;
  font-size: 15px;
  border-radius: 999px;
  padding: 4px 12px;
  white-space: nowrap;
}

.tone-good { background: var(--app-good-soft); color: var(--app-good-text); }
.tone-fair { background: var(--app-warn-soft); color: var(--app-warn-text); }
.tone-poor { background: var(--app-danger-soft); color: var(--app-danger-text); }
.tone-none { background: var(--app-surface-muted); color: var(--app-text-muted); }

.missing {
  margin: 0;
  font-size: 12px;
  color: var(--app-warn-text);
  background: var(--app-warn-soft);
  border-radius: 8px;
  padding: 7px 10px;
}
</style>
