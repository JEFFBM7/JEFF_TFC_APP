<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { api, ApiError } from '../api/client'
import type { ApiResource, ClassRoom, LevelCycle, Paginated, Subject, Teacher } from '../types'
import Modal from '../components/Modal.vue'
import RowActionMenu from '../components/RowActionMenu.vue'
import { useConfirmStore } from '../stores/confirm'
import { useSchoolYearStore } from '../stores/schoolYear'
import { useAuthStore } from '../stores/auth'
import { useCycleTabs, type CycleFilter } from '../composables/useCycleTabs'

const PRIMARY_CYCLES = new Set(['maternel', 'primaire'])

const auth = useAuthStore()
const schoolYearStore = useSchoolYearStore()
const confirmDialog = useConfirmStore()
const router = useRouter()
const { cycleTabs, authorizedCycleValues } = useCycleTabs()
const items = ref<Teacher[]>([])
const subjects = ref<Subject[]>([])
const referenceLoading = ref(false)
const loading = ref(false)
const error = ref('')
const activeCycle = ref<CycleFilter>('all')
const searchQuery = ref('')
const selectedTeacherIds = ref<number[]>([])
const bulkDeleting = ref(false)

const classrooms = ref<ClassRoom[]>([])
const showForm = ref(false)
const showAssignClass = ref(false)
const assigningTeacher = ref<Teacher | null>(null)
const assignClassroomId = ref<number | null>(null)
const assignClassLoading = ref(false)
const assignClassSubmitting = ref(false)
const assignClassError = ref('')
const assignClassErrors = reactive<Record<string, string[]>>({})
const editing = ref<Teacher | null>(null)
const formError = ref('')
const formErrors = reactive<Record<string, string[]>>({})
const submitting = ref(false)

const form = reactive({
  teacher_type: '' as '' | 'primaire' | 'secondaire',
  speciality: '',
  name: '',
  gender: '' as '' | 'F' | 'M',
  birth_date: '',
  registration_number: '',
  phone: '',
  address: '',
  grade: '',
  contract_type: '' as '' | 'Permanent' | 'Vacataire',
  hired_on: '',
  email: '',
  password: '',
})

const isSecondaryTeacher = computed(() => form.teacher_type === 'secondaire')

const specialityOptions = computed(() => {
  const names = new Set<string>()
  for (const subject of subjects.value) {
    const name = subject.name?.trim()
    if (name) names.add(name)
  }
  return Array.from(names).sort((a, b) => a.localeCompare(b, 'fr'))
})

const canManageTeachers = computed(() => auth.hasRole('admin'))

const canAssignPrimaryClass = computed(() => {
  if (!canManageTeachers.value) return false
  const scope = auth.user?.admin_scope ?? 'global'
  return scope === 'global' || scope === 'primary_maternal'
})

const primaryClassrooms = computed(() =>
  classrooms.value.filter((classroom) => {
    const cycle = classroom.level?.cycle
    return cycle !== undefined && PRIMARY_CYCLES.has(cycle)
      && authorizedCycleValues.value.includes(cycle)
  }),
)

const canPickTeacherType = computed(
  () => auth.user?.role === 'admin' && (auth.user.admin_scope ?? 'global') === 'global',
)

const defaultTeacherTypeForScope = computed((): 'primaire' | 'secondaire' | '' => {
  const scope = auth.user?.admin_scope ?? 'global'
  if (scope === 'primary_maternal') return 'primaire'
  if (scope === 'secondary_technical') return 'secondaire'
  return ''
})

const displayedItems = computed(() => {
  const query = searchQuery.value.trim().toLowerCase()
  if (!query) return items.value

  return items.value.filter((item) => {
    const parts = teacherRowParts(item)
    const haystack = [
      teacherDisplayName(item),
      item.registration_number ?? '',
      item.speciality ?? '',
      item.phone ?? '',
      item.user?.email ?? '',
      parts.classroom,
      parts.subject,
      teacherCycleLabel(item.cycle),
      teacherAssignmentRoleLabel(item),
    ]
      .join(' ')
      .toLowerCase()

    return haystack.includes(query)
  })
})

const visibleTeacherIds = computed(() => displayedItems.value.map((teacher) => teacher.id))

const listMetaLabel = computed(() => {
  const visible = displayedItems.value.length
  const total = items.value.length
  if (searchQuery.value.trim() && visible !== total) {
    return `${visible} sur ${total} enseignant(s)`
  }
  return `${visible} enseignant(s)`
})

watch(cycleTabs, (tabs) => {
  if (!tabs.some((tab) => tab.value === activeCycle.value)) {
    activeCycle.value = 'all'
  }
})

const selectedCount = computed(() => selectedTeacherIds.value.length)

const allVisibleSelected = computed(() =>
  visibleTeacherIds.value.length > 0
  && visibleTeacherIds.value.every((id) => selectedTeacherIds.value.includes(id)),
)

const someVisibleSelected = computed(() =>
  selectedTeacherIds.value.length > 0 && !allVisibleSelected.value,
)

function teacherDisplayName(item: Teacher): string {
  return item.user?.name ?? `Enseignant #${item.id}`
}

const CYCLE_LABELS: Record<LevelCycle, string> = {
  maternel: 'Maternelle',
  primaire: 'Primaire',
  cteb: 'CTEB',
  secondaire: 'Secondaire',
}

function teacherCycleLabel(cycle?: LevelCycle | null): string {
  if (!cycle) return '—'
  return CYCLE_LABELS[cycle] ?? '—'
}

function teacherAssignmentRoleLabel(item: Teacher): string {
  return item.assignment_role === 'principal' ? 'Principal' : 'Intervenant'
}

function isSecondaryPrincipal(item: Teacher): boolean {
  return item.assignment_role === 'principal' && item.teacher_type === 'secondaire'
}

interface TeacherRowParts {
  classroom: string
  subject: string
  courses: string
  courseWarning: boolean
}

