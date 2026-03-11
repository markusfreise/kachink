# TimeTracker — Product Requirements Document

**Version:** 1.0
**Author:** Markus Freise / freise design+digital
**Date:** March 1, 2026
**Status:** Final Draft

---

## 1. Executive Summary

TimeTracker is a comprehensive time tracking system built for the freise design+digital agency team. It combines a **Laravel/Vue.js web application** with a **native macOS menu bar app** to provide seamless, low-friction time tracking with deep **Asana integration**. The system enables accurate project-based time tracking, powerful reporting, and bi-directional synchronization with Asana to keep project management and time data in a single source of truth.

### 1.1 Vision

Eliminate the friction of time tracking by embedding it directly into the team's existing workflow — the Mac menu bar and Asana — so tracking becomes effortless and data becomes actionable for project planning, billing, and team management.

### 1.2 Key Goals

- Provide a fast, always-accessible time tracking interface via the macOS menu bar
- Enable project- and task-level time tracking linked to Asana projects and tasks
- Deliver comprehensive reporting for billing, profitability analysis, and team utilization
- Maintain bi-directional sync with Asana so time data flows seamlessly between systems
- Support the agency's internal workflow without relying on third-party SaaS time trackers

---

## 2. Target Users

| Role | Description | Primary Needs |
|------|-------------|---------------|
| **Agency Owner (Markus)** | Manages team, projects, and client billing | Reporting, profitability insights, team utilization overview |
| **Team Members** | Designers, developers working on client projects | Fast time entry, easy project/task selection, minimal disruption |
| **Project Managers** | Oversee project delivery and budgets | Budget tracking, time vs. estimate comparisons, project health |

---

## 3. Technical Architecture

### 3.1 Stack Overview

| Layer | Technology | Purpose |
|-------|-----------|---------|
| **Backend API** | Laravel 11+ (PHP 8.3+) | REST API, business logic, Asana sync, authentication |
| **Frontend Web App** | Vue.js 3 (Composition API) | Dashboard, reporting, admin interface |
| **Database** | PostgreSQL 16+ | Primary data store |
| **macOS Menu Bar App** | Swift / SwiftUI | Native time tracking widget |
| **Queue / Jobs** | Laravel Horizon + Redis | Background sync, report generation |
| **Authentication** | Laravel Sanctum | API token auth for menu bar app, SPA auth for web |

### 3.2 System Architecture Diagram

```
┌─────────────────────┐     ┌─────────────────────────────────┐
│  macOS Menu Bar App  │────▶│                                 │
│  (Swift / SwiftUI)   │◀────│         Laravel API              │
└─────────────────────┘     │                                 │
                            │  ┌───────────┐  ┌────────────┐ │
┌─────────────────────┐     │  │ Auth      │  │ Sync       │ │
│  Vue.js Web App      │────▶│  │ (Sanctum) │  │ Engine     │ │
│  (SPA Dashboard)     │◀────│  └───────────┘  └─────┬──────┘ │
└─────────────────────┘     │                        │        │
                            │  ┌───────────┐  ┌──────▼──────┐ │
                            │  │ Reports   │  │ Asana API   │ │
                            │  │ Engine    │  │ Connector   │ │
                            │  └───────────┘  └─────────────┘ │
                            └────────┬────────────────────────┘
                                     │
                              ┌──────▼──────┐
                              │ PostgreSQL   │
                              │ + Redis      │
                              └─────────────┘
```

### 3.3 Deployment

- **Web App & API:** Hosted on the agency's existing infrastructure (or containerized via Docker)
- **macOS App:** Distributed internally (ad-hoc or TestFlight for team)
- **Database:** PostgreSQL instance (can run on Synology NAS Docker or dedicated server)

---

## 4. Data Model

### 4.1 Core Entities

