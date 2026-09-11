<script setup lang="ts">
import { ref, watch } from 'vue'
import api from '@/api/client'
import type { ProjectTask } from '@/types'
import TaskRow from '@/components/TaskRow.vue'

/**
 * One node of the subtask tree: the row plus its lazily loaded children.
 * Completion toggles bubble up to the view, which saves and refetches; the
 * refreshed props then reload the children of expanded nodes.
 */
const props = withDefaults(defineProps<{
  task: ProjectTask
  depth?: number
  showProject?: boolean
  busyId?: string | null
  /** Children already known (task detail delivers them); otherwise loaded on expand. */
  initialChildren?: ProjectTask[] | null
  startExpanded?: boolean
}>(), { depth: 0, showProject: false, busyId: null, initialChildren: null, startExpanded: false })

const emit = defineEmits<{ (e: 'toggle', task: ProjectTask, completed: boolean): void }>()

const expanded = ref(props.startExpanded)
const children = ref<ProjectTask[]>(props.initialChildren ?? [])
const loaded = ref(props.initialChildren !== null)
const loading = ref(false)

async function load() {
  loading.value = true
  try {
    const { data } = await api.get('/project-tasks', {
      params: { 'filter[parent_id]': props.task.id, 'filter[status]': 'all', per_page: 500, sort: 'priority' },
    })
    children.value = data.data
    loaded.value = true
  } catch {
    // The row keeps its summary; expanding again retries.
    expanded.value = false
  } finally {
    loading.value = false
  }
}

function toggleExpand() {
  expanded.value = !expanded.value
  if (expanded.value && !loaded.value) load()
}

watch(
  () => `${props.task.updated_at}|${props.task.children_count}|${props.task.open_children_count}`,
  () => { if (expanded.value) load() },
)
watch(() => props.initialChildren, (c) => { if (c) { children.value = c; loaded.value = true } })

if (props.startExpanded && !loaded.value) load()
</script>

<template>
  <TaskRow
    :task="task"
    :show-project="showProject"
    :busy="busyId === task.id"
    :depth="depth"
    :expandable="(task.children_count ?? 0) > 0"
    :expanded="expanded"
    :loading="loading"
    @toggle="(t, c) => emit('toggle', t, c)"
    @expand="toggleExpand"
  />
  <template v-if="expanded">
    <TaskTree
      v-for="child in children"
      :key="child.id"
      :task="child"
      :depth="depth + 1"
      :show-project="false"
      :busy-id="busyId"
      @toggle="(t, c) => emit('toggle', t, c)"
    />
  </template>
</template>
