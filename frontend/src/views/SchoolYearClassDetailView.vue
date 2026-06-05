<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { api, ApiError } from '../api/client'
import Modal from '../components/Modal.vue'
import type { ApiResource, Assignment, Paginated, SchoolYearClassDetails, Teacher } from '../types'
import { useConfirmStore } from '../stores/confirm'
import { formatAveragePercent } from '../utils/grades'

const props = defineProps<{ id: string | number; classroomId: string | number }>()
const route = useRoute()
const confirmDialog = useConfirmStore()

type ClassDetailStudent = SchoolYearClassDetails['students'][number]
type ClassDetailParent = SchoolYearClassDetails['parents'][number]
type ClassDetailTimetableSlot = SchoolYearClassDetails['timetable'][number]
type ClassDetailTab = 'people' | 'timetable' | 'courses' | 'attendance' | 'evaluations'

const detailTabs: Array<{ id: ClassDetailTab; label: string }> = [
  { id: 'people', label: 'Élèves / Parents' },
  { id: 'timetable', label: 'Emploi du temps' },
  { id: 'courses', label: 'Cours et enseignants' },
  { id: 'attendance', label: 'Présences récentes' },
  { id: 'evaluations', label: 'Évaluations' },
]

const details = ref<SchoolYearClassDetails | null>(null)
const loading = ref(false)
const error = ref('')
const activeClassTab = ref<ClassDetailTab>('people')
const classSearch = ref('')
const showAssignModal = ref(false)
const assignmentLoading = ref(false)
const assignmentSubmitting = ref(false)
const assignmentError = ref('')
const assignmentErrors = ref<Record<string, string[]>>({})
const principalMenuOpen = ref(false)
const assignmentMode = ref<'create' | 'edit'>('create')
const editingAssignmentId = ref<number | null>(null)
const disassigningPrincipal = ref(false)
const teachers = ref<Teacher[]>([])
const assignmentForm = ref<{ teacher_id: number | '' }>({
  teacher_id: '',
})

const dateFormatter = new Intl.DateTimeFormat('fr-FR', {
  day: '2-digit',
  month: '2-digit',
  year: 'numeric',
})

const dayLabels: Record<number, string> = {
  1: 'Lundi',
  2: 'Mardi',
  3: 'Mercredi',
  4: 'Jeudi',
  5: 'Vendredi',
  6: 'Samedi',
  7: 'Dimanche',
}

const cameFromClasses = computed(() => route.query.from === 'classes')
const backRoute = computed(() =>
  cameFromClasses.value
    ? { name: 'levels' }
    : { name: 'school-year-detail', params: { id: props.id } },
)
const backLabel = computed(() =>
  cameFromClasses.value ? 'Retour aux classes' : "Retour à l'année scolaire",
)
const assignmentModalTitle = computed(() => {
  const classroomName = details.value?.classroom.full_name ?? 'la classe'
  return assignmentMode.value === 'edit'
    ? `Changer le référent de ${classroomName}`
    : `Assigner un référent à ${classroomName}`
})

const tabCounts = computed<Record<ClassDetailTab, number>>(() => ({
  people: (details.value?.students.length ?? 0) + (details.value?.parents.length ?? 0),
  timetable: details.value?.timetable.length ?? 0,
  courses: details.value?.courses.length ?? 0,
  attendance: details.value?.recent_attendances.length ?? 0,
  evaluations: details.value?.evaluations.length ?? details.value?.summary.evaluations ?? 0,
}))

const timetableDayNumbers = computed(() => {
  const hasSundaySlot = details.value?.timetable.some((slot) => slot.day_of_week === 7) ?? false
  return hasSundaySlot ? [1, 2, 3, 4, 5, 6, 7] : [1, 2, 3, 4, 5, 6]
})

const timetableSlotsByDay = computed<Record<number, ClassDetailTimetableSlot[]>>(() => {
  const grouped: Record<number, ClassDetailTimetableSlot[]> = {}
  for (const day of timetableDayNumbers.value) grouped[day] = []

  for (const slot of details.value?.timetable ?? []) {
    if (!grouped[slot.day_of_week]) grouped[slot.day_of_week] = []
    grouped[slot.day_of_week].push(slot)
  }

  for (const day of Object.keys(grouped)) {
    grouped[Number(day)].sort((a, b) => a.starts_at.localeCompare(b.starts_at))
  }

  return grouped
})

const searchPlaceholder = computed(() => {
  switch (activeClassTab.value) {
    case 'people':
      return 'Rechercher un élève, parent, matricule…'
    case 'timetable':
      return 'Rechercher un cours, enseignant, salle…'
    case 'courses':
      return 'Rechercher un cours ou enseignant…'
    case 'attendance':
      return 'Rechercher un élève, cours, statut…'
    case 'evaluations':
      return 'Rechercher une évaluation ou matière…'
    default:
      return 'Rechercher…'
  }
})

