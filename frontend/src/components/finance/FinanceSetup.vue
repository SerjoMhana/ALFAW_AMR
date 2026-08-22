<script setup>
import { computed, onMounted, ref } from 'vue'
import { useApi } from '../../api.js'
import { notifyError, notifySuccess } from '../../notify.js'
import { money } from './money.js'
import { confirmDelete } from '../../confirm.js'
import { pick } from '../../i18n.js'
import { tr } from '../../phrases.js'

const { api } = useApi()

const setup = ref({ categories: [], templates: [], rules: [], methods: [] })
const academicYears = ref([])
const classes = ref([])
const loading = ref(false)
const saving = ref(false)
const openSection = ref('categories')

const newCategory = ref('')
const newMethod = ref('')

const emptyTemplate = () => ({
  id: null,
  name: '',
  fee_category_id: '',
  amount: '',
  academic_year: '',
  grade_levels: [],
  due_date: '',
  is_mandatory: true,
  is_active: true,
})
const emptyRule = () => ({
  id: null,
  name: 'خصم الإخوة',
  type: 'sibling',
  applies_to_categories: [],
  value: null,
  sibling_tiers: [
    { ordinal: 2, percentage: 10 },
    { ordinal: 3, percentage: 15 },
  ],
  is_active: true,
})

const templateForm = ref(emptyTemplate())
const ruleForm = ref(emptyRule())

// Grade names come from the classes that actually exist, so the admin picks
// real grades rather than typing them.
const gradeOptions = computed(() => [
  ...new Set(
    classes.value
      .filter((row) => !templateForm.value.academic_year || row.academic_year === templateForm.value.academic_year)
      .map((row) => row.class_name || row.section_code)
      .filter(Boolean),
  ),
].sort())

const categoryNames = computed(() => setup.value.categories.map((row) => row.name))

onMounted(load)

async function load() {
  loading.value = true

  try {
    const [setupResponse, years, classList] = await Promise.all([
      api('/finance/setup'),
      api('/academic-years'),
      api('/finance/classes'),
    ])
    setup.value = setupResponse.data
    academicYears.value = years.data
    classes.value = classList.data

    if (!templateForm.value.academic_year) {
      templateForm.value.academic_year = academicYears.value.find((year) => year.is_active)?.name
        ?? academicYears.value[0]?.name
        ?? ''
    }
  } catch (err) {
    notifyError(err.message)
  } finally {
    loading.value = false
  }
}

async function call(path, options, successText) {
  saving.value = true

  try {
    await api(path, options)
    notifySuccess(successText)
    await load()

    return true
  } catch (err) {
    notifyError(err.message)

    return false
  } finally {
    saving.value = false
  }
}

async function addCategory() {
  if (!newCategory.value.trim()) return
  const ok = await call('/finance/fee-categories', {
    method: 'POST',
    body: JSON.stringify({ name: newCategory.value.trim() }),
  }, 'تمت إضافة الفئة.')
  if (ok) newCategory.value = ''
}

async function renameCategory(category) {
  const name = window.prompt('اسم الفئة الجديد:', category.name)
  if (!name || name === category.name) return
  await call(`/finance/fee-categories/${category.id}`, {
    method: 'PUT',
    body: JSON.stringify({ name, is_active: category.is_active }),
  }, 'تم تعديل الفئة.')
}

async function removeCategory(category) {
  if (!await confirmDelete(pick(`سيتم حذف فئة «${category.name}».`, `The category "${category.name}" will be deleted.`))) return
  await call(`/finance/fee-categories/${category.id}`, { method: 'DELETE' }, 'تم حذف الفئة.')
}

async function addMethod() {
  if (!newMethod.value.trim()) return
  const ok = await call('/finance/payment-methods', {
    method: 'POST',
    body: JSON.stringify({ name: newMethod.value.trim() }),
  }, 'تمت إضافة طريقة الدفع.')
  if (ok) newMethod.value = ''
}

async function renameMethod(method) {
  const name = window.prompt('اسم طريقة الدفع الجديد:', method.name)
  if (!name || name === method.name) return
  await call(`/finance/payment-methods/${method.id}`, {
    method: 'PUT',
    body: JSON.stringify({ name, is_active: method.is_active }),
  }, 'تم تعديل طريقة الدفع.')
}

async function removeMethod(method) {
  if (!await confirmDelete(pick(`سيتم حذف «${method.name}».`, `"${method.name}" will be deleted.`), { detail: pick('الإيصالات السابقة تحتفظ باسمها ولا تتأثر.', 'Existing receipts keep their method name and are unaffected.') })) return
  await call(`/finance/payment-methods/${method.id}`, { method: 'DELETE' }, 'تم حذف طريقة الدفع.')
}

