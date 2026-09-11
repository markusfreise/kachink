<script setup lang="ts">
import { ref, computed } from 'vue'
import { RouterLink } from 'vue-router'
import { CalendarIcon, ChevronLeftIcon, ChevronRightIcon } from '@heroicons/vue/24/outline'
import type { ProjectTask, User } from '@/types'
import { priorityBadgeClass, formatEstimate } from '@/utils/tasks'
import { formatDate } from '@/utils/format'
import UserAvatar from '@/components/UserAvatar.vue'

/** One kanban column; `id` is the value written into the task's `field` on drop (null = the "none" column). */
export interface BoardColumn {
  key: string
  id: string | null
  name: string
  color: string
  /** Fixed columns cannot be moved. */
  fixed?: boolean
  /** Shown instead of the color dot (assignee board). */
  user?: User | null
}

/**
 * Generic kanban board: one column per value of `field` (work status, project
 * status or assignee). Cards are dragged between and within columns; movable
 * columns can be shifted with the arrow buttons.
 */
const props = withDefaults(defineProps<{
  tasks: ProjectTask[]
  columns: BoardColumn[]
  field: 'status_id' | 'project_status_id' | 'assignee_id'
  busy?: boolean
  showProject?: boolean
}>(), { busy: false, showProject: true })

const emit = defineEmits<{
  (e: 'move', columnId: string | null, orderedIds: string[]): void
  (e: 'reorder-columns', orderedIds: string[]): void
}>()

const filled = computed(() =>
  props.columns.map((column) => ({
    ...column,
    tasks: props.tasks
      .filter((task) => ((task[props.field] as string | null | undefined) ?? null) === column.id)
      .sort((a, b) => a.position - b.position),
  })),
)

type Column = (typeof filled.value)[number]

// ---------------------------------------------------------------- drag and drop

const draggingId = ref<string | null>(null)
const overColumn = ref<string | null>(null)
const overIndex = ref<number | null>(null)

function onDragStart(event: DragEvent, task: ProjectTask) {
  draggingId.value = task.id
  event.dataTransfer?.setData('text/plain', task.id)
  if (event.dataTransfer) event.dataTransfer.effectAllowed = 'move'
}

function onDragEnd() {
  draggingId.value = null
  overColumn.value = null
  overIndex.value = null
}

function onDragOverColumn(event: DragEvent, column: Column) {
  if (!draggingId.value) return
  event.preventDefault()
  if (event.dataTransfer) event.dataTransfer.dropEffect = 'move'
  overColumn.value = column.key
  const list = (event.currentTarget as HTMLElement).querySelectorAll<HTMLElement>('[data-card]')
  let index = list.length
  list.forEach((el, i) => {
    const rect = el.getBoundingClientRect()
    if (index === list.length && event.clientY < rect.top + rect.height / 2) index = i
  })
  overIndex.value = index
}

function onDragLeaveColumn(event: DragEvent) {
  if (event.currentTarget === event.target) {
    overColumn.value = null
    overIndex.value = null
  }
}

function onDrop(event: DragEvent, column: Column) {
  event.preventDefault()
  const id = draggingId.value ?? event.dataTransfer?.getData('text/plain')
  if (!id) return
  const without = column.tasks.map((x) => x.id).filter((x) => x !== id)
  const index = Math.min(overIndex.value ?? without.length, without.length)
  without.splice(index, 0, id)
  emit('move', column.id, without)
  onDragEnd()
}

function placeholderAt(column: Column, index: number) {
  return draggingId.value && overColumn.value === column.key && overIndex.value === index
}

// ---------------------------------------------------------------- column order

const movable = computed(() => filled.value.filter((c) => !c.fixed && c.id))

function moveColumn(column: Column, direction: -1 | 1) {
  const ids = movable.value.map((c) => c.id!)
  const i = ids.indexOf(column.id!)
  const j = i + direction
  if (i < 0 || j < 0 || j >= ids.length) return
  ;[ids[i], ids[j]] = [ids[j]!, ids[i]!]
  emit('reorder-columns', ids)
}
</script>

