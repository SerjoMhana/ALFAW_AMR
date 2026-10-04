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
const secondaryLogoUrl = ref(null)
const thirdLogoUrl = ref(null)
const loading = ref(true)
const saving = ref(false)
const uploading = ref(false)
const previewTab = ref('quarter')
const logoInput = ref(null)
const secondaryLogoInput = ref(null)
const thirdLogoInput = ref(null)

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
    secondaryLogoUrl.value = response.secondary_logo_url
    thirdLogoUrl.value = response.third_logo_url
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
    const { logo_path: _ignored, secondary_logo_path: _secondaryIgnored, third_logo_path: _thirdIgnored, ...payload } = template.value

    const response = await api('/report-card-template', {
      method: 'PUT',
      body: JSON.stringify(payload),
    })
    template.value = response.data
    logoUrl.value = response.logo_url
    secondaryLogoUrl.value = response.secondary_logo_url
    thirdLogoUrl.value = response.third_logo_url
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
    secondaryLogoUrl.value = response.secondary_logo_url
    thirdLogoUrl.value = response.third_logo_url
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

async function uploadSecondaryLogo(event) {
  const file = event.target.files?.[0]
  if (!file) return
  const body = new FormData()
  body.append('logo', file)
  uploading.value = true

  try {
    const response = await fetch(`${apiBaseUrl}/report-card-template/secondary-logo`, {
      method: 'POST', credentials: 'include', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...csrfHeader() }, body,
    })
    const data = await response.json().catch(() => ({}))
    if (!response.ok) throw new Error(data.message ?? pick('تعذّر رفع الشعار الثاني.', 'The secondary logo could not be uploaded.'))
    template.value = data.data
    secondaryLogoUrl.value = data.secondary_logo_url ? `${data.secondary_logo_url}?v=${Date.now()}` : null
    savedSnapshot.value = JSON.stringify(data.data)
    notifySuccess(pick('تم رفع الشعار الثاني.', 'Secondary logo uploaded.'))
  } catch (err) {
    notifyError(err.message)
  } finally {
    uploading.value = false
    if (secondaryLogoInput.value) secondaryLogoInput.value.value = ''
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

async function removeSecondaryLogo() {
  const ok = await confirmAction(pick('سيُحذف الشعار الثاني من التقارير.', 'The secondary logo will be removed from reports.'))
  if (!ok) return
  try {
    const response = await api('/report-card-template/secondary-logo', { method: 'DELETE' })
    template.value = response.data
    secondaryLogoUrl.value = null
    savedSnapshot.value = JSON.stringify(response.data)
    notifySuccess(pick('تم حذف الشعار الثاني.', 'Secondary logo removed.'))
  } catch (err) {
    notifyError(err.message)
  }
}

async function uploadThirdLogo(event) {
  const file = event.target.files?.[0]
  if (!file) return
  const body = new FormData()
  body.append('logo', file)
  uploading.value = true
  try {
    const response = await fetch(`${apiBaseUrl}/report-card-template/third-logo`, {
      method: 'POST', credentials: 'include', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...csrfHeader() }, body,
    })
    const data = await response.json().catch(() => ({}))
    if (!response.ok) throw new Error(data.message ?? tr('تعذّر رفع الشعار الثالث.'))
    template.value = data.data
    thirdLogoUrl.value = data.third_logo_url ? `${data.third_logo_url}?v=${Date.now()}` : null
    savedSnapshot.value = JSON.stringify(data.data)
  } catch (err) { notifyError(err.message) } finally {
    uploading.value = false
    if (thirdLogoInput.value) thirdLogoInput.value.value = ''
  }
}

async function removeThirdLogo() {
  if (!await confirmAction(tr('سيُحذف الشعار الثالث من التقارير.'))) return
  try {
    const response = await api('/report-card-template/third-logo', { method: 'DELETE' })
    template.value = response.data
    thirdLogoUrl.value = null
    savedSnapshot.value = JSON.stringify(response.data)
  } catch (err) { notifyError(err.message) }
}

