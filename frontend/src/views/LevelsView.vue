<script setup lang="ts">
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue'
import { useRouter, type RouteLocationRaw } from 'vue-router'
import { api, ApiError } from '../api/client'
import type { ApiResource, ClassRoom, Level, LevelCycle, Paginated, SchoolClass, SchoolOption, SchoolOptionFiliere } from '../types'
import Modal from '../components/Modal.vue'
import DataTable, { type Column } from '../components/DataTable.vue'
import { useConfirmStore } from '../stores/confirm'
import { useSchoolYearStore } from '../stores/schoolYear'
import { useAuthStore } from '../stores/auth'
import { formatAveragePercent } from '../utils/grades'

const schoolYearStore = useSchoolYearStore()
const confirmDialog = useConfirmStore()
const auth = useAuthStore()

const CYCLE_OPTIONS: Array<{ value: LevelCycle; label: string }> = [
  { value: 'maternel', label: 'Maternelle' },
  { value: 'primaire', label: 'Primaire' },
  { value: 'cteb', label: 'CTEB' },
  { value: 'secondaire', label: 'Secondaire' },
]

const FILIERE_OPTIONS: Array<{ value: SchoolOptionFiliere; label: string }> = [
  { value: 'generale', label: 'Humanités générales' },
  { value: 'technique', label: 'Humanités techniques' },
  { value: 'professionnelle', label: 'Humanités professionnelles' },
]

function filiereLabel(value?: SchoolOptionFiliere | null): string {
  if (!value) return ''
  return FILIERE_OPTIONS.find((item) => item.value === value)?.label ?? ''
}

type CycleFilter = 'all' | LevelCycle
type ClassesViewTab = CycleFilter | 'structure'

const router = useRouter()

const levels = ref<Level[]>([])
const classrooms = ref<ClassRoom[]>([])
const schoolClasses = ref<SchoolClass[]>([])
const schoolOptions = ref<SchoolOption[]>([])
const loading = ref(false)
const error = ref('')
const activeCycle = ref<ClassesViewTab>('all')
const classSearchQuery = ref('')
const openMenuId = ref<number | null>(null)
const selectedClassroomIds = ref<Array<string | number>>([])
const bulkDeleting = ref(false)
const generatingClasses = ref(false)
const addingSchoolClassId = ref<number | null>(null)

const DEFAULT_DIVISION_CAPACITY = 40

type ClassTableRow = {
  rowKey: string
  id: number
  classroomId: number | null
  schoolClassId: number
  isBaseOnly: boolean
  isPlanned?: boolean
  full_name: string
  level_id: number
  school_option_id?: number | null
  section: string
  option: string
  capacity?: number
  student_count?: number
  main_teacher?: ClassRoom['main_teacher']
  grade_average?: number | null
  level?: Level
  school_option?: ClassRoom['school_option']
  current_school_year_id?: number | null
  divisionsCount?: number
}

const columns: Column<ClassTableRow>[] = [
  { key: 'name', label: 'Nom' },
  { key: 'student_count', label: 'Effectif' },
  { key: 'main_teacher', label: 'Prof principal' },
  { key: 'grade_average', label: 'Moyenne générale' },
  { key: 'actions', label: 'Actions', width: '1%', align: 'right' },
]

const structureColumns: Column<ClassTableRow>[] = [
  { key: 'name', label: 'Emplacement' },
  { key: 'divisions_count', label: 'Divisions créées' },
  { key: 'option', label: 'Option / filière', width: '18%' },
  { key: 'actions', label: 'Actions', width: '1%', align: 'right' },
]

const showForm = ref(false)
const editing = ref<Level | null>(null)
const formError = ref('')
const formErrors = reactive<Record<string, string[]>>({})
const submitting = ref(false)

const isGlobalAdmin = computed(() =>
  auth.user?.role === 'admin' && (auth.user.admin_scope ?? 'global') === 'global',
)

const activeSchoolYear = computed(() => schoolYearStore.selected ?? schoolYearStore.current)

const canManageStructure = computed(
  () => auth.hasRole('admin') && !!activeSchoolYear.value && !schoolYearStore.isViewingArchived,
)

const authorizedCycleValues = computed<LevelCycle[]>(() => {
  const allCycles = CYCLE_OPTIONS.map((cycle) => cycle.value)
  if (auth.user?.role !== 'admin' || isGlobalAdmin.value) return allCycles

  const allowed = auth.user.admin_cycles?.filter((cycle): cycle is LevelCycle =>
    allCycles.includes(cycle as LevelCycle),
  ) ?? []

  return allowed.length > 0 ? allowed : allCycles
})

const cycleOptions = computed(() =>
  CYCLE_OPTIONS.filter((cycle) => authorizedCycleValues.value.includes(cycle.value)),
)

const cycleTabs = computed<Array<{ value: CycleFilter; label: string }>>(() => [
  { value: 'all', label: 'Tous' },
  ...cycleOptions.value,
])

const viewTabs = computed<Array<{ value: ClassesViewTab; label: string }>>(() => {
  const tabs: Array<{ value: ClassesViewTab; label: string }> = [...cycleTabs.value]
  if (isGlobalAdmin.value) {
    tabs.push({ value: 'structure', label: 'Structure' })
  }
  return tabs
})

