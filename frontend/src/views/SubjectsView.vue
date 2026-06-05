<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { api, ApiError } from '../api/client'
import type { ApiResource, ClassRoom, Paginated, SchoolClass, SchoolYear, Subject, Teacher, Term } from '../types'
import Modal from '../components/Modal.vue'
import RowActionMenu from '../components/RowActionMenu.vue'
import DataTable, { type Column } from '../components/DataTable.vue'
import { useConfirmStore } from '../stores/confirm'
import { useSchoolYearStore } from '../stores/schoolYear'
import { useAuthStore } from '../stores/auth'
import { useCycleTabs, type CycleFilter } from '../composables/useCycleTabs'

const schoolYearStore = useSchoolYearStore()
const confirmDialog = useConfirmStore()
const auth = useAuthStore()
const { cycleTabs } = useCycleTabs()

type CourseStatus = 'actif' | 'inactif'
type EvaluationType = 'sur_10' | 'sur_20' | 'pourcentage'

const EVALUATION_TYPES: Array<{ value: EvaluationType; label: string }> = [
  { value: 'sur_20', label: 'Note sur 20' },
  { value: 'sur_10', label: 'Note sur 10' },
  { value: 'pourcentage', label: 'Pourcentage' },
]

const STATUS_OPTIONS: Array<{ value: CourseStatus; label: string }> = [
  { value: 'actif', label: 'Actif' },
  { value: 'inactif', label: 'Inactif' },
]

const items = ref<Subject[]>([])
const classrooms = ref<ClassRoom[]>([])
const schoolClasses = ref<SchoolClass[]>([])
const ensuringDivisions = ref(false)
const terms = ref<Term[]>([])
const teachers = ref<Teacher[]>([])
const loading = ref(false)
const error = ref('')
const activeCycle = ref<CycleFilter>('all')
const filterClassroom = ref<number | ''>('')
const selectedSubjectIds = ref<Array<number | string>>([])
const bulkDeleting = ref(false)
const generatingCurriculum = ref(false)
const curriculumSuccess = ref('')

const columns: Column<Subject>[] = [
  { key: 'name', label: 'Nom' },
  { key: 'code', label: 'Code' },
  { key: 'classroom', label: 'Classe' },
  { key: 'teacher', label: 'Enseignant' },
  { key: 'coefficient', label: 'Coefficient' },
  { key: 'evaluation', label: 'Évaluation' },
  { key: 'status', label: 'Statut' },
  { key: 'description', label: 'Description' },
  { key: 'actions', label: 'Actions', width: '1%', align: 'right' }
]

const showForm = ref(false)
const editing = ref<Subject | null>(null)
const formError = ref('')
const formErrors = reactive<Record<string, string[]>>({})
const submitting = ref(false)

const showAssignTeacher = ref(false)
const assignCourse = ref<Subject | null>(null)
const assignTeacherId = ref(0)
const eligibleTeachers = ref<Teacher[]>([])
const assignLoading = ref(false)
const assignSubmitting = ref(false)
const assignError = ref('')
const assignErrors = reactive<Record<string, string[]>>({})

const form = reactive({
  name: '',
  code: '',
  description: '',
  default_coefficient: 1,
  classroom_id: null as number | null,
  school_year_id: null as number | null,
  term_id: null as number | null,
  weekly_hours: null as number | null,
  evaluation_type: 'sur_20' as EvaluationType,
  status: 'actif' as CourseStatus,
})

const visibleClassrooms = computed<ClassRoom[]>(() => {
  if (activeCycle.value === 'all') return classrooms.value

  return classrooms.value.filter((classroom) => classroom.level?.cycle === activeCycle.value)
})

watch(cycleTabs, (tabs) => {
  if (!tabs.some((tab) => tab.value === activeCycle.value)) {
    activeCycle.value = 'all'
  }
})

const selectedClassroom = computed(() =>
  classrooms.value.find((classroom) => classroom.id === form.classroom_id) ?? null,
)

const schoolYears = computed<SchoolYear[]>(() =>
  schoolYearStore.years.length > 0
    ? schoolYearStore.years
    : schoolYearStore.current ? [schoolYearStore.current] : [],
)

const selectedSchoolYear = computed(() =>
  schoolYearStore.selected
    ?? schoolYears.value.find((year) => year.id === schoolYearStore.effectiveId)
    ?? schoolYearStore.current
    ?? schoolYears.value[0]
    ?? null,
)