<template>
  <div class="board" :class="{ 'is-loading': busy }">
    <section
      v-for="column in filled"
      :key="column.key"
      class="board__column"
      :class="{ 'board__column--over': overColumn === column.key }"
      @dragover="onDragOverColumn($event, column)"
      @dragleave="onDragLeaveColumn"
      @drop="onDrop($event, column)"
    >
      <header class="board__head">
        <UserAvatar v-if="column.user" :name="column.user.name" :avatar-url="column.user.avatar_url" size="sm" />
        <span v-else class="color-dot" :style="{ backgroundColor: column.color }" aria-hidden="true"></span>
        <h2 class="board__title">{{ column.name }}</h2>
        <span class="board__count">{{ column.tasks.length }}</span>
        <div v-if="!column.fixed && column.id" class="board__order">
          <button type="button" class="btn btn--ghost btn--icon btn--sm" :aria-label="$t('tasks.moveColumnLeft')" :disabled="movable[0]?.key === column.key" @click="moveColumn(column, -1)">
            <ChevronLeftIcon class="btn__icon" aria-hidden="true" />
          </button>
          <button type="button" class="btn btn--ghost btn--icon btn--sm" :aria-label="$t('tasks.moveColumnRight')" :disabled="movable[movable.length - 1]?.key === column.key" @click="moveColumn(column, 1)">
            <ChevronRightIcon class="btn__icon" aria-hidden="true" />
          </button>
        </div>
      </header>

      <div class="board__cards">
        <template v-for="(task, index) in column.tasks" :key="task.id">
          <div v-if="placeholderAt(column, index)" class="board__placeholder" aria-hidden="true"></div>
          <article
            class="board__card"
            :class="{ 'board__card--dragging': draggingId === task.id }"
            draggable="true"
            data-card
            @dragstart="onDragStart($event, task)"
            @dragend="onDragEnd"
          >
            <div class="board__card-head">
              <span v-if="task.is_due_today_alert" class="due-dot" :title="$t('tasks.dueTodayAlert')" role="img" :aria-label="$t('tasks.dueTodayAlert')"></span>
              <RouterLink class="board__card-title" :to="{ name: 'task-detail', params: { id: task.id } }">{{ task.title }}</RouterLink>
            </div>
            <div class="board__card-meta">
              <RouterLink v-if="showProject && task.project" class="board__card-project" :to="{ name: 'project-detail', params: { id: task.project.id } }">
                <span class="color-dot" :style="{ backgroundColor: task.project.color }" aria-hidden="true"></span>
                {{ task.project.name }}
              </RouterLink>
              <span v-if="task.deadline" class="board__card-deadline" :class="{ 'board__card-deadline--overdue': task.is_overdue }">
                <CalendarIcon class="board__card-icon" aria-hidden="true" />{{ formatDate(task.deadline) }}
              </span>
              <span v-if="formatEstimate(task.estimate_minutes)" class="board__card-estimate">{{ formatEstimate(task.estimate_minutes) }}</span>
              <span v-if="field !== 'status_id' && task.status" class="badge" :style="{ backgroundColor: task.status.color + '22', color: task.status.color }">{{ task.status.name }}</span>
              <span v-if="field !== 'project_status_id' && task.project_status" class="badge" :style="{ backgroundColor: task.project_status.color + '22', color: task.project_status.color }">{{ task.project_status.name }}</span>
            </div>
            <div class="board__card-foot">
              <span class="badge" :class="priorityBadgeClass(task.priority)">{{ $t(`tasks.priorities.${task.priority}`) }}</span>
              <span v-if="task.assignee && field !== 'assignee_id'" class="board__card-assignee">
                <UserAvatar :name="task.assignee.name" :avatar-url="task.assignee.avatar_url" size="sm" />
                <span class="board__card-assignee-name">{{ task.assignee.name }}</span>
              </span>
            </div>
          </article>
        </template>
        <div v-if="placeholderAt(column, column.tasks.length)" class="board__placeholder" aria-hidden="true"></div>
        <p v-if="column.tasks.length === 0 && !placeholderAt(column, 0)" class="board__empty">{{ $t('tasks.boardEmpty') }}</p>
      </div>
    </section>
  </div>
</template>
