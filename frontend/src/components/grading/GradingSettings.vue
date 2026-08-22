<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useApi } from '../../api.js'
import { notifyError, notifySuccess } from '../../notify.js'
import { confirmAction, confirmDelete } from '../../confirm.js'
import { pick } from '../../i18n.js'
import { tr } from '../../phrases.js'

const { api } = useApi()

const tiers = ref([])
const newItemForms = ref({})
const selectedTierId = ref(null)
const settingsTab = ref('schemes')
const gpaScale = ref([])
const creditLegend = ref([])
const savingPoints = ref(false)

const presets = ref([])
const showNewScheme = ref(false)
const schemeForm = ref({ name: '', min_grade: 1, max_grade: 4 })
const newCategoryForms = ref({})
const busy = ref(false)
const saving = ref(false)
const savedSnapshot = ref('')
const deactivating = ref(null)
const allSections = ref([])
const sectionPicks = ref([])

const selectedTier = computed(() => tiers.value.find((tier) => tier.id === selectedTierId.value) ?? null)

// A class carries one scheme, so a class already claimed elsewhere is offered
// with a note saying which scheme currently owns it.
const sectionOptions = computed(() => allSections.value.map((section) => {
  const owner = tiers.value.find((tier) => tier.id === section.grade_tier_id)

  return {
    id: section.id,
    label: section.class_name || section.section_code,
    year: section.academic_year,
    ownedBy: owner && owner.id !== selectedTierId.value ? owner.name : null,
  }
}))

function tierLabel(tier) {
  return `${tier.name} (G${tier.min_grade} - G${tier.max_grade})`
}

// What is left of the 100% before the scheme is fully allocated.
function remainingWeight(tier) {
  return Math.round((100 - tierWeightTotal(tier)) * 100) / 100
}

function categoryMaxTotal(category) {
  return category.items
    .filter((item) => item.is_active && !item.is_total_field)
    .reduce((sum, item) => sum + Number(item.max_score || 0), 0)
}

onMounted(() => {
  loadStructure()
  loadPresets()
  loadSections()
  loadGpaSettings()
})

async function loadSections() {
  try {
    allSections.value = (await api('/course-sections')).data
  } catch (err) {
    notifyError(err.message)
  }
}

// Keep the class picker in step with whichever scheme is open.
watch(selectedTier, (tier) => {
  sectionPicks.value = tier ? (tier.course_sections ?? []).map((section) => section.id) : []
}, { immediate: true })

async function loadStructure() {
  try {
    const response = await api('/grading-structure')
    tiers.value = response.data
  } catch (err) {
    notifyError(err.message)
  }
}

async function loadPresets() {
  try {
    presets.value = (await api('/grading-structure/presets')).data
  } catch (err) {
    notifyError(err.message)
  }
}

async function applyPreset(preset) {
  if (!await confirmAction(pick(`سيتم إنشاء مخطط «${preset.name}» بفئاته وبنوده كاملة.`, `The "${preset.name}" scheme will be created with all its categories and items.`))) return
  busy.value = true

  try {
    await api('/grading-structure/presets', { method: 'POST', body: JSON.stringify({ key: preset.key }) })
    notifySuccess(pick(`تم إنشاء مخطط ${preset.name}.`, `The ${preset.name} scheme was created.`))
    await Promise.all([loadStructure(), loadPresets()])
  } catch (err) {
    notifyError(err.message)
  } finally {
    busy.value = false
  }
}

async function createScheme() {
  if (!schemeForm.value.name.trim()) {
    notifyError(pick('أدخل اسم المخطط.', 'Enter a scheme name.'))
    return
  }

  busy.value = true

  try {
    const response = await api('/grade-tiers', { method: 'POST', body: JSON.stringify(schemeForm.value) })
    notifySuccess(pick('تم إنشاء المخطط. أضف الفئات وأوزانها الآن.', 'Scheme created. Add its categories and weights now.'))
    showNewScheme.value = false
    schemeForm.value = { name: '', min_grade: 1, max_grade: 4 }
    await Promise.all([loadStructure(), loadPresets()])
    selectedTierId.value = response.data.id
  } catch (err) {
    notifyError(err.message)
  } finally {
    busy.value = false
  }
}