function toggleGrade(grade) {
  const list = templateForm.value.grade_levels
  templateForm.value.grade_levels = list.includes(grade)
    ? list.filter((item) => item !== grade)
    : [...list, grade]
}

function selectAllGrades() {
  templateForm.value.grade_levels = templateForm.value.grade_levels.length === gradeOptions.value.length
    ? []
    : [...gradeOptions.value]
}

async function saveTemplate() {
  const form = templateForm.value
  const payload = {
    name: form.name,
    fee_category_id: Number(form.fee_category_id),
    amount: Number(form.amount),
    academic_year: form.academic_year,
    grade_levels: form.grade_levels,
    due_date: form.due_date || null,
    is_mandatory: form.is_mandatory,
    is_active: form.is_active,
  }

  const ok = await call(
    form.id ? `/finance/fee-templates/${form.id}` : '/finance/fee-templates',
    { method: form.id ? 'PUT' : 'POST', body: JSON.stringify(payload) },
    form.id ? 'تم تعديل الرسم.' : 'تمت إضافة الرسم.',
  )

  if (ok) templateForm.value = { ...emptyTemplate(), academic_year: form.academic_year }
}

function editTemplate(template) {
  templateForm.value = {
    id: template.id,
    name: template.name,
    fee_category_id: template.fee_category_id,
    amount: template.amount,
    academic_year: template.academic_year,
    grade_levels: template.grade_levels ?? [],
    due_date: template.due_date?.slice(0, 10) ?? '',
    is_mandatory: template.is_mandatory,
    is_active: template.is_active,
  }
  openSection.value = 'templates'
}

async function removeTemplate(template) {
  if (!await confirmDelete(pick(`سيتم حذف قالب «${template.name}».`, `The template "${template.name}" will be deleted.`), { detail: pick('الرسوم المسندة للطلبة لن تتأثر.', 'Fees already raised against students are unaffected.') })) return
  await call(`/finance/fee-templates/${template.id}`, { method: 'DELETE' }, 'تم حذف القالب.')
}

function addTier() {
  const next = (ruleForm.value.sibling_tiers.at(-1)?.ordinal ?? 1) + 1
  ruleForm.value.sibling_tiers.push({ ordinal: next, percentage: 0 })
}

function toggleRuleCategory(name) {
  const list = ruleForm.value.applies_to_categories
  ruleForm.value.applies_to_categories = list.includes(name)
    ? list.filter((item) => item !== name)
    : [...list, name]
}

async function saveRule() {
  const form = ruleForm.value
  const tiers = {}
  form.sibling_tiers.forEach((tier) => {
    if (tier.ordinal) tiers[String(tier.ordinal)] = Number(tier.percentage)
  })

  const ok = await call(
    form.id ? `/finance/discount-rules/${form.id}` : '/finance/discount-rules',
    {
      method: form.id ? 'PUT' : 'POST',
      body: JSON.stringify({
        name: form.name,
        type: form.type,
        applies_to_categories: form.applies_to_categories,
        value: form.type === 'sibling' ? null : Number(form.value || 0),
        sibling_tiers: form.type === 'sibling' ? tiers : null,
        is_active: form.is_active,
      }),
    },
    form.id ? 'تم تعديل قاعدة الخصم.' : 'تمت إضافة قاعدة الخصم.',
  )

  if (ok) ruleForm.value = emptyRule()
}

function editRule(rule) {
  ruleForm.value = {
    id: rule.id,
    name: rule.name,
    type: rule.type,
    applies_to_categories: rule.applies_to_categories ?? [],
    value: rule.value,
    sibling_tiers: Object.entries(rule.sibling_tiers ?? {}).map(([ordinal, percentage]) => ({
      ordinal: Number(ordinal),
      percentage: Number(percentage),
    })),
    is_active: rule.is_active,
  }
  openSection.value = 'rules'
}

async function removeRule(rule) {
  if (!await confirmDelete(pick(`سيتم حذف قاعدة «${rule.name}».`, `The rule "${rule.name}" will be deleted.`), { detail: pick('الخصومات المطبقة سابقاً لن تتأثر.', 'Discounts already applied are unaffected.') })) return
  await call(`/finance/discount-rules/${rule.id}`, { method: 'DELETE' }, 'تم حذف القاعدة.')
}
</script>

