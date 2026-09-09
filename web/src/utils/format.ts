import i18n from '@/i18n'

function currentLocale(): string {
  return i18n.global.locale.value === 'de' ? 'de-DE' : 'en-GB'
}

/** Seconds -> "h:mm" */
export function formatDuration(seconds: number | null | undefined): string {
  const s = Math.max(0, Math.round(seconds ?? 0))
  const h = Math.floor(s / 3600)
  const m = Math.floor((s % 3600) / 60)
  return `${h}:${String(m).padStart(2, '0')}`
}

/** Seconds -> "h:mm:ss" */
export function formatClock(seconds: number): string {
  const s = Math.max(0, Math.floor(seconds))
  const h = Math.floor(s / 3600)
  const m = Math.floor((s % 3600) / 60)
  const sec = s % 60
  return `${h}:${String(m).padStart(2, '0')}:${String(sec).padStart(2, '0')}`
}

/** Seconds -> decimal hours string, e.g. "2.50" / "2,50" */
export function formatHoursDecimal(seconds: number | null | undefined): string {
  return ((seconds ?? 0) / 3600).toLocaleString(currentLocale(), {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })
}

export function formatCurrency(amount: number): string {
  return amount.toLocaleString(currentLocale(), { style: 'currency', currency: 'EUR' })
}

export function formatDate(iso: string, opts: Intl.DateTimeFormatOptions = {}): string {
  return new Date(iso).toLocaleDateString(currentLocale(), {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    ...opts,
  })
}

export function formatDateLong(iso: string): string {
  return new Date(iso).toLocaleDateString(currentLocale(), {
    weekday: 'long',
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
  })
}

export function formatTime(iso: string): string {
  return new Date(iso).toLocaleTimeString(currentLocale(), { hour: '2-digit', minute: '2-digit' })
}

export function formatMonth(dateIso: string): string {
  return new Date(dateIso + 'T00:00:00').toLocaleDateString(currentLocale(), { month: 'long', year: 'numeric' })
}

/** Date -> "YYYY-MM-DD" in local time */
export function toDateString(d: Date): string {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}