/**
 * Edits are held in the page until the admin presses save, so a half-typed
 * weight never reaches the server. The snapshot is what "unchanged" means.
 */
function snapshotOf(tier) {
  return JSON.stringify({
    name: tier.name,
    min_grade: tier.min_grade,
    max_grade: tier.max_grade,
    categories: tier.categories.map((category) => ({
      id: category.id,
      name: category.name,
      weight_percentage: Number(category.weight_percentage),
      items: category.items.map((item) => ({ id: item.id, name: item.name, max_score: Number(item.max_score) })),
    })),
  })
}

function openTier(tier) {
  selectedTierId.value = tier.id
  savedSnapshot.value = snapshotOf(tier)
}

const schemeDirty = computed(() =>
  Boolean(selectedTier.value) && snapshotOf(selectedTier.value) !== savedSnapshot.value,
)

async function saveScheme() {
  const tier = selectedTier.value
  if (!tier) return

  const before = JSON.parse(savedSnapshot.value)
  const categoriesBefore = Object.fromEntries(before.categories.map((row) => [row.id, row]))
  const itemsBefore = Object.fromEntries(
    before.categories.flatMap((row) => row.items.map((item) => [item.id, item])),
  )

  saving.value = true

  try {
    if (tier.name !== before.name || tier.min_grade !== before.min_grade || tier.max_grade !== before.max_grade) {
      await api(`/grade-tiers/${tier.id}`, {
        method: 'PUT',
        body: JSON.stringify({ name: tier.name, min_grade: tier.min_grade, max_grade: tier.max_grade }),
      })
    }

    for (const category of tier.categories) {
      const old = categoriesBefore[category.id]
      if (!old) continue
      if (old.name !== category.name || old.weight_percentage !== Number(category.weight_percentage)) {
        await api(`/grading-categories/${category.id}`, {
          method: 'PUT',
          body: JSON.stringify({ name: category.name, weight_percentage: category.weight_percentage }),
        })
      }

      for (const item of category.items) {
        const oldItem = itemsBefore[item.id]
        if (!oldItem) continue
        if (oldItem.name !== item.name || oldItem.max_score !== Number(item.max_score)) {
          await api(`/grading-items/${item.id}`, {
            method: 'PUT',
            body: JSON.stringify({ name: item.name, max_score: item.max_score }),
          })
        }
      }
    }

    notifySuccess(pick('تم حفظ التغييرات على المخطط.', 'Scheme changes saved.'))
    await Promise.all([loadStructure(), loadPresets()])
    savedSnapshot.value = snapshotOf(selectedTier.value)
  } catch (err) {
    notifyError(err.message)
    await loadStructure()
    if (selectedTier.value) savedSnapshot.value = snapshotOf(selectedTier.value)
  } finally {
    saving.value = false
  }
}

async function activateTier(tier) {
  try {
    await api(`/grade-tiers/${tier.id}/activate`, { method: 'POST' })
    notifySuccess(pick(`تم تفعيل مخطط «${tier.name}». أصبح ساري المفعول على فصوله.`, `The “${tier.name}” scheme is now active for its classes.`))
    await loadStructure()
  } catch (err) {
    notifyError(err.message)
  }
}

/**
 * Switching a scheme off deletes every mark entered under it, so the admin is
 * shown the exact count first and has to tick the box before the call is made.
 */
async function askDeactivate(tier) {
  try {
    const impact = (await api(`/grade-tiers/${tier.id}/deactivation-impact`)).data
    deactivating.value = { tier, impact, agreed: false }
  } catch (err) {
    notifyError(err.message)
  }
}

async function confirmDeactivate() {
  const { tier } = deactivating.value

  try {
    const response = await api(`/grade-tiers/${tier.id}/deactivate`, {
      method: 'POST',
      body: JSON.stringify({ confirm: true }),
    })
    notifySuccess(pick(`تم إلغاء تفعيل المخطط وحذف ${response.deleted_scores} درجة.`, `Scheme switched off and ${response.deleted_scores} marks deleted.`))
    deactivating.value = null
    await loadStructure()
  } catch (err) {
    notifyError(err.message)
  }
}

