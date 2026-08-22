<script setup>
import { computed, onMounted, ref } from 'vue'
import { useApi } from '../../api.js'
import { notifyError, notifySuccess } from '../../notify.js'
import { confirmAction } from '../../confirm.js'
import { pick } from '../../i18n.js'
import { tr } from '../../phrases.js'

/**
 * The report card's own wording and look, edited beside a drawing of the sheet
 * so the admin sees the result before printing anything.
 */
const { api, apiBaseUrl } = useApi()

const template = ref(null)
const defaults = ref({})
const logoUrl = ref(null)
const loading = ref(true)
const saving = ref(false)
const uploading = ref(false)
const previewTab = ref('quarter')
const logoInput = ref(null)

// The saved state, so the page can tell whether anything is pending.
const savedSnapshot = ref('')
const dirty = computed(() =>
  Boolean(template.value) && JSON.stringify(template.value) !== savedSnapshot.value,
)

onMounted(load)

async function load() {
  loading.value = true

  try {
    const response = await api('/report-card-template')
    template.value = response.data
    defaults.value = response.defaults
    logoUrl.value = response.logo_url
    savedSnapshot.value = JSON.stringify(response.data)
  } catch (err) {
    notifyError(err.message)
  } finally {
    loading.value = false
  }
}

async function save() {
  saving.value = true

  try {
    // logo_path is owned by the upload endpoint, not the form.
    const { logo_path: _ignored, ...payload } = template.value

    const response = await api('/report-card-template', {
      method: 'PUT',
      body: JSON.stringify(payload),
    })
    template.value = response.data
    logoUrl.value = response.logo_url
    savedSnapshot.value = JSON.stringify(response.data)
    notifySuccess(pick('تم حفظ تصميم كشف الدرجات.', 'Report card design saved.'))
  } catch (err) {
    notifyError(err.message)
  } finally {
    saving.value = false
  }
}

async function resetAll() {
  const ok = await confirmAction(pick(
    'سيعود تصميم كشف الدرجات إلى الشكل الافتراضي، ويُحذف الشعار المرفوع.',
    'The report card design returns to its default, and the uploaded logo is removed.',
  ))
  if (!ok) return

  try {
    const response = await api('/report-card-template/reset', { method: 'POST' })
    template.value = response.data
    logoUrl.value = response.logo_url
    savedSnapshot.value = JSON.stringify(response.data)
    notifySuccess(pick('تمت استعادة التصميم الافتراضي.', 'The default design was restored.'))
  } catch (err) {
    notifyError(err.message)
  }
}

async function uploadLogo(event) {
  const file = event.target.files?.[0]
  if (!file) return

  const body = new FormData()
  body.append('logo', file)
  uploading.value = true

  try {
    // Sent as multipart, so this one call bypasses the JSON helper.
    const response = await fetch(`${apiBaseUrl}/report-card-template/logo`, {
      method: 'POST',
      credentials: 'include',
      headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...csrfHeader(),
      },
      body,
    })

    const data = await response.json().catch(() => ({}))
    if (!response.ok) throw new Error(data.message ?? pick('تعذّر رفع الشعار.', 'The logo could not be uploaded.'))

    template.value = data.data
    // Bust the cache so the replaced file is actually re-fetched.
    logoUrl.value = `${data.logo_url}?v=${Date.now()}`
    savedSnapshot.value = JSON.stringify(data.data)
    notifySuccess(pick('تم رفع الشعار.', 'Logo uploaded.'))
  } catch (err) {
    notifyError(err.message)
  } finally {
    uploading.value = false
    if (logoInput.value) logoInput.value.value = ''
  }
}

function csrfHeader() {
  const match = document.cookie.match(/(^|; )XSRF-TOKEN=([^;]*)/)
  return match ? { 'X-XSRF-TOKEN': decodeURIComponent(match[2]) } : {}
}

async function removeLogo() {
  const ok = await confirmAction(pick(
    'سيُحذف الشعار المرفوع ويعود الشعار الافتراضي.',
    'The uploaded logo is removed and the bundled one returns.',
  ))
  if (!ok) return

  try {
    const response = await api('/report-card-template/logo', { method: 'DELETE' })
    template.value = response.data
    logoUrl.value = response.logo_url ? `${response.logo_url}?v=${Date.now()}` : null
    savedSnapshot.value = JSON.stringify(response.data)
    notifySuccess(pick('تم حذف الشعار.', 'Logo removed.'))
  } catch (err) {
    notifyError(err.message)
  }
}

