<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { Plus } from 'lucide-vue-next'
import { api, ApiError } from '../api/client'
import Modal from '../components/Modal.vue'
import type { Assignment, ClassRoom, LevelCycle, Paginated, SchoolYear, Subject, Teacher } from '../types'
import { useConfirmStore } from '../stores/confirm'
import { useSchoolYearStore } from '../stores/schoolYear'
import { useAuthStore } from '../stores/auth'
import { useCycleTabs, type CycleFilter } from '../composables/useCycleTabs'

const schoolYearStore = useSchoolYearStore()
const confirmDialog = useConfirmStore()
const authStore = useAuthStore()
const { cycleTabs } = useCycleTabs()

interface TimetableSlot {
  id: number
  classroom_id: number
  subject_id: number
  teacher_id: number
  school_year_id: number
  day_of_week: number
  starts_at: string
  ends_at: string
  room: string | null
  subject?: { id: number; name: string }
  teacher?: { id: number; name: string }
  classroom?: { id: number; full_name: string }
}

const DAYS = [
  { id: 1, label: 'Lundi' },
  { id: 2, label: 'Mardi' },
  { id: 3, label: 'Mercredi' },
  { id: 4, label: 'Jeudi' },
  { id: 5, label: 'Vendredi' },
  { id: 6, label: 'Samedi' },
]

const SLOT_DURATION_MINUTES = 45
const DEFAULT_DAY_START = '08:00'

const classrooms = ref<ClassRoom[]>([])
const classroomSubjects = ref<Subject[]>([])
const teachers = ref<Teacher[]>([])
const assignments = ref<Assignment[]>([])
const slots = ref<TimetableSlot[]>([])

const activeCycle = ref<CycleFilter>('all')
const classroomSearch = ref('')
const filterClassroom = ref<number | ''>('')
const loading = ref(false)
const error = ref('')

const showForm = ref(false)
const showQuickAdd = ref(false)
const quickAddDay = ref(1)
const quickAddLoading = ref(false)
const quickAddSubmitting = ref(false)
const quickAddError = ref('')
const editing = ref<TimetableSlot | null>(null)
const submitting = ref(false)
const formError = ref('')
const formErrors = reactive<Record<string, string[]>>({})

const form = reactive({
  classroom_id: 0 as number,
  subject_id: 0 as number,
  teacher_id: 0 as number,
  school_year_id: 0 as number,
  day_of_week: 1,
  starts_at: '08:00',
  ends_at: '09:00',
  room: '',
})

const slotsByDay = computed(() => {
  const grouped: Record<number, TimetableSlot[]> = {}
  for (const d of DAYS) grouped[d.id] = []
  for (const s of slots.value) {
    if (grouped[s.day_of_week]) grouped[s.day_of_week].push(s)
  }
  for (const k of Object.keys(grouped)) {
    grouped[+k].sort((a, b) => a.starts_at.localeCompare(b.starts_at))
  }
  return grouped
})

const visibleClassrooms = computed<ClassRoom[]>(() => {
  if (activeCycle.value === 'all') return classrooms.value

  return classrooms.value.filter((classroom) => classroom.level?.cycle === activeCycle.value)
})

function classroomMatchesSearch(classroom: ClassRoom, query: string): boolean {
  const haystack = [
    classroom.full_name,
    classroom.section,
    classroom.option,
    classroom.level?.name,
    classroom.school_option?.name,
    classroom.school_class?.name,
  ]
    .filter(Boolean)
    .join(' ')
    .toLowerCase()

  return haystack.includes(query)
}

const filteredClassrooms = computed<ClassRoom[]>(() => {
  const query = classroomSearch.value.trim().toLowerCase()
  const base = visibleClassrooms.value
  if (!query) return base

  return base.filter((classroom) => classroomMatchesSearch(classroom, query))
})

const classroomSelectPlaceholder = computed(() => {
  if (filteredClassrooms.value.length === 0) {
    return classroomSearch.value.trim() ? 'Aucune classe trouvée' : 'Choisir une classe…'
  }

  return 'Choisir une classe…'
})

function onClassroomPicked(): void {
  if (filterClassroom.value !== '') {
    classroomSearch.value = ''
  }
}

watch(classroomSearch, (query) => {
  const normalized = query.trim().toLowerCase()
  if (normalized === '' || filterClassroom.value === '') return

  const selected = visibleClassrooms.value.find((classroom) => classroom.id === filterClassroom.value)
  if (selected && !classroomMatchesSearch(selected, normalized)) {
    filterClassroom.value = ''
  }
})

watch(cycleTabs, (tabs) => {
  if (!tabs.some((tab) => tab.value === activeCycle.value)) {
    activeCycle.value = 'all'
  }
})

