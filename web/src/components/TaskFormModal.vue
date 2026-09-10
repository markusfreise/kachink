<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '@/api/client'
import type { ProjectTask, Project, User, Tag, TaskPriority } from '@/types'
import BaseModal from '@/components/BaseModal.vue'
import ComboBox from '@/components/ComboBox.vue'
import { useToastStore, errorMessage } from '@/stores/toast'
import { TASK_PRIORITIES, splitEstimate, joinEstimate } from '@/utils/tasks'

const props = defineProps<{
  /** Task to edit; null creates a new one. */
  task: ProjectTask | null
  /** Preselected project for new tasks (locked when a parent is given or when editing). */
  projectId?: string | null
  /** Creates a subtask below this task. */
  parent?: { id: string; title: string; project_id: string } | null
}>()

const emit = defineEmits<{
  close: []
  saved: [task: ProjectTask]
}>()

const { t } = useI18n()
const toast = useToastStore()

const projects = ref<Project[]>([])
const users = ref<User[]>([])
const tags = ref<Tag[]>([])
const optionsLoading = ref(true)

const title = ref('')
const description = ref('')
const projectIdModel = ref(props.parent?.project_id ?? props.projectId ?? '')
const assigneeId = ref('')
const priority = ref<TaskPriority>('soon')
const estimateHours = ref<number | null>(null)
const estimateMinutes = ref<number | null>(null)
const budget = ref<number | null>(null)
const deadline = ref('')
const reminderAt = ref('')
const tagIds = ref<string[]>([])
const saving = ref(false)
const error = ref('')

const isEdit = computed(() => props.task !== null)
const projectLocked = computed(() => isEdit.value || !!props.parent)
const modalTitle = computed(() => {
  if (isEdit.value) return t('tasks.editTask')
  return props.parent ? t('tasks.newSubtask') : t('tasks.newTask')
})

const projectOptions = computed(() =>
  projects.value.map((p) => ({ id: p.id, label: p.name, subtitle: p.client?.name, color: p.color })),
)
const userOptions = computed(() => users.value.map((u) => ({ id: u.id, label: u.name })))

onMounted(async () => {
  if (props.task) {
    title.value = props.task.title
    description.value = props.task.description ?? ''
    projectIdModel.value = props.task.project_id
    assigneeId.value = props.task.assignee_id ?? ''
    priority.value = props.task.priority
    const est = splitEstimate(props.task.estimate_minutes)
    estimateHours.value = est.hours
    estimateMinutes.value = est.minutes
    budget.value = props.task.budget != null ? Number(props.task.budget) : null
    deadline.value = props.task.deadline ?? ''
    reminderAt.value = props.task.reminder_at ?? ''
    tagIds.value = (props.task.tags ?? []).map((tag) => tag.id)
  }

  try {
    const [projectsRes, usersRes, tagsRes] = await Promise.all([
      projectLocked.value
        ? Promise.resolve(null)
        : api.get('/projects', { params: { 'filter[is_active]': 1, per_page: 500, sort: 'name' } }),
      api.get('/users', { params: { 'filter[is_active]': 1 } }),
      api.get('/tags'),
    ])
    if (projectsRes) projects.value = projectsRes.data.data
    users.value = usersRes.data.data
    tags.value = tagsRes.data.data
  } catch (e) {
    error.value = errorMessage(e, t('common.loadFailed'))
  } finally {
    optionsLoading.value = false
  }
})

function toggleTag(id: string) {
  tagIds.value = tagIds.value.includes(id) ? tagIds.value.filter((x) => x !== id) : [...tagIds.value, id]
}

function numberOrNull(v: number | null): number | null {
  return v === null || Number.isNaN(v) ? null : v
}

