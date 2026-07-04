import { ref } from 'vue'
import { defineStore } from 'pinia'

export type ToastVariant = 'success' | 'error' | 'info' | 'warning'

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
    // Anti-doublon : la même notification déjà à l'écran n'est pas rejouée
    // (ex. erreur globale API + erreur locale d'une vue).
    const existing = toasts.value.find(
      (toast) => toast.message === message && toast.variant === variant,
    )
    if (existing) return existing.id

    const id = ++nextId
    toasts.value.push({ id, message, variant })
    if (duration > 0) {
      window.setTimeout(() => dismiss(id), duration)
    }
    return id
  }

  const success = (message: string, duration?: number) => push(message, 'success', duration)
  const error = (message: string, duration?: number) => push(message, 'error', duration ?? 6000)
  const info = (message: string, duration?: number) => push(message, 'info', duration)
  const warning = (message: string, duration?: number) => push(message, 'warning', duration ?? 6000)

  return { toasts, push, success, error, info, warning, dismiss }
})