const canGenerateCurriculum = computed(
  () => auth.hasRole('admin') && !!selectedSchoolYear.value && !schoolYearStore.isViewingArchived,
)

const hasBaseClasses = computed(() => schoolClasses.value.length > 0)

const hasDivisions = computed(() => classrooms.value.length > 0)

const needsDefaultDivisions = computed(() => hasBaseClasses.value && !hasDivisions.value)

const curriculumScopeNote = computed(() => {
  const label = auth.user?.admin_scope_label
  if (!label || (auth.user?.admin_scope ?? 'global') === 'global') {
    return 'Le programme officiel RDC sera appliqué à toutes les divisions de l’année sélectionnée.'
  }
  return `Le programme officiel RDC sera appliqué aux divisions de votre périmètre (${label}).`
})

const termsForSelectedYear = computed(() =>
  terms.value.filter((term) => term.school_year_id === form.school_year_id),
)

const selectedLevelLabel = computed(() => selectedClassroom.value?.level?.name ?? 'Sélectionner une classe')

const selectedOptionLabel = computed(() => {
  const classroom = selectedClassroom.value
  if (!classroom) return 'Sélectionner une classe'
  return classroom.level?.cycle === 'secondaire'
    ? classroom.option || classroom.school_option?.name || 'Option non définie'
    : 'Non applicable'
})

function classroomLabel(classroom: ClassRoom): string {
  return classroom.full_name ?? `${classroom.level?.name ?? ''} ${classroom.section}`.trim()
}

function evaluationTypeLabel(value?: string | null): string {
  return EVALUATION_TYPES.find((type) => type.value === value)?.label ?? '—'
}

function statusLabel(value?: string | null): string {
  return STATUS_OPTIONS.find((status) => status.value === value)?.label ?? '—'
}

function resetForm(): void {
  form.name = ''
  form.code = ''
  form.description = ''
  form.default_coefficient = 1
  form.classroom_id = visibleClassrooms.value[0]?.id ?? null
  form.school_year_id = selectedSchoolYear.value?.id ?? null
  form.term_id = termsForSelectedYear.value[0]?.id ?? null
  form.weekly_hours = null
  form.evaluation_type = 'sur_20'
  form.status = 'actif'
  formError.value = ''
  Object.keys(formErrors).forEach((k) => delete formErrors[k])
}

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    const query: Record<string, string | number> = {}
    if (activeCycle.value !== 'all') query.cycle = activeCycle.value
    if (filterClassroom.value !== '') query.classroom_id = filterClassroom.value

    const res = await api<Paginated<Subject>>('/api/v1/subjects', { query })
    items.value = res.data
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Erreur de chargement.'
  } finally {
    loading.value = false
  }
}

async function loadRefs(): Promise<void> {
  try {
    const yearId = selectedSchoolYear.value?.id
    const [classroomsRes, termsRes, teachersRes, schoolClassesRes] = await Promise.all([
      api<Paginated<ClassRoom>>('/api/v1/classrooms'),
      api<Paginated<Term>>('/api/v1/terms'),
      api<Paginated<Teacher>>('/api/v1/teachers'),
      yearId
        ? api<Paginated<SchoolClass>>(`/api/v1/school-years/${yearId}/school-classes`)
        : Promise.resolve({ data: [] as SchoolClass[] }),
    ])
    await schoolYearStore.fetchAll()
    classrooms.value = classroomsRes.data
    terms.value = termsRes.data
    teachers.value = teachersRes.data
    schoolClasses.value = schoolClassesRes.data
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Chargement des options impossible.'
  }
}

async function ensureDefaultDivisions(): Promise<void> {
  const yearId = selectedSchoolYear.value?.id
  if (!yearId || !canGenerateCurriculum.value || !needsDefaultDivisions.value) return

  ensuringDivisions.value = true
  error.value = ''
  try {
    await api(`/api/v1/school-years/${yearId}/generate-classes`, { method: 'POST' })
    await loadRefs()
    curriculumSuccess.value = 'Divisions par défaut créées (une section A par classe). Vous pouvez maintenant générer le programme scolaire.'
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Création des divisions impossible.'
  } finally {
    ensuringDivisions.value = false
  }
}