const isStructureView = computed(() => activeCycle.value === 'structure')

const activeColumns = computed(() => (isStructureView.value ? structureColumns : columns))

const form = reactive<{ name: string; cycle: LevelCycle; order: number }>({
  name: '',
  cycle: 'primaire',
  order: 0,
})

function sortClassRows(items: ClassTableRow[]): ClassTableRow[] {
  return [...items].sort((a, b) => {
    const levelOrder = (a.level?.order ?? 999) - (b.level?.order ?? 999)
    if (levelOrder !== 0) return levelOrder

    const optionCompare = (a.option ?? '').localeCompare(b.option ?? '', 'fr', { sensitivity: 'base' })
    if (optionCompare !== 0) return optionCompare

    const sectionCompare = (a.section ?? '').localeCompare(b.section ?? '', 'fr', { numeric: true, sensitivity: 'base' })
    if (sectionCompare !== 0) return sectionCompare

    return (a.full_name ?? '').localeCompare(b.full_name ?? '', 'fr', { numeric: true, sensitivity: 'base' })
  })
}

function classroomToRow(item: ClassRoom, schoolClassId: number): ClassTableRow {
  return {
    rowKey: `division-${item.id}`,
    id: item.id,
    classroomId: item.id,
    schoolClassId,
    isBaseOnly: false,
    full_name: item.full_name,
    level_id: item.level_id,
    school_option_id: item.school_option_id,
    section: item.section,
    option: item.option,
    capacity: item.capacity,
    student_count: item.student_count,
    main_teacher: item.main_teacher,
    grade_average: item.grade_average,
    level: item.level,
    school_option: item.school_option,
    current_school_year_id: item.current_school_year_id,
  }
}

function baseSchoolClassRow(item: SchoolClass): ClassTableRow {
  return {
    rowKey: `base-${item.id}`,
    id: -item.id,
    classroomId: null,
    schoolClassId: item.id,
    isBaseOnly: true,
    full_name: item.name,
    level_id: item.level_id,
    school_option_id: item.school_option_id,
    section: '',
    option: item.school_option?.name ?? '',
    student_count: 0,
    main_teacher: null,
    grade_average: null,
    level: item.level,
    school_option: item.school_option ?? undefined,
    current_school_year_id: activeSchoolYear.value?.id ?? null,
  }
}

function isCycleAuthorized(cycle?: LevelCycle | null): boolean {
  if (!cycle) return true
  return authorizedCycleValues.value.includes(cycle)
}

function normalizeClassSearch(value: string | null | undefined): string {
  return (value ?? '')
    .toString()
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
    .trim()
}

function rowMatchesSearch(row: ClassTableRow, term: string): boolean {
  if (!term) return true

  return [
    row.full_name,
    row.section,
    row.option,
    row.level?.name,
    row.main_teacher?.name,
    row.main_teacher?.email,
    row.main_teacher?.speciality,
  ].some((value) => normalizeClassSearch(value).includes(term))
}

function buildPlannedRows(): ClassTableRow[] {
  const rows: ClassTableRow[] = []
  let syntheticId = -1

  for (const level of levels.value) {
    if (!isCycleAuthorized(level.cycle)) continue

    if (level.has_options) {
      for (const option of schoolOptions.value) {
        rows.push({
          rowKey: `planned-${level.id}-${option.id}`,
          id: syntheticId--,
          classroomId: null,
          schoolClassId: 0,
          isBaseOnly: true,
          isPlanned: true,
          full_name: `${level.abbreviation || level.name} - ${option.abbreviation || option.name}`,
          level_id: level.id,
          school_option_id: option.id,
          section: '',
          option: option.name,
          student_count: 0,
          main_teacher: null,
          grade_average: null,
          level,
          school_option: option,
          current_school_year_id: activeSchoolYear.value?.id ?? null,
        })
      }
      continue
    }

    rows.push({
      rowKey: `planned-${level.id}`,
      id: syntheticId--,
      classroomId: null,
      schoolClassId: 0,
      isBaseOnly: true,
      isPlanned: true,
      full_name: level.abbreviation || level.name,
      level_id: level.id,
      school_option_id: null,
      section: '',
      option: '',
      student_count: 0,
      main_teacher: null,
      grade_average: null,
      level,
      current_school_year_id: activeSchoolYear.value?.id ?? null,
    })
  }

  return sortClassRows(rows)
}

function structureSchoolClassRow(item: SchoolClass): ClassTableRow {
  const divisionsCount = item.divisions_count ?? item.divisions?.length ?? 0

  return {
    ...baseSchoolClassRow(item),
    divisionsCount,
  }
}

