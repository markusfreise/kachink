import { defineStore } from 'pinia'
import { ref } from 'vue'

export type MobileSection = 'tasks' | 'projects' | 'clients'

export interface MobileSectionActions {
  section: MobileSection
  /** Opens the create dialog of the section. */
  onNew: () => void
  /** Narrows the list to "mine" (tasks: assigned to me; projects and clients: active). */
  onMine: () => void
  /** Shows everything. */
  onAll: () => void
  /** Whether "mine" is currently active. */
  isMine: () => boolean
  /** Receives the search text from the bar. */
  onSearch: (query: string) => void
}

/**
 * The mobile bottom bar: views inside a section register their actions so the
 * bar can offer New / Mine / All / Search for them.
 */
export const useMobileBarStore = defineStore('mobilebar', () => {
  const actions = ref<MobileSectionActions | null>(null)
  const searchOpen = ref(false)
  const query = ref('')

  function register(next: MobileSectionActions) {
    actions.value = next
    searchOpen.value = false
    query.value = ''
  }

  function unregister(section: MobileSection) {
    if (actions.value?.section === section) actions.value = null
    searchOpen.value = false
    query.value = ''
  }

  function toggleSearch() {
    searchOpen.value = !searchOpen.value
    if (!searchOpen.value) {
      query.value = ''
      actions.value?.onSearch('')
    }
  }

  function setQuery(value: string) {
    query.value = value
    actions.value?.onSearch(value)
  }

  return { actions, searchOpen, query, register, unregister, toggleSearch, setQuery }
})
