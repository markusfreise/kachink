<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '@/api/client'
import type { Tag } from '@/types'
import { useToastStore, errorMessage } from '@/stores/toast'
import BaseModal from '@/components/BaseModal.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import { PlusIcon, PencilIcon, TrashIcon, TagIcon } from '@heroicons/vue/24/outline'

const { t } = useI18n()
const toast = useToastStore()

const tags = ref<Tag[]>([])
const loading = ref(true)
const showForm = ref(false)
const editingTag = ref<Tag | null>(null)

const formName = ref('')
const formColor = ref('#6B7280')
const saving = ref(false)
const formError = ref('')

const deleteTarget = ref<Tag | null>(null)
const deleting = ref(false)

async function fetchTags() {
  loading.value = true
  try {
    const { data } = await api.get('/tags')
    tags.value = data.data
  } catch (e) {
    toast.error(errorMessage(e, t('common.loadFailed')))
  } finally {
    loading.value = false
  }
}

function openCreate() {
  editingTag.value = null
  formName.value = ''
  formColor.value = '#6B7280'
  formError.value = ''
  showForm.value = true
}

function openEdit(tag: Tag) {
  editingTag.value = tag
  formName.value = tag.name
  formColor.value = tag.color
  formError.value = ''
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
    toast.success(t('tags.saved'))
    fetchTags()
  } catch (e) {
    formError.value = errorMessage(e, t('common.failedToSave'))
  } finally {
    saving.value = false
  }
}

async function confirmDelete() {
  if (!deleteTarget.value) return
  deleting.value = true
  try {
    await api.delete(`/tags/${deleteTarget.value.id}`)
    deleteTarget.value = null
    toast.success(t('tags.deleted'))
    fetchTags()
  } catch (e) {
    toast.error(errorMessage(e, t('common.error')))
  } finally {
    deleting.value = false
  }
}

onMounted(fetchTags)
</script>

<template>
  <div class="page tags">
    <div class="page__header">
      <div>
        <h1 class="heading-1 page__title">{{ $t('tags.title') }}</h1>
        <p v-if="!loading && tags.length > 0" class="page__subtitle small">{{ $t('tags.count', { count: tags.length }) }}</p>
      </div>
      <div class="page__actions">
        <button type="button" class="btn btn--primary" @click="openCreate">
          <PlusIcon class="btn__icon" aria-hidden="true" />
          {{ $t('tags.newTag') }}
        </button>
      </div>
    </div>

    <div v-if="loading" class="loading">
      <div class="spinner" role="status"></div>
    </div>

    <div v-else-if="tags.length === 0" class="card">
      <div class="empty">
        <TagIcon class="empty__icon" aria-hidden="true" />
        <p class="empty__text">{{ $t('tags.noTags') }}</p>
        <button type="button" class="btn btn--primary btn--sm" @click="openCreate">
          <PlusIcon class="btn__icon" aria-hidden="true" />
          {{ $t('tags.newTag') }}
        </button>
      </div>
    </div>

    <ul v-else class="grid grid--3">
      <li v-for="tag in tags" :key="tag.id" class="card tags__card">
        <div class="tags__info">
          <span class="color-dot color-dot--lg" :style="{ backgroundColor: tag.color }" aria-hidden="true"></span>
          <span class="tags__name">{{ tag.name }}</span>
        </div>
        <div class="tags__actions">
          <button
            type="button"
            class="btn btn--ghost btn--icon btn--sm"
            :aria-label="$t('tags.editAria', { name: tag.name })"
            :title="$t('common.edit')"
            @click="openEdit(tag)"
          >
            <PencilIcon class="btn__icon" aria-hidden="true" />
          </button>
          <button
            type="button"
            class="btn btn--danger-ghost btn--icon btn--sm"
            :aria-label="$t('tags.deleteAria', { name: tag.name })"
            :title="$t('common.delete')"
            @click="deleteTarget = tag"
          >
            <TrashIcon class="btn__icon" aria-hidden="true" />
          </button>
        </div>
      </li>
    </ul>

    <BaseModal
      v-if="showForm"
      :title="editingTag ? $t('tags.editTag') : $t('tags.newTag')"
      size="narrow"
      @close="showForm = false"
    >
      <form id="tag-form" class="form" @submit.prevent="handleSave">
        <div v-if="formError" class="form__alert" role="alert">{{ formError }}</div>
        <div class="form__group">
          <label for="tag-name" class="form__label">{{ $t('tags.nameRequired') }}</label>
          <input id="tag-name" v-model="formName" type="text" class="form__input" required autofocus />
        </div>
        <div class="form__group">
          <label for="tag-color" class="form__label">{{ $t('common.color') }}</label>
          <input id="tag-color" v-model="formColor" type="color" class="form__input form__color" />
        </div>
      </form>
      <template #footer>
        <button type="button" class="btn btn--secondary" @click="showForm = false">{{ $t('common.cancel') }}</button>
        <button type="submit" form="tag-form" class="btn btn--primary" :disabled="saving">
          {{ saving ? $t('common.saving') : $t('common.save') }}
        </button>
      </template>
    </BaseModal>

    <ConfirmDialog
      v-if="deleteTarget"
      :title="$t('tags.deleteTitle')"
      :text="$t('tags.deleteText', { name: deleteTarget.name })"
      :confirm-label="$t('common.delete')"
      danger
      :busy="deleting"
      @confirm="confirmDelete"
      @cancel="deleteTarget = null"
    />
  </div>
</template>