const divisionRows = computed(() => {
  const rows: ClassTableRow[] = []
  const linkedClassroomIds = new Set<number>()
  const linkedDivisionKeys = new Set<string>()
  const classroomById = new Map(classrooms.value.map((item) => [item.id, item]))
  const yearSchoolClassIds = new Set(schoolClasses.value.map((item) => item.id))

  function divisionKey(
    levelId: number,
    section: string,
    option = '',
  ): string {
    return `${levelId}-${section}-${option}`
  }

  for (const schoolClass of schoolClasses.value) {
    const divisions = schoolClass.divisions ?? []

    if (divisions.length === 0) {
      const fallbackDivisions = classrooms.value.filter(
        (classroom) => classroom.school_class_id === schoolClass.id,
      )
      for (const division of fallbackDivisions) {
        linkedClassroomIds.add(division.id)
        linkedDivisionKeys.add(
          divisionKey(
            division.level_id,
            division.section,
            division.option ?? division.school_option?.name ?? '',
          ),
        )
        rows.push(classroomToRow(division, schoolClass.id))
      }
      continue
    }

    for (const division of divisions) {
      linkedClassroomIds.add(division.id)
      linkedDivisionKeys.add(
        divisionKey(
          division.level_id ?? schoolClass.level_id,
          division.section,
          division.school_option?.name ?? schoolClass.school_option?.name ?? division.option ?? '',
        ),
      )
      const classroom = classroomById.get(division.id)
      rows.push(classroom ? classroomToRow(classroom, schoolClass.id) : {
        rowKey: `division-${division.id}`,
        id: division.id,
        classroomId: division.id,
        schoolClassId: schoolClass.id,
        isBaseOnly: false,
        full_name: division.full_name,
        level_id: division.level_id ?? schoolClass.level_id,
        school_option_id: division.school_option_id ?? schoolClass.school_option_id,
        section: division.section,
        option: division.school_option?.name ?? schoolClass.school_option?.name ?? '',
        student_count: 0,
        main_teacher: null,
        grade_average: null,
        level: division.level ?? schoolClass.level,
        school_option: division.school_option ?? schoolClass.school_option ?? undefined,
        current_school_year_id: activeSchoolYear.value?.id ?? null,
      })
    }
  }

  for (const classroom of classrooms.value) {
    if (linkedClassroomIds.has(classroom.id)) continue

    if (
      classroom.school_class_id != null
      && yearSchoolClassIds.has(classroom.school_class_id)
    ) {
      continue
    }

    const key = divisionKey(
      classroom.level_id,
      classroom.section,
      classroom.option ?? classroom.school_option?.name ?? '',
    )
    // À clé identique, on n'écarte la classe d'une autre année que si elle n'a
    // aucun élève : une division qui porte une inscription sur l'année courante
    // (ex. issue d'un seed historique) doit primer sur sa jumelle vide.
    if (linkedDivisionKeys.has(key) && (classroom.student_count ?? 0) === 0) continue

    rows.push(
      classroomToRow(classroom, classroom.school_class_id ?? 0),
    )
  }

  // Déduplication finale par clé : on conserve la division la plus « peuplée »
  // pour éviter qu'une jumelle vide masque celle qui contient réellement les élèves.
  const rowsByKey = new Map<string, ClassTableRow>()
  for (const row of rows) {
    const key = divisionKey(
      row.level_id,
      row.section,
      row.option ?? row.school_option?.name ?? '',
    )
    const existing = rowsByKey.get(key)
    if (!existing || (row.student_count ?? 0) > (existing.student_count ?? 0)) {
      rowsByKey.set(key, row)
    }
  }

  const scopedRows = [...rowsByKey.values()].filter((row) => isCycleAuthorized(row.level?.cycle))

  return sortClassRows(scopedRows)
})

const structureCatalogRows = computed(() => {
  if (schoolClasses.value.length === 0 && levels.value.length > 0) {
    return buildPlannedRows()
  }

  return sortClassRows(
    scopedSchoolClasses.value.map((schoolClass) => structureSchoolClassRow(schoolClass)),
  )
})

const searchedDivisionRows = computed(() => {
  const term = normalizeClassSearch(classSearchQuery.value)
  const withStudents = divisionRows.value.filter((row) => (row.student_count ?? 0) > 0)
  if (!term) return withStudents
  return withStudents.filter((row) => rowMatchesSearch(row, term))
})

const searchedStructureRows = computed(() => {
  const term = normalizeClassSearch(classSearchQuery.value)
  if (!term) return structureCatalogRows.value

  return structureCatalogRows.value.filter((row) => rowMatchesSearch(row, term))
})

const groupedClassrooms = computed(() =>
  viewTabs.value.map((tab) => ({
    ...tab,
    classrooms: sortClassRows(
      tab.value === 'structure'
        ? searchedStructureRows.value
        : tab.value === 'all'
          ? searchedDivisionRows.value
          : searchedDivisionRows.value.filter((item) => item.level?.cycle === tab.value),
    ),
  })),
)

const activeGroup = computed(
  () =>
    groupedClassrooms.value.find((group) => group.value === activeCycle.value) ??
    groupedClassrooms.value[0],
)

const totalDivisions = computed(() => divisionRows.value.length)

const structureSlotsCount = computed(() => structureCatalogRows.value.length)

/** Divisions existantes pour le cycle actif, AVANT le filtre "élèves > 0". */
const activeCycleRawDivisions = computed(() => {
  const cycle = activeCycle.value
  if (cycle === 'all' || cycle === 'structure') return divisionRows.value
  return divisionRows.value.filter((r) => r.level?.cycle === cycle)
})

