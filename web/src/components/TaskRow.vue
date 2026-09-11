<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import { CalendarIcon, ChatBubbleLeftIcon, PaperClipIcon, CheckIcon, ChevronRightIcon } from '@heroicons/vue/24/outline'
import type { ProjectTask } from '@/types'
import { priorityBadgeClass, formatEstimate } from '@/utils/tasks'
import { formatDate } from '@/utils/format'
import UserAvatar from '@/components/UserAvatar.vue'

const props = withDefaults(defineProps<{
  task: ProjectTask
  showProject?: boolean
  busy?: boolean
  /** Nesting depth inside a subtask tree. */
  depth?: number
  expandable?: boolean
  expanded?: boolean
  loading?: boolean
}>(), { showProject: false, busy: false, depth: 0, expandable: false, expanded: false, loading: false })

const emit = defineEmits<{
  (e: 'toggle', task: ProjectTask, completed: boolean): void
  (e: 'expand', task: ProjectTask): void
}>()

const estimate = computed(() => formatEstimate(props.task.estimate_minutes))
const childTotal = computed(() => props.task.children_count ?? 0)
const childOpen = computed(() => props.task.open_children_count ?? 0)
const indent = computed(() => ({ paddingLeft: `calc(var(--gap) + ${props.depth} * 1.75rem)` }))
</script>

<template>
  <li class="task-row" :class="{ 'task-row--done': task.is_completed, 'task-row--nested': depth > 0 }" :style="indent">
    <button
      v-if="expandable"
      type="button"
      class="task-row__expand"
      :class="{ 'task-row__expand--open': expanded, 'task-row__expand--loading': loading }"
      :aria-expanded="expanded"
      :aria-label="expanded ? $t('tasks.collapse') : $t('tasks.expand')"
      @click="emit('expand', task)"
    >
      <ChevronRightIcon class="task-row__expand-icon" aria-hidden="true" />
    </button>
    <span v-else class="task-row__expand task-row__expand--placeholder" aria-hidden="true"></span>

    <button
      type="button"
      class="task-row__check"
      :class="{ 'task-row__check--done': task.is_completed }"
      :aria-label="task.is_completed ? $t('tasks.markOpen') : $t('tasks.markDone')"
      :aria-pressed="task.is_completed"
      :disabled="busy"
      @click="emit('toggle', task, !task.is_completed)"
    >
      <CheckIcon class="task-row__check-icon" aria-hidden="true" />
    </button>

    <div class="task-row__main">
      <div class="task-row__head">
        <span v-if="task.is_due_today_alert" class="due-dot" :title="$t('tasks.dueTodayAlert')" role="img" :aria-label="$t('tasks.dueTodayAlert')"></span>
        <RouterLink class="task-row__title" :to="{ name: 'task-detail', params: { id: task.id } }" :aria-label="$t('tasks.openTask', { title: task.title })">
          {{ task.title }}
        </RouterLink>
        <span v-if="task.status" class="badge task-row__status" :style="{ backgroundColor: task.status.color + '22', color: task.status.color }">
          {{ task.status.name }}
        </span>
        <span v-if="task.project_status" class="badge task-row__status" :title="$t('projectStatus.label')" :style="{ backgroundColor: task.project_status.color + '22', color: task.project_status.color }">
          {{ task.project_status.name }}
        </span>
      </div>
      <div class="task-row__meta">
        <RouterLink
          v-if="showProject && task.project"
          class="task-row__project"
          :to="{ name: 'project-detail', params: { id: task.project.id } }"
          :title="task.project.client?.name"
        >
          <span class="color-dot" :style="{ backgroundColor: task.project.color }" aria-hidden="true"></span>
          {{ task.project.name }}
        </RouterLink>
        <span v-if="task.deadline" class="task-row__deadline" :class="{ 'task-row__deadline--overdue': task.is_overdue }">
          <CalendarIcon class="task-row__meta-icon" aria-hidden="true" />
          {{ formatDate(task.deadline) }}
        </span>
        <span v-if="estimate" class="task-row__estimate">{{ estimate }}</span>
        <span v-if="childTotal && !expanded" class="task-row__children">
          <template v-if="task.earliest_child_deadline">
            {{ $t('tasks.childrenSummary', { count: childTotal, open: childOpen, date: formatDate(task.earliest_child_deadline) }) }}
          </template>
          <template v-else>
            {{ $t('tasks.childrenSummaryNoDeadline', { count: childTotal, open: childOpen }) }}
          </template>
        </span>
        <span v-else-if="childTotal" class="task-row__children">{{ $t('tasks.subtasksProgress', { done: childTotal - childOpen, total: childTotal }) }}</span>
        <span v-if="task.comments_count" class="task-row__count">
          <ChatBubbleLeftIcon class="task-row__meta-icon" aria-hidden="true" />{{ task.comments_count }}
        </span>
        <span v-if="task.attachments_count" class="task-row__count">
          <PaperClipIcon class="task-row__meta-icon" aria-hidden="true" />{{ task.attachments_count }}
        </span>
        <span v-for="tag in task.tags ?? []" :key="tag.id" class="task-row__tag">
          <span class="color-dot" :style="{ backgroundColor: tag.color }" aria-hidden="true"></span>{{ tag.name }}
        </span>
      </div>
    </div>

    <div class="task-row__side">
      <span class="badge" :class="priorityBadgeClass(task.priority)">{{ $t(`tasks.priorities.${task.priority}`) }}</span>
      <span v-if="task.assignee" class="task-row__assignee" :title="task.assignee.name">
        <UserAvatar :name="task.assignee.name" :avatar-url="task.assignee.avatar_url" size="sm" />
        <span class="task-row__assignee-name">{{ task.assignee.name }}</span>
      </span>
    </div>
  </li>
</template>
