<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import { CalendarIcon, ChatBubbleLeftIcon, PaperClipIcon, CheckIcon } from '@heroicons/vue/24/outline'
import type { ProjectTask } from '@/types'
import { priorityBadgeClass, formatEstimate } from '@/utils/tasks'
import { formatDate } from '@/utils/format'

const props = withDefaults(defineProps<{
  task: ProjectTask
  showProject?: boolean
  busy?: boolean
}>(), { showProject: false, busy: false })

const emit = defineEmits<{ (e: 'toggle', task: ProjectTask, completed: boolean): void }>()

const estimate = computed(() => formatEstimate(props.task.estimate_minutes))
const childTotal = computed(() => props.task.children_count ?? 0)
const childOpen = computed(() => props.task.open_children_count ?? 0)
</script>

<template>
  <li class="task-row" :class="{ 'task-row--done': task.is_completed }">
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
      <RouterLink class="task-row__title" :to="{ name: 'task-detail', params: { id: task.id } }" :aria-label="$t('tasks.openTask', { title: task.title })">
        {{ task.title }}
      </RouterLink>
      <div class="task-row__meta">
        <span v-if="showProject && task.project" class="task-row__project">
          <span class="color-dot" :style="{ backgroundColor: task.project.color }" aria-hidden="true"></span>
          {{ task.project.name }}
        </span>
        <span v-if="task.deadline" class="task-row__deadline" :class="{ 'task-row__deadline--overdue': task.is_overdue }">
          <CalendarIcon class="task-row__meta-icon" aria-hidden="true" />
          {{ formatDate(task.deadline) }}
        </span>
        <span v-if="estimate" class="task-row__estimate">{{ estimate }}</span>
        <span v-if="childTotal" class="task-row__children">{{ $t('tasks.subtasksProgress', { done: childTotal - childOpen, total: childTotal }) }}</span>
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
        <span class="task-row__avatar" aria-hidden="true">{{ task.assignee.name.charAt(0) }}</span>
        <span class="task-row__assignee-name">{{ task.assignee.name }}</span>
      </span>
    </div>
  </li>
</template>
