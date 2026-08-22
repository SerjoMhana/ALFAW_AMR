<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useApi } from '../../api.js'
import { notifyError, notifyInfo, notifySuccess } from '../../notify.js'
import { confirmAction } from '../../confirm.js'
import { pick } from '../../i18n.js'
import { tr } from '../../phrases.js'
import GoogleClassroomGuide from './GoogleClassroomGuide.vue'

/**
 * Google Classroom, driven from here: one Classroom course per subject, the
 * assigned teacher added as a teacher, and the class roster enrolled.
 *
 * Nothing happens on its own — every action is a button the admin presses, so a
 * wrong Workspace setting cannot quietly spray courses across the school.
 */
const { api } = useApi()

const settings = ref(null)
const sections = ref([])
const sectionId = ref('')
const status = ref(null)
const loading = ref(false)
const busy = ref('')
const showGuide = ref(false)
const emailPattern = ref('admission_no')
const savingEmails = ref(false)

// The addresses being edited, keyed by user id, so the table stays editable
// without writing to the server on every keystroke.
const emailDraft = ref({})

const picker = ref(null)

onMounted(async () => {
  await Promise.all([loadSettings(), loadSections()])
})

watch(sectionId, (value) => {
  if (value) loadStatus()
  else status.value = null
})

async function loadSettings() {
  try {
    settings.value = (await api('/google-classroom/settings')).data
  } catch (err) {
    notifyError(err.message)
  }
}

async function loadSections() {
  try {
    const response = await api('/course-sections')
    sections.value = response.data ?? response
  } catch (err) {
    notifyError(err.message)
  }
}

async function loadStatus() {
  loading.value = true

  try {
    status.value = (await api(`/google-classroom/classes/${sectionId.value}`)).data
    resetDrafts()
  } catch (err) {
    notifyError(err.message)
    status.value = null
  } finally {
    loading.value = false
  }
}

function resetDrafts() {
  const draft = {}

  for (const student of status.value?.students ?? []) {
    draft[student.user_id] = student.google_email ?? ''
  }
  for (const subject of status.value?.subjects ?? []) {
    if (subject.teacher_user_id) draft[subject.teacher_user_id] = subject.teacher_google_email ?? ''
  }

  emailDraft.value = draft
}

// ---- addresses --------------------------------------------------------------

const teacherRows = computed(() => {
  const seen = new Map()

  for (const subject of status.value?.subjects ?? []) {
    if (subject.teacher_user_id && !seen.has(subject.teacher_user_id)) {
      seen.set(subject.teacher_user_id, {
        user_id: subject.teacher_user_id,
        name: subject.teacher,
        subject: subject.name,
      })
    }
  }

  return [...seen.values()]
})

const emailsDirty = computed(() => {
  const saved = {}
  for (const student of status.value?.students ?? []) saved[student.user_id] = student.google_email ?? ''
  for (const row of teacherRows.value) {
    const subject = status.value.subjects.find((item) => item.teacher_user_id === row.user_id)
    saved[row.user_id] = subject?.teacher_google_email ?? ''
  }

  return Object.entries(emailDraft.value).some(([id, value]) => (saved[id] ?? '') !== value)
})

const readyCount = computed(() => (status.value?.students ?? []).filter((student) => student.ready).length)

async function suggestEmails() {
  try {
    const response = await api(`/google-classroom/classes/${sectionId.value}/suggest-emails`, {
      method: 'POST',
      body: JSON.stringify({ pattern: emailPattern.value }),
    })

    if (!response.data.length) {
      notifyInfo(pick('كل الطلبة لديهم عناوين بالفعل.', 'Every student already has an address.'))

      return
    }

    for (const suggestion of response.data) {
      emailDraft.value[suggestion.user_id] = suggestion.google_email
    }

    notifyInfo(pick(
      `تم اقتراح ${response.data.length} عنواناً — راجعها ثم اضغط حفظ العناوين.`,
      `${response.data.length} addresses suggested — review them, then press Save addresses.`,
    ))
  } catch (err) {
    notifyError(err.message)
  }
}

async function saveEmails() {
  savingEmails.value = true

  try {
    const emails = Object.entries(emailDraft.value).map(([userId, email]) => ({
      user_id: Number(userId),
      google_email: email.trim() || null,
    }))

    await api('/google-classroom/emails', { method: 'PUT', body: JSON.stringify({ emails }) })
    notifySuccess(pick('تم حفظ العناوين.', 'Addresses saved.'))
    await loadStatus()
  } catch (err) {
    notifyError(err.message)
  } finally {
    savingEmails.value = false
  }
}

// ---- the Classroom courses --------------------------------------------------

async function run(key, action, successText) {
  busy.value = key

  try {
    const result = await action()
    if (successText) notifySuccess(successText)
    await loadStatus()

    return result
  } catch (err) {
    notifyError(err.message)

    return null
  } finally {
    busy.value = ''
  }
}