// ---- the drawing ----------------------------------------------------------
// Stand-in rows so the sheet looks like a real one while being edited.
const sampleSubjects = [
  { name: 'English', grade: '89.4', letter: 'B+', credit: '1' },
  { name: 'Mathematics', grade: '95.0', letter: 'A', credit: '1' },
  { name: 'Science', grade: '78.5', letter: 'C+', credit: '1' },
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
            <h3>{{ tr('هوية المدرسة والشعارات') }}</h3>
            <div class="crud-form">
              <label class="grow">{{ tr('اسم المدرسة') }} <input v-model="template.school_name" /></label>
              <label class="grow">{{ tr('عنوان المدرسة') }} <input v-model="template.school_address" /></label>
              <label>{{ tr('هاتف المدرسة') }} <input v-model="template.school_phone" /></label>
              <label>{{ tr('اسم المدير') }} <input v-model="template.principal_name" /></label>
              <label class="checkbox-label">
                <input v-model="template.show_logo" type="checkbox" /> {{ tr('إظهار الشعار الأساسي') }}
              </label>
              <label>{{ tr('عرض الشعار الأساسي') }}
                <input v-model.number="template.logo_width" type="number" min="30" max="300" />
              </label>
              <label>{{ tr('الاسم تحت الشعار الأساسي') }} <input v-model="template.logo_label" /></label>
              <label class="checkbox-label">
                <input v-model="template.show_secondary_logo" type="checkbox" /> {{ tr('إظهار الشعار الثاني') }}
              </label>
              <label>{{ tr('عرض الشعار الثاني') }}
                <input v-model.number="template.secondary_logo_width" type="number" min="30" max="300" />
              </label>
              <label>{{ tr('الاسم تحت الشعار الثاني') }} <input v-model="template.secondary_logo_label" /></label>
              <label class="checkbox-label"><input v-model="template.show_third_logo" type="checkbox" /> {{ tr('إظهار الشعار الثالث') }}</label>
              <label>{{ tr('عرض الشعار الثالث') }} <input v-model.number="template.third_logo_width" type="number" min="30" max="300" /></label>
              <label>{{ tr('الاسم تحت الشعار الثالث') }} <input v-model="template.third_logo_label" /></label>
            </div>
            <div class="actions">
              <input ref="logoInput" type="file" accept="image/png,image/jpeg" :disabled="uploading" @change="uploadLogo" />
              <button type="button" class="danger compact" @click="removeLogo">{{ tr('حذف الشعار الأساسي') }}</button>
            </div>
            <div class="actions secondary-logo-actions">
              <input ref="secondaryLogoInput" type="file" accept="image/png,image/jpeg" :disabled="uploading" @change="uploadSecondaryLogo" />
              <button type="button" class="danger compact" @click="removeSecondaryLogo">{{ tr('حذف الشعار الثاني') }}</button>
            </div>
            <div class="actions secondary-logo-actions">
              <input ref="thirdLogoInput" type="file" accept="image/png,image/jpeg" :disabled="uploading" @change="uploadThirdLogo" />
              <button type="button" class="danger compact" @click="removeThirdLogo">{{ tr('حذف الشعار الثالث') }}</button>
            </div>
          </article>

          <article class="panel">
            <h3>{{ tr('العناوين') }}</h3>
            <div class="crud-form">
              <label class="grow">{{ tr('عنوان كشف الكوارتر') }} <input v-model="template.quarter_title" /></label>
              <label class="grow">{{ tr('عنوان كشف السيمستر') }} <input v-model="template.semester_title" /></label>
              <label class="grow">{{ tr('عنوان التقرير النهائي') }} <input v-model="template.final_title" /></label>
            </div>
            <p class="muted">{{ tr('اكتب {semester} في عنوان السيمستر ليُستبدل برقمه.') }}</p>
          </article>

          <article class="panel">
            <h3>{{ tr('مسميات بيانات الطالب') }}</h3>
            <div class="crud-form">
              <label>{{ tr('رقم الطالب') }} <input v-model="template.label_student_number" /></label>
              <label>{{ tr('اسم الطالب') }} <input v-model="template.label_student_name" /></label>
              <label>{{ tr('رقم الجلوس') }} <input v-model="template.label_roll_number" /></label>
              <label>{{ tr('رقم التسجيل') }} <input v-model="template.label_registration_number" /></label>
              <label>{{ tr('ولي الأمر') }} <input v-model="template.label_guardian" /></label>
              <label>{{ tr('الصف') }} <input v-model="template.label_grade" /></label>
              <label>{{ tr('التاريخ') }} <input v-model="template.label_date" /></label>
              <label>{{ tr('المدرسة') }} <input v-model="template.label_school" /></label>
              <label>{{ tr('المدير') }} <input v-model="template.label_principal" /></label>
              <label>{{ tr('المعلم') }} <input v-model="template.label_teacher" /></label>
              <label>{{ tr('مجموع أيام الغياب') }} <input v-model="template.label_absence_total" /></label>
              <label class="checkbox-label"><input v-model="template.show_roll_number" type="checkbox" /> {{ tr('إظهار رقم الجلوس') }}</label>
              <label class="checkbox-label"><input v-model="template.show_registration_number" type="checkbox" /> {{ tr('إظهار رقم التسجيل') }}</label>
              <label class="checkbox-label"><input v-model="template.show_guardian" type="checkbox" /> {{ tr('إظهار ولي الأمر') }}</label>
              <label class="checkbox-label">
                <input v-model="template.show_report_date" type="checkbox" /> {{ tr('إظهار تاريخ التقرير') }}
              </label>
              <label class="checkbox-label">
                <input v-model="template.show_absence_total" type="checkbox" /> {{ tr('إظهار مجموع أيام الغياب') }}
              </label>
            </div>
          </article>

          <article class="panel">
            <h3>{{ tr('أعمدة جدول الدرجات') }}</h3>
            <div class="crud-form">
              <label>{{ tr('عمود المادة') }} <input v-model="template.column_subject" /></label>
              <label>{{ tr('عمود الدرجة') }} <textarea v-model="template.column_marks" rows="2"></textarea></label>
              <label>{{ tr('عمود التقدير') }} <textarea v-model="template.column_letter" rows="2"></textarea></label>
              <label>{{ tr('عمود الساعات المعتمدة') }} <input v-model="template.column_credit" /></label>
              <label class="checkbox-label">
                <input v-model="template.show_letter_grade" type="checkbox" /> {{ tr('إظهار عمود التقدير') }}
              </label>
              <label>{{ tr('عمود الدرجة النهائية') }} <input v-model="template.column_final_grade" /></label>
              <label>{{ tr('الكورتر الأول') }} <input v-model="template.column_quarter_1" /></label>
              <label>{{ tr('الكورتر الثاني') }} <input v-model="template.column_quarter_2" /></label>
              <label>{{ tr('الكورتر الثالث') }} <input v-model="template.column_quarter_3" /></label>
              <label>{{ tr('الكورتر الرابع') }} <input v-model="template.column_quarter_4" /></label>
              <label>{{ tr('السيمستر الأول') }} <input v-model="template.column_semester_1" /></label>
              <label>{{ tr('السيمستر الثاني') }} <input v-model="template.column_semester_2" /></label>
            </div>
            <p class="muted">{{ tr('اضغط Enter داخل العمود لتقسيم العنوان على سطرين.') }}</p>
          </article>

          <article class="panel">
            <h3>{{ tr('الملخص ومفتاح الدرجات والملاحظات') }}</h3>
            <div class="crud-form">
              <label class="checkbox-label"><input v-model="template.show_gpa" type="checkbox" /> {{ tr('إظهار GPA') }}</label>
              <label>{{ tr('مسمى GPA') }} <input v-model="template.label_gpa" /></label>
              <label class="checkbox-label"><input v-model="template.show_quarter_summary" type="checkbox" /> {{ tr('إظهار المجموع والحالة') }}</label>
              <label>{{ tr('مسمى المجموع') }} <input v-model="template.label_grand_total" /></label>
              <label>{{ tr('مسمى الحالة') }} <input v-model="template.label_status" /></label>
              <label>{{ tr('نص النجاح') }} <input v-model="template.label_pass" /></label>
              <label>{{ tr('نص الرسوب') }} <input v-model="template.label_fail" /></label>
              <label class="checkbox-label"><input v-model="template.show_grading_key" type="checkbox" /> {{ tr('إظهار مفتاح الدرجات') }}</label>
              <label>{{ tr('عنوان مفتاح الدرجات') }} <input v-model="template.grading_key_title" /></label>
              <label class="grow">{{ tr('نص مفتاح الدرجات') }} <textarea v-model="template.grading_key_text" rows="3"></textarea></label>
              <label class="checkbox-label"><input v-model="template.show_teacher_remarks" type="checkbox" /> {{ tr('إظهار ملاحظات المعلم') }}</label>
              <label>{{ tr('عنوان ملاحظات المعلم') }} <input v-model="template.teacher_remarks_label" /></label>
              <label class="grow">{{ tr('نص ملاحظات المعلم') }} <textarea v-model="template.teacher_remarks_text" rows="3"></textarea></label>
            </div>
          </article>

          <article class="panel">
            <h3>{{ tr('التوقيع والتذييل') }}</h3>
            <div class="crud-form">
              <label class="checkbox-label">
                <input v-model="template.show_signature" type="checkbox" /> {{ tr('إظهار التوقيع') }}
              </label>
              <label>{{ tr('اسم صاحب التوقيع الأول') }} <input v-model="template.signer_one" /></label>
              <label>{{ tr('اسم صاحب التوقيع الثاني') }} <input v-model="template.signer_two" /></label>
              <label>{{ tr('اسم صاحب التوقيع الثالث') }} <input v-model="template.signer_three" /></label>
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
            <button type="button" :class="previewTab === 'final' ? '' : 'secondary'" @click="previewTab = 'final'">
              {{ tr('التقرير النهائي') }}
            </button>
          </div>

          <div
            class="sheet"
            :style="{ color: template.text_color, fontSize: `${template.font_size}px`, borderColor: template.border_color }"
          >
            <div class="sheet-identity three-logos">
              <div class="sheet-logo side-logo">
                <img v-if="template.show_logo && logoUrl" :src="logoUrl" alt="" :style="{ width: `${template.logo_width}px` }" />
                <small>{{ template.logo_label }}</small>
              </div>
              <div class="sheet-logo side-logo">
                <img v-if="template.show_secondary_logo && secondaryLogoUrl" :src="secondaryLogoUrl" alt="" :style="{ width: `${template.secondary_logo_width}px` }" />
                <small>{{ template.secondary_logo_label }}</small>
              </div>
              <div class="sheet-logo side-logo">
                <img v-if="template.show_third_logo && thirdLogoUrl" :src="thirdLogoUrl" alt="" :style="{ width: `${template.third_logo_width}px` }" />
                <small>{{ template.third_logo_label }}</small>
              </div>
            </div>
            <div class="sheet-title" :style="{ fontSize: `${template.font_size}px` }">
              <template v-if="previewTab === 'quarter'">{{ template.quarter_title }} — Quarter 1</template>
              <template v-else-if="previewTab === 'semester'">{{ semesterTitle }}</template>
              <template v-else>{{ template.final_title }}</template>
            </div>
            <div class="sheet-school-line">{{ template.school_name }}<small v-if="template.school_address">{{ template.school_address }}</small></div>

            <table class="sheet-info" :style="{ borderColor: template.border_color }">
              <tbody>
                <tr>
                  <td class="lbl" :style="{ background: template.label_bg, borderColor: template.border_color }">{{ template.label_student_name }}</td>
                  <td :style="{ borderColor: template.border_color }">BAHAR AHMADYAR</td>
                  <td class="lbl" :style="{ background: template.label_bg, borderColor: template.border_color }">{{ template.label_grade }}</td>
                  <td :style="{ borderColor: template.border_color }">G12</td>
                </tr>
                <tr v-if="template.show_roll_number || template.show_registration_number">
                  <template v-if="template.show_roll_number"><td class="lbl">{{ template.label_roll_number }}</td><td>12</td></template>
                  <template v-else><td colspan="2"></td></template>
                  <template v-if="template.show_registration_number"><td class="lbl">{{ template.label_registration_number }}</td><td>9174987</td></template>
                  <template v-else><td colspan="2"></td></template>
                </tr>
                <tr v-if="template.show_guardian">
                  <td class="lbl">{{ template.label_guardian }}</td><td>Yaqoob Khan</td>
                  <td class="lbl">{{ template.label_teacher }}</td><td>Ahmed Ali</td>
                </tr>
                <tr v-if="template.show_report_date">
                  <td class="lbl" :style="{ background: template.label_bg, borderColor: template.border_color }">{{ template.label_date }}</td>
                  <td :style="{ borderColor: template.border_color }">01/06/2026</td>
                  <template v-if="template.show_absence_total"><td class="lbl">{{ template.label_absence_total }}</td><td>3</td></template>
                  <template v-else><td colspan="2"></td></template>
                </tr>
              </tbody>
            </table>

            <table v-if="previewTab === 'quarter'" class="sheet-marks">
              <thead>
                <tr :style="{ background: template.header_bg }">
                  <th class="start" :style="{ borderColor: template.border_color }">
                    <span v-for="(line, i) in lines(template.column_subject)" :key="i" class="line">{{ line }}</span>
                  </th>
                  <th :style="{ borderColor: template.border_color }">
                    <span v-for="(line, i) in lines(template.column_marks)" :key="i" class="line">{{ line }}</span>
                  </th>
                  <th :style="{ borderColor: template.border_color }">{{ template.column_credit }}</th>
                  <th v-if="template.show_letter_grade" :style="{ borderColor: template.border_color }">
                    <span v-for="(line, i) in lines(template.column_letter)" :key="i" class="line">{{ line }}</span>
                  </th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="subject in sampleSubjects" :key="subject.name">
                  <td :style="{ borderColor: template.border_color }">{{ subject.name }}</td>
                  <td class="num" :style="{ borderColor: template.border_color }">{{ subject.grade }}</td>
                  <td class="num" :style="{ borderColor: template.border_color }">{{ subject.credit }}</td>
                  <td v-if="template.show_letter_grade" class="num" :style="{ borderColor: template.border_color }">{{ subject.letter }}</td>
                </tr>
                <tr v-if="template.show_quarter_summary" class="preview-gpa"><td>{{ template.label_grand_total }}</td><td :colspan="template.show_letter_grade ? 3 : 2">263 / 300 (87.7%)</td></tr>
                <tr v-if="template.show_quarter_summary" class="preview-gpa"><td>{{ template.label_status }}</td><td :colspan="template.show_letter_grade ? 3 : 2">{{ template.label_pass }}</td></tr>
                <tr v-if="template.show_gpa" class="preview-gpa"><td>{{ template.label_gpa }}</td><td :colspan="template.show_letter_grade ? 3 : 2">3.42</td></tr>
              </tbody>
            </table>

            <table v-else class="sheet-marks">
              <thead><tr :style="{ background: template.header_bg }"><th class="start" :style="{ borderColor: template.border_color }">{{ template.column_subject }}</th><th :style="{ borderColor: template.border_color }">{{ previewTab === 'final' ? template.column_semester_1 : template.column_quarter_1 }}</th><th :style="{ borderColor: template.border_color }">{{ previewTab === 'final' ? template.column_semester_2 : template.column_quarter_2 }}</th><th :style="{ borderColor: template.border_color }">{{ template.column_final_grade }}</th><th :style="{ borderColor: template.border_color }">{{ template.column_credit }}</th></tr></thead>
              <tbody><tr v-for="subject in sampleSubjects" :key="`semester-${subject.name}`"><td :style="{ borderColor: template.border_color }">{{ subject.name }}</td><td class="num" :style="{ borderColor: template.border_color }">{{ subject.grade }}</td><td class="num" :style="{ borderColor: template.border_color }">91.0</td><td class="num" :style="{ borderColor: template.border_color }">90.2 ({{ subject.letter }})</td><td class="num" :style="{ borderColor: template.border_color }">{{ subject.credit }}</td></tr><tr v-if="template.show_quarter_summary" class="preview-gpa"><td>{{ template.label_grand_total }}</td><td colspan="4">263 / 300 (87.7%)</td></tr><tr v-if="template.show_quarter_summary" class="preview-gpa"><td>{{ template.label_status }}</td><td colspan="4">{{ template.label_pass }}</td></tr><tr v-if="template.show_gpa" class="preview-gpa"><td>{{ template.label_gpa }}</td><td colspan="4">3.42</td></tr></tbody>
            </table>

            <div v-if="template.show_grading_key && template.grading_key_text" class="sheet-grading-key">
              <strong>{{ template.grading_key_title }}</strong>
              <span>{{ template.grading_key_text }}</span>
            </div>

            <div v-if="template.show_teacher_remarks" class="sheet-remarks">
              <strong>{{ template.teacher_remarks_label }}</strong>
              <span>{{ template.teacher_remarks_text }}</span>
            </div>

            <div v-if="template.show_signature" class="sheet-signature">
              <div>{{ template.signer_one }}</div>
              <div>{{ template.signer_two }}</div>
              <div>{{ template.signer_three }}</div>
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
  border: 2px solid #98a2b3;
  border-radius: 0;
  padding: 30px 34px 52px;
  box-shadow: 0 12px 28px rgb(16 24 40 / 8%);
  font-family: Georgia, 'Times New Roman', serif;
  direction: ltr;
  text-align: left;
  overflow-x: auto;
}

