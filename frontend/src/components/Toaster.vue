<script setup lang="ts">
import { AlertTriangle, CheckCircle2, Info, X, XCircle } from 'lucide-vue-next'
import { useToastStore } from '../stores/toast'

const toast = useToastStore()

const icons = {
  success: CheckCircle2,
  error: XCircle,
  info: Info,
  warning: AlertTriangle,
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
          <span class="toast-badge" aria-hidden="true">
            <component :is="icons[item.variant]" class="toast-icon" />
          </span>
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
  /* Au-dessus des modales (100) et du ConfirmDialog (300). */
  z-index: 400;
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
  width: min(24rem, calc(100vw - 2rem));
  pointer-events: none;
}

.toast {
  --toast-accent: var(--accent);
  --toast-soft: var(--primary-soft);
  pointer-events: auto;
  position: relative;
  display: flex;
  align-items: center;
  gap: 0.7rem;
  padding: 0.8rem 0.85rem 0.95rem;
  border: 1px solid var(--border-strong);
  border-radius: var(--radius);
  background: var(--bg-card);
  color: var(--text);
  box-shadow: var(--shadow-card);
  font-size: 0.88rem;
  font-weight: 600;
  overflow: hidden;
}

/* Barre de statut colorée en bas de la carte. */
.toast::after {
  content: '';
  position: absolute;
  inset: auto 0 0;
  height: 4px;
  background: var(--toast-accent);
}

.toast-badge {
  display: grid;
  place-items: center;
  width: 2rem;
  height: 2rem;
  flex: 0 0 auto;
  border-radius: 8px;
  background: var(--toast-soft);
  color: var(--toast-accent);
}

.toast-icon {
  width: 1.15rem;
  height: 1.15rem;
}

.toast--success {
  --toast-accent: var(--success, #16a34a);
  --toast-soft: var(--success-soft, rgba(74, 222, 128, 0.15));
}

.toast--error {
  --toast-accent: var(--danger, #dc2626);
  --toast-soft: var(--danger-soft, rgba(248, 113, 113, 0.15));
}

.toast--warning {
  --toast-accent: var(--warn, #d97706);
  --toast-soft: var(--warn-soft, rgba(251, 191, 36, 0.15));
}

.toast--info {
  --toast-accent: var(--accent, #2563eb);
  --toast-soft: var(--primary-soft, rgba(59, 130, 246, 0.15));
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