const isTeacherView = computed(() => authStore.hasRole('enseignant'))
const canManageTimetable = computed(() => authStore.hasRole('admin'))

const years = computed<SchoolYear[]>(() =>
  schoolYearStore.years.length > 0
    ? schoolYearStore.years
    : schoolYearStore.current ? [schoolYearStore.current] : [],
)

const selectedSchoolYear = computed(() =>
  schoolYearStore.selected
    ?? years.value.find((year) => year.id === schoolYearStore.effectiveId)
    ?? schoolYearStore.current
    ?? years.value[0]
    ?? null,
)

function isSecondaryCycle(cycle: LevelCycle | undefined): boolean {
  return cycle === 'secondaire' || cycle === 'cteb'
}

const gridClassroom = computed(() =>
  classrooms.value.find((classroom) => classroom.id === Number(filterClassroom.value)) ?? null,
)

const gridClassroomName = computed(() => gridClassroom.value?.full_name ?? 'Classe')

const isSecondaryGridClassroom = computed(() =>
  isSecondaryCycle(gridClassroom.value?.level?.cycle),
)

const formClassroom = computed(() =>
  classrooms.value.find((classroom) => classroom.id === form.classroom_id) ?? null,
)

const isSecondaryFormClassroom = computed(() =>
  isSecondaryCycle(formClassroom.value?.level?.cycle),
)

const quickAddDayLabel = computed(() =>
  DAYS.find((day) => day.id === quickAddDay.value)?.label ?? 'Jour',
)

const quickAddStartTime = computed(() => suggestedStartTime(quickAddDay.value))

const quickAddEndTime = computed(() =>
  addMinutesToTime(quickAddStartTime.value, SLOT_DURATION_MINUTES),
)

const formCourseAssignments = computed(() =>
  assignments.value.filter((assignment) => assignment.subject_id !== null),
)

const formSubjectOptions = computed(() => classroomSubjects.value)

const selectedSubjectOption = computed(() =>
  classroomSubjects.value.find((subject) => subject.id === form.subject_id) ?? null,
)

const resolvedFormTeacher = computed(() => {
  const assignment = formCourseAssignments.value.find(
    (row) => row.subject_id === form.subject_id,
  )
  if (!assignment) return null

  return assignment.teacher
    ?? teachers.value.find((teacher) => teacher.id === assignment.teacher_id)
    ?? null
})

function subjectHasAssignedTeacher(subjectId: number): boolean {
  return formCourseAssignments.value.some((assignment) => assignment.subject_id === subjectId)
}

function teacherLabelForSubject(subjectId: number): string {
  const assignment = formCourseAssignments.value.find((row) => row.subject_id === subjectId)
    ?? (!isSecondaryGridClassroom.value
      ? assignments.value.find((row) => row.is_main && row.subject_id === null)
      : undefined)

  const teacher = assignment?.teacher
    ?? teachers.value.find((row) => row.id === assignment?.teacher_id)

  return teacher?.user?.name ?? 'Non assigné'
}

function resolveTeacherIdForSubject(subjectId: number): number {
  const assignment = assignments.value.find((row) => row.subject_id === subjectId)
  if (assignment) return assignment.teacher_id

  if (!isSecondaryGridClassroom.value) {
    const main = assignments.value.find((row) => row.is_main && row.subject_id === null)
    if (main) return main.teacher_id
    return teachers.value[0]?.id ?? 0
  }

  return 0
}

function suggestedStartTime(dayId: number): string {
  const daySlots = slotsByDay.value[dayId] ?? []
  if (daySlots.length === 0) return DEFAULT_DAY_START

  const lastSlot = daySlots[daySlots.length - 1]
  return lastSlot.ends_at.slice(0, 5)
}

function addMinutesToTime(time: string, minutes: number): string {
  const [hours, mins] = time.split(':').map(Number)
  const totalMinutes = hours * 60 + mins + minutes
  const normalized = ((totalMinutes % (24 * 60)) + (24 * 60)) % (24 * 60)
  const nextHours = Math.floor(normalized / 60)
  const nextMinutes = normalized % 60

  return `${String(nextHours).padStart(2, '0')}:${String(nextMinutes).padStart(2, '0')}`
}

function isQuickAddBlocked(subjectId: number): boolean {
  return isSecondaryGridClassroom.value && !subjectHasAssignedTeacher(subjectId)
}

const secondarySubjectMissingTeacher = computed(() =>
  isSecondaryFormClassroom.value
  && form.subject_id > 0
  && !subjectHasAssignedTeacher(form.subject_id),
)

const canSubmitSlot = computed(() => {
  if (!isSecondaryFormClassroom.value) return form.teacher_id > 0
  return form.subject_id > 0 && !secondarySubjectMissingTeacher.value
})