function teacherRowParts(item: Teacher): TeacherRowParts {
  const count = item.assigned_courses_count ?? 0
  const courses = String(count)
  const courseWarning = count === 0

  if (item.teacher_type === 'primaire') {
    return {
      classroom: item.main_classroom?.full_name ?? '—',
      subject: '—',
      courses,
      courseWarning: courseWarning && !!item.main_classroom,
    }
  }

  const subject = item.speciality ?? '—'

  if (isSecondaryPrincipal(item)) {
    return {
      classroom: item.main_classroom?.full_name ?? '—',
      subject,
      courses,
      courseWarning,
    }
  }

  const classrooms = item.assigned_classrooms ?? []
  let classroom = '—'
  if (classrooms.length === 1) {
    classroom = classrooms[0].full_name ?? '—'
  } else if (classrooms.length > 1) {
    classroom = `${classrooms.length} classes`
  }

  return { classroom, subject, courses, courseWarning }
}

const rowPartsById = computed(() => {
  const map = new Map<number, TeacherRowParts>()
  for (const item of displayedItems.value) {
    map.set(item.id, teacherRowParts(item))
  }
  return map
})

function rowPartsFor(item: Teacher): TeacherRowParts {
  return rowPartsById.value.get(item.id) ?? teacherRowParts(item)
}

function classroomLabel(classroom: ClassRoom): string {
  return classroom.full_name ?? `${classroom.level?.name ?? ''} ${classroom.section}`.trim()
}

async function loadClassrooms(): Promise<void> {
  try {
    const res = await api<Paginated<ClassRoom>>('/api/v1/classrooms')
    classrooms.value = res.data
  } catch {
    classrooms.value = []
  }
}

async function openAssignClass(item: Teacher): Promise<void> {
  if (!canAssignPrimaryClass.value || item.teacher_type !== 'primaire') return

  assigningTeacher.value = item
  assignClassroomId.value = item.main_classroom?.id ?? null
  assignClassError.value = ''
  Object.keys(assignClassErrors).forEach((k) => delete assignClassErrors[k])
  showAssignClass.value = true
  assignClassLoading.value = true

  try {
    await loadClassrooms()
  } finally {
    assignClassLoading.value = false
  }
}

async function submitAssignClass(): Promise<void> {
  const teacher = assigningTeacher.value
  const classroomId = assignClassroomId.value
  const schoolYearId = schoolYearStore.effectiveId

  if (!teacher || !classroomId || !schoolYearId) {
    assignClassError.value = 'Sélectionnez une classe et une année scolaire active.'
    return
  }

  assignClassSubmitting.value = true
  assignClassError.value = ''
  Object.keys(assignClassErrors).forEach((k) => delete assignClassErrors[k])

  try {
    await api<ApiResource<Teacher>>(`/api/v1/teachers/${teacher.id}/assign-classroom`, {
      method: 'POST',
      body: {
        classroom_id: classroomId,
        school_year_id: schoolYearId,
      },
    })
    showAssignClass.value = false
    await load()
  } catch (e) {
    if (e instanceof ApiError) {
      assignClassError.value = e.message
      if (e.errors) Object.assign(assignClassErrors, e.errors)
    } else {
      assignClassError.value = 'Affectation impossible.'
    }
  } finally {
    assignClassSubmitting.value = false
  }
}

async function unassignClass(): Promise<void> {
  const teacher = assigningTeacher.value
  const schoolYearId = schoolYearStore.effectiveId
  if (!teacher || !schoolYearId) return

  const className = teacher.main_classroom?.full_name ?? 'cette classe'
  const confirmed = await confirmDialog.ask({
    title: 'Retirer la classe titulaire',
    message: `Retirer ${teacherDisplayName(teacher)} de « ${className} » ? Tous les cours de la classe seront désaffectés.`,
    confirmLabel: 'Retirer',
    variant: 'danger',
  })
  if (!confirmed) return

  assignClassSubmitting.value = true
  assignClassError.value = ''

  try {
    await api(`/api/v1/teachers/${teacher.id}/assign-classroom`, {
      method: 'DELETE',
      query: { school_year_id: schoolYearId },
    })
    showAssignClass.value = false
    await load()
  } catch (e) {
    assignClassError.value = e instanceof ApiError ? e.message : 'Retrait impossible.'
  } finally {
    assignClassSubmitting.value = false
  }
}

function resetForm(): void {
  form.teacher_type = ''
  form.speciality = ''
  form.name = ''
  form.gender = ''
  form.birth_date = ''
  form.registration_number = ''
  form.phone = ''
  form.address = ''
  form.grade = ''
  form.contract_type = ''
  form.hired_on = ''
  form.email = ''
  form.password = ''
  formError.value = ''
  Object.keys(formErrors).forEach((k) => delete formErrors[k])
}

function buildPayload(): Record<string, unknown> {
  const payload: Record<string, unknown> = {
    teacher_type: form.teacher_type,
    name: form.name.trim(),
    ...(form.teacher_type === 'secondaire' ? { speciality: form.speciality.trim() } : {}),
    gender: form.gender || null,
    birth_date: form.birth_date || null,
    phone: form.phone.trim() || null,
    address: form.address.trim() || null,
    grade: form.grade.trim() || null,
    contract_type: form.contract_type || null,
    hired_on: form.hired_on || null,
    email: form.email.trim() || null,
  }

  if (form.password.trim()) {
    payload.password = form.password
  }

  return payload
}

async function loadReferenceData(): Promise<void> {
  referenceLoading.value = true
  try {
    const subjectsRes = await api<Paginated<Subject>>('/api/v1/subjects')
    subjects.value = subjectsRes.data
  } catch {
    subjects.value = []
  } finally {
    referenceLoading.value = false
  }
}

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    const query: Record<string, string> = {}
    if (activeCycle.value !== 'all') query.cycle = activeCycle.value

    const teachersRes = await api<Paginated<Teacher>>('/api/v1/teachers', { query })
    items.value = teachersRes.data
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Erreur de chargement.'
  } finally {
    loading.value = false
  }
}

function setActiveCycle(cycle: CycleFilter): void {
  activeCycle.value = cycle
}

function toggleSelectAllVisible(event: Event): void {
  const checked = (event.target as HTMLInputElement).checked
  selectedTeacherIds.value = checked ? [...visibleTeacherIds.value] : []
}

function toggleTeacherSelection(teacherId: number, event: Event): void {
  const checked = (event.target as HTMLInputElement).checked
  selectedTeacherIds.value = checked
    ? Array.from(new Set([...selectedTeacherIds.value, teacherId]))
    : selectedTeacherIds.value.filter((id) => id !== teacherId)
}

