<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useSchoolYearStore } from '../stores/schoolYear'

const store = useSchoolYearStore()
const open = ref(false)
const dropdownRef = ref<HTMLElement | null>(null)

const sortedYears = computed(() => {
  if (store.years.length > 0) {
    return [...store.years].sort((a, b) => b.starts_on.localeCompare(a.starts_on))
  }
  return store.current ? [store.current] : []
})

const triggerLabel = computed(() => store.selected?.name ?? '—')
const currentYearLabel = computed(() => store.current?.name ?? 'année courante')

const triggerBadge = computed<'current' | 'archived' | 'other' | null>(() => {
  if (!store.selected) return null
  if (store.isViewingArchived) return 'archived'
  if (store.isViewingCurrent) return 'current'
  return 'other'
})

async function toggle(): Promise<void> {
  if (open.value) {
    open.value = false
    return
  }
  open.value = true
  if (store.years.length === 0) {
    await store.fetchAll()
  }
}

function selectYear(id: number): void {
  store.setSelected(id)
  open.value = false
}

function backToCurrent(): void {
  store.resetToCurrent()
  open.value = false
}

function onClickOutside(event: MouseEvent): void {
  if (!open.value) return
  if (dropdownRef.value && !dropdownRef.value.contains(event.target as Node)) {
    open.value = false
  }
}

onMounted(() => {
  document.addEventListener('mousedown', onClickOutside)
})

watch(open, (isOpen) => {
  if (!isOpen) return
  // Refocus to allow ESC close
  setTimeout(() => dropdownRef.value?.focus(), 0)
})

function badgeFor(yearId: number): 'current' | 'archived' | null {
  const y = sortedYears.value.find((s) => s.id === yearId)
  if (!y) return null
  if (y.is_archived) return 'archived'
  if (y.is_current) return 'current'
  return null
}
</script>

<template>
  <div ref="dropdownRef" class="sy-switcher" :class="{ open }" @keydown.esc="open = false">
    <button
      type="button"
      class="sy-trigger"
      :aria-expanded="open"
      aria-haspopup="listbox"
      :title="store.selected ? `Année scolaire ${store.selected.name}` : 'Aucune année définie'"
      @click="toggle"
    >
      <span class="sy-label">{{ triggerLabel }}</span>
      <span v-if="triggerBadge === 'current'" class="sy-pill sy-pill-current">Courante</span>
      <span v-else-if="triggerBadge === 'archived'" class="sy-pill sy-pill-archived">Archivée</span>
      <span v-else-if="triggerBadge === 'other'" class="sy-pill sy-pill-other">Consultation</span>
      <span class="sy-caret" aria-hidden="true">▾</span>
    </button>

    <div v-if="open" class="sy-menu" role="listbox" tabindex="-1">
      <div class="sy-menu-header">
        <span>Années scolaires</span>
      </div>

      <ul class="sy-list">
        <li v-if="store.loading" class="sy-empty">Chargement…</li>
        <li v-else-if="sortedYears.length === 0" class="sy-empty">Aucune année définie.</li>
        <template v-else>
          <li v-if="!store.isViewingCurrent && store.current" class="sy-current-row">
            <button type="button" class="sy-current-action" @click="backToCurrent">
              <span>
                <strong>Revenir à l'année courante</strong>
                <small>{{ currentYearLabel }}</small>
              </span>
              <span aria-hidden="true">↗</span>
            </button>
          </li>
          <li v-for="year in sortedYears" :key="year.id">
            <button
              type="button"
              class="sy-item"
              :class="{ 'is-active': store.selected?.id === year.id }"
              role="option"
              :aria-selected="store.selected?.id === year.id"
              @click="selectYear(year.id)"
            >
              <span class="sy-item-name">{{ year.name }}</span>
              <span class="sy-item-meta">{{ year.starts_on }} → {{ year.ends_on }}</span>
              <span v-if="badgeFor(year.id) === 'current'" class="sy-pill sy-pill-current">Courante</span>
              <span v-else-if="badgeFor(year.id) === 'archived'" class="sy-pill sy-pill-archived">Archivée</span>
            </button>
          </li>
        </template>
      </ul>
    </div>
  </div>
</template>

<style scoped>
.sy-switcher {
  position: relative;
  display: inline-flex;
}