async function saveSections(tier) {
  try {
    await api(`/grade-tiers/${tier.id}/sections`, {
      method: 'PUT',
      body: JSON.stringify({ course_section_ids: sectionPicks.value }),
    })
    notifySuccess(pick('تم تحديث الفصول التي يعمل بها المخطط.', 'The classes using this scheme were updated.'))
    await loadStructure()
  } catch (err) {
    notifyError(err.message)
    await loadStructure()
  }
}

async function removeTier(tier) {
  if (!await confirmDelete(pick(`سيتم حذف مخطط «${tier.name}» بكل فئاته وبنوده.`, `The "${tier.name}" scheme and all its categories and items will be deleted.`))) return

  try {
    await api(`/grade-tiers/${tier.id}`, { method: 'DELETE' })
    notifySuccess(pick('تم حذف المخطط.', 'Scheme deleted.'))
    selectedTierId.value = null
    await Promise.all([loadStructure(), loadPresets()])
  } catch (err) {
    notifyError(err.message)
  }
}

function newCategoryForm(tierId) {
  if (!newCategoryForms.value[tierId]) {
    newCategoryForms.value[tierId] = { name: '', weight_percentage: '' }
  }
  return newCategoryForms.value[tierId]
}

async function addCategory(tier) {
  const form = newCategoryForm(tier.id)

  if (!form.name.trim() || form.weight_percentage === '') {
    notifyError(pick('أدخل اسم الفئة ووزنها.', 'Enter a category name and weight.'))
    return
  }

  try {
    const response = await api('/grading-categories', {
      method: 'POST',
      body: JSON.stringify({
        grade_tier_id: tier.id,
        name: form.name,
        weight_percentage: form.weight_percentage,
      }),
    })
    newCategoryForms.value[tier.id] = { name: '', weight_percentage: '' }
    notifySuccess(response.warning ?? 'تمت إضافة الفئة.')
    await loadStructure()
  } catch (err) {
    notifyError(err.message)
  }
}

async function removeCategory(category) {
  if (!await confirmDelete(pick(`سيتم حذف فئة «${category.name}» وبنودها.`, `The category "${category.name}" and its items will be deleted.`))) return

  try {
    const response = await api(`/grading-categories/${category.id}`, { method: 'DELETE' })
    notifySuccess(response?.message ?? 'تم حذف الفئة.')
    await loadStructure()
  } catch (err) {
    notifyError(err.message)
  }
}



async function loadGpaSettings() {
  try {
    const response = await api('/gpa-settings')
    gpaScale.value = response.data.scale
    creditLegend.value = response.data.legend
  } catch (err) {
    notifyError(err.message)
  }
}

async function saveGpaSettings() {
  savingPoints.value = true
  try {
    const response = await api('/gpa-settings', {
      method: 'PUT',
      body: JSON.stringify({
        scale: gpaScale.value.map((band) => ({
          letter: band.letter,
          min_score: band.min_score,
          max_score: band.max_score,
          points: band.points,
        })),
        legend: creditLegend.value.map((row) => ({
          classes_per_week: row.classes_per_week,
          credits: row.credits,
        })),
      }),
    })
    gpaScale.value = response.data.scale
    creditLegend.value = response.data.legend
    notifySuccess(pick('تم حفظ إعدادات نظام النقاط بنجاح.', 'Grade point settings saved.'))
  } catch (err) {
    notifyError(err.message)
  } finally {
    savingPoints.value = false
  }
}

function addScaleRow() {
  gpaScale.value.push({ letter: '', min_score: 0, max_score: 0, points: 0 })
}

function removeScaleRow(index) {
  if (gpaScale.value.length === 1) return
  gpaScale.value.splice(index, 1)
}

function addLegendRow() {
  const next = Math.max(0, ...creditLegend.value.map((row) => Number(row.classes_per_week) || 0)) + 1
  creditLegend.value.push({ classes_per_week: next, credits: 0 })
}

function removeLegendRow(index) {
  if (creditLegend.value.length === 1) return
  creditLegend.value.splice(index, 1)
}

function tierWeightTotal(tier) {
  return tier.categories
    .filter((category) => category.is_active)
    .reduce((sum, category) => sum + Number(category.weight_percentage), 0)
}


async function saveItem(item) {
  try {
    await api(`/grading-items/${item.id}`, {
      method: 'PUT',
      body: JSON.stringify({ max_score: item.max_score, is_active: item.is_active }),
    })
    notifySuccess(pick('تم تحديث البند.', 'Item updated.'))
  } catch (err) {
    notifyError(err.message)
    await loadStructure()
  }
}

