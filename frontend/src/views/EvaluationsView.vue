<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { ClipboardList, GraduationCap, FileEdit, CheckCircle2 } from 'lucide-vue-next'
import { api, ApiError } from '../api/client'
import type { ApiResource, ClassRoom, Evaluation, Level, LevelCycle, Paginated, Period, SchoolYear, Subject, Term, TermCycle } from '../types'
import Modal from '../components/Modal.vue'
import RowActionMenu from '../components/RowActionMenu.vue'
import { useConfirmStore } from '../stores/confirm'
import { useToastStore } from '../stores/toast'
import { useSchoolYearStore } from '../stores/schoolYear'
import { useAuthStore } from '../stores/auth'
import { useTermCycleScope } from '../composables/useTermCycleScope'

interface EvaluationsSummary {
  total: number
  exams: number
  continuous: number
  drafts: number
}

interface EvaluationsResponse extends Paginated<Evaluation> {
  summary: EvaluationsSummary
}

const schoolYearStore = useSchoolYearStore()
const confirmDialog = useConfirmStore()
const toast = useToastStore()
const auth = useAuthStore()
const { filterTerms } = useTermCycleScope()
const router = useRouter()

const items = ref<Evaluation[]>([])
const currentPage = ref(1)
const lastPage = ref(1)
const totalItems = ref(0)
const levels = ref<Level[]>([])
const teacherClassrooms = ref<ClassRoom[]>([])
const subjects = ref<Subject[]>([])
const loading = ref(false)
const error = ref('')

const filterClassroom = ref<number | ''>('')
const filterSubject = ref<number | ''>('')
const filterTerm = ref<number | ''>('')
const filterPeriod = ref<number | ''>('')
const filterComponent = ref<'' | 'exam' | 'continuous' | 'draft'>('')

const isAdmin = computed(() => auth.user?.role === 'admin')
const isTeacher = computed(() => auth.user?.role === 'enseignant')

const allClassrooms = computed<ClassRoom[]>(() =>
  isTeacher.value
    ? teacherClassrooms.value
    : levels.value.flatMap((l) => (l.classrooms ?? []).map((cr) => ({ ...cr, level: l }))),
)

const subjectsForSelectedClassroom = computed(() => {
  const classroomId = showForm.value && form.classroom_id > 0
    ? form.classroom_id
    : (filterClassroom.value !== '' ? Number(filterClassroom.value) : 0)
  if (classroomId === 0) return subjects.value

  return subjects.value.filter((subject) => subject.classroom_id === classroomId)
})

const schoolYears = computed<SchoolYear[]>(() =>
  schoolYearStore.years.length > 0
    ? schoolYearStore.years
    : schoolYearStore.current ? [schoolYearStore.current] : [],
)

const selectedSchoolYearId = computed(() => schoolYearStore.effectiveId)

const allTerms = computed<Term[]>(() =>
  filterTerms(
    schoolYears.value
      .flatMap((y) => y.terms ?? [])
      .filter((s) => selectedSchoolYearId.value === null || s.school_year_id === selectedSchoolYearId.value),
  ),
)

const allPeriods = computed<Period[]>(() =>
  allTerms.value.flatMap((term) => (term.periods ?? []).map((period) => ({ ...period, term }))),
)

const periodsForFilter = computed<Period[]>(() => {
  const base =
    filterTerm.value === ''
      ? allPeriods.value
      : allPeriods.value.filter((period) => period.term_id === filterTerm.value)

  if (filterClassroom.value === '') return base

  const allowedTermIds = new Set(termsForClassroomId(filterClassroom.value).map((term) => term.id))
  return base.filter((period) => allowedTermIds.has(period.term_id))
})

function termApplicableCycleForLevel(levelCycle?: LevelCycle | null): TermCycle {
  return levelCycle === 'secondaire' || levelCycle === 'cteb' ? 'secondaire' : 'primaire'
}

function termsForClassroomId(classroomId: number | ''): Term[] {
  if (classroomId === '') return allTerms.value
  const classroom = allClassrooms.value.find((item) => item.id === classroomId)
  if (!classroom) return allTerms.value
  const expectedCycle = termApplicableCycleForLevel(classroom.level?.cycle)
  return allTerms.value.filter((term) => (term.applicable_cycle ?? 'primaire') === expectedCycle)
}

