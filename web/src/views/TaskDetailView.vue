<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import api from '@/api/client'
import { downloadFile } from '@/api/download'
import type { ProjectTask, ProjectTaskComment, ProjectTaskAttachment, ProjectTaskHistory, User } from '@/types'
import { useAuthStore } from '@/stores/auth'
import { useToastStore, errorMessage } from '@/stores/toast'
import TaskRow from '@/components/TaskRow.vue'
import TaskFormModal from '@/components/TaskFormModal.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
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
import { priorityBadgeClass, formatEstimate, formatFileSize } from '@/utils/tasks'
import { formatDate, formatCurrency, formatTime } from '@/utils/format'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const toast = useToastStore()

const task = ref<ProjectTask | null>(null)
const loading = ref(true)
const notFound = ref(false)
const users = ref<User[]>([])

const showEdit = ref(false)
const showSubtaskForm = ref(false)
const showDelete = ref(false)
const deleting = ref(false)
const toggling = ref(false)
const busyChildId = ref<string | null>(null)

const commentBody = ref('')
const commentSaving = ref(false)
const editingComment = ref<ProjectTaskComment | null>(null)
const editingBody = ref('')
const deleteCommentTarget = ref<ProjectTaskComment | null>(null)
const commentDeleting = ref(false)

const fileInput = ref<HTMLInputElement | null>(null)
const uploading = ref(false)
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

async function fetchTask() {
  loading.value = true
  notFound.value = false
  try {
    const { data } = await api.get(`/project-tasks/${taskId.value}`)
    task.value = data.data
  } catch (e) {
    task.value = null
    notFound.value = true
    const status = (e as { response?: { status?: number } })?.response?.status
    if (status !== 404) toast.error(errorMessage(e, t('common.loadFailed')))
  } finally {
    loading.value = false
  }
}

async function fetchUsers() {
  try {
    const { data } = await api.get('/users')
    users.value = data.data
  } catch {
    // Names in the history fall back to ids.
  }
}

async function toggleCompleted(target: ProjectTask, completed: boolean) {
  const isSelf = target.id === task.value?.id
  if (isSelf) toggling.value = true
  else busyChildId.value = target.id
  try {
    await api.put(`/project-tasks/${target.id}`, { completed })
    await fetchTask()
  } catch (e) {
    toast.error(errorMessage(e, t('common.failedToSave')))
  } finally {
    toggling.value = false
    busyChildId.value = null
  }
}