```
users
├── id (uuid, PK)
├── name
├── email
├── role (enum: admin, member)
├── asana_user_gid
├── avatar_url
├── is_active (boolean)
├── created_at / updated_at

clients
├── id (uuid, PK)
├── name
├── slug
├── color (hex, for UI)
├── is_active (boolean)
├── notes
├── created_at / updated_at

projects
├── id (uuid, PK)
├── client_id (FK → clients)
├── name
├── slug
├── color (hex)
├── asana_project_gid
├── budget_hours (decimal, nullable)
├── hourly_rate (decimal, nullable)
├── is_billable (boolean, default: true)
├── is_active (boolean)
├── archived_at (timestamp, nullable)
├── created_at / updated_at

tasks
├── id (uuid, PK)
├── project_id (FK → projects)
├── name
├── asana_task_gid
├── is_active (boolean)
├── created_at / updated_at

time_entries
├── id (uuid, PK)
├── user_id (FK → users)
├── project_id (FK → projects)
├── task_id (FK → tasks, nullable)
├── description (text, nullable)
├── started_at (timestamp)
├── stopped_at (timestamp, nullable)  -- null = currently running
├── duration_seconds (integer, nullable) -- computed on stop, allows manual override
├── is_billable (boolean)
├── is_running (boolean, default: false)
├── source (enum: web, menubar, manual, asana)
├── asana_task_gid (string, nullable)
├── created_at / updated_at

tags
├── id (uuid, PK)
├── name
├── color (hex)

time_entry_tag (pivot)
├── time_entry_id (FK)
├── tag_id (FK)

asana_sync_log
├── id (uuid, PK)
├── direction (enum: inbound, outbound)
├── entity_type (string)
├── entity_id (uuid)
├── asana_gid (string)
├── status (enum: success, failed, skipped)
├── payload (jsonb)
├── error_message (text, nullable)
├── synced_at (timestamp)
```

### 4.2 Key Relationships

- A **Client** has many **Projects**
- A **Project** has many **Tasks** and many **Time Entries**
- A **User** has many **Time Entries**
- A **Time Entry** optionally belongs to a **Task**
- A **Time Entry** can have many **Tags**
- Each **Project** and **Task** can be linked to an Asana counterpart via GID

---

## 5. Feature Specifications

### 5.1 macOS Menu Bar App

The menu bar app is the primary time entry interface for daily use.

#### 5.1.1 Core Functionality

- **Always-visible timer** in the macOS menu bar showing elapsed time and current project name (abbreviated)
- **Click to expand** a dropdown panel with:
  - Current timer display (project, task, description, elapsed time)
  - Start / Stop / Pause controls
  - Project selector (searchable dropdown, grouped by client)
  - Task selector (filtered by selected project, synced from Asana)
  - Description field (free text)
  - Billable toggle
- **Quick switch:** Stop current timer and start a new one in a single action
- **Keyboard shortcut:** Global hotkey to start/stop timer (configurable, default: `⌘⇧T`)
- **Recent entries:** Show last 5 entries for quick restart
- **Offline support:** Queue entries locally when offline, sync when connection restores

#### 5.1.2 Menu Bar States

| State | Icon | Display |
|-------|------|---------|
| **Idle** | Grey circle | "No timer running" |
| **Tracking** | Green pulsing dot | "1:23:45 — Project Name" |
| **Paused** | Orange dot | "Paused — 0:45:12" |
| **Offline** | Grey circle + slash | "Offline — entries queued" |
| **Syncing** | Spinning indicator | Brief sync animation |

#### 5.1.3 Notifications

- Idle reminder after configurable period (default: 30 min) if no timer is running during work hours
- Daily summary notification at end of work day (configurable)
- Long-running timer alert after configurable threshold (default: 4 hours)

#### 5.1.4 Technical Details

- Built with **Swift / SwiftUI** targeting macOS 14+
- Communicates with Laravel API via **REST + Sanctum token auth**
- Local SQLite cache for offline entries and project/task list
- Background sync every 60 seconds when timer is running
- Login via one-time token generated in web app (QR code or copy-paste)

---

### 5.2 Web Application

The Vue.js web app serves as the administration, reporting, and management interface.

#### 5.2.1 Dashboard

- **Today's summary:** Total hours tracked, breakdown by project (mini bar chart)
- **Running timers:** Show all currently active timers across the team (admin view)
- **Weekly overview:** Hours per day for the current week (bar chart)
- **Quick actions:** Start timer, add manual entry, view reports

#### 5.2.2 Time Entry Management

- **Timer mode:** Start/stop timer directly from the web app
- **Manual entry:** Add entries with start/end time or duration
- **Inline editing:** Click any entry in the list to edit project, task, description, times
- **Bulk actions:** Select multiple entries to reassign project, toggle billable, delete
- **Calendar view:** Visual timeline of entries per day (optional, Phase 2)
- **Filters:** By date range, project, client, user, billable status, tags

#### 5.2.3 Project Management

- CRUD for clients and projects
- Set budget hours and hourly rates per project
- Archive projects (hide from selectors but keep data)
- Link/unlink Asana projects
- Project-level billable default
- Color coding for visual identification

#### 5.2.4 Team Management (Admin)

