import { computed, type ComputedRef } from 'vue'
import { useAuthStore } from '../stores/auth'
import type { Term, TermCycle } from '../types'

export function useTermCycleScope(): {
  isGlobalAdmin: ComputedRef<boolean>
  /** null = les deux calendriers (admin général). */
  allowedTermCycles: ComputedRef<TermCycle[] | null>
  filterTerms: (terms: Term[]) => Term[]
  canAccessTermCycle: (cycle?: TermCycle | null) => boolean
} {
  const auth = useAuthStore()

  const isGlobalAdmin = computed(
    () => auth.user?.role === 'admin' && (auth.user.admin_scope ?? 'global') === 'global',
  )

  const allowedTermCycles = computed<TermCycle[] | null>(() => {
    const user = auth.user
    if (!user) return []

    if (user.term_applicable_cycles !== undefined) {
      return user.term_applicable_cycles
    }

    if (user.role === 'admin') {
      if ((user.admin_scope ?? 'global') === 'global') return null
      if (user.admin_scope === 'primary_maternal') return ['primaire']
      if (user.admin_scope === 'secondary_technical') return ['secondaire']
    }

    return null
  })

  function canAccessTermCycle(cycle?: TermCycle | null): boolean {
    const allowed = allowedTermCycles.value
    if (allowed === null) return true
    return allowed.includes((cycle ?? 'primaire') as TermCycle)
  }

  function filterTerms(terms: Term[]): Term[] {
    const allowed = allowedTermCycles.value
    if (allowed === null) return terms
    return terms.filter((term) => allowed.includes((term.applicable_cycle ?? 'primaire') as TermCycle))
  }

  return {
    isGlobalAdmin,
    allowedTermCycles,
    filterTerms,
    canAccessTermCycle,
  }
}