async function loadClassroomSubjects(classroomId: number): Promise<void> {
  if (!classroomId) {
    classroomSubjects.value = []
    return
  }

  try {
    const res = await api<Paginated<Subject>>(`/api/v1/classrooms/${classroomId}/subjects`)
    classroomSubjects.value = res.data
  } catch {
    classroomSubjects.value = []
  }
}

async function loadAssignmentsForClassroom(classroomId: number): Promise<void> {
  if (!classroomId) {
    assignments.value = []
    return
  }

  try {
    const res = await api<Paginated<Assignment>>('/api/v1/assignments', {
      query: { classroom_id: classroomId },
    })
    assignments.value = res.data
  } catch {
    assignments.value = []
  }
}

async function loadFormClassroomData(classroomId: number): Promise<void> {
  await Promise.all([
    loadClassroomSubjects(classroomId),
    loadAssignmentsForClassroom(classroomId),
  ])
}

function syncTeacherFromSubject(): void {
  if (!isSecondaryFormClassroom.value) return

  const assignment = formCourseAssignments.value.find(
    (row) => row.subject_id === form.subject_id,
  )
  form.teacher_id = assignment?.teacher_id ?? 0
}

function ensureSecondarySubjectSelection(): void {
  if (!isSecondaryFormClassroom.value) return

  const options = formSubjectOptions.value
  if (options.length === 0) {
    form.subject_id = 0
    form.teacher_id = 0
    return
  }

  if (!options.some((subject) => subject.id === form.subject_id)) {
    form.subject_id = options[0].id
  }

  syncTeacherFromSubject()
}

async function loadRefs(): Promise<void> {
  try {
    const [c] = await Promise.all([
      api<Paginated<ClassRoom>>('/api/v1/classrooms'),
      schoolYearStore.fetchAll(),
    ])
    classrooms.value = c.data

    if (canManageTimetable.value) {
      const t = await api<Paginated<Teacher>>('/api/v1/teachers')
      teachers.value = t.data
    } else {
      teachers.value = []
    }

    if (isTeacherView.value) {
      if (
        filterClassroom.value !== ''
        && !visibleClassrooms.value.some((classroom) => classroom.id === filterClassroom.value)
      ) {
        filterClassroom.value = ''
      }
      form.school_year_id = selectedSchoolYear.value?.id ?? 0
      return
    }

    if (
      visibleClassrooms.value.length
      && (
        filterClassroom.value === ''
        || !visibleClassrooms.value.some((classroom) => classroom.id === filterClassroom.value)
      )
    ) {
      filterClassroom.value = visibleClassrooms.value[0].id
    } else if (visibleClassrooms.value.length === 0) {
      filterClassroom.value = ''
    }
    form.school_year_id = selectedSchoolYear.value?.id ?? 0
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Chargement impossible.'
  }
}

async function loadSlots(): Promise<void> {
  if (filterClassroom.value === '' && !isTeacherView.value) {
    slots.value = []
    return
  }
  loading.value = true
  error.value = ''
  try {
    const query: Record<string, string | number> = {}
    if (filterClassroom.value !== '') query.classroom_id = Number(filterClassroom.value)
    if (activeCycle.value !== 'all') query.cycle = activeCycle.value

    const res = await api<Paginated<TimetableSlot>>('/api/v1/timetable-slots', {
      query,
    })
    slots.value = res.data
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Chargement impossible.'
    slots.value = []
  } finally {
    loading.value = false
  }
}

function setActiveCycle(cycle: CycleFilter): void {
  activeCycle.value = cycle
  classroomSearch.value = ''

  if (isTeacherView.value) {
    if (
      filterClassroom.value !== ''
      && !visibleClassrooms.value.some((classroom) => classroom.id === filterClassroom.value)
    ) {
      filterClassroom.value = ''
    }
    return
  }

  if (
    filterClassroom.value === '' ||
    !visibleClassrooms.value.some((classroom) => classroom.id === filterClassroom.value)
  ) {
    filterClassroom.value = visibleClassrooms.value[0]?.id ?? ''
  }
}

async function openQuickAdd(dayId: number): Promise<void> {
  if (filterClassroom.value === '') {
    error.value = 'Sélectionnez une classe avant d’ajouter un cours.'
    return
  }

  quickAddDay.value = dayId
  quickAddError.value = ''
  showQuickAdd.value = true
  quickAddLoading.value = true

  try {
    await loadFormClassroomData(Number(filterClassroom.value))
  } catch (e) {
    quickAddError.value = e instanceof ApiError ? e.message : 'Impossible de charger les cours.'
  } finally {
    quickAddLoading.value = false
  }
}

function closeQuickAdd(): void {
  showQuickAdd.value = false
  quickAddError.value = ''
}