function clearSelection(): void {
  if (bulkDeleting.value) return
  selectedTeacherIds.value = []
}

function openCreate(): void {
  if (!canManageTeachers.value) return
  editing.value = null
  resetForm()
  if (defaultTeacherTypeForScope.value) {
    form.teacher_type = defaultTeacherTypeForScope.value
  }
  void loadReferenceData()
  showForm.value = true
}

function setTeacherType(type: 'primaire' | 'secondaire'): void {
  if (editing.value) return
  form.teacher_type = type
  if (type === 'primaire') {
    form.speciality = ''
  }
}

async function openEdit(item: Teacher): Promise<void> {
  editing.value = item
  form.teacher_type = item.teacher_type ?? ''
  form.speciality = item.teacher_type === 'secondaire' ? (item.speciality ?? '') : ''
  form.name = item.user?.name ?? ''
  form.gender = item.gender ?? ''
  form.birth_date = item.birth_date ?? ''
  form.registration_number = item.registration_number ?? ''
  form.phone = item.phone ?? ''
  form.address = item.address ?? ''
  form.grade = item.grade ?? ''
  form.contract_type = item.contract_type ?? ''
  form.hired_on = item.hired_on ?? ''
  form.email = item.user?.email ?? ''
  form.password = ''
  formError.value = ''
  Object.keys(formErrors).forEach((k) => delete formErrors[k])

  await loadReferenceData()
  showForm.value = true
}

function openTeacherDetail(item: Teacher): void {
  void router.push({ name: 'teacher-detail', params: { id: item.id } })
}