const filteredStudents = computed<ClassDetailStudent[]>(() => {
  const students = details.value?.students ?? []
  const term = normalizeSearch(classSearch.value)
  if (!term) return students

  return students.filter((student) =>
    [
      student.full_name,
      student.registration_number,
      studentParentNames(student),
    ].some((value) => normalizeSearch(value).includes(term)),
  )
})

const filteredParents = computed<ClassDetailParent[]>(() => {
  const parents = details.value?.parents ?? []
  const term = normalizeSearch(classSearch.value)
  if (!term) return parents

  return parents.filter((parent) =>
    [
      parent.name,
      parent.phone,
      parent.email,
      parentChildrenLabel(parent),
    ].some((value) => normalizeSearch(value).includes(term)),
  )
})

const filteredCourses = computed(() => {
  const courses = details.value?.courses ?? []
  const term = normalizeSearch(classSearch.value)
  if (!term) return courses

  return courses.filter((course) =>
    [
      course.subject?.name,
      course.teacher?.name,
      course.teacher?.speciality,
    ].some((value) => normalizeSearch(value).includes(term)),
  )
})

const filteredEvaluations = computed(() => {
  const evaluations = details.value?.evaluations ?? []
  const term = normalizeSearch(classSearch.value)
  if (!term) return evaluations

  return evaluations.filter((evaluation) =>
    [
      evaluation.name,
      evaluation.subject?.name,
      evaluation.term?.name,
      evaluationTypeLabel(evaluation.type),
    ].some((value) => normalizeSearch(value).includes(term)),
  )
})

const filteredAttendances = computed(() => {
  const rows = details.value?.recent_attendances ?? []
  const term = normalizeSearch(classSearch.value)
  if (!term) return rows

  return rows.filter((attendance) =>
    [
      attendance.student?.full_name,
      attendance.subject?.name,
      statusLabel(attendance.status),
      attendance.justified ? 'justifie' : 'non justifie',
    ].some((value) => normalizeSearch(value).includes(term)),
  )
})

const filteredTimetable = computed(() => {
  const slots = details.value?.timetable ?? []
  const term = normalizeSearch(classSearch.value)
  if (!term) return slots

  return slots.filter((slot) =>
    [
      slot.subject?.name,
      slot.teacher?.name,
      slot.room,
      dayLabel(slot.day_of_week),
      slot.starts_at,
      slot.ends_at,
    ].some((value) => normalizeSearch(value).includes(term)),
  )
})

const filteredTimetableSlotsByDay = computed<Record<number, ClassDetailTimetableSlot[]>>(() => {
  const grouped: Record<number, ClassDetailTimetableSlot[]> = {}
  for (const day of timetableDayNumbers.value) grouped[day] = []

  for (const slot of filteredTimetable.value) {
    if (!grouped[slot.day_of_week]) grouped[slot.day_of_week] = []
    grouped[slot.day_of_week].push(slot)
  }

  for (const day of Object.keys(grouped)) {
    grouped[Number(day)].sort((a, b) => a.starts_at.localeCompare(b.starts_at))
  }

  return grouped
})

function normalizeSearch(value: string | null | undefined): string {
  return (value ?? '')
    .toString()
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
    .trim()
}

function formatDate(value: string): string {
  const date = new Date(`${value}T00:00:00`)
  if (Number.isNaN(date.getTime())) return value
  return dateFormatter.format(date)
}

function dateLabel(value: string | null | undefined): string {
  return value ? formatDate(value) : '-'
}

function averageLabel(value: number | null | undefined): string {
  return formatAveragePercent(value, 1)
}

function dayLabel(day: number): string {
  return dayLabels[day] ?? `Jour ${day}`
}

function statusLabel(status: string): string {
  if (status === 'present') return 'Présent'
  if (status === 'absent') return 'Absent'
  if (status === 'late') return 'Retard'
  return status
}

function evaluationTypeLabel(type: string | null | undefined): string {
  if (type === 'devoir') return 'Devoir'
  if (type === 'controle') return 'Contrôle'
  if (type === 'examen') return 'Examen'
  if (type === 'oral') return 'Oral'
  if (type === 'projet') return 'Projet'
  return type ?? '-'
}

function studentParentNames(student: ClassDetailStudent): string {
  const names = student.parents.map((parent) => parent.name ?? 'Parent').filter(Boolean)
  return names.length ? names.join(', ') : '-'
}

function parentChildrenLabel(parent: ClassDetailParent): string {
  return parent.children
    .map((child) => `${child.full_name}${child.relation ? ` (${child.relation})` : ''}`)
    .join(', ')
}

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    const res = await api<ApiResource<SchoolYearClassDetails>>(
      `/api/v1/school-years/${props.id}/classrooms/${props.classroomId}/details`,
    )
    details.value = res.data
  } catch (err) {
    error.value = err instanceof ApiError ? err.message : 'Détails de la classe indisponibles.'
  } finally {
    loading.value = false
  }
}

