<script setup lang="ts">
import { ref, computed, onMounted, watch, nextTick } from 'vue'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import api from '@/api/client'
import { downloadFile } from '@/api/download'
import type { ProjectTask, ProjectTaskComment, ProjectTaskAttachment, ProjectTaskHistory, User, Tag, TaskStatus, TaskPriority } from '@/types'
import { useAuthStore } from '@/stores/auth'
import { useToastStore, errorMessage } from '@/stores/toast'
import TaskTree from '@/components/TaskTree.vue'
import TaskFormModal from '@/components/TaskFormModal.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import ComboBox from '@/components/ComboBox.vue'
import {
  ArrowLeftIcon,
  CheckIcon,
  PencilSquareIcon,
  PlusIcon,
  TrashIcon,
  ArrowDownTrayIcon,
  PaperClipIcon,
  ChatBubbleLeftIcon,
  ClockIcon,
} from '@heroicons/vue/24/outline'
import { TASK_PRIORITIES, priorityBadgeClass, formatEstimate, formatFileSize, splitEstimate, joinEstimate } from '@/utils/tasks'
import { formatDate, formatCurrency, formatTime, toDateString } from '@/utils/format'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const toast = useToastStore()

const task = ref<ProjectTask | null>(null)
const loading = ref(true)
const notFound = ref(false)
const users = ref<User[]>([])
const tags = ref<Tag[]>([])
const statuses = ref<TaskStatus[]>([])

const showSubtaskForm = ref(false)
const showDelete = ref(false)
const deleting = ref(false)
const toggling = ref(false)
const busyChildId = ref<string | null>(null)
const saving = ref(false)

// Inline editing state
const editingTitle = ref(false)
const titleDraft = ref('')
const titleInput = ref<HTMLInputElement | null>(null)
const editingDescription = ref(false)
const descriptionDraft = ref('')
const estimateHours = ref<number | null>(null)
const estimateMinutes = ref<number | null>(null)
const budgetDraft = ref<number | null>(null)

const commentBody = ref('')
const commentSaving = ref(false)
const editingComment = ref<ProjectTaskComment | null>(null)
const editingBody = ref('')
const deleteCommentTarget = ref<ProjectTaskComment | null>(null)
const commentDeleting = ref(false)

const fileInput = ref<HTMLInputElement | null>(null)
const uploading = ref(false)
const dragging = ref(false)
const deleteAttachmentTarget = ref<ProjectTaskAttachment | null>(null)
const attachmentDeleting = ref(false)

const taskId = computed(() => String(route.params.id))
const isAdmin = computed(() => auth.user?.role === 'admin')
const canDeleteTask = computed(() => isAdmin.value || (!!task.value && task.value.created_by === auth.user?.id))
const estimate = computed(() => formatEstimate(task.value?.estimate_minutes))
const children = computed(() => task.value?.children ?? [])
const childrenDone = computed(() => children.value.filter((c) => c.is_completed).length)
const parentForSubtask = computed(() =>
  task.value ? { id: task.value.id, title: task.value.title, project_id: task.value.project_id } : null,
)
const userOptions = computed(() => users.value.map((u) => ({ id: u.id, label: u.name })))
const statusOptions = computed(() => statuses.value.map((s) => ({ id: s.id, label: s.name, color: s.color })))
const dayChips = [1, 3, 7, 14]

function syncDrafts() {
  if (!task.value) return
  const est = splitEstimate(task.value.estimate_minutes)
  estimateHours.value = est.hours
  estimateMinutes.value = est.minutes
  budgetDraft.value = task.value.budget != null ? Number(task.value.budget) : null
}

async function fetchTask() {
  loading.value = true
  notFound.value = false
  try {
    const { data } = await api.get(`/project-tasks/${taskId.value}`)
    task.value = data.data
    syncDrafts()
  } catch (e) {
    task.value = null
    notFound.value = true
    const status = (e as { response?: { status?: number } })?.response?.status
    if (status !== 404) toast.error(errorMessage(e, t('common.loadFailed')))
  } finally {
    loading.value = false
  }
}

async function fetchOptions() {
  try {
    const [u, tg, st] = await Promise.all([api.get('/users'), api.get('/tags'), api.get('/task-statuses')])
    users.value = u.data.data
    tags.value = tg.data.data
    statuses.value = st.data.data
  } catch {
    // Selects stay empty; the page still works.
  }
}