.sheet-identity { display: grid; grid-template-columns: repeat(3, 1fr); align-items: center; text-align: center; margin-bottom: 5px; }
.sheet-logo { text-align: center; }
.side-logo { min-height: 58px; display: grid; place-items: center; align-content: end; gap: 3px; }
.sheet-logo img { max-width: 100%; }
.sheet-logo small { min-height: 1.2em; font-weight: 700; }
.sheet-school-line { text-align: center; font-weight: 800; font-size: 1.65em; margin: 4px 0 18px; }
.sheet-school-line small { display: block; font-family: Arial, sans-serif; font-size: 0.4em; font-weight: 400; margin-top: 4px; }
.sheet-title { text-align: center; font-style: italic; margin-bottom: 7px; }

.sheet-info { border-collapse: collapse; width: 100%; margin-bottom: 18px; }
.sheet-info td { border: 0; padding: 5px 8px; }
.sheet-info td.lbl { font-weight: 700; width: 20%; }

.sheet-marks { border-collapse: collapse; width: 100%; }
.sheet-marks th,
.sheet-marks td { border: 1px solid #c7d2e0; padding: 6px 8px; text-align: center; }
.sheet-marks th { background: #f3f6f9; }
.sheet-marks tbody tr:nth-child(even) { background: #fbfcfd; }
.sheet-marks th.start,
.sheet-marks td:first-child { text-align: left; }
.sheet-marks .line { display: block; }
.preview-gpa td { font-weight: 700; background: #f3f4f6; text-align: center !important; }

.sheet-grading-key { border: 1px solid #c7d2e0; margin-top: 10px; font-size: 0.68em; text-align: center; }
.sheet-grading-key strong { display: block; padding: 4px; background: #f3f6f9; border-bottom: 1px solid #c7d2e0; }
.sheet-grading-key span { display: block; padding: 5px 7px; line-height: 1.45; }
.sheet-remarks { display: grid; gap: 4px; margin-top: 12px; font-size: 0.76em; line-height: 1.5; }

.sheet-signature { display: grid; grid-template-columns: repeat(3, 1fr); gap: 22px; margin-top: 46px; font-size: 0.8em; font-weight: 700; text-align: center; }
.sheet-signature > div { border-top: 1px solid #667085; padding-top: 5px; }
.sig-name { font-weight: 700; }
.sig-role { color: #475467; }
.sig-label { border-top: 1px solid; display: inline-block; padding-top: 5px; font-weight: 700; }

.sheet-footer { margin-top: 18px; font-size: 0.85em; color: #475467; white-space: pre-wrap; }

.preview-note { font-size: 12px; }
.secondary-logo-actions { margin-top: 10px; padding-top: 10px; border-top: 1px solid var(--app-border); }
</style>
