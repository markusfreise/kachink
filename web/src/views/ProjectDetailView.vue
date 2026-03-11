<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import api from '@/api/client'
import type { Project, Task } from '@/types'
import { PlusIcon, TrashIcon } from '@heroicons/vue/24/outline'

const { t } = useI18n()
const route = useRoute()
const project = ref<Project | null>(null)
const loading = ref(true)
const newTaskName = ref('')
const addingTask = ref(false)

async function fetchProject() {
  loading.value = true
  try {
    const { data } = await api.get(`/projects/${route.params.id}`)
    project.value = data.data
  } finally {
    loading.value = false
  }
}

async function addTask() {
  if (!newTaskName.value.trim() || !project.value) return
  addingTask.value = true
  try {
    await api.post('/tasks', {
      project_id: project.value.id,
      name: newTaskName.value.trim(),
    })
    newTaskName.value = ''
    fetchProject()
  } finally {
    addingTask.value = false
  }
}

async function deleteTask(task: Task) {
  if (!confirm(t('projectDetail.deleteTaskConfirm', { name: task.name }))) return
  await api.delete(`/tasks/${task.id}`)
  fetchProject()
}

onMounted(fetchProject)
</script>

<template>
  <div class="page-container">
    <div v-if="loading" class="loading-center">
      <div class="loading-spinner"></div>
    </div>

    <template v-else-if="project">
      <div class="detail-header">
        <div class="detail-title-row">
          <span class="color-dot-lg" :style="{ backgroundColor: project.color }"></span>
          <div>
            <h1 class="heading-1">{{ project.name }}</h1>
            <span class="text-muted">{{ project.client?.name }}</span>
          </div>
        </div>
        <div class="detail-badges">
          <span :class="project.is_billable ? 'badge-green' : 'badge-gray'">
            {{ project.is_billable ? $t('common.billable') : $t('common.nonBillable') }}
          </span>
          <span v-if="project.hourly_rate" class="badge-blue">&euro;{{ project.hourly_rate }}/h</span>
        </div>
      </div>

      <!-- Stats -->
      <div class="detail-stats">
        <div class="stat-card-mini">
          <span class="stat-mini-label">{{ $t('projectDetail.tracked') }}</span>
          <span class="stat-mini-value">{{ project.total_tracked_hours ?? 0 }}h</span>
        </div>
        <div v-if="project.budget_hours" class="stat-card-mini">
          <span class="stat-mini-label">{{ $t('projectDetail.budget') }}</span>
          <span class="stat-mini-value">{{ project.budget_hours }}h</span>
        </div>
        <div v-if="project.budget_used_percentage != null" class="stat-card-mini">
          <span class="stat-mini-label">{{ $t('projectDetail.used') }}</span>
          <span class="stat-mini-value">{{ project.budget_used_percentage }}%</span>
        </div>
        <div class="stat-card-mini">
          <span class="stat-mini-label">{{ $t('projectDetail.tasks') }}</span>
          <span class="stat-mini-value">{{ project.tasks?.length ?? 0 }}</span>
        </div>
      </div>

      <!-- Tasks -->
      <div class="card">
        <div class="card-header">
          <h2 class="heading-3">{{ $t('projectDetail.tasks') }}</h2>
        </div>
        <div class="card-body">
          <div class="task-add-row">
            <input
              v-model="newTaskName"
              type="text"
              class="form-input"
              :placeholder="$t('projectDetail.addTask')"
              @keydown.enter="addTask"
            />
            <button class="btn-primary btn-sm" :disabled="addingTask || !newTaskName.trim()" @click="addTask">
              <PlusIcon class="btn-icon-sm" />
              {{ $t('common.add') }}
            </button>
          </div>

          <div v-if="!project.tasks?.length" class="empty-state">
            <p class="empty-state-text">{{ $t('projectDetail.noTasks') }}</p>
          </div>
          <ul v-else class="task-list">
            <li v-for="task in project.tasks" :key="task.id" class="task-item">
              <span class="task-name">{{ task.name }}</span>
              <button class="btn-ghost btn-icon btn-sm" @click="deleteTask(task)">
                <TrashIcon class="task-action-icon" />
              </button>
            </li>
          </ul>
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped>
@reference "../assets/main.css";
.loading-center {
  @apply flex justify-center py-12;
}

.detail-header {
  @apply flex items-start justify-between mb-6;
}

.detail-title-row {
  @apply flex items-center gap-3;
}

.color-dot-lg {
  @apply inline-block h-5 w-5 rounded-full shrink-0;
}

.detail-badges {
  @apply flex gap-2;
}

.detail-stats {
  @apply grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6;
}

.stat-card-mini {
  @apply bg-white rounded-lg border border-gray-200 shadow-sm p-4 flex flex-col;
}

.stat-mini-label {
  @apply text-xs text-gray-500;
}

.stat-mini-value {
  @apply text-lg font-bold text-gray-900 tabular-nums;
}

.task-add-row {
  @apply flex gap-3 mb-4;
}

.btn-icon-sm {
  @apply h-4 w-4;
}

.task-list {
  @apply divide-y divide-gray-100;
}

.task-item {
  @apply flex items-center justify-between py-2;
}

.task-name {
  @apply text-sm text-gray-900;
}

.task-action-icon {
  @apply h-4 w-4;
}
</style>
