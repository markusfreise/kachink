import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import api from '@/api/client'
import type { Organization } from '@/types'

const STORAGE_KEY = 'org:current'

export const useOrgStore = defineStore('org', () => {
  const organizations = ref<Organization[]>([])
  const currentOrgId = ref<string | null>(localStorage.getItem(STORAGE_KEY))

  const currentOrg = computed(() =>
    organizations.value.find((o) => o.id === currentOrgId.value) ?? organizations.value[0] ?? null,
  )

  async function fetchOrganizations() {
    const { data } = await api.get('/organizations')
    organizations.value = data.data

    // Auto-select: use stored id if valid, otherwise pick first
    const storedValid = organizations.value.some((o) => o.id === currentOrgId.value)
    if (!storedValid && organizations.value.length > 0) {
      setCurrentOrg(organizations.value[0].id)
    }
  }

  function setCurrentOrg(id: string) {
    currentOrgId.value = id
    localStorage.setItem(STORAGE_KEY, id)
  }

  function clear() {
    organizations.value = []
    currentOrgId.value = null
    localStorage.removeItem(STORAGE_KEY)
  }

  return { organizations, currentOrgId, currentOrg, fetchOrganizations, setCurrentOrg, clear }
})