async function toggleItem(item) {
  item.is_active = !item.is_active
  await saveItem(item)
}

async function removeItem(item) {
  if (!await confirmDelete(pick(`سيتم حذف البند «${item.name}».`, `The item "${item.name}" will be deleted.`))) return

  try {
    await api(`/grading-items/${item.id}`, { method: 'DELETE' })
    notifySuccess(pick('تم حذف البند.', 'Item deleted.'))
    await loadStructure()
  } catch (err) {
    notifyError(err.message)
  }
}

function newItemForm(categoryId) {
  if (!newItemForms.value[categoryId]) {
    newItemForms.value[categoryId] = { name: '', max_score: '' }
  }
  return newItemForms.value[categoryId]
}

async function addItem(category) {
  const form = newItemForm(category.id)
  if (!form.name || !form.max_score) return

  try {
    await api('/grading-items', {
      method: 'POST',
      body: JSON.stringify({
        grading_category_id: category.id,
        name: form.name,
        max_score: form.max_score,
        display_order: category.items.length,
      }),
    })
    newItemForms.value[category.id] = { name: '', max_score: '' }
    notifySuccess(pick('تمت إضافة البند.', 'Item added.'))
    await loadStructure()
  } catch (err) {
    notifyError(err.message)
  }
}
</script>