async function submit(): Promise<void> {
  submitting.value = true
  formError.value = ''
  Object.keys(formErrors).forEach((k) => delete formErrors[k])
  try {
    const body = buildPayload()
    if (editing.value) {
      await api<ApiResource<Teacher>>(`/api/v1/teachers/${editing.value.id}`, {
        method: 'PUT',
        body,
      })
    } else {
      await api<ApiResource<Teacher>>('/api/v1/teachers', { method: 'POST', body })
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

async function remove(item: Teacher): Promise<void> {
  const ok = await confirmDialog.ask({
    title: 'Supprimer un enseignant',
    message: 'Ce profil enseignant sera supprimé.',
    details: [teacherDisplayName(item)],
    note: 'Les affectations liées peuvent ne plus être disponibles dans les vues associées.',
    confirmLabel: 'Supprimer',
    variant: 'danger',
  })
  if (!ok) return
  try {
    await api(`/api/v1/teachers/${item.id}`, { method: 'DELETE' })
    await load()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Suppression impossible.'
  }
}

async function removeSelected(): Promise<void> {
  const ids = [...selectedTeacherIds.value]
  if (ids.length === 0 || bulkDeleting.value) return

  const names = ids.map((id) => teacherDisplayName(items.value.find((item) => item.id === id) ?? { id } as Teacher))
  const details = names.length > 5
    ? [...names.slice(0, 5), `${names.length - 5} autre(s)`]
    : names

  const ok = await confirmDialog.ask({
    title: 'Supprimer les enseignants sélectionnés',
    message: `${ids.length} profil(s) enseignant seront supprimés.`,
    details,
    note: 'Les affectations liées peuvent ne plus être disponibles dans les vues associées.',
    confirmLabel: 'Supprimer',
    variant: 'danger',
  })
  if (!ok) return

  bulkDeleting.value = true
  error.value = ''
  const failedIds: number[] = []

  try {
    for (const id of ids) {
      try {
        await api(`/api/v1/teachers/${id}`, { method: 'DELETE' })
      } catch {
        failedIds.push(id)
      }
    }

    selectedTeacherIds.value = failedIds
    await load()

    if (failedIds.length > 0) {
      error.value = `${failedIds.length} suppression(s) impossible(s) sur ${ids.length}.`
    }
  } finally {
    bulkDeleting.value = false
  }
}

watch(activeCycle, load)
watch(
  () => schoolYearStore.effectiveId,
  () => {
    void load()
  },
)
watch(items, () => {
  const visibleIds = new Set(displayedItems.value.map((teacher) => teacher.id))
  selectedTeacherIds.value = selectedTeacherIds.value.filter((id) => visibleIds.has(id))
})

onMounted(load)
</script>

<template>
  <section class="teachers-page">
    <div class="card teachers-card">
      <header class="teachers-header">
        <div class="teachers-heading">
          <h1>Enseignants</h1>
          <p class="teachers-meta">{{ listMetaLabel }}</p>
        </div>
        <button v-if="canManageTeachers" type="button" class="btn-primary" @click="openCreate">
          + Ajouter
        </button>
      </header>

      <div class="teacher-toolbar">
        <div class="cycle-tabs" role="tablist" aria-label="Filtrer les enseignants par cycle">
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

        <label class="teacher-search">
          <span class="sr-only">Rechercher un enseignant</span>
          <input
            v-model="searchQuery"
            type="search"
            placeholder="Nom, matricule, matière, classe…"
            :disabled="loading"
          />
        </label>

        <div v-if="selectedCount > 0" class="selection-strip" role="status">
          <div class="selection-summary">
            <strong>{{ selectedCount }}</strong>
            <span>sélectionné(s)</span>
          </div>
          <div class="bulk-actions" aria-label="Actions groupées">
            <button type="button" :disabled="bulkDeleting" @click="clearSelection">
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

      <p v-if="error" class="alert alert-error teachers-alert">{{ error }}</p>

      <div v-if="loading" class="empty-state teachers-empty">Chargement…</div>
      <div v-else-if="items.length === 0" class="empty-state teachers-empty">
        Aucun enseignant dans votre périmètre.
      </div>
      <div v-else-if="displayedItems.length === 0" class="empty-state teachers-empty">
        Aucun résultat pour « {{ searchQuery.trim() }} ».
      </div>

      <div v-else class="table-wrap">
        <table class="teachers-table">
          <thead>
            <tr>
              <th class="select-col">
                <input
                  type="checkbox"
                  aria-label="Sélectionner tous les enseignants affichés"
                  :checked="allVisibleSelected"
                  :indeterminate="someVisibleSelected"
                  :disabled="bulkDeleting"
                  @change="toggleSelectAllVisible"
                />
              </th>
              <th class="col-identity">Enseignant</th>
              <th class="col-cycle">Cycle</th>
              <th class="col-type">Type</th>
              <th class="col-class">Classe</th>
              <th class="col-subject">Spécialité</th>
              <th class="col-courses num">Affectations</th>
              <th class="col-phone hide-md">Téléphone</th>
              <th class="col-actions">Actions</th>
            </tr>
          </thead>
          <tbody>
            <template v-for="(item, index) in displayedItems" :key="item.id">
              <tr
                class="clickable-row"
                :class="{ 'is-selected': selectedTeacherIds.includes(item.id) }"
                tabindex="0"
                role="link"
                @click="openTeacherDetail(item)"
                @keydown.enter.prevent="openTeacherDetail(item)"
                @keydown.space.prevent="openTeacherDetail(item)"
              >
              <td class="select-col" @click.stop @keydown.stop>
                <input
                  type="checkbox"
                  :aria-label="`Sélectionner ${teacherDisplayName(item)}`"
                  :checked="selectedTeacherIds.includes(item.id)"
                  :disabled="bulkDeleting"
                  @change="toggleTeacherSelection(item.id, $event)"
                />
              </td>
              <td class="col-identity">
                <div class="teacher-identity">
                  <RouterLink
                    class="teacher-name"
                    :to="{ name: 'teacher-detail', params: { id: item.id } }"
                    @click.stop
                    @keydown.stop
                  >
                    {{ teacherDisplayName(item) }}
                  </RouterLink>
                  <span v-if="item.registration_number" class="teacher-matricule">
                    {{ item.registration_number }}
                  </span>
                </div>
              </td>
              <td class="col-cycle muted-cell">{{ teacherCycleLabel(item.cycle) }}</td>
              <td class="col-type">
                <span
                  class="role-text"
                  :class="item.assignment_role === 'principal' ? 'is-principal' : 'is-intervenant'"
                >
                  {{ teacherAssignmentRoleLabel(item) }}
                </span>
              </td>
              <td class="col-class">{{ rowPartsFor(item).classroom }}</td>
              <td class="col-subject muted-cell">{{ rowPartsFor(item).subject }}</td>
              <td class="col-courses num">
                <span
                  class="courses-value"
                  :class="{ 'is-warning': rowPartsFor(item).courseWarning }"
                  :title="rowPartsFor(item).courseWarning ? 'Aucun cours assigné' : undefined"
                >
                  {{ rowPartsFor(item).courses }}
                </span>
              </td>
              <td class="col-phone hide-md muted-cell">{{ item.phone ?? '—' }}</td>
              <td class="col-actions" @click.stop @keydown.stop>
                <RowActionMenu
                  :open-up="index >= displayedItems.length - 2"
                  :aria-label="`Actions pour ${item.user?.name ?? 'enseignant'}`"
                >
                <RouterLink :to="{ name: 'teacher-detail', params: { id: item.id } }">
                  Voir la fiche
                </RouterLink>
                <button
                  v-if="canAssignPrimaryClass && item.teacher_type === 'primaire'"
                  type="button"
                  @click="openAssignClass(item)"
                >
                  Assigner une classe
                </button>
                <button type="button" @click="openEdit(item)">Modifier</button>
                <button type="button" class="danger-action" @click="remove(item)">Supprimer</button>
              </RowActionMenu>
            </td>
              </tr>
            </template>
          </tbody>
      </table>
      </div>
    </div>

    <Modal
      :open="showForm"
      :title="editing ? 'Modifier un enseignant' : 'Nouveau profil enseignant'"
      size="xlarge"
      @close="showForm = false"
    >
      <form id="teacher-form" class="teacher-form" @submit.prevent="submit">
        <section class="form-section">
          <h3>Type d'enseignant</h3>
          <p v-if="!canPickTeacherType && defaultTeacherTypeForScope" class="section-note">
            Périmètre {{ auth.user?.admin_scope_label ?? 'cycle' }} :
            {{ defaultTeacherTypeForScope === 'primaire' ? 'Maternel & Primaire' : 'Secondaire & CTEB' }}.
          </p>
          <div v-if="canPickTeacherType" class="type-picker" role="radiogroup" aria-label="Type d'enseignant">
            <label class="type-option" :class="{ active: form.teacher_type === 'primaire' }">
              <input
                type="radio"
                name="teacher_type"
                value="primaire"
                :checked="form.teacher_type === 'primaire'"
                :disabled="!!editing"
                @change="setTeacherType('primaire')"
              />
              <span class="type-option-title">Enseignant du Primaire</span>
              <span class="type-option-desc">Maternel et primaire — affectation par classe (tous les cours)</span>
            </label>
            <label class="type-option" :class="{ active: form.teacher_type === 'secondaire' }">
              <input
                type="radio"
                name="teacher_type"
                value="secondaire"
                :checked="form.teacher_type === 'secondaire'"
                :disabled="!!editing"
                @change="setTeacherType('secondaire')"
              />
              <span class="type-option-title">Enseignant du Secondaire</span>
              <span class="type-option-desc">Secondaire et CTEB — cours assignés depuis la vue Cours</span>
            </label>
          </div>
          <small v-if="formErrors.teacher_type" class="err">{{ formErrors.teacher_type[0] }}</small>
        </section>

        <section v-if="form.teacher_type" class="form-section">
          <h3>Informations personnelles et administratives</h3>
          <div class="form-grid form-grid--identity">
            <div class="field wide">
              <label for="t-name">Nom &amp; Prénom</label>
              <input id="t-name" v-model="form.name" type="text" maxlength="255" required />
              <small v-if="formErrors.name" class="err">{{ formErrors.name[0] }}</small>
            </div>
            <div class="field">
              <label for="t-gender">Sexe</label>
              <select id="t-gender" v-model="form.gender">
                <option value="">— Non renseigné —</option>
                <option value="F">Féminin</option>
                <option value="M">Masculin</option>
              </select>
              <small v-if="formErrors.gender" class="err">{{ formErrors.gender[0] }}</small>
            </div>
            <div class="field">
              <label for="t-birth">Date de naissance</label>
              <input id="t-birth" v-model="form.birth_date" type="date" />
              <small v-if="formErrors.birth_date" class="err">{{ formErrors.birth_date[0] }}</small>
            </div>
            <div class="field">
              <label>Numéro de matricule</label>
              <div class="readonly-field">
                {{ editing?.registration_number ?? 'Généré automatiquement' }}
              </div>
              <small v-if="!editing" class="hint">Format : ENS-PRI-2026-00001 ou ENS-SEC-2026-00001</small>
              <small v-if="formErrors.registration_number" class="err">{{ formErrors.registration_number[0] }}</small>
            </div>
            <div class="field">
              <label for="t-phone">Téléphone</label>
              <input id="t-phone" v-model="form.phone" type="tel" maxlength="32" />
              <small v-if="formErrors.phone" class="err">{{ formErrors.phone[0] }}</small>
            </div>
            <div class="field wide">
              <label for="t-address">Adresse / Quartier</label>
              <input id="t-address" v-model="form.address" type="text" maxlength="255" />
              <small v-if="formErrors.address" class="err">{{ formErrors.address[0] }}</small>
            </div>
            <div class="field">
              <label for="t-grade">Grade / Titre</label>
              <input id="t-grade" v-model="form.grade" type="text" maxlength="128" />
              <small v-if="formErrors.grade" class="err">{{ formErrors.grade[0] }}</small>
            </div>
            <div class="field">
              <label for="t-contract">Type de contrat</label>
              <select id="t-contract" v-model="form.contract_type">
                <option value="">— Non renseigné —</option>
                <option value="Permanent">Permanent</option>
                <option value="Vacataire">Vacataire</option>
              </select>
              <small v-if="formErrors.contract_type" class="err">{{ formErrors.contract_type[0] }}</small>
            </div>
            <div class="field">
              <label for="t-hired">Date d'entrée en service</label>
              <input id="t-hired" v-model="form.hired_on" type="date" />
              <small v-if="formErrors.hired_on" class="err">{{ formErrors.hired_on[0] }}</small>
            </div>
          </div>
        </section>

        <section v-if="form.teacher_type === 'primaire'" class="form-section">
          <h3>Affectation</h3>
          <p class="section-note">
            Après création, utilisez le menu <strong>⋮</strong> → <strong>Assigner une classe</strong> :
            l'enseignant titulaire recevra automatiquement tous les cours de la division.
          </p>
        </section>

        <section v-if="isSecondaryTeacher" class="form-section">
          <h3>Spécialité</h3>
          <p class="section-note">
            Indiquez la matière enseignée au secondaire / CTEB. Les cours s'assignent ensuite depuis
            <strong>Cours</strong> via le menu d'actions de chaque ligne.
          </p>
          <div class="form-grid">
            <div class="field wide">
              <label for="t-speciality">Spécialité / matière enseignée</label>
              <input
                id="t-speciality"
                v-model="form.speciality"
                type="text"
                list="speciality-suggestions"
                maxlength="128"
                required
                placeholder="Ex. Mathématiques, Français…"
              />
              <datalist id="speciality-suggestions">
                <option v-for="name in specialityOptions" :key="name" :value="name" />
              </datalist>
              <small v-if="referenceLoading" class="hint">Chargement des matières…</small>
              <small v-if="formErrors.speciality" class="err">{{ formErrors.speciality[0] }}</small>
            </div>
          </div>
        </section>

        <section v-if="form.teacher_type" class="form-section">
          <h3>Accès à la plateforme</h3>
          <p class="section-note">
            L'enseignant pourra se connecter avec son matricule ou son email.
            Si aucun mot de passe n'est défini, le mot de passe par défaut
            <code>Malunga2026</code> sera appliqué.
          </p>
          <div class="form-grid">
            <div class="field wide">
              <label for="t-email">Email (optionnel)</label>
              <input id="t-email" v-model="form.email" type="email" maxlength="255" autocomplete="off" />
              <small v-if="formErrors.email" class="err">{{ formErrors.email[0] }}</small>
            </div>
            <div class="field wide">
              <label for="t-password">
                Mot de passe
                <span v-if="!editing" class="optional">(optionnel)</span>
                <span v-else class="optional">(laisser vide pour conserver)</span>
              </label>
              <input
                id="t-password"
                v-model="form.password"
                type="password"
                minlength="8"
                autocomplete="new-password"
              />
              <small v-if="formErrors.password" class="err">{{ formErrors.password[0] }}</small>
            </div>
          </div>
        </section>

        <p v-if="formError" class="alert alert-error">{{ formError }}</p>
      </form>
      <template #footer>
        <button type="button" @click="showForm = false">Annuler</button>
        <button type="submit" form="teacher-form" class="btn-primary" :disabled="submitting">
          {{ submitting ? 'Enregistrement…' : editing ? 'Enregistrer' : 'Créer' }}
        </button>
      </template>
    </Modal>

    <Modal
      :open="showAssignClass"
      title="Assigner une classe titulaire"
      max-width="32rem"
      @close="showAssignClass = false"
    >
      <div v-if="assigningTeacher" class="assign-class-modal">
        <p class="section-note">
          Enseignant : <strong>{{ teacherDisplayName(assigningTeacher) }}</strong>.
          Tous les cours du programme de la classe seront rattachés à ce professeur.
        </p>

        <div v-if="assignClassLoading" class="empty-state compact">Chargement des classes…</div>
        <template v-else>
          <div class="field">
            <label for="assign-classroom">Classe</label>
            <select id="assign-classroom" v-model.number="assignClassroomId">
              <option :value="null">— Sélectionner —</option>
              <option v-for="classroom in primaryClassrooms" :key="classroom.id" :value="classroom.id">
                {{ classroomLabel(classroom) }}
              </option>
            </select>
            <small v-if="primaryClassrooms.length === 0" class="hint">
              Aucune division maternelle / primaire dans votre périmètre.
            </small>
            <small v-if="assignClassErrors.classroom_id" class="err">
              {{ assignClassErrors.classroom_id[0] }}
            </small>
          </div>
        </template>

        <p v-if="assignClassError" class="alert alert-error">{{ assignClassError }}</p>
      </div>
      <template #footer>
        <button type="button" @click="showAssignClass = false">Annuler</button>
        <button
          v-if="assigningTeacher?.main_classroom"
          type="button"
          class="danger-action"
          :disabled="assignClassSubmitting"
          @click="unassignClass"
        >
          Retirer la classe
        </button>
        <button
          type="button"
          class="btn-primary"
          :disabled="assignClassSubmitting || !assignClassroomId || assignClassLoading"
          @click="submitAssignClass"
        >
          {{ assignClassSubmitting ? 'Enregistrement…' : 'Assigner' }}
        </button>
      </template>
    </Modal>
  </section>
</template>

<style scoped>
.sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
  border: 0;
}
.teachers-card {
  overflow: hidden;
}
.teachers-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
  padding: 1.1rem 1.15rem 0.9rem;
  border-bottom: 1px solid var(--border);
}
.teachers-heading h1 {
  margin: 0;
  font-size: 1.2rem;
  font-weight: 800;
  letter-spacing: -0.02em;
}
.teachers-meta {
  margin: 0.2rem 0 0;
  color: var(--text-soft);
  font-size: 0.82rem;
}
.teachers-alert {
  margin: 0 1rem 1rem;
}
.teachers-empty {
  margin: 1rem;
}
button + button { margin-left: 0.4rem; }
code {
  font-family: ui-monospace, Consolas, monospace;
  background: var(--bg-soft);
  padding: 0.1rem 0.35rem;
  border-radius: 4px;
  font-size: 0.78rem;
}
.err { display: block; color: var(--danger); font-size: 0.78rem; margin-top: 0.25rem; }
.optional { color: var(--text-soft); font-weight: 500; font-size: 0.82rem; }
.teacher-identity {
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
  min-width: 10rem;
}
.teacher-name {
  color: var(--text);
  font-size: 0.9rem;
  font-weight: 750;
  line-height: 1.3;
  text-decoration: none;
}
.teacher-name:hover,
.teacher-name:focus-visible {
  color: var(--primary);
}
.teacher-matricule {
  color: var(--text-muted);
  font-family: ui-monospace, Consolas, monospace;
  font-size: 0.72rem;
}
.clickable-row {
  cursor: pointer;
  transition: background 0.12s ease;
}
.clickable-row:hover {
  background: var(--primary-soft);
}
.clickable-row:focus-visible {
  outline: 2px solid var(--primary);
  outline-offset: -2px;
}
.is-selected {
  background: var(--primary-soft);
}
.select-col {
  width: 2.6rem;
  text-align: center;
}
.select-col input {
  width: 1rem;
  height: 1rem;
  margin: 0;
}
tbody .select-col input {
  opacity: 0;
  transition: opacity 0.15s;
}
tbody tr:hover .select-col input,
tbody tr.is-selected .select-col input,
tbody tr:focus-within .select-col input {
  opacity: 1;
}
.teacher-toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.65rem 0.85rem;
  padding: 0.8rem 1rem;
  border-bottom: 1px solid var(--border);
  background: linear-gradient(180deg, var(--bg-subtle) 0%, var(--bg-card) 100%);
}
.teacher-search {
  flex: 1 1 14rem;
  max-width: 20rem;
  margin-left: auto;
}
.teacher-search input {
  width: 100%;
  min-height: 2.35rem;
  border-radius: 8px;
}
.cycle-tabs {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  flex-wrap: wrap;
}
.cycle-tab {
  min-height: 2.2rem;
  padding: 0.4rem 0.75rem;
  border: 1px solid var(--border);
  border-radius: 8px;
  background: var(--bg-card);
  color: var(--text-soft);
  font-size: 0.84rem;
  font-weight: 700;
  box-shadow: none;
}
.cycle-tab:hover {
  border-color: var(--primary);
  color: var(--primary);
  transform: none;
}
.cycle-tab.active {
  border-color: var(--primary);
  background: var(--primary);
  color: #fff;
  box-shadow: 0 4px 12px rgb(37 99 235 / 18%);
}
.selection-strip {
  display: inline-flex;
  align-items: center;
  gap: 0.75rem;
  width: fit-content;
  padding: 0.5rem 0.7rem;
  border: 1px solid var(--primary-tint);
  border-radius: 8px;
  background: var(--primary-soft);
  color: var(--text);
  font-size: 0.86rem;
}
.selection-summary,
.bulk-actions {
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
}
.selection-strip strong {
  color: var(--primary);
}
.selection-strip button {
  min-height: 1.8rem;
  padding: 0.2rem 0.55rem;
  border: 1px solid var(--primary-tint);
  border-radius: 6px;
  background: var(--bg-soft);
  color: var(--primary);
  font-size: 0.8rem;
  font-weight: 800;
}
.selection-strip button:disabled {
  cursor: not-allowed;
  opacity: 0.65;
}
.selection-strip .bulk-danger {
  border-color: rgba(248, 113, 113, 0.35);
  color: var(--danger);
}
.selection-strip .bulk-danger:hover:not(:disabled) {
  background: var(--danger-soft);
}
.teacher-form {
  display: grid;
  gap: 1.15rem;
}
@media (min-width: 720px) {
  .teacher-form .form-grid--identity {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }
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
.section-note {
  margin: 0;
  color: var(--text-soft);
  font-size: 0.84rem;
  line-height: 1.45;
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
  background: var(--bg-soft);
  color: var(--text-soft);
  font-size: 0.9rem;
  font-weight: 750;
}
.field-label {
  display: block;
  margin-bottom: 0.35rem;
  font-size: 0.86rem;
  font-weight: 800;
  color: var(--text);
}
.hint {
  display: block;
  margin-top: 0.25rem;
  color: var(--text-soft);
  font-size: 0.78rem;
}
.type-picker {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.72rem;
}
.type-option {
  display: grid;
  gap: 0.25rem;
  padding: 0.8rem;
  border: 1px solid var(--border);
  border-radius: 10px;
  background: var(--bg-soft);
  cursor: pointer;
  transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.type-option.active {
  border-color: var(--primary);
  box-shadow: 0 0 0 1px var(--primary);
  background: var(--primary-soft);
}
.type-option input {
  position: absolute;
  opacity: 0;
  pointer-events: none;
}
.type-option-title {
  font-size: 0.88rem;
  font-weight: 900;
  color: var(--text);
}
.type-option-desc {
  font-size: 0.78rem;
  color: var(--text-soft);
  line-height: 1.35;
}
.secondary-role-picker {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.72rem;
  margin-bottom: 0.85rem;
}
.secondary-role-option {
  display: grid;
  gap: 0.2rem;
  padding: 0.75rem;
  border: 1px solid var(--border);
  border-radius: 10px;
  background: var(--bg-soft);
  cursor: pointer;
}
.secondary-role-option.active {
  border-color: var(--primary);
  background: var(--primary-soft);
  box-shadow: 0 0 0 1px rgb(59 130 246 / 25%);
}
.secondary-role-option input {
  position: absolute;
  opacity: 0;
  pointer-events: none;
}
.secondary-role-title {
  font-size: 0.86rem;
  font-weight: 900;
  color: var(--text);
}
.secondary-role-desc {
  font-size: 0.76rem;
  color: var(--text-soft);
  line-height: 1.35;
}
.table-wrap {
  overflow: auto;
  max-height: min(70vh, 52rem);
  -webkit-overflow-scrolling: touch;
}
.teachers-table {
  width: 100%;
  min-width: 48rem;
  border-collapse: collapse;
}
.teachers-table thead th {
  position: sticky;
  top: 0;
  z-index: 2;
  padding: 0.55rem 0.65rem;
  border-bottom: 1px solid var(--border-strong);
  background: var(--bg-subtle);
  font-size: 0.68rem;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: var(--text-soft);
  text-align: left;
  white-space: nowrap;
}
.teachers-table tbody td {
  padding: 0.62rem 0.65rem;
  border-bottom: 1px solid var(--border);
  vertical-align: middle;
  font-size: 0.86rem;
}
.teachers-table tbody tr:last-child td {
  border-bottom: 0;
}
.col-identity { min-width: 11rem; }
.col-cycle { width: 6rem; }
.col-type { width: 6.5rem; }
.col-class { min-width: 9rem; max-width: 14rem; }
.col-subject { min-width: 6rem; }
.col-courses { width: 3.5rem; }
.col-phone { min-width: 8.5rem; }
.col-actions {
  width: 1%;
  text-align: right;
  white-space: nowrap;
}
.num { text-align: right; }
.muted-cell { color: var(--text-soft); }
.role-text.is-principal { color: var(--text); font-weight: 750; }
.role-text.is-intervenant { color: var(--text-soft); font-weight: 600; }
.courses-value { font-variant-numeric: tabular-nums; font-weight: 700; }
.courses-value.is-warning { color: var(--warn); }
.classroom-picker-field {
  gap: 0.55rem;
}
.classroom-picker-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
}
.selection-count {
  display: inline-flex;
  align-items: center;
  min-height: 1.6rem;
  padding: 0.1rem 0.55rem;
  border-radius: 999px;
  background: var(--primary-soft);
  color: var(--primary);
  font-size: 0.76rem;
  font-weight: 800;
}
.classroom-picker {
  display: grid;
  gap: 0.72rem;
  padding: 0.75rem;
  border: 1px solid var(--border);
  border-radius: 12px;
  background: linear-gradient(180deg, var(--bg-subtle) 0%, var(--bg-soft) 100%);
}
.classroom-picker--single {
  border-color: var(--primary-tint);
  background: linear-gradient(180deg, var(--bg-subtle) 0%, var(--primary-soft) 100%);
}
.classroom-picker--single .classroom-grid {
  grid-template-columns: 1fr;
}
.classroom-picker-empty {
  padding: 1rem;
  border: 1px dashed var(--border);
  border-radius: 10px;
  background: var(--bg-soft);
  color: var(--text-soft);
  font-size: 0.84rem;
  text-align: center;
}
.classroom-picker-empty.compact {
  padding: 0.85rem;
}
.selected-classrooms {
  display: flex;
  flex-wrap: wrap;
  gap: 0.45rem;
}
.selected-classroom-chip {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  max-width: 100%;
  padding: 0.28rem 0.45rem 0.28rem 0.6rem;
  border: 1px solid var(--primary-tint);
  border-radius: 999px;
  background: var(--bg-soft);
  color: var(--primary);
  font-size: 0.78rem;
  font-weight: 800;
  line-height: 1.2;
}
.selected-classroom-chip:hover {
  border-color: var(--primary);
  background: var(--primary-soft);
}
.selected-classroom-chip span:first-child {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.chip-remove {
  display: inline-grid;
  place-items: center;
  width: 1rem;
  height: 1rem;
  border-radius: 999px;
  background: var(--primary-tint);
  font-size: 0.82rem;
  line-height: 1;
}
.classroom-picker-toolbar {
  display: flex;
  align-items: center;
  gap: 0.65rem;
  flex-wrap: wrap;
}
.classroom-search {
  flex: 1 1 12rem;
  min-height: 2.35rem;
  padding: 0.45rem 0.7rem;
  border: 1px solid var(--border);
  border-radius: 8px;
  background: var(--bg-soft);
  font-size: 0.86rem;
}
.classroom-search:focus-visible {
  outline: 2px solid var(--primary);
  outline-offset: 1px;
  border-color: var(--primary);
}
.select-all-toggle {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  color: var(--text-soft);
  font-size: 0.8rem;
  font-weight: 700;
  white-space: nowrap;
  user-select: none;
}
.select-all-toggle input {
  width: 0.95rem;
  height: 0.95rem;
  margin: 0;
}
.classroom-groups {
  display: grid;
  gap: 0.85rem;
  max-height: min(22rem, 42vh);
  overflow: auto;
  padding-right: 0.15rem;
}
.classroom-group {
  display: grid;
  gap: 0.45rem;
}
.classroom-group-title {
  margin: 0;
  color: var(--text-soft);
  font-size: 0.74rem;
  font-weight: 900;
  letter-spacing: 0.04em;
  text-transform: uppercase;
}
.classroom-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 0.45rem;
}
.classroom-card {
  display: flex;
  align-items: flex-start;
  gap: 0.55rem;
  min-height: 3.1rem;
  padding: 0.62rem 0.68rem;
  border: 1px solid var(--border);
  border-radius: 10px;
  background: var(--bg-soft);
  color: var(--text);
  text-align: left;
  transition: border-color 0.15s ease, box-shadow 0.15s ease, background 0.15s ease;
}
.classroom-card:hover {
  border-color: var(--primary);
  box-shadow: 0 6px 16px rgb(0 0 0 / 35%);
}
.classroom-card.selected {
  border-color: var(--primary);
  background: var(--primary-soft);
  box-shadow: 0 0 0 1px rgb(59 130 246 / 25%);
}
.classroom-card-check {
  display: inline-grid;
  place-items: center;
  width: 1.15rem;
  height: 1.15rem;
  margin-top: 0.1rem;
  border: 1.5px solid var(--border-strong);
  border-radius: 4px;
  background: var(--bg-card);
  color: transparent;
  flex-shrink: 0;
}
.classroom-card-check svg {
  width: 0.72rem;
  height: 0.72rem;
}
.classroom-card.selected .classroom-card-check {
  border-color: var(--primary);
  background: var(--primary);
  color: #fff;
}
.classroom-card.single .classroom-card-check {
  border-radius: 999px;
}
.classroom-card.single.selected .classroom-card-check {
  background: var(--primary);
  color: #fff;
}
.radio-dot {
  width: 0.42rem;
  height: 0.42rem;
  border-radius: 999px;
  background: currentColor;
}
.classroom-card-body {
  display: grid;
  gap: 0.12rem;
  min-width: 0;
}
.classroom-card-body strong {
  font-size: 0.84rem;
  line-height: 1.25;
}
.classroom-card-meta {
  color: var(--text-soft);
  font-size: 0.74rem;
  line-height: 1.2;
}
.subject-picker-field {
  margin-top: 0.5rem;
}
.subject-picker-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 0.75rem;
  margin-bottom: 0.55rem;
}
.subject-picker-hint {
  margin: 0.2rem 0 0;
  color: var(--text-soft);
  font-size: 0.8rem;
  line-height: 1.4;
}
.subject-picker-count {
  flex-shrink: 0;
  display: inline-flex;
  align-items: center;
  min-height: 1.65rem;
  padding: 0.2rem 0.65rem;
  border-radius: 999px;
  border: 1px solid var(--border);
  background: var(--bg-soft);
  color: var(--text-soft);
  font-size: 0.78rem;
  font-weight: 800;
  white-space: nowrap;
}
.subject-picker-count--active {
  border-color: var(--primary-tint);
  background: var(--primary-soft);
  color: var(--primary);
}
.subject-picker {
  display: grid;
  gap: 0.65rem;
  padding: 0.85rem;
  border: 1px solid var(--border);
  border-radius: 12px;
  background: linear-gradient(180deg, var(--bg-subtle) 0%, var(--bg-card) 100%);
  box-shadow: inset 0 1px 0 rgb(255 255 255 / 5%);
}
.subject-picker-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  padding-bottom: 0.55rem;
  border-bottom: 1px solid var(--border);
}
.subject-select-all {
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
  color: var(--text);
  font-size: 0.8rem;
  font-weight: 700;
  user-select: none;
  cursor: pointer;
}
.subject-select-all input {
  width: 0.95rem;
  height: 0.95rem;
  margin: 0;
  accent-color: var(--primary);
}
.subject-toolbar-btn {
  min-height: 1.85rem;
  padding: 0.25rem 0.7rem;
  border: 1px solid var(--border);
  border-radius: 8px;
  background: var(--bg-soft);
  color: var(--text-soft);
  font-size: 0.78rem;
  font-weight: 800;
  cursor: pointer;
  transition: border-color 0.15s ease, color 0.15s ease, background 0.15s ease;
}
.subject-toolbar-btn:hover:not(:disabled) {
  border-color: rgba(248, 113, 113, 0.35);
  background: var(--danger-soft);
  color: var(--danger);
}
.subject-toolbar-btn:disabled {
  opacity: 0.45;
  cursor: not-allowed;
}
.subject-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 0.5rem;
  max-height: min(20rem, 40vh);
  overflow: auto;
  padding: 0.1rem 0.15rem 0.1rem 0;
}
.subject-card {
  display: flex;
  align-items: flex-start;
  gap: 0.55rem;
  min-height: 2.85rem;
  padding: 0.62rem 0.68rem;
  border: 1px solid var(--border);
  border-radius: 10px;
  background: var(--bg-soft);
  color: var(--text);
  font-size: 0.84rem;
  line-height: 1.25;
  cursor: pointer;
  transition: border-color 0.15s ease, box-shadow 0.15s ease, background 0.15s ease;
}
.subject-card:hover {
  border-color: var(--primary);
  box-shadow: 0 4px 12px rgb(0 0 0 / 35%);
}
.subject-card:focus-within {
  outline: 2px solid var(--primary);
  outline-offset: 1px;
}
.subject-card-input {
  position: absolute;
  opacity: 0;
  width: 1px;
  height: 1px;
  pointer-events: none;
}
.subject-card-check {
  display: inline-grid;
  place-items: center;
  flex-shrink: 0;
  width: 1.15rem;
  height: 1.15rem;
  margin-top: 0.08rem;
  border: 1.5px solid var(--border-strong);
  border-radius: 4px;
  background: var(--bg-card);
  color: transparent;
}
.subject-card-check svg {
  width: 0.72rem;
  height: 0.72rem;
}
.subject-card.selected {
  border-color: var(--primary);
  background: var(--primary-soft);
  box-shadow: 0 0 0 1px rgb(59 130 246 / 25%);
}
.subject-card.selected .subject-card-check {
  border-color: var(--primary);
  background: var(--primary);
  color: #fff;
}
.subject-card-label {
  flex: 1;
  font-weight: 650;
}
@media (max-width: 960px) {
  .hide-md {
    display: none;
  }
  .teacher-search {
    flex: 1 1 100%;
    max-width: none;
    margin-left: 0;
    order: 3;
  }
  .teachers-table {
    min-width: 40rem;
  }
}
@media (max-width: 720px) {
  .teachers-header {
    flex-direction: column;
    align-items: stretch;
  }
  .cycle-tabs {
    align-items: stretch;
  }
  .cycle-tab {
    flex: 1 1 8rem;
  }
  .selection-strip {
    width: 100%;
    justify-content: space-between;
  }
  .selection-summary,
  .bulk-actions {
    flex-wrap: wrap;
  }
  .form-grid {
    grid-template-columns: 1fr;
  }
  .type-picker {
    grid-template-columns: 1fr;
  }
  .secondary-role-picker {
    grid-template-columns: 1fr;
  }
  .teacher-form .form-grid--identity,
  .classroom-grid,
  .subject-grid {
    grid-template-columns: 1fr;
  }
  .classroom-picker-toolbar {
    align-items: stretch;
  }
  .select-all-toggle {
    justify-content: flex-end;
  }
}
</style>