/** Élément « en cours » aujourd'hui (starts_on ≤ auj. ≤ ends_on) dans une liste triée par date. */
function pickCurrent<T extends { starts_on: string; ends_on: string }>(list: T[]): T | undefined {
  if (list.length === 0) return undefined
  const today = new Date().toISOString().slice(0, 10)
  const active = list.find((item) => item.starts_on <= today && today <= item.ends_on)
  if (active) return active
  // Entre deux périodes (vacances, avant la rentrée…) : la dernière déjà
  // entamée, sinon la toute première à venir.
  const started = [...list]
    .filter((item) => item.starts_on <= today)
    .sort((a, b) => b.starts_on.localeCompare(a.starts_on))[0]
  return started ?? [...list].sort((a, b) => a.starts_on.localeCompare(b.starts_on))[0]
}

function currentTermFor(classroomId: number | ''): Term | undefined {
  return pickCurrent(termsForClassroomId(classroomId))
}

function currentPeriodFor(termId: number): Period | undefined {
  return pickCurrent(allPeriods.value.filter((period) => period.term_id === termId))
}

function classroomLabel(c: ClassRoom): string {
  return c.full_name ?? `${c.level?.name ?? ''} ${c.section}`.trim()
}

function periodLabel(period: Period): string {
  const term = allTerms.value.find((item) => item.id === period.term_id)
  return `${period.name}${term ? ' — ' + term.name : ''}`
}

const showForm = ref(false)
const editing = ref<Evaluation | null>(null)
const formError = ref('')
const formErrors = reactive<Record<string, string[]>>({})
const submitting = ref(false)

const TEACHER_TYPES = [
  { value: 'interrogation', label: 'Interrogation' },
  { value: 'devoir', label: 'Devoir' },
  { value: 'oral', label: 'Oral' },
  { value: 'projet', label: 'Projet' },
] as const

const TYPE_LABELS: Record<Evaluation['type'], string> = {
  interrogation: 'Interrogation',
  controle: 'Interrogation',
  devoir: 'Devoir',
  examen: 'Examen de période',
  oral: 'Oral',
  projet: 'Projet',
}

const form = reactive({
  classroom_id: 0,
  subject_id: 0,
  term_id: 0,
  period_id: 0,
  name: '',
  type: 'interrogation' as Evaluation['type'],
  held_on: new Date().toISOString().slice(0, 10),
  max_value: 20,
})

const allowedFormTypes = computed(() => (isAdmin.value ? [{ value: 'examen', label: 'Examen de période' }] : TEACHER_TYPES))

const createButtonLabel = computed(() => (isAdmin.value ? '+ Nouvel examen' : '+ Nouvelle évaluation'))

const roleBanner = computed(() => {
  if (isAdmin.value) {
    return {
      tone: 'exam' as const,
      title: 'Examens de période (60 %)',
      text: 'En tant qu’administration, vous créez et gérez uniquement les examens de période. Le contrôle continu est assuré par les enseignants.',
    }
  }
  if (isTeacher.value) {
    return {
      tone: 'continuous' as const,
      title: 'Contrôle continu (40 %)',
      text: 'Vous créez les interrogations, devoirs, oraux et projets. Les examens de période sont réservés à l’administration.',
    }
  }
  return null
})

const termsForForm = computed(() => termsForClassroomId(form.classroom_id))
const termsForFilterSelect = computed(() =>
  filterClassroom.value === '' ? allTerms.value : termsForClassroomId(filterClassroom.value),
)
const periodsForForm = computed<Period[]>(() =>
  allPeriods.value.filter((period) => period.term_id === form.term_id),
)

const summary = ref<EvaluationsSummary>({ total: 0, exams: 0, continuous: 0, drafts: 0 })

function isExamType(type: Evaluation['type']): boolean {
  return type === 'examen'
}

function canEditEvaluation(item: Evaluation): boolean {
  if (isAdmin.value) return isExamType(item.type)
  if (isTeacher.value) return !isExamType(item.type)
  return false
}

function canDeleteEvaluation(item: Evaluation): boolean {
  return canEditEvaluation(item)
}

function hasSecondaryActions(item: Evaluation): boolean {
  return (
    !item.is_published
    || (item.is_published && isAdmin.value)
    || canEditEvaluation(item)
    || canDeleteEvaluation(item)
  )
}

function evaluationTypeLabel(item: Evaluation): string {
  if (item.type_label) return item.type_label
  return TYPE_LABELS[item.type] ?? item.type
}

function componentLabel(item: Evaluation): string {
  return isExamType(item.type) ? 'Examen · 60 %' : 'Continu · 40 %'
}

function syncFormTermForClassroom(): void {
  const terms = termsForForm.value
  if (!terms.some((term) => term.id === form.term_id)) {
    form.term_id = currentTermFor(form.classroom_id)?.id ?? terms[0]?.id ?? 0
  }
  if (!periodsForForm.value.some((period) => period.id === form.period_id)) {
    form.period_id = currentPeriodFor(form.term_id)?.id ?? periodsForForm.value[0]?.id ?? 0
  }
}