async function quickAddSubject(subject: Subject): Promise<void> {
  if (filterClassroom.value === '') return
  if (isQuickAddBlocked(subject.id)) {
    quickAddError.value = `« ${subject.name} » n’a pas d’enseignant assigné. Assignez un professeur depuis la page Cours.`
    return
  }

  const teacherId = resolveTeacherIdForSubject(subject.id)
  if (teacherId <= 0) {
    quickAddError.value = `Impossible d’ajouter « ${subject.name} » : enseignant manquant.`
    return
  }

  const startsAt = suggestedStartTime(quickAddDay.value)
  const endsAt = addMinutesToTime(startsAt, SLOT_DURATION_MINUTES)

  quickAddSubmitting.value = true
  quickAddError.value = ''

  try {
    await api('/api/v1/timetable-slots', {
      method: 'POST',
      body: {
        classroom_id: Number(filterClassroom.value),
        subject_id: subject.id,
        teacher_id: teacherId,
        school_year_id: selectedSchoolYear.value?.id ?? form.school_year_id,
        day_of_week: quickAddDay.value,
        starts_at: startsAt,
        ends_at: endsAt,
        room: null,
      },
    })
    closeQuickAdd()
    await loadSlots()
  } catch (e) {
    quickAddError.value = e instanceof ApiError ? e.message : 'Ajout impossible.'
  } finally {
    quickAddSubmitting.value = false
  }
}

async function openCreate(day = 1, time = DEFAULT_DAY_START): Promise<void> {
  editing.value = null
  form.classroom_id = Number(filterClassroom.value) || (visibleClassrooms.value[0]?.id ?? classrooms.value[0]?.id ?? 0)
  form.school_year_id = selectedSchoolYear.value?.id ?? 0
  form.day_of_week = day
  form.starts_at = time
  form.ends_at = addMinutesToTime(time, SLOT_DURATION_MINUTES)
  form.room = ''
  formError.value = ''
  Object.keys(formErrors).forEach((k) => delete formErrors[k])

  await loadFormClassroomData(form.classroom_id)

  if (isSecondaryFormClassroom.value) {
    ensureSecondarySubjectSelection()
  } else {
    form.subject_id = classroomSubjects.value[0]?.id ?? 0
    form.teacher_id = teachers.value[0]?.id ?? 0
  }

  showForm.value = true
}

async function openEdit(slot: TimetableSlot): Promise<void> {
  editing.value = slot
  form.classroom_id = slot.classroom_id
  form.subject_id = slot.subject_id
  form.teacher_id = slot.teacher_id
  form.school_year_id = slot.school_year_id
  form.day_of_week = slot.day_of_week
  form.starts_at = slot.starts_at
  form.ends_at = slot.ends_at
  form.room = slot.room ?? ''
  formError.value = ''
  Object.keys(formErrors).forEach((k) => delete formErrors[k])

  await loadFormClassroomData(form.classroom_id)
  ensureSecondarySubjectSelection()

  showForm.value = true
}