/** Saves a partial update and replaces the task with the server response. */
async function patch(payload: Record<string, unknown>, successMessage?: string) {
  if (!task.value) return false
  saving.value = true
  try {
    const { data } = await api.put(`/project-tasks/${task.value.id}`, payload)
    task.value = data.data
    syncDrafts()
    if (successMessage) toast.success(successMessage)
    return true
  } catch (e) {
    toast.error(errorMessage(e, t('common.failedToSave')))
    return false
  } finally {
    saving.value = false
  }
}

// ---------------------------------------------------------------- title and description

async function startEditTitle() {
  if (!task.value) return
  titleDraft.value = task.value.title
  editingTitle.value = true
  await nextTick()
  titleInput.value?.focus()
  titleInput.value?.select()
}

async function saveTitle() {
  if (!editingTitle.value || !task.value) return
  const title = titleDraft.value.trim()
  editingTitle.value = false
  if (!title || title === task.value.title) return
  await patch({ title })
}

function startEditDescription() {
  if (!task.value) return
  descriptionDraft.value = task.value.description ?? ''
  editingDescription.value = true
}

async function saveDescription() {
  if (!task.value) return
  const description = descriptionDraft.value.trim() || null
  if (description === (task.value.description ?? null)) {
    editingDescription.value = false
    return
  }
  if (await patch({ description })) editingDescription.value = false
}

// ---------------------------------------------------------------- meta panel

async function toggleCompleted(target: ProjectTask, completed: boolean) {
  const isSelf = target.id === task.value?.id
  if (isSelf) {
    toggling.value = true
    await patch({ completed })
    toggling.value = false
    return
  }
  busyChildId.value = target.id
  try {
    await api.put(`/project-tasks/${target.id}`, { completed })
    await fetchTask()
  } catch (e) {
    toast.error(errorMessage(e, t('common.failedToSave')))
  } finally {
    busyChildId.value = null
  }
}

function setStatus(id: string) {
  patch({ status_id: id || null })
}

async function createStatus(name: string) {
  try {
    const { data } = await api.post('/task-statuses', { name: name.trim() })
    statuses.value.push(data.data)
    await patch({ status_id: data.data.id })
  } catch (e) {
    toast.error(errorMessage(e, t('common.failedToSave')))
  }
}

function setPriority(event: Event) {
  patch({ priority: (event.target as HTMLSelectElement).value as TaskPriority })
}

function setAssignee(id: string) {
  patch({ assignee_id: id || null })
}

function setDate(field: 'deadline' | 'reminder_at', value: string) {
  patch({ [field]: value || null })
}

function inDays(field: 'deadline' | 'reminder_at', days: number) {
  const d = new Date()
  d.setDate(d.getDate() + days)
  setDate(field, toDateString(d))
}

function saveEstimate() {
  const minutes = joinEstimate(estimateHours.value, estimateMinutes.value)
  if (minutes === (task.value?.estimate_minutes ?? null)) return
  patch({ estimate_minutes: minutes })
}

function saveBudget() {
  const budget = budgetDraft.value === null || Number.isNaN(budgetDraft.value) ? null : budgetDraft.value
  if (budget === (task.value?.budget ?? null)) return
  patch({ budget })
}

function toggleTag(id: string) {
  if (!task.value) return
  const current = (task.value.tags ?? []).map((tag) => tag.id)
  const next = current.includes(id) ? current.filter((x) => x !== id) : [...current, id]
  patch({ tag_ids: next })
}

function hasTag(id: string) {
  return (task.value?.tags ?? []).some((tag) => tag.id === id)
}

function onSubtaskSaved() {
  showSubtaskForm.value = false
  fetchTask()
}

async function deleteTask() {
  if (!task.value) return
  deleting.value = true
  try {
    const parentId = task.value.parent_id
    await api.delete(`/project-tasks/${task.value.id}`)
    toast.success(t('tasks.deleted'))
    if (parentId) router.replace({ name: 'task-detail', params: { id: parentId } })
    else router.replace({ name: 'tasks' })
  } catch (e) {
    toast.error(errorMessage(e, t('common.error')))
    deleting.value = false
    showDelete.value = false
  }
}

