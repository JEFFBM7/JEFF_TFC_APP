<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { api } from '../api/client'
import type { Paginated } from '../types'
import { ChevronLeft, ChevronRight, MapPin, User } from 'lucide-vue-next'

interface SlotItem {
  id: number
  day_of_week: number
  starts_at: string
  ends_at: string
  room: string | null
  type?: string | null
  subject?: { id: number; name: string }
  teacher?: { id: number; name: string }
}

const DAYS = [
  { id: 1, short: 'Lun', long: 'Lundi' },
  { id: 2, short: 'Mar', long: 'Mardi' },
  { id: 3, short: 'Mer', long: 'Mercredi' },
  { id: 4, short: 'Jeu', long: 'Jeudi' },
  { id: 5, short: 'Ven', long: 'Vendredi' },
  { id: 6, short: 'Sam', long: 'Samedi' },
]

const SLOT_TYPE_COLORS: Record<string, string> = {
  CM: '#3b82f6',
  TD: '#8b5cf6',
  TP: '#10b981',
  DS: '#ef4444',
}

const selectedDate = ref(toDateInputValue(new Date()))
const slots = ref<SlotItem[]>([])
const loading = ref(false)
const error = ref('')

const weekStart = computed(() => startOfWeek(parseDateInput(selectedDate.value)))
const weekEnd = computed(() => addDays(weekStart.value, 5))
const weekLabel = computed(
  () => `Semaine du ${formatDate(weekStart.value)} au ${formatDate(weekEnd.value)}`,
)

const weekDays = computed(() =>
  DAYS.map((day, index) => ({
    ...day,
    date: addDays(weekStart.value, index),
    dayNum: addDays(weekStart.value, index).getDate(),
    isToday: isSameDay(addDays(weekStart.value, index), new Date()),
  })),
)

const selectedDayId = ref<number>(todayDayId())

const activeDay = computed(
  () => weekDays.value.find((d) => d.id === selectedDayId.value) ?? weekDays.value[0],
)

const slotsForDay = computed(() => {
  const daySlots = slots.value
    .filter((s) => s.day_of_week === selectedDayId.value)
    .sort((a, b) => a.starts_at.localeCompare(b.starts_at))

  const result: Array<SlotItem | { type: 'break'; label: string; at: string }> = []
  for (let i = 0; i < daySlots.length; i++) {
    result.push(daySlots[i])
    if (i < daySlots.length - 1) {
      const endH = parseInt(daySlots[i].ends_at.split(':')[0])
      const startNextH = parseInt(daySlots[i + 1].starts_at.split(':')[0])
      if (startNextH >= 12 && endH < 12) {
        result.push({ type: 'break', label: 'Pause déjeuner', at: daySlots[i].ends_at })
      }
    }
  }
  return result
})

function todayDayId(): number {
  const d = new Date().getDay()
  return d === 0 ? 7 : d
}