function createCourse(subject) {
  return run(
    `create-${subject.course_id}`,
    () => api(`/google-classroom/courses/${subject.course_id}`, { method: 'POST' }),
    pick('تم إنشاء الفصل في Google Classroom.', 'The course was created in Google Classroom.'),
  )
}

const renaming = ref(null)

function renameCourse(subject) {
  renaming.value = { course_id: subject.course_id, name: subject.google_name ?? subject.name }
}

function submitRename() {
  const pending = renaming.value
  if (!pending?.name?.trim()) return

  renaming.value = null

  return run(
    `rename-${pending.course_id}`,
    () => api(`/google-classroom/courses/${pending.course_id}`, {
      method: 'PUT',
      body: JSON.stringify({ name: pending.name.trim() }),
    }),
    pick('تم تغيير الاسم.', 'The name was changed.'),
  )
}

function addTeacher(subject) {
  return run(
    `teacher-${subject.course_id}`,
    () => api(`/google-classroom/courses/${subject.course_id}/teacher`, { method: 'POST' }),
    pick('تمت إضافة الأستاذ.', 'The teacher was added.'),
  )
}

function openPicker(subject) {
  picker.value = {
    subject,
    // Anyone without an address cannot be enrolled, so they start unticked.
    selected: (status.value.students ?? []).filter((student) => student.ready).map((student) => student.id),
  }
}

function toggleStudent(id) {
  const selected = picker.value.selected
  const index = selected.indexOf(id)
  index === -1 ? selected.push(id) : selected.splice(index, 1)
}

async function submitPicker() {
  const pending = picker.value
  picker.value = null

  const result = await run(
    `students-${pending.subject.course_id}`,
    () => api(`/google-classroom/courses/${pending.subject.course_id}/students`, {
      method: 'POST',
      body: JSON.stringify({ student_profile_ids: pending.selected }),
    }),
  )

  if (!result) return

  const { added = [], invited = [], skipped = [] } = result.data
  const parts = []

  if (added.length) parts.push(pick(`أُضيف ${added.length}`, `${added.length} added`))
  if (invited.length) parts.push(pick(`دُعي ${invited.length}`, `${invited.length} invited`))
  if (skipped.length) parts.push(pick(`تُخطّي ${skipped.length}`, `${skipped.length} skipped`))

  notifySuccess(parts.join(' · ') || pick('لا جديد — الجميع مسجّل بالفعل.', 'Nothing new — everyone is already enrolled.'))

  for (const row of skipped) {
    notifyError(`${row.name}: ${row.reason === 'no_google_email'
      ? pick('لا يوجد بريد Google', 'no Google address')
      : row.reason}`)
  }
}

async function archiveCourse(subject) {
  const ok = await confirmAction(
    pick(
      `ستُؤرشَف مادة «${subject.name}» في Google Classroom ويتوقف نشاطها هناك. الدرجات في هذه المنظومة لا تتأثر.`,
      `“${subject.name}” will be archived in Google Classroom and go quiet there. Marks in this system are untouched.`,
    ),
    { danger: true, confirmLabel: pick('أرشفة', 'Archive') },
  )
  if (!ok) return

  return run(
    `archive-${subject.course_id}`,
    () => api(`/google-classroom/courses/${subject.course_id}/archive`, { method: 'POST' }),
    pick('تمت الأرشفة.', 'Archived.'),
  )
}

function stateLabel(subject) {
  if (!subject.linked) return pick('غير مربوط', 'Not linked')
  if (subject.state === 'ARCHIVED') return pick('مؤرشف', 'Archived')
  if (subject.state === 'ACTIVE') return pick('فعّال', 'Active')

  return pick('جاهز للتفعيل', 'Awaiting activation')
}
</script>