async function loadAssignmentOptions(): Promise<void> {
  assignmentLoading.value = true
  assignmentError.value = ''
  try {
    const teachersRes = await api<Paginated<Teacher>>('/api/v1/teachers')
    teachers.value = teachersRes.data
  } catch (err) {
    assignmentError.value = err instanceof ApiError ? err.message : 'Options d’affectation indisponibles.'
  } finally {
    assignmentLoading.value = false
  }
}

function resetAssignmentForm(): void {
  assignmentForm.value = { teacher_id: '' }
  assignmentError.value = ''
  assignmentErrors.value = {}
  assignmentMode.value = 'create'
  editingAssignmentId.value = null
}

function togglePrincipalMenu(): void {
  if (!details.value?.main_teacher) return
  principalMenuOpen.value = !principalMenuOpen.value
}

function closePrincipalMenu(): void {
  principalMenuOpen.value = false
}

async function openAssignPrincipal(): Promise<void> {
  resetAssignmentForm()
  showAssignModal.value = true
  if (teachers.value.length === 0) {
    await loadAssignmentOptions()
  }
}

async function openChangePrincipal(): Promise<void> {
  const mainTeacher = details.value?.main_teacher
  if (!mainTeacher) return

  closePrincipalMenu()
  resetAssignmentForm()
  assignmentMode.value = 'edit'
  editingAssignmentId.value = mainTeacher.assignment_id
  assignmentForm.value = {
    teacher_id: mainTeacher.id,
  }
  showAssignModal.value = true
  if (teachers.value.length === 0) {
    await loadAssignmentOptions()
  }
}

async function submitPrincipalAssignment(): Promise<void> {
  if (!details.value || assignmentForm.value.teacher_id === '') return

  assignmentSubmitting.value = true
  assignmentError.value = ''
  assignmentErrors.value = {}
  try {
    const path = editingAssignmentId.value
      ? `/api/v1/assignments/${editingAssignmentId.value}`
      : '/api/v1/assignments'
    await api<ApiResource<Assignment>>(path, {
      method: editingAssignmentId.value ? 'PATCH' : 'POST',
      body: {
        teacher_id: Number(assignmentForm.value.teacher_id),
        subject_id: null,
        classroom_id: details.value.classroom.id,
        school_year_id: details.value.school_year.id,
        is_main: true,
      },
    })
    showAssignModal.value = false
    resetAssignmentForm()
    await load()
  } catch (err) {
    if (err instanceof ApiError) {
      assignmentError.value = err.message
      assignmentErrors.value = err.errors ?? {}
    } else {
      assignmentError.value = 'Affectation impossible.'
    }
  } finally {
    assignmentSubmitting.value = false
  }
}

async function disassignPrincipal(): Promise<void> {
  const mainTeacher = details.value?.main_teacher
  if (!details.value || !mainTeacher || disassigningPrincipal.value) return

  closePrincipalMenu()
  const name = mainTeacher.name ?? 'ce référent'
  const ok = await confirmDialog.ask({
    title: 'Désassigner le référent',
    message: 'Cet enseignant ne sera plus référent de la classe.',
    details: [
      `${name} - ${details.value.classroom.full_name}`,
    ],
    confirmLabel: 'Désassigner',
    variant: 'warning',
  })
  if (!ok) return

  disassigningPrincipal.value = true
  error.value = ''
  try {
    if (mainTeacher.subject_id) {
      await api<ApiResource<Assignment>>(`/api/v1/assignments/${mainTeacher.assignment_id}`, {
        method: 'PATCH',
        body: {
          teacher_id: mainTeacher.id,
          subject_id: mainTeacher.subject_id,
          classroom_id: details.value.classroom.id,
          school_year_id: details.value.school_year.id,
          is_main: false,
        },
      })
    } else {
      await api(`/api/v1/assignments/${mainTeacher.assignment_id}`, { method: 'DELETE' })
    }
    await load()
  } catch (err) {
    error.value = err instanceof ApiError ? err.message : 'Désassignation impossible.'
  } finally {
    disassigningPrincipal.value = false
  }
}

onMounted(load)
</script>