function setActiveCycle(cycle: CycleFilter): void {
  activeCycle.value = cycle
  if (
    filterClassroom.value !== ''
    && !visibleClassrooms.value.some((classroom) => classroom.id === filterClassroom.value)
  ) {
    filterClassroom.value = ''
  }
}

function openCreate(): void {
  editing.value = null
  resetForm()
  showForm.value = true
}

function openEdit(item: Subject): void {
  editing.value = item
  form.name = item.name
  form.code = item.code ?? ''
  form.description = item.description ?? ''
  form.default_coefficient = item.default_coefficient ?? item.coefficient ?? 1
  form.classroom_id = item.classroom_id ?? item.classroom?.id ?? null
  form.school_year_id = item.school_year_id ?? item.school_year?.id ?? selectedSchoolYear.value?.id ?? null
  form.term_id = item.term_id ?? item.term?.id ?? null
  form.weekly_hours = item.weekly_hours ?? null
  form.evaluation_type = item.evaluation_type ?? 'sur_20'
  form.status = item.status ?? 'actif'
  formError.value = ''
  Object.keys(formErrors).forEach((k) => delete formErrors[k])
  showForm.value = true
}

const assignCourseIsPrimary = computed(() => {
  const cycle = assignCourse.value?.classroom?.level?.cycle
  return cycle === 'primaire' || cycle === 'maternel'
})

function isPrimaryOrMaternelCourse(item: Subject): boolean {
  const cycle = item.classroom?.level?.cycle
  return cycle === 'primaire' || cycle === 'maternel'
}

function teacherLabel(teacher: Teacher): string {
  const name = teacher.user?.name ?? `Enseignant #${teacher.id}`
  if (teacher.teacher_type === 'primaire' || !teacher.speciality) {
    return name
  }
  return `${name} — ${teacher.speciality}`
}

async function openAssignTeacher(item: Subject): Promise<void> {
  assignCourse.value = item
  assignTeacherId.value = item.teacher_id ?? item.teacher?.id ?? 0
  assignError.value = ''
  Object.keys(assignErrors).forEach((k) => delete assignErrors[k])
  showAssignTeacher.value = true
  assignLoading.value = true
  try {
    const classroomId = item.classroom_id ?? item.classroom?.id
    const query: Record<string, string> = { for_subject_id: String(item.id) }
    if (classroomId) {
      query.for_classroom_id = String(classroomId)
    }

    const res = await api<Paginated<Teacher>>('/api/v1/teachers', { query })
    eligibleTeachers.value = res.data
  } catch (e) {
    eligibleTeachers.value = []
    assignError.value = e instanceof ApiError ? e.message : 'Impossible de charger les enseignants.'
  } finally {
    assignLoading.value = false
  }
}

async function submitAssignTeacher(): Promise<void> {
  if (!assignCourse.value || assignTeacherId.value <= 0) return

  const classroomId = assignCourse.value.classroom_id ?? assignCourse.value.classroom?.id
  const schoolYearId = assignCourse.value.school_year_id ?? schoolYearStore.effectiveId

  if (!classroomId || !schoolYearId) {
    assignError.value = 'Ce cours n\'est pas rattaché à une classe ou à l\'année courante.'
    return
  }

  assignSubmitting.value = true
  assignError.value = ''
  Object.keys(assignErrors).forEach((k) => delete assignErrors[k])

  try {
    await api<ApiResource<Subject>>(`/api/v1/subjects/${assignCourse.value.id}/assign-teacher`, {
      method: 'POST',
      body: {
        teacher_id: assignTeacherId.value,
        classroom_id: classroomId,
        school_year_id: schoolYearId,
        term_id: assignCourse.value.term_id,
        weekly_hours: assignCourse.value.weekly_hours,
      },
    })
    showAssignTeacher.value = false
    await load()
  } catch (e) {
    if (e instanceof ApiError) {
      assignError.value = e.message
      if (e.errors) Object.assign(assignErrors, e.errors)
    } else {
      assignError.value = 'Erreur réseau.'
    }
  } finally {
    assignSubmitting.value = false
  }
}

