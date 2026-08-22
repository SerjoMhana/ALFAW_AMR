<script setup>
import { ref } from 'vue'
import { useApi } from '../../api.js'

const { api, apiUpload } = useApi()

const fileInput = ref(null)
const fileName = ref('')
const preview = ref(null)
const loading = ref(false)
const confirming = ref(false)
const message = ref('')
const error = ref('')

function openPicker() {
  fileInput.value?.click()
}

async function onFileChange(event) {
  const file = event.target.files?.[0]
  if (!file) return

  fileName.value = file.name
  message.value = ''
  error.value = ''
  loading.value = true
  preview.value = null

  try {
    const formData = new FormData()
    formData.append('file', file)
    const response = await apiUpload('/grading-structure/import/preview', formData)
    preview.value = response.data
  } catch (err) {
    error.value = err.message
  } finally {
    loading.value = false
  }
}

function tierWeightTotal(tier) {
  return tier.categories.reduce((sum, category) => sum + Number(category.weight_percentage), 0)
}

function removeItem(category, itemIndex) {
  category.items.splice(itemIndex, 1)
}

function removeCategory(tier, categoryIndex) {
  tier.categories.splice(categoryIndex, 1)
}

async function confirmImport() {
  if (!preview.value) return

  message.value = ''
  error.value = ''
  confirming.value = true

  try {
    await api('/grading-structure/import/confirm', {
      method: 'POST',
      body: JSON.stringify({ structure: preview.value }),
    })
    message.value = 'Grading structure imported successfully.'
    preview.value = null
    fileName.value = ''
  } catch (err) {
    error.value = err.message
  } finally {
    confirming.value = false
  }
}
</script>

<template>
  <div class="workspace">
    <article class="excel-upload-card" role="button" tabindex="0" @click="openPicker" @keydown.enter="openPicker">
      <input ref="fileInput" class="hidden-file-input" type="file" accept=".xlsx,.xls" @change="onFileChange" />
      <div class="excel-upload-icon">XLS</div>
      <div>
        <h3>Upload Grading Structure Excel</h3>
        <p class="muted">{{ fileName || 'Click to select the grading system workbook (one sheet per grade tier).' }}</p>
      </div>
    </article>

    <p v-if="loading" class="muted">Parsing workbook...</p>
    <p v-if="message" class="notice success">{{ message }}</p>
    <p v-if="error" class="notice error">{{ error }}</p>

    <article v-for="(tier, tierIndex) in preview" :key="tierIndex" class="panel">
      <div class="permission-card-header">
        <div class="form-grid" style="flex: 1">
          <label>Tier Name<input v-model="tier.tier_name" /></label>
          <label>Min Grade<input v-model.number="tier.min_grade" type="number" min="1" /></label>
          <label>Max Grade<input v-model.number="tier.max_grade" type="number" min="1" /></label>
        </div>
        <span class="badge" :class="{ danger: Math.abs(tierWeightTotal(tier) - 100) > 0.01 }">
          Total weight: {{ tierWeightTotal(tier) }}%
        </span>
      </div>

      <div v-for="(category, categoryIndex) in tier.categories" :key="categoryIndex" class="form-card">
        <div class="permission-card-header">
          <label>Category Name<input v-model="category.name" /></label>
          <label>Weight %<input v-model.number="category.weight_percentage" type="number" min="0" max="100" step="0.5" style="width: 90px" /></label>
          <button type="button" class="danger compact" @click="removeCategory(tier, categoryIndex)">Remove category</button>
        </div>
        <div class="table-wrap">
          <table>
            <thead><tr><th>Item Name</th><th>Max Score</th><th>Computed Total</th><th></th></tr></thead>
            <tbody>
              <tr v-for="(item, itemIndex) in category.items" :key="itemIndex">
                <td><input v-model="item.name" /></td>
                <td><input v-model.number="item.max_score" type="number" min="0" step="0.5" style="width: 90px" /></td>
                <td>
                  <label class="checkbox-label">
                    <input v-model="item.is_total_field" type="checkbox" />
                  </label>
                </td>
                <td><button type="button" class="danger compact" @click="removeItem(category, itemIndex)">Remove</button></td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </article>

    <button v-if="preview" type="button" :disabled="confirming" @click="confirmImport">
      {{ confirming ? 'Importing...' : 'Confirm Import' }}
    </button>
  </div>
</template>

<style scoped>
.badge.danger {
  background: var(--app-danger-soft);
  color: var(--app-danger);
}
</style>