// ---- the drawing ----------------------------------------------------------
// Stand-in rows so the sheet looks like a real one while being edited.
const sampleSubjects = [
  { name: 'English', grade: '89.4', letter: 'B+' },
  { name: 'Mathematics', grade: '95.0', letter: 'A' },
  { name: 'Science', grade: '78.5', letter: 'C+' },
]

const semesterTitle = computed(() =>
  (template.value?.semester_title ?? '').replace('{semester}', '1'),
)

function lines(text) {
  return String(text ?? '').split('\n')
}
</script>

<template>
  <div class="workspace">
    <p v-if="loading" class="muted">{{ tr('جاري التحميل...') }}</p>

    <template v-else-if="template">
      <article class="panel">
        <div class="permission-card-header">
          <div>
            <h3>{{ tr('تصميم كشف الدرجات') }}</h3>
            <p class="muted">{{ tr('غيّر الشعار والاسم والعناوين والمسميات والألوان — وستراها في المعاينة فوراً قبل الطباعة.') }}</p>
          </div>
          <div class="actions">
            <button type="button" :disabled="!dirty || saving" @click="save">
              {{ saving ? tr('جاري الحفظ...') : tr('حفظ التصميم') }}
            </button>
            <button type="button" class="danger compact" @click="resetAll">{{ tr('استعادة الافتراضي') }}</button>
          </div>
        </div>
        <p class="muted">{{ dirty ? tr('لديك تعديلات غير محفوظة.') : tr('كل التغييرات محفوظة.') }}</p>
      </article>

      <div class="designer">
        <!-- ------------------------------ the form ------------------------------ -->
        <div class="designer-form">
          <article class="panel">
            <h3>{{ tr('الشعار واسم المدرسة') }}</h3>
            <div class="crud-form">
              <label class="grow">{{ tr('اسم المدرسة') }} <input v-model="template.school_name" /></label>
              <label class="checkbox-label">
                <input v-model="template.show_logo" type="checkbox" /> {{ tr('إظهار الشعار') }}
              </label>
              <label>{{ tr('عرض الشعار (بكسل)') }}
                <input v-model.number="template.logo_width" type="number" min="30" max="300" />
              </label>
            </div>
            <div class="actions">
              <input ref="logoInput" type="file" accept="image/png,image/jpeg" :disabled="uploading" @change="uploadLogo" />
              <button type="button" class="danger compact" @click="removeLogo">{{ tr('حذف الشعار') }}</button>
            </div>
          </article>

          <article class="panel">
            <h3>{{ tr('العناوين') }}</h3>
            <div class="crud-form">
              <label class="grow">{{ tr('عنوان كشف الكوارتر') }} <input v-model="template.quarter_title" /></label>
              <label class="grow">{{ tr('عنوان كشف السيمستر') }} <input v-model="template.semester_title" /></label>
            </div>
            <p class="muted">{{ tr('اكتب {semester} في عنوان السيمستر ليُستبدل برقمه.') }}</p>
          </article>

          <article class="panel">
            <h3>{{ tr('مسميات بيانات الطالب') }}</h3>
            <div class="crud-form">
              <label>{{ tr('رقم الطالب') }} <input v-model="template.label_student_number" /></label>
              <label>{{ tr('اسم الطالب') }} <input v-model="template.label_student_name" /></label>
              <label>{{ tr('الصف') }} <input v-model="template.label_grade" /></label>
              <label>{{ tr('التاريخ') }} <input v-model="template.label_date" /></label>
              <label>{{ tr('المدرسة') }} <input v-model="template.label_school" /></label>
              <label>{{ tr('المدير') }} <input v-model="template.label_principal" /></label>
              <label class="checkbox-label">
                <input v-model="template.show_report_date" type="checkbox" /> {{ tr('إظهار تاريخ التقرير') }}
              </label>
            </div>
          </article>

          <article class="panel">
            <h3>{{ tr('أعمدة جدول الدرجات') }}</h3>
            <div class="crud-form">
              <label>{{ tr('عمود المادة') }} <input v-model="template.column_subject" /></label>
              <label>{{ tr('عمود الدرجة') }} <textarea v-model="template.column_marks" rows="2"></textarea></label>
              <label>{{ tr('عمود التقدير') }} <textarea v-model="template.column_letter" rows="2"></textarea></label>
              <label class="checkbox-label">
                <input v-model="template.show_letter_grade" type="checkbox" /> {{ tr('إظهار عمود التقدير') }}
              </label>
            </div>
            <p class="muted">{{ tr('اضغط Enter داخل العمود لتقسيم العنوان على سطرين.') }}</p>
          </article>

          <article class="panel">
            <h3>{{ tr('التوقيع والتذييل') }}</h3>
            <div class="crud-form">
              <label class="checkbox-label">
                <input v-model="template.show_signature" type="checkbox" /> {{ tr('إظهار التوقيع') }}
              </label>
              <label>{{ tr('سطر التوقيع') }} <input v-model="template.signature_name" /></label>
              <label class="grow">{{ tr('صفة الموقّع') }} <input v-model="template.signature_role" /></label>
              <label class="grow">{{ tr('مسمى توقيع السيمستر') }} <input v-model="template.signature_label" /></label>
              <label class="grow">{{ tr('ملاحظة أسفل الكشف') }}
                <textarea v-model="template.footer_note" rows="2"></textarea>
              </label>
            </div>
          </article>

          <article class="panel">
            <h3>{{ tr('الألوان والخط') }}</h3>
            <div class="crud-form colour-form">
              <label>{{ tr('اللون الأساسي') }} <input v-model="template.accent_color" type="color" /></label>
              <label>{{ tr('خلفية رأس الجدول') }} <input v-model="template.header_bg" type="color" /></label>
              <label>{{ tr('خلفية المسميات') }} <input v-model="template.label_bg" type="color" /></label>
              <label>{{ tr('لون الإطار') }} <input v-model="template.border_color" type="color" /></label>
              <label>{{ tr('لون النص') }} <input v-model="template.text_color" type="color" /></label>
              <label>{{ tr('حجم الخط') }} <input v-model.number="template.font_size" type="number" min="8" max="20" /></label>
            </div>
          </article>
        </div>

        <!-- ---------------------------- the preview ---------------------------- -->
        <div class="designer-preview">
          <div class="preview-tabs">
            <button type="button" :class="previewTab === 'quarter' ? '' : 'secondary'" @click="previewTab = 'quarter'">
              {{ tr('كشف الكوارتر') }}
            </button>
            <button type="button" :class="previewTab === 'semester' ? '' : 'secondary'" @click="previewTab = 'semester'">
              {{ tr('كشف السيمستر') }}
            </button>
          </div>

          <div
            class="sheet"
            :style="{ color: template.text_color, fontSize: `${template.font_size}px` }"
          >
            <div v-if="template.show_logo && logoUrl" class="sheet-logo">
              <img :src="logoUrl" alt="" :style="{ width: `${template.logo_width}px` }" />
            </div>

            <div class="sheet-school" :style="{ color: template.accent_color, fontSize: `${template.font_size + 8}px` }">
              {{ template.school_name }}
            </div>

            <div class="sheet-title" :style="{ fontSize: `${template.font_size + 2}px` }">
              <template v-if="previewTab === 'quarter'">{{ template.quarter_title }} — Quarter 1</template>
              <template v-else>{{ semesterTitle }}</template>
            </div>

            <table class="sheet-info" :style="{ borderColor: template.border_color }">
              <tbody>
                <tr>
                  <td class="lbl" :style="{ background: template.label_bg, borderColor: template.border_color }">{{ template.label_student_number }}</td>
                  <td :style="{ borderColor: template.border_color }">9174987</td>
                </tr>
                <tr>
                  <td class="lbl" :style="{ background: template.label_bg, borderColor: template.border_color }">{{ template.label_student_name }}</td>
                  <td :style="{ borderColor: template.border_color }">BAHAR AHMADYAR</td>
                </tr>
                <tr>
                  <td class="lbl" :style="{ background: template.label_bg, borderColor: template.border_color }">{{ template.label_grade }}</td>
                  <td :style="{ borderColor: template.border_color }">G12</td>
                </tr>
                <tr v-if="template.show_report_date">
                  <td class="lbl" :style="{ background: template.label_bg, borderColor: template.border_color }">{{ template.label_date }}</td>
                  <td :style="{ borderColor: template.border_color }">01/06/2026</td>
                </tr>
                <tr v-if="previewTab === 'semester'">
                  <td class="lbl" :style="{ background: template.label_bg, borderColor: template.border_color }">{{ template.label_school }}</td>
                  <td :style="{ borderColor: template.border_color }">{{ template.school_name }}</td>
                </tr>
              </tbody>
            </table>

            <table class="sheet-marks">
              <thead>
                <tr :style="{ background: template.header_bg }">
                  <th class="start" :style="{ borderColor: template.border_color }">
                    <span v-for="(line, i) in lines(template.column_subject)" :key="i" class="line">{{ line }}</span>
                  </th>
                  <th :style="{ borderColor: template.border_color }">
                    <span v-for="(line, i) in lines(template.column_marks)" :key="i" class="line">{{ line }}</span>
                  </th>
                  <th v-if="template.show_letter_grade" :style="{ borderColor: template.border_color }">
                    <span v-for="(line, i) in lines(template.column_letter)" :key="i" class="line">{{ line }}</span>
                  </th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="subject in sampleSubjects" :key="subject.name">
                  <td :style="{ borderColor: template.border_color }">{{ subject.name }}</td>
                  <td class="num" :style="{ borderColor: template.border_color }">{{ subject.grade }}</td>
                  <td v-if="template.show_letter_grade" class="num" :style="{ borderColor: template.border_color }">{{ subject.letter }}</td>
                </tr>
              </tbody>
            </table>

            <div v-if="template.show_signature" class="sheet-signature">
              <template v-if="previewTab === 'quarter'">
                <div class="sig-name">{{ template.signature_name }}</div>
                <div class="sig-role">{{ template.signature_role }}</div>
              </template>
              <div v-else class="sig-label" :style="{ borderColor: template.border_color }">{{ template.signature_label }}</div>
            </div>

            <div v-if="template.footer_note" class="sheet-footer">{{ template.footer_note }}</div>
          </div>

          <p class="muted preview-note">{{ tr('هذه معاينة تقريبية ببيانات تجريبية — الطباعة الفعلية تأخذ بيانات الطالب.') }}</p>
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped>
.designer {
  display: grid;
  grid-template-columns: minmax(320px, 1fr) minmax(340px, 1fr);
  gap: 16px;
  align-items: start;
}