<template>
  <div class="workspace">

    <div class="actions settings-tabs">
      <button type="button" :class="settingsTab === 'schemes' ? '' : 'secondary'" @click="settingsTab = 'schemes'; selectedTierId = null">
        {{ tr('مخططات نظام الدرجات') }}
      </button>
      <button type="button" :class="settingsTab === 'points' ? '' : 'secondary'" @click="settingsTab = 'points'">
        {{ tr('إعداد نظام النقاط') }}
      </button>
    </div>

    <template v-if="settingsTab === 'schemes' && !selectedTier">
      <article class="panel">
        <div class="permission-card-header">
          <div>
            <h3>{{ tr('مخططات نظام الدرجات') }}</h3>
            <p class="muted">
              {{ tr('المخطط يحدد كيف تُقسَّم الـ 100 درجة على فئات (أعمال الفصل، الاختبارات، النهائي…)، وكل فئة تحتوي بنوداً يُدخل الأستاذ درجاتها. كل صف دراسي يتبع مخططاً واحداً فقط.') }}
            </p>
          </div>
          <button type="button" @click="showNewScheme = !showNewScheme">
            {{ showNewScheme ? tr('إلغاء') : tr('＋ مخطط جديد') }}
          </button>
        </div>

        <form v-if="showNewScheme" class="crud-form scheme-form" @submit.prevent="createScheme">
          <label>{{ tr('اسم المخطط') }}
            <input v-model="schemeForm.name" :placeholder="tr('مثال: G1-4')" required />
          </label>
          <label>{{ tr('من الصف') }}
            <select v-model.number="schemeForm.min_grade">
              <option v-for="n in 12" :key="`min-${n}`" :value="n">G{{ n }}</option>
            </select>
          </label>
          <label>{{ tr('إلى الصف') }}
            <select v-model.number="schemeForm.max_grade">
              <option v-for="n in 12" :key="`max-${n}`" :value="n">G{{ n }}</option>
            </select>
          </label>
          <button type="submit" :disabled="busy">{{ tr('حفظ المخطط') }}</button>
        </form>

        <div class="table-wrap">
          <table>
            <thead><tr><th>{{ tr('اسم المخطط') }}</th><th>{{ tr('الحالة') }}</th><th>{{ tr('الفصول') }}</th><th>{{ tr('الفئات') }}</th><th>{{ tr('مجموع الأوزان') }}</th><th></th></tr></thead>
            <tbody>
              <tr v-for="tier in tiers" :key="tier.id">
                <td><strong>{{ tier.name }}</strong></td>
                <td>
                  <span v-if="tier.is_active" class="badge good">{{ tr('مفعّل') }}</span>
                  <span v-else class="badge">{{ tr('غير مفعّل') }}</span>
                </td>
                <td>
                  <template v-if="tier.course_sections?.length">
                    {{ tier.course_sections.map((section) => section.class_name || section.section_code).join('، ') }}
                  </template>
                  <span v-else class="muted">{{ tr('لم تُحدَّد فصول') }}</span>
                </td>
                <td>{{ tier.categories.length }}</td>
                <td>
                  <span class="badge" :class="{ danger: Math.abs(tierWeightTotal(tier) - 100) > 0.01 }">
                    {{ tierWeightTotal(tier) }}%
                  </span>
                </td>
                <td>
                  <div class="actions">
                    <button type="button" class="secondary compact" @click="openTier(tier)">{{ tr('تعديل التقسيم') }}</button>
                    <button v-if="!tier.is_active" type="button" class="compact" @click="activateTier(tier)">{{ tr('تفعيل المخطط') }}</button>
                    <button v-else type="button" class="danger compact" @click="askDeactivate(tier)">{{ tr('إلغاء التفعيل') }}</button>
                    <button type="button" class="danger compact" @click="removeTier(tier)">{{ tr('حذف') }}</button>
                  </div>
                </td>
              </tr>
              <tr v-if="!tiers.length"><td colspan="6" class="muted">{{ tr('لا توجد مخططات درجات بعد. أنشئ مخططاً أو استخدم أحد المخططات الجاهزة أدناه.') }}</td></tr>
            </tbody>
          </table>
        </div>
      </article>

      <article v-if="presets.length" class="panel">
        <div class="permission-card-header">
          <div>
            <h3>{{ tr('مخططات جاهزة — القسم الأمريكي') }}</h3>
            <p class="muted">{{ tr('تقسيم الدرجات المعتمد في المدرسة، يُنشأ بضغطة واحدة بفئاته وبنوده وأوزانه.') }}</p>
          </div>
        </div>

        <div class="preset-grid">
          <article v-for="preset in presets" :key="preset.key" class="preset-card">
            <div class="preset-head">
              <strong>{{ preset.name }}</strong>
              <small>G{{ preset.min_grade }} — G{{ preset.max_grade }}</small>
            </div>
            <p class="muted">{{ preset.label }}</p>
            <ul class="preset-list">
              <li v-for="category in preset.categories" :key="category.name">
                <span>{{ category.name }}</span>
                <span class="badge">{{ category.weight_percentage }}%</span>
              </li>
            </ul>
            <p v-if="preset.conflict" class="muted conflict">
              {{ tr('يتقاطع مع مخطط «') }}{{ preset.conflict }}{{ tr('» الموجود — احذفه أو عدّل نطاقه أولاً.') }}
            </p>
            <button v-else type="button" class="secondary" :disabled="busy" @click="applyPreset(preset)">
              {{ tr('إنشاء هذا المخطط') }}
            </button>
          </article>
        </div>
      </article>
    </template>

    <template v-if="settingsTab === 'schemes' && selectedTier">
      <article class="panel">
        <div class="permission-card-header">
          <h3>{{ tierLabel(selectedTier) }} {{ tr('— تقسيم الدرجات') }}</h3>
          <button type="button" class="secondary compact" @click="selectedTierId = null">{{ tr('← العودة للمخططات') }}</button>
        </div>

        <div class="crud-form scheme-form">
          <label>{{ tr('اسم المخطط') }}
            <input v-model="selectedTier.name" />
          </label>
          <label>{{ tr('من الصف') }}
            <select v-model.number="selectedTier.min_grade">
              <option v-for="n in 12" :key="`emin-${n}`" :value="n">G{{ n }}</option>
            </select>
          </label>
          <label>{{ tr('إلى الصف') }}
            <select v-model.number="selectedTier.max_grade">
              <option v-for="n in 12" :key="`emax-${n}`" :value="n">G{{ n }}</option>
            </select>
          </label>
        </div>

        <div class="save-bar">
          <button type="button" :disabled="!schemeDirty || saving" @click="saveScheme">
            {{ saving ? tr('جاري الحفظ...') : tr('💾 حفظ التغييرات') }}
          </button>
          <span v-if="schemeDirty" class="muted">{{ tr('لديك تعديلات غير محفوظة.') }}</span>
          <span v-else class="muted">{{ tr('كل التغييرات محفوظة.') }}</span>
        </div>

        <p v-if="!selectedTier.is_active" class="notice warn">
          {{ tr('هذا المخطط') }} <strong>{{ tr('غير مفعّل') }}</strong> {{ tr('ولا يُعمل به بعد. حدد فصوله ثم اضغط «تفعيل المخطط».') }}
        </p>

        <p class="notice" :class="Math.abs(tierWeightTotal(selectedTier) - 100) > 0.01 ? 'warn' : 'success'">
          {{ tr('مجموع الأوزان') }} <strong>{{ tierWeightTotal(selectedTier) }}%</strong>
          <template v-if="remainingWeight(selectedTier) > 0"> {{ tr('— متبقٍ') }} {{ remainingWeight(selectedTier) }}{{ tr('% لتكتمل المئة.') }}</template>
          <template v-else-if="remainingWeight(selectedTier) < 0"> {{ tr('— تجاوزت المئة بـ') }} {{ -remainingWeight(selectedTier) }}%.</template>
          <template v-else> {{ tr('— المخطط مكتمل.') }}</template>
        </p>
      </article>

      <article v-for="category in selectedTier.categories" :key="category.id" class="panel category-card" :class="{ retired: !category.is_active }">
        <div class="permission-card-header">
          <label class="grow">{{ tr('اسم الفئة') }}
            <input v-model="category.name" />
          </label>
          <label>{{ tr('الوزن %') }}
            <input v-model.number="category.weight_percentage" type="number" min="0" max="100" step="0.5" />
          </label>
          <button type="button" class="danger compact" @click="removeCategory(category)">{{ tr('حذف الفئة') }}</button>
        </div>

        <p class="muted">
          {{ tr('مجموع درجات بنود هذه الفئة:') }} <strong>{{ categoryMaxTotal(category) }}</strong>
          {{ tr('— يُحوَّل إلى نسبة ثم يُضرب في وزن الفئة (') }}{{ category.weight_percentage }}%).
          <template v-if="!category.is_active"> {{ tr('· هذه الفئة معطّلة ولا تدخل في الحساب.') }}</template>
        </p>

        <div class="table-wrap">
          <table>
            <thead><tr><th>{{ tr('البند') }}</th><th>{{ tr('الدرجة القصوى') }}</th><th>{{ tr('الحالة') }}</th><th></th></tr></thead>
            <tbody>
              <tr v-for="item in category.items" :key="item.id">
                <td><input v-model="item.name" /></td>
                <td>
                  <input
                    v-model.number="item.max_score"
                    type="number"
                    min="0"
                    step="0.5"
                    style="width: 100px"
                    :disabled="item.is_total_field"
                  />
                </td>
                <td>
                  <button type="button" class="secondary compact" @click="toggleItem(item)">
                    {{ item.is_active ? tr('مفعّل') : tr('معطّل') }}
                  </button>
                </td>
                <td><button type="button" class="danger compact" @click="removeItem(item)">{{ tr('حذف') }}</button></td>
              </tr>
              <tr>
                <td><input v-model="newItemForm(category.id).name" :placeholder="tr('اسم بند جديد')" /></td>
                <td><input v-model.number="newItemForm(category.id).max_score" type="number" min="0" step="0.5" style="width: 100px" :placeholder="tr('من')" /></td>
                <td></td>
                <td><button type="button" class="secondary compact" @click="addItem(category)">{{ tr('＋ إضافة بند') }}</button></td>
              </tr>
            </tbody>
          </table>
        </div>
      </article>

      <article class="panel">
        <div class="permission-card-header">
          <div>
            <h3>{{ tr('الفصول التي تعمل بهذا المخطط') }}</h3>
            <p class="muted">
              {{ tr('اختر الفصول التي تُحتسب درجاتها بهذا التقسيم. الفصل الواحد يتبع مخططاً واحداً فقط، وأي فصل تختاره هنا يُنقل إليه من مخططه السابق.') }}
            </p>
          </div>
          <button type="button" class="secondary compact" @click="saveSections(selectedTier)">{{ tr('حفظ الفصول') }}</button>
        </div>

        <p v-if="!sectionOptions.length" class="muted">{{ tr('لا توجد فصول مسجلة بعد. أنشئ الفصول أولاً من قسم الفصول.') }}</p>

        <div v-else class="section-picker">
          <label v-for="section in sectionOptions" :key="section.id" class="section-option">
            <input v-model="sectionPicks" type="checkbox" :value="section.id" />
            <span>
              <strong>{{ section.label }}</strong>
              <small>{{ section.year }}<template v-if="section.ownedBy"> {{ tr('· حالياً ضمن «') }}{{ section.ownedBy }}»</template></small>
            </span>
          </label>
        </div>
      </article>

      <article class="panel">
        <h3>{{ tr('إضافة فئة جديدة') }}</h3>
        <p class="muted">
          {{ tr('مثال: «الاختبارات القصيرة» بوزن 10% ثم أضف تحتها Quiz 1 إلى Quiz 4.') }}
          <template v-if="remainingWeight(selectedTier) > 0">{{ tr('المتاح الآن') }} {{ remainingWeight(selectedTier) }}%.</template>
        </p>
        <form class="crud-form" @submit.prevent="addCategory(selectedTier)">
          <label>{{ tr('اسم الفئة') }} <input v-model="newCategoryForm(selectedTier.id).name" :placeholder="tr('مثال: Quizzes')" /></label>
          <label>{{ tr('الوزن %') }}
            <input v-model.number="newCategoryForm(selectedTier.id).weight_percentage" type="number" min="0" max="100" step="0.5" />
          </label>
          <button type="submit">{{ tr('＋ إضافة الفئة') }}</button>
        </form>
      </article>
    </template>

    <div v-if="deactivating" class="modal-backdrop" @click.self="deactivating = null">
      <article class="student-modal danger-modal">
        <h3>{{ tr('⚠ إلغاء تفعيل مخطط «') }}{{ deactivating.impact.scheme }}»</h3>

        <p class="notice error">
          {{ tr('الدرجات المُدخلة لا معنى لها خارج التقسيم الذي أُدخلت عليه، لذلك سيؤدي إلغاء التفعيل إلى') }}
          <strong>{{ tr('حذف جميع الدرجات المسجّلة على هذا المخطط نهائياً') }}</strong>{{ tr('. لا يمكن التراجع عن هذا الإجراء.') }}
        </p>

        <div class="stats-grid">
          <article class="stat-card stat-rose">
            <span>{{ tr('درجات ستُحذف') }}</span><strong>{{ deactivating.impact.scores }}</strong>
          </article>
          <article class="stat-card stat-blue">
            <span>{{ tr('طلبة متأثرون') }}</span><strong>{{ deactivating.impact.students }}</strong>
          </article>
        </div>

        <p v-if="deactivating.impact.classes.length" class="muted">
          {{ tr('الفصول المتأثرة:') }} {{ deactivating.impact.classes.join('، ') }}
        </p>

        <label class="checkbox-label agree">
          <input v-model="deactivating.agreed" type="checkbox" />
          {{ tr('أفهم أن') }} {{ deactivating.impact.scores }} {{ tr('درجة ستُحذف نهائياً وأوافق على المتابعة.') }}
        </label>

        <div class="actions">
          <button type="button" class="danger" :disabled="!deactivating.agreed" @click="confirmDeactivate">
            {{ tr('إلغاء التفعيل وحذف الدرجات') }}
          </button>
          <button type="button" class="secondary" @click="deactivating = null">{{ tr('تراجع') }}</button>
        </div>
      </article>
    </div>

    <template v-if="settingsTab === 'points'">
      <article class="panel">
        <div class="permission-card-header">
          <h3>{{ tr('Legend of Credits Earned — جدول النقاط المكتسبة') }}</h3>
          <button type="button" class="secondary compact" @click="addLegendRow">{{ tr('+ إضافة صف') }}</button>
        </div>
        <p class="muted">
          {{ tr('Credits Earned = ساعات السنة ÷ 120 · ساعات السنة = عدد الحصص أسبوعياً × عدد الأسابيع (30) × 40 دقيقة. آخر صف في الجدول يُطبَّق على أي عدد حصص أكبر منه (≥).') }}
        </p>
        <div class="table-wrap">
          <table>
            <thead><tr><th>{{ tr('عدد الحصص في الأسبوع (40 دقيقة)') }}</th><th>Credits Earned</th><th></th></tr></thead>
            <tbody>
              <tr v-for="(row, index) in creditLegend" :key="`legend-${index}`">
                <td><input v-model.number="row.classes_per_week" type="number" min="1" max="40" style="width: 110px" /></td>
                <td><input v-model.number="row.credits" type="number" min="0" step="0.1" style="width: 110px" /></td>
                <td><button v-if="creditLegend.length > 1" type="button" class="danger compact" @click="removeLegendRow(index)">{{ tr('حذف') }}</button></td>
              </tr>
            </tbody>
          </table>
        </div>
      </article>

      <article class="panel">
        <div class="permission-card-header">
          <h3>{{ tr('Grading / GPA Scale — سلم تقدير الدرجات') }}</h3>
          <button type="button" class="secondary compact" @click="addScaleRow">{{ tr('+ إضافة تقدير') }}</button>
        </div>
        <p class="muted">{{ tr('Course GPA يُحدد من هذا السلم حسب الدرجة النهائية للمادة. عدّل أعلى وأصغر درجة والنقاط لكل تقدير.') }}</p>
        <div class="table-wrap">
          <table>
            <thead><tr><th>{{ tr('التقدير') }}</th><th>{{ tr('أصغر درجة') }}</th><th>{{ tr('أعلى درجة') }}</th><th>{{ tr('النقاط (GPA)') }}</th><th></th></tr></thead>
            <tbody>
              <tr v-for="(band, index) in gpaScale" :key="`band-${index}`">
                <td><input v-model="band.letter" style="width: 70px" /></td>
                <td><input v-model.number="band.min_score" type="number" min="0" max="100" step="0.5" style="width: 90px" /></td>
                <td><input v-model.number="band.max_score" type="number" min="0" max="100" step="0.5" style="width: 90px" /></td>
                <td><input v-model.number="band.points" type="number" min="0" max="10" step="0.1" style="width: 90px" /></td>
                <td><button v-if="gpaScale.length > 1" type="button" class="danger compact" @click="removeScaleRow(index)">{{ tr('حذف') }}</button></td>
              </tr>
            </tbody>
          </table>
        </div>
      </article>

      <div class="actions">
        <button type="button" :disabled="savingPoints" @click="saveGpaSettings">
          {{ savingPoints ? tr('جاري الحفظ...') : tr('حفظ إعدادات نظام النقاط') }}
        </button>
      </div>
    </template>
  </div>
