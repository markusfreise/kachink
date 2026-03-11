export interface User {
  id: string
  name: string
  email: string
  role: 'admin' | 'member'
  avatar_url: string | null
  is_active: boolean
  created_at: string
}

export interface Client {
  id: string
  name: string
  slug: string
  color: string
  is_active: boolean
  notes: string | null
  projects_count?: number
  projects?: Project[]
  total_hours?: number
  created_at: string
  updated_at: string
}

export interface Project {
  id: string
  client_id: string
  name: string
  slug: string
  color: string
  asana_project_gid: string | null
  budget_hours: number | null
  hourly_rate: number | null
  is_billable: boolean
  is_active: boolean
  archived_at: string | null
  client?: Client
  tasks?: Task[]
  tasks_count?: number
  total_tracked_hours?: number
  budget_used_percentage?: number | null
  created_at: string
  updated_at: string
}

export interface Task {
  id: string
  project_id: string
  name: string
  asana_task_gid: string | null
  is_active: boolean
  project?: Project
  created_at: string
  updated_at: string
}

export interface Tag {
  id: string
  name: string
  color: string
  created_at: string
}

export interface TimeEntry {
  id: string
  user_id: string
  project_id: string
  task_id: string | null
  description: string | null
  started_at: string
  stopped_at: string | null
  duration_seconds: number | null
  duration_human: string
  is_billable: boolean
  is_running: boolean
  source: 'web' | 'menubar' | 'manual' | 'api'
  user?: User
  project?: Project
  task?: Task | null
  tags?: Tag[]
  created_at: string
  updated_at: string
}

export interface PaginationMeta {
  current_page: number
  last_page: number
  per_page: number
  total: number
}

export interface ApiResponse<T> {
  data: T
  meta?: PaginationMeta
}

export interface ReportTotals {
  total_hours: number
  billable_hours: number
  non_billable_hours: number
  entry_count: number
}

export interface SummaryRow {
  project_id?: string
  project_name?: string
  client_id?: string
  client_name?: string
  user_id?: string
  user_name?: string
  period?: string
  color?: string
  total_hours: number
  billable_hours: number
  entry_count: number
}

export interface BudgetRow {
  id: string
  project_name: string
  client_name: string
  color: string
  budget_hours: number
  tracked_hours: number
  billable_hours: number
  remaining_hours: number
  budget_used_percentage: number
  hourly_rate: number | null
  revenue: number | null
  status: 'on_track' | 'at_risk' | 'over_budget'
}

export interface UtilizationRow {
  id: string
  name: string
  total_hours: number
  billable_hours: number
  non_billable_hours: number
  billable_percentage: number
  days_tracked: number
  avg_hours_per_day: number
}
