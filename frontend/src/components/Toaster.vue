<script setup lang="ts">
import { CheckCircle2, Info, X, XCircle } from 'lucide-vue-next'
import { useToastStore } from '../stores/toast'

const toast = useToastStore()

const icons = {
  success: CheckCircle2,
  error: XCircle,
  info: Info,
}
</script>

<template>
  <Teleport to="body">
    <div class="toaster" role="region" aria-label="Notifications">
      <TransitionGroup name="toast">
        <div
          v-for="item in toast.toasts"
          :key="item.id"
          class="toast"
          :class="`toast--${item.variant}`"
          role="status"
          aria-live="polite"
        >
          <component :is="icons[item.variant]" class="toast-icon" aria-hidden="true" />
          <span class="toast-message">{{ item.message }}</span>
          <button
            type="button"
            class="toast-close"
            aria-label="Fermer la notification"
            @click="toast.dismiss(item.id)"
          >
            <X :size="16" aria-hidden="true" />
          </button>
        </div>
      </TransitionGroup>
    </div>
  </Teleport>
</template>

<style scoped>
.toaster {
  position: fixed;
  top: max(1rem, env(safe-area-inset-top));
  right: max(1rem, env(safe-area-inset-right));
  left: auto;
  z-index: 200;
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
  width: min(24rem, calc(100vw - 2rem));
  pointer-events: none;
}

.toast {
  pointer-events: auto;
  display: flex;
  align-items: center;
  gap: 0.65rem;
  padding: 0.75rem 0.85rem;
  border: 1px solid var(--border-strong);
  border-radius: var(--radius);
  background: var(--bg-card);
  color: var(--text);
  box-shadow: var(--shadow-card);
  font-size: 0.88rem;
  font-weight: 600;
}

.toast-icon {
  width: 1.15rem;
  height: 1.15rem;
  flex: 0 0 auto;
}

.toast--success {
  border-color: rgba(74, 222, 128, 0.35);
}
.toast--success .toast-icon {
  color: var(--success);
}

.toast--error {
  border-color: rgba(248, 113, 113, 0.35);
}
.toast--error .toast-icon {
  color: var(--danger);
}

.toast--info .toast-icon {
  color: var(--accent);
}

.toast-message {
  flex: 1;
  min-width: 0;
  line-height: 1.35;
}

.toast-close {
  display: grid;
  place-items: center;
  width: 1.6rem;
  height: 1.6rem;
  flex: 0 0 auto;
  min-height: 0;
  padding: 0;
  border: 0;
  border-radius: 6px;
  background: transparent;
  color: var(--text-soft);
  box-shadow: none;
}
.toast-close:hover:not(:disabled) {
  background: var(--bg-subtle);
  box-shadow: none;
}

.toast-enter-active,
.toast-leave-active {
  transition: opacity 0.2s ease, transform 0.2s ease;
}
.toast-enter-from {
  opacity: 0;
  transform: translateX(0.75rem);
}
.toast-leave-to {
  opacity: 0;
  transform: translateX(0.75rem);
}
</style>
