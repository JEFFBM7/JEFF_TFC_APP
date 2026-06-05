<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'

const props = withDefaults(defineProps<{ ariaLabel?: string; openUp?: boolean }>(), {
  ariaLabel: 'Actions',
  openUp: false,
})

const root = ref<HTMLElement | null>(null)
const menuRef = ref<HTMLElement | null>(null)
const open = ref(false)
const opensUpward = ref(false)

const menuCoords = ref<{ top?: number; bottom?: number; right: number }>({
  right: 0,
})

const menuStyle = computed(() => {
  const coords = menuCoords.value
  const style: Record<string, string> = {
    position: 'fixed',
    right: `${coords.right}px`,
    zIndex: '2000',
    minWidth: '12.5rem',
  }

  if (coords.top != null) style.top = `${coords.top}px`
  if (coords.bottom != null) style.bottom = `${coords.bottom}px`

  return style
})

function updatePosition(): void {
  const button = root.value?.querySelector('.icon-menu-btn') as HTMLElement | null
  if (!button) return

  const rect = button.getBoundingClientRect()
  const gap = 6
  const menuHeight = menuRef.value?.offsetHeight ?? 168
  const spaceBelow = window.innerHeight - rect.bottom
  const spaceAbove = rect.top

  opensUpward.value = props.openUp || (spaceBelow < menuHeight + gap && spaceAbove > spaceBelow)

  if (opensUpward.value) {
    menuCoords.value = {
      bottom: window.innerHeight - rect.top + gap,
      right: window.innerWidth - rect.right,
    }
    return
  }

  menuCoords.value = {
    top: rect.bottom + gap,
    right: window.innerWidth - rect.right,
  }
}

async function toggle(): Promise<void> {
  open.value = !open.value
  if (!open.value) return

  await nextTick()
  updatePosition()
  await nextTick()
  updatePosition()
}

function close(): void {
  open.value = false
}

function onDocumentClick(event: MouseEvent): void {
  const target = event.target as Node
  if (root.value?.contains(target) || menuRef.value?.contains(target)) return
  close()
}

function onDocumentKeydown(event: KeyboardEvent): void {
  if (event.key === 'Escape') {
    close()
  }
}

onMounted(() => {
  document.addEventListener('click', onDocumentClick)
  document.addEventListener('keydown', onDocumentKeydown)
  window.addEventListener('scroll', close, true)
  window.addEventListener('resize', updatePosition)
})

onBeforeUnmount(() => {
  document.removeEventListener('click', onDocumentClick)
  document.removeEventListener('keydown', onDocumentKeydown)
  window.removeEventListener('scroll', close, true)
  window.removeEventListener('resize', updatePosition)
})

watch(open, (isOpen) => {
  if (!isOpen) return
  void nextTick().then(updatePosition)
})
</script>

<template>
  <div ref="root" class="row-action-menu" :class="{ 'is-open': open }">
    <button
      type="button"
      class="icon-menu-btn"
      :class="{ active: open }"
      :aria-label="ariaLabel"
      :aria-expanded="open"
      aria-haspopup="menu"
      @click.stop="toggle"
    >
      <span class="menu-bars" aria-hidden="true">
        <i />
        <i />
        <i />
      </span>
    </button>

    <Teleport to="body">
      <Transition name="row-menu-fade">
        <div
          v-if="open"
          ref="menuRef"
          class="row-menu"
          :class="{ 'row-menu-up': opensUpward }"
          :style="menuStyle"
          role="menu"
          @click.stop="close"
        >
          <slot />
        </div>
      </Transition>
    </Teleport>
  </div>
</template>

<style scoped>
.row-action-menu {
  position: relative;
  display: inline-flex;
  justify-content: flex-end;
}

.icon-menu-btn {
  width: 2.1rem;
  min-height: 2rem;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  margin: 0;
  padding: 0;
  border: 1px solid var(--border);
  border-radius: 8px;
  background: var(--bg-card);
  color: var(--text-soft);
  box-shadow: none;
  transition:
    border-color 0.12s ease,
    color 0.12s ease,
    background 0.12s ease;
}

.icon-menu-btn:hover,
.icon-menu-btn.active {
  border-color: var(--primary-tint);
  background: var(--primary-soft);
  color: var(--primary);
  transform: none;
}

.menu-bars {
  display: inline-grid;
  gap: 0.16rem;
  width: 0.9rem;
}

.menu-bars i {
  display: block;
  height: 0.11rem;
  border-radius: 999px;
  background: currentColor;
}

.row-menu {
  display: grid;
  gap: 0.1rem;
  padding: 0.35rem;
  border: 1px solid var(--border-strong);
  border-radius: 10px;
  background: var(--bg-card);
  box-shadow:
    0 10px 24px rgb(15 23 42 / 12%),
    0 2px 6px rgb(15 23 42 / 6%);
}

.row-menu :deep(button),
.row-menu :deep(a) {
  width: 100%;
  min-height: 2.05rem;
  display: flex;
  align-items: center;
  justify-content: flex-start;
  margin: 0;
  padding: 0.5rem 0.65rem;
  border: 0;
  border-radius: 7px;
  background: transparent;
  box-shadow: none;
  color: var(--text);
  font-size: 0.84rem;
  font-weight: 650;
  text-align: left;
  text-decoration: none;
  white-space: nowrap;
}

.row-menu :deep(button:hover),
.row-menu :deep(a:hover) {
  background: var(--bg-subtle);
  color: var(--primary);
  text-decoration: none;
  transform: none;
}

.row-menu :deep(.danger-action) {
  color: var(--danger);
}

.row-menu :deep(.danger-action:hover) {
  background: var(--danger-soft);
  color: var(--danger);
}

.row-menu-fade-enter-active,
.row-menu-fade-leave-active {
  transition:
    opacity 0.14s ease,
    transform 0.14s ease;
}

.row-menu-fade-enter-from,
.row-menu-fade-leave-to {
  opacity: 0;
  transform: translateY(-0.2rem) scale(0.98);
}

.row-menu-up.row-menu-fade-enter-from,
.row-menu-up.row-menu-fade-leave-to {
  transform: translateY(0.2rem) scale(0.98);
}
</style>
