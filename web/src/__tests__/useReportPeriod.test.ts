import { describe, it, expect } from 'vitest'
import { monthlyReportRange, lastWorkdayOfMonth, shiftMonths, isFullMonth } from '@/composables/useReportPeriod'

describe('lastWorkdayOfMonth', () => {
  it('returns the last weekday of a month ending on a weekend', () => {
    // August 2026 ends on Monday the 31st
    expect(lastWorkdayOfMonth(2026, 7).getDate()).toBe(31)
    // May 2026 ends on Sunday the 31st -> Friday the 29th
    expect(lastWorkdayOfMonth(2026, 4).getDate()).toBe(29)
  })
})

describe('monthlyReportRange', () => {
  it('shows the previous month in the middle of a month', () => {
    expect(monthlyReportRange(new Date(2026, 8, 9))).toEqual({ from: '2026-08-01', to: '2026-08-31' })
  })

  it('shows the current month on its last workday', () => {
    // 2026-09-30 is a Wednesday
    expect(monthlyReportRange(new Date(2026, 8, 30))).toEqual({ from: '2026-09-01', to: '2026-09-30' })
  })

  it('shows the current month on a weekend after the last workday', () => {
    // May 2026: last workday Fri 29th; Sat 30th and Sun 31st count as current month
    expect(monthlyReportRange(new Date(2026, 4, 30))).toEqual({ from: '2026-05-01', to: '2026-05-31' })
  })

  it('shows the previous month the day before the last workday', () => {
    expect(monthlyReportRange(new Date(2026, 4, 28))).toEqual({ from: '2026-04-01', to: '2026-04-30' })
  })

  it('handles January by going back to December', () => {
    expect(monthlyReportRange(new Date(2027, 0, 5))).toEqual({ from: '2026-12-01', to: '2026-12-31' })
  })
})

describe('shiftMonths', () => {
  it('keeps full months full when stepping', () => {
    const feb = shiftMonths({ from: '2026-03-01', to: '2026-03-31' }, -1)
    expect(feb).toEqual({ from: '2026-02-01', to: '2026-02-28' })
    expect(isFullMonth(feb)).toBe(true)
  })

  it('shifts custom ranges by a month', () => {
    expect(shiftMonths({ from: '2026-03-10', to: '2026-03-20' }, 1)).toEqual({ from: '2026-04-10', to: '2026-04-20' })
  })
})
