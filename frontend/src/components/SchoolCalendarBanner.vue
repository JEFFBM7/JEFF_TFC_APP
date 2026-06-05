<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { api } from '../api/client'
import { useDevCalendarReload } from '../stores/devCalendar'
import { useSchoolYearStore } from '../stores/schoolYear'
import type { TermCycle } from '../types'

interface CalendarTerm {
  id: number
  name: string
  term_type: string
  applicable_cycle: TermCycle
  starts_on: string
  ends_on: string
  is_closed: boolean
}

interface CalendarPeriod {
  id: number
  name: string
  position: number
  starts_on: string
  ends_on: string
  is_closed: boolean
}

interface CalendarEntry {
  cycle: TermCycle
  cycle_label: string
  term_type_label: string
  status: 'active' | 'between_periods' | 'upcoming' | 'ended' | 'none'
  hint: string | null
  term: CalendarTerm | null
  period: CalendarPeriod | null
}

interface CalendarContext {
  school_year: { id: number; name: string }
  today: string
  entries: CalendarEntry[]
  simulation?: { active: boolean; reference_date?: string } | null
}

const props = withDefaults(
  defineProps<{
    /** strip = bandeau layout ; below = sous les filtres (dashboard) */
    variant?: 'strip' | 'below'
  }>(),
  { variant: 'strip' },
)

const schoolYearStore = useSchoolYearStore()
const context = ref<CalendarContext | null>(null)
const loading = ref(false)

const visibleEntries = computed(() => context.value?.entries ?? [])

const hasContent = computed(() => visibleEntries.value.length > 0)

const isSimulated = computed(() => context.value?.simulation?.active === true)

async function load(): Promise<void> {
  if (!schoolYearStore.effectiveId) {
    context.value = null
    return
  }

  loading.value = true
  try {
    const res = await api<{ data: CalendarContext | null }>('/api/v1/school-calendar/context', {
      query: { school_year_id: schoolYearStore.effectiveId },
    })
    context.value = res.data
  } catch {
    context.value = null
  } finally {
    loading.value = false
  }
}

function formatShortDate(value: string): string {
  const date = new Date(`${value}T00:00:00`)
  if (Number.isNaN(date.getTime())) return value
  return new Intl.DateTimeFormat('fr-FR', { day: 'numeric', month: 'short' }).format(date)
}

function cycleShortLabel(entry: CalendarEntry): string {
  return entry.cycle === 'secondaire' ? 'Secondaire' : 'Primaire'
}

const bannerTitle = computed(() => {
  const entries = visibleEntries.value
  if (entries.some((e) => e.status === 'active')) return 'En cours'
  if (entries.some((e) => e.status === 'between_periods')) return 'En cours'
  if (entries.some((e) => e.status === 'upcoming')) return 'À venir'
  if (entries.some((e) => e.status === 'ended')) return 'Terminé'
  return 'Calendrier'
})

function entryLine(entry: CalendarEntry): string {
  if (entry.status === 'active' && entry.term && entry.period) {
    return `${entry.term.name}, ${entry.period.name}`
  }
  if ((entry.status === 'active' || entry.status === 'between_periods') && entry.term) {
    return entry.term.name
  }
  if (entry.hint) {
    return entry.hint
  }
  return `Aucun ${entry.term_type_label.toLowerCase()} actif`
}

function showDateRange(entry: CalendarEntry): boolean {
  return (entry.status === 'active' || entry.status === 'between_periods') && entryDateRange(entry) !== null
}

function entryDateRange(entry: CalendarEntry): string | null {
  const start = entry.period?.starts_on ?? entry.term?.starts_on
  const end = entry.period?.ends_on ?? entry.term?.ends_on
  if (!start || !end) return null

  return `${formatShortDate(start)} – ${formatShortDate(end)}`
}

function closureNote(entry: CalendarEntry): string | null {
  const parts: string[] = []
  if (entry.term?.is_closed) parts.push('terme clôturé')
  if (entry.period?.is_closed) parts.push('période clôturée')
  return parts.length > 0 ? parts.join(', ') : null
}

onMounted(() => void load())

watch(
  () => schoolYearStore.effectiveId,
  () => void load(),
)

useDevCalendarReload(() => void load())
</script>

