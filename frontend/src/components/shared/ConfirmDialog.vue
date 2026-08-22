<script setup>
import { computed, onMounted, onUnmounted } from 'vue'
import { dialog, settle } from '../../confirm.js'
import { t } from '../../i18n.js'

/**
 * Mounted once in the shell. Every confirmAction/confirmDelete call anywhere in
 * the app renders here, so there is exactly one dialog to style and translate.
 */
const blocked = computed(() => Boolean(
  dialog.value?.requireAcknowledgement && !dialog.value.acknowledged,
))

function onKey(event) {
  if (!dialog.value) return

  if (event.key === 'Escape') settle(false)
  if (event.key === 'Enter' && !blocked.value) settle(true)
}

onMounted(() => window.addEventListener('keydown', onKey))
onUnmounted(() => window.removeEventListener('keydown', onKey))
</script>

<template>
  <Teleport to="body">
    <div v-if="dialog" class="confirm-backdrop" @click.self="settle(false)">
      <article class="confirm-card" :class="{ danger: dialog.danger }" role="alertdialog" aria-modal="true">
        <h3>
          <span aria-hidden="true">{{ dialog.danger ? '⚠' : '❔' }}</span>
          {{ dialog.title }}
        </h3>

        <p class="confirm-message">{{ dialog.message }}</p>
        <p v-if="dialog.detail" class="confirm-detail">{{ dialog.detail }}</p>

        <label v-if="dialog.requireAcknowledgement" class="confirm-ack">
          <input v-model="dialog.acknowledged" type="checkbox" />
          <span>{{ t('irreversible') }}</span>
        </label>

        <div class="confirm-actions">
          <button
            type="button"
            :class="dialog.danger ? 'danger-solid' : ''"
            :disabled="blocked"
            @click="settle(true)"
          >
            {{ dialog.confirmLabel }}
          </button>
          <button type="button" class="secondary" @click="settle(false)">
            {{ dialog.cancelLabel }}
          </button>
        </div>
      </article>
    </div>
  </Teleport>
</template>

<style scoped>
.confirm-backdrop {
  position: fixed;
  inset: 0;
  z-index: 9999;
  display: grid;
  place-items: center;
  padding: 20px;
  background: rgb(16 24 40 / 55%);
  backdrop-filter: blur(2px);
}

.confirm-card {
  width: min(460px, 100%);
  display: grid;
  gap: 12px;
  border-radius: 14px;
  border: 1px solid var(--app-border);
  background: var(--app-surface);
  color: var(--app-text);
  padding: 22px;
  box-shadow: 0 20px 45px rgb(16 24 40 / 25%);
}

.confirm-card h3 {
  display: flex;
  align-items: center;
  gap: 8px;
  margin: 0;
  font-size: 17px;
  font-weight: 800;
}

.confirm-card.danger h3 { color: var(--app-danger-text); }

.confirm-message {
  margin: 0;
  line-height: 1.7;
  font-size: 14px;
}

.confirm-detail {
  margin: 0;
  font-size: 13px;
  color: var(--app-text-muted);
  /* A detail may list what is about to be deleted, one item per line. */
  white-space: pre-line;
  max-height: 40vh;
  overflow-y: auto;
}

.confirm-ack {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 13px;
  font-weight: 700;
}

.confirm-actions {
  display: flex;
  gap: 10px;
  margin-top: 4px;
}

.confirm-actions button { flex: 0 0 auto; }

/* A filled red button, distinct from the app's outlined `.danger`. */
.confirm-actions .danger-solid {
  background: var(--app-danger);
  /* Literal white: the surface token is dark in dark mode. */
  color: #fff;
}

.confirm-actions .danger-solid:hover:not(:disabled) { background: var(--app-danger-strong); }
</style>