</template>

<style scoped>
.badge.danger {
  background: var(--app-danger-soft);
  color: var(--app-danger);
}

.scheme-form label input,
.scheme-form label select { min-width: 130px; }

.permission-card-header label.grow { flex: 1; min-width: 200px; }
.permission-card-header label input[type='number'] { width: 100px; }

.category-card.retired { opacity: 0.65; }

.preset-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
  gap: 12px;
}

.preset-card {
  display: grid;
  gap: 8px;
  align-content: start;
  border: 1px solid var(--app-border);
  border-radius: 10px;
  background: var(--app-surface);
  padding: 14px;
}

.preset-head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 8px;
}

.preset-head small { color: var(--app-text-muted); }

.preset-list {
  display: grid;
  gap: 4px;
  margin: 0;
  padding: 0;
  list-style: none;
  font-size: 13px;
}

.preset-list li {
  display: flex;
  justify-content: space-between;
  gap: 8px;
  border-bottom: 1px dashed var(--app-border);
  padding-bottom: 3px;
}

.conflict { color: var(--app-warn-text); }

.badge.good { background: var(--app-good-soft); color: var(--app-good-text); }

.save-bar {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
}

.notice.warn {
  background: var(--app-warn-soft);
  border: 1px solid var(--app-warn-border);
  color: var(--app-warn-text);
}

.notice.error {
  background: var(--app-danger-soft);
  border: 1px solid var(--app-danger);
  color: var(--app-danger-text);
}

.section-picker {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(210px, 1fr));
  gap: 8px;
}

.section-option {
  display: flex;
  align-items: center;
  gap: 8px;
  border: 1px solid var(--app-border);
  border-radius: 8px;
  padding: 8px 10px;
  cursor: pointer;
}

.section-option:hover { background: var(--app-surface-muted); }
.section-option span { display: grid; gap: 1px; min-width: 0; }
.section-option small { color: var(--app-text-muted); font-size: 11px; }

.danger-modal { width: min(560px, 100%); }
.danger-modal .agree { margin: 4px 0; font-weight: 700; }
</style>
