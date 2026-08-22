<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useApi } from '../../api.js'
import { notifyError, notifySuccess } from '../../notify.js'
import { confirmDelete } from '../../confirm.js'
import { isArabic, pick } from '../../i18n.js'
import { tr } from '../../phrases.js'

/**
 * The school calendar — one calendar for the whole school.
 *
 * Parents, students and teachers read it; the office writes to it. The same
 * component serves both, because what changes between them is which buttons
 * exist, not what the month looks like.
 */
const { api } = useApi()

const today = new Date()
const month = ref(today.getMonth() + 1)
const year = ref(today.getFullYear())
const events = ref([])
const kinds = ref([])
const kindColors = ref({})
const canManage = ref(false)
const loading = ref(false)
const saving = ref(false)
const editing = ref(null)
const selectedDate = ref(null)

const kindLabels = {
  activity: { ar: 'نشاط', en: 'Activity' },
  holiday: { ar: 'عطلة', en: 'Holiday' },
  exam: { ar: 'امتحان', en: 'Exam' },
  meeting: { ar: 'اجتماع', en: 'Meeting' },
  other: { ar: 'أخرى', en: 'Other' },
}

const monthLabel = computed(() => new Date(year.value, month.value - 1, 1)
  .toLocaleString(isArabic.value ? 'ar' : 'en', { month: 'long', year: 'numeric' }))

const weekdayNames = computed(() => {
  // The week starts on Sunday here, matching the school week.
  const base = new Date(2024, 0, 7)

  return Array.from({ length: 7 }, (_, i) => new Date(base.getFullYear(), base.getMonth(), base.getDate() + i)
    .toLocaleString(isArabic.value ? 'ar' : 'en', { weekday: 'short' }))
})

/**
 * The month laid out as whole weeks, with the leading and trailing days of the
 * neighbouring months left blank so the columns line up under their weekday.
 */
const weeks = computed(() => {
  const first = new Date(year.value, month.value - 1, 1)
  const daysInMonth = new Date(year.value, month.value, 0).getDate()
  const cells = []

  for (let i = 0; i < first.getDay(); i++) cells.push(null)

  for (let day = 1; day <= daysInMonth; day++) {
    const date = `${year.value}-${String(month.value).padStart(2, '0')}-${String(day).padStart(2, '0')}`
    cells.push({
      day,
      date,
      isToday: date === new Date().toISOString().slice(0, 10),
      events: events.value.filter((event) => date >= event.starts_on && date <= event.ends_on),
    })
  }

  while (cells.length % 7 !== 0) cells.push(null)

  return Array.from({ length: cells.length / 7 }, (_, i) => cells.slice(i * 7, i * 7 + 7))
})

const upcoming = computed(() => [...events.value].sort((a, b) => a.starts_on.localeCompare(b.starts_on)))

onMounted(load)
watch([month, year], load)

async function load() {
  loading.value = true

  try {
    const response = await api(`/calendar-events?year=${year.value}&month=${month.value}`)
    events.value = response.data
    kinds.value = response.kinds
    kindColors.value = response.kind_colors
    canManage.value = response.can_manage
  } catch (err) {
    notifyError(err.message)
  } finally {
    loading.value = false
  }
}

function shiftMonth(step) {
  const next = new Date(year.value, month.value - 1 + step, 1)
  year.value = next.getFullYear()
  month.value = next.getMonth() + 1
}

function goToday() {
  const now = new Date()
  year.value = now.getFullYear()
  month.value = now.getMonth() + 1
}

// ---- writing ----------------------------------------------------------------

function newEvent(date = null) {
  if (!canManage.value) return

  const start = date ?? new Date().toISOString().slice(0, 10)

  editing.value = {
    id: null,
    title: '',
    description: '',
    kind: 'activity',
    color: kindColors.value.activity ?? '#465fff',
    starts_on: start,
    ends_on: start,
    all_day: true,
    starts_at: '',
    ends_at: '',
    location: '',
  }
}

function editEvent(event) {
  if (!canManage.value) return

  editing.value = { ...event, starts_at: event.starts_at ?? '', ends_at: event.ends_at ?? '' }
}

// Changing the kind moves the colour with it, unless it has been set by hand.
function onKindChange() {
  const previous = Object.values(kindColors.value)

  if (previous.includes(editing.value.color)) {
    editing.value.color = kindColors.value[editing.value.kind] ?? editing.value.color
  }
}