/** Vrai si des divisions existent pour ce cycle mais aucune n'a d'élève inscrit. */
const activeCycleExistsButEmpty = computed(
  () => activeGroup.value?.classrooms.length === 0 && activeCycleRawDivisions.value.length > 0,
)

const hasPlannedStructureOnly = computed(() =>
  structureCatalogRows.value.length > 0 && structureCatalogRows.value.every((row) => row.isPlanned),
)

const scopedSchoolClasses = computed(() =>
  schoolClasses.value.filter((item) => isCycleAuthorized(item.level?.cycle)),
)

function closeMenu(): void {
  openMenuId.value = null
}

async function loadSchoolClasses(): Promise<void> {
  const yearId = schoolYearStore.effectiveId ?? activeSchoolYear.value?.id
  if (!yearId) {
    schoolClasses.value = []
    return
  }

  const res = await api<Paginated<SchoolClass>>(`/api/v1/school-years/${yearId}/school-classes`)
  schoolClasses.value = res.data
}

function selectCycle(cycle: ClassesViewTab): void {
  closeMenu()
  activeCycle.value = cycle
  if (cycle !== 'structure') {
    selectedClassroomIds.value = []
  }
}

async function load(): Promise<void> {
  closeMenu()
  loading.value = true
  error.value = ''
  try {
    if (!schoolYearStore.initialized) {
      await schoolYearStore.init()
    }

    const [levelsRes, classroomsRes, optionsRes] = await Promise.all([
      api<Paginated<Level>>('/api/v1/levels'),
      api<Paginated<ClassRoom>>('/api/v1/classrooms'),
      api<Paginated<SchoolOption>>('/api/v1/school-options').catch(() => ({ data: [] as SchoolOption[] })),
    ])
    levels.value = levelsRes.data
    classrooms.value = classroomsRes.data
    schoolOptions.value = optionsRes.data
    await loadSchoolClasses()
    if (
      activeCycle.value !== 'all'
      && activeCycle.value !== 'structure'
      && !authorizedCycleValues.value.includes(activeCycle.value)
    ) {
      activeCycle.value = 'all'
    }
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Erreur de chargement.'
  } finally {
    loading.value = false
  }
}

async function generateSchoolClasses(): Promise<void> {
  const yearId = schoolYearStore.effectiveId ?? activeSchoolYear.value?.id
  if (!yearId || !canManageStructure.value) return

  generatingClasses.value = true
  error.value = ''
  try {
    await api(`/api/v1/school-years/${yearId}/generate-classes`, { method: 'POST' })
    await load()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Génération des classes impossible.'
  } finally {
    generatingClasses.value = false
  }
}

async function addDivisionForRow(item: ClassTableRow): Promise<void> {
  if (!canManageStructure.value || !item.schoolClassId) return

  addingSchoolClassId.value = item.schoolClassId
  closeMenu()
  error.value = ''
  try {
    if (item.isBaseOnly) {
      await api(`/api/v1/school-classes/${item.schoolClassId}/divisions`, {
        method: 'POST',
        body: { count: 1, capacity: DEFAULT_DIVISION_CAPACITY },
      })
    } else {
      await api(`/api/v1/school-classes/${item.schoolClassId}/divisions/next`, {
        method: 'POST',
        body: { capacity: DEFAULT_DIVISION_CAPACITY },
      })
    }
    await load()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Ajout de la division impossible.'
  } finally {
    addingSchoolClassId.value = null
  }
}

function openEdit(item: Level): void {
  editing.value = item
  form.name = item.name
  form.cycle = item.cycle
  form.order = item.order
  formError.value = ''
  Object.keys(formErrors).forEach((k) => delete formErrors[k])
  showForm.value = true
}

async function submit(): Promise<void> {
  submitting.value = true
  formError.value = ''
  Object.keys(formErrors).forEach((k) => delete formErrors[k])
  try {
    if (editing.value) {
      await api<ApiResource<Level>>(`/api/v1/levels/${editing.value.id}`, {
        method: 'PUT',
        body: { ...form },
      })
    } else {
      await api<ApiResource<Level>>('/api/v1/levels', { method: 'POST', body: { ...form } })
    }
    showForm.value = false
    await load()
  } catch (e) {
    if (e instanceof ApiError) {
      formError.value = e.message
      if (e.errors) Object.assign(formErrors, e.errors)
    } else {
      formError.value = 'Erreur réseau.'
    }
  } finally {
    submitting.value = false
  }
}

function averageLabel(value: number | null | undefined): string {
  return formatAveragePercent(value, 1)
}

function classroomRoute(item: ClassTableRow): RouteLocationRaw {
  if (item.current_school_year_id && item.classroomId) {
    return {
      name: 'school-year-class-detail',
      params: { id: item.current_school_year_id, classroomId: item.classroomId },
      query: { from: 'classes' },
    }
  }

  return { name: 'level-detail', params: { id: item.level_id } }
}

function openClassroom(item: ClassTableRow): void {
  if (item.isPlanned || item.isBaseOnly || !item.classroomId) return
  closeMenu()
  void router.push(classroomRoute(item))
}

function rowMenuId(item: ClassTableRow): number {
  return item.classroomId ?? item.id
}

