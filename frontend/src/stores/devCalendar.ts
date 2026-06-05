import { computed, ref, watch } from 'vue'
import { setDevCalendarHeaderProvider } from '../api/client'
import { useSchoolYearStore } from './schoolYear'

const STORAGE_KEY = 'educonnect_dev_calendar'

export interface DevCalendarState {
  primaryTermId: number | null
  primaryPeriodId: number | null
  secondaryTermId: number | null
  secondaryPeriodId: number | null
}

function loadState(): DevCalendarState {
  if (!import.meta.env.DEV) {
    return emptyState()
  }

  try {
    const raw = localStorage.getItem(STORAGE_KEY)
    if (!raw) return emptyState()
    const parsed = JSON.parse(raw) as Partial<DevCalendarState>
    return {
      primaryTermId: parsed.primaryTermId ?? null,
      primaryPeriodId: parsed.primaryPeriodId ?? null,
      secondaryTermId: parsed.secondaryTermId ?? null,
      secondaryPeriodId: parsed.secondaryPeriodId ?? null,
    }
  } catch {
    return emptyState()
  }
}

function emptyState(): DevCalendarState {
  return {
    primaryTermId: null,
    primaryPeriodId: null,
    secondaryTermId: null,
    secondaryPeriodId: null,
  }
}

const state = ref<DevCalendarState>(loadState())

const isActive = computed(
  () =>
    state.value.primaryTermId !== null
    || state.value.primaryPeriodId !== null
    || state.value.secondaryTermId !== null
    || state.value.secondaryPeriodId !== null,
)

function persist(): void {
  if (!import.meta.env.DEV) return
  localStorage.setItem(STORAGE_KEY, JSON.stringify(state.value))
}

function headerValue(id: number | null): string | undefined {
  return id !== null ? String(id) : undefined
}

function applyHeaders(): Record<string, string> {
  const headers: Record<string, string> = {}
  const primaryTerm = headerValue(state.value.primaryTermId)
  const primaryPeriod = headerValue(state.value.primaryPeriodId)
  const secondaryTerm = headerValue(state.value.secondaryTermId)
  const secondaryPeriod = headerValue(state.value.secondaryPeriodId)

  if (primaryTerm) headers['X-Dev-Calendar-Primary-Term-Id'] = primaryTerm
  if (primaryPeriod) headers['X-Dev-Calendar-Primary-Period-Id'] = primaryPeriod
  if (secondaryTerm) headers['X-Dev-Calendar-Secondary-Term-Id'] = secondaryTerm
  if (secondaryPeriod) headers['X-Dev-Calendar-Secondary-Period-Id'] = secondaryPeriod

  return headers
}

export function initDevCalendarStore(): void {
  if (!import.meta.env.DEV) return

  setDevCalendarHeaderProvider(() => applyHeaders())

  watch(state, persist, { deep: true })
}

export function useDevCalendarStore() {
  function setPrimaryTerm(id: number | null): void {
    state.value.primaryTermId = id
    state.value.primaryPeriodId = null
  }

  function setPrimaryPeriod(id: number | null): void {
    state.value.primaryPeriodId = id
  }

  function setSecondaryTerm(id: number | null): void {
    state.value.secondaryTermId = id
    state.value.secondaryPeriodId = null
  }

  function setSecondaryPeriod(id: number | null): void {
    state.value.secondaryPeriodId = id
  }

  function reset(): void {
    state.value = emptyState()
    window.dispatchEvent(new CustomEvent('dev-calendar-changed'))
  }

  function notifyChange(): void {
    persist()
    window.dispatchEvent(new CustomEvent('dev-calendar-changed'))
  }

  return {
    state,
    isActive,
    setPrimaryTerm,
    setPrimaryPeriod,
    setSecondaryTerm,
    setSecondaryPeriod,
    reset,
    notifyChange,
  }
}

export function useDevCalendarReload(callback: () => void): void {
  if (!import.meta.env.DEV) return

  const schoolYear = useSchoolYearStore()

  watch(
    () => schoolYear.effectiveId,
    () => callback(),
  )

  window.addEventListener('dev-calendar-changed', callback)
}
