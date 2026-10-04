<script setup>
import { Download } from 'lucide-vue-next'
import { tr } from '../../phrases.js'

defineProps({
  publications: { type: Array, default: () => [] },
  studentName: { type: String, default: '' },
})

defineEmits(['download'])

const periodLabels = {
  'Quarter 1': 'الكورتر الأول',
  'Quarter 2': 'الكورتر الثاني',
  'Quarter 3': 'الكورتر الثالث',
  'Quarter 4': 'الكورتر الرابع',
  'Semester 1': 'السيمستر الأول',
  'Semester 2': 'السيمستر الثاني',
  'Final Report': 'التقرير النهائي للسنة',
}
</script>

<template>
  <article class="form-card">
    <h3>{{ tr('تقارير الدرجات المرسلة من الإدارة') }}</h3>

    <div v-if="publications.length" class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>{{ tr('اسم الطالب') }}</th>
            <th>{{ tr('الفترة') }}</th>
            <th>{{ tr('الفصل') }}</th>
            <th>{{ tr('تاريخ الإرسال') }}</th>
            <th>{{ tr('تنزيل') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="publication in publications" :key="publication.id">
            <td><strong>{{ studentName || '—' }}</strong></td>
            <td>{{ tr(periodLabels[publication.period] || publication.period) }}</td>
            <td>
              {{ publication.course_section?.class_name || publication.course_section?.section_code || '—' }}
            </td>
            <td>{{ publication.published_at || '—' }}</td>
            <td>
              <button
                type="button"
                class="secondary compact download-button"
                :aria-label="`${tr('تنزيل تقرير')} ${periodLabels[publication.period] || publication.period}`"
                @click="$emit('download', publication)"
              >
                <Download :size="17" aria-hidden="true" />
                {{ tr('تنزيل PDF') }}
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <p v-else class="muted">
      {{ tr('لم ترسل الإدارة أي تقرير درجات لهذا الطالب بعد.') }}
    </p>
  </article>
</template>

<style scoped>
.download-button {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
}
</style>