function canOpenRowMenu(item: ClassTableRow): boolean {
  if (isStructureView.value) {
    return canManageStructure.value && item.schoolClassId > 0
  }
  if (item.isPlanned) return false
  return !!item.classroomId || (canManageStructure.value && !!item.schoolClassId)
}

function toggleMenu(item: ClassTableRow): void {
  const menuId = rowMenuId(item)
  openMenuId.value = openMenuId.value === menuId ? null : menuId
}

async function removeClassroom(item: ClassTableRow): Promise<void> {
  if (!item.classroomId) return
  closeMenu()
  const ok = await confirmDialog.ask({
    title: 'Supprimer une classe',
    message: 'Cette classe sera supprimée.',
    details: [item.full_name ?? 'Classe'],
    note: 'Vérifie qu’aucun élève ou planning actif ne dépend encore de cette classe.',
    confirmLabel: 'Supprimer',
    variant: 'danger',
  })
  if (!ok) return
  try {
    await api(`/api/v1/classrooms/${item.classroomId}`, { method: 'DELETE' })
    await load()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Suppression impossible.'
  }
}

const selectedDeletableRows = computed(() =>
  divisionRows.value.filter(
    (row) => selectedClassroomIds.value.includes(row.rowKey) && row.classroomId != null,
  ),
)

async function removeSelectedClassrooms(): Promise<void> {
  const rows = selectedDeletableRows.value
  const ids = [...new Set(rows.map((row) => row.classroomId!))]
  if (ids.length === 0) return
  const skipped = selectedClassroomIds.value.length - rows.length
  const ok = await confirmDialog.ask({
    title: 'Supprimer les classes sélectionnées',
    message: `Voulez-vous vraiment supprimer ${ids.length} division(s) ?`,
    note: skipped > 0
      ? `${skipped} entrée(s) planifiée(s) ou sans division ne peuvent pas être supprimées et seront ignorées.`
      : 'Les élèves, cours et plannings liés à ces divisions peuvent être impactés.',
    confirmLabel: 'Supprimer',
    variant: 'danger',
  })
  if (!ok) return

  bulkDeleting.value = true
  try {
    await Promise.all(ids.map((id) => api(`/api/v1/classrooms/${id}`, { method: 'DELETE' })))
    selectedClassroomIds.value = []
    await load()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Erreur lors de la suppression groupée.'
  } finally {
    bulkDeleting.value = false
  }
}

function editLevel(item: ClassTableRow): void {
  if (!item.level) return
  closeMenu()
  openEdit(item.level)
}

function onDocumentClick(event: MouseEvent): void {
  const target = event.target as HTMLElement
  if (target.closest('.options-cell')) return
  closeMenu()
}

function onDocumentKeydown(event: KeyboardEvent): void {
  if (event.key === 'Escape') {
    closeMenu()
  }
}

function onWindowScroll(): void {
  closeMenu()
}

watch(
  viewTabs,
  (tabs) => {
    if (!tabs.some((tab) => tab.value === activeCycle.value)) {
      activeCycle.value = 'all'
    }
  },
)

watch(
  () => schoolYearStore.effectiveId,
  () => {
    void load()
  },
)

onMounted(async () => {
  await load()
  document.addEventListener('click', onDocumentClick)
  document.addEventListener('keydown', onDocumentKeydown)
  window.addEventListener('scroll', onWindowScroll, true)
})

onUnmounted(() => {
  document.removeEventListener('click', onDocumentClick)
  document.removeEventListener('keydown', onDocumentKeydown)
  window.removeEventListener('scroll', onWindowScroll, true)
})
</script>

