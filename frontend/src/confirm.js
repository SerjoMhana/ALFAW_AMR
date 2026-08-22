import { ref } from 'vue'
import { t } from './i18n.js'

/**
 * An in-app replacement for window.confirm.
 *
 * The browser dialog cannot be styled, ignores the app's language and theme,
 * and looks like a phishing prompt in some browsers. This keeps the same
 * await-a-yes-or-no shape so call sites read the same way.
 */
export const dialog = ref(null)

function ask({ title, message, detail, confirmLabel, cancelLabel, danger = false, requireAcknowledgement = false }) {
  return new Promise((resolve) => {
    dialog.value = {
      title: title ?? (danger ? t('confirmDelete') : t('confirmTitle')),
      message,
      detail,
      confirmLabel: confirmLabel ?? (danger ? t('delete') : t('confirm')),
      cancelLabel: cancelLabel ?? t('cancel'),
      danger,
      requireAcknowledgement,
      acknowledged: false,
      resolve,
    }
  })
}

export function confirmAction(message, options = {}) {
  return ask({ message, ...options })
}

/**
 * The destructive variant: red, and worded as a deletion by default.
 */
export function confirmDelete(message, options = {}) {
  return ask({
    message: message ?? t('confirmDeleteDefault'),
    detail: options.detail ?? t('irreversible'),
    danger: true,
    ...options,
  })
}

export function settle(answer) {
  dialog.value?.resolve(answer)
  dialog.value = null
}

export function useConfirm() {
  return { confirmAction, confirmDelete }
}
