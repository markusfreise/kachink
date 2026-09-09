import { toDateString } from '@/utils/format'

export interface DateRange {
  from: string
  to: string
}

function lastDayOfMonth(year: number, month: number): Date {
  return new Date(year, month + 1, 0)
}

/** Last Monday-Friday day of the given month (public holidays are not considered). */
export function lastWorkdayOfMonth(year: number, month: number): Date {
  const d = lastDayOfMonth(year, month)
  while (d.getDay() === 0 || d.getDay() === 6) d.setDate(d.getDate() - 1)
  return d
}

export function monthRange(year: number, month: number): DateRange {
  return {
    from: toDateString(new Date(year, month, 1)),
    to: toDateString(lastDayOfMonth(year, month)),
  }
}

/**
 * The month a "monthly report" should show: the previous month, unless today is
 * the last workday of the current month (or later), in which case the current
 * month is already complete for reporting purposes.
 */
export function monthlyReportRange(today: Date = new Date()): DateRange {
  const y = today.getFullYear()
  const m = today.getMonth()
  const lastWorkday = lastWorkdayOfMonth(y, m)
  const todayStart = new Date(y, m, today.getDate())

  if (todayStart.getTime() >= lastWorkday.getTime()) {
    return monthRange(y, m)
  }
  return monthRange(y, m - 1)
}

export function isFullMonth(range: DateRange): boolean {
  const from = new Date(range.from + 'T00:00:00')
  const to = new Date(range.to + 'T00:00:00')
  return (
    from.getDate() === 1 &&
    from.getFullYear() === to.getFullYear() &&
    from.getMonth() === to.getMonth() &&
    to.getDate() === lastDayOfMonth(to.getFullYear(), to.getMonth()).getDate()
  )
}

/** Shift a range by whole months (keeps full-month ranges full). */
export function shiftMonths(range: DateRange, delta: number): DateRange {
  const from = new Date(range.from + 'T00:00:00')
  if (isFullMonth(range)) {
    return monthRange(from.getFullYear(), from.getMonth() + delta)
  }
  const to = new Date(range.to + 'T00:00:00')
  from.setMonth(from.getMonth() + delta)
  to.setMonth(to.getMonth() + delta)
  return { from: toDateString(from), to: toDateString(to) }
}