<template>
  <section>
    <div class="card">
      <div class="card-header">
        <div>
          <h1 style="margin: 0">Classes</h1>
          <p v-if="activeSchoolYear" class="header-meta">
            Année {{ activeSchoolYear.name }}
            <template v-if="isStructureView">
              <span class="header-meta-sep">·</span>
              <span>
                Catalogue structure — {{ structureSlotsCount }} emplacement(s)
                <template v-if="hasPlannedStructureOnly"> à générer</template>
              </span>
            </template>
            <template v-else>
              <span class="header-meta-sep">·</span>
              <span>{{ totalDivisions }} division(s) active(s)</span>
              <template v-if="isGlobalAdmin && structureSlotsCount > 0">
                <span class="header-meta-sep">·</span>
                <span>{{ structureSlotsCount }} emplacement(s) au catalogue</span>
              </template>
            </template>
          </p>
        </div>
        <div class="header-actions">
          <button
            v-if="canManageStructure && isGlobalAdmin"
            type="button"
            class="btn-secondary"
            :disabled="generatingClasses"
            @click="generateSchoolClasses"
          >
            {{ generatingClasses ? 'Génération…' : 'Générer les classes de base' }}
          </button>
        </div>
      </div>

      <p v-if="error" class="alert alert-error" style="margin: 1rem">{{ error }}</p>
      <div v-if="loading" class="empty-state">Chargement…</div>
      <div v-else-if="viewTabs.length === 0" class="empty-state">
        Aucun cycle autorisé dans votre périmètre.
      </div>

      <div v-else class="cycle-tabs-wrap">
        <div class="classes-search-bar">
          <label class="sr-only" for="classes-search">Rechercher une classe</label>
          <input
            id="classes-search"
            v-model="classSearchQuery"
            type="search"
            placeholder="Rechercher une classe, section, option ou professeur…"
            autocomplete="off"
          />
        </div>

        <div class="cycle-tabs" role="tablist" aria-label="Cycles de classes">
          <button
            v-for="group in groupedClassrooms"
            :key="group.value"
            type="button"
            class="cycle-tab"
            :class="{ active: activeCycle === group.value, 'structure-tab': group.value === 'structure' }"
            role="tab"
            :aria-selected="activeCycle === group.value"
            :aria-controls="`cycle-panel-${group.value}`"
            :id="`cycle-tab-${group.value}`"
            translate="no"
            @click="selectCycle(group.value)"
          >
            <span>{{ group.label }}</span>
            <strong>{{ group.classrooms.length }}</strong>
          </button>
        </div>

        <section
          v-if="activeGroup"
          :id="`cycle-panel-${activeGroup.value}`"
          class="cycle-panel"
          role="tabpanel"
          :aria-labelledby="`cycle-tab-${activeGroup.value}`"
        >
          <div class="cycle-heading">
            <h2 translate="no">{{ activeGroup.label }}</h2>
            <span v-if="isStructureView">{{ activeGroup.classrooms.length }} emplacement(s)</span>
            <span v-else>{{ activeGroup.classrooms.length }} division(s)</span>
          </div>

          <p v-if="isStructureView" class="structure-hint">
            Catalogue EPST/RDC pour l’année : emplacements niveau × option. Créez les divisions (A, B…)
            via « Générer les classes de base » ou « + Ajouter une division ».
          </p>

          <div
            v-if="selectedClassroomIds.length > 0 && !isStructureView"
            class="selection-strip"
            role="status"
            style="margin-bottom: 1rem; border-bottom: none;"
          >
            <div class="selection-summary">
              <strong>{{ selectedClassroomIds.length }}</strong>
              <span>classe(s) sélectionnée(s)</span>
            </div>
            <div class="bulk-actions" aria-label="Actions groupées">
              <button type="button" :disabled="bulkDeleting" @click="selectedClassroomIds = []">
                Désélectionner
              </button>
              <button
                type="button"
                class="bulk-danger"
                :disabled="bulkDeleting"
                @click="removeSelectedClassrooms"
              >
                {{ bulkDeleting ? 'Suppression…' : 'Supprimer' }}
              </button>
            </div>
          </div>

          <div v-if="activeGroup.classrooms.length === 0" class="empty-state compact">
            <template v-if="isStructureView">Aucun emplacement dans ce cycle.</template>
            <template v-else-if="activeCycleExistsButEmpty">
              <p>Aucun élève inscrit dans ce cycle.</p>
              <p class="empty-hint">
                Les divisions s’afficheront dès la première inscription d’un élève.
              </p>
            </template>
            <template v-else>
              <p>Aucune division dans ce cycle.</p>
              <p v-if="isGlobalAdmin" class="empty-hint">
                Consultez l’onglet <strong>Structure</strong> ou générez les classes de base.
              </p>
            </template>
          </div>

          <DataTable
            v-else
            :items="activeGroup.classrooms"
            :columns="activeColumns"
            key-field="rowKey"
            :selectable="!isStructureView"
            v-model:selected-ids="selectedClassroomIds"
            :row-clickable="!isStructureView"
            @row-click="openClassroom"
          >
            <template #col-name="{ item }">
              <strong>{{ item.full_name }}</strong>
              <span v-if="item.isPlanned" class="planned-badge">Catalogue EPST</span>
            </template>
            <template #col-divisions_count="{ item }">
              <span v-if="item.isPlanned" class="muted-cell">—</span>
              <span v-else-if="(item.divisionsCount ?? 0) === 0" class="muted-cell">Aucune</span>
              <span v-else>{{ item.divisionsCount }} division(s)</span>
            </template>
            <template #col-option="{ item }">
              <div class="option-cell">
                <span v-if="item.school_option?.name || item.option" class="option-name">
                  {{ item.school_option?.name || item.option }}
                </span>
                <span v-else class="muted-cell">Non applicable</span>
                <span
                  v-if="item.school_option?.filiere"
                  class="filiere-badge"
                  :class="`filiere-${item.school_option.filiere}`"
                >
                  {{ filiereLabel(item.school_option.filiere) }}
                </span>
              </div>
            </template>
            <template #col-student_count="{ item }">
              {{ item.student_count ?? 0 }} élève(s)
            </template>
            <template #col-main_teacher="{ item }">
              {{ item.main_teacher?.name ?? 'Non défini' }}
            </template>
            <template #col-grade_average="{ item }">
              {{ averageLabel(item.grade_average) }}
            </template>
            <template #col-actions="{ item, index }">
              <div class="options-cell" @click.stop>
                <button
                  v-if="canOpenRowMenu(item)"
                  type="button"
                  class="icon-menu-btn"
                  aria-label="Options de la classe"
                  :aria-expanded="openMenuId === rowMenuId(item)"
                  @click.stop="toggleMenu(item)"
                >
                  <span class="menu-bars" aria-hidden="true">
                    <i />
                    <i />
                    <i />
                  </span>
                </button>
                <Transition name="row-menu-fade">
                  <div
                    v-if="canOpenRowMenu(item) && openMenuId === rowMenuId(item)"
                    class="row-menu"
                    :class="{ 'row-menu-up': index >= activeGroup.classrooms.length - 2 }"
                    @click.stop
                  >
                    <button v-if="item.classroomId" type="button" @click="openClassroom(item)">Ouvrir</button>
                    <button v-if="item.classroomId && isGlobalAdmin" type="button" @click="editLevel(item)">
                      Modifier le niveau
                    </button>
                    <button
                      v-if="item.classroomId"
                      type="button"
                      class="danger-option"
                      @click="removeClassroom(item)"
                    >
                      Supprimer
                    </button>
                    <button
                      v-if="isStructureView && canManageStructure && item.schoolClassId"
                      type="button"
                      class="menu-add-option"
                      :disabled="addingSchoolClassId === item.schoolClassId"
                      @click="addDivisionForRow(item)"
                    >
                      {{ addingSchoolClassId === item.schoolClassId ? 'Ajout…' : '+ Ajouter une division' }}
                    </button>
                  </div>
                </Transition>
              </div>
            </template>
          </DataTable>
        </section>
      </div>
    </div>

    <Modal
      :open="showForm"
      :title="editing ? 'Modifier un niveau de classe' : 'Nouveau niveau de classe'"
      @close="showForm = false"
    >
      <form id="level-form" @submit.prevent="submit">
        <div class="field">
          <label for="lv-name">Nom (ex. 1ère primaire, 1ère secondaire)</label>
          <input id="lv-name" v-model="form.name" type="text" required maxlength="64" />
          <small v-if="formErrors.name" class="err">{{ formErrors.name[0] }}</small>
        </div>
        <div class="field">
          <label for="lv-cycle">Cycle</label>
          <select id="lv-cycle" v-model="form.cycle" required>
            <option v-for="cycle in CYCLE_OPTIONS" :key="cycle.value" :value="cycle.value" translate="no">
              {{ cycle.label }}
            </option>
          </select>
          <small v-if="formErrors.cycle" class="err">{{ formErrors.cycle[0] }}</small>
        </div>
        <div class="field">
          <label for="lv-order">Ordre d'affichage</label>
          <input id="lv-order" v-model.number="form.order" type="number" min="0" max="255" />
          <small v-if="formErrors.order" class="err">{{ formErrors.order[0] }}</small>
        </div>
        <p v-if="formError" class="alert alert-error">{{ formError }}</p>
      </form>
      <template #footer>
        <button type="button" @click="showForm = false">Annuler</button>
        <button type="submit" form="level-form" class="btn-primary" :disabled="submitting">
          {{ submitting ? 'Enregistrement…' : editing ? 'Enregistrer' : 'Créer' }}
        </button>
      </template>
    </Modal>
  </section>