// ---------------------------------------------------------------- comments

function canEditComment(c: ProjectTaskComment) {
  return isAdmin.value || c.user_id === auth.user?.id
}

async function addComment() {
  if (!task.value || !commentBody.value.trim()) return
  commentSaving.value = true
  try {
    await api.post(`/project-tasks/${task.value.id}/comments`, { body: commentBody.value.trim() })
    commentBody.value = ''
    toast.success(t('tasks.commentAdded'))
    await fetchTask()
  } catch (e) {
    toast.error(errorMessage(e, t('common.failedToSave')))
  } finally {
    commentSaving.value = false
  }
}

function startEditComment(c: ProjectTaskComment) {
  editingComment.value = c
  editingBody.value = c.body
}

async function saveComment() {
  if (!task.value || !editingComment.value || !editingBody.value.trim()) return
  commentSaving.value = true
  try {
    await api.put(`/project-tasks/${task.value.id}/comments/${editingComment.value.id}`, { body: editingBody.value.trim() })
    editingComment.value = null
    await fetchTask()
  } catch (e) {
    toast.error(errorMessage(e, t('common.failedToSave')))
  } finally {
    commentSaving.value = false
  }
}

async function deleteComment() {
  if (!task.value || !deleteCommentTarget.value) return
  commentDeleting.value = true
  try {
    await api.delete(`/project-tasks/${task.value.id}/comments/${deleteCommentTarget.value.id}`)
    deleteCommentTarget.value = null
    toast.success(t('tasks.commentDeleted'))
    await fetchTask()
  } catch (e) {
    toast.error(errorMessage(e, t('common.error')))
  } finally {
    commentDeleting.value = false
  }
}

// ---------------------------------------------------------------- attachments

async function uploadFiles(files: FileList | File[]) {
  if (!task.value || files.length === 0) return
  uploading.value = true
  let ok = 0
  try {
    for (const file of Array.from(files)) {
      const form = new FormData()
      form.append('file', file)
      try {
        await api.post(`/project-tasks/${task.value.id}/attachments`, form, { headers: { 'Content-Type': 'multipart/form-data' } })
        ok++
      } catch (e) {
        toast.error(`${file.name}: ${errorMessage(e, t('common.failedToSave'))}`)
      }
    }
    if (ok > 0) {
      toast.success(t('tasks.attachmentAdded'))
      await fetchTask()
    }
  } finally {
    uploading.value = false
  }
}

function onFileChosen(event: Event) {
  const input = event.target as HTMLInputElement
  if (input.files) uploadFiles(input.files)
  input.value = ''
}

function onDrop(event: DragEvent) {
  dragging.value = false
  if (event.dataTransfer?.files?.length) uploadFiles(event.dataTransfer.files)
}

async function download(a: ProjectTaskAttachment) {
  if (!task.value) return
  try {
    await downloadFile(`/project-tasks/${task.value.id}/attachments/${a.id}/download`)
  } catch (e) {
    toast.error(errorMessage(e, t('common.error')))
  }
}

function canDeleteAttachment(a: ProjectTaskAttachment) {
  return isAdmin.value || a.user_id === auth.user?.id
}

async function deleteAttachment() {
  if (!task.value || !deleteAttachmentTarget.value) return
  attachmentDeleting.value = true
  try {
    await api.delete(`/project-tasks/${task.value.id}/attachments/${deleteAttachmentTarget.value.id}`)
    deleteAttachmentTarget.value = null
    toast.success(t('tasks.attachmentDeleted'))
    await fetchTask()
  } catch (e) {
    toast.error(errorMessage(e, t('common.error')))
  } finally {
    attachmentDeleting.value = false
  }
}

// ---------------------------------------------------------------- history

function userName(id: unknown): string {
  if (typeof id !== 'string') return t('tasks.empty')
  return users.value.find((u) => u.id === id)?.name ?? id
}

function statusName(id: unknown): string {
  if (typeof id !== 'string') return t('tasks.empty')
  return statuses.value.find((s) => s.id === id)?.name ?? id
}