<template>
  <p
    v-if="hasContent"
    role="status"
    :aria-busy="loading"
    :class="[
      props.variant === 'below' ? 'calendar-below' : 'calendar-strip',
      { 'is-loading': loading },
    ]"
  >
    <span :class="props.variant === 'below' ? 'calendar-below-label' : 'calendar-strip-label'">
      {{ bannerTitle }}<span v-if="isSimulated" class="calendar-sim-tag"> · simulé</span>
    </span>
    <template v-for="(entry, index) in visibleEntries" :key="entry.cycle">
      <span
        v-if="index > 0"
        :class="props.variant === 'below' ? 'calendar-below-sep' : 'calendar-strip-sep'"
        aria-hidden="true"
      >·</span>
      <span :class="props.variant === 'below' ? 'calendar-below-item' : 'calendar-strip-item'">
        <span :class="props.variant === 'below' ? 'calendar-below-cycle' : 'calendar-strip-cycle'">
          {{ cycleShortLabel(entry) }}
        </span>
        <span class="calendar-entry-dash" aria-hidden="true"> — </span>
        <span :class="props.variant === 'below' ? 'calendar-below-value' : 'calendar-strip-value'">
          {{ entryLine(entry) }}
        </span>
        <span
          v-if="showDateRange(entry)"
          :class="props.variant === 'below' ? 'calendar-below-dates' : 'calendar-strip-dates'"
        >
          ({{ entryDateRange(entry) }})
        </span>
        <span
          v-if="closureNote(entry)"
          :class="props.variant === 'below' ? 'calendar-below-note' : 'calendar-strip-note'"
        >{{ closureNote(entry) }}</span>
      </span>
    </template>
  </p>
</template>

<style scoped>
.calendar-strip {
  margin: 0;
  padding: 0.35rem 1.5rem;
  border-bottom: 1px solid var(--border);
  background: var(--bg);
  font-size: 0.78rem;
  line-height: 1.45;
  color: var(--text-soft);
  font-weight: 500;
}

.calendar-strip.is-loading {
  opacity: 0.6;
}

.calendar-strip-label {
  margin-right: 0.45rem;
  color: var(--text-muted, var(--text-soft));
  font-weight: 600;
}

.calendar-sim-tag {
  font-weight: 600;
  color: #b45309;
  font-size: 0.92em;
}

.calendar-strip-sep {
  margin: 0 0.4rem;
  opacity: 0.45;
}

.calendar-strip-item {
  white-space: normal;
}

.calendar-strip-cycle {
  margin-right: 0.3rem;
  font-weight: 650;
  color: var(--text);
}

.calendar-strip-value {
  color: var(--text);
}

.calendar-strip-dates {
  margin-left: 0.2rem;
  color: var(--text-soft);
}

.calendar-strip-note {
  margin-left: 0.25rem;
  font-style: italic;
  opacity: 0.85;
}

@media (max-width: 640px) {
  .calendar-strip {
    padding-inline: 1rem;
    font-size: 0.74rem;
  }

  .calendar-strip-dates {
    display: block;
    margin-left: 0;
    padding-left: 3.2rem;
  }
}

.calendar-below {
  margin: 0;
  padding: 0;
  width: 100%;
  text-align: right;
  font-size: 0.76rem;
  line-height: 1.45;
  color: var(--text-soft);
  font-weight: 500;
}

.calendar-entry-dash {
  opacity: 0.5;
}

.calendar-below.is-loading {
  opacity: 0.6;
}

.calendar-below-label {
  margin-right: 0.35rem;
  font-weight: 600;
  color: var(--text-soft);
}

.calendar-below-sep {
  margin: 0 0.35rem;
  opacity: 0.45;
}

.calendar-below-cycle {
  margin-right: 0.25rem;
  font-weight: 650;
  color: var(--text);
}

.calendar-below-value {
  color: var(--text);
}

.calendar-below-dates {
  margin-left: 0.15rem;
  color: var(--text-soft);
}

.calendar-below-note {
  margin-left: 0.2rem;
  font-style: italic;
  opacity: 0.85;
}

@media (max-width: 720px) {
  .calendar-below {
    text-align: left;
    max-width: none;
  }
}
</style>