function toDateInputValue(date: Date): string {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`
}

function parseDateInput(value: string): Date {
  const [y, m, d] = value.split('-').map(Number)
  return new Date(y, m - 1, d)
}

function addDays(date: Date, days: number): Date {
  const next = new Date(date)
  next.setDate(next.getDate() + days)
  return next
}

function startOfWeek(date: Date): Date {
  const d = date.getDay()
  return addDays(date, d === 0 ? -6 : 1 - d)
}

function isSameDay(a: Date, b: Date): boolean {
  return a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate()
}

function formatDate(date: Date): string {
  return new Intl.DateTimeFormat('fr-FR', { day: '2-digit', month: 'long' }).format(date)
}

function prevWeek(): void {
  selectedDate.value = toDateInputValue(addDays(parseDateInput(selectedDate.value), -7))
}

function nextWeek(): void {
  selectedDate.value = toDateInputValue(addDays(parseDateInput(selectedDate.value), 7))
}

function goToday(): void {
  selectedDate.value = toDateInputValue(new Date())
  selectedDayId.value = todayDayId()
}

function slotColor(s: SlotItem): string {
  const t = (s.type ?? '').toUpperCase()
  return SLOT_TYPE_COLORS[t] ?? '#3b82f6'
}

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    const res = await api<Paginated<SlotItem>>('/api/v1/student/timetable')
    slots.value = res.data
  } catch {
    error.value = "Impossible de charger l'emploi du temps."
    slots.value = []
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>

<template>
  <section class="tt-page portal-mobile">
    <!-- Header -->
    <header class="tt-header">
      <div class="tt-header__top">
        <div>
          <h1 class="tt-header__title">Mon Emploi du Temps</h1>
          <p class="tt-header__sub">{{ weekLabel }}</p>
        </div>
        <button type="button" class="tt-today-btn" @click="goToday">Aujourd'hui</button>
      </div>

      <!-- Week nav -->
      <div class="tt-week-nav">
        <button type="button" class="tt-week-nav__btn" aria-label="Semaine précédente" @click="prevWeek">
          <ChevronLeft aria-hidden="true" />
        </button>
        <div class="tt-day-chips" role="tablist" aria-label="Jours de la semaine">
          <button
            v-for="day in weekDays"
            :key="day.id"
            type="button"
            role="tab"
            class="tt-day-chip"
            :class="{ 'is-active': selectedDayId === day.id, 'is-today': day.isToday }"
            :aria-selected="selectedDayId === day.id"
            @click="selectedDayId = day.id"
          >
            <span class="tt-day-chip__short">{{ day.short }}</span>
            <span class="tt-day-chip__num">{{ day.dayNum }}</span>
          </button>
        </div>
        <button type="button" class="tt-week-nav__btn" aria-label="Semaine suivante" @click="nextWeek">
          <ChevronRight aria-hidden="true" />
        </button>
      </div>
    </header>

    <p v-if="error" class="alert alert-error">{{ error }}</p>

    <!-- Timeline -->
    <div v-if="loading" class="tt-empty">Chargement…</div>
    <div v-else-if="slotsForDay.length === 0" class="tt-empty">
      Aucun cours prévu {{ activeDay.isToday ? "aujourd'hui" : `ce ${activeDay.long.toLowerCase()}` }}.
    </div>
    <ol v-else class="tt-timeline" aria-label="`Cours du ${activeDay.long}`">
      <li
        v-for="(item, i) in slotsForDay"
        :key="i"
        class="tt-timeline__item"
        :class="item.type === 'break' ? 'tt-timeline__item--break' : 'tt-timeline__item--slot'"
      >
        <!-- Break separator -->
        <template v-if="item.type === 'break'">
          <div class="tt-break">
            <span class="tt-break__icon" aria-hidden="true">🍽</span>
            <span>{{ (item as { type: 'break'; label: string; at: string }).label }}</span>
          </div>
        </template>

        <!-- Slot card -->
        <template v-else>
          <div class="tt-slot">
            <div class="tt-slot__time">
              <span>{{ (item as SlotItem).starts_at }}</span>
              <span class="tt-slot__time-end">{{ (item as SlotItem).ends_at }}</span>
            </div>
            <div class="tt-slot__dot-col">
              <span class="tt-slot__dot" :style="{ background: slotColor(item as SlotItem) }" />
              <span class="tt-slot__line" />
            </div>
            <div class="tt-slot__card">
              <div class="tt-slot__card-head">
                <strong class="tt-slot__subject">{{ (item as SlotItem).subject?.name ?? 'Cours' }}</strong>
                <span v-if="(item as SlotItem).type" class="tt-slot__type-badge"
                  :style="{ background: slotColor(item as SlotItem) + '22', color: slotColor(item as SlotItem) }">
                  {{ ((item as SlotItem).type ?? '').toUpperCase() }}
                </span>
              </div>
              <p v-if="(item as SlotItem).teacher?.name" class="tt-slot__meta">
                <User class="tt-slot__meta-icon" aria-hidden="true" />
                {{ (item as SlotItem).teacher!.name }}
              </p>
              <p v-if="(item as SlotItem).room" class="tt-slot__meta">
                <MapPin class="tt-slot__meta-icon" aria-hidden="true" />
                {{ (item as SlotItem).room }}
              </p>
            </div>
          </div>
        </template>
      </li>
    </ol>
  </section>
</template>

<style scoped>
.tt-page {
  display: flex;
  flex-direction: column;
  gap: 0;
  min-height: calc(100vh - 5rem);
  padding-bottom: 5.5rem;
}

/* ── Header ── */
.tt-header {
  padding: 1.1rem 1rem 0;
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.tt-header__top {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 0.5rem;
}

.tt-header__title {
  margin: 0;
  font-size: 1.15rem;
  font-weight: 800;
  color: var(--text);
}

.tt-header__sub {
  margin: 0.18rem 0 0;
  font-size: 0.77rem;
  color: var(--text-soft);
}

.tt-today-btn {
  flex-shrink: 0;
  padding: 0.35rem 0.85rem;
  border: 1px solid var(--border);
  border-radius: 8px;
  background: var(--bg-card);
  color: var(--accent);
  font-size: 0.78rem;
  font-weight: 700;
}

/* ── Week nav ── */
.tt-week-nav {
  display: flex;
  align-items: center;
  gap: 0.4rem;
}

.tt-week-nav__btn {
  display: grid;
  place-items: center;
  width: 2rem;
  height: 2rem;
  border: none;
  border-radius: 8px;
  background: var(--bg-subtle);
  color: var(--text-soft);
  flex-shrink: 0;
  padding: 0;
  box-shadow: none;
}

.tt-week-nav__btn svg { width: 1.1rem; height: 1.1rem; }

/* ── Day chips ── */
.tt-day-chips {
  display: flex;
  flex: 1;
  gap: 0.3rem;
  overflow-x: auto;
  scrollbar-width: none;
}

.tt-day-chips::-webkit-scrollbar { display: none; }

.tt-day-chip {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.15rem;
  flex: 1;
  min-width: 2.6rem;
  padding: 0.4rem 0.3rem;
  border: 1px solid transparent;
  border-radius: 12px;
  background: transparent;
  color: var(--text-soft);
  box-shadow: none;
  cursor: pointer;
  transition: all 0.15s;
}

.tt-day-chip__short {
  font-size: 0.66rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.tt-day-chip__num {
  font-size: 1rem;
  font-weight: 800;
  line-height: 1;
}

.tt-day-chip.is-today .tt-day-chip__num {
  color: var(--accent);
}

.tt-day-chip.is-active {
  background: var(--primary);
  color: #fff;
  border-color: var(--primary);
}

.tt-day-chip.is-active.is-today .tt-day-chip__num {
  color: #fff;
}

/* ── Empty ── */
.tt-empty {
  padding: 3rem 1.5rem;
  text-align: center;
  color: var(--text-soft);
  font-size: 0.9rem;
}

/* ── Timeline ── */
.tt-timeline {
  list-style: none;
  margin: 1rem 0 0;
  padding: 0 1rem;
  display: flex;
  flex-direction: column;
  gap: 0;
}

.tt-timeline__item { display: contents; }

/* Break */
.tt-break {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.5rem 0.25rem;
  margin: 0.25rem 0;
  font-size: 0.75rem;
  font-weight: 700;
  color: var(--text-muted);
  text-transform: uppercase;
  letter-spacing: 0.08em;
  border-top: 1px dashed var(--border);
  border-bottom: 1px dashed var(--border);
}

.tt-break__icon { font-size: 0.9rem; }

/* Slot */
.tt-slot {
  display: grid;
  grid-template-columns: 3.6rem 1.6rem 1fr;
  gap: 0 0.5rem;
  padding: 0.6rem 0;
  align-items: start;
}

.tt-slot__time {
  display: flex;
  flex-direction: column;
  gap: 0.1rem;
  text-align: right;
  font-size: 0.74rem;
  font-weight: 700;
  color: var(--text-soft);
  padding-top: 0.1rem;
}

.tt-slot__time-end {
  font-weight: 500;
  color: var(--text-muted);
  font-size: 0.68rem;
}

.tt-slot__dot-col {
  display: flex;
  flex-direction: column;
  align-items: center;
  padding-top: 0.25rem;
  gap: 0;
}

.tt-slot__dot {
  display: block;
  width: 0.7rem;
  height: 0.7rem;
  border-radius: 50%;
  border: 2px solid var(--bg-card);
  box-shadow: 0 0 0 2px currentColor;
  flex-shrink: 0;
}

.tt-slot__line {
  flex: 1;
  width: 2px;
  min-height: 1.5rem;
  background: var(--border);
  margin-top: 0.25rem;
}

.tt-timeline__item:last-of-type .tt-slot__line { display: none; }

/* Card */
.tt-slot__card {
  background: var(--bg-card);
  border: 1px solid var(--border);
  border-radius: 14px;
  padding: 0.85rem 1rem;
  margin-bottom: 0.75rem;
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
}

.tt-slot__card-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 0.5rem;
}

.tt-slot__subject {
  font-size: 0.98rem;
  font-weight: 800;
  color: var(--text);
  line-height: 1.25;
}

.tt-slot__type-badge {
  flex-shrink: 0;
  padding: 0.15rem 0.5rem;
  border-radius: 6px;
  font-size: 0.68rem;
  font-weight: 800;
  letter-spacing: 0.06em;
}

.tt-slot__meta {
  display: flex;
  align-items: center;
  gap: 0.35rem;
  margin: 0;
  font-size: 0.77rem;
  color: var(--text-soft);
}

.tt-slot__meta-icon {
  width: 0.85rem;
  height: 0.85rem;
  flex-shrink: 0;
  opacity: 0.7;
}
</style>