function defaultFormType(): Evaluation['type'] {
  return isAdmin.value ? 'examen' : 'interrogation'
}

function syncFormSubjectForClassroom(): void {
  const available = subjectsForSelectedClassroom.value
  if (!available.some((subject) => subject.id === form.subject_id)) {
    form.subject_id = available[0]?.id ?? 0
  }
}

function resetForm(): void {
  form.classroom_id = allClassrooms.value[0]?.id ?? 0
  syncFormSubjectForClassroom()
  if (form.subject_id === 0) {
    form.subject_id = subjects.value[0]?.id ?? 0
  }
  // Toujours la période/le terme « en cours » à la création — jamais un reliquat
  // d'une précédente ouverture du formulaire (édition ou classe différente).
  form.term_id = currentTermFor(form.classroom_id)?.id ?? termsForForm.value[0]?.id ?? 0
  form.period_id = currentPeriodFor(form.term_id)?.id ?? periodsForForm.value[0]?.id ?? 0
  form.name = ''
  form.type = defaultFormType()
  form.held_on = new Date().toISOString().slice(0, 10)
  form.max_value = 20
  formError.value = ''
  Object.keys(formErrors).forEach((k) => delete formErrors[k])
}

async function loadRefs(): Promise<void> {
  await schoolYearStore.fetchAll()

  if (isTeacher.value) {
    const [classroomsRes, subjectsRes] = await Promise.all([
      api<Paginated<ClassRoom>>('/api/v1/classrooms'),
      api<Paginated<Subject>>('/api/v1/subjects'),
    ])
    teacherClassrooms.value = classroomsRes.data
    subjects.value = subjectsRes.data
    levels.value = []
    return
  }

  const [levelsRes, subjectsRes] = await Promise.all([
    api<Paginated<Level>>('/api/v1/levels'),
    api<Paginated<Subject>>('/api/v1/subjects'),
  ])
  levels.value = levelsRes.data
  subjects.value = subjectsRes.data
  teacherClassrooms.value = []
}

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    const query: Record<string, string | number> = { page: currentPage.value }
    if (filterClassroom.value !== '') query.classroom_id = filterClassroom.value
    if (filterSubject.value !== '') query.subject_id = filterSubject.value
    if (filterTerm.value !== '') query.term_id = filterTerm.value
    if (filterPeriod.value !== '') query.period_id = filterPeriod.value
    if (filterComponent.value !== '') query.component = filterComponent.value
    const res = await api<EvaluationsResponse>('/api/v1/evaluations', { query })
    items.value = res.data
    currentPage.value = res.meta?.current_page ?? 1
    lastPage.value = res.meta?.last_page ?? 1
    totalItems.value = res.meta?.total ?? res.data.length
    summary.value = res.summary
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Erreur de chargement.'
  } finally {
    loading.value = false
  }
}

function goToPage(page: number): void {
  if (page < 1 || page > lastPage.value || page === currentPage.value) return
  currentPage.value = page
  void load()
}

function openCreate(): void {
  editing.value = null
  resetForm()
  showForm.value = true
}

function openEdit(item: Evaluation): void {
  if (!canEditEvaluation(item)) return
  editing.value = item
  form.classroom_id = item.classroom_id
  form.subject_id = item.subject_id
  form.term_id = item.term_id
  form.period_id = item.period_id
  form.name = item.name
  form.type = item.type === 'controle' ? 'interrogation' : item.type
  form.held_on = item.held_on
  form.max_value = item.max_value
  formError.value = ''
  Object.keys(formErrors).forEach((k) => delete formErrors[k])
  showForm.value = true
}