<template>
  <section class="class-detail-page">
    <RouterLink class="back-link" :to="backRoute">{{ backLabel }}</RouterLink>

    <p v-if="error" class="alert alert-error">{{ error }}</p>

    <div v-if="loading && !details" class="detail-skeleton">
      <div class="skeleton-block hero" />
      <div class="skeleton-grid">
        <div v-for="i in 4" :key="i" class="skeleton-block" />
      </div>
    </div>

    <template v-else-if="details">
      <div class="class-detail-hero">
        <div>
          <p class="eyebrow">Classe</p>
          <h1>{{ details.classroom.full_name }}</h1>
          <p>
            {{ details.classroom.level?.name ?? 'Niveau non défini' }} ·
            {{ details.summary.students }} élève(s) affecté(s) · {{ details.school_year.name }}
          </p>
        </div>
        <div
          class="main-teacher-card"
          :class="{ 'has-main-teacher': details.main_teacher, 'menu-open': principalMenuOpen }"
          @keydown.esc="closePrincipalMenu"
        >
          <button
            v-if="details.main_teacher"
            type="button"
            class="main-teacher-trigger"
            :aria-expanded="principalMenuOpen"
            aria-haspopup="menu"
            @click="togglePrincipalMenu"
          >
            <span>Prof principal / référent</span>
            <strong>{{ details.main_teacher.name ?? 'Non défini' }}</strong>
            <small>
              {{ details.main_teacher.subject ?? details.main_teacher.speciality ?? 'Référent de classe' }}
            </small>
            <span class="principal-menu-hint" aria-hidden="true">Actions</span>
          </button>
          <template v-else>
            <span>Prof principal / référent</span>
            <strong class="muted">Non défini</strong>
            <button
              type="button"
              class="assign-main-teacher-button"
              @click="openAssignPrincipal"
            >
              Assigner
            </button>
          </template>
          <div v-if="details.main_teacher && principalMenuOpen" class="principal-action-menu" @click.stop>
            <button type="button" @click="openChangePrincipal">Changer de principal</button>
            <button type="button" class="danger-action" :disabled="disassigningPrincipal" @click="disassignPrincipal">
              {{ disassigningPrincipal ? 'Désassignation…' : 'Désassigner' }}
            </button>
          </div>
        </div>
      </div>

      <div class="class-detail-kpis">
        <span><strong>{{ details.summary.students }}</strong> Élèves</span>
        <span><strong>{{ details.summary.parents }}</strong> Parents</span>
        <span><strong>{{ details.summary.teachers }}</strong> Enseignants</span>
        <span><strong>{{ details.summary.subjects }}</strong> Cours</span>
        <span><strong>{{ averageLabel(details.summary.grade_average) }}</strong> Moyenne</span>
        <span><strong>{{ details.summary.absences }}</strong> Absences</span>
        <span><strong>{{ details.summary.lates }}</strong> Retards</span>
      </div>

      <nav class="class-section-tabs" aria-label="Navigation du détail classe">
        <button
          v-for="tab in detailTabs"
          :key="tab.id"
          type="button"
          class="class-section-tab"
          :class="{ active: activeClassTab === tab.id }"
          :aria-current="activeClassTab === tab.id ? 'page' : undefined"
          @click="activeClassTab = tab.id"
        >
          <span>{{ tab.label }}</span>
          <strong>{{ tabCounts[tab.id] }}</strong>
        </button>
      </nav>

      <div class="class-search-bar">
        <label class="sr-only" for="class-search">Rechercher dans cette classe</label>
        <input
          id="class-search"
          v-model="classSearch"
          type="search"
          :placeholder="searchPlaceholder"
          autocomplete="off"
        />
      </div>

      <div class="class-tab-panel">
        <div v-show="activeClassTab === 'people'" class="class-detail-grid">
        <section class="detail-panel">
          <div class="detail-panel-header">
            <h2>Liste des élèves</h2>
            <span>{{ filteredStudents.length }} / {{ details.students.length }} élève(s)</span>
          </div>
          <div v-if="details.students.length === 0" class="empty-state compact">
            Aucun élève dans cette classe.
          </div>
          <template v-else>
            <div v-if="filteredStudents.length === 0" class="empty-state compact">
              Aucun élève ne correspond à cette recherche.
            </div>
          </template>
          <div v-if="details.students.length > 0 && filteredStudents.length > 0" class="mini-table scrollable-list">
            <table>
              <thead>
                <tr>
                  <th>Élève</th>
                  <th>Parents</th>
                  <th>Présences</th>
                  <th>Absences</th>
                  <th>Retards</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="student in filteredStudents" :key="student.id">
                  <td>
                    <strong>{{ student.full_name }}</strong>
                    <small>{{ student.registration_number ?? 'Sans matricule' }}</small>
                  </td>
                  <td>{{ studentParentNames(student) }}</td>
                  <td>{{ student.attendance.present }}</td>
                  <td>
                    {{ student.attendance.absences }}
                    <small v-if="student.attendance.unjustified_absences">
                      dont {{ student.attendance.unjustified_absences }} non justifiée(s)
                    </small>
                  </td>
                  <td>{{ student.attendance.lates }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

        <section class="detail-panel">
          <div class="detail-panel-header">
            <h2>Parents liés</h2>
            <span>{{ filteredParents.length }} / {{ details.parents.length }} parent(s)</span>
          </div>
          <div v-if="details.parents.length === 0" class="empty-state compact">
            Aucun parent lié aux élèves de cette classe.
          </div>
          <template v-else>
            <div v-if="filteredParents.length === 0" class="empty-state compact">
              Aucun parent ne correspond à cette recherche.
            </div>
          </template>
          <div v-if="details.parents.length > 0 && filteredParents.length > 0" class="mini-table scrollable-list">
            <table>
              <thead>
                <tr>
                  <th>Parent</th>
                  <th>Contact</th>
                  <th>Élève(s)</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="parent in filteredParents" :key="parent.id">
                  <td><strong>{{ parent.name ?? 'Parent' }}</strong></td>
                  <td>
                    {{ parent.phone ?? '-' }}
                    <small>{{ parent.email ?? '' }}</small>
                  </td>
                  <td>{{ parentChildrenLabel(parent) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>
        </div>

        <section v-show="activeClassTab === 'timetable'" class="detail-panel detail-panel-wide">
          <div class="detail-panel-header">
            <h2>Emploi du temps</h2>
            <span>{{ details.timetable.length }} créneau(x)</span>
          </div>
          <div v-if="details.timetable.length === 0" class="empty-state compact">
            Aucun créneau défini.
          </div>
          <div v-else-if="filteredTimetable.length === 0" class="empty-state compact">
            Aucun créneau ne correspond à cette recherche.
          </div>
          <div v-else class="weekly-timetable">
            <article v-for="day in timetableDayNumbers" :key="day" class="timetable-day">
              <h3>{{ dayLabel(day) }}</h3>
              <div v-if="(filteredTimetableSlotsByDay[day] ?? []).length === 0" class="timetable-day-empty">
                —
              </div>
              <div v-else class="timetable-slot-list">
                <div v-for="slot in filteredTimetableSlotsByDay[day]" :key="slot.id" class="timetable-slot-card">
                  <span class="timetable-time">{{ slot.starts_at }} – {{ slot.ends_at }}</span>
                  <strong>{{ slot.subject?.name ?? 'Cours non défini' }}</strong>
                  <small>{{ slot.teacher?.name ?? 'Enseignant non affecté' }}</small>
                  <span v-if="slot.room" class="timetable-room">{{ slot.room }}</span>
                </div>
              </div>
            </article>
          </div>
        </section>

        <section v-show="activeClassTab === 'courses'" class="detail-panel detail-panel-wide">
          <div class="detail-panel-header">
            <h2>Cours et enseignants</h2>
            <span>{{ details.courses.length }} cours</span>
          </div>
          <div v-if="details.courses.length === 0" class="empty-state compact">
            Aucun cours affecté pour cette année.
          </div>
          <div v-else-if="filteredCourses.length === 0" class="empty-state compact">
            Aucun cours ne correspond à cette recherche.
          </div>
          <div v-else class="course-list">
            <div v-for="course in filteredCourses" :key="course.id" class="course-row">
              <div>
                <strong>{{ course.subject?.name ?? 'Cours non défini' }}</strong>
                <span>{{ course.teacher?.speciality ?? 'Spécialité non renseignée' }}</span>
              </div>
              <span>{{ course.teacher?.name ?? 'Enseignant non affecté' }}</span>
            </div>
          </div>
        </section>

        <section v-show="activeClassTab === 'attendance'" class="detail-panel detail-panel-wide">
          <div class="detail-panel-header">
            <h2>Registre des présences récent</h2>
            <span>{{ details.summary.attendance_records }} entrée(s) sur l'année</span>
          </div>
          <div class="attendance-summary">
            <span>Présents : <strong>{{ details.summary.present }}</strong></span>
            <span>Absences justifiées : <strong>{{ details.summary.justified_absences }}</strong></span>
            <span>Absences non justifiées : <strong>{{ details.summary.unjustified_absences }}</strong></span>
            <span>Retards : <strong>{{ details.summary.lates }}</strong></span>
          </div>
          <div v-if="details.recent_attendances.length === 0" class="empty-state compact">
            Aucun enregistrement de présence.
          </div>
          <div v-else-if="filteredAttendances.length === 0" class="empty-state compact">
            Aucune présence ne correspond à cette recherche.
          </div>
          <div v-else class="mini-table">
            <table>
              <thead>
                <tr>
                  <th>Date</th>
                  <th>Élève</th>
                  <th>Cours</th>
                  <th>Statut</th>
                  <th>Justification</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="attendance in filteredAttendances" :key="attendance.id">
                  <td>{{ dateLabel(attendance.date) }}</td>
                  <td>{{ attendance.student?.full_name ?? '-' }}</td>
                  <td>{{ attendance.subject?.name ?? '-' }}</td>
                  <td>
                    <span class="status-pill" :class="`status-${attendance.status}`">
                      {{ statusLabel(attendance.status) }}
                    </span>
                  </td>
                  <td>
                    {{ attendance.status === 'absent' ? (attendance.justified ? 'Justifiée' : 'Non justifiée') : '-' }}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

        <section v-show="activeClassTab === 'evaluations'" class="detail-panel detail-panel-wide">
          <div class="detail-panel-header">
            <h2>Évaluations</h2>
            <span>{{ details.summary.evaluations }} évaluation(s)</span>
          </div>
          <div class="attendance-summary">
            <span>Notes saisies : <strong>{{ details.summary.grades_entered }}</strong></span>
            <span>Moyenne : <strong>{{ averageLabel(details.summary.grade_average) }}</strong></span>
          </div>
          <div v-if="details.evaluations.length === 0" class="empty-state compact">
            Aucune évaluation enregistrée pour cette classe.
          </div>
          <div v-else-if="filteredEvaluations.length === 0" class="empty-state compact">
            Aucune évaluation ne correspond à cette recherche.
          </div>
          <div v-else class="mini-table">
            <table>
              <thead>
                <tr>
                  <th>Évaluation</th>
                  <th>Cours</th>
                  <th>Trimestre</th>
                  <th>Date</th>
                  <th>Barème</th>
                  <th>Notes</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="evaluation in filteredEvaluations" :key="evaluation.id">
                  <td>
                    <strong>{{ evaluation.name }}</strong>
                    <small>{{ evaluationTypeLabel(evaluation.type) }}</small>
                  </td>
                  <td>{{ evaluation.subject?.name ?? '-' }}</td>
                  <td>{{ evaluation.term?.name ?? '-' }}</td>
                  <td>{{ dateLabel(evaluation.held_on) }}</td>
                  <td>{{ evaluation.max_value }}</td>
                  <td>{{ evaluation.grades_count }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>
      </div>

      <Modal
        :open="showAssignModal"
        :title="assignmentModalTitle"
        @close="showAssignModal = false"
      >
        <form id="principal-assignment-form" class="assignment-form" @submit.prevent="submitPrincipalAssignment">
          <p v-if="assignmentError" class="alert alert-error">{{ assignmentError }}</p>
          <div v-if="assignmentLoading" class="empty-state compact">Chargement…</div>
          <template v-else>
            <div class="field">
              <label for="principal-teacher">Enseignant</label>
              <select
                id="principal-teacher"
                v-model="assignmentForm.teacher_id"
                required
                :disabled="teachers.length === 0"
              >
                <option value="" disabled>— Sélectionner —</option>
                <option v-for="teacher in teachers" :key="teacher.id" :value="teacher.id">
                  {{ teacher.user?.name ?? `Enseignant #${teacher.id}` }}
                  <template v-if="teacher.speciality"> · {{ teacher.speciality }}</template>
                </option>
              </select>
              <small v-if="teachers.length === 0" class="form-hint">Aucun profil enseignant disponible.</small>
              <small v-if="assignmentErrors.teacher_id" class="err">{{ assignmentErrors.teacher_id[0] }}</small>
            </div>

          </template>
        </form>
        <template #footer>
          <button type="button" @click="showAssignModal = false">Annuler</button>
          <button
            type="submit"
            form="principal-assignment-form"
            class="btn-primary"
            :disabled="
              assignmentLoading ||
              assignmentSubmitting ||
              assignmentForm.teacher_id === ''
            "
          >
            {{ assignmentSubmitting ? 'Affectation…' : assignmentMode === 'edit' ? 'Enregistrer' : 'Assigner' }}
          </button>
        </template>
      </Modal>
    </template>
  </section>
</template>

<style scoped>
.class-detail-page {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.back-link {
  width: fit-content;
  font-weight: 750;
}

.class-detail-hero {
  display: flex;
  align-items: stretch;
  justify-content: space-between;
  gap: 1rem;
  padding: 1.15rem;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: linear-gradient(135deg, #f8faff, #ffffff);
  box-shadow: var(--shadow-card);
}

.class-detail-hero h1 {
  margin: 0;
}

.class-detail-hero p {
  margin: 0.25rem 0 0;
  color: var(--text-soft);
  font-size: 0.9rem;
}

.eyebrow {
  margin: 0 0 0.12rem;
  color: var(--text-soft);
  font-size: 0.73rem;
  font-weight: 800;
  letter-spacing: 0.08em;
  text-transform: uppercase;
}

.main-teacher-card {
  min-width: 16rem;
  position: relative;
  display: grid;
  align-content: center;
  gap: 0.25rem;
  padding: 0.85rem;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: var(--bg-card);
}

.main-teacher-card.has-main-teacher {
  min-width: 17rem;
  padding: 0;
}

.main-teacher-card.menu-open {
  border-color: rgba(37, 99, 235, 0.32);
}

.main-teacher-trigger {
  width: 100%;
  display: grid;
  gap: 0.25rem;
  padding: 0.85rem;
  border: 0;
  border-radius: var(--radius);
  background: transparent;
  color: inherit;
  text-align: left;
}

.main-teacher-trigger:hover {
  background: #f8faff;
}

.main-teacher-trigger:focus-visible {
  outline: 3px solid rgba(37, 99, 235, 0.18);
  outline-offset: 2px;
}

.main-teacher-card span {
  color: var(--text-soft);
  font-size: 0.72rem;
  font-weight: 850;
  letter-spacing: 0.05em;
  text-transform: uppercase;
}

.main-teacher-card strong {
  color: var(--text);
  font-size: 1rem;
}

.main-teacher-card strong.muted {
  color: var(--text-soft);
}

.main-teacher-card small {
  color: var(--text-muted);
  font-weight: 700;
}

.principal-menu-hint {
  width: fit-content;
  margin-top: 0.15rem;
  padding: 0.16rem 0.45rem;
  border-radius: 999px;
  background: var(--primary-tint);
  color: var(--primary) !important;
  font-size: 0.68rem !important;
  letter-spacing: 0 !important;
  text-transform: none !important;
}

.principal-action-menu {
  width: 13.5rem;
  position: absolute;
  top: calc(100% + 0.42rem);
  right: 0;
  z-index: 20;
  display: grid;
  gap: 0.18rem;
  padding: 0.35rem;
  border: 1px solid var(--border);
  border-radius: 0.55rem;
  background: var(--bg-card);
  box-shadow: 0 18px 36px rgba(15, 23, 42, 0.16);
}

.principal-action-menu button {
  width: 100%;
  margin: 0;
  padding: 0.56rem 0.65rem;
  border: 0;
  border-radius: 0.38rem;
  background: transparent;
  color: var(--text);
  font-size: 0.82rem;
  font-weight: 800;
  text-align: left;
}

.principal-action-menu button:hover {
  background: #f8fafc;
}

.principal-action-menu .danger-action {
  color: var(--danger);
}

.principal-action-menu .danger-action:hover {
  background: #fff1f2;
}

.assign-main-teacher-button {
  width: fit-content;
  margin-top: 0.28rem;
  padding: 0.46rem 0.72rem;
  border-color: rgba(37, 99, 235, 0.24);
  background: var(--primary-tint);
  color: var(--primary);
  font-size: 0.78rem;
  font-weight: 850;
}

.assign-main-teacher-button:hover {
  border-color: rgba(37, 99, 235, 0.42);
  background: #eaf0ff;
}

.assignment-form {
  display: grid;
  gap: 0.9rem;
}

.form-hint,
.err {
  display: block;
  margin-top: 0.28rem;
  font-size: 0.78rem;
}

.form-hint {
  color: var(--text-soft);
  font-weight: 700;
}

.err {
  color: var(--danger);
}

.class-detail-kpis {
  display: grid;
  grid-template-columns: repeat(7, minmax(0, 1fr));
  gap: 0.55rem;
}

.class-detail-kpis span {
  min-width: 0;
  display: grid;
  gap: 0.2rem;
  padding: 0.7rem;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: var(--bg-subtle);
  color: var(--text-soft);
  font-size: 0.72rem;
  font-weight: 800;
}

.class-detail-kpis strong {
  color: var(--text);
  font-size: 1rem;
}

.class-section-tabs {
  display: flex;
  flex-wrap: wrap;
  gap: 0.45rem;
  padding: 0.38rem;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: var(--bg-card);
  box-shadow: var(--shadow-card);
}

.class-section-tab {
  min-height: 2.55rem;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.45rem;
  padding: 0.5rem 0.75rem;
  border: 1px solid transparent;
  border-radius: 0.65rem;
  background: transparent;
  color: var(--text-soft);
  font-size: 0.82rem;
  font-weight: 850;
}

.class-section-tab:hover {
  border-color: var(--border);
  background: #f8fafc;
  color: var(--text);
}

.class-section-tab.active {
  border-color: rgba(37, 99, 235, 0.22);
  background: var(--primary);
  color: #fff;
  box-shadow: 0 10px 22px rgba(37, 99, 235, 0.16);
}

.class-section-tab strong {
  min-width: 1.35rem;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 0.12rem 0.4rem;
  border-radius: 999px;
  background: #eef2f7;
  color: var(--text-soft);
  font-size: 0.72rem;
}

.class-section-tab.active strong {
  background: rgba(255, 255, 255, 0.22);
  color: #fff;
}

.class-tab-panel {
  display: grid;
  gap: 0.85rem;
}

.class-detail-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.85rem;
}

.detail-panel {
  min-width: 0;
  overflow: hidden;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: var(--bg-card);
}

.detail-panel-wide {
  grid-column: 1 / -1;
}

.detail-panel-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 0.8rem 0.95rem;
  border-bottom: 1px solid var(--border);
  background: #f8fafc;
}

.detail-panel-header h2 {
  margin: 0;
  font-size: 0.98rem;
}

.detail-panel-header span {
  color: var(--text-soft);
  font-size: 0.76rem;
  font-weight: 800;
  white-space: nowrap;
}

.class-search-bar {
  margin: 0 0 1rem;
}

.class-search-bar input {
  width: 100%;
  min-height: 2.5rem;
  padding: 0.55rem 0.85rem;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  font-size: 0.92rem;
  background: var(--bg-card);
}

.class-search-bar input:focus-visible {
  outline: 2px solid var(--primary);
  outline-offset: 1px;
  border-color: var(--primary);
}

.list-tools {
  padding: 0.75rem 0.95rem;
  border-bottom: 1px solid var(--border);
}

.list-tools input {
  width: 100%;
  min-height: 2.35rem;
}

.sr-only {
  width: 1px;
  height: 1px;
  position: absolute;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
}

.mini-table {
  overflow-x: auto;
}

.mini-table.scrollable-list {
  max-height: 34rem;
  overflow: auto;
}

.mini-table.scrollable-list thead th {
  position: sticky;
  top: 0;
  z-index: 2;
}

.mini-table table {
  min-width: 36rem;
}

.mini-table td strong,
.mini-table td small {
  display: block;
}

.mini-table td small {
  margin-top: 0.16rem;
  color: var(--text-muted);
  font-size: 0.72rem;
}

.weekly-timetable {
  display: grid;
  grid-template-columns: repeat(6, minmax(11rem, 1fr));
  gap: 0.5rem;
  padding: 0.55rem;
  overflow-x: auto;
}

.timetable-day {
  min-width: 11rem;
  min-height: 13.5rem;
  display: flex;
  flex-direction: column;
  gap: 0.55rem;
  padding: 0.55rem;
  border: 1px solid #dbe3f1;
  border-radius: 0.45rem;
  background: #f5f7fb;
}

.timetable-day h3 {
  margin: 0;
  color: #64708a;
  font-size: 0.84rem;
  font-weight: 850;
  text-align: center;
}

.timetable-day-empty {
  display: grid;
  flex: 1;
  place-items: start center;
  padding-top: 0.55rem;
  color: #8a96aa;
  font-size: 1rem;
}

.timetable-slot-list {
  display: grid;
  gap: 0.45rem;
}

.timetable-slot-card {
  min-height: 6rem;
  display: grid;
  align-content: start;
  gap: 0.18rem;
  padding: 0.55rem 0.55rem 0.55rem 0.7rem;
  border: 1px solid #d9e1ee;
  border-left: 0.18rem solid var(--primary);
  border-radius: 0.35rem;
  background: #fff;
}

.timetable-time {
  color: #63708a;
  font-size: 0.73rem;
  font-weight: 750;
}

.timetable-slot-card strong {
  color: var(--text);
  font-size: 0.82rem;
  line-height: 1.25;
}

.timetable-slot-card small {
  color: var(--text-muted);
  font-size: 0.72rem;
  font-weight: 700;
}

.timetable-room {
  width: fit-content;
  margin-top: 0.18rem;
  padding: 0.14rem 0.4rem;
  border-radius: 999px;
  background: #eef2f7;
  color: #64708a;
  font-size: 0.68rem;
  font-weight: 850;
}

.empty-state.compact {
  padding: 1rem;
  font-size: 0.86rem;
}

.course-list {
  display: grid;
}

.course-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 0.78rem 0.95rem;
  border-bottom: 1px solid var(--border);
}

.course-row:last-child {
  border-bottom: 0;
}

.course-row div,
.course-row strong,
.course-row div span {
  min-width: 0;
  display: block;
}

.course-row div span {
  margin-top: 0.12rem;
  color: var(--text-muted);
  font-size: 0.74rem;
  font-weight: 700;
}

.course-row > span {
  color: var(--text-soft);
  font-size: 0.82rem;
  font-weight: 800;
  text-align: right;
}

.attendance-summary {
  display: flex;
  flex-wrap: wrap;
  gap: 0.45rem;
  padding: 0.8rem 0.95rem;
  border-bottom: 1px solid var(--border);
}

.attendance-summary span,
.status-pill {
  display: inline-flex;
  align-items: center;
  gap: 0.25rem;
  padding: 0.22rem 0.5rem;
  border-radius: 999px;
  background: #f2f4f7;
  color: var(--text-soft);
  font-size: 0.74rem;
  font-weight: 800;
}

.status-present {
  background: var(--success-soft);
  color: var(--success);
}

.status-absent {
  background: var(--warn-soft);
  color: var(--warn);
}

.status-late {
  background: #edf2ff;
  color: var(--primary);
}

.detail-skeleton,
.skeleton-grid {
  display: grid;
  gap: 0.85rem;
}

.skeleton-grid {
  grid-template-columns: repeat(4, minmax(0, 1fr));
}

.skeleton-block {
  min-height: 6rem;
  border-radius: var(--radius);
  background: linear-gradient(90deg, #f2f4f7, #f8faff, #f2f4f7);
  background-size: 180% 100%;
  animation: shimmer 1.2s infinite linear;
}

.skeleton-block.hero {
  min-height: 8rem;
}

@keyframes shimmer {
  from {
    background-position: 120% 0;
  }

  to {
    background-position: -120% 0;
  }
}

@media (max-width: 1160px) {
  .class-detail-kpis {
    grid-template-columns: repeat(4, minmax(0, 1fr));
  }
}

@media (max-width: 720px) {
  .class-detail-hero {
    flex-direction: column;
  }

  .class-detail-grid {
    grid-template-columns: 1fr;
  }

  .class-detail-kpis,
  .skeleton-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .weekly-timetable {
    grid-template-columns: repeat(6, minmax(10.5rem, 1fr));
  }

  .class-section-tab {
    flex: 1 1 100%;
    justify-content: space-between;
  }
}
</style>
