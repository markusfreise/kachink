import { defineStore } from 'pinia'
import { ref } from 'vue'

export type ToastKind = 'info' | 'success' | 'error'

export interface Toast {
  id: number
  kind: ToastKind
  text: string
}

let nextId = 1

export const useToastStore = defineStore('toast', () => {
  const toasts = ref<Toast[]>([])

  function push(text: string, kind: ToastKind = 'info', timeoutMs = 4500) {
    const id = nextId++
    toasts.value.push({ id, kind, text })
    if (timeoutMs > 0) {
      setTimeout(() => dismiss(id), timeoutMs)
    }
    return id
  }

  function dismiss(id: number) {
    toasts.value = toasts.value.filter((t) => t.id !== id)
  }

  const success = (text: string) => push(text, 'success')
  const error = (text: string) => push(text, 'error', 7000)
  const info = (text: string) => push(text, 'info')

  return { toasts, push, dismiss, success, error, info }
})

/** Extracts a readable message from an axios error. */
export function errorMessage(e: unknown, fallback = 'Something went wrong.'): string {
  const err = e as { response?: { data?: { message?: string; errors?: Record<string, string[]> } }; message?: string }
  const data = err?.response?.data
  if (data?.errors) {
    const first = Object.values(data.errors)[0]
    if (first && first[0]) return first[0]
  }
  return data?.message || err?.message || fallback
}
