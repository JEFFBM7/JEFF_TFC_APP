import { computed, ref } from 'vue'
import { defineStore } from 'pinia'

export type ConfirmVariant = 'danger' | 'warning' | 'default'

export interface ConfirmOptions {
  title: string
  message: string
  details?: string[]
  note?: string
  confirmLabel?: string
  cancelLabel?: string
  variant?: ConfirmVariant
}

const DEFAULT_OPTIONS: ConfirmOptions = {
  title: 'Confirmer',
  message: 'Confirmer cette action ?',
  confirmLabel: 'Confirmer',
  cancelLabel: 'Annuler',
  variant: 'default',
}

export const useConfirmStore = defineStore('confirm', () => {
  const open = ref(false)
  const options = ref<ConfirmOptions>({ ...DEFAULT_OPTIONS })
  const resolver = ref<((value: boolean) => void) | null>(null)

  const isDanger = computed(() => options.value.variant === 'danger')
  const isWarning = computed(() => options.value.variant === 'warning')

  function ask(nextOptions: ConfirmOptions): Promise<boolean> {
    if (resolver.value) {
      resolver.value(false)
    }

    options.value = {
      ...DEFAULT_OPTIONS,
      ...nextOptions,
      details: nextOptions.details ?? [],
    }
    open.value = true

    return new Promise<boolean>((resolve) => {
      resolver.value = resolve
    })
  }

  function resolve(value: boolean): void {
    open.value = false
    resolver.value?.(value)
    resolver.value = null
  }

  function confirm(): void {
    resolve(true)
  }

  function cancel(): void {
    resolve(false)
  }

  return {
    open,
    options,
    isDanger,
    isWarning,
    ask,
    confirm,
    cancel,
  }
})