- User list with role assignment (admin / member)
- View any team member's time entries
- Link Asana user accounts
- Deactivate users (preserve data)

#### 5.2.5 Tags

- Create and manage tags (e.g., "Meeting", "Development", "Design", "Support")
- Assign tags to time entries for finer categorization
- Filter reports by tags

---

### 5.3 Reporting Engine

Reports are a critical feature for billing, profitability analysis, and team management.

#### 5.3.1 Report Types

**Summary Report**
- Total hours by project, client, user, or tag
- Grouping: by day, week, month, or custom range
- Filter by: date range, client, project, user, billable status, tags
- Export: CSV, PDF

**Detailed Report**
- Individual time entries with all metadata
- Same filters as summary
- Export: CSV, PDF

**Project Budget Report**
- Hours tracked vs. budget hours (progress bar)
- Burn rate (hours/week trend)
- Projected completion vs. budget
- Revenue: tracked hours × hourly rate
- Profitability indicators (on track / at risk / over budget)

**Team Utilization Report**
- Hours tracked per team member per period
- Billable vs. non-billable ratio
- Utilization rate (tracked hours / available hours)
- Comparison across team members

**Client Profitability Report**
- Aggregated across all projects per client
- Total hours, total revenue, average hourly effective rate
- Trend over time

#### 5.3.2 Report Features

- **Saved reports:** Save filter configurations for quick access
- **Scheduled reports:** Auto-generate and email weekly/monthly summaries (Phase 2)
- **Visual charts:** Bar, line, and pie charts using a Vue charting library (e.g., Chart.js or ApexCharts)
- **Drill-down:** Click any summary row to see underlying time entries
- **Date presets:** Today, this week, last week, this month, last month, this quarter, this year, custom

---

### 5.4 Asana Integration (Bi-Directional)

The Asana integration is a core differentiator, ensuring time tracking lives alongside project management.

#### 5.4.1 Inbound Sync (Asana → TimeTracker)

| Asana Entity | TimeTracker Entity | Trigger |
|--------------|--------------------|---------|
| Workspace projects | Projects | Initial sync + webhook |
| Tasks within linked projects | Tasks | Initial sync + webhook |
| Task assignee | Suggested user for time entry | On task selection |
| Task completion | Visual indicator in task selector | Webhook |
| Project archive | Project archive in TimeTracker | Webhook |

**Sync Behavior:**
- On initial project link: Import all active (non-completed) tasks
- Ongoing: Listen to Asana webhooks for task created, updated, deleted, completed
- Task names and structure stay in sync
- Deleted tasks in Asana → soft-delete in TimeTracker (preserve time entries)

#### 5.4.2 Outbound Sync (TimeTracker → Asana)

| TimeTracker Event | Asana Action |
|-------------------|-------------|
| Time entry created/updated for an Asana-linked task | Update custom field "Tracked Time" on Asana task |
| Time entry deleted | Recalculate and update "Tracked Time" field |
| Daily summary (optional) | Post comment on Asana task with daily time summary |

**Custom Fields in Asana:**
- `Tracked Time (hours)` — Number field, sum of all time entries for that task
- `Budget Remaining (hours)` — Calculated field (if project has budget)

#### 5.4.3 Sync Architecture

```
┌──────────┐   Webhooks    ┌──────────────────┐
│  Asana   │──────────────▶│  Laravel          │
│  API     │◀──────────────│  Sync Engine      │
└──────────┘   REST API    │                   │
                           │  ┌──────────────┐ │
                           │  │ Webhook      │ │
                           │  │ Controller   │ │
                           │  └──────┬───────┘ │
                           │         │         │
                           │  ┌──────▼───────┐ │
                           │  │ Sync Jobs    │ │
                           │  │ (Queue)      │ │
                           │  └──────┬───────┘ │
                           │         │         │
                           │  ┌──────▼───────┐ │
                           │  │ Sync Log     │ │
                           │  │ (Audit)      │ │
                           │  └──────────────┘ │
                           └──────────────────┘
```

- All sync operations run as **queued jobs** (Laravel Horizon)
- **Conflict resolution:** Last-write-wins with timestamp comparison; log conflicts
- **Rate limiting:** Respect Asana API rate limits (150 req/min) with exponential backoff
- **Sync log:** Every sync operation is logged for debugging and audit

#### 5.4.4 Authentication

- OAuth 2.0 flow for connecting the Asana workspace
- Store access token and refresh token encrypted in database
- Auto-refresh tokens before expiry
- Admin-only: Connect/disconnect Asana workspace

