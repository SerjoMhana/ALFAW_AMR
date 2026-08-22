import { describe, expect, it } from 'vitest'
import { PERIOD_LABELS, STATUS_LABELS, STATUS_TONES, money } from './money.js'

describe('money formatting', () => {
  it('always shows two decimals', () => {
    expect(money(1000)).toBe('1,000.00')
    expect(money(0.5)).toBe('0.50')
    expect(money(1234567.891)).toBe('1,234,567.89')
  })

  it('reads a missing amount as zero rather than NaN', () => {
    expect(money(null)).toBe('0.00')
    expect(money(undefined)).toBe('0.00')
  })

  it('accepts the strings the api returns for decimal columns', () => {
    expect(money('3000.00')).toBe('3,000.00')
    expect(money('89.4')).toBe('89.40')
  })

  it('keeps a negative amount signed', () => {
    expect(money(-250)).toBe('-250.00')
  })

  // The school asked for no currency symbol anywhere.
  it('prints digits, separators and nothing else', () => {
    expect(money(1000)).toMatch(/^-?[\d,]+\.\d{2}$/)
    expect(money(-99.5)).toMatch(/^-?[\d,]+\.\d{2}$/)
  })
})

describe('status vocabulary', () => {
  it('labels and tones cover the same statuses', () => {
    expect(Object.keys(STATUS_LABELS).sort()).toEqual(Object.keys(STATUS_TONES).sort())
  })

  it('names every status the backend can set', () => {
    for (const status of ['unpaid', 'partially_paid', 'paid', 'overdue']) {
      expect(STATUS_LABELS[status]).toBeTruthy()
      expect(STATUS_TONES[status]).toBeTruthy()
    }
  })

  it('names every reporting period', () => {
    expect(Object.keys(PERIOD_LABELS)).toEqual(['day', 'week', 'month', 'year'])
  })
})