function onEdited() {
  showEdit.value = false
  fetchTask()
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

async function onFileChosen(event: Event) {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file || !task.value) return
  uploading.value = true
  try {
    const form = new FormData()
    form.append('file', file)
    await api.post(`/project-tasks/${task.value.id}/attachments`, form, { headers: { 'Content-Type': 'multipart/form-data' } })
    toast.success(t('tasks.attachmentAdded'))
    await fetchTask()
  } catch (e) {
    toast.error(errorMessage(e, t('common.failedToSave')))
  } finally {
    uploading.value = false
    input.value = ''
  }
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

function historyValue(field: string, value: unknown): string {
  if (value === null || value === undefined || value === '') return t('tasks.empty')
  switch (field) {
    case 'assignee_id':
      return userName(value)
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
    case 'parent_id':
      return String(value)
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
    return {
      field,
      label: t(`tasks.fields.${field}`),
      from: historyValue(field, d.from),
      to: historyValue(field, d.to),
    }
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
  fetchUsers()
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
            <h1 class="heading-1 task-detail__title" :class="{ 'task-detail__title--done': task.is_completed }">{{ task.title }}</h1>
            <div class="task-detail__badges">
              <span class="badge" :class="priorityBadgeClass(task.priority)">{{ $t(`tasks.priorities.${task.priority}`) }}</span>
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
          <button type="button" class="btn btn--secondary" @click="showEdit = true">
            <PencilSquareIcon class="btn__icon" aria-hidden="true" />
            {{ $t('common.edit') }}
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
            </div>
            <div class="card__body">
              <p v-if="task.description" class="task-detail__description">{{ task.description }}</p>
              <p v-else class="muted">{{ $t('tasks.noDescription') }}</p>
            </div>
          </section>

          <section class="card page__section">
            <div class="card__header">
              <h2 class="card__title">{{ $t('tasks.subtasks') }}</h2>
              <span v-if="children.length" class="toolbar__count">{{ $t('tasks.subtasksProgress', { done: childrenDone, total: children.length }) }}</span>
            </div>
            <div class="card__body card__body--flush">
              <div v-if="children.length === 0" class="empty">
                <p class="empty__text">{{ $t('tasks.noSubtasks') }}</p>
                <button type="button" class="btn btn--secondary btn--sm" @click="showSubtaskForm = true">
                  <PlusIcon class="btn__icon" aria-hidden="true" />
                  {{ $t('tasks.newSubtask') }}
                </button>
              </div>
              <ul v-else class="task-list">
                <TaskRow v-for="child in children" :key="child.id" :task="child" :busy="busyChildId === child.id" @toggle="toggleCompleted" />
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
              <dl class="task-meta">
                <div class="task-meta__item">
                  <dt class="task-meta__label">{{ $t('tasks.assignee') }}</dt>
                  <dd class="task-meta__value" :class="{ 'task-meta__value--muted': !task.assignee }">{{ task.assignee?.name ?? $t('tasks.noAssignee') }}</dd>
                </div>
                <div class="task-meta__item">
                  <dt class="task-meta__label">{{ $t('tasks.deadline') }}</dt>
                  <dd class="task-meta__value" :class="{ 'task-meta__value--muted': !task.deadline, 'task-meta__value--danger': task.is_overdue }">
                    {{ task.deadline ? formatDate(task.deadline) : $t('tasks.noDeadline') }}
                  </dd>
                </div>
                <div class="task-meta__item">
                  <dt class="task-meta__label">{{ $t('tasks.reminder') }}</dt>
                  <dd class="task-meta__value" :class="{ 'task-meta__value--muted': !task.reminder_at }">
                    <template v-if="task.reminder_at">
                      {{ formatDate(task.reminder_at) }}
                      <span class="task-meta__hint">({{ task.reminder_sent_at ? $t('tasks.reminderSent') : $t('tasks.reminderPending') }})</span>
                    </template>
                    <template v-else>{{ $t('tasks.noReminder') }}</template>
                  </dd>
                </div>
                <div class="task-meta__item">
                  <dt class="task-meta__label">{{ $t('tasks.estimate') }}</dt>
                  <dd class="task-meta__value" :class="{ 'task-meta__value--muted': !estimate }">{{ estimate ?? $t('tasks.noEstimate') }}</dd>
                </div>
                <div class="task-meta__item">
                  <dt class="task-meta__label">{{ $t('tasks.budget') }}</dt>
                  <dd class="task-meta__value" :class="{ 'task-meta__value--muted': task.budget == null }">
                    {{ task.budget != null ? formatCurrency(Number(task.budget)) : $t('tasks.noBudget') }}
                  </dd>
                </div>
                <div class="task-meta__item">
                  <dt class="task-meta__label">{{ $t('tasks.tags') }}</dt>
                  <dd class="task-meta__value">
                    <span v-if="!task.tags?.length" class="task-meta__value--muted">{{ $t('tasks.noTags') }}</span>
                    <span v-for="tag in task.tags" :key="tag.id" class="task-row__tag">
                      <span class="color-dot" :style="{ backgroundColor: tag.color }" aria-hidden="true"></span>{{ tag.name }}
                    </span>
                  </dd>
                </div>
              </dl>
            </div>
          </section>

          <section class="card page__section">
            <div class="card__header">
              <h2 class="card__title">{{ $t('tasks.attachments') }}</h2>
              <button type="button" class="btn btn--secondary btn--sm" :disabled="uploading" @click="fileInput?.click()">
                <PaperClipIcon class="btn__icon" aria-hidden="true" />
                {{ uploading ? $t('tasks.uploading') : $t('tasks.upload') }}
              </button>
              <input ref="fileInput" type="file" class="sr-only" :aria-label="$t('tasks.upload')" @change="onFileChosen" />
            </div>
            <div class="card__body">
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
              <p class="form__hint">{{ $t('tasks.maxSize') }}</p>
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

    <TaskFormModal v-if="showEdit && task" :task="task" @close="showEdit = false" @saved="onEdited" />
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