async function save() {
  if (!editing.value.title.trim()) {
    notifyError(pick('اكتب عنوان الفعالية.', 'Give the event a title.'))

    return
  }

  saving.value = true

  const payload = {
    title: editing.value.title.trim(),
    description: editing.value.description || null,
    kind: editing.value.kind,
    color: editing.value.color,
    starts_on: editing.value.starts_on,
    ends_on: editing.value.ends_on || editing.value.starts_on,
    all_day: editing.value.all_day,
    starts_at: editing.value.all_day ? null : (editing.value.starts_at || null),
    ends_at: editing.value.all_day ? null : (editing.value.ends_at || null),
    location: editing.value.location || null,
  }

  try {
    await api(
      editing.value.id ? `/calendar-events/${editing.value.id}` : '/calendar-events',
      { method: editing.value.id ? 'PUT' : 'POST', body: JSON.stringify(payload) },
    )
    notifySuccess(pick('تم حفظ الفعالية.', 'Event saved.'))
    editing.value = null
    await load()
  } catch (err) {
    notifyError(err.message)
  } finally {
    saving.value = false
  }
}

async function remove(event) {
  const ok = await confirmDelete(pick(
    `ستُحذف «${event.title}» من تقويم المدرسة.`,
    `"${event.title}" will be removed from the school calendar.`,
  ))
  if (!ok) return

  try {
    await api(`/calendar-events/${event.id}`, { method: 'DELETE' })
    notifySuccess(pick('تم حذف الفعالية.', 'Event deleted.'))
    await load()
  } catch (err) {
    notifyError(err.message)
  }
}

function kindLabel(kind) {
  const found = kindLabels[kind]

  return found ? pick(found.ar, found.en) : kind
}

function dayRange(event) {
  if (event.starts_on === event.ends_on) return event.starts_on

  return `${event.starts_on} → ${event.ends_on}`
}
</script>

<template>
  <div class="workspace">
    <article class="panel">
      <div class="permission-card-header">
        <div>
          <h3>{{ tr('تقويم المدرسة') }}</h3>
          <p class="muted">
            {{ canManage
              ? tr('أضف النشاطات والعطلات والامتحانات بألوانها — يراها أولياء الأمور والطلبة والأساتذة.')
              : tr('نشاطات المدرسة وعطلاتها ومواعيدها المهمة.') }}
          </p>
        </div>
        <div class="actions">
          <button type="button" class="secondary compact" @click="shiftMonth(-1)">‹</button>
          <button type="button" class="secondary compact" @click="goToday">{{ tr('هذا الشهر') }}</button>
          <button type="button" class="secondary compact" @click="shiftMonth(1)">›</button>
          <button v-if="canManage" type="button" @click="newEvent()">{{ tr('إضافة فعالية') }}</button>
        </div>
      </div>

      <h4 class="month-label">{{ monthLabel }}</h4>

      <div class="legend">
        <span v-for="kind in kinds" :key="kind" class="legend-item">
          <i :style="{ background: kindColors[kind] }"></i>{{ kindLabel(kind) }}
        </span>
      </div>
    </article>

    <p v-if="loading" class="muted">{{ tr('جاري التحميل...') }}</p>

    <template v-else>
      <article class="panel">
        <div class="calendar">
          <div v-for="name in weekdayNames" :key="name" class="calendar-weekday">{{ name }}</div>

          <template v-for="(week, wi) in weeks" :key="wi">
            <div
              v-for="(cell, ci) in week"
              :key="`${wi}-${ci}`"
              :class="['calendar-cell', { empty: !cell, today: cell?.isToday, clickable: canManage && cell }]"
              @click="cell && canManage ? newEvent(cell.date) : null"
            >
              <template v-if="cell">
                <span class="calendar-day">{{ cell.day }}</span>
                <button
                  v-for="event in cell.events"
                  :key="event.id"
                  type="button"
                  class="calendar-event"
                  :style="{ background: event.color }"
                  :title="event.title"
                  @click.stop="editEvent(event)"
                >{{ event.title }}</button>
              </template>
            </div>
          </template>
        </div>
      </article>

      <article class="panel">
        <h3>{{ tr('فعاليات الشهر') }}</h3>
        <p v-if="!upcoming.length" class="muted">{{ tr('لا توجد فعاليات هذا الشهر.') }}</p>

        <div v-else class="event-list">
          <div v-for="event in upcoming" :key="event.id" class="event-row">
            <span class="event-dot" :style="{ background: event.color }"></span>
            <div class="event-main">
              <strong>{{ event.title }}</strong>
              <span class="muted">
                {{ kindLabel(event.kind) }} · {{ dayRange(event) }}
                <template v-if="!event.all_day && event.starts_at"> · {{ event.starts_at }}<template v-if="event.ends_at">–{{ event.ends_at }}</template></template>
                <template v-if="event.location"> · {{ event.location }}</template>
              </span>
              <p v-if="event.description" class="muted">{{ event.description }}</p>
            </div>
            <div v-if="canManage" class="actions">
              <button type="button" class="secondary compact" @click="editEvent(event)">{{ tr('تعديل') }}</button>
              <button type="button" class="danger compact" @click="remove(event)">{{ tr('حذف') }}</button>
            </div>
          </div>
        </div>
      </article>
    </template>

    <!-- ------------------------------- editor ------------------------------- -->
    <div v-if="editing" class="modal-backdrop" @click.self="editing = null">
      <article class="student-modal subjects-modal">
        <h3>{{ editing.id ? tr('تعديل فعالية') : tr('إضافة فعالية') }}</h3>

        <div class="crud-form">
          <label class="grow">{{ tr('العنوان') }} <input v-model="editing.title" /></label>
          <label>{{ tr('النوع') }}
            <select v-model="editing.kind" @change="onKindChange">
              <option v-for="kind in kinds" :key="kind" :value="kind">{{ kindLabel(kind) }}</option>
            </select>
          </label>
          <label>{{ tr('اللون') }} <input v-model="editing.color" type="color" /></label>
          <label>{{ tr('من تاريخ') }} <input v-model="editing.starts_on" type="date" /></label>
          <label>{{ tr('إلى تاريخ') }} <input v-model="editing.ends_on" type="date" /></label>
          <label class="checkbox-label">
            <input v-model="editing.all_day" type="checkbox" /> {{ tr('يوم كامل') }}
          </label>
          <template v-if="!editing.all_day">
            <label>{{ tr('من الساعة') }} <input v-model="editing.starts_at" type="time" /></label>
            <label>{{ tr('إلى الساعة') }} <input v-model="editing.ends_at" type="time" /></label>
          </template>
          <label class="grow">{{ tr('المكان') }} <input v-model="editing.location" /></label>
          <label class="grow">{{ tr('التفاصيل') }}
            <textarea v-model="editing.description" rows="2"></textarea>
          </label>
        </div>

        <div class="actions">
          <button type="button" :disabled="saving" @click="save">
            {{ saving ? tr('جاري الحفظ...') : tr('حفظ') }}
          </button>
          <button type="button" class="secondary" @click="editing = null">{{ tr('إلغاء') }}</button>
        </div>
      </article>
    </div>
  </div>
