<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { api } from '../api/client'
import type { Paginated } from '../types'

interface SlotItem {
  id: number
  day_of_week: number
  starts_at: string
  ends_at: string
  room: string | null
  subject?: { id: number; name: string }
  teacher?: { id: number; name: string }
}

const DAYS = [
  { id: 1, label: 'Lundi' },
  { id: 2, label: 'Mardi' },
  { id: 3, label: 'Mercredi' },
  { id: 4, label: 'Jeudi' },
  { id: 5, label: 'Vendredi' },
  { id: 6, label: 'Samedi' },
]

const longDateFormatter = new Intl.DateTimeFormat('fr-FR', {
  day: '2-digit',
  month: 'long',
  year: 'numeric',
})
const shortDateFormatter = new Intl.DateTimeFormat('fr-FR', {
  day: '2-digit',
  month: '2-digit',
})

const selectedDate = ref(toDateInputValue(new Date()))
const slots = ref<SlotItem[]>([])
const loading = ref(false)
const error = ref('')

const weekStart = computed(() => startOfWeek(parseDateInput(selectedDate.value)))
const weekEnd = computed(() => addDays(weekStart.value, 5))
const weekDays = computed(() =>
  DAYS.map((day, index) => ({
    ...day,
    date: addDays(weekStart.value, index),
  })),
)
const weekLabel = computed(() =>
  `Semaine du ${formatLongDate(weekStart.value)} au ${formatLongDate(weekEnd.value)}`,
)

const slotsByDay = computed(() => {
  const grouped: Record<number, SlotItem[]> = {}
  for (const d of DAYS) grouped[d.id] = []
  for (const s of slots.value) {
    if (grouped[s.day_of_week]) grouped[s.day_of_week].push(s)
  }
  return grouped
})

function toDateInputValue(date: Date): string {
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

function parseDateInput(value: string): Date {
  const [year, month, day] = value.split('-').map(Number)
  if (!year || !month || !day) return new Date()
  return new Date(year, month - 1, day)
}

function addDays(date: Date, days: number): Date {
  const next = new Date(date)
  next.setDate(next.getDate() + days)
  return next
}

function startOfWeek(date: Date): Date {
  const day = date.getDay()
  const diff = day === 0 ? -6 : 1 - day
  return addDays(date, diff)
}

function formatLongDate(date: Date): string {
  return longDateFormatter.format(date)
}

function formatShortDate(date: Date): string {
  return shortDateFormatter.format(date)
}

function selectToday(): void {
  selectedDate.value = toDateInputValue(new Date())
}

async function loadSlots(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    const res = await api<Paginated<SlotItem>>('/api/v1/student/timetable')
    slots.value = res.data
  } catch {
    error.value = 'Impossible de charger l\'emploi du temps.'
    slots.value = []
  } finally {
    loading.value = false
  }
}

onMounted(loadSlots)
</script>

<template>
  <section class="timetable-page">
    <div class="page-head">
      <div>
        <h1>Mon emploi du temps</h1>
        <p>{{ weekLabel }}</p>
      </div>
    </div>

    <p v-if="error" class="alert alert-error">{{ error }}</p>

    <div class="filters-row">
      <label class="date-picker">
        <span>Date</span>
        <input v-model="selectedDate" type="date" />
      </label>
      <button type="button" class="today-button" @click="selectToday">Aujourd'hui</button>
    </div>

    <div v-if="loading" class="empty-state">Chargement…</div>
    <div v-else-if="slots.length === 0" class="empty-state">Aucun cours planifié pour cette période.</div>
    <div v-else class="timetable-grid">
      <div v-for="day in weekDays" :key="day.id" class="day-col">
        <h3>
          <span>{{ day.label }}</span>
          <small>{{ formatShortDate(day.date) }}</small>
        </h3>
        <ul v-if="slotsByDay[day.id].length > 0">
          <li v-for="s in slotsByDay[day.id]" :key="s.id" class="slot">
            <div class="slot-time">{{ s.starts_at }} – {{ s.ends_at }}</div>
            <div class="slot-subject">{{ s.subject?.name }}</div>
            <div class="slot-teacher">{{ s.teacher?.name }}<span v-if="s.room"> · {{ s.room }}</span></div>
          </li>
        </ul>
        <p v-else class="empty-day">—</p>
      </div>
    </div>
  </section>
</template>

<style scoped>
.timetable-page {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.page-head h1 {
  margin: 0;
}

.page-head p {
  margin: 0.25rem 0 0;
  color: var(--text-soft);
}

.filters-row {
  display: flex;
  align-items: flex-end;
  gap: 0.75rem;
}

.date-picker {
  display: grid;
  gap: 0.3rem;
  margin: 0;
}

.date-picker span {
  color: var(--text-soft);
  font-size: 0.76rem;
  font-weight: 850;
  text-transform: uppercase;
}

.date-picker input {
  min-width: 14rem;
}

.today-button {
  min-height: 2.55rem;
  padding: 0 0.9rem;
  border: 1px solid var(--border);
  border-radius: 8px;
  background: #fff;
  color: var(--text-soft);
  font-weight: 800;
}

.today-button:hover {
  border-color: var(--primary);
  color: var(--primary);
}

.timetable-grid {
  display: grid;
  grid-template-columns: repeat(6, 1fr);
  gap: 0.5rem;
}
.day-col {
  background: var(--bg-card);
  border: 1px solid var(--border);
  border-radius: 6px;
  padding: 0.5rem;
  min-height: 160px;
}
.day-col h3 {
  display: grid;
  gap: 0.12rem;
  margin: 0 0 0.5rem;
  font-size: 0.85rem;
  color: var(--text-soft);
  text-align: center;
}

.day-col h3 small {
  color: var(--text-muted);
  font-size: 0.72rem;
  font-weight: 700;
}
.day-col ul { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 0.4rem; }
.slot {
  background: var(--bg);
  border-left: 3px solid var(--primary);
  border-radius: 4px;
  padding: 0.35rem 0.5rem;
}
.slot-time { font-size: 0.7rem; color: var(--text-soft); }
.slot-subject { font-size: 0.82rem; font-weight: 600; }
.slot-teacher { font-size: 0.7rem; color: var(--text-soft); }
.empty-day { text-align: center; color: var(--text-soft); font-size: 0.78rem; margin: 0; }
@media (max-width: 900px) { .timetable-grid { grid-template-columns: repeat(3, 1fr); } }
@media (max-width: 560px) {
  .filters-row,
  .date-picker,
  .date-picker input,
  .today-button {
    width: 100%;
  }

  .timetable-grid {
    grid-template-columns: 1fr;
    gap: 0.75rem;
  }

  .day-col {
    min-height: auto;
    padding: 0.75rem;
  }

  .day-col h3 {
    text-align: left;
  }

  .slot {
    padding: 0.65rem 0.75rem;
  }

  .slot-time,
  .slot-teacher {
    font-size: 0.76rem;
  }

  .slot-subject {
    font-size: 0.92rem;
  }
}
</style>
