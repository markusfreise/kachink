<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '@/api/client'
import type { Tag } from '@/types'
import { PlusIcon, PencilIcon, TrashIcon, XMarkIcon } from '@heroicons/vue/24/outline'

const { t } = useI18n()
const tags = ref<Tag[]>([])
const loading = ref(true)
const showForm = ref(false)
const editingTag = ref<Tag | null>(null)

const formName = ref('')
const formColor = ref('#6B7280')
const saving = ref(false)
const formError = ref('')

async function fetchTags() {
  loading.value = true
  try {
    const { data } = await api.get('/tags')
    tags.value = data.data
  } finally {
    loading.value = false
  }
}

function openCreate() {
  editingTag.value = null
  formName.value = ''
  formColor.value = '#6B7280'
  showForm.value = true
}

function openEdit(tag: Tag) {
  editingTag.value = tag
  formName.value = tag.name
  formColor.value = tag.color
  showForm.value = true
}

async function handleSave() {
  formError.value = ''
  saving.value = true
  try {
    const payload = { name: formName.value, color: formColor.value }
    if (editingTag.value) {
      await api.put(`/tags/${editingTag.value.id}`, payload)
    } else {
      await api.post('/tags', payload)
    }
    showForm.value = false
    fetchTags()
  } catch (e: any) {
    formError.value = e.response?.data?.message || t('common.failedToSave')
  } finally {
    saving.value = false
  }
}

async function deleteTag(tag: Tag) {
  if (!confirm(t('tags.deleteConfirm', { name: tag.name }))) return
  await api.delete(`/tags/${tag.id}`)
  fetchTags()
}

onMounted(fetchTags)
</script>

<template>
  <div class="page-container">
    <div class="page-header">
      <h1 class="heading-1">{{ $t('tags.title') }}</h1>
      <button class="btn-primary" @click="openCreate">
        <PlusIcon class="btn-icon-sm" />
        {{ $t('tags.newTag') }}
      </button>
    </div>

    <div v-if="loading" class="loading-center">
      <div class="loading-spinner"></div>
    </div>

    <div v-else class="tags-grid">
      <div v-for="tag in tags" :key="tag.id" class="tag-card">
        <div class="tag-info">
          <span class="color-dot" :style="{ backgroundColor: tag.color }"></span>
          <span class="tag-name">{{ tag.name }}</span>
        </div>
        <div class="tag-actions">
          <button class="btn-ghost btn-icon btn-sm" @click="openEdit(tag)">
            <PencilIcon class="tag-action-icon" />
          </button>
          <button class="btn-ghost btn-icon btn-sm" @click="deleteTag(tag)">
            <TrashIcon class="tag-action-icon" />
          </button>
        </div>
      </div>

      <div v-if="tags.length === 0" class="empty-state">
        <p class="empty-state-text">{{ $t('tags.noTags') }}</p>
      </div>
    </div>

    <!-- Form Modal -->
    <div v-if="showForm" class="modal-overlay" @click.self="showForm = false">
      <div class="modal-panel-sm">
        <div class="modal-header">
          <h2 class="heading-2">{{ editingTag ? $t('tags.editTag') : $t('tags.newTag') }}</h2>
          <button class="btn-ghost btn-icon" @click="showForm = false">
            <XMarkIcon class="modal-close-icon" />
          </button>
        </div>
        <form class="modal-body" @submit.prevent="handleSave">
          <div v-if="formError" class="form-error-box">{{ formError }}</div>
          <div class="form-group">
            <label class="form-label">{{ $t('tags.nameRequired') }}</label>
            <input v-model="formName" type="text" class="form-input" required />
          </div>
          <div class="form-group">
            <label class="form-label">{{ $t('common.color') }}</label>
            <input v-model="formColor" type="color" class="form-input form-color" />
          </div>
          <div class="modal-actions">
            <button type="button" class="btn-secondary" @click="showForm = false">{{ $t('common.cancel') }}</button>
            <button type="submit" class="btn-primary" :disabled="saving">
              {{ saving ? $t('common.saving') : $t('common.save') }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<style scoped>
@reference "../assets/main.css";
.page-header {
  @apply flex items-center justify-between mb-6;
}

.loading-center {
  @apply flex justify-center py-12;
}

.tags-grid {
  @apply grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3;
}

.tag-card {
  @apply bg-white rounded-lg border border-gray-200 shadow-sm p-4 flex items-center justify-between;
}

.tag-info {
  @apply flex items-center gap-2;
}

.tag-name {
  @apply text-sm font-medium text-gray-900;
}

.tag-actions {
  @apply flex gap-1;
}

.tag-action-icon {
  @apply h-4 w-4;
}

.btn-icon-sm {
  @apply h-4 w-4;
}

.modal-overlay {
  @apply fixed inset-0 z-50 flex items-center justify-center bg-black/50;
}

.modal-panel-sm {
  @apply w-full max-w-sm rounded-xl bg-white shadow-xl;
}

.modal-header {
  @apply flex items-center justify-between px-6 py-4 border-b border-gray-200;
}

.modal-close-icon {
  @apply h-5 w-5;
}

.modal-body {
  @apply space-y-4 px-6 py-4;
}

.form-error-box {
  @apply rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 border border-red-200;
}

.form-color {
  @apply h-10 p-1 cursor-pointer;
}

.modal-actions {
  @apply flex justify-end gap-3 pt-2;
}
</style>
