export const STATUS_LABELS = {
  unpaid: 'غير مسدد',
  partially_paid: 'مسدد جزئياً',
  paid: 'مسدد',
  overdue: 'متأخر',
}

export const STATUS_TONES = {
  unpaid: 'tone-muted',
  partially_paid: 'tone-warn',
  paid: 'tone-good',
  overdue: 'tone-bad',
}

export const PERIOD_LABELS = {
  day: 'يومي',
  week: 'أسبوعي',
  month: 'شهري',
  year: 'سنوي',
}

export function money(value) {
  return Number(value ?? 0).toLocaleString('en-US', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })
}
