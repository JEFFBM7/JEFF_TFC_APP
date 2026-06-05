<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { api } from '../api/client'
import { useDevCalendarStore } from '../stores/devCalendar'
import { useSchoolYearStore } from '../stores/schoolYear'
import { useTermCycleScope } from '../composables/useTermCycleScope'

interface DevPeriodOption {
  id: number
  name: string
  position: number
  starts_on: string
  ends_on: string
}

interface DevTermOption {
  id: number
  name: string
  term_type: string
  starts_on: string
  ends_on: string
  periods: DevPeriodOption[]
}

interface DevOptions {
  school_year_id: number
  primary: DevTermOption[]
  secondary: DevTermOption[]
}

const schoolYear = useSchoolYearStore()
const { allowedTermCycles, isGlobalAdmin } = useTermCycleScope()
const dev = useDevCalendarStore()

const options = ref<DevOptions | null>(null)
const loading = ref(false)
const collapsed = ref(true)
const isDev = import.meta.env.DEV

const showPrimary = computed(
  () => isGlobalAdmin.value || allowedTermCycles.value === null || allowedTermCycles.value?.includes('primaire'),
)
const showSecondary = computed(
  () => isGlobalAdmin.value || allowedTermCycles.value === null || allowedTermCycles.value?.includes('secondaire'),
)

const primaryPeriods = computed(() => {
  const termId = dev.state.value.primaryTermId
  if (!termId || !options.value) return []
  return options.value.primary.find((t) => t.id === termId)?.periods ?? []
})

const secondaryPeriods = computed(() => {
  const termId = dev.state.value.secondaryTermId
  if (!termId || !options.value) return []
  return options.value.secondary.find((t) => t.id === termId)?.periods ?? []
})

async function loadOptions(): Promise<void> {
  if (!import.meta.env.DEV || !schoolYear.effectiveId) {
    options.value = null
    return
  }

  loading.value = true
  try {
    const res = await api<{ data: DevOptions | null }>('/api/v1/school-calendar/dev-options', {
      query: { school_year_id: schoolYear.effectiveId },
    })
    options.value = res.data
  } catch {
    options.value = null
  } finally {
    loading.value = false
  }
}

function onPrimaryTermChange(event: Event): void {
  const value = (event.target as HTMLSelectElement).value
  dev.setPrimaryTerm(value ? Number(value) : null)
  dev.notifyChange()
}

function onPrimaryPeriodChange(event: Event): void {
  const value = (event.target as HTMLSelectElement).value
  dev.setPrimaryPeriod(value ? Number(value) : null)
  dev.notifyChange()
}

function onSecondaryTermChange(event: Event): void {
  const value = (event.target as HTMLSelectElement).value
  dev.setSecondaryTerm(value ? Number(value) : null)
  dev.notifyChange()
}

function onSecondaryPeriodChange(event: Event): void {
  const value = (event.target as HTMLSelectElement).value
  dev.setSecondaryPeriod(value ? Number(value) : null)
  dev.notifyChange()
}

function resetSimulation(): void {
  dev.reset()
}

onMounted(() => void loadOptions())

watch(() => schoolYear.effectiveId, () => void loadOptions())
</script>

