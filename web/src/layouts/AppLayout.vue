<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { RouterLink, RouterView, useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { useTimerStore } from '@/stores/timer'
import { useOrgStore } from '@/stores/org'
import UserAvatar from '@/components/UserAvatar.vue'
import MobileBar from '@/components/MobileBar.vue'
import {
  Squares2X2Icon,
  ClockIcon,
  FolderIcon,
  UsersIcon,
  UserGroupIcon,
  ChartBarIcon,
  TagIcon,
  ClipboardDocumentListIcon,
  Cog6ToothIcon,
  ArrowRightStartOnRectangleIcon,
  BuildingOfficeIcon,
  Bars3Icon,
  XMarkIcon,
} from '@heroicons/vue/24/outline'

const { t } = useI18n()
const auth = useAuthStore()
const org = useOrgStore()
const timer = useTimerStore()
const route = useRoute()

const menuOpen = ref(false)

onMounted(() => {
  timer.fetchRunning()
  timer.startPolling()
})

onUnmounted(() => {
  timer.stopPolling()
})

watch(() => route.fullPath, () => { menuOpen.value = false })

const navItems = computed(() => [
  { to: '/', label: t('nav.dashboard'), icon: Squares2X2Icon },
  { to: '/time-entries', label: t('nav.timeEntries'), icon: ClockIcon },
  { to: '/projects', label: t('nav.projects'), icon: FolderIcon },
  { to: '/tasks', label: t('nav.tasks'), icon: ClipboardDocumentListIcon },
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

const roleLabel = computed(() => (auth.user?.role === 'admin' ? t('users.roleAdmin') : t('users.roleMember')))

async function handleLogout() {
  await auth.logout()
  window.location.href = '/'
}
</script>

<template>
  <div class="app">
    <div v-if="menuOpen" class="app__backdrop" @click="menuOpen = false"></div>

    <aside class="sidebar" :class="{ 'sidebar--open': menuOpen }" :aria-label="$t('nav.menu')">
      <div class="sidebar__brand">
        <span class="sidebar__brand-text">kaCHINK!</span>
        <button type="button" class="btn btn--icon btn--sm sidebar__close" :aria-label="$t('common.close')" @click="menuOpen = false">
          <XMarkIcon class="btn__icon" />
        </button>
      </div>

      <div v-if="org.organizations.length > 0" class="sidebar__org">
        <BuildingOfficeIcon class="sidebar__org-icon" aria-hidden="true" />
        <span v-if="org.organizations.length === 1" class="sidebar__org-name">{{ org.currentOrg?.name }}</span>
        <select
          v-else
          class="sidebar__org-select"
          :aria-label="$t('nav.organization')"
          :value="org.currentOrgId ?? ''"
          @change="org.setCurrentOrg(($event.target as HTMLSelectElement).value)"
        >
          <option v-for="o in org.organizations" :key="o.id" :value="o.id">{{ o.name }}</option>
        </select>
      </div>

      <nav class="sidebar__nav">
        <RouterLink
          v-for="item in navItems"
          :key="item.to"
          :to="item.to"
          class="sidebar__link"
          :class="{ 'sidebar__link--active': isActive(item.to) }"
          :aria-current="isActive(item.to) ? 'page' : undefined"
        >
          <component :is="item.icon" class="sidebar__icon" aria-hidden="true" />
          {{ item.label }}
        </RouterLink>
      </nav>

      <RouterLink v-if="timer.isRunning" to="/" class="sidebar__timer" aria-live="off">
        <span class="sidebar__timer-dot"></span>
        <span class="sidebar__timer-info">
          <span class="sidebar__timer-time">{{ timer.elapsedFormatted }}</span>
          <span class="sidebar__timer-project">{{ timer.runningEntry?.project?.name }}</span>
        </span>
      </RouterLink>

      <div class="sidebar__footer">
        <RouterLink to="/settings" class="sidebar__user">
          <UserAvatar :name="auth.user?.name" :avatar-url="auth.user?.avatar_url" size="md" />
          <span class="sidebar__user-info">
            <span class="sidebar__user-name">{{ auth.user?.name }}</span>
            <span class="sidebar__user-role">{{ roleLabel }}</span>
          </span>
        </RouterLink>
        <button type="button" class="sidebar__logout" :aria-label="$t('nav.logout')" :title="$t('nav.logout')" @click="handleLogout">
          <ArrowRightStartOnRectangleIcon class="sidebar__icon" aria-hidden="true" />
        </button>
      </div>
    </aside>

    <div class="app__main">
      <header class="topbar">
        <button type="button" class="btn btn--ghost btn--icon" :aria-label="$t('nav.menu')" @click="menuOpen = true">
          <Bars3Icon class="btn__icon" />
        </button>
        <span class="topbar__brand">kaCHINK!</span>
        <span v-if="timer.isRunning" class="topbar__timer">{{ timer.elapsedFormatted }}</span>
      </header>
      <main>
        <RouterView />
      </main>
      <MobileBar />
    </div>
  </div>
</template>