---

## 6. API Design

### 6.1 Core Endpoints

```
Authentication
  POST   /api/auth/login
  POST   /api/auth/logout
  GET    /api/auth/me
  POST   /api/auth/token          -- Generate menu bar app token

Time Entries
  GET    /api/time-entries         -- List (filterable, paginated)
  POST   /api/time-entries         -- Create
  GET    /api/time-entries/{id}    -- Show
  PUT    /api/time-entries/{id}    -- Update
  DELETE /api/time-entries/{id}    -- Delete
  POST   /api/time-entries/start   -- Start timer
  POST   /api/time-entries/stop    -- Stop running timer
  GET    /api/time-entries/running -- Get currently running entry

Projects
  GET    /api/projects             -- List (with client, budget info)
  POST   /api/projects             -- Create
  GET    /api/projects/{id}        -- Show (with tasks, time summary)
  PUT    /api/projects/{id}        -- Update
  DELETE /api/projects/{id}        -- Archive

Tasks
  GET    /api/projects/{id}/tasks  -- List tasks for project
  POST   /api/tasks                -- Create
  PUT    /api/tasks/{id}           -- Update
  DELETE /api/tasks/{id}           -- Soft delete

Clients
  GET    /api/clients              -- List
  POST   /api/clients              -- Create
  GET    /api/clients/{id}         -- Show (with projects)
  PUT    /api/clients/{id}         -- Update
  DELETE /api/clients/{id}         -- Archive

Users
  GET    /api/users                -- List team members
  GET    /api/users/{id}           -- Show
  PUT    /api/users/{id}           -- Update

Tags
  GET    /api/tags                 -- List
  POST   /api/tags                 -- Create
  PUT    /api/tags/{id}            -- Update
  DELETE /api/tags/{id}            -- Delete

Reports
  GET    /api/reports/summary      -- Summary report (query params for filters)
  GET    /api/reports/detailed     -- Detailed report
  GET    /api/reports/budget       -- Project budget report
  GET    /api/reports/utilization  -- Team utilization report
  GET    /api/reports/export       -- Export as CSV/PDF

Asana Sync
  POST   /api/asana/connect       -- OAuth callback
  DELETE /api/asana/disconnect     -- Disconnect workspace
  POST   /api/asana/sync/{project} -- Manual sync trigger
  GET    /api/asana/sync/status    -- Sync health status
  POST   /api/asana/webhooks       -- Webhook receiver
```

### 6.2 API Conventions

- All responses follow JSON:API-inspired structure: `{ data: {...}, meta: {...} }`
- Pagination: cursor-based for time entries, offset-based for admin lists
- Filtering via query parameters: `?filter[project_id]=xxx&filter[date_from]=2026-01-01`
- Sorting: `?sort=-started_at` (prefix `-` for descending)
- Includes: `?include=project,task,tags` for eager loading related data
- Error format: `{ error: { code: "VALIDATION_ERROR", message: "...", details: {...} } }`

---

## 7. UI/UX Design Principles

### 7.1 Design System

- **Clean, minimal interface** — focus on data density without clutter
- **Semantic CSS approach** — maintainable, readable class names
- **Color system:** Client/project colors used consistently for instant visual recognition
- **Typography:** System font stack for performance, clear hierarchy
- **Dark mode:** Support macOS dark mode in menu bar app; optional in web app (Phase 2)

### 7.2 Key UX Principles

- **< 2 clicks to start tracking:** From menu bar click to running timer
- **Zero-config for team members:** They log in, see their projects, start tracking
- **Data always visible:** No hiding important info behind modals or extra pages
- **Forgiving:** Easy to edit, reassign, and fix time entries after the fact
- **Responsive web app:** Usable on tablet for on-the-go entry review (not a mobile-first priority)

---

## 8. Security & Privacy

- All API communication over **HTTPS**
- **Sanctum token authentication** with scoped abilities for menu bar app
- **Role-based access control:** Members see own data; admins see all
- Asana tokens stored **encrypted at rest** (Laravel encryption)
- **CSRF protection** on web app
- **Rate limiting** on all API endpoints
- **Audit log** for sensitive operations (user management, project deletion)
- Compliant with **DSGVO/GDPR** — all data stored in EU, user data exportable/deletable

---

## 9. Development Phases

### Phase 1 — Core MVP (Weeks 1–6)

**Goal:** Working time tracking with basic reporting

