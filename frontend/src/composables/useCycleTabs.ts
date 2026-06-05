import { computed, type ComputedRef } from 'vue'
import { useAuthStore } from '../stores/auth'
import type { LevelCycle } from '../types'

export type CycleFilter = 'all' | LevelCycle
export type CycleTab = { value: CycleFilter; label: string }

const CYCLE_OPTIONS: Array<{ value: LevelCycle; label: string }> = [
  { value: 'maternel', label: 'Maternelle' },
  { value: 'primaire', label: 'Primaire' },
  { value: 'cteb', label: 'CTEB' },
  { value: 'secondaire', label: 'Secondaire' },
]

const ALL_CYCLE_VALUES = CYCLE_OPTIONS.map((cycle) => cycle.value)

export function useCycleTabs(): {
  cycleTabs: ComputedRef<CycleTab[]>
  authorizedCycleValues: ComputedRef<LevelCycle[]>
} {
  const auth = useAuthStore()

  const authorizedCycleValues = computed<LevelCycle[]>(() => {
    const user = auth.user
    if (user?.role !== 'admin' || (user.admin_scope ?? 'global') === 'global') return ALL_CYCLE_VALUES

    const allowed = user.admin_cycles?.filter((cycle): cycle is LevelCycle =>
      ALL_CYCLE_VALUES.includes(cycle as LevelCycle),
    ) ?? []

    return allowed.length > 0 ? allowed : ALL_CYCLE_VALUES
  })

  const cycleTabs = computed<CycleTab[]>(() => [
    { value: 'all', label: 'Tous' },
    ...CYCLE_OPTIONS.filter((cycle) => authorizedCycleValues.value.includes(cycle.value)),
  ])

  return { cycleTabs, authorizedCycleValues }
}