async function submit(): Promise<void> {
  submitting.value = true
  formError.value = ''
  Object.keys(formErrors).forEach((k) => delete formErrors[k])
  if (isAdmin.value) form.type = 'examen'
  try {
    if (editing.value) {
      await api<ApiResource<Evaluation>>(`/api/v1/evaluations/${editing.value.id}`, {
        method: 'PUT',
        body: { ...form },
      })
    } else {
      await api<ApiResource<Evaluation>>('/api/v1/evaluations', { method: 'POST', body: { ...form } })
    }
    toast.success(editing.value ? 'Évaluation mise à jour.' : 'Évaluation créée.')
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

async function remove(item: Evaluation): Promise<void> {
  if (!canDeleteEvaluation(item)) return
  const ok = await confirmDialog.ask({
    title: 'Supprimer une évaluation',
    message: 'Cette évaluation et toutes ses notes seront supprimées.',
    details: [item.name],
    note: 'Cette action impacte les bulletins et les moyennes associées.',
    confirmLabel: 'Supprimer',
    variant: 'danger',
  })
  if (!ok) return
  try {
    await api(`/api/v1/evaluations/${item.id}`, { method: 'DELETE' })
    toast.success(`Évaluation « ${item.name} » supprimée.`)
    await load()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Suppression impossible.'
  }
}

function openGrades(item: Evaluation): void {
  router.push({ name: 'grade-entry', params: { id: item.id } })
}

async function publishEvaluation(item: Evaluation): Promise<void> {
  try {
    await api(`/api/v1/evaluations/${item.id}/publish`, { method: 'POST' })
    toast.success(`« ${item.name} » publiée : notes visibles des élèves et parents.`)
    await load()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Erreur de publication.'
  }
}

async function unpublishEvaluation(item: Evaluation): Promise<void> {
  try {
    await api(`/api/v1/evaluations/${item.id}/unpublish`, { method: 'POST' })
    toast.info(`« ${item.name} » repassée en brouillon.`)
    await load()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Erreur de dépublication.'
  }
}

const allowedTermCyclesKey = computed(() =>
  auth.user?.term_applicable_cycles === null
    ? 'all'
    : (auth.user?.term_applicable_cycles ?? []).join(','),
)

watch(
  () => allowedTermCyclesKey.value,
  () => {
    if (filterTerm.value !== '' && !allTerms.value.some((term) => term.id === filterTerm.value)) {
      filterTerm.value = ''
    }
  },
)

watch([filterClassroom, filterSubject, filterTerm, filterPeriod, filterComponent], () => {
  currentPage.value = 1
  void load()
})
watch(filterTerm, () => {
  if (filterPeriod.value !== '' && !periodsForFilter.value.some((period) => period.id === filterPeriod.value)) {
    filterPeriod.value = ''
  }
})
watch(filterClassroom, () => {
  if (filterTerm.value !== '' && !termsForFilterSelect.value.some((term) => term.id === filterTerm.value)) {
    filterTerm.value = ''
  }
})
watch(
  () => form.classroom_id,
  () => {
    syncFormTermForClassroom()
    syncFormSubjectForClassroom()
  },
)
watch(
  () => form.term_id,
  () => {
    if (!periodsForForm.value.some((period) => period.id === form.period_id)) {
      form.period_id = periodsForForm.value[0]?.id ?? 0
    }
  },
)
watch(
  () => schoolYearStore.effectiveId,
  async () => {
    await loadRefs()
    if (filterClassroom.value !== '' && !allClassrooms.value.some((classroom) => classroom.id === filterClassroom.value)) {
      filterClassroom.value = ''
    }
    if (filterSubject.value !== '' && !subjects.value.some((subject) => subject.id === filterSubject.value)) {
      filterSubject.value = ''
    }
    if (filterTerm.value !== '' && !allTerms.value.some((s) => s.id === filterTerm.value)) {
      filterTerm.value = ''
    }
    if (filterPeriod.value !== '' && !allPeriods.value.some((period) => period.id === filterPeriod.value)) {
      filterPeriod.value = ''
    }
    if (!editing.value && showForm.value) {
      form.term_id = allTerms.value[0]?.id ?? 0
      form.period_id = periodsForForm.value[0]?.id ?? 0
    }
    await load()
  },
)

onMounted(async () => {
  await loadRefs()
  await load()
})
</script>

<template>
  <section class="evaluations-page">
    <div class="page-header">
      <div>
        <h1 class="page-title">Évaluations</h1>
        <p class="page-subtitle">Planification, saisie et publication des notes par période.</p>
      </div>
      <button type="button" class="btn-primary create-btn" @click="openCreate">{{ createButtonLabel }}</button>
    </div>

    <div v-if="roleBanner" class="role-banner" :class="roleBanner.tone">
      <div class="role-banner-icon">
        <GraduationCap v-if="roleBanner.tone === 'exam'" aria-hidden="true" />
        <ClipboardList v-else aria-hidden="true" />
      </div>
      <div>
        <strong>{{ roleBanner.title }}</strong>
        <p>{{ roleBanner.text }}</p>
      </div>
    </div>

    <div v-if="summary.total > 0 || loading" class="summary-bar">
      <article class="summary-card">
        <span class="summary-label">Total</span>
        <strong>{{ summary.total }}</strong>
      </article>
      <article class="summary-card exam">
        <span class="summary-label">Examens</span>
        <strong>{{ summary.exams }}</strong>
      </article>
      <article class="summary-card continuous">
        <span class="summary-label">Contrôle continu</span>
        <strong>{{ summary.continuous }}</strong>
      </article>
      <article class="summary-card draft">
        <span class="summary-label">Brouillons</span>
        <strong>{{ summary.drafts }}</strong>
      </article>
    </div>

    <div class="card main-card">
      <div class="filters-panel">
        <div class="filter-field">
          <label for="f-class">Classe</label>
          <select id="f-class" v-model="filterClassroom">
            <option value="">Toutes les classes</option>
            <option v-for="c in allClassrooms" :key="c.id" :value="c.id">{{ classroomLabel(c) }}</option>
          </select>
        </div>
        <div class="filter-field">
          <label for="f-subj">Cours</label>
          <select id="f-subj" v-model="filterSubject">
            <option value="">Tous les cours</option>
            <option v-for="s in subjectsForSelectedClassroom" :key="s.id" :value="s.id">{{ s.name }}</option>
          </select>
        </div>
        <div class="filter-field">
          <label for="f-term">Trimestre / Semestre</label>
          <select id="f-term" v-model="filterTerm">
            <option value="">Tous les termes</option>
            <option v-for="s in termsForFilterSelect" :key="s.id" :value="s.id">{{ s.name }}</option>
          </select>
        </div>
        <div class="filter-field">
          <label for="f-period">Période</label>
          <select id="f-period" v-model="filterPeriod" :disabled="periodsForFilter.length === 0">
            <option value="">Toutes les périodes</option>
            <option v-for="period in periodsForFilter" :key="period.id" :value="period.id">{{ periodLabel(period) }}</option>
          </select>
        </div>
      </div>

      <div class="component-tabs" role="tablist" aria-label="Filtrer par composante">
        <button
          type="button"
          role="tab"
          :aria-selected="filterComponent === ''"
          :class="{ active: filterComponent === '' }"
          @click="filterComponent = ''"
        >
          Toutes
        </button>
        <button
          type="button"
          role="tab"
          :aria-selected="filterComponent === 'exam'"
          :class="{ active: filterComponent === 'exam' }"
          @click="filterComponent = 'exam'"
        >
          Examens (60 %)
        </button>
        <button
          type="button"
          role="tab"
          :aria-selected="filterComponent === 'continuous'"
          :class="{ active: filterComponent === 'continuous' }"
          @click="filterComponent = 'continuous'"
        >
          Contrôle continu (40 %)
        </button>
        <button
          type="button"
          role="tab"
          :aria-selected="filterComponent === 'draft'"
          :class="{ active: filterComponent === 'draft' }"
          @click="filterComponent = 'draft'"
        >
          Brouillons ({{ summary.drafts }})
        </button>
      </div>

      <p v-if="error" class="alert alert-error list-alert">{{ error }}</p>
      <div v-if="loading" class="empty-state">Chargement…</div>
      <div v-else-if="items.length === 0" class="empty-state">
        <FileEdit aria-hidden="true" class="empty-icon" />
        <p>Aucune évaluation ne correspond à vos filtres.</p>
        <button type="button" class="btn-primary" @click="openCreate">{{ createButtonLabel }}</button>
      </div>

      <div v-else class="table-wrap">
        <table class="eval-table">
          <thead>
            <tr>
              <th>Évaluation</th>
              <th>Classe / Cours</th>
              <th>Période</th>
              <th>Composante</th>
              <th class="num">Note max</th>
              <th class="num">Notes saisies</th>
              <th class="actions-col">Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="(item, index) in items"
              :key="item.id"
              :class="{ 'row-exam': isExamType(item.type) }"
            >
              <td>
                <div class="eval-name-cell">
                  <strong>{{ item.name }}</strong>
                  <span class="eval-meta">{{ item.held_on }}</span>
                  <span v-if="item.is_published" class="badge badge-published">
                    <CheckCircle2 aria-hidden="true" /> Publié
                  </span>
                  <span v-else class="badge badge-draft">Brouillon</span>
                </div>
              </td>
              <td>
                <div class="stack-cell">
                  <span>{{ item.classroom ? classroomLabel(item.classroom) : '—' }}</span>
                  <span class="muted">{{ item.subject?.name ?? '—' }}</span>
                </div>
              </td>
              <td>{{ item.period ? periodLabel(item.period) : '—' }}</td>
              <td>
                <span class="badge" :class="isExamType(item.type) ? 'badge-exam' : 'badge-continuous'">
                  {{ evaluationTypeLabel(item) }}
                </span>
                <span class="component-hint">{{ componentLabel(item) }}</span>
              </td>
              <td class="num">{{ item.max_value }}</td>
              <td class="num">{{ item.grades_count ?? 0 }}</td>
              <td class="actions-col">
                <div class="eval-actions">
                  <button
                    type="button"
                    class="eval-action-primary"
                    @click="openGrades(item)"
                  >
                    Saisir notes
                  </button>
                  <RowActionMenu
                    v-if="hasSecondaryActions(item)"
                    :open-up="index >= items.length - 2"
                    :aria-label="`Actions pour ${item.name}`"
                  >
                    <button
                      v-if="!item.is_published"
                      type="button"
                      @click.stop="publishEvaluation(item)"
                    >
                      Publier
                    </button>
                    <button
                      v-if="item.is_published && isAdmin"
                      type="button"
                      @click.stop="unpublishEvaluation(item)"
                    >
                      Dépublier
                    </button>
                    <button
                      v-if="canEditEvaluation(item)"
                      type="button"
                      @click.stop="openEdit(item)"
                    >
                      Modifier
                    </button>
                    <button
                      v-if="canDeleteEvaluation(item)"
                      type="button"
                      class="danger-action"
                      @click.stop="remove(item)"
                    >
                      Supprimer
                    </button>
                  </RowActionMenu>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="lastPage > 1" class="eval-pagination">
        <span class="eval-pagination-info">
          Page {{ currentPage }} / {{ lastPage }} · {{ totalItems }} évaluation(s) au total
        </span>
        <div class="eval-pagination-nav">
          <button type="button" :disabled="currentPage <= 1" @click="goToPage(currentPage - 1)">
            ← Précédent
          </button>
          <button type="button" :disabled="currentPage >= lastPage" @click="goToPage(currentPage + 1)">
            Suivant →
          </button>
        </div>
      </div>
    </div>

    <Modal
      :open="showForm"
      :title="editing ? 'Modifier une évaluation' : (isAdmin ? 'Nouvel examen de période' : 'Nouvelle évaluation')"
      @close="showForm = false"
    >
      <div v-if="roleBanner" class="modal-role-hint" :class="roleBanner.tone">
        {{ roleBanner.text }}
      </div>

      <form id="eval-form" @submit.prevent="submit">
        <div class="field">
          <label for="e-name">Intitulé</label>
          <input
            id="e-name"
            v-model="form.name"
            type="text"
            required
            maxlength="128"
            :placeholder="isAdmin ? 'Ex. Examen de période — Maths' : 'Ex. Interrogation ch. 3'"
          />
          <small v-if="formErrors.name" class="err">{{ formErrors.name[0] }}</small>
        </div>

        <div class="form-grid two">
          <div class="field">
            <label for="e-class">Classe</label>
            <select id="e-class" v-model.number="form.classroom_id" required>
              <option :value="0" disabled>Choisir une classe</option>
              <option v-for="c in allClassrooms" :key="c.id" :value="c.id">{{ classroomLabel(c) }}</option>
            </select>
            <small v-if="formErrors.classroom_id" class="err">{{ formErrors.classroom_id[0] }}</small>
          </div>
          <div class="field">
            <label for="e-subj">Cours</label>
            <select id="e-subj" v-model.number="form.subject_id" required>
              <option :value="0" disabled>Choisir un cours</option>
              <option v-for="s in subjectsForSelectedClassroom" :key="s.id" :value="s.id">{{ s.name }}</option>
            </select>
            <small v-if="formErrors.subject_id" class="err">{{ formErrors.subject_id[0] }}</small>
          </div>
        </div>

        <div class="form-grid two">
          <div class="field">
            <label for="e-term">Trimestre / Semestre</label>
            <select id="e-term" v-model.number="form.term_id" required :disabled="!editing">
              <option :value="0" disabled>Choisir un terme</option>
              <option v-for="s in termsForForm" :key="s.id" :value="s.id">{{ s.name }}</option>
            </select>
            <small v-if="!editing" class="field-hint">Période en cours — non modifiable.</small>
            <small v-if="formErrors.term_id" class="err">{{ formErrors.term_id[0] }}</small>
          </div>
          <div class="field">
            <label for="e-period">Période</label>
            <select id="e-period" v-model.number="form.period_id" required :disabled="!editing || periodsForForm.length === 0">
              <option :value="0" disabled>Choisir une période</option>
              <option v-for="period in periodsForForm" :key="period.id" :value="period.id">{{ period.name }}</option>
            </select>
            <small v-if="!editing" class="field-hint">Période en cours — non modifiable.</small>
            <small v-if="formErrors.period_id" class="err">{{ formErrors.period_id[0] }}</small>
          </div>
        </div>

        <div class="form-grid two">
          <div class="field">
            <label for="e-type">Type</label>
            <template v-if="isAdmin">
              <div id="e-type" class="type-fixed badge badge-exam">Examen de période · 60 %</div>
            </template>
            <select v-else id="e-type" v-model="form.type" required>
              <option v-for="ty in allowedFormTypes" :key="ty.value" :value="ty.value">{{ ty.label }}</option>
            </select>
            <small v-if="formErrors.type" class="err">{{ formErrors.type[0] }}</small>
          </div>
          <div class="field">
            <label for="e-date">Date</label>
            <input id="e-date" v-model="form.held_on" type="date" required />
          </div>
        </div>

        <div class="field">
          <label for="e-max">Note sur</label>
          <input id="e-max" v-model.number="form.max_value" type="number" min="1" max="100" step="0.01" required />
          <small v-if="formErrors.max_value" class="err">{{ formErrors.max_value[0] }}</small>
        </div>

        <p v-if="formError" class="alert alert-error">{{ formError }}</p>
      </form>

      <template #footer>
        <button type="button" @click="showForm = false">Annuler</button>
        <button type="submit" form="eval-form" class="btn-primary" :disabled="submitting">
          {{ submitting ? 'Enregistrement…' : editing ? 'Enregistrer' : 'Créer' }}
        </button>
      </template>
    </Modal>
  </section>
</template>

<style scoped>
.evaluations-page {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.page-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
  flex-wrap: wrap;
}

.page-title {
  margin: 0;
  font-size: 1.55rem;
  font-weight: 800;
}

.page-subtitle {
  margin: 0.25rem 0 0;
  color: var(--text-soft);
  font-size: 0.92rem;
}

.create-btn {
  min-height: 2.4rem;
  white-space: nowrap;
}

.role-banner {
  display: flex;
  gap: 0.85rem;
  align-items: flex-start;
  padding: 0.85rem 1rem;
  border-radius: var(--radius);
  border: 1px solid var(--border);
}

.role-banner.exam {
  background: var(--primary-soft);
  border-color: var(--primary-tint);
}

.role-banner.continuous {
  background: var(--success-soft);
  border-color: rgba(74, 222, 128, 0.3);
}

.role-banner-icon {
  width: 2rem;
  height: 2rem;
  display: grid;
  place-items: center;
  border-radius: 8px;
  background: rgba(255, 255, 255, 0.1);
  flex-shrink: 0;
}

.role-banner-icon :deep(svg) {
  width: 1.15rem;
  height: 1.15rem;
}

.role-banner strong {
  display: block;
  font-size: 0.88rem;
  margin-bottom: 0.15rem;
}

.role-banner p {
  margin: 0;
  font-size: 0.84rem;
  color: var(--text-soft);
  line-height: 1.45;
}

.summary-bar {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 0.65rem;
}

.summary-card {
  padding: 0.75rem 0.9rem;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: var(--bg-card);
}

.summary-card.exam { border-color: var(--primary-tint); background: var(--primary-soft); }
.summary-card.continuous { border-color: rgba(74, 222, 128, 0.3); background: var(--success-soft); }
.summary-card.draft { border-color: rgba(251, 191, 36, 0.3); background: var(--warn-soft); }

.summary-label {
  display: block;
  font-size: 0.72rem;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: var(--text-muted);
  margin-bottom: 0.2rem;
}

.summary-card strong {
  font-size: 1.35rem;
  font-weight: 800;
}

/* La carte globale a overflow:hidden — le menu ⋮ doit pouvoir dépasser. */
.evaluations-page .main-card.card {
  overflow: visible;
}

.filters-panel {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 0.65rem;
  padding: 0.85rem 1rem;
  border-bottom: 1px solid var(--border);
  background: var(--bg-subtle);
}

.filter-field label {
  display: block;
  font-size: 0.72rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--text-muted);
  margin-bottom: 0.25rem;
}

.filter-field select {
  width: 100%;
  min-height: 2.25rem;
  font-size: 0.88rem;
}

.component-tabs {
  display: flex;
  gap: 0.35rem;
  padding: 0.65rem 1rem;
  border-bottom: 1px solid var(--border);
  flex-wrap: wrap;
}

.component-tabs button {
  border: 1px solid var(--border);
  background: var(--bg-card);
  border-radius: 999px;
  padding: 0.35rem 0.75rem;
  font-size: 0.82rem;
  font-weight: 650;
  cursor: pointer;
  color: var(--text-soft);
}

.component-tabs button.active {
  background: var(--primary);
  border-color: var(--primary);
  color: #fff;
}

.list-alert {
  margin: 0.75rem 1rem 0;
}

.eval-pagination {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  flex-wrap: wrap;
  padding: 0.75rem 1rem;
  border-top: 1px solid var(--border);
}

.eval-pagination-info {
  color: var(--text-soft);
  font-size: 0.84rem;
}

.eval-pagination-nav {
  display: flex;
  gap: 0.5rem;
}

.eval-pagination-nav button {
  min-height: 2.1rem;
  padding: 0.4rem 0.85rem;
  font-size: 0.85rem;
}

.table-wrap {
  overflow-x: auto;
  overflow-y: visible;
  padding-top: 0.35rem;
  padding-bottom: 0.5rem;
}

.eval-table {
  width: 100%;
  min-width: 56rem;
}

.eval-table th {
  font-size: 0.72rem;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--text-muted);
}

