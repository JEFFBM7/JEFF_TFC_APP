<script setup lang="ts">
import { computed, nextTick, ref, useId, watch } from 'vue'

const props = withDefaults(
  defineProps<{
    title: string
    open: boolean
    maxWidth?: string
    size?: 'default' | 'large' | 'xlarge'
    /** Fermeture au clic sur le fond (désactivé par défaut pour éviter les fermetures accidentelles). */
    closeOnBackdrop?: boolean
    /** Classe additionnelle sur l'overlay (la racine étant un Teleport, `class` ne retombe pas dessus). */
    overlayClass?: string
  }>(),
  {
    size: 'default',
    closeOnBackdrop: false,
    overlayClass: undefined,
  },
)

const emit = defineEmits<{ (e: 'close'): void }>()

const titleId = useId()
const dialogRef = ref<HTMLElement | null>(null)
let previouslyFocused: HTMLElement | null = null

const FOCUSABLE_SELECTOR = [
  'a[href]',
  'button:not([disabled])',
  'textarea:not([disabled])',
  'input:not([disabled]):not([type="hidden"])',
  'select:not([disabled])',
  '[tabindex]:not([tabindex="-1"])',
].join(',')

function focusableElements(): HTMLElement[] {
  if (!dialogRef.value) return []
  return Array.from(dialogRef.value.querySelectorAll<HTMLElement>(FOCUSABLE_SELECTOR)).filter(
    (el) => el.offsetWidth > 0 || el.offsetHeight > 0 || el === document.activeElement,
  )
}

function onOverlayClick(): void {
  if (props.closeOnBackdrop) {
    emit('close')
  }
}

function onKeydown(event: KeyboardEvent): void {
  if (event.key === 'Escape') {
    event.stopPropagation()
    emit('close')
    return
  }

  if (event.key !== 'Tab') return

  const focusables = focusableElements()
  if (focusables.length === 0) {
    event.preventDefault()
    dialogRef.value?.focus()
    return
  }

  const first = focusables[0]
  const last = focusables[focusables.length - 1]
  const active = document.activeElement as HTMLElement | null
  const insideDialog = active ? dialogRef.value?.contains(active) ?? false : false

  if (event.shiftKey && (active === first || !insideDialog)) {
    event.preventDefault()
    last.focus()
  } else if (!event.shiftKey && active === last) {
    event.preventDefault()
    first.focus()
  }
}

watch(
  () => props.open,
  async (open) => {
    if (open) {
      previouslyFocused = document.activeElement as HTMLElement | null
      await nextTick()
      // On cible le 1er champ saisissable s'il existe (formulaires), sinon le conteneur :
      // on évite ainsi de poser d'emblée le focus sur un bouton destructif.
      const focusables = focusableElements()
      const firstField = focusables.find(
        (el) => el.tagName === 'INPUT' || el.tagName === 'TEXTAREA' || el.tagName === 'SELECT',
      )
      ;(firstField ?? dialogRef.value)?.focus()
    } else if (previouslyFocused) {
      previouslyFocused.focus()
      previouslyFocused = null
    }
  },
)

const resolvedMaxWidth = computed(() => {
  if (props.maxWidth) {
    return props.maxWidth
  }

  if (props.size === 'xlarge') {
    return 'min(56rem, 96vw)'
  }

  if (props.size === 'large') {
    return 'min(42rem, 94vw)'
  }

  return '28rem'
})

const dialogClass = computed(() => ({
  'dialog--large': props.size === 'large' || props.maxWidth !== undefined,
  'dialog--xlarge': props.size === 'xlarge',
}))
</script>

<template>
  <Teleport to="body">
    <Transition name="fade">
      <div v-if="open" class="overlay" :class="overlayClass" @click.self="onOverlayClick" @keydown="onKeydown">
        <div
          ref="dialogRef"
          class="dialog"
          :class="dialogClass"
          :style="{ maxWidth: resolvedMaxWidth }"
          role="dialog"
          aria-modal="true"
          :aria-labelledby="titleId"
          tabindex="-1"
        >
          <header class="dialog-header">
            <h2 :id="titleId" style="margin: 0">{{ title }}</h2>
            <button type="button" class="close" aria-label="Fermer" @click="emit('close')">×</button>
          </header>
          <div class="dialog-body">
            <slot />
          </div>
          <footer v-if="$slots.footer" class="dialog-footer">
            <slot name="footer" />
          </footer>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.overlay {
  position: fixed;
  inset: 0;
  background: rgba(15, 23, 42, 0.45);
  display: grid;
  place-items: center;
  padding: 1rem;
  z-index: 100;
}
.dialog {
  background: var(--bg-card);
  border-radius: var(--radius);
  width: 100%;
  box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
  display: flex;
  flex-direction: column;
  max-height: min(92vh, 900px);
}
.dialog:focus-visible {
  outline: 2px solid var(--primary);
  outline-offset: 2px;
}
.dialog--large .dialog-header,
.dialog--large .dialog-footer {
  padding-inline: 1.35rem;
}
.dialog--large .dialog-body,
.dialog--xlarge .dialog-body {
  padding: 1.35rem 1.5rem;
}
.dialog--xlarge {
  max-height: min(94vh, 960px);
}
.dialog--xlarge .dialog-header,
.dialog--xlarge .dialog-footer {
  padding: 1.1rem 1.5rem;
}
.dialog--xlarge .dialog-header h2 {
  font-size: 1.15rem;
}
.dialog-header {
  padding: 1rem 1.2rem;
  border-bottom: 1px solid var(--border);
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.dialog-body {
  padding: 1.2rem;
  overflow-y: auto;
}
.dialog-footer {
  padding: 1rem 1.2rem;
  border-top: 1px solid var(--border);
  display: flex;
  gap: 0.5rem;
  justify-content: flex-end;
}
.close {
  border: none;
  background: transparent;
  font-size: 1.4rem;
  line-height: 1;
  color: var(--text-soft);
  padding: 0 0.5rem;
}
.close:hover {
  background: transparent;
  color: var(--text);
}

.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.15s ease;
}
.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
</style>
