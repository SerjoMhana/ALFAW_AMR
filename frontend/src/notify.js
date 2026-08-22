import { toast } from 'vue3-toastify'
import { isArabic, setOnLanguageChange, t } from './i18n.js'

// The toast container keeps whichever side it was built with, so clear the live
// ones when the language flips or the next toast would arrive on the old side.
setOnLanguageChange(() => toast.clearAll())

export const toastDefaults = {
  autoClose: 3500,
  theme: 'colored',
  hideProgressBar: false,
  closeOnClick: true,
  pauseOnHover: true,
}

/**
 * Toasts follow the reading direction: they arrive on the right in Arabic and
 * on the left in English, so they never appear on the side the eye leaves.
 */
export function toastOptions() {
  return {
    rtl: isArabic.value,
    position: isArabic.value ? toast.POSITION.TOP_RIGHT : toast.POSITION.TOP_LEFT,
  }
}

export function notifySuccess(text) {
  if (!text) return
  toast.success(text, toastOptions())
}

export function notifyError(text) {
  toast.error(text || t('unexpectedError'), toastOptions())
}

export function notifyInfo(text) {
  if (!text) return
  toast.info(text, toastOptions())
}

/**
 * Runs an async action and reports the outcome as a toast, returning whether it
 * succeeded so callers can skip follow-up work after a failure.
 */
export async function notifyAround(action, successText) {
  try {
    await action()
    notifySuccess(successText ?? t('savedSuccessfully'))

    return true
  } catch (err) {
    notifyError(err.message)

    return false
  }
}