function historyValue(field: string, value: unknown): string {
  if (value === null || value === undefined || value === '') return t('tasks.empty')
  switch (field) {
    case 'assignee_id':
      return userName(value)
    case 'status_id':
      return statusName(value)
    case 'priority':
      return t(`tasks.priorities.${String(value)}`)
    case 'estimate_minutes':
      return formatEstimate(Number(value)) ?? t('tasks.empty')
    case 'budget':
      return formatCurrency(Number(value))
    case 'deadline':
    case 'reminder_at':
      return formatDate(String(value))
    case 'tags':
      return Array.isArray(value) && value.length ? value.join(', ') : t('tasks.empty')
    case 'description':
      return String(value).length > 80 ? `${String(value).slice(0, 80)}...` : String(value)
    default:
      return String(value)
  }
}

interface ChangeLine {
  field: string
  label: string
  from: string
  to: string
}

function changeLines(h: ProjectTaskHistory): ChangeLine[] {
  if (h.action !== 'updated' || !h.changes) return []
  return Object.entries(h.changes).map(([field, diff]) => {
    const d = (diff ?? {}) as { from?: unknown; to?: unknown }
    return { field, label: t(`tasks.fields.${field}`), from: historyValue(field, d.from), to: historyValue(field, d.to) }
  })
}

function historyText(h: ProjectTaskHistory): string {
  const changes = (h.changes ?? {}) as Record<string, unknown>
  switch (h.action) {
    case 'updated':
      return t('tasks.actions.updated', { fields: Object.keys(changes).map((f) => t(`tasks.fields.${f}`)).join(', ') })
    case 'attachment_added':
    case 'attachment_removed':
      return t(`tasks.actions.${h.action}`, { name: String(changes.name ?? '') })
    default:
      return t(`tasks.actions.${h.action}`)
  }
}

onMounted(() => {
  fetchTask()
  fetchOptions()
})
watch(taskId, fetchTask)
</script>