.row-exam {
  /* Teinte discrète adaptée au thème (au lieu d'un blanc translucide codé en
     dur, invisible en clair et délavé en sombre — cf. .badge-exam voisin). */
  background: var(--primary-soft);
}

.eval-name-cell {
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
  align-items: flex-start;
}

.eval-name-cell strong {
  font-size: 0.92rem;
}

.eval-meta {
  font-size: 0.78rem;
  color: var(--text-muted);
}

.stack-cell {
  display: flex;
  flex-direction: column;
  gap: 0.1rem;
}

.stack-cell .muted {
  font-size: 0.78rem;
  color: var(--text-muted);
}

.component-hint {
  display: block;
  font-size: 0.72rem;
  color: var(--text-muted);
  margin-top: 0.15rem;
}

.num {
  text-align: right;
  white-space: nowrap;
}

.actions-col {
  width: 1%;
  white-space: nowrap;
  vertical-align: middle;
}

.eval-actions {
  position: relative;
  display: inline-flex;
  align-items: center;
  justify-content: flex-end;
  gap: 0.35rem;
  overflow: visible;
}

.eval-table tbody tr:has(.row-action-menu.is-open) {
  position: relative;
  z-index: 15;
}

.eval-action-primary {
  min-height: 2rem;
  padding: 0.35rem 0.7rem;
  border: 1px solid var(--primary);
  border-radius: 8px;
  background: var(--primary);
  color: #fff;
  font-size: 0.8rem;
  font-weight: 800;
  white-space: nowrap;
  box-shadow: 0 6px 14px rgb(37 99 235 / 18%);
}

