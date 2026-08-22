import { beforeEach, describe, expect, it } from 'vitest'
import { confirmAction, confirmDelete, dialog, settle } from './confirm.js'
import { setLanguage } from './i18n.js'

/**
 * The in-app replacement for window.confirm. Its whole job is to be awaited and
 * to answer truthfully, so that is what these check.
 */
describe('confirm dialog', () => {
  beforeEach(() => {
    dialog.value = null
    setLanguage('ar')
  })

  it('shows a dialog and resolves true when accepted', async () => {
    const answer = confirmAction('هل تريد المتابعة؟')

    expect(dialog.value).not.toBeNull()
    expect(dialog.value.message).toBe('هل تريد المتابعة؟')

    settle(true)
    await expect(answer).resolves.toBe(true)
    expect(dialog.value).toBeNull()
  })

  it('resolves false when dismissed', async () => {
    const answer = confirmAction('هل تريد المتابعة؟')
    settle(false)

    await expect(answer).resolves.toBe(false)
  })

  // A delete has to look different from an ordinary confirmation.
  it('marks a deletion as destructive and warns it cannot be undone', async () => {
    const answer = confirmDelete('سيتم حذف الطالب.')

    expect(dialog.value.danger).toBe(true)
    expect(dialog.value.detail).toBeTruthy()

    settle(false)
    await answer
  })

  it('falls back to a default question when none is given', async () => {
    const answer = confirmDelete()

    expect(dialog.value.message).toBeTruthy()

    settle(false)
    await answer
  })

  it('labels itself in the active language', async () => {
    setLanguage('en')
    const answer = confirmDelete('The student will be deleted.')

    expect(dialog.value.title).toBe('Confirm deletion')
    expect(dialog.value.confirmLabel).toBe('Delete')
    expect(dialog.value.cancelLabel).toBe('Cancel')

    settle(false)
    await answer

    setLanguage('ar')
    const arabic = confirmDelete('سيتم حذف الطالب.')
    expect(dialog.value.title).toBe('تأكيد الحذف')
    expect(dialog.value.confirmLabel).toBe('حذف')
    settle(false)
    await arabic
  })

  it('accepts custom labels', async () => {
    const answer = confirmAction('إرسال؟', { confirmLabel: 'إرسال نهائي' })

    expect(dialog.value.confirmLabel).toBe('إرسال نهائي')

    settle(false)
    await answer
  })
})