async function handleSave() {
  error.value = ''
  if (!projectIdModel.value) {
    error.value = t('tasks.selectProject')
    return
  }
  saving.value = true
  try {
    const payload: Record<string, unknown> = {
      title: title.value.trim(),
      description: description.value.trim() || null,
      assignee_id: assigneeId.value || null,
      priority: priority.value,
      estimate_minutes: joinEstimate(estimateHours.value, estimateMinutes.value),
      budget: numberOrNull(budget.value),
      deadline: deadline.value || null,
      reminder_at: reminderAt.value || null,
      tag_ids: tagIds.value,
    }
    let data
    if (props.task) {
      ;({ data } = await api.put(`/project-tasks/${props.task.id}`, payload))
    } else {
      payload.project_id = projectIdModel.value
      if (props.parent) payload.parent_id = props.parent.id
      ;({ data } = await api.post('/project-tasks', payload))
    }
    toast.success(props.task ? t('tasks.updated') : t('tasks.created'))
    emit('saved', data.data as ProjectTask)
  } catch (e) {
    error.value = errorMessage(e, t('common.failedToSave'))
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <BaseModal :title="modalTitle" @close="emit('close')">
    <form id="task-form" class="form task-form" novalidate @submit.prevent="handleSave">
      <div v-if="error" class="form__alert" role="alert">{{ error }}</div>

      <p v-if="parent" class="task-form__parent">
        <span class="task-form__parent-label">{{ $t('tasks.parentTask') }}</span>
        <span class="task-form__parent-title">{{ parent.title }}</span>
      </p>

      <div class="form__group">
        <label class="form__label" for="task-form-title">{{ $t('tasks.titleRequired') }}</label>
        <input id="task-form-title" v-model="title" type="text" class="form__input" required autofocus autocomplete="off" />
      </div>

      <div v-if="!projectLocked" class="form__group">
        <label class="form__label" for="task-form-project">{{ $t('tasks.project') }} *</label>
        <ComboBox
          id="task-form-project"
          v-model="projectIdModel"
          :options="projectOptions"
          :placeholder="$t('tasks.selectProject')"
          :disabled="optionsLoading"
        />
      </div>

      <div class="form__group">
        <label class="form__label" for="task-form-description">{{ $t('tasks.description') }}</label>
        <textarea id="task-form-description" v-model="description" class="form__textarea" rows="4"></textarea>
      </div>

      <div class="form__row">
        <div class="form__group">
          <label class="form__label" for="task-form-assignee">{{ $t('tasks.assignee') }}</label>
          <ComboBox
            id="task-form-assignee"
            v-model="assigneeId"
            :options="userOptions"
            :placeholder="$t('tasks.selectAssignee')"
            :clear-label="$t('tasks.noAssignee')"
            clearable
            :disabled="optionsLoading"
          />
        </div>
        <div class="form__group">
          <label class="form__label" for="task-form-priority">{{ $t('tasks.priority') }}</label>
          <select id="task-form-priority" v-model="priority" class="form__select">
            <option v-for="p in TASK_PRIORITIES" :key="p" :value="p">{{ $t(`tasks.priorities.${p}`) }}</option>
          </select>
        </div>
      </div>

      <div class="form__row task-form__estimate">
        <div class="form__group">
          <label class="form__label" for="task-form-est-h">{{ $t('tasks.estimate') }} ({{ $t('tasks.estimateHours') }})</label>
          <input id="task-form-est-h" v-model.number="estimateHours" type="number" min="0" step="1" inputmode="numeric" class="form__input" placeholder="0" />
        </div>
        <div class="form__group">
          <label class="form__label" for="task-form-est-m">{{ $t('tasks.estimateMinutes') }}</label>
          <input id="task-form-est-m" v-model.number="estimateMinutes" type="number" min="0" max="59" step="5" inputmode="numeric" class="form__input" placeholder="0" />
        </div>
        <div class="form__group">
          <label class="form__label" for="task-form-budget">{{ $t('tasks.budget') }} (EUR)</label>
          <input id="task-form-budget" v-model.number="budget" type="number" min="0" step="0.01" inputmode="decimal" class="form__input" :placeholder="$t('common.optional')" />
        </div>
      </div>

      <div class="form__row">
        <div class="form__group">
          <label class="form__label" for="task-form-deadline">{{ $t('tasks.deadline') }}</label>
          <input id="task-form-deadline" v-model="deadline" type="date" class="form__input" />
        </div>
        <div class="form__group">
          <label class="form__label" for="task-form-reminder">{{ $t('tasks.reminder') }}</label>
          <input id="task-form-reminder" v-model="reminderAt" type="date" class="form__input" />
        </div>
      </div>

      <div v-if="tags.length" class="form__group">
        <span class="form__label">{{ $t('tasks.tags') }}</span>
        <div class="task-form__tags" role="group" :aria-label="$t('tasks.tags')">
          <button
            v-for="tag in tags"
            :key="tag.id"
            type="button"
            class="task-form__tag"
            :class="{ 'task-form__tag--active': tagIds.includes(tag.id) }"
            :aria-pressed="tagIds.includes(tag.id)"
            @click="toggleTag(tag.id)"
          >
            <span class="color-dot" :style="{ backgroundColor: tag.color }" aria-hidden="true"></span>
            {{ tag.name }}
          </button>
        </div>
      </div>
    </form>

    <template #footer>
      <button type="button" class="btn btn--secondary" @click="emit('close')">{{ $t('common.cancel') }}</button>
      <button type="submit" form="task-form" class="btn btn--primary" :disabled="saving || !title.trim()">
        {{ saving ? $t('common.saving') : $t('common.save') }}
      </button>
    </template>
  </BaseModal>
</template>