async function unassignTeacherFromCourse(): Promise<void> {
  if (!assignCourse.value) return

  const classroomId = assignCourse.value.classroom_id ?? assignCourse.value.classroom?.id
  const schoolYearId = assignCourse.value.school_year_id ?? schoolYearStore.effectiveId

  if (!classroomId || !schoolYearId) return

  const ok = await confirmDialog.ask({
    title: 'Retirer l\'enseignant',
    message: `Retirer l'enseignant de « ${assignCourse.value.name} » ?`,
    confirmLabel: 'Retirer',
    variant: 'danger',
  })
  if (!ok) return

  assignSubmitting.value = true
  assignError.value = ''
  try {
    await api(`/api/v1/subjects/${assignCourse.value.id}/assign-teacher`, {
      method: 'DELETE',
      query: {
        classroom_id: String(classroomId),
        school_year_id: String(schoolYearId),
      },
    })
    showAssignTeacher.value = false
    await load()
  } catch (e) {
    assignError.value = e instanceof ApiError ? e.message : 'Retrait impossible.'
  } finally {
    assignSubmitting.value = false
  }
}

async function submit(): Promise<void> {
  submitting.value = true
  formError.value = ''
  Object.keys(formErrors).forEach((k) => delete formErrors[k])
  const payload = {
    name: form.name,
    code: form.code || null,
    description: form.description || null,
    default_coefficient: form.default_coefficient,
    classroom_id: form.classroom_id,
    school_year_id: form.school_year_id,
    term_id: form.term_id,
    weekly_hours: form.weekly_hours,
    evaluation_type: form.evaluation_type,
    status: form.status,
  }
  try {
    if (editing.value) {
      await api<ApiResource<Subject>>(`/api/v1/subjects/${editing.value.id}`, {
        method: 'PUT',
        body: payload,
      })
    } else {
      await api<ApiResource<Subject>>('/api/v1/subjects', { method: 'POST', body: payload })
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

async function generateCurriculum(): Promise<void> {
  const yearId = selectedSchoolYear.value?.id
  if (!yearId || !canGenerateCurriculum.value) return

  const ok = await confirmDialog.ask({
    title: 'Générer le programme scolaire',
    message: 'Appliquer le programme RDC aux divisions existantes ?',
    note: `${curriculumScopeNote.value} Les matières déjà rattachées manuellement seront conservées ; seuls les coefficients manquants ou différents seront complétés.`,
    confirmLabel: 'Générer',
  })
  if (!ok) return

  generatingCurriculum.value = true
  curriculumSuccess.value = ''
  error.value = ''
  try {
    const res = await api<{ data: { classrooms_processed: number; links_created: number; links_updated: number }; message: string }>(
      `/api/v1/school-years/${yearId}/generate-curriculum`,
      { method: 'POST' },
    )
    curriculumSuccess.value = res.message
    await load()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Génération du programme impossible.'
  } finally {
    generatingCurriculum.value = false
  }
}

async function remove(item: Subject): Promise<void> {
  const ok = await confirmDialog.ask({
    title: 'Supprimer un cours',
    message: 'Ce cours sera supprimé.',
    details: [item.name],
    note: 'Les associations avec les classes et enseignants peuvent être impactées.',
    confirmLabel: 'Supprimer',
    variant: 'danger',
  })
  if (!ok) return
  try {
    await api(`/api/v1/subjects/${item.id}`, { method: 'DELETE' })
    await load()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Suppression impossible.'
  }
}

function subjectIdFromSelectionKey(key: number | string): number | null {
  const rowKey = String(key)
  const item = items.value.find((subject) => (subject.row_key ?? String(subject.id)) === rowKey)
  return item?.id ?? null
}

async function removeSelected(): Promise<void> {
  if (selectedSubjectIds.value.length === 0) return
  const subjectIds = [...new Set(
    selectedSubjectIds.value
      .map((key) => subjectIdFromSelectionKey(key))
      .filter((id): id is number => id !== null),
  )]
  if (subjectIds.length === 0) return
  const ok = await confirmDialog.ask({
    title: 'Supprimer les cours sélectionnés',
    message: `Voulez-vous vraiment supprimer ${subjectIds.length} cours ?`,
    confirmLabel: 'Supprimer',
    variant: 'danger',
  })
  if (!ok) return
  
  bulkDeleting.value = true
  try {
    await Promise.all(subjectIds.map(id => api(`/api/v1/subjects/${id}`, { method: 'DELETE' })))
    selectedSubjectIds.value = []
    await load()
  } catch (e) {
    error.value = 'Erreur lors de la suppression groupée.'
  } finally {
    bulkDeleting.value = false
  }
}

watch([activeCycle, filterClassroom], load)
watch(classrooms, () => {
  if (
    filterClassroom.value !== ''
    && !visibleClassrooms.value.some((classroom) => classroom.id === filterClassroom.value)
  ) {
    filterClassroom.value = ''
  }
})
watch(
  () => schoolYearStore.effectiveId,
  async () => {
    await loadRefs()
    if (!editing.value) {
      form.school_year_id = selectedSchoolYear.value?.id ?? null
    }
    await load()
  },
)
watch(
  () => form.school_year_id,
  () => {
    if (form.term_id && !termsForSelectedYear.value.some((term) => term.id === form.term_id)) {
      form.term_id = termsForSelectedYear.value[0]?.id ?? null
    }
  },
)

onMounted(async () => {
  await loadRefs()
  await load()
})
</script>

<template>
  <section>
    <div class="card">
      <div class="card-header">
        <h1 style="margin: 0">Cours</h1>
        <div class="header-actions">
          <button
            v-if="canGenerateCurriculum"
            type="button"
            class="btn-secondary"
            :disabled="generatingCurriculum || !hasDivisions"
            @click="generateCurriculum"
          >
            {{ generatingCurriculum ? 'Génération…' : 'Générer le programme scolaire' }}
          </button>
          <button type="button" class="btn-primary" @click="openCreate">+ Nouveau cours</button>
        </div>
      </div>

      <p v-if="canGenerateCurriculum && !hasBaseClasses" class="alert alert-info curriculum-banner">
        Aucune classe de base pour cette année. Générez d’abord la structure depuis l’onglet
        <router-link to="/classes">Classes</router-link>.
      </p>
      <div v-else-if="canGenerateCurriculum && needsDefaultDivisions" class="alert alert-info curriculum-banner curriculum-banner--action">
        <p>
          Les classes de base sont prêtes pour {{ selectedSchoolYear?.name ?? 'cette année' }}, mais aucune
          division (A, B…) n’est disponible dans votre périmètre. Ajoutez des divisions depuis l’onglet
          <router-link to="/classes">Classes</router-link>, ou créez une section A par classe.
        </p>
        <button
          type="button"
          class="btn-secondary"
          :disabled="ensuringDivisions"
          @click="ensureDefaultDivisions"
        >
          {{ ensuringDivisions ? 'Création…' : 'Créer les divisions par défaut' }}
        </button>
      </div>
      <p v-if="curriculumSuccess" class="alert alert-success curriculum-banner">{{ curriculumSuccess }}</p>

      <div class="subject-toolbar">
        <div class="cycle-tabs" role="tablist" aria-label="Filtrer les cours par cycle">
          <button
            v-for="tab in cycleTabs"
            :key="tab.value"
            type="button"
            class="cycle-tab"
            :class="{ active: activeCycle === tab.value }"
            role="tab"
            :aria-selected="activeCycle === tab.value"
            @click="setActiveCycle(tab.value)"
          >
            {{ tab.label }}
          </button>
        </div>

        <label class="classroom-filter">
          <span>Classe</span>
          <select v-model.number="filterClassroom" :disabled="visibleClassrooms.length === 0">
            <option value="">Toutes les classes</option>
            <option v-for="classroom in visibleClassrooms" :key="classroom.id" :value="classroom.id">
              {{ classroomLabel(classroom) }}
            </option>
          </select>
        </label>

        <div v-if="selectedSubjectIds.length > 0" class="selection-strip" role="status">
          <div class="selection-summary">
            <strong>{{ selectedSubjectIds.length }}</strong>
            <span>cours sélectionné(s)</span>
          </div>
          <div class="bulk-actions" aria-label="Actions groupées">
            <button type="button" :disabled="bulkDeleting" @click="selectedSubjectIds = []">
              Désélectionner
            </button>
            <button
              type="button"
              class="bulk-danger"
              :disabled="bulkDeleting"
              @click="removeSelected"
            >
              {{ bulkDeleting ? 'Suppression…' : 'Supprimer' }}
            </button>
          </div>
        </div>
      </div>

      <p v-if="error" class="alert alert-error" style="margin: 1rem">{{ error }}</p>
      <div v-if="loading" class="empty-state">Chargement…</div>
      <div v-else-if="items.length === 0" class="empty-state">Aucun cours enregistré.</div>

      <DataTable
        v-else
        :items="items"
        :columns="columns"
        key-field="row_key"
        selectable
        v-model:selected-ids="selectedSubjectIds"
        row-clickable
        @row-click="openEdit"
      >
        <template #col-name="{ item }">
          <strong>{{ item.name }}</strong>
        </template>
        <template #col-code="{ item }">
          <code v-if="item.code">{{ item.code }}</code><span v-else>—</span>
        </template>
        <template #col-classroom="{ item }">
          {{ item.classroom ? classroomLabel(item.classroom) : '—' }}
          <small v-if="item.term || item.school_year">
            {{ item.term?.name ?? 'Toute période' }} · {{ item.school_year?.name ?? 'Année non définie' }}
          </small>
        </template>
        <template #col-teacher="{ item }">
          {{ item.teacher?.user?.name ?? '—' }}
        </template>
        <template #col-coefficient="{ item }">
          {{ item.default_coefficient ?? item.coefficient ?? '—' }}
        </template>
        <template #col-evaluation="{ item }">
          {{ evaluationTypeLabel(item.evaluation_type) }}
        </template>
        <template #col-status="{ item }">
          <span class="badge" :class="item.status === 'inactif' ? 'badge-muted' : ''">
            {{ statusLabel(item.status) }}
          </span>
        </template>
        <template #col-description="{ item }">
          <span style="color: var(--text-soft)">{{ item.description ?? '—' }}</span>
        </template>
        <template #col-actions="{ item, index }">
          <RowActionMenu
            :open-up="index >= items.length - 2"
            :aria-label="`Actions pour ${item.name}`"
          >
            <button
              v-if="!isPrimaryOrMaternelCourse(item)"
              type="button"
              @click.stop="openAssignTeacher(item)"
            >
              Assigner un enseignant
            </button>
            <button type="button" @click.stop="openEdit(item)">Modifier</button>
            <button type="button" class="danger-action" @click.stop="remove(item)">Supprimer</button>
          </RowActionMenu>
        </template>
      </DataTable>
    </div>

    <Modal
      :open="showForm"
      :title="editing ? 'Modifier un cours' : 'Nouveau cours'"
      max-width="62rem"
      @close="showForm = false"
    >
      <form id="subject-form" class="course-form" @submit.prevent="submit">
        <section class="form-section">
          <h3>Informations de base</h3>
          <div class="form-grid">
            <div class="field">
              <label for="s-name">Nom / Intitulé</label>
              <input id="s-name" v-model="form.name" type="text" required maxlength="128" placeholder="Mathématiques" />
              <small v-if="formErrors.name" class="err">{{ formErrors.name[0] }}</small>
            </div>
            <div class="field">
              <label for="s-code">Code du cours</label>
              <input id="s-code" v-model="form.code" type="text" maxlength="32" placeholder="MATH-001" />
              <small v-if="formErrors.code" class="err">{{ formErrors.code[0] }}</small>
            </div>
            <div class="field">
              <label for="s-coef">Coefficient / Crédit</label>
              <input
                id="s-coef"
                v-model.number="form.default_coefficient"
                type="number"
                required
                min="0.01"
                max="99.99"
                step="0.01"
              />
              <small v-if="formErrors.default_coefficient" class="err">
                {{ formErrors.default_coefficient[0] }}
              </small>
            </div>
            <div class="field">
              <label for="s-status">Statut du cours</label>
              <select id="s-status" v-model="form.status" required>
                <option v-for="status in STATUS_OPTIONS" :key="status.value" :value="status.value">
                  {{ status.label }}
                </option>
              </select>
              <small v-if="formErrors.status" class="err">{{ formErrors.status[0] }}</small>
            </div>
            <div class="field wide">
              <label for="s-desc">Description</label>
              <textarea id="s-desc" v-model="form.description" rows="3" maxlength="255" />
              <small v-if="formErrors.description" class="err">{{ formErrors.description[0] }}</small>
            </div>
          </div>
        </section>

        <section class="form-section">
          <h3>Organisation</h3>
          <div class="form-grid">
            <div class="field">
              <label for="s-classroom">Classe concernée</label>
              <select id="s-classroom" v-model.number="form.classroom_id">
                <option :value="null">— Non rattaché —</option>
                <option v-for="classroom in visibleClassrooms" :key="classroom.id" :value="classroom.id">
                  {{ classroomLabel(classroom) }}
                </option>
              </select>
              <small v-if="formErrors.classroom_id" class="err">{{ formErrors.classroom_id[0] }}</small>
            </div>
            <div class="field">
              <label>Niveau scolaire</label>
              <div class="readonly-field">{{ selectedLevelLabel }}</div>
            </div>
            <div class="field">
              <label>Section / Option</label>
              <div class="readonly-field">{{ selectedOptionLabel }}</div>
            </div>
            <div class="field">
              <label for="s-year">Année académique</label>
              <select id="s-year" v-model.number="form.school_year_id">
                <option :value="null">— Non définie —</option>
                <option v-for="year in schoolYears" :key="year.id" :value="year.id">
                  {{ year.name }}
                </option>
              </select>
              <small v-if="formErrors.school_year_id" class="err">{{ formErrors.school_year_id[0] }}</small>
            </div>
            <div class="field">
              <label for="s-term">Période / Trimestre</label>
              <select id="s-term" v-model.number="form.term_id" :disabled="termsForSelectedYear.length === 0">
                <option :value="null">Toute l'année</option>
                <option v-for="term in termsForSelectedYear" :key="term.id" :value="term.id">
                  {{ term.name }}
                </option>
              </select>
              <small v-if="formErrors.term_id" class="err">{{ formErrors.term_id[0] }}</small>
            </div>
          </div>
        </section>

        <section class="form-section">
          <h3>Évaluation</h3>
          <p class="section-note">
            Au secondaire / CTEB : menu <strong>⋮</strong> → <strong>Assigner un enseignant</strong> (filtré par spécialité).
            Au primaire / maternelle : affectation par classe depuis <strong>Enseignants</strong>.
          </p>
          <div class="form-grid">
            <div class="field">
              <label for="s-hours">Charge horaire hebdomadaire</label>
              <input
                id="s-hours"
                v-model.number="form.weekly_hours"
                type="number"
                min="0.25"
                max="99.99"
                step="0.25"
                placeholder="4"
              />
              <small v-if="formErrors.weekly_hours" class="err">{{ formErrors.weekly_hours[0] }}</small>
            </div>
            <div class="field">
              <label for="s-evaluation">Type d'évaluation</label>
              <select id="s-evaluation" v-model="form.evaluation_type" required>
                <option v-for="type in EVALUATION_TYPES" :key="type.value" :value="type.value">
                  {{ type.label }}
                </option>
              </select>
              <small v-if="formErrors.evaluation_type" class="err">{{ formErrors.evaluation_type[0] }}</small>
            </div>
          </div>
        </section>

        <p v-if="formError" class="alert alert-error">{{ formError }}</p>
      </form>
      <template #footer>
        <button type="button" @click="showForm = false">Annuler</button>
        <button type="submit" form="subject-form" class="btn-primary" :disabled="submitting">
          {{ submitting ? 'Enregistrement…' : editing ? 'Enregistrer' : 'Créer' }}
        </button>
      </template>
    </Modal>

    <Modal
      :open="showAssignTeacher"
      title="Assigner un enseignant"
      size="large"
      @close="showAssignTeacher = false"
    >
      <div v-if="assignCourse" class="assign-teacher-modal">
        <p class="section-note">
          Cours : <strong>{{ assignCourse.name }}</strong>
          <span v-if="assignCourse.classroom"> — {{ classroomLabel(assignCourse.classroom) }}</span>
        </p>
        <p v-if="assignCourseIsPrimary" class="section-note">
          Cours du primaire / maternel : tous les enseignants de ce type sont proposés (pas de spécialité).
        </p>
        <p v-else class="section-note">
          Seuls les enseignants dont la spécialité correspond à « {{ assignCourse.name }} » sont proposés.
        </p>

        <div v-if="assignLoading" class="empty-state compact">Chargement des enseignants…</div>
        <div v-else-if="eligibleTeachers.length === 0" class="alert alert-error">
          <template v-if="assignCourseIsPrimary">
            Aucun enseignant du primaire / maternel disponible.
            Créez d'abord un profil « Enseignant du Primaire ».
          </template>
          <template v-else>
            Aucun enseignant avec la spécialité « {{ assignCourse.name }} ».
            Créez d'abord un profil enseignant du secondaire avec cette spécialité.
          </template>
        </div>
        <div v-else class="field">
          <label for="assign-teacher">Enseignant</label>
          <select id="assign-teacher" v-model.number="assignTeacherId">
            <option :value="0" disabled>Sélectionner un enseignant</option>
            <option v-for="teacher in eligibleTeachers" :key="teacher.id" :value="teacher.id">
              {{ teacherLabel(teacher) }}
            </option>
          </select>
          <small v-if="assignErrors.teacher_id" class="err">{{ assignErrors.teacher_id[0] }}</small>
        </div>

        <p v-if="assignError" class="alert alert-error">{{ assignError }}</p>
      </div>

      <template #footer>
        <button type="button" @click="showAssignTeacher = false">Annuler</button>
        <button
          v-if="assignCourse?.teacher_id || assignCourse?.teacher"
          type="button"
          class="danger-action"
          :disabled="assignSubmitting"
          @click="unassignTeacherFromCourse"
        >
          Retirer l'enseignant
        </button>
        <button
          type="button"
          class="btn-primary"
          :disabled="assignSubmitting || assignTeacherId <= 0 || eligibleTeachers.length === 0"
          @click="submitAssignTeacher"
        >
          {{ assignSubmitting ? 'Enregistrement…' : 'Assigner' }}
        </button>
      </template>
    </Modal>
  </section>
</template>

<style scoped>
button + button { margin-left: 0.4rem; }
.header-actions {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 0.5rem;
}
.curriculum-banner {
  margin: 0 1rem 0;
}
.curriculum-banner a {
  font-weight: 800;
}
.curriculum-banner--action {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 0.85rem;
  flex-wrap: wrap;
}
.curriculum-banner--action p {
  margin: 0;
  flex: 1 1 16rem;
}
.alert-success {
  color: #166534;
  background: #ecfdf5;
  border: 1px solid #bbf7d0;
}
.alert-info {
  color: #1e40af;
  background: #eff6ff;
  border: 1px solid #bfdbfe;
}
.err { display: block; color: var(--danger); font-size: 0.78rem; margin-top: 0.25rem; }
code { font-family: ui-monospace,Consolas,monospace; background:#f1f5f9; padding:.1rem .35rem; border-radius:4px; font-size:.78rem; }
td small {
  display: block;
  margin-top: 0.16rem;
  color: var(--text-muted);
  font-size: 0.72rem;
}
.subject-toolbar {
  display: grid;
  gap: 0.85rem;
  padding: 1rem;
  border-bottom: 1px solid var(--border);
}
.cycle-tabs {
  display: flex;
  align-items: center;
  gap: 0.45rem;
  flex-wrap: wrap;
}
.cycle-tab {
  min-height: 2.35rem;
  padding: 0.45rem 0.8rem;
  border: 1px solid var(--border);
  border-radius: 8px;
  background: #fff;
  color: var(--text-soft);
  font-size: 0.86rem;
  font-weight: 800;
}
.cycle-tab:hover {
  border-color: var(--primary);
  color: var(--primary);
}
.cycle-tab.active {
  border-color: var(--primary);
  background: var(--primary);
  color: #fff;
  box-shadow: 0 8px 18px rgb(37 99 235 / 16%);
}
.classroom-filter {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  flex-wrap: wrap;
  color: var(--text-soft);
  font-size: 0.88rem;
  font-weight: 800;
}
.classroom-filter select {
  min-width: 14rem;
}
.course-form {
  display: grid;
  gap: 1rem;
}
.form-section {
  display: grid;
  gap: 0.75rem;
  padding-bottom: 1rem;
  border-bottom: 1px solid var(--border);
}
.form-section:last-of-type {
  padding-bottom: 0;
  border-bottom: 0;
}
.form-section h3 {
  margin: 0;
  color: var(--text);
  font-size: 0.92rem;
}
.form-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.72rem;
}
.field.wide {
  grid-column: 1 / -1;
}
.readonly-field {
  min-height: 2.55rem;
  display: flex;
  align-items: center;
  padding: 0.58rem 0.7rem;
  border: 1px solid var(--border);
  border-radius: 8px;
  background: #f8fafc;
  color: var(--text-soft);
  font-size: 0.9rem;
  font-weight: 750;
}
textarea {
  resize: vertical;
}
@media (max-width: 720px) {
  .cycle-tabs {
    align-items: stretch;
  }
  .cycle-tab {
    flex: 1 1 8rem;
  }
  .classroom-filter,
  .classroom-filter select {
    width: 100%;
  }
  .form-grid {
    grid-template-columns: 1fr;
  }
  .field.wide {
    grid-column: auto;
  }
}
</style>
