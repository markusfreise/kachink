<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '@/api/client'
import type { TaskStatus } from '@/types'
import { useToastStore, errorMessage } from '@/stores/toast'
import BaseModal from '@/components/BaseModal.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import ComboBox from '@/components/ComboBox.vue'
import { TrashIcon } from '@heroicons/vue/24/outline'

/** Manage the project statuses (columns) of one project; clone them from another project when empty. */
const props = defineProps<{
  projectId: string
  statuses: TaskStatus[]
}>()

const emit = defineEmits<{
  (e: 'close'): void
  (e: 'changed', statuses: TaskStatus[]): void
}>()

const { t } = useI18n()
const toast = useToastStore()

const newName = ref('')
const newColor = ref('#6D4FC2')
const saving = ref(false)
const deleteTarget = ref<TaskStatus | null>(null)
const deleting = ref(false)
const sources = ref<{ id: string; name: string; color: string }[]>([])
const cloneFrom = ref('')
const cloning = ref(false)

async function loadSources() {
  try {
    const { data } = await api.get(`/projects/${props.projectId}/statuses/sources`)
    sources.value = data.data
  } catch {
    sources.value = []
  }
}

async function refresh() {
  const { data } = await api.get(`/projects/${props.projectId}/statuses`)
  emit('changed', data.data)
}

async function add() {
  if (!newName.value.trim()) return
  saving.value = true
  try {
    await api.post(`/projects/${props.projectId}/statuses`, { name: newName.value.trim(), color: newColor.value })
    newName.value = ''
    await refresh()
  } catch (e) {
    toast.error(errorMessage(e, t('common.failedToSave')))
  } finally {
    saving.value = false
  }
}

async function update(s: TaskStatus, payload: Partial<Pick<TaskStatus, 'name' | 'color'>>) {
  try {
    await api.put(`/projects/${props.projectId}/statuses/${s.id}`, payload)
  } catch (e) {
    toast.error(errorMessage(e, t('common.failedToSave')))
  }
  await refresh()
}

async function remove() {
  if (!deleteTarget.value) return
  deleting.value = true
  try {
    await api.delete(`/projects/${props.projectId}/statuses/${deleteTarget.value.id}`)
    deleteTarget.value = null
    await refresh()
  } catch (e) {
    toast.error(errorMessage(e, t('common.error')))
  } finally {
    deleting.value = false
  }
}

async function clone() {
  if (!cloneFrom.value) return
  cloning.value = true
  try {
    const { data } = await api.post(`/projects/${props.projectId}/statuses/clone`, { from_project_id: cloneFrom.value })
    emit('changed', data.data)
    toast.success(t('projectStatus.cloned'))
  } catch (e) {
    toast.error(errorMessage(e, t('common.failedToSave')))
  } finally {
    cloning.value = false
  }
}

onMounted(loadSources)
</script>

<template>
  <BaseModal :title="$t('projectStatus.manage')" size="narrow" @close="emit('close')">
    <p class="small muted status-manager__intro">{{ $t('projectStatus.intro') }}</p>

    <div v-if="statuses.length === 0 && sources.length" class="project-status__clone form">
      <label class="form__label" for="clone-source">{{ $t('projectStatus.cloneFrom') }}</label>
      <div class="project-status__clone-row">
        <ComboBox id="clone-source" v-model="cloneFrom" :options="sources.map((s) => ({ id: s.id, label: s.name, color: s.color }))" :placeholder="$t('asana.chooseProject')" size="sm" />
        <button type="button" class="btn btn--secondary btn--sm" :disabled="!cloneFrom || cloning" @click="clone">{{ $t('projectStatus.clone') }}</button>
      </div>
      <span class="form__hint">{{ $t('projectStatus.cloneHint') }}</span>
    </div>

    <ul class="status-manager">
      <li v-for="s in statuses" :key="s.id" class="status-manager__row">
        <input type="color" class="form__input form__color status-manager__color" :value="s.color" :aria-label="$t('common.color')" @change="update(s, { color: ($event.target as HTMLInputElement).value })" />
        <input type="text" class="form__input form__input--sm status-manager__name" :value="s.name" :aria-label="$t('common.name')" @change="update(s, { name: ($event.target as HTMLInputElement).value })" />
        <span class="status-manager__count">{{ s.tasks_count ?? 0 }}</span>
        <button type="button" class="btn btn--danger-ghost btn--icon btn--sm" :aria-label="$t('common.delete')" @click="deleteTarget = s">
          <TrashIcon class="btn__icon" aria-hidden="true" />
        </button>
      </li>
    </ul>
    <form class="status-manager__add" @submit.prevent="add">
      <input v-model="newColor" type="color" class="form__input form__color status-manager__color" :aria-label="$t('common.color')" />
      <input v-model="newName" type="text" class="form__input form__input--sm status-manager__name" :placeholder="$t('projectStatus.new')" :aria-label="$t('projectStatus.new')" />
      <button type="submit" class="btn btn--primary btn--sm" :disabled="saving || !newName.trim()">{{ $t('common.add') }}</button>
    </form>

    <template #footer>
      <button type="button" class="btn btn--secondary" @click="emit('close')">{{ $t('common.close') }}</button>
    </template>

    <ConfirmDialog
      v-if="deleteTarget"
      :title="$t('projectStatus.delete')"
      :text="$t('projectStatus.deleteText', { name: deleteTarget.name, count: deleteTarget.tasks_count ?? 0 })"
      :confirm-label="$t('common.delete')"
      danger
      :busy="deleting"
      @confirm="remove"
      @cancel="deleteTarget = null"
    />
  </BaseModal>
</template>