<template>
  <div v-if="isDev" class="dev-calendar" :class="{ 'is-active': dev.isActive.value }">
    <button type="button" class="dev-calendar-toggle" @click="collapsed = !collapsed">
      <span>Dev · Calendrier</span>
      <span v-if="dev.isActive.value" class="dev-calendar-pill">simulé</span>
      <span class="dev-calendar-chevron">{{ collapsed ? '▾' : '▴' }}</span>
    </button>

    <div v-show="!collapsed" class="dev-calendar-panel">
      <p class="dev-calendar-hint">
        Simule le trimestre / semestre en cours (local uniquement). Recharge les stats et le bandeau calendrier.
      </p>

      <div v-if="loading" class="dev-calendar-loading">Chargement…</div>

      <div v-else class="dev-calendar-fields">
        <fieldset v-if="showPrimary" class="dev-calendar-group">
          <legend>Primaire / Maternel</legend>
          <label>
            <span>Trimestre</span>
            <select
              :value="dev.state.value.primaryTermId ?? ''"
              @change="onPrimaryTermChange"
            >
              <option value="">Réel (date du jour)</option>
              <option v-for="term in options?.primary ?? []" :key="term.id" :value="term.id">
                {{ term.name }}
              </option>
            </select>
          </label>
          <label v-if="primaryPeriods.length">
            <span>Période</span>
            <select
              :value="dev.state.value.primaryPeriodId ?? ''"
              @change="onPrimaryPeriodChange"
            >
              <option value="">Auto (dans le trimestre)</option>
              <option v-for="period in primaryPeriods" :key="period.id" :value="period.id">
                {{ period.name }}
              </option>
            </select>
          </label>
        </fieldset>

        <fieldset v-if="showSecondary" class="dev-calendar-group">
          <legend>Secondaire / CTEB</legend>
          <label>
            <span>Semestre</span>
            <select
              :value="dev.state.value.secondaryTermId ?? ''"
              @change="onSecondaryTermChange"
            >
              <option value="">Réel (date du jour)</option>
              <option v-for="term in options?.secondary ?? []" :key="term.id" :value="term.id">
                {{ term.name }}
              </option>
            </select>
          </label>
          <label v-if="secondaryPeriods.length">
            <span>Période</span>
            <select
              :value="dev.state.value.secondaryPeriodId ?? ''"
              @change="onSecondaryPeriodChange"
            >
              <option value="">Auto (dans le semestre)</option>
              <option v-for="period in secondaryPeriods" :key="period.id" :value="period.id">
                {{ period.name }}
              </option>
            </select>
          </label>
        </fieldset>
      </div>

      <button
        v-if="dev.isActive.value"
        type="button"
        class="dev-calendar-reset"
        @click="resetSimulation"
      >
        Revenir au calendrier réel
      </button>
    </div>
  </div>
</template>

<style scoped>
.dev-calendar {
  margin: 0;
  border: 1px dashed color-mix(in srgb, #f59e0b 45%, var(--border));
  border-radius: var(--radius);
  background: color-mix(in srgb, #f59e0b 5%, var(--bg-card));
  font-size: 0.78rem;
}

.dev-calendar.is-active {
  border-color: color-mix(in srgb, #f59e0b 70%, var(--border));
}

.dev-calendar-toggle {
  display: flex;
  align-items: center;
  gap: 0.45rem;
  width: 100%;
  padding: 0.4rem 0.65rem;
  border: none;
  background: transparent;
  font: inherit;
  font-weight: 650;
  color: #92400e;
  cursor: pointer;
  text-align: left;
}

.dev-calendar-pill {
  font-size: 0.65rem;
  font-weight: 800;
  text-transform: uppercase;
  padding: 0.1rem 0.35rem;
  border-radius: 999px;
  background: #fef3c7;
  color: #92400e;
}

.dev-calendar-chevron {
  margin-left: auto;
  opacity: 0.6;
}

.dev-calendar-panel {
  padding: 0 0.65rem 0.65rem;
  border-top: 1px dashed color-mix(in srgb, #f59e0b 30%, var(--border));
}

.dev-calendar-hint {
  margin: 0.5rem 0 0.65rem;
  color: var(--text-soft);
  line-height: 1.35;
}

.dev-calendar-fields {
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem 1.25rem;
}

.dev-calendar-group {
  margin: 0;
  padding: 0.5rem 0.65rem;
  border: 1px solid var(--border);
  border-radius: calc(var(--radius) - 2px);
  min-width: 14rem;
  flex: 1 1 14rem;
}

.dev-calendar-group legend {
  padding: 0 0.25rem;
  font-weight: 700;
  font-size: 0.72rem;
  color: var(--text-soft);
}

.dev-calendar-group label {
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
  margin-top: 0.45rem;
}

.dev-calendar-group label span {
  font-size: 0.7rem;
  color: var(--text-soft);
}

.dev-calendar-group select {
  min-height: 2rem;
  font-size: 0.78rem;
  border: 1px solid var(--border);
  border-radius: calc(var(--radius) - 2px);
  padding: 0.2rem 0.45rem;
  background: var(--bg-card);
}

.dev-calendar-reset {
  margin-top: 0.55rem;
  padding: 0.3rem 0.55rem;
  font-size: 0.74rem;
  border: 1px solid var(--border);
  border-radius: calc(var(--radius) - 2px);
  background: var(--bg-card);
  cursor: pointer;
}

.dev-calendar-loading {
  padding: 0.35rem 0;
  color: var(--text-soft);
}
</style>
