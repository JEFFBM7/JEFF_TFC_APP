import { ref } from 'vue'
import { defineStore } from 'pinia'

export type ToastVariant = 'success' | 'error' | 'info'

export interface Toast {
  id: number
  message: string
  variant: ToastVariant
}

let nextId = 0

export const useToastStore = defineStore('toast', () => {
  const toasts = ref<Toast[]>([])

  function dismiss(id: number): void {
    toasts.value = toasts.value.filter((toast) => toast.id !== id)
  }

  function push(message: string, variant: ToastVariant = 'info', duration = 4000): number {
    const id = ++nextId
    toasts.value.push({ id, message, variant })
    if (duration > 0) {
      window.setTimeout(() => dismiss(id), duration)
    }
    return id
  }

  const success = (message: string, duration?: number) => push(message, 'success', duration)
  const error = (message: string, duration?: number) => push(message, 'error', duration)
  const info = (message: string, duration?: number) => push(message, 'info', duration)

  return { toasts, push, success, error, info, dismiss }
})