.sy-trigger {
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
  min-height: 2.15rem;
  padding: 0.4rem 0.65rem;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: var(--bg-card);
  color: var(--text);
  font-size: 0.84rem;
  font-weight: 700;
  cursor: pointer;
  transition: border-color 0.15s ease, background 0.15s ease;
}

.sy-trigger:hover {
  border-color: #cfd9ef;
  background: #f8faff;
}

.sy-switcher.open .sy-trigger {
  border-color: var(--primary);
  background: var(--primary-soft);
}

.sy-icon {
  font-size: 0.95rem;
  line-height: 1;
}

.sy-label {
  font-weight: 800;
  letter-spacing: 0.01em;
}

.sy-caret {
  margin-left: 0.1rem;
  color: var(--text-soft);
  font-size: 0.7rem;
}

.sy-pill {
  display: inline-flex;
  align-items: center;
  padding: 0.1rem 0.45rem;
  border-radius: 999px;
  font-size: 0.66rem;
  font-weight: 800;
  letter-spacing: 0.04em;
  text-transform: uppercase;
}

.sy-pill-current {
  background: var(--success-soft, #def7ec);
  color: var(--success, #03543f);
}

.sy-pill-archived {
  background: #fef3c7;
  color: #92400e;
}

.sy-pill-other {
  background: #e0e7ff;
  color: #3730a3;
}

.sy-menu {
  position: absolute;
  top: calc(100% + 0.4rem);
  right: 0;
  z-index: 120;
  width: min(21rem, calc(100vw - 1rem));
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: var(--bg-card);
  box-shadow: 0 18px 38px rgba(15, 23, 42, 0.15);
  outline: none;
  overflow: hidden;
}

.sy-menu-header {
  display: flex;
  align-items: center;
  justify-content: flex-start;
  padding: 0.55rem 0.75rem;
  border-bottom: 1px solid var(--border);
  color: var(--text-soft);
  font-size: 0.74rem;
  font-weight: 800;
  letter-spacing: 0.05em;
  text-transform: uppercase;
}

.sy-current-row {
  padding: 0.35rem 0.5rem 0.45rem;
  border-bottom: 1px solid var(--border);
}

.sy-current-action {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  width: 100%;
  padding: 0.55rem 0.65rem;
  border: 1px solid var(--border);
  border-radius: 8px;
  background: #f8faff;
  color: var(--primary);
  text-align: left;
  cursor: pointer;
}

.sy-current-action:hover {
  background: var(--primary-soft);
  border-color: #cfd9ef;
}

.sy-current-action span:first-child {
  display: grid;
  min-width: 0;
  gap: 0.12rem;
}

.sy-current-action strong {
  font-size: 0.78rem;
  line-height: 1.2;
}

.sy-current-action small {
  color: var(--text-soft);
  font-size: 0.72rem;
  font-weight: 700;
}

.sy-list {
  margin: 0;
  padding: 0.35rem 0;
  list-style: none;
  max-height: 60vh;
  overflow-y: auto;
}

.sy-empty {
  padding: 0.75rem 0.85rem;
  color: var(--text-soft);
  font-size: 0.84rem;
}

.sy-item {
  display: grid;
  grid-template-columns: 1fr auto;
  grid-template-rows: auto auto;
  column-gap: 0.6rem;
  width: 100%;
  padding: 0.55rem 0.85rem;
  border: 0;
  background: transparent;
  color: var(--text);
  text-align: left;
  cursor: pointer;
  transition: background 0.12s ease;
}

.sy-item:hover {
  background: var(--primary-soft);
}

.sy-item.is-active {
  background: linear-gradient(90deg, #eef3ff, #ffffff);
}

.sy-item-name {
  grid-column: 1;
  font-size: 0.9rem;
  font-weight: 800;
}

.sy-item-meta {
  grid-column: 1;
  grid-row: 2;
  color: var(--text-soft);
  font-size: 0.74rem;
}

.sy-item .sy-pill {
  grid-column: 2;
  grid-row: 1 / span 2;
  align-self: center;
}

@media (max-width: 620px) {
  .sy-trigger {
    padding: 0.35rem 0.5rem;
  }
  .sy-trigger .sy-pill,
  .sy-trigger .sy-caret {
    display: none;
  }
}
</style>
