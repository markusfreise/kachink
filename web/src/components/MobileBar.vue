<script setup lang="ts">
import { computed, ref, watch, nextTick } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { useMobileBarStore } from '@/stores/mobilebar'
import {
  Squares2X2Icon,
  ClipboardDocumentListIcon,
  FolderIcon,
  UsersIcon,
  PlusIcon,
  UserIcon,
  RectangleStackIcon,
  MagnifyingGlassIcon,
} from '@heroicons/vue/24/outline'

/**
 * Bottom bar on phones. Outside a section: Dashboard, Tasks, Projects,
 * Clients. Inside a section that registered its actions: Dashboard, New,
 * Mine, All, Search; the search field opens full width above the bar.
 */
const route = useRoute()
const bar = useMobileBarStore()
const searchInput = ref<HTMLInputElement | null>(null)

const inSection = computed(() => !!bar.actions)
const isMine = computed(() => bar.actions?.isMine() ?? false)

function isActive(path: string) {
  if (path === '/') return route.path === '/'
  return route.path.startsWith(path)
}

watch(() => bar.searchOpen, async (open) => {
  if (open) {
    await nextTick()
    searchInput.value?.focus()
  }
})
</script>

<template>
  <div class="mobilebar" :class="{ 'mobilebar--search': bar.searchOpen }">
    <div v-if="inSection && bar.searchOpen" class="mobilebar__search">
      <MagnifyingGlassIcon class="mobilebar__search-icon" aria-hidden="true" />
      <input
        ref="searchInput"
        type="search"
        class="form__input mobilebar__search-input"
        :value="bar.query"
        :placeholder="$t('mobilebar.searchPlaceholder')"
        :aria-label="$t('mobilebar.search')"
        @input="bar.setQuery(($event.target as HTMLInputElement).value)"
        @keydown.esc="bar.toggleSearch()"
      />
    </div>

    <nav class="mobilebar__nav" :aria-label="$t('nav.menu')">
      <RouterLink class="mobilebar__item" :class="{ 'mobilebar__item--active': isActive('/') }" to="/">
        <Squares2X2Icon class="mobilebar__icon" aria-hidden="true" />
        <span class="mobilebar__label">{{ $t('nav.dashboard') }}</span>
      </RouterLink>

      <template v-if="inSection && bar.actions">
        <button type="button" class="mobilebar__item mobilebar__item--primary" @click="bar.actions.onNew()">
          <PlusIcon class="mobilebar__icon" aria-hidden="true" />
          <span class="mobilebar__label">{{ $t('mobilebar.new') }}</span>
        </button>
        <button type="button" class="mobilebar__item" :class="{ 'mobilebar__item--active': isMine }" :aria-pressed="isMine" @click="bar.actions.onMine()">
          <UserIcon class="mobilebar__icon" aria-hidden="true" />
          <span class="mobilebar__label">{{ bar.actions.section === 'tasks' ? $t('mobilebar.mine') : $t('mobilebar.active') }}</span>
        </button>
        <button type="button" class="mobilebar__item" :class="{ 'mobilebar__item--active': !isMine }" :aria-pressed="!isMine" @click="bar.actions.onAll()">
          <RectangleStackIcon class="mobilebar__icon" aria-hidden="true" />
          <span class="mobilebar__label">{{ $t('mobilebar.all') }}</span>
        </button>
        <button type="button" class="mobilebar__item" :class="{ 'mobilebar__item--active': bar.searchOpen }" :aria-pressed="bar.searchOpen" @click="bar.toggleSearch()">
          <MagnifyingGlassIcon class="mobilebar__icon" aria-hidden="true" />
          <span class="mobilebar__label">{{ $t('mobilebar.search') }}</span>
        </button>
      </template>

      <template v-else>
        <RouterLink class="mobilebar__item" :class="{ 'mobilebar__item--active': isActive('/tasks') }" to="/tasks">
          <ClipboardDocumentListIcon class="mobilebar__icon" aria-hidden="true" />
          <span class="mobilebar__label">{{ $t('nav.tasks') }}</span>
        </RouterLink>
        <RouterLink class="mobilebar__item" :class="{ 'mobilebar__item--active': isActive('/projects') }" to="/projects">
          <FolderIcon class="mobilebar__icon" aria-hidden="true" />
          <span class="mobilebar__label">{{ $t('nav.projects') }}</span>
        </RouterLink>
        <RouterLink class="mobilebar__item" :class="{ 'mobilebar__item--active': isActive('/clients') }" to="/clients">
          <UsersIcon class="mobilebar__icon" aria-hidden="true" />
          <span class="mobilebar__label">{{ $t('nav.clients') }}</span>
        </RouterLink>
      </template>
    </nav>
  </div>
</template>