.eval-action-primary:hover {
  filter: brightness(1.05);
}

.badge-published {
  display: inline-flex;
  align-items: center;
  gap: 0.2rem;
  background: var(--success-soft);
  color: var(--success);
  border-radius: 999px;
  padding: 2px 8px;
  font-size: 0.72rem;
  font-weight: 700;
}

.badge-published :deep(svg) {
  width: 0.85rem;
  height: 0.85rem;
}

.badge-draft {
  background: var(--warn-soft);
  color: var(--warn);
  border-radius: 999px;
  padding: 2px 8px;
  font-size: 0.72rem;
  font-weight: 700;
}

.badge-exam {
  background: var(--primary-soft);
  color: var(--accent);
  border-radius: 999px;
  padding: 2px 8px;
  font-size: 0.75rem;
  font-weight: 700;
}

.badge-continuous {
  background: var(--success-soft);
  color: var(--success);
  border-radius: 999px;
  padding: 2px 8px;
  font-size: 0.75rem;
  font-weight: 700;
}

.type-fixed {
  display: inline-flex;
  min-height: 2.25rem;
  align-items: center;
}

.empty-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.65rem;
  padding: 2.5rem 1rem;
  text-align: center;
  color: var(--text-soft);
}

.empty-icon {
  width: 2rem;
  height: 2rem;
  opacity: 0.45;
}

.modal-role-hint {
  margin-bottom: 0.85rem;
  padding: 0.65rem 0.75rem;
  border-radius: var(--radius);
  font-size: 0.82rem;
  line-height: 1.45;
}

.modal-role-hint.exam {
  background: var(--primary-soft);
  color: var(--accent);
}

.modal-role-hint.continuous {
  background: var(--success-soft);
  color: var(--success);
}

.form-grid.two {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.65rem;
}

.err {
  display: block;
  color: var(--danger);
  font-size: 0.78rem;
  margin-top: 0.25rem;
}

.field-hint {
  display: block;
  color: var(--text-muted);
  font-size: 0.78rem;
  margin-top: 0.25rem;
}

@media (max-width: 960px) {
  .summary-bar,
  .filters-panel {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: 640px) {
  .summary-bar,
  .filters-panel,
  .form-grid.two {
    grid-template-columns: 1fr;
  }

  .eval-actions {
    flex-direction: column;
    align-items: stretch;
  }

  .eval-action-primary {
    width: 100%;
    justify-content: center;
  }
}
</style>
