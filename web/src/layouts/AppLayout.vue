<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink, RouterView, useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { useTimerStore } from '@/stores/timer'
import { useOrgStore } from '@/stores/org'
import {
  ClockIcon,
  HomeIcon,
  FolderIcon,
  UsersIcon,
  UserGroupIcon,
  ChartBarIcon,
  TagIcon,
  Cog6ToothIcon,
  ArrowRightStartOnRectangleIcon,
  BuildingOfficeIcon,
} from '@heroicons/vue/24/outline'

const { t } = useI18n()
const auth = useAuthStore()
const org = useOrgStore()
const timer = useTimerStore()
const route = useRoute()

const navItems = computed(() => [
  { to: '/', label: t('nav.dashboard'), icon: HomeIcon },
  { to: '/time-entries', label: t('nav.timeEntries'), icon: ClockIcon },
  { to: '/projects', label: t('nav.projects'), icon: FolderIcon },
  { to: '/clients', label: t('nav.clients'), icon: UsersIcon },
  { to: '/reports', label: t('nav.reports'), icon: ChartBarIcon },
  { to: '/tags', label: t('nav.tags'), icon: TagIcon },
  ...(auth.isAdmin ? [{ to: '/users', label: t('users.title'), icon: UserGroupIcon }] : []),
  { to: '/settings', label: t('nav.settings'), icon: Cog6ToothIcon },
])

function isActive(path: string) {
  if (path === '/') return route.path === '/'
  return route.path.startsWith(path)
}

async function handleLogout() {
  await auth.logout()
  window.location.href = '/login'
}
</script>

<template>
  <div class="app-layout">
    <!-- Sidebar -->
    <aside class="app-sidebar">
      <div class="sidebar-brand">
        <ClockIcon class="sidebar-brand-icon" />
        <span class="sidebar-brand-text">kaCHINK!</span>
      </div>

      <!-- Organization switcher -->
      <div v-if="org.organizations.length > 0" class="sidebar-org">
        <BuildingOfficeIcon class="sidebar-org-icon" />
        <template v-if="org.organizations.length === 1">
          <span class="sidebar-org-name">{{ org.currentOrg?.name }}</span>
        </template>
        <template v-else>
          <select
            class="sidebar-org-select"
            :value="org.currentOrgId ?? ''"
            @change="org.setCurrentOrg(($event.target as any).value)"
          >
            <option v-for="o in org.organizations" :key="o.id" :value="o.id">{{ o.name }}</option>
          </select>
        </template>
      </div>

      <nav class="sidebar-nav">
        <RouterLink
          v-for="item in navItems"
          :key="item.to"
          :to="item.to"
          :class="isActive(item.to) ? 'sidebar-link-active' : 'sidebar-link'"
        >
          <component :is="item.icon" class="sidebar-icon" />
          {{ item.label }}
        </RouterLink>
      </nav>

      <!-- Timer indicator in sidebar -->
      <div v-if="timer.isRunning" class="sidebar-timer">
        <div class="sidebar-timer-dot"></div>
        <div class="sidebar-timer-info">
          <span class="sidebar-timer-time">{{ timer.elapsedFormatted }}</span>
          <span class="sidebar-timer-project">{{ timer.runningEntry?.project?.name }}</span>
        </div>
      </div>

      <div class="sidebar-footer">
        <div class="sidebar-user">
          <div class="sidebar-user-avatar">
            {{ auth.user?.name?.charAt(0) ?? '?' }}
          </div>
          <div class="sidebar-user-info">
            <span class="sidebar-user-name">{{ auth.user?.name }}</span>
            <span class="sidebar-user-role">{{ auth.user?.role }}</span>
          </div>
        </div>
        <button class="btn-ghost btn-sm" @click="handleLogout">
          <ArrowRightStartOnRectangleIcon class="sidebar-icon" />
        </button>
      </div>
    </aside>

    <!-- Main content -->
    <main class="app-main">
      <RouterView />
    </main>
  </div>
</template>

<style scoped>
@reference "../assets/main.css";
.app-layout {
  @apply flex min-h-screen;
}

.app-sidebar {
  @apply fixed inset-y-0 left-0 z-30 flex w-64 flex-col bg-white border-r border-gray-200;
}

.sidebar-brand {
  @apply flex items-center gap-3 px-6 py-5 border-b border-gray-200;
}

.sidebar-brand-icon {
  @apply h-8 w-8 text-primary-600;
}

.sidebar-brand-text {
  @apply text-lg font-bold text-gray-900;
}

.sidebar-nav {
  @apply flex-1 space-y-1 px-3 py-4 overflow-y-auto;
}

.sidebar-icon {
  @apply h-5 w-5 shrink-0;
}

.sidebar-timer {
  @apply flex items-center gap-3 mx-3 rounded-lg bg-green-50 px-3 py-2 border border-green-200;
}

.sidebar-timer-dot {
  @apply h-2.5 w-2.5 rounded-full bg-green-500 animate-pulse shrink-0;
}

.sidebar-timer-info {
  @apply flex flex-col min-w-0;
}

.sidebar-timer-time {
  @apply font-mono text-sm font-semibold text-green-700 tabular-nums;
}

.sidebar-timer-project {
  @apply text-xs text-green-600 truncate;
}

.sidebar-footer {
  @apply flex items-center justify-between border-t border-gray-200 px-4 py-3;
}

.sidebar-user {
  @apply flex items-center gap-3 min-w-0;
}

.sidebar-user-avatar {
  @apply flex h-8 w-8 items-center justify-center rounded-full bg-primary-100 text-sm font-medium text-primary-700 shrink-0;
}

.sidebar-user-info {
  @apply flex flex-col min-w-0;
}

.sidebar-user-name {
  @apply text-sm font-medium text-gray-900 truncate;
}

.sidebar-user-role {
  @apply text-xs text-gray-500 capitalize;
}

.app-main {
  @apply flex-1 ml-64;
}
</style>