<template>
  <div class="page task-detail">
    <div v-if="loading && !task" class="loading">
      <span class="spinner" role="status"></span>
    </div>

    <div v-else-if="notFound || !task" class="empty card">
      <p class="empty__title">{{ $t('tasks.notFound') }}</p>
      <p class="empty__text">{{ $t('tasks.notFoundText') }}</p>
      <RouterLink class="btn btn--secondary btn--sm" :to="{ name: 'tasks' }">
        <ArrowLeftIcon class="btn__icon" aria-hidden="true" />
        {{ $t('tasks.backToTasks') }}
      </RouterLink>
    </div>

    <template v-else>
      <nav class="task-detail__crumbs" :aria-label="$t('tasks.parentTask')">
        <RouterLink class="task-detail__crumb" :to="{ name: 'tasks' }">{{ $t('tasks.title') }}</RouterLink>
        <span class="task-detail__crumb-sep" aria-hidden="true">/</span>
        <RouterLink v-if="task.project" class="task-detail__crumb" :to="{ name: 'project-detail', params: { id: task.project.id } }">
          <span class="color-dot" :style="{ backgroundColor: task.project.color }" aria-hidden="true"></span>
          {{ task.project.name }}
        </RouterLink>
        <template v-for="a in task.ancestors ?? []" :key="a.id">
          <span class="task-detail__crumb-sep" aria-hidden="true">/</span>
          <RouterLink class="task-detail__crumb" :to="{ name: 'task-detail', params: { id: a.id } }">{{ a.title }}</RouterLink>
        </template>
      </nav>

      <div class="page__header task-detail__header">
        <div class="task-detail__ident">
          <button
            type="button"
            class="task-row__check task-detail__check"
            :class="{ 'task-row__check--done': task.is_completed }"
            :aria-label="task.is_completed ? $t('tasks.markOpen') : $t('tasks.markDone')"
            :aria-pressed="task.is_completed"
            :disabled="toggling"
            @click="toggleCompleted(task, !task.is_completed)"
          >
            <CheckIcon class="task-row__check-icon" aria-hidden="true" />
          </button>
          <div class="task-detail__titles">
            <input
              v-if="editingTitle"
              ref="titleInput"
              v-model="titleDraft"
              type="text"
              class="heading-1 task-detail__title-input"
              :aria-label="$t('tasks.fields.title')"
              @keydown.enter.prevent="saveTitle"
              @keydown.esc.prevent="editingTitle = false"
              @blur="saveTitle"
            />
            <h1
              v-else
              class="heading-1 task-detail__title task-detail__editable"
              :class="{ 'task-detail__title--done': task.is_completed }"
              :title="$t('tasks.clickToEdit')"
              tabindex="0"
              @click="startEditTitle"
              @keydown.enter.prevent="startEditTitle"
            >
              {{ task.title }}
            </h1>
            <div class="task-detail__badges">
              <span class="badge" :class="priorityBadgeClass(task.priority)">{{ $t(`tasks.priorities.${task.priority}`) }}</span>
              <span v-if="task.status" class="badge" :style="{ backgroundColor: task.status.color + '22', color: task.status.color }">{{ task.status.name }}</span>
              <span v-if="task.is_completed && task.completed_at" class="badge badge--success">{{ $t('tasks.completedAt', { date: formatDate(task.completed_at) }) }}</span>
              <span v-else-if="task.is_overdue" class="badge badge--danger">{{ $t('tasks.overdue') }}</span>
              <span v-if="task.creator" class="task-detail__creator">{{ $t('tasks.createdBy', { name: task.creator.name }) }}</span>
            </div>
          </div>
        </div>
        <div class="page__actions">
          <button type="button" class="btn btn--primary" @click="showSubtaskForm = true">
            <PlusIcon class="btn__icon" aria-hidden="true" />
            {{ $t('tasks.newSubtask') }}
          </button>
          <button v-if="canDeleteTask" type="button" class="btn btn--danger-ghost" @click="showDelete = true">
            <TrashIcon class="btn__icon" aria-hidden="true" />
            {{ $t('common.delete') }}
          </button>
        </div>
      </div>

      <div class="task-detail__layout">
        <div class="task-detail__main">
          <section class="card page__section">
            <div class="card__header">
              <h2 class="card__title">{{ $t('tasks.description') }}</h2>
              <button v-if="!editingDescription" type="button" class="btn btn--ghost btn--sm" @click="startEditDescription">
                <PencilSquareIcon class="btn__icon" aria-hidden="true" />
                {{ $t('common.edit') }}
              </button>
            </div>
            <div class="card__body">
              <form v-if="editingDescription" class="form" @submit.prevent="saveDescription">
                <textarea v-model="descriptionDraft" class="form__textarea" rows="6" autofocus :aria-label="$t('tasks.description')" @keydown.esc.prevent="editingDescription = false"></textarea>
                <div class="form__actions">
                  <button type="button" class="btn btn--secondary btn--sm" @click="editingDescription = false">{{ $t('common.cancel') }}</button>
                  <button type="submit" class="btn btn--primary btn--sm" :disabled="saving">{{ $t('common.save') }}</button>
                </div>
              </form>
              <p v-else-if="task.description" class="task-detail__description task-detail__editable" :title="$t('tasks.clickToEdit')" @click="startEditDescription">{{ task.description }}</p>
              <p v-else class="muted task-detail__editable" :title="$t('tasks.clickToEdit')" @click="startEditDescription">{{ $t('tasks.noDescription') }}</p>
            </div>
          </section>

          <section class="card page__section">
            <div class="card__header">
              <h2 class="card__title">{{ $t('tasks.subtasks') }}</h2>
              <div class="task-detail__subtask-actions">
                <span v-if="children.length" class="toolbar__count">{{ $t('tasks.subtasksProgress', { done: childrenDone, total: children.length }) }}</span>
                <button type="button" class="btn btn--secondary btn--sm" @click="showSubtaskForm = true">
                  <PlusIcon class="btn__icon" aria-hidden="true" />
                  {{ $t('tasks.newSubtask') }}
                </button>
              </div>
            </div>
            <div class="card__body card__body--flush">
              <div v-if="children.length === 0" class="empty">
                <p class="empty__text">{{ $t('tasks.noSubtasks') }}</p>
              </div>
              <ul v-else class="task-list">
                <TaskTree v-for="child in children" :key="child.id" :task="child" :busy-id="busyChildId" @toggle="toggleCompleted" />
              </ul>
            </div>
          </section>

          <section class="card page__section">
            <div class="card__header">
              <h2 class="card__title">{{ $t('tasks.comments') }}</h2>
              <span v-if="task.comments?.length" class="toolbar__count">{{ task.comments.length }}</span>
            </div>
            <div class="card__body">
              <p v-if="!task.comments?.length" class="muted task-detail__none">{{ $t('tasks.noComments') }}</p>
              <ul v-else class="comments">
                <li v-for="c in task.comments" :key="c.id" class="comment">
                  <span class="comment__avatar" aria-hidden="true">{{ c.user?.name?.charAt(0) ?? '?' }}</span>
                  <div class="comment__main">
                    <div class="comment__head">
                      <span class="comment__author">{{ c.user?.name ?? $t('tasks.someone') }}</span>
                      <span class="comment__time">{{ formatDate(c.created_at) }} {{ formatTime(c.created_at) }}</span>
                      <div v-if="canEditComment(c) && editingComment?.id !== c.id" class="comment__actions">
                        <button type="button" class="btn btn--ghost btn--icon btn--sm" :aria-label="$t('tasks.editComment')" :title="$t('common.edit')" @click="startEditComment(c)">
                          <PencilSquareIcon class="btn__icon" aria-hidden="true" />
                        </button>
                        <button type="button" class="btn btn--danger-ghost btn--icon btn--sm" :aria-label="$t('tasks.deleteComment')" :title="$t('common.delete')" @click="deleteCommentTarget = c">
                          <TrashIcon class="btn__icon" aria-hidden="true" />
                        </button>
                      </div>
                    </div>
                    <form v-if="editingComment?.id === c.id" class="form comment__edit" @submit.prevent="saveComment">
                      <textarea v-model="editingBody" class="form__textarea" rows="3" :aria-label="$t('tasks.editComment')"></textarea>
                      <div class="form__actions">
                        <button type="button" class="btn btn--secondary btn--sm" @click="editingComment = null">{{ $t('common.cancel') }}</button>
                        <button type="submit" class="btn btn--primary btn--sm" :disabled="commentSaving || !editingBody.trim()">{{ $t('common.save') }}</button>
                      </div>
                    </form>
                    <p v-else class="comment__body">{{ c.body }}</p>
                  </div>
                </li>
              </ul>

              <form class="form comment-form" @submit.prevent="addComment">
                <label class="sr-only" for="task-comment">{{ $t('tasks.addComment') }}</label>
                <textarea id="task-comment" v-model="commentBody" class="form__textarea" rows="3" :placeholder="$t('tasks.commentPlaceholder')"></textarea>
                <div class="form__actions">
                  <button type="submit" class="btn btn--primary btn--sm" :disabled="commentSaving || !commentBody.trim()">
                    <ChatBubbleLeftIcon class="btn__icon" aria-hidden="true" />
                    {{ $t('tasks.addComment') }}
                  </button>
                </div>
              </form>
            </div>
          </section>
        </div>

        <aside class="task-detail__side">
          <section class="card page__section">
            <div class="card__body">
              <div class="task-meta form" :class="{ 'is-loading': saving }">
                <div class="task-meta__item">
                  <label class="task-meta__label" for="task-status">{{ $t('tasks.status') }}</label>
                  <ComboBox
                    id="task-status"
                    :model-value="task.status_id ?? ''"
                    :options="statusOptions"
                    :placeholder="$t('tasks.noStatus')"
                    :clear-label="$t('tasks.noStatus')"
                    clearable
                    allow-create
                    size="sm"
                    @update:model-value="setStatus"
                    @create="createStatus"
                  />
                </div>
                <div class="task-meta__item">
                  <label class="task-meta__label" for="task-priority">{{ $t('tasks.priority') }}</label>
                  <select id="task-priority" class="form__select form__select--sm" :value="task.priority" @change="setPriority">
                    <option v-for="p in TASK_PRIORITIES" :key="p" :value="p">{{ $t(`tasks.priorities.${p}`) }}</option>
                  </select>
                </div>
                <div class="task-meta__item">
                  <label class="task-meta__label" for="task-assignee">{{ $t('tasks.assignee') }}</label>
                  <ComboBox
                    id="task-assignee"
                    :model-value="task.assignee_id ?? ''"
                    :options="userOptions"
                    :placeholder="$t('tasks.noAssignee')"
                    :clear-label="$t('tasks.noAssignee')"
                    clearable
                    size="sm"
                    @update:model-value="setAssignee"
                  />
                </div>
                <div class="task-meta__item">
                  <label class="task-meta__label" for="task-deadline">{{ $t('tasks.deadline') }}</label>
                  <input id="task-deadline" type="date" class="form__input form__input--sm" :class="{ 'task-meta__input--danger': task.is_overdue }" :value="task.deadline ?? ''" @change="setDate('deadline', ($event.target as HTMLInputElement).value)" />
                  <div class="task-meta__chips">
                    <button v-for="n in dayChips" :key="n" type="button" class="task-meta__chip" @click="inDays('deadline', n)">{{ $t('tasks.inDays', n) }}</button>
                  </div>
                </div>
                <div class="task-meta__item">
                  <label class="task-meta__label" for="task-reminder">
                    {{ $t('tasks.reminder') }}
                    <span v-if="task.reminder_at" class="task-meta__hint">({{ task.reminder_sent_at ? $t('tasks.reminderSent') : $t('tasks.reminderPending') }})</span>
                  </label>
                  <input id="task-reminder" type="date" class="form__input form__input--sm" :value="task.reminder_at ?? ''" @change="setDate('reminder_at', ($event.target as HTMLInputElement).value)" />
                  <div class="task-meta__chips">
                    <button v-for="n in dayChips" :key="n" type="button" class="task-meta__chip" @click="inDays('reminder_at', n)">{{ $t('tasks.inDays', n) }}</button>
                  </div>
                </div>
                <div class="task-meta__item">
                  <span class="task-meta__label">{{ $t('tasks.estimate') }}</span>
                  <div class="task-meta__estimate">
                    <label class="task-meta__unit">
                      <input v-model.number="estimateHours" type="number" min="0" step="1" inputmode="numeric" class="form__input form__input--sm" placeholder="0" :aria-label="$t('tasks.estimateHours')" @change="saveEstimate" />
                      <span>h</span>
                    </label>
                    <label class="task-meta__unit">
                      <input v-model.number="estimateMinutes" type="number" min="0" max="59" step="5" inputmode="numeric" class="form__input form__input--sm" placeholder="0" :aria-label="$t('tasks.estimateMinutes')" @change="saveEstimate" />
                      <span>min</span>
                    </label>
                  </div>
                </div>
                <div class="task-meta__item">
                  <label class="task-meta__label" for="task-budget">{{ $t('tasks.budget') }}</label>
                  <input id="task-budget" v-model.number="budgetDraft" type="number" min="0" step="0.01" inputmode="decimal" class="form__input form__input--sm" :placeholder="task.calculated_budget != null ? formatCurrency(task.calculated_budget) : $t('tasks.noBudget')" @change="saveBudget" />
                  <p v-if="task.budget == null && task.calculated_budget != null" class="task-meta__hint">
                    {{ $t('tasks.calculatedBudget', { amount: formatCurrency(task.calculated_budget), estimate: estimate ?? '', rate: formatCurrency(task.calculated_rate ?? 0) }) }}
                  </p>
                  <p v-else-if="task.budget == null" class="task-meta__hint">{{ $t('tasks.calculatedBudgetNone') }}</p>
                </div>
                <div class="task-meta__item">
                  <span class="task-meta__label">{{ $t('tasks.tags') }}</span>
                  <div v-if="tags.length" class="task-form__tags">
                    <button
                      v-for="tag in tags"
                      :key="tag.id"
                      type="button"
                      class="task-form__tag"
                      :class="{ 'task-form__tag--active': hasTag(tag.id) }"
                      :aria-pressed="hasTag(tag.id)"
                      @click="toggleTag(tag.id)"
                    >
                      <span class="color-dot" :style="{ backgroundColor: tag.color }" aria-hidden="true"></span>
                      {{ tag.name }}
                    </button>
                  </div>
                  <span v-else class="task-meta__value--muted">{{ $t('tasks.noTags') }}</span>
                </div>
              </div>
            </div>
          </section>

          <section class="card page__section">
            <div class="card__header">
              <h2 class="card__title">{{ $t('tasks.attachments') }}</h2>
              <button type="button" class="btn btn--secondary btn--sm" :disabled="uploading" @click="fileInput?.click()">
                <PaperClipIcon class="btn__icon" aria-hidden="true" />
                {{ uploading ? $t('tasks.uploading') : $t('tasks.upload') }}
              </button>
              <input ref="fileInput" type="file" multiple class="sr-only" :aria-label="$t('tasks.upload')" @change="onFileChosen" />
            </div>
            <div
              class="card__body dropzone"
              :class="{ 'dropzone--active': dragging, 'dropzone--busy': uploading }"
              @dragenter.prevent="dragging = true"
              @dragover.prevent="dragging = true"
              @dragleave.self="dragging = false"
              @drop.prevent="onDrop"
            >
              <p v-if="!task.attachments?.length" class="muted task-detail__none">{{ $t('tasks.noAttachments') }}</p>
              <ul v-else class="attachments">
                <li v-for="a in task.attachments" :key="a.id" class="attachment">
                  <button type="button" class="attachment__name" :title="$t('tasks.download')" @click="download(a)">
                    <ArrowDownTrayIcon class="attachment__icon" aria-hidden="true" />
                    <span class="attachment__label">{{ a.original_name }}</span>
                  </button>
                  <span class="attachment__meta">{{ formatFileSize(a.size) }}<template v-if="a.user"> · {{ a.user.name }}</template></span>
                  <button v-if="canDeleteAttachment(a)" type="button" class="btn btn--danger-ghost btn--icon btn--sm" :aria-label="$t('tasks.deleteAttachment')" @click="deleteAttachmentTarget = a">
                    <TrashIcon class="btn__icon" aria-hidden="true" />
                  </button>
                </li>
              </ul>
              <p class="dropzone__hint">{{ dragging ? $t('tasks.dropNow') : $t('tasks.dropHint') }}</p>
            </div>
          </section>

          <section class="card page__section">
            <div class="card__header">
              <h2 class="card__title">{{ $t('tasks.history') }}</h2>
            </div>
            <div class="card__body">
              <p v-if="!task.history?.length" class="muted task-detail__none">{{ $t('tasks.noHistory') }}</p>
              <ol v-else class="history">
                <li v-for="h in task.history" :key="h.id" class="history__item">
                  <ClockIcon class="history__icon" aria-hidden="true" />
                  <div class="history__main">
                    <p class="history__text">
                      <span class="history__user">{{ h.user?.name ?? $t('tasks.someone') }}</span>
                      {{ historyText(h) }}
                    </p>
                    <ul v-if="changeLines(h).length" class="history__changes">
                      <li v-for="line in changeLines(h)" :key="line.field" class="history__change">
                        <span class="history__field">{{ line.label }}:</span>
                        <span class="history__from">{{ line.from }}</span>
                        <span class="history__to">{{ line.to }}</span>
                      </li>
                    </ul>
                    <span class="history__time">{{ formatDate(h.created_at) }} {{ formatTime(h.created_at) }}</span>
                  </div>
                </li>
              </ol>
            </div>
          </section>
        </aside>
      </div>
    </template>

    <TaskFormModal v-if="showSubtaskForm && task" :task="null" :parent="parentForSubtask" @close="showSubtaskForm = false" @saved="onSubtaskSaved" />

    <ConfirmDialog
      v-if="showDelete && task"
      :title="$t('tasks.deleteTask')"
      :text="$t('tasks.deleteTaskText', { title: task.title })"
      :confirm-label="$t('common.delete')"
      danger
      :busy="deleting"
      @confirm="deleteTask"
      @cancel="showDelete = false"
    />
    <ConfirmDialog
      v-if="deleteCommentTarget"
      :title="$t('tasks.deleteComment')"
      :text="$t('tasks.deleteCommentText')"
      :confirm-label="$t('common.delete')"
      danger
      :busy="commentDeleting"
      @confirm="deleteComment"
      @cancel="deleteCommentTarget = null"
    />
    <ConfirmDialog
      v-if="deleteAttachmentTarget"
      :title="$t('tasks.deleteAttachment')"
      :text="$t('tasks.deleteAttachmentText', { name: deleteAttachmentTarget.original_name })"
      :confirm-label="$t('common.delete')"
      danger
      :busy="attachmentDeleting"
      @confirm="deleteAttachment"
      @cancel="deleteAttachmentTarget = null"
    />
  </div>
</template>