</template>

<style scoped>
button + button { margin-left: 0.4rem; }
.card { overflow: visible; }
.err { display: block; color: var(--danger); font-size: 0.78rem; margin-top: 0.25rem; }
.header-meta {
  color: var(--text-muted);
  font-size: 0.86rem;
  margin: 0.35rem 0 0;
}
.header-meta-sep { margin: 0 0.35rem; }
.header-actions {
  align-items: center;
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}
.header-actions button { margin-left: 0; }
.structure-link {
  align-items: center;
  display: inline-flex;
  text-decoration: none;
}
.menu-add-option {
  color: var(--primary);
  font-weight: 750;
}
.menu-add-option:disabled {
  opacity: 0.6;
  cursor: wait;
}
.cycle-tabs-wrap { padding: 1rem; }

.classes-search-bar {
  margin-bottom: 1rem;
}

.classes-search-bar input {
  width: 100%;
  min-height: 2.5rem;
  padding: 0.55rem 0.85rem;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  font-size: 0.92rem;
  background: var(--bg-card);
}

.classes-search-bar input:focus-visible {
  outline: 2px solid var(--primary);
  outline-offset: 1px;
  border-color: var(--primary);
}

.sr-only {
  width: 1px;
  height: 1px;
  position: absolute;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
}
:deep(.table-container) {
  overflow: visible;
}
:deep(.data-table td:last-child) {
  overflow: visible;
}
.cycle-tabs {
  display: flex;
  align-items: center;
  gap: 0.45rem;
  flex-wrap: wrap;
  margin-bottom: 1rem;
}
.cycle-tab {
  min-height: 2.35rem;
  padding: 0.45rem 0.8rem;
  border: 1px solid var(--border);
  border-radius: 8px;
  background: var(--bg-soft);
  color: var(--text-soft);
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  justify-content: center;
  margin: 0;
  font-size: 0.86rem;
  font-weight: 800;
  white-space: nowrap;
}
.cycle-tab:hover {
  border-color: var(--primary);
  color: var(--primary);
}
.cycle-tab strong {
  align-items: center;
  background: var(--primary-soft);
  border-radius: 999px;
  color: var(--accent);
  display: inline-flex;
  font-size: 0.78rem;
  justify-content: center;
  min-width: 1.5rem;
  padding: 0.05rem 0.45rem;
}
.cycle-tab.active {
  border-color: var(--primary);
  background: var(--primary);
  color: #fff;
  box-shadow: 0 8px 18px rgb(37 99 235 / 16%);
}
.cycle-tab.active strong {
  background: rgb(255 255 255 / 18%);
  color: #fff;
}
.cycle-panel { min-height: 12rem; }
.cycle-heading {
  align-items: baseline;
  display: flex;
  justify-content: space-between;
  margin-bottom: 0.75rem;
}
.cycle-heading h2 { font-size: 1.05rem; margin: 0; }
.cycle-heading span { color: var(--text-soft); font-size: 0.85rem; }
.empty-state.compact { padding: 1rem; }
.classroom-table td { vertical-align: middle; }
.classroom-row {
  cursor: pointer;
  outline: none;
}
.classroom-row:hover { background: var(--primary-tint); }
.classroom-row:focus-visible {
  box-shadow: inset 0 0 0 2px var(--primary);
}
.classroom-row small {
  color: var(--text-soft);
  display: block;
  font-size: 0.8rem;
  margin-top: 0.12rem;
}
.filiere-badge {
  display: inline-flex;
  align-items: center;
  margin-top: 0.3rem;
  padding: 0.16rem 0.5rem;
  border-radius: 999px;
  font-size: 0.7rem;
  font-weight: 700;
  letter-spacing: 0.01em;
  white-space: nowrap;
}
.filiere-generale {
  background: rgba(59, 130, 246, 0.15);
  color: var(--accent);
  border: 1px solid rgba(59, 130, 246, 0.25);
}
.filiere-technique {
  background: var(--warn-soft);
  color: var(--warn);
  border: 1px solid rgba(251, 191, 36, 0.25);
}
.filiere-professionnelle {
  background: var(--success-soft);
  color: var(--success);
  border: 1px solid rgba(74, 222, 128, 0.25);
}
.options-cell {
  position: relative;
  display: inline-block;
  text-align: right;
  white-space: nowrap;
}
.icon-menu-btn {
  align-items: center;
  display: inline-flex;
  font-size: 1.05rem;
  justify-content: center;
  min-height: 2rem;
  padding: 0;
  width: 2.25rem;
}
.menu-bars {
  display: inline-grid;
  gap: 0.18rem;
  width: 1rem;
}
.menu-bars i {
  display: block;
  height: 0.12rem;
  border-radius: 999px;
  background: currentColor;
}
.row-menu {
  background: var(--bg-card);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  box-shadow: var(--shadow-card);
  display: grid;
  min-width: 13rem;
  padding: 0.35rem;
  position: absolute;
  right: 0;
  top: calc(100% + 0.25rem);
  z-index: 40;
}
.row-menu-up {
  bottom: calc(100% + 0.25rem);
  top: auto;
}
.row-menu-fade-enter-active,
.row-menu-fade-leave-active {
  transition:
    opacity 0.12s ease,
    transform 0.12s ease;
}
.row-menu-fade-enter-from,
.row-menu-fade-leave-to {
  opacity: 0;
  transform: translateY(-0.25rem) scale(0.98);
}
.row-menu-up.row-menu-fade-enter-from,
.row-menu-up.row-menu-fade-leave-to {
  transform: translateY(0.25rem) scale(0.98);
}
.row-menu button {
  border-color: transparent;
  box-shadow: none;
  justify-content: flex-start;
  margin: 0;
  min-height: 2rem;
  text-align: left;
  width: 100%;
}
.row-menu button + button { margin-left: 0; }
.row-menu .danger-option {
  color: var(--danger);
}