</template>

<style scoped>
.month-label { margin: 0; font-size: 1.05rem; }

.legend {
  display: flex;
  flex-wrap: wrap;
  gap: 0.9rem;
  font-size: 0.8rem;
  color: var(--app-text-muted);
}

.legend-item { display: inline-flex; align-items: center; gap: 0.35rem; }
.legend-item i { width: 0.7rem; height: 0.7rem; border-radius: 3px; display: inline-block; }

.calendar {
  display: grid;
  grid-template-columns: repeat(7, minmax(0, 1fr));
  gap: 2px;
}

.calendar-weekday {
  text-align: center;
  font-size: 0.75rem;
  font-weight: 700;
  color: var(--app-text-muted);
  padding-bottom: 0.3rem;
}

.calendar-cell {
  min-height: 84px;
  border: 1px solid var(--app-border);
  border-radius: 0.5rem;
  padding: 0.25rem;
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
  background: var(--app-surface);
}

.calendar-cell.empty { border-style: dashed; opacity: 0.4; }
.calendar-cell.today { border-color: var(--app-accent); box-shadow: inset 0 0 0 1px var(--app-accent); }
.calendar-cell.clickable { cursor: pointer; }
.calendar-cell.clickable:hover { background: var(--app-surface-muted); }

.calendar-day { font-size: 0.75rem; font-weight: 700; color: var(--app-text-muted); }

.calendar-event {
  display: block;
  width: 100%;
  text-align: start;
  padding: 0.1rem 0.35rem;
  border-radius: 0.3rem;
  border: none;
  color: #fff;
  font-size: 0.7rem;
  font-weight: 600;
  line-height: 1.4;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  box-shadow: none;
}

.event-list { display: grid; gap: 0.5rem; }

.event-row {
  display: flex;
  align-items: start;
  gap: 0.6rem;
  padding: 0.6rem 0.75rem;
  border: 1px solid var(--app-border);
  border-radius: 0.75rem;
  background: var(--app-surface-muted);
}

.event-dot { width: 0.75rem; height: 0.75rem; border-radius: 999px; margin-top: 0.3rem; flex: none; }
.event-main { display: grid; gap: 0.15rem; flex: 1; min-width: 0; }
.event-main p { margin: 0; }
</style>
