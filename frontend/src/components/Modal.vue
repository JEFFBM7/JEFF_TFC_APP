<script setup lang="ts">
import { computed } from 'vue'

const props = withDefaults(
  defineProps<{
    title: string
    open: boolean
    maxWidth?: string
    size?: 'default' | 'large' | 'xlarge'
    /** Fermeture au clic sur le fond (désactivé par défaut pour éviter les fermetures accidentelles). */
    closeOnBackdrop?: boolean
  }>(),
  {
    size: 'default',
    closeOnBackdrop: false,
  },
)

function onOverlayClick(): void {
  if (props.closeOnBackdrop) {
    emit('close')
  }
}

const emit = defineEmits<{ (e: 'close'): void }>()

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
      <div v-if="open" class="overlay" role="dialog" aria-modal="true" @click.self="onOverlayClick">
        <div class="dialog" :class="dialogClass" :style="{ maxWidth: resolvedMaxWidth }">
          <header class="dialog-header">
            <h2 style="margin: 0">{{ title }}</h2>
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
