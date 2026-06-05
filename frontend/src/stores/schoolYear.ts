import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { api, ApiError } from '../api/client'
import type { ApiResource, Paginated, SchoolYear } from '../types'

const SELECTED_YEAR_KEY = 'educonnect_school_year_id'

/**
 * Store global de l'année scolaire.
 *
 * Trois rôles distincts :
 *  - `current` : l'année définie comme courante côté backend (`is_current = true`).
 *    C'est le défaut sur lequel toutes les requêtes se calent quand aucun
 *    `school_year_id` n'est fourni.
 *  - `selectedId` : l'année actuellement consultée par l'utilisateur dans l'UI.
 *    Persistée en `sessionStorage`. Différente de `current` lorsqu'il consulte
 *    une année passée / archivée.
 *  - `years` : la liste complète, hydratée à la demande pour les sélecteurs.
 */
export const useSchoolYearStore = defineStore('schoolYear', () => {
  const current = ref<SchoolYear | null>(null)
  const years = ref<SchoolYear[]>([])
  const selectedId = ref<number | null>(readPersistedId())
  const loading = ref(false)
  const initialized = ref(false)

  const selected = computed<SchoolYear | null>(() => {
    if (selectedId.value === null) return current.value
    return years.value.find((y) => y.id === selectedId.value)
      ?? (current.value && current.value.id === selectedId.value ? current.value : null)
  })

  const isViewingArchived = computed(() => selected.value?.is_archived === true)
  const isViewingCurrent = computed(
    () => current.value !== null && selected.value?.id === current.value.id,
  )

  /** Identifiant à injecter dans les requêtes (null = laisser le backend décider). */
  const effectiveId = computed<number | null>(() => {
    if (selectedId.value !== null) return selectedId.value
    return current.value?.id ?? null
  })

  function readPersistedId(): number | null {
    if (typeof sessionStorage === 'undefined') return null
    const raw = sessionStorage.getItem(SELECTED_YEAR_KEY)
    if (!raw) return null
    const parsed = Number(raw)
    return Number.isFinite(parsed) && parsed > 0 ? parsed : null
  }

  function persistSelected(id: number | null): void {
    if (typeof sessionStorage === 'undefined') return
    if (id === null) sessionStorage.removeItem(SELECTED_YEAR_KEY)
    else sessionStorage.setItem(SELECTED_YEAR_KEY, String(id))
  }

  async function fetchCurrent(): Promise<void> {
    try {
      const res = await api<ApiResource<SchoolYear | null>>('/api/v1/school-years/current', {
        skipSchoolYear: true,
      })
      current.value = res.data ?? null
    } catch (e) {
      // Erreur silencieuse : le backend peut ne pas avoir d'année courante définie.
      if (!(e instanceof ApiError) || e.status !== 404) {
        console.warn('Impossible de charger l\'année scolaire courante', e)
      }
      current.value = null
    }
  }

  async function fetchAll(): Promise<void> {
    if (loading.value) return
    loading.value = true
    try {
      const res = await api<Paginated<SchoolYear>>('/api/v1/school-years', {
        skipSchoolYear: true,
      })
      replaceYears(res.data)
    } catch (e) {
      // Tous les rôles ne peuvent pas lister `/school-years` (réservé admin) :
      // on tolère et on retombe sur la seule année courante.
      if (e instanceof ApiError && (e.status === 401 || e.status === 403)) {
        replaceYears(current.value ? [current.value] : [])
      } else {
        throw e
      }
    } finally {
      loading.value = false
    }
  }

  function setSelected(id: number | null): void {
    if (id === null || (current.value && id === current.value.id)) {
      // Revenir à l'année courante : on efface la persistance.
      selectedId.value = null
      persistSelected(null)
      return
    }

    selectedId.value = id
    persistSelected(id)
  }

  function resetToCurrent(): void {
    setSelected(null)
  }

  function replaceYears(list: SchoolYear[]): void {
    years.value = list
    current.value = list.find((y) => y.is_current) ?? null

    if (selectedId.value !== null && list.length > 0 && !list.some((y) => y.id === selectedId.value)) {
      setSelected(null)
    }
  }

  /**
   * Marque localement une année comme courante (à appeler après un PUT
   * `is_current=true` réussi) sans repasser par le réseau.
   */
  function markCurrent(id: number): void {
    years.value = years.value.map((y) => ({ ...y, is_current: y.id === id }))
    const updated = years.value.find((y) => y.id === id) ?? null
    if (updated) {
      current.value = updated
    }
    // Si l'utilisateur ne consultait pas explicitement une autre année,
    // on suit le mouvement.
    if (selectedId.value === null || selectedId.value === id) {
      setSelected(null)
    }
  }

  async function init(): Promise<void> {
    if (initialized.value) return
    await fetchCurrent()
    initialized.value = true
  }

  function reset(): void {
    current.value = null
    years.value = []
    selectedId.value = null
    persistSelected(null)
    initialized.value = false
  }

  return {
    current,
    years,
    selectedId,
    selected,
    loading,
    initialized,
    isViewingArchived,
    isViewingCurrent,
    effectiveId,
    init,
    fetchCurrent,
    fetchAll,
    setSelected,
    resetToCurrent,
    replaceYears,
    markCurrent,
    reset,
  }
})