<template>
  <div class="workspace">
    <div class="section-tabs">
      <button type="button" :class="{ active: openSection === 'categories' }" @click="openSection = 'categories'">
        {{ tr('الفئات وطرق الدفع') }}
      </button>
      <button type="button" :class="{ active: openSection === 'templates' }" @click="openSection = 'templates'">
        {{ tr('قوالب الرسوم (') }}{{ setup.templates.length }})
      </button>
      <button type="button" :class="{ active: openSection === 'rules' }" @click="openSection = 'rules'">
        {{ tr('قواعد الخصم (') }}{{ setup.rules.length }})
      </button>
    </div>

    <p v-if="loading" class="muted">{{ tr('جاري التحميل...') }}</p>

    <template v-else-if="openSection === 'categories'">
      <article class="form-card">
        <h3>{{ tr('فئات الرسوم') }}</h3>
        <p class="muted">{{ tr('أضف اسم الفئة فقط — مثل كتب، زي مدرسي، نقل — لتختارها عند إنشاء قالب رسم.') }}</p>
        <div class="crud-form">
          <label>{{ tr('اسم الفئة') }}
            <input v-model="newCategory" :placeholder="tr('زي مدرسي')" @keyup.enter="addCategory" />
          </label>
          <button type="button" :disabled="saving || !newCategory.trim()" @click="addCategory">{{ tr('إضافة فئة') }}</button>
        </div>

        <div v-if="setup.categories.length" class="table-wrap">
          <table>
            <thead><tr><th>{{ tr('الفئة') }}</th><th>{{ tr('الحالة') }}</th><th></th></tr></thead>
            <tbody>
              <tr v-for="category in setup.categories" :key="category.id">
                <td>{{ category.name }}</td>
                <td>{{ category.is_active ? tr('مفعّلة') : tr('معطّلة') }}</td>
                <td>
                  <div class="actions">
                    <button type="button" class="secondary compact" @click="renameCategory(category)">{{ tr('تعديل') }}</button>
                    <button type="button" class="danger compact" @click="removeCategory(category)">{{ tr('حذف') }}</button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <p v-else class="muted">{{ tr('لم تُضف أي فئة بعد.') }}</p>
      </article>

      <article class="form-card">
        <h3>{{ tr('طرق الدفع') }}</h3>
        <p class="muted">{{ tr('هذه القائمة تظهر لموظف المالية عند تحصيل الدفعة.') }}</p>
        <div class="crud-form">
          <label>{{ tr('اسم الطريقة') }}
            <input v-model="newMethod" :placeholder="tr('شيك')" @keyup.enter="addMethod" />
          </label>
          <button type="button" :disabled="saving || !newMethod.trim()" @click="addMethod">{{ tr('إضافة طريقة') }}</button>
        </div>

        <div v-if="setup.methods.length" class="table-wrap">
          <table>
            <thead><tr><th>{{ tr('الطريقة') }}</th><th>{{ tr('الحالة') }}</th><th></th></tr></thead>
            <tbody>
              <tr v-for="method in setup.methods" :key="method.id">
                <td>{{ method.name }}</td>
                <td>{{ method.is_active ? tr('مفعّلة') : tr('معطّلة') }}</td>
                <td>
                  <div class="actions">
                    <button type="button" class="secondary compact" @click="renameMethod(method)">{{ tr('تعديل') }}</button>
                    <button type="button" class="danger compact" @click="removeMethod(method)">{{ tr('حذف') }}</button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </article>
    </template>

    <template v-else-if="openSection === 'templates'">
      <article class="form-card">
        <h3>{{ templateForm.id ? tr('تعديل قالب رسم') : tr('إضافة قالب رسم') }}</h3>

        <p v-if="!setup.categories.length" class="notice error">
          {{ tr('أضف فئة واحدة على الأقل من تبويب «الفئات وطرق الدفع» أولاً.') }}
        </p>

        <div class="form-grid">
          <label>{{ tr('اسم الرسم') }} <input v-model="templateForm.name" required /></label>
          <label>{{ tr('الفئة') }}
            <select v-model="templateForm.fee_category_id">
              <option value="">{{ tr('اختر الفئة') }}</option>
              <option v-for="category in setup.categories" :key="category.id" :value="category.id">
                {{ category.name }}
              </option>
            </select>
          </label>
          <label>{{ tr('المبلغ') }} <input v-model="templateForm.amount" type="number" step="0.01" min="0" required /></label>
          <label>{{ tr('السنة الدراسية') }}
            <select v-model="templateForm.academic_year">
              <option v-for="year in academicYears" :key="year.id" :value="year.name">{{ year.name }}</option>
            </select>
          </label>
          <label>{{ tr('تاريخ الاستحقاق') }} <input v-model="templateForm.due_date" type="date" /></label>
          <label class="checkbox-label">
            <input v-model="templateForm.is_mandatory" type="checkbox" /> {{ tr('إلزامي (يُسند تلقائياً)') }}
          </label>
          <label class="checkbox-label"><input v-model="templateForm.is_active" type="checkbox" /> {{ tr('مفعّل') }}</label>
        </div>

        <div class="grade-picker">
          <div class="permission-card-header">
            <p class="muted">
              {{ tr('الصفوف المشمولة — اختر أكثر من صف إذا كان السعر واحداً. اترك الكل بلا تحديد ليشمل كل الصفوف.') }}
            </p>
            <button type="button" class="secondary compact" @click="selectAllGrades">
              {{ templateForm.grade_levels.length === gradeOptions.length ? tr('إلغاء الكل') : tr('تحديد الكل') }}
            </button>
          </div>
          <div class="grade-toggles">
            <label v-for="grade in gradeOptions" :key="grade" class="checkbox-label">
              <input
                type="checkbox"
                :checked="templateForm.grade_levels.includes(grade)"
                @change="toggleGrade(grade)"
              />
              {{ grade }}
            </label>
          </div>
          <p v-if="!gradeOptions.length" class="muted">{{ tr('لا توجد فصول لهذه السنة الدراسية.') }}</p>
        </div>

        <div class="actions">
          <button
            type="button"
            :disabled="saving || !templateForm.name || !templateForm.fee_category_id"
            @click="saveTemplate"
          >
            {{ templateForm.id ? tr('حفظ التعديل') : tr('إضافة') }}
          </button>
          <button v-if="templateForm.id" type="button" class="secondary" @click="templateForm = emptyTemplate()">
            {{ tr('إلغاء') }}
          </button>
        </div>
      </article>

      <div class="table-wrap">
        <table>
          <thead>
            <tr><th>{{ tr('الاسم') }}</th><th>{{ tr('الفئة') }}</th><th>{{ tr('المبلغ') }}</th><th>{{ tr('السنة') }}</th><th>{{ tr('الصفوف') }}</th><th>{{ tr('الاستحقاق') }}</th><th></th></tr>
          </thead>
          <tbody>
            <tr v-for="template in setup.templates" :key="template.id">
              <td>{{ template.name }}</td>
              <td>{{ template.category?.name || '—' }}</td>
              <td>{{ money(template.amount) }}</td>
              <td>{{ template.academic_year }}</td>
              <td>
                <template v-if="(template.grade_levels ?? []).length">
                  <span v-for="grade in template.grade_levels" :key="grade" class="pill pill-muted">{{ grade }}</span>
                </template>
                <span v-else class="muted">{{ tr('كل الصفوف') }}</span>
              </td>
              <td>{{ template.due_date?.slice(0, 10) || '—' }}</td>
              <td>
                <div class="actions">
                  <button type="button" class="secondary compact" @click="editTemplate(template)">{{ tr('تعديل') }}</button>
                  <button type="button" class="danger compact" @click="removeTemplate(template)">{{ tr('حذف') }}</button>
                </div>
              </td>
            </tr>
            <tr v-if="!setup.templates.length"><td colspan="7" class="muted">{{ tr('لا توجد قوالب بعد.') }}</td></tr>
          </tbody>
        </table>
      </div>
    </template>

    <template v-else>
      <article class="form-card">
        <h3>{{ ruleForm.id ? tr('تعديل قاعدة خصم') : tr('إضافة قاعدة خصم') }}</h3>
        <div class="form-grid">
          <label>{{ tr('اسم القاعدة') }} <input v-model="ruleForm.name" required /></label>
          <label>{{ tr('النوع') }}
            <select v-model="ruleForm.type">
              <option value="sibling">{{ tr('خصم الإخوة (تلقائي)') }}</option>
              <option value="percentage">{{ tr('نسبة ثابتة') }}</option>
              <option value="fixed">{{ tr('مبلغ ثابت') }}</option>
            </select>
          </label>
          <label v-if="ruleForm.type !== 'sibling'">
            {{ ruleForm.type === 'percentage' ? tr('النسبة %') : tr('المبلغ') }}
            <input v-model="ruleForm.value" type="number" step="0.01" min="0" />
          </label>
          <label class="checkbox-label"><input v-model="ruleForm.is_active" type="checkbox" /> {{ tr('مفعّلة') }}</label>
        </div>

        <p class="muted">{{ tr('تُطبَّق على الفئات التالية فقط (اتركها فارغة لتشمل كل الفئات):') }}</p>
        <div class="grade-toggles">
          <label v-for="name in categoryNames" :key="name" class="checkbox-label">
            <input
              type="checkbox"
              :checked="ruleForm.applies_to_categories.includes(name)"
              @change="toggleRuleCategory(name)"
            />
            {{ name }}
          </label>
        </div>

        <template v-if="ruleForm.type === 'sibling'">
          <p class="muted">{{ tr('شرائح الإخوة — الابن الأول عادةً بلا خصم:') }}</p>
          <div class="table-wrap">
            <table>
              <thead><tr><th>{{ tr('ترتيب الابن') }}</th><th>{{ tr('نسبة الخصم %') }}</th><th></th></tr></thead>
              <tbody>
                <tr v-for="(tier, index) in ruleForm.sibling_tiers" :key="index">
                  <td><input v-model="tier.ordinal" type="number" min="2" /></td>
                  <td><input v-model="tier.percentage" type="number" step="0.01" min="0" max="100" /></td>
                  <td>
                    <button type="button" class="danger compact" @click="ruleForm.sibling_tiers.splice(index, 1)">
                      {{ tr('حذف') }}
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <div class="actions">
            <button type="button" class="secondary" @click="addTier">{{ tr('إضافة شريحة') }}</button>
          </div>
        </template>

        <div class="actions">
          <button type="button" :disabled="saving || !ruleForm.name" @click="saveRule">
            {{ ruleForm.id ? tr('حفظ التعديل') : tr('إضافة') }}
          </button>
          <button v-if="ruleForm.id" type="button" class="secondary" @click="ruleForm = emptyRule()">{{ tr('إلغاء') }}</button>
        </div>
      </article>

      <div class="table-wrap">
        <table>
          <thead><tr><th>{{ tr('القاعدة') }}</th><th>{{ tr('النوع') }}</th><th>{{ tr('تُطبَّق على') }}</th><th>{{ tr('القيمة') }}</th><th></th></tr></thead>
          <tbody>
            <tr v-for="rule in setup.rules" :key="rule.id">
              <td>{{ rule.name }}</td>
              <td>{{ rule.type === 'sibling' ? tr('إخوة') : rule.type === 'percentage' ? tr('نسبة') : tr('مبلغ') }}</td>
              <td>
                <span v-for="name in (rule.applies_to_categories ?? [])" :key="name" class="pill pill-muted">
                  {{ name }}
                </span>
                <span v-if="!(rule.applies_to_categories ?? []).length" class="muted">{{ tr('كل الفئات') }}</span>
              </td>
              <td>
                <template v-if="rule.type === 'sibling'">
                  <span v-for="(percentage, ordinal) in (rule.sibling_tiers ?? {})" :key="ordinal" class="pill pill-muted">
                    {{ tr('الابن') }} {{ ordinal }}: {{ percentage }}%
                  </span>
                </template>
                <template v-else>{{ rule.value }}</template>
              </td>
              <td>
                <div class="actions">
                  <button type="button" class="secondary compact" @click="editRule(rule)">{{ tr('تعديل') }}</button>
                  <button type="button" class="danger compact" @click="removeRule(rule)">{{ tr('حذف') }}</button>
                </div>
              </td>
            </tr>
            <tr v-if="!setup.rules.length"><td colspan="5" class="muted">{{ tr('لا توجد قواعد خصم بعد.') }}</td></tr>
          </tbody>
        </table>
      </div>
    </template>
  </div>
</template>

<style scoped>
.section-tabs {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.section-tabs button {
  background: var(--app-surface);
  color: var(--app-text);
  border: 1px solid var(--app-border);
}

.section-tabs button.active {
  background: var(--app-accent);
  color: var(--app-surface);
  border-color: var(--app-accent);
}

.grade-picker {
  border: 1px dashed var(--app-border);
  border-radius: 10px;
  padding: 12px;
  display: grid;
  gap: 8px;
}

.grade-toggles {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
}

.pill {
  display: inline-block;
  border-radius: 999px;
  padding: 2px 9px;
  font-size: 11px;
  font-weight: 700;
  margin-inline-end: 4px;
}

.pill-muted { background: var(--app-surface-muted); color: var(--app-text); }
</style>
