import { beforeEach, describe, expect, it, vi } from 'vitest'
import { direction, isArabic, language, pick, setLanguage, t } from './i18n.js'
import { toastOptions } from './notify.js'

describe('language', () => {
  beforeEach(() => setLanguage('ar'))

  it('defaults the document to the chosen direction', () => {
    setLanguage('ar')
    expect(direction.value).toBe('rtl')
    expect(document.documentElement.dir).toBe('rtl')

    setLanguage('en')
    expect(direction.value).toBe('ltr')
    expect(document.documentElement.dir).toBe('ltr')
  })

  it('remembers the choice', () => {
    setLanguage('en')
    expect(localStorage.getItem('ui_language')).toBe('en')
  })

  it('translates a key into the active language', () => {
    setLanguage('ar')
    expect(t('delete')).toBe('حذف')

    setLanguage('en')
    expect(t('delete')).toBe('Delete')
  })

  it('fills placeholders rather than gluing fragments together', () => {
    setLanguage('en')
    expect(t('confirmDelete')).not.toContain('{')
  })

  it('returns the key itself when a translation is missing, so nothing renders blank', () => {
    expect(t('a-key-that-does-not-exist')).toBe('a-key-that-does-not-exist')
  })

  it('picks the right one of two ready-made sentences', () => {
    setLanguage('ar')
    expect(pick('عربي', 'English')).toBe('عربي')

    setLanguage('en')
    expect(pick('عربي', 'English')).toBe('English')
  })

  it('has no untranslated key in either dictionary', () => {
    setLanguage('ar')
    const arabic = t('savedSuccessfully')
    setLanguage('en')
    const english = t('savedSuccessfully')

    expect(arabic).not.toBe(english)
    expect(arabic).toMatch(/[؀-ۿ]/)
    expect(english).toMatch(/[A-Za-z]/)
  })
})

describe('toast placement', () => {
  // Notifications should arrive on the side the reader starts from.
  it('comes in on the right in Arabic', () => {
    setLanguage('ar')
    const options = toastOptions()

    expect(options.position).toBe('top-right')
    expect(options.rtl).toBe(true)
  })

  it('comes in on the left in English', () => {
    setLanguage('en')
    const options = toastOptions()

    expect(options.position).toBe('top-left')
    expect(options.rtl).toBe(false)
  })
})