@media (max-width: 1100px) {
  .designer { grid-template-columns: 1fr; }
}

.designer-form { display: grid; gap: 14px; }

/* The preview follows as you scroll the (much taller) form. */
.designer-preview { position: sticky; top: 12px; display: grid; gap: 8px; }

.preview-tabs { display: flex; gap: 8px; }

.colour-form input[type='color'] {
  width: 56px;
  height: 34px;
  padding: 2px;
  cursor: pointer;
}

/* A paper-like stand-in for the printed sheet. */
.sheet {
  background: #fff;
  border: 1px solid #e4e7ec;
  border-radius: 10px;
  padding: 24px 26px;
  box-shadow: 0 12px 28px rgb(16 24 40 / 8%);
  direction: ltr;
  text-align: left;
  overflow-x: auto;
}

.sheet-logo { text-align: center; margin-bottom: 8px; }
.sheet-logo img { max-width: 100%; }
.sheet-school { text-align: center; font-weight: 800; margin-bottom: 4px; }
.sheet-title { text-align: center; font-weight: 700; margin-bottom: 18px; }

.sheet-info { border-collapse: collapse; width: 75%; margin-bottom: 16px; }
.sheet-info td { border: 1px solid; padding: 5px 8px; }
.sheet-info td.lbl { font-weight: 700; width: 45%; }

.sheet-marks { border-collapse: collapse; width: 100%; }
.sheet-marks th,
.sheet-marks td { border: 1px solid; padding: 6px 8px; text-align: center; }
.sheet-marks th.start,
.sheet-marks td:first-child { text-align: left; }
.sheet-marks .line { display: block; }

.sheet-signature { margin-top: 34px; }
.sig-name { font-weight: 700; }
.sig-role { color: #475467; }
.sig-label { border-top: 1px solid; display: inline-block; padding-top: 5px; font-weight: 700; }

.sheet-footer { margin-top: 18px; font-size: 0.85em; color: #475467; white-space: pre-wrap; }

.preview-note { font-size: 12px; }
</style>
