import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import api from '@/api/client'
import type { TimeEntry } from '@/types'

export const useTimerStore = defineStore('timer', () => {
  const runningEntry = ref<TimeEntry | null>(null)
  const elapsed = ref(0)
  let interval: ReturnType<typeof setInterval> | null = null
  let pollInterval: ReturnType<typeof setInterval> | null = null
  let visibilityHandler: (() => void) | null = null
  const POLL_MS = 60_000

  const isRunning = computed(() => !!runningEntry.value)

  const elapsedFormatted = computed(() => {
    const s = elapsed.value
    const hours = Math.floor(s / 3600)
    const minutes = Math.floor((s % 3600) / 60)
    const seconds = s % 60
    return `${hours}:${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`
  })

  function startTicking() {
    stopTicking()
    if (runningEntry.value) {
      const started = new Date(runningEntry.value.started_at).getTime()
      const tick = () => {
        elapsed.value = Math.max(0, Math.floor((Date.now() - started) / 1000))
      }
      tick()
      interval = setInterval(tick, 1000)
    }
  }

  function stopTicking() {
    if (interval) {
      clearInterval(interval)
      interval = null
    }
    elapsed.value = 0
  }

  async function fetchRunning() {
    try {
      const { data } = await api.get('/time-entries/running')
      const next: TimeEntry | null = data.data
      const changed = next?.id !== runningEntry.value?.id
      runningEntry.value = next
      if (next) {
        if (changed || !interval) startTicking()
      } else {
        stopTicking()
      }
    } catch {
      // keep the current state on transient network errors
    }
  }

  /** Keep the web app in sync with timers started/stopped from the menubar app. */
  function startPolling() {
    stopPolling()
    pollInterval = setInterval(fetchRunning, POLL_MS)
    visibilityHandler = () => {
      if (document.visibilityState === 'visible') fetchRunning()
    }
    document.addEventListener('visibilitychange', visibilityHandler)
    window.addEventListener('focus', visibilityHandler)
  }

  function stopPolling() {
    if (pollInterval) clearInterval(pollInterval)
    pollInterval = null
    if (visibilityHandler) {
      document.removeEventListener('visibilitychange', visibilityHandler)
      window.removeEventListener('focus', visibilityHandler)
      visibilityHandler = null
    }
  }

  async function start(projectId: string, taskId?: string, description?: string, isBillable?: boolean) {
    const { data } = await api.post('/time-entries/start', {
      project_id: projectId,
      task_id: taskId || null,
      description: description || null,
      is_billable: isBillable,
    })
    runningEntry.value = data.data
    startTicking()
  }

  async function stop() {
    const { data } = await api.post('/time-entries/stop')
    const stopped = data.data
    runningEntry.value = null
    stopTicking()
    return stopped
  }

  return {
    runningEntry,
    elapsed,
    isRunning,
    elapsedFormatted,
    fetchRunning,
    startPolling,
    stopPolling,
    start,
    stop,
    startTicking,
    stopTicking,
  }
})