<template>
  <div class="workspace">
    <!-- ------------------------------ connection ------------------------------ -->
    <article class="panel">
      <div class="permission-card-header">
        <div>
          <h3>{{ tr('ربط Google Classroom') }}</h3>
          <p class="muted">
            {{ tr('كل مادة هنا تقابل فصلاً واحداً في Google Classroom، ويُضاف إليه أستاذها وطلبتها من هذه الصفحة.') }}
          </p>
        </div>
        <div class="actions">
          <button type="button" class="secondary" @click="showGuide = !showGuide">
            {{ showGuide ? tr('إخفاء دليل الربط') : tr('كيف أربط البريد؟') }}
          </button>
        </div>
      </div>

      <div v-if="settings" class="gc-connection">
        <span :class="['gc-badge', settings.enabled ? 'ok' : 'warn']">
          {{ settings.enabled ? tr('متصل بـ Google') : tr('وضع التجربة — بلا اتصال فعلي') }}
        </span>
        <span class="muted">
          {{ tr('نطاق المدرسة') }}: <strong>{{ settings.domain || tr('غير مضبوط') }}</strong>
        </span>
        <span class="muted">
          {{ tr('حساب الإدارة') }}: <strong>{{ settings.impersonate || tr('غير مضبوط') }}</strong>
        </span>
        <span :class="['gc-badge', settings.credentials_present ? 'ok' : 'warn']">
          {{ settings.credentials_present ? tr('ملف المفتاح موجود') : tr('ملف المفتاح غير موجود') }}
        </span>
      </div>

      <p v-if="settings && !settings.enabled" class="muted">
        {{ tr('الصفحة تعمل الآن على نسخة تجريبية داخلية: كل شيء يُحفظ ويُعرض، ولا يُرسل شيء إلى Google حتى تُفعّل الربط من ملف .env.') }}
      </p>
    </article>

    <GoogleClassroomGuide v-if="showGuide" />

    <!-- -------------------------------- class -------------------------------- -->
    <article class="panel">
      <div class="crud-form">
        <label class="grow">{{ tr('اختر الفصل') }}
          <select v-model="sectionId">
            <option value="">{{ tr('— اختر —') }}</option>
            <option v-for="section in sections" :key="section.id" :value="section.id">
              {{ section.class_name || section.section_code }} — {{ section.academic_year }}
            </option>
          </select>
        </label>
      </div>
    </article>

    <p v-if="loading" class="muted">{{ tr('جاري التحميل...') }}</p>

    <template v-else-if="status">
      <!-- ------------------------------ addresses ------------------------------ -->
      <article class="panel">
        <div class="permission-card-header">
          <div>
            <h3>{{ tr('عناوين Google للطلبة والأساتذة') }}</h3>
            <p class="muted">
              {{ tr('لا يُعرف أحد في Google إلا ببريده على نطاق المدرسة. لا يمكن تسجيل طالب بلا عنوان.') }}
            </p>
          </div>
          <div class="actions">
            <button type="button" :disabled="!emailsDirty || savingEmails" @click="saveEmails">
              {{ savingEmails ? tr('جاري الحفظ...') : tr('حفظ العناوين') }}
            </button>
          </div>
        </div>

        <div class="crud-form">
          <label>{{ tr('اقتراح العناوين حسب') }}
            <select v-model="emailPattern">
              <option value="admission_no">{{ tr('رقم القبول') }}</option>
              <option value="username">{{ tr('الاسم') }}</option>
            </select>
          </label>
          <button type="button" class="secondary" :disabled="!settings?.domain" @click="suggestEmails">
            {{ tr('اقترح للفارغين') }}
          </button>
          <span class="muted">
            {{ tr('جاهزون') }}: <strong>{{ readyCount }}</strong> / {{ status.students.length }}
          </span>
        </div>

        <div v-if="teacherRows.length" class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>{{ tr('الأستاذ') }}</th>
                <th>{{ tr('المادة') }}</th>
                <th>{{ tr('بريد Google') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in teacherRows" :key="`t-${row.user_id}`">
                <td>{{ row.name }}</td>
                <td class="muted">{{ row.subject }}</td>
                <td><input v-model="emailDraft[row.user_id]" type="email" dir="ltr" placeholder="name@domain" /></td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>{{ tr('الطالب') }}</th>
                <th>{{ tr('رقم القبول') }}</th>
                <th>{{ tr('بريد Google') }}</th>
                <th>{{ tr('الحالة') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="student in status.students" :key="student.id">
                <td>{{ student.name }}</td>
                <td>{{ student.admission_no }}</td>
                <td><input v-model="emailDraft[student.user_id]" type="email" dir="ltr" placeholder="name@domain" /></td>
                <td>
                  <span :class="['gc-badge', student.ready ? 'ok' : 'warn']">
                    {{ student.ready ? tr('جاهز') : tr('بلا بريد') }}
                  </span>
                </td>
              </tr>
              <tr v-if="!status.students.length">
                <td colspan="4" class="muted">{{ tr('لا يوجد طلبة في هذا الفصل.') }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </article>

      <!-- ------------------------------- subjects ------------------------------- -->
      <article class="panel">
        <h3>{{ tr('المواد وفصولها في Google Classroom') }}</h3>

        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>{{ tr('المادة') }}</th>
                <th>{{ tr('الأستاذ') }}</th>
                <th>{{ tr('الحالة') }}</th>
                <th>{{ tr('الطلبة') }}</th>
                <th>{{ tr('رمز الانضمام') }}</th>
                <th>{{ tr('الإجراءات') }}</th>
              </tr>
            </thead>
            <tbody>
            <tr v-for="subject in status.subjects" :key="subject.course_id">
              <td>
                <strong>{{ subject.name }}</strong>
                <div v-if="subject.google_name && subject.google_name !== subject.name" class="muted">
                  {{ subject.google_name }}
                </div>
                <div v-if="subject.last_error" class="gc-error">{{ subject.last_error }}</div>
              </td>
              <td>
                {{ subject.teacher || tr('بلا معلم') }}
                <span v-if="subject.teacher_added" class="gc-badge ok">{{ tr('مضاف') }}</span>
              </td>
              <td>
                <span :class="['gc-badge', subject.linked ? 'ok' : 'warn']">{{ stateLabel(subject) }}</span>
                <a v-if="subject.link" :href="subject.link" target="_blank" rel="noopener">{{ tr('فتح') }}</a>
              </td>
              <td>
                {{ subject.students_enrolled }}
                <span v-if="subject.students_missing" class="muted">
                  ({{ tr('ناقص') }} {{ subject.students_missing }})
                </span>
              </td>
              <td dir="ltr">{{ subject.enrollment_code || '—' }}</td>
              <td class="actions">
                <button
                  v-if="!subject.linked"
                  type="button"
                  :disabled="busy === `create-${subject.course_id}`"
                  @click="createCourse(subject)"
                >
                  {{ tr('إنشاء الفصل') }}
                </button>
                <template v-else>
                  <button type="button" class="secondary compact" @click="renameCourse(subject)">
                    {{ tr('إعادة التسمية') }}
                  </button>
                  <button
                    type="button"
                    class="secondary compact"
                    :disabled="!subject.teacher_user_id || busy === `teacher-${subject.course_id}`"
                    @click="addTeacher(subject)"
                  >
                    {{ tr('إضافة الأستاذ') }}
                  </button>
                  <button
                    type="button"
                    class="compact"
                    :disabled="busy === `students-${subject.course_id}`"
                    @click="openPicker(subject)"
                  >
                    {{ tr('إضافة الطلبة') }}
                  </button>
                  <button type="button" class="danger compact" @click="archiveCourse(subject)">
                    {{ tr('أرشفة') }}
                  </button>
                </template>
              </td>
            </tr>
            <tr v-if="!status.subjects.length">
              <td colspan="6" class="muted">{{ tr('لا توجد مواد في هذا الفصل.') }}</td>
            </tr>
            </tbody>
          </table>
        </div>
      </article>
    </template>

    <!-- ------------------------------- rename ------------------------------- -->
    <div v-if="renaming" class="modal-backdrop" @click.self="renaming = null">
      <div class="student-modal subjects-modal">
        <h3>{{ tr('إعادة تسمية الفصل في Google Classroom') }}</h3>
        <label class="grow">{{ tr('الاسم') }} <input v-model="renaming.name" /></label>
        <div class="actions">
          <button type="button" @click="submitRename">{{ tr('حفظ') }}</button>
          <button type="button" class="secondary" @click="renaming = null">{{ tr('إلغاء') }}</button>
        </div>
      </div>
    </div>

    <!-- --------------------------- student picker --------------------------- -->
    <div v-if="picker" class="modal-backdrop" @click.self="picker = null">
      <div class="student-modal subjects-modal">
        <h3>{{ tr('اختر الطلبة') }} — {{ picker.subject.name }}</h3>
        <p class="muted">{{ tr('من ليس لديه بريد Google لا يمكن تسجيله، ويظهر معطّلاً.') }}</p>

        <div class="gc-picker">
          <label v-for="student in status.students" :key="student.id" class="checkbox-label">
            <input
              type="checkbox"
              :disabled="!student.ready"
              :checked="picker.selected.includes(student.id)"
              @change="toggleStudent(student.id)"
            />
            {{ student.name }}
            <span class="muted" dir="ltr">{{ student.google_email || tr('بلا بريد') }}</span>
          </label>
        </div>

        <div class="actions">
          <button type="button" :disabled="!picker.selected.length" @click="submitPicker">
            {{ tr('تسجيل المحدّدين') }} ({{ picker.selected.length }})
          </button>
          <button type="button" class="secondary" @click="picker = null">{{ tr('إلغاء') }}</button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.gc-connection {
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem 1.25rem;
  align-items: center;
  margin-top: 0.5rem;
}

.gc-badge {
  display: inline-block;
  padding: 0.15rem 0.6rem;
  border-radius: 999px;
  font-size: 0.78rem;
  font-weight: 600;
}

.gc-badge.ok {
  background: var(--app-good-soft);
  color: var(--app-good-text);
}

.gc-badge.warn {
  background: var(--app-warn-soft);
  color: var(--app-warn-text);
}

.gc-error {
  font-size: 0.78rem;
  color: var(--app-danger-text);
}

.gc-picker {
  display: grid;
  gap: 0.35rem;
  max-height: 45vh;
  overflow-y: auto;
  margin: 0.75rem 0;
}
</style>
