import type { TaskPriority } from '@/types'

export const TASK_PRIORITIES: TaskPriority[] = ['immediate', 'urgent', 'soon', 'easy']

/** Badge modifier per priority. */
export function priorityBadgeClass(priority: TaskPriority): string {
  switch (priority) {
    case 'immediate':
      return 'badge--danger'
    case 'urgent':
      return 'badge--warning'
    case 'soon':
      return 'badge--info'
    default:
      return 'badge--neutral'
  }
}

/** Minutes -> "2:30 h" style string; null when no estimate. */
export function formatEstimate(minutes: number | null | undefined): string | null {
  if (minutes == null) return null
  const h = Math.floor(minutes / 60)
  const m = minutes % 60
  return `${h}:${String(m).padStart(2, '0')} h`
}

export function splitEstimate(minutes: number | null | undefined): { hours: number | null; minutes: number | null } {
  if (minutes == null) return { hours: null, minutes: null }
  return { hours: Math.floor(minutes / 60), minutes: minutes % 60 }
}

export function joinEstimate(hours: number | null, minutes: number | null): number | null {
  const h = hours == null || Number.isNaN(hours) ? 0 : Math.max(0, Math.floor(hours))
  const m = minutes == null || Number.isNaN(minutes) ? 0 : Math.max(0, Math.floor(minutes))
  if ((hours == null || Number.isNaN(hours)) && (minutes == null || Number.isNaN(minutes))) return null
  return h * 60 + m
}

export function formatFileSize(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}