- [ ] Laravel project setup (API scaffolding, auth, database migrations)
- [ ] Core data model (users, clients, projects, tasks, time_entries)
- [ ] Time entry CRUD API
- [ ] Timer start/stop logic with running entry support
- [ ] Vue.js web app: Login, dashboard, time entry list, manual entry
- [ ] Project & client management (CRUD)
- [ ] Basic summary report (hours by project/user for date range)
- [ ] CSV export

### Phase 2 — macOS Menu Bar App (Weeks 5–9)

**Goal:** Native menu bar time tracking

- [ ] Swift/SwiftUI menu bar app scaffold
- [ ] API authentication (token-based via Sanctum)
- [ ] Project/task selector with search
- [ ] Timer UI (start, stop, pause, switch)
- [ ] Local SQLite cache for offline support
- [ ] Background sync
- [ ] Keyboard shortcut support
- [ ] Idle detection notifications

### Phase 3 — Asana Integration (Weeks 7–11)

**Goal:** Bi-directional sync with Asana

- [ ] Asana OAuth 2.0 flow
- [ ] Project linking UI
- [ ] Inbound sync: Import projects and tasks
- [ ] Webhook setup for real-time updates
- [ ] Outbound sync: Push tracked time to Asana custom fields
- [ ] Sync log and error handling
- [ ] Conflict resolution logic

### Phase 4 — Advanced Reporting (Weeks 10–13)

**Goal:** Full reporting suite

- [ ] Project budget tracking with visual indicators
- [ ] Team utilization report
- [ ] Client profitability report
- [ ] PDF export (styled reports)
- [ ] Saved report configurations
- [ ] Dashboard charts and visualizations
- [ ] Drill-down from summary to detail

### Phase 5 — Polish & Enhancements (Weeks 12–16)

**Goal:** Production-ready, team rollout

- [ ] Tag system for time entries
- [ ] Bulk editing and batch operations
- [ ] Calendar/timeline view for time entries
- [ ] Scheduled email reports
- [ ] Dark mode (web app)
- [ ] Performance optimization and load testing
- [ ] Team onboarding and documentation
- [ ] Bug fixing and UX refinements

---

## 10. Success Metrics

| Metric | Target | Measurement |
|--------|--------|-------------|
| **Adoption** | 100% of team tracking daily within 2 weeks of launch | Active time entries per user per day |
| **Tracking accuracy** | < 5% of entries need manual correction | Edits per entry ratio |
| **Timer start latency** | < 1 second from click to running timer | Menu bar app performance |
| **Asana sync reliability** | > 99% successful sync operations | Sync log success rate |
| **Report generation** | < 3 seconds for any standard report | API response time |
| **Billable visibility** | Team can see billable hours in real-time | Dashboard usage |

---

## 11. Risks & Mitigations

| Risk | Impact | Mitigation |
|------|--------|------------|
| Asana API rate limits during heavy sync | Sync delays | Queue-based processing with backoff; cache Asana data locally |
| Team resistance to time tracking | Low adoption | Minimize friction (menu bar app), show value (personal insights) |
| Offline data conflicts | Duplicate or lost entries | Optimistic locking, conflict detection with user prompt |
| macOS app distribution complexity | Delayed rollout | Start with ad-hoc signing; move to TestFlight if team grows |
| Scope creep in reporting | Delayed delivery | Strict phase gates; MVP reports first, iterate based on actual usage |

---

## 12. Open Questions

1. **Invoicing integration:** Should TimeTracker generate invoices directly, or export data to an invoicing tool (e.g., lexoffice, FastBill)?
2. **Mobile app:** Is a lightweight iOS companion app needed for tracking on the go, or is the web app sufficient on mobile?
3. **Multiple workspaces:** Will the agency ever need to support multiple Asana workspaces or is one sufficient?
4. **Guest/contractor tracking:** Should external contractors be able to track time, or is this internal-only?
5. **Approval workflow:** Does the agency need a time entry approval step before billing, or is trust-based sufficient?

---

## 13. Glossary

| Term | Definition |
|------|-----------|
| **GID** | Global Identifier — Asana's unique ID format for objects |
| **Time Entry** | A single recorded block of work with start/end time, project, and metadata |
| **Running Entry** | A time entry that has been started but not yet stopped (active timer) |
| **Budget Hours** | The allocated hours for a project, used for progress and profitability tracking |
| **Utilization Rate** | Percentage of available work hours that are actually tracked |
| **Billable** | Time that can be invoiced to a client |

---

*This document is maintained by freise design+digital. Last updated: March 1, 2026.*