@media (max-width: 640px) {
  .cycle-tabs { align-items: stretch; }
  .cycle-tab {
    flex: 1 1 8rem;
    justify-content: space-between;
  }
  .class-form-grid { grid-template-columns: 1fr; }
  .classroom-table {
    display: block;
    overflow-x: auto;
    white-space: nowrap;
  }
}
.cycle-tab.structure-tab.active {
  border-color: var(--text-muted);
  background: var(--text-muted);
  box-shadow: 0 8px 18px rgba(74, 106, 144, 0.2);
}
.structure-hint,
.empty-hint {
  margin: 0 0 1rem;
  padding: 0.75rem 1rem;
  border-radius: 8px;
  background: var(--bg-soft);
  border: 1px solid var(--border);
  color: var(--text-soft);
  font-size: 0.88rem;
  line-height: 1.45;
}
.row-subtitle {
  display: block;
  margin-top: 0.16rem;
  color: var(--text-muted);
  font-size: 0.72rem;
}
.muted-cell {
  color: var(--text-muted);
}
.option-cell {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 0.35rem;
  min-width: 10rem;
}
.option-name {
  font-weight: 700;
  color: var(--text);
  line-height: 1.35;
}
.option-cell .filiere-badge {
  margin-top: 0;
}
.planned-badge {
  display: inline-flex;
  margin-left: 0.45rem;
  padding: 0.1rem 0.45rem;
  border-radius: 999px;
  background: var(--warn-soft);
  color: var(--warn);
  font-size: 0.68rem;
  font-weight: 700;
  vertical-align: middle;
}

</style>
