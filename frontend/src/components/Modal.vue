<script setup lang="ts">
defineProps<{ title: string; open: boolean }>()
const emit = defineEmits<{ (e: 'close'): void }>()
</script>

<template>
  <Teleport to="body">
    <Transition name="fade">
      <div v-if="open" class="overlay" role="dialog" aria-modal="true" @click.self="emit('close')">
        <div class="dialog">
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
  max-width: 28rem;
  box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
  display: flex;
  flex-direction: column;
  max-height: 90vh;
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