async function submit(): Promise<void> {
  submitting.value = true
  formError.value = ''
  Object.keys(formErrors).forEach((k) => delete formErrors[k])

  if (isSecondaryFormClassroom.value && secondarySubjectMissingTeacher.value) {
    formError.value = `Le cours « ${selectedSubjectOption.value?.name ?? 'sélectionné'} » n’a pas d’enseignant assigné. Assignez un professeur depuis la page Cours avant de créer le créneau.`
    submitting.value = false
    return
  }

  if (isSecondaryFormClassroom.value) {
    syncTeacherFromSubject()
  }

  try {
    if (editing.value) {
      await api(`/api/v1/timetable-slots/${editing.value.id}`, { method: 'PUT', body: { ...form, room: form.room || null } })
    } else {
      await api('/api/v1/timetable-slots', { method: 'POST', body: { ...form, room: form.room || null } })
    }
    showForm.value = false
    await loadSlots()
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

async function remove(slot: TimetableSlot): Promise<void> {
  const ok = await confirmDialog.ask({
    title: 'Supprimer un créneau',
    message: 'Ce créneau d’emploi du temps sera supprimé.',
    details: [
      `${slot.subject?.name ?? 'Cours'} - ${slot.starts_at} à ${slot.ends_at}`,
    ],
    confirmLabel: 'Supprimer',
    variant: 'danger',
  })
  if (!ok) return
  try {
    await api(`/api/v1/timetable-slots/${slot.id}`, { method: 'DELETE' })
    await loadSlots()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Suppression impossible.'
  }
}

watch([filterClassroom, activeCycle], async () => {
  await loadSlots()
  if (filterClassroom.value !== '' && canManageTimetable.value) {
    await loadFormClassroomData(Number(filterClassroom.value))
  }
})

watch(
  () => [form.classroom_id, form.school_year_id] as const,
  async ([classroomId]) => {
    if (!showForm.value) return

    await loadFormClassroomData(classroomId)
    ensureSecondarySubjectSelection()
  },
)

watch(() => form.subject_id, () => syncTeacherFromSubject())
// Recharge créneaux + valeurs par défaut du formulaire quand l'année courante change.
watch(
  () => schoolYearStore.effectiveId,
  async (id) => {
    if (id !== null) {
      form.school_year_id = id
    }
    await loadRefs()
    await loadSlots()
  },
)

onMounted(async () => {
  await loadRefs()
  await loadSlots()
  if (filterClassroom.value !== '' && canManageTimetable.value) {
    await loadFormClassroomData(Number(filterClassroom.value))
  }
})
</script>

<template>
  <section>
    <div class="card">
      <div class="card-header">
        <h1 style="margin: 0">Emploi du temps</h1>
        <button v-if="canManageTimetable" type="button" class="btn-primary" @click="openCreate()">
          + Nouveau créneau
        </button>
      </div>

      <div class="timetable-toolbar">
        <div
          v-if="!isTeacherView"
          class="cycle-tabs"
          role="tablist"
          aria-label="Filtrer l'emploi du temps par cycle"
        >
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

        <div v-if="!isTeacherView" class="classroom-filters-block">
          <p class="classroom-filters-hint">
            Saisissez un nom pour filtrer la liste, puis choisissez la classe dans le menu déroulant.
          </p>

          <div class="classroom-filters">
            <div class="filter-field filter-field-search">
              <label for="timetable-class-search">Filtrer</label>
              <input
                id="timetable-class-search"
                v-model="classroomSearch"
                type="search"
                placeholder="Nom, section, option…"
                aria-label="Filtrer les classes par nom"
                :disabled="visibleClassrooms.length === 0"
              />
            </div>

            <div class="filter-field filter-field-class">
              <label for="timetable-class-select">Classe</label>
              <select
                id="timetable-class-select"
                v-model.number="filterClassroom"
                :disabled="visibleClassrooms.length === 0 || (classroomSearch.trim() !== '' && filteredClassrooms.length === 0)"
                @change="onClassroomPicked"
              >
                <option value="" disabled>
                  {{ classroomSelectPlaceholder }}
                </option>
                <option v-for="c in filteredClassrooms" :key="c.id" :value="c.id">{{ c.full_name }}</option>
              </select>
            </div>
          </div>
        </div>
      </div>

      <p v-if="error" class="alert alert-error" style="margin: 0 1rem 1rem">{{ error }}</p>
      <div v-if="loading" class="empty-state">Chargement…</div>

      <div v-else class="timetable-grid">
        <div v-for="day in DAYS" :key="day.id" class="day-col">
          <div class="day-col-header">
            <h3>{{ day.label }}</h3>
            <button
              v-if="canManageTimetable && filterClassroom !== '' && slotsByDay[day.id].length > 0"
              type="button"
              class="day-add-btn"
              :aria-label="`Ajouter un cours le ${day.label}`"
              :disabled="loading || quickAddSubmitting"
              @click="openQuickAdd(day.id)"
            >
              <Plus aria-hidden="true" />
            </button>
          </div>

          <ul v-if="slotsByDay[day.id].length > 0" class="day-slot-list">
            <li v-for="s in slotsByDay[day.id]" :key="s.id" class="slot">
              <div class="slot-time">{{ s.starts_at }} – {{ s.ends_at }}</div>
              <div class="slot-subject">{{ s.subject?.name }}</div>
              <div class="slot-teacher">
                <span v-if="!isTeacherView && s.teacher?.name">{{ s.teacher.name }}</span>
                <span v-if="s.room">{{ s.room }}</span>
              </div>
              <div v-if="canManageTimetable" class="slot-actions">
                <button type="button" class="btn-mini" @click="openEdit(s)">Éditer</button>
                <button type="button" class="btn-mini btn-danger-mini" @click="remove(s)">×</button>
              </div>
            </li>
          </ul>

          <button
            v-else-if="canManageTimetable && filterClassroom !== ''"
            type="button"
            class="day-empty-add"
            :disabled="loading || quickAddSubmitting"
            @click="openQuickAdd(day.id)"
          >
            <Plus aria-hidden="true" />
            <span>Ajouter un cours</span>
          </button>
          <p v-else class="empty-state-small">—</p>
        </div>
      </div>
    </div>

    <Modal
      v-if="canManageTimetable"
      :open="showForm"
      :title="editing ? 'Modifier le créneau' : 'Nouveau créneau'"
      @close="showForm = false"
    >
      <form id="ts-form" @submit.prevent="submit">
        <div class="field">
          <label>Année scolaire</label>
          <select v-model.number="form.school_year_id" required>
            <option v-for="y in years" :key="y.id" :value="y.id">{{ y.name }}</option>
          </select>
        </div>
        <div class="field">
          <label>Classe</label>
          <select v-model.number="form.classroom_id" required>
            <option v-for="c in visibleClassrooms" :key="c.id" :value="c.id">{{ c.full_name }}</option>
          </select>
        </div>
        <div class="field">
          <label>Cours</label>
          <select v-model.number="form.subject_id" required>
            <option v-if="formSubjectOptions.length === 0" value="" disabled>
              Aucun cours pour cette classe
            </option>
            <option v-for="s in formSubjectOptions" :key="s.id" :value="s.id">
              {{ s.name }}{{ isSecondaryFormClassroom && !subjectHasAssignedTeacher(s.id) ? ' — sans enseignant' : '' }}
            </option>
          </select>
          <small v-if="formSubjectOptions.length === 0" class="err">
            Générez le programme scolaire ou rattachez des cours à cette classe.
          </small>
        </div>
        <div
          v-if="secondarySubjectMissingTeacher"
          class="subject-teacher-alert"
          role="alert"
        >
          <strong>Ce cours n’a pas d’enseignant assigné.</strong>
          <p>
            Créez ou assignez d’abord un professeur à
            « {{ selectedSubjectOption?.name }} » depuis la page
            <RouterLink :to="{ name: 'subjects' }">Cours</RouterLink>
            (menu ⋮ → Assigner un enseignant).
          </p>
        </div>
        <div v-if="isSecondaryFormClassroom" class="field">
          <label>Enseignant</label>
          <p
            class="field-readonly"
            :class="{ 'field-readonly-warn': secondarySubjectMissingTeacher }"
          >
            {{ resolvedFormTeacher?.user?.name ?? 'Non assigné' }}
          </p>
          <small v-if="!secondarySubjectMissingTeacher" class="field-hint">
            Déterminé automatiquement selon l’affectation du cours.
          </small>
        </div>
        <div v-else class="field">
          <label>Enseignant</label>
          <select v-model.number="form.teacher_id" required>
            <option v-for="t in teachers" :key="t.id" :value="t.id">{{ t.user?.name ?? '—' }}</option>
          </select>
        </div>
        <div class="field">
          <label>Jour</label>
          <select v-model.number="form.day_of_week">
            <option v-for="d in DAYS" :key="d.id" :value="d.id">{{ d.label }}</option>
          </select>
        </div>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem">
          <div class="field">
            <label>Début</label>
            <input v-model="form.starts_at" type="time" required />
            <small v-if="formErrors.starts_at" class="err">{{ formErrors.starts_at[0] }}</small>
          </div>
          <div class="field">
            <label>Fin</label>
            <input v-model="form.ends_at" type="time" required />
            <small v-if="formErrors.ends_at" class="err">{{ formErrors.ends_at[0] }}</small>
          </div>
        </div>
        <div class="field">
          <label>Salle (optionnel)</label>
          <input v-model="form.room" type="text" maxlength="64" />
        </div>
        <p v-if="formError" class="alert alert-error">{{ formError }}</p>
      </form>
      <template #footer>
        <button type="button" @click="showForm = false">Annuler</button>
        <button
          type="submit"
          form="ts-form"
          class="btn-primary"
          :disabled="submitting || !canSubmitSlot"
        >
          {{ submitting ? 'Enregistrement…' : editing ? 'Enregistrer' : 'Créer' }}
        </button>
      </template>
    </Modal>

    <Modal
      v-if="canManageTimetable"
      :open="showQuickAdd"
      :title="`Ajouter un cours — ${quickAddDayLabel}`"
      @close="closeQuickAdd"
    >
      <div class="quick-add-shell">
        <p class="quick-add-meta">
          <strong>{{ gridClassroomName }}</strong>
          <span>Créneau de {{ SLOT_DURATION_MINUTES }} min</span>
          <span>{{ quickAddStartTime }} → {{ quickAddEndTime }}</span>
        </p>

        <div v-if="quickAddLoading" class="empty-state compact">Chargement des cours…</div>

        <div v-else-if="classroomSubjects.length === 0" class="quick-add-empty">
          <p>Aucun cours rattaché à cette classe.</p>
          <RouterLink :to="{ name: 'subjects' }">Gérer les cours</RouterLink>
        </div>

        <ul v-else class="course-pick-list" role="listbox" :aria-label="`Cours de ${gridClassroomName}`">
          <li v-for="subject in classroomSubjects" :key="subject.id">
            <button
              type="button"
              class="course-pick-item"
              :class="{
                blocked: isQuickAddBlocked(subject.id),
                adding: quickAddSubmitting,
              }"
              :disabled="quickAddSubmitting || isQuickAddBlocked(subject.id)"
              @click="quickAddSubject(subject)"
            >
              <span class="course-pick-main">
                <strong>{{ subject.name }}</strong>
                <small>{{ teacherLabelForSubject(subject.id) }}</small>
              </span>
              <span v-if="isQuickAddBlocked(subject.id)" class="course-pick-badge warn">
                Sans enseignant
              </span>
              <span v-else class="course-pick-badge">
                + {{ SLOT_DURATION_MINUTES }} min
              </span>
            </button>
          </li>
        </ul>

        <p v-if="quickAddError" class="alert alert-error quick-add-error">{{ quickAddError }}</p>

        <p v-if="isSecondaryGridClassroom" class="quick-add-hint">
          Les cours sans enseignant assigné doivent d’abord être configurés dans
          <RouterLink :to="{ name: 'subjects' }">Cours</RouterLink>.
        </p>
      </div>

      <template #footer>
        <button type="button" @click="closeQuickAdd">Fermer</button>
      </template>
    </Modal>
  </section>
</template>

<style scoped>
.timetable-toolbar {
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
.classroom-filters-block {
  display: grid;
  gap: 0.45rem;
}
.classroom-filters-hint {
  margin: 0;
  color: var(--text-soft);
  font-size: 0.78rem;
  line-height: 1.45;
}
.classroom-filters {
  display: grid;
  grid-template-columns: minmax(11rem, 16rem) minmax(14rem, 22rem);
  gap: 0.65rem 1rem;
  align-items: end;
  width: fit-content;
  max-width: 100%;
}
.filter-field label {
  display: block;
  margin-bottom: 0.35rem;
  color: var(--text-soft);
  font-size: 0.72rem;
  font-weight: 700;
  letter-spacing: 0.02em;
  text-transform: uppercase;
}
.filter-field input,
.filter-field select {
  width: 100%;
  min-height: 2.35rem;
  font-size: 0.88rem;
}
.filter-field-class select {
  min-width: 14rem;
}
.timetable-grid {
  display: grid;
  grid-template-columns: repeat(6, 1fr);
  gap: 0.5rem;
  padding: 0 1rem 1rem;
}
.day-col {
  display: flex;
  flex-direction: column;
  gap: 0.45rem;
  background: var(--bg);
  border: 1px solid var(--border);
  border-radius: 10px;
  padding: 0.55rem;
  min-height: 220px;
}
.day-col-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.35rem;
  padding-bottom: 0.35rem;
  border-bottom: 1px solid var(--border);
}
.day-col h3 {
  margin: 0;
  font-size: 0.86rem;
  font-weight: 850;
  color: var(--text);
}
.day-add-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 1.85rem;
  height: 1.85rem;
  min-height: 1.85rem;
  padding: 0;
  border: 1px solid #c7d7fe;
  border-radius: 999px;
  background: #eef3ff;
  color: var(--primary);
  box-shadow: none;
  cursor: pointer;
  flex-shrink: 0;
  transition: transform 0.12s ease, background 0.12s ease;
}
.day-add-btn:hover:not(:disabled) {
  background: var(--primary);
  color: #fff;
}
.day-add-btn:disabled {
  opacity: 0.55;
  cursor: not-allowed;
}
.day-add-btn svg {
  display: block;
  width: 0.95rem;
  height: 0.95rem;
  flex-shrink: 0;
}
.day-slot-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
  flex: 1;
}
.day-empty-add {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 0.35rem;
  min-height: 6.5rem;
  margin-top: 0.15rem;
  border: 1px dashed #c7d7fe;
  border-radius: 8px;
  background: #f8faff;
  color: var(--primary);
  font-size: 0.78rem;
  font-weight: 750;
  cursor: pointer;
}
.day-empty-add svg {
  width: 1.1rem;
  height: 1.1rem;
}
.day-empty-add:hover:not(:disabled) {
  background: #eef3ff;
}
.slot {
  background: var(--bg-card);
  border: 1px solid var(--border);
  border-left: 3px solid var(--primary);
  border-radius: 4px;
  padding: 0.4rem 0.5rem;
  font-size: 0.78rem;
}
.slot-time { color: var(--text-soft); font-size: 0.72rem; }
.slot-subject { font-weight: 600; }
.slot-teacher {
  display: flex;
  flex-wrap: wrap;
  gap: 0.18rem;
  color: var(--text-soft);
  font-size: 0.72rem;
}
.slot-teacher span + span::before {
  content: '·';
  margin-right: 0.18rem;
}
.slot-actions { display: flex; gap: 0.3rem; margin-top: 0.3rem; }
.btn-mini {
  font-size: 0.72rem;
  padding: 0.15rem 0.4rem;
  border: 1px solid var(--border);
  border-radius: 3px;
  background: transparent;
  cursor: pointer;
}
.btn-danger-mini { color: var(--danger); }
.empty-state-small { color: var(--text-soft); text-align: center; font-size: 0.78rem; margin: 0; }
.empty-state.compact {
  padding: 1.25rem 0.5rem;
  text-align: center;
  color: var(--text-soft);
  font-size: 0.86rem;
}
.quick-add-shell {
  display: grid;
  gap: 0.85rem;
}
.quick-add-meta {
  display: flex;
  flex-wrap: wrap;
  gap: 0.35rem 0.65rem;
  margin: 0;
  padding: 0.7rem 0.8rem;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: var(--bg-subtle);
  color: var(--text-soft);
  font-size: 0.8rem;
}
.quick-add-meta strong {
  color: var(--text);
}
.quick-add-empty {
  display: grid;
  gap: 0.5rem;
  padding: 1rem;
  text-align: center;
  color: var(--text-soft);
  font-size: 0.86rem;
}
.course-pick-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  gap: 0.45rem;
  max-height: min(24rem, 58vh);
  overflow: auto;
}
.course-pick-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  width: 100%;
  padding: 0.75rem 0.85rem;
  border: 1px solid var(--border);
  border-radius: 10px;
  background: #fff;
  text-align: left;
  cursor: pointer;
  transition: border-color 0.12s ease, box-shadow 0.12s ease, transform 0.12s ease;
}
.course-pick-item:hover:not(:disabled) {
  border-color: #c7d7fe;
  box-shadow: 0 8px 20px rgb(37 99 235 / 8%);
  transform: translateY(-1px);
}
.course-pick-item:disabled {
  cursor: not-allowed;
  opacity: 0.72;
}
.course-pick-item.blocked {
  border-style: dashed;
  background: #fffafa;
}
.course-pick-main {
  display: grid;
  gap: 0.12rem;
  min-width: 0;
}
.course-pick-main strong {
  font-size: 0.92rem;
  color: var(--text);
}
.course-pick-main small {
  color: var(--text-soft);
  font-size: 0.76rem;
}
.course-pick-badge {
  flex-shrink: 0;
  padding: 0.2rem 0.5rem;
  border-radius: 999px;
  background: #eef3ff;
  color: var(--primary);
  font-size: 0.68rem;
  font-weight: 800;
  white-space: nowrap;
}
.course-pick-badge.warn {
  background: #fef2f2;
  color: #b42318;
}
.quick-add-error {
  margin: 0;
}
.quick-add-hint {
  margin: 0;
  color: var(--text-soft);
  font-size: 0.76rem;
  line-height: 1.45;
}
.quick-add-hint a {
  color: var(--primary);
  font-weight: 750;
}
.field { display: flex; flex-direction: column; gap: 0.3rem; margin-bottom: 0.5rem; }
.field label { font-size: 0.85rem; color: var(--text-soft); }
.field-readonly {
  margin: 0;
  min-height: 2.45rem;
  display: flex;
  align-items: center;
  padding: 0.55rem 0.7rem;
  border: 1px solid var(--border);
  border-radius: 8px;
  background: var(--bg-subtle);
  color: var(--text);
  font-size: 0.92rem;
  font-weight: 700;
}
.field-hint {
  color: var(--text-soft);
  font-size: 0.76rem;
}
.field-readonly-warn {
  border-color: #fecaca;
  background: #fef2f2;
  color: #b42318;
}
.subject-teacher-alert {
  margin: 0 0 0.65rem;
  padding: 0.75rem 0.85rem;
  border: 1px solid #fecaca;
  border-radius: var(--radius);
  background: #fef2f2;
  color: #7f1d1d;
  font-size: 0.84rem;
}
.subject-teacher-alert strong {
  display: block;
  margin-bottom: 0.25rem;
  color: #b42318;
}
.subject-teacher-alert p {
  margin: 0;
  line-height: 1.45;
}
.subject-teacher-alert a {
  color: var(--primary);
  font-weight: 750;
}
.err { color: var(--danger); font-size: 0.78rem; }
@media (max-width: 1100px) {
  .timetable-grid { grid-template-columns: repeat(3, 1fr); }
}
@media (max-width: 700px) {
  .cycle-tabs {
    align-items: stretch;
  }
  .cycle-tab {
    flex: 1 1 8rem;
  }
  .classroom-filters {
    grid-template-columns: 1fr;
    width: 100%;
  }
  .timetable-grid { grid-template-columns: 1fr; }
}
</style>
