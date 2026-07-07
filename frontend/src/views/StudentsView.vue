<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { api, apiUrl, ApiError, getToken } from '../api/client'
import type {
  ApiResource,
  ClassRoom,
  Level,
  Paginated,
  SchoolYear,
  Student,
  StudentPortalStatus,
} from '../types'
import Modal from '../components/Modal.vue'
import RowActionMenu from '../components/RowActionMenu.vue'
import DataTable, { type Column } from '../components/DataTable.vue'
import { useSchoolYearStore } from '../stores/schoolYear'
import { useToastStore } from '../stores/toast'
import { useCycleTabs, type CycleFilter } from '../composables/useCycleTabs'

const schoolYearStore = useSchoolYearStore()
const toast = useToastStore()
const router = useRouter()
const { cycleTabs } = useCycleTabs()

type StudentStatus = 'actif' | 'redoublant' | 'transfere' | 'inactif'
type SummaryItem = { label: string; value: string }
type SummarySection = { title: string; items: SummaryItem[] }
type PortalCredential = {
  role: 'eleve' | 'parent'
  name: string
  login: string
  login_type: 'matricule' | 'email' | 'telephone'
  password: string | null
  generated: boolean
}
type StudentSaveResponse = ApiResource<Student> & {
  meta?: {
    portal_credentials?: PortalCredential[]
  }
}

const STATUS_OPTIONS: Array<{ value: StudentStatus; label: string }> = [
  { value: 'actif', label: 'Actif' },
  { value: 'redoublant', label: 'Redoublant' },
  { value: 'transfere', label: 'Transféré' },
  { value: 'inactif', label: 'Inactif' },
]

const items = ref<Student[]>([])
const levels = ref<Level[]>([])
const loading = ref(false)
const error = ref('')

const columns: Column<Student>[] = [
  { key: 'name', label: 'Nom' },
  { key: 'classroom', label: 'Classe' },
  { key: 'status', label: 'Statut' },
  { key: 'dob', label: 'Naissance' },
  { key: 'gender', label: 'Sexe' },
  { key: 'registration_number', label: 'Matricule' },
  { key: 'actions', label: 'Actions', width: '1%', align: 'right' }
]

const activeCycle = ref<CycleFilter>('all')
const filterClassroom = ref<number | ''>('')
const search = ref('')
const selectedStudentIds = ref<number[]>([])
const bulkDeleting = ref(false)
const showDeleteConfirm = ref(false)
const deleteCandidateIds = ref<number[]>([])
const deleteSubmitting = ref(false)

const showForm = ref(false)
const editing = ref<Student | null>(null)
const showRegistrationSummary = ref(false)
const registrationSummaryStudent = ref<Student | null>(null)
const registrationPortalCredentials = ref<PortalCredential[]>([])
const returnToSummaryAfterEdit = ref(false)
const formError = ref('')
const formErrors = reactive<Record<string, string[]>>({})
const submitting = ref(false)

// Import CSV
const showImport = ref(false)
const importFile = ref<File | null>(null)
const importing = ref(false)
const importResult = ref<{
  message: string
  created: number
  updated?: number
  skipped: number
  errors: { line: number; errors: string[] }[]
  credentials: { email: string; password: string }[]
  warnings: { line: number; warning: string }[]
} | null>(null)
const importError = ref('')

const form = reactive({
  first_name: '',
  last_name: '',
  middle_name: '',
  classroom_id: null as number | null,
  enrollment_school_year_id: null as number | null,
  date_of_birth: '',
  place_of_birth: '',
  gender: '' as 'F' | 'M' | '',
  nationality: 'Congolaise',
  photo_path: '',
  enrollment_status: 'actif' as StudentStatus,
  order_number: '',
  enrolled_on: new Date().toISOString().slice(0, 10),
  previous_school: '',
  father_name: '',
  mother_name: '',
  legal_guardian_name: '',
  guardian_relationship: '',
  primary_phone: '',
  secondary_phone: '',
  parent_email: '',
  residential_address: '',
  father_profession: '',
  mother_profession: '',
  notes: '',
})

const allClassrooms = computed<ClassRoom[]>(() => {
  const classroomsById = new Map<number, ClassRoom>()

  levels.value.forEach((level) => {
    const levelClassrooms = level.classrooms ?? []

    levelClassrooms.forEach((classroom) => {
      if (!classroomsById.has(classroom.id)) {
        classroomsById.set(classroom.id, { ...classroom, level })
      }
    })
  })

  return [...classroomsById.values()]
})

watch(cycleTabs, (tabs) => {
  if (!tabs.some((tab) => tab.value === activeCycle.value)) {
    activeCycle.value = 'all'
  }
})

const visibleClassrooms = computed<ClassRoom[]>(() => {
  if (activeCycle.value === 'all') return allClassrooms.value

  return allClassrooms.value.filter((classroom) => classroom.level?.cycle === activeCycle.value)
})

const selectedClassroom = computed(() =>
  allClassrooms.value.find((classroom) => classroom.id === form.classroom_id) ?? null,
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

const formSchoolYearId = computed(() => form.enrollment_school_year_id ?? selectedSchoolYear.value?.id ?? null)

function classroomSchoolYearId(classroom: ClassRoom): number | null {
  return classroom.current_school_year_id ?? classroom.school_class?.school_year_id ?? null
}

function classroomOptionKey(classroom: ClassRoom): string {
  return classroomLabel(classroom)
    .normalize('NFD')
    .replace(/\p{Diacritic}/gu, '')
    .toLowerCase()
    .replace(/\s+/g, ' ')
    .trim()
}

const formClassrooms = computed<ClassRoom[]>(() => {
  const targetSchoolYearId = formSchoolYearId.value
  const selectedId = form.classroom_id
  const classroomsByLabel = new Map<string, ClassRoom>()

  allClassrooms.value.forEach((classroom) => {
    const schoolYearId = classroomSchoolYearId(classroom)
    const isSelected = selectedId !== null && classroom.id === selectedId

    if (!isSelected && targetSchoolYearId !== null && schoolYearId !== null && schoolYearId !== targetSchoolYearId) {
      return
    }

    const key = classroomOptionKey(classroom)
    if (!classroomsByLabel.has(key) || isSelected) {
      classroomsByLabel.set(key, classroom)
    }
  })

  return [...classroomsByLabel.values()]
})

const selectedLevelLabel = computed(() => selectedClassroom.value?.level?.name ?? 'Sélectionner une classe')

const selectedOptionLabel = computed(() => {
  const classroom = selectedClassroom.value
  if (!classroom) return 'Sélectionner une classe'
  return classroom.level?.cycle === 'secondaire'
    ? classroom.option || 'Option non définie'
    : 'Non applicable'
})

const visibleStudentIds = computed(() => items.value.map((student) => student.id))

const selectedCount = computed(() => selectedStudentIds.value.length)

const deleteCandidateNames = computed(() =>
  deleteCandidateIds.value.map((id) =>
    items.value.find((student) => student.id === id)?.full_name ?? `Élève #${id}`,
  ),
)

const deleteCandidateCount = computed(() => deleteCandidateIds.value.length)

const deleteConfirmTitle = computed(() =>
  deleteCandidateCount.value > 1 ? 'Supprimer les élèves sélectionnés' : "Supprimer l'élève",
)

const deleteConfirmButtonLabel = computed(() =>
  deleteCandidateCount.value > 1 ? 'Supprimer les élèves' : "Supprimer l'élève",
)

const deletePreviewNames = computed(() => deleteCandidateNames.value.slice(0, 5))

const remainingDeletePreviewCount = computed(() =>
  Math.max(0, deleteCandidateNames.value.length - deletePreviewNames.value.length),
)

const registrationSummarySections = computed<SummarySection[]>(() => {
  const student = registrationSummaryStudent.value
  if (!student) return []

  return [
    {
      title: "Identification de l'élève",
      items: [
        { label: 'Nom complet', value: displayValue(student.full_name) },
        { label: 'Date de naissance', value: formatDisplayDate(student.date_of_birth) },
        { label: 'Lieu de naissance', value: displayValue(student.place_of_birth) },
        { label: 'Sexe', value: genderLabel(student.gender) },
        { label: 'Nationalité', value: displayValue(student.nationality) },
      ],
    },
    {
      title: 'Scolarité',
      items: [
        { label: 'Classe', value: studentClassroomLabel(student) },
        { label: "Année d'inscription", value: studentSchoolYearLabel(student) },
        { label: 'Statut', value: statusLabel(student.enrollment_status) },
        { label: "Date d'inscription", value: formatDisplayDate(student.enrolled_on) },
        { label: 'École de provenance', value: displayValue(student.previous_school) },
      ],
    },
    {
      title: 'Administratif',
      items: [
        { label: 'Matricule', value: displayValue(student.registration_number) },
        { label: "Numéro d'ordre", value: displayValue(student.order_number) },
        { label: 'Portail élève', value: studentPortalStatusLabel(student.student_portal_status) },
      ],
    },
    {
      title: 'Parent / Tuteur',
      items: [
        { label: 'Père', value: displayValue(student.father_name) },
        { label: 'Mère', value: displayValue(student.mother_name) },
        { label: 'Tuteur légal', value: displayValue(student.legal_guardian_name) },
        { label: 'Lien de parenté', value: displayValue(student.guardian_relationship) },
        { label: 'Téléphone principal', value: displayValue(student.primary_phone) },
        { label: 'Téléphone secondaire', value: displayValue(student.secondary_phone) },
        { label: 'Adresse e-mail', value: displayValue(student.parent_email) },
        { label: 'Adresse', value: displayValue(student.residential_address) },
      ],
    },
    {
      title: 'Notes',
      items: [
        { label: 'Observation', value: displayValue(student.notes) },
      ],
    },
  ]
})

function classroomLabel(c: ClassRoom): string {
  return c.full_name ?? `${c.level?.name ?? ''} ${c.section}`.trim()
}

function statusLabel(status: Student['enrollment_status']): string {
  return STATUS_OPTIONS.find((option) => option.value === status)?.label ?? '—'
}

function displayValue(value?: string | number | null): string {
  return value === undefined || value === null || String(value).trim() === '' ? '—' : String(value)
}

function formatDisplayDate(value?: string | null): string {
  if (!value) return '—'
  const date = new Date(`${value}T00:00:00`)
  if (Number.isNaN(date.getTime())) return value

  return new Intl.DateTimeFormat('fr-FR').format(date)
}

function genderLabel(gender?: Student['gender']): string {
  if (gender === 'F') return 'Féminin'
  if (gender === 'M') return 'Masculin'
  return '—'
}

function studentClassroomLabel(student: Student): string {
  return student.classroom ? classroomLabel(student.classroom) : '—'
}

function studentSchoolYearLabel(student: Student): string {
  return student.enrollment_school_year?.name
    ?? schoolYears.value.find((year) => year.id === student.enrollment_school_year_id)?.name
    ?? '—'
}

function credentialRoleLabel(role: PortalCredential['role']): string {
  return role === 'eleve' ? 'Élève' : 'Parent'
}

function credentialLoginTypeLabel(type: PortalCredential['login_type']): string {
  if (type === 'matricule') return 'Matricule'
  if (type === 'telephone') return 'Téléphone'
  return 'Email'
}

function studentPortalStatusLabel(status?: StudentPortalStatus): string {
  if (status === 'active') return 'Actif'
  if (status === 'inactive') return 'Inactif'
  if (status === 'not_created') return 'À créer'
  if (status === 'disabled_until_7e') return 'Désactivé avant la 7e'
  if (status === 'not_created_until_7e') return 'Non créé avant la 7e'
  return '—'
}

function clearSelection(): void {
  if (bulkDeleting.value) return
  selectedStudentIds.value = []
}

function openDeleteConfirm(ids: number[]): void {
  if (ids.length === 0 || bulkDeleting.value || deleteSubmitting.value) return

  deleteCandidateIds.value = [...ids]
  error.value = ''
  showDeleteConfirm.value = true
}

function closeDeleteConfirm(): void {
  if (deleteSubmitting.value) return

  showDeleteConfirm.value = false
  deleteCandidateIds.value = []
}

function removeSelected(): void {
  const ids = [...selectedStudentIds.value]
  if (ids.length === 0 || bulkDeleting.value) return

  openDeleteConfirm(ids)
}

async function confirmDeleteStudents(): Promise<void> {
  const ids = [...deleteCandidateIds.value]
  if (ids.length === 0 || deleteSubmitting.value) return

  deleteSubmitting.value = true
  bulkDeleting.value = true
  error.value = ''
  const failedIds: number[] = []

  try {
    for (const id of ids) {
      try {
        await api(`/api/v1/students/${id}`, { method: 'DELETE' })
      } catch {
        failedIds.push(id)
      }
    }

    selectedStudentIds.value = failedIds
    await load()

    if (failedIds.length > 0) {
      error.value = `${failedIds.length} suppression(s) impossible(s) sur ${ids.length}.`
    }

    showDeleteConfirm.value = false
    deleteCandidateIds.value = []
  } finally {
    bulkDeleting.value = false
    deleteSubmitting.value = false
  }
}

function setActiveCycle(cycle: CycleFilter): void {
  if (activeCycle.value === cycle) return

  activeCycle.value = cycle
  if (filterClassroom.value !== '' && !visibleClassrooms.value.some((classroom) => classroom.id === filterClassroom.value)) {
    filterClassroom.value = ''
  }
}

function resetForm(): void {
  form.first_name = ''
  form.last_name = ''
  form.middle_name = ''
  form.classroom_id = null
  form.enrollment_school_year_id = selectedSchoolYear.value?.id ?? null
  form.date_of_birth = ''
  form.place_of_birth = ''
  form.gender = ''
  form.nationality = 'Congolaise'
  form.photo_path = ''
  form.enrollment_status = 'actif'
  form.order_number = ''
  form.enrolled_on = new Date().toISOString().slice(0, 10)
  form.previous_school = ''
  form.father_name = ''
  form.mother_name = ''
  form.legal_guardian_name = ''
  form.guardian_relationship = ''
  form.primary_phone = ''
  form.secondary_phone = ''
  form.parent_email = ''
  form.residential_address = ''
  form.father_profession = ''
  form.mother_profession = ''
  form.notes = ''
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
    if (search.value.trim()) query.search = search.value.trim()
    const res = await api<Paginated<Student>>('/api/v1/students', { query })
    items.value = res.data
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Erreur de chargement.'
  } finally {
    loading.value = false
  }
}

async function loadLevels(): Promise<void> {
  try {
    const res = await api<Paginated<Level>>('/api/v1/levels')
    levels.value = res.data
  } catch {
    levels.value = []
  }
}

async function loadSchoolYears(): Promise<void> {
  try {
    await schoolYearStore.fetchAll()
  } catch {
    /* Le store garde l'année courante en fallback quand la liste complète est inaccessible. */
  }
}

function openCreate(): void {
  editing.value = null
  returnToSummaryAfterEdit.value = false
  resetForm()
  showForm.value = true
}

function openStudentDetail(s: Student): void {
  void router.push({ name: 'student-detail', params: { id: s.id } })
}

function openEdit(s: Student): void {
  returnToSummaryAfterEdit.value = false
  editing.value = s
  form.first_name = s.first_name
  form.last_name = s.last_name
  form.middle_name = s.middle_name ?? ''
  form.classroom_id = s.classroom_id ?? null
  form.enrollment_school_year_id = s.enrollment_school_year_id ?? selectedSchoolYear.value?.id ?? null
  form.date_of_birth = s.date_of_birth ?? ''
  form.place_of_birth = s.place_of_birth ?? ''
  form.gender = (s.gender as 'F' | 'M' | null) ?? ''
  form.nationality = s.nationality ?? 'Congolaise'
  form.photo_path = s.photo_path ?? ''
  form.enrollment_status = s.enrollment_status ?? 'actif'
  form.order_number = s.order_number ?? ''
  form.enrolled_on = s.enrolled_on ?? new Date().toISOString().slice(0, 10)
  form.previous_school = s.previous_school ?? ''
  form.father_name = s.father_name ?? ''
  form.mother_name = s.mother_name ?? ''
  form.legal_guardian_name = s.legal_guardian_name ?? ''
  form.guardian_relationship = s.guardian_relationship ?? ''
  form.primary_phone = s.primary_phone ?? ''
  form.secondary_phone = s.secondary_phone ?? ''
  form.parent_email = s.parent_email ?? ''
  form.residential_address = s.residential_address ?? ''
  form.father_profession = s.father_profession ?? ''
  form.mother_profession = s.mother_profession ?? ''
  form.notes = s.notes ?? ''
  formError.value = ''
  Object.keys(formErrors).forEach((k) => delete formErrors[k])
  showForm.value = true
}

function editRegistrationSummary(): void {
  const student = registrationSummaryStudent.value
  if (!student) return

  showRegistrationSummary.value = false
  openEdit(student)
  returnToSummaryAfterEdit.value = true
}

function escapeHtml(value: string): string {
  return value
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;')
}

function registrationPrintHtml(
  student: Student,
  sections: SummarySection[],
  credentials: PortalCredential[],
): string {
  const photo = student.photo_path
    ? `<img src="${escapeHtml(student.photo_path)}" alt="Photo">`
    : 'PHOTO'
  const printableSections = sections
    .map((section) => ({
      ...section,
      items: section.items.filter((item) => item.value !== '—' || section.title !== 'Notes'),
    }))
    .filter((section) => section.items.length > 0)
  const sectionsHtml = printableSections.map((section) => `
    <section>
      <h2>${escapeHtml(section.title)}</h2>
      <dl>
        ${section.items.map((item) => `
          <div>
            <dt>${escapeHtml(item.label)}</dt>
            <dd>${escapeHtml(item.value)}</dd>
          </div>
        `).join('')}
      </dl>
    </section>
  `).join('')
  const credentialsHtml = credentials.length > 0
    ? `<section>
        <h2>Identifiants portail</h2>
        <table>
          <thead>
            <tr><th>Profil</th><th>Nom</th><th>Identifiant</th><th>Mot de passe</th></tr>
          </thead>
          <tbody>
            ${credentials.map((credential) => `
              <tr>
                <td>${escapeHtml(credentialRoleLabel(credential.role))}</td>
                <td>${escapeHtml(credential.name)}</td>
                <td>${escapeHtml(credentialLoginTypeLabel(credential.login_type))} : ${escapeHtml(credential.login)}</td>
                <td>${escapeHtml(credential.password ?? 'Compte existant')}</td>
              </tr>
            `).join('')}
          </tbody>
        </table>
      </section>`
    : ''

  return `<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <title>Fiche d'inscription - ${escapeHtml(student.full_name)}</title>
  <style>
    * { box-sizing: border-box; }
    @page { size: A4; margin: 8mm; }
    body { margin: 0; background: #fff; color: #111827; font-family: Arial, sans-serif; font-size: 10.5px; }
    .page { width: 194mm; min-height: 281mm; margin: 0 auto; background: #fff; }
    header { display: grid; grid-template-columns: 1fr 25mm; gap: 8mm; align-items: start; margin-bottom: 4mm; }
    .eyebrow { margin: 0 0 1mm; font-size: 9px; font-weight: 700; text-transform: uppercase; color: #64748b; letter-spacing: .04em; }
    h1 { margin: 0; font-size: 17px; text-transform: uppercase; text-align: center; }
    .year { margin: 1mm 0 0; text-align: center; font-weight: 700; }
    .photo { width: 25mm; height: 32mm; border: 1px solid #94a3b8; display: grid; place-items: center; color: #64748b; font-size: 10px; font-weight: 700; overflow: hidden; }
    .photo img { width: 100%; height: 100%; object-fit: cover; }
    section { margin-top: 3.5mm; break-inside: avoid; page-break-inside: avoid; }
    h2 { margin: 0 0 1.5mm; font-size: 10px; text-transform: uppercase; border-bottom: 1px solid #cbd5e1; padding-bottom: 1mm; }
    dl { margin: 0; display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 2mm 5mm; }
    dl div { min-height: 7mm; border-bottom: 1px dotted #94a3b8; }
    dt { font-size: 9px; color: #64748b; font-weight: 700; }
    dd { margin: .6mm 0 0; font-size: 10.5px; font-weight: 700; overflow-wrap: anywhere; }
    table { width: 100%; border-collapse: collapse; font-size: 10px; }
    th, td { border: 1px solid #cbd5e1; padding: 1.4mm; text-align: left; vertical-align: top; }
    th { background: #f8fafc; font-size: 9px; text-transform: uppercase; }
    .signatures { display: grid; grid-template-columns: 1fr 1fr; gap: 18mm; margin-top: 10mm; }
    .signature { border-top: 1px solid #111827; padding-top: 1.5mm; text-align: center; font-size: 10px; font-weight: 700; }
  </style>
</head>
<body>
  <main class="page">
    <header>
      <div>
        <p class="eyebrow">Fiche d'inscription</p>
        <h1>${escapeHtml(student.full_name)}</h1>
        <p class="year">Année scolaire ${escapeHtml(studentSchoolYearLabel(student))}</p>
      </div>
      <div class="photo">${photo}</div>
    </header>
    ${sectionsHtml}
    ${credentialsHtml}
    <div class="signatures">
      <div class="signature">Signature du parent / tuteur</div>
      <div class="signature">Administration</div>
    </div>
  </main>
</body>
</html>`
}

function printRegistrationSummary(): void {
  const student = registrationSummaryStudent.value
  if (!student) return

  const printWindow = window.open('', '_blank', 'width=900,height=1200')
  if (!printWindow) {
    toast.error("Ouverture de la fenêtre d'impression impossible.")
    return
  }

  printWindow.document.write(registrationPrintHtml(
    student,
    registrationSummarySections.value,
    registrationPortalCredentials.value,
  ))
  printWindow.document.close()
  printWindow.focus()
  window.setTimeout(() => {
    printWindow.print()
  }, 250)
}

async function submit(): Promise<void> {
  submitting.value = true
  formError.value = ''
  Object.keys(formErrors).forEach((k) => delete formErrors[k])
  const wasEditing = editing.value !== null
  const shouldShowSummary = !wasEditing || returnToSummaryAfterEdit.value
  const payload: Record<string, unknown> = {
    first_name: form.first_name,
    last_name: form.last_name,
    middle_name: form.middle_name,
    classroom_id: form.classroom_id,
    enrollment_school_year_id: form.enrollment_school_year_id,
    date_of_birth: form.date_of_birth,
    place_of_birth: form.place_of_birth,
    gender: form.gender,
    nationality: form.nationality,
    photo_path: form.photo_path || null,
    enrollment_status: form.enrollment_status,
    enrolled_on: form.enrolled_on,
    previous_school: form.previous_school || null,
    father_name: form.father_name || null,
    mother_name: form.mother_name || null,
    legal_guardian_name: form.legal_guardian_name || null,
    guardian_relationship: form.guardian_relationship || null,
    primary_phone: form.primary_phone,
    secondary_phone: form.secondary_phone || null,
    parent_email: form.parent_email || null,
    residential_address: form.residential_address || null,
    father_profession: form.father_profession || null,
    mother_profession: form.mother_profession || null,
    notes: form.notes || null,
  }
  try {
    let savedStudent: Student
    let portalCredentials: PortalCredential[] = []
    if (editing.value) {
      const res = await api<StudentSaveResponse>(`/api/v1/students/${editing.value.id}`, {
        method: 'PUT',
        body: payload,
      })
      savedStudent = res.data
      portalCredentials = res.meta?.portal_credentials ?? []
    } else {
      const res = await api<StudentSaveResponse>('/api/v1/students', {
        method: 'POST',
        body: payload,
      })
      savedStudent = res.data
      portalCredentials = res.meta?.portal_credentials ?? []
    }
    registrationPortalCredentials.value = portalCredentials
    showForm.value = false
    await load()
    if (shouldShowSummary) {
      try {
        const full = await api<ApiResource<Student>>(`/api/v1/students/${savedStudent.id}`)
        registrationSummaryStudent.value = full.data
      } catch {
        registrationSummaryStudent.value = savedStudent
      }
      showRegistrationSummary.value = true
    }
    returnToSummaryAfterEdit.value = false
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

function openImport(): void {
  importFile.value = null
  importResult.value = null
  importError.value = ''
  showImport.value = true
}

function onFileChange(e: Event): void {
  const target = e.target as HTMLInputElement
  importFile.value = target.files?.[0] ?? null
}

async function downloadTemplate(): Promise<void> {
  try {
    const token = getToken()
    const res = await fetch(apiUrl('/api/v1/students/import/template'), {
      headers: { Authorization: `Bearer ${token ?? ''}`, Accept: 'application/json' },
    })
    if (!res.ok) throw new Error()
    const blob = await res.blob()
    const a = document.createElement('a')
    a.href = URL.createObjectURL(blob)
    a.download = 'modele-eleves.csv'
    a.click()
    URL.revokeObjectURL(a.href)
  } catch {
    importError.value = 'Téléchargement du modèle impossible.'
  }
}

async function submitImport(): Promise<void> {
  if (!importFile.value) {
    importError.value = 'Sélectionne un fichier CSV.'
    return
  }
  importing.value = true
  importError.value = ''
  importResult.value = null
  try {
    const token = getToken()
    const fd = new FormData()
    fd.append('file', importFile.value)
    const res = await fetch(apiUrl('/api/v1/students/import'), {
      method: 'POST',
      headers: { Authorization: `Bearer ${token ?? ''}`, Accept: 'application/json' },
      body: fd,
    })
    const json = await res.json()
    if (!res.ok) {
      importError.value = json.message ?? 'Import impossible.'
      return
    }
    importResult.value = { warnings: [], ...json }
    await load()
  } catch {
    importError.value = 'Erreur réseau.'
  } finally {
    importing.value = false
  }
}

function remove(s: Student): void {
  openDeleteConfirm([s.id])
}

let searchTimer: number | null = null
watch([search, filterClassroom, activeCycle], () => {
  if (searchTimer) window.clearTimeout(searchTimer)
  searchTimer = window.setTimeout(load, 250)
})

watch(items, () => {
  const visibleIds = new Set(visibleStudentIds.value)
  selectedStudentIds.value = selectedStudentIds.value.filter((id) => visibleIds.has(id))
})

watch(formClassrooms, (classrooms) => {
  if (form.classroom_id !== null && !classrooms.some((classroom) => classroom.id === form.classroom_id)) {
    form.classroom_id = null
  }
})

// Recharge la liste quand l'utilisateur bascule d'année via le sélecteur global.
watch(
  () => schoolYearStore.effectiveId,
  async () => {
    await loadLevels()
    if (
      filterClassroom.value !== ''
      && !visibleClassrooms.value.some((classroom) => classroom.id === filterClassroom.value)
    ) {
      filterClassroom.value = ''
    }
    if (!editing.value) {
      form.enrollment_school_year_id = selectedSchoolYear.value?.id ?? null
    }
    await load()
  },
)

onMounted(async () => {
  await Promise.all([loadLevels(), loadSchoolYears(), load()])
})
</script>

<template>
  <section>
    <div class="card">
      <div class="card-header">
        <h1 style="margin: 0">Élèves</h1>
        <div style="display: flex; gap: 0.5rem">
          <button type="button" class="btn-secondary" @click="openImport">Importer CSV</button>
          <button type="button" class="btn-primary" @click="openCreate">+ Nouvel élève</button>
        </div>
      </div>

      <div class="student-toolbar">
        <div class="cycle-tabs" role="tablist" aria-label="Filtrer les élèves par cycle">
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

        <div class="student-filters">
          <input
            v-model="search"
            type="search"
            placeholder="Rechercher par nom, postnom, prénom ou matricule…"
          />
          <select v-model="filterClassroom">
            <option value="">Toutes les classes</option>
            <option v-for="c in visibleClassrooms" :key="c.id" :value="c.id">
              {{ classroomLabel(c) }}
            </option>
          </select>
        </div>

        <div v-if="selectedCount > 0" class="selection-strip" role="status">
          <div class="selection-summary">
            <strong>{{ selectedCount }}</strong>
            <span>élève(s) sélectionné(s)</span>
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

      <p v-if="error" class="alert alert-error" style="margin: 0 1rem 1rem">{{ error }}</p>
      <div v-if="loading" class="empty-state">Chargement…</div>
      <div v-else-if="items.length === 0" class="empty-state">Aucun élève trouvé.</div>

      <DataTable
        v-else
        :items="items"
        :columns="columns"
        key-field="id"
        selectable
        v-model:selected-ids="selectedStudentIds"
        row-clickable
        @row-click="openStudentDetail"
      >
        <template #col-name="{ item }">
          <RouterLink
            class="entity-name-link"
            :to="{ name: 'student-detail', params: { id: item.id } }"
            @click.stop
            @keydown.stop
          >
            {{ item.last_name }}
          </RouterLink>
        </template>
        <template #col-classroom="{ item }">
          {{ item.classroom ? classroomLabel(item.classroom) : '—' }}
        </template>
        <template #col-status="{ item }">
          {{ statusLabel(item.enrollment_status) }}
        </template>
        <template #col-dob="{ item }">
          {{ item.date_of_birth ?? '—' }}
        </template>
        <template #col-gender="{ item }">
          {{ item.gender ?? '—' }}
        </template>
        <template #col-registration_number="{ item }">
          <code v-if="item.registration_number">{{ item.registration_number }}</code><span v-else>—</span>
        </template>
        <template #col-actions="{ item, index }">
          <RowActionMenu
            :open-up="index >= items.length - 2"
            :aria-label="`Actions pour ${item.full_name}`"
          >
            <RouterLink :to="{ name: 'student-detail', params: { id: item.id } }">Ouvrir</RouterLink>
            <button type="button" @click="openEdit(item)">Modifier</button>
            <button type="button" class="danger-action" @click="remove(item)">Supprimer</button>
          </RowActionMenu>
        </template>
      </DataTable>
    </div>

    <Modal
      :open="showForm"
      :title="editing ? 'Modifier un élève' : 'Nouvel élève'"
      max-width="64rem"
      @close="showForm = false"
    >
      <form id="student-form" class="student-form" @submit.prevent="submit">
        <section class="form-section">
          <h3>Identification</h3>
          <div class="form-grid">
            <div class="field">
              <label for="s-last">Nom</label>
              <input id="s-last" v-model="form.last_name" type="text" required maxlength="100" placeholder="Ex. KABONGO" />
              <small v-if="formErrors.last_name" class="err">{{ formErrors.last_name[0] }}</small>
            </div>
            <div class="field">
              <label for="s-middle">Postnom</label>
              <input id="s-middle" v-model="form.middle_name" type="text" required maxlength="100" placeholder="Ex. MUKENDI" />
              <small v-if="formErrors.middle_name" class="err">{{ formErrors.middle_name[0] }}</small>
            </div>
            <div class="field">
              <label for="s-first">Prénom(s)</label>
              <input id="s-first" v-model="form.first_name" type="text" required maxlength="100" placeholder="Ex. Grâce" />
              <small v-if="formErrors.first_name" class="err">{{ formErrors.first_name[0] }}</small>
            </div>
            <div class="field">
              <label for="s-dob">Date de naissance</label>
              <input id="s-dob" v-model="form.date_of_birth" type="date" required />
              <small v-if="formErrors.date_of_birth" class="err">{{ formErrors.date_of_birth[0] }}</small>
            </div>
            <div class="field">
              <label for="s-pob">Lieu de naissance</label>
              <input id="s-pob" v-model="form.place_of_birth" type="text" required maxlength="100" placeholder="Ex. Lubumbashi" />
              <small v-if="formErrors.place_of_birth" class="err">{{ formErrors.place_of_birth[0] }}</small>
            </div>
            <div class="field">
              <label for="s-gender">Sexe</label>
              <select id="s-gender" v-model="form.gender" required>
                <option value="" disabled>— Sélectionner —</option>
                <option value="F">Féminin</option>
                <option value="M">Masculin</option>
              </select>
              <small v-if="formErrors.gender" class="err">{{ formErrors.gender[0] }}</small>
            </div>
            <div class="field">
              <label for="s-nationality">Nationalité</label>
              <input id="s-nationality" v-model="form.nationality" type="text" required maxlength="80" />
              <small v-if="formErrors.nationality" class="err">{{ formErrors.nationality[0] }}</small>
            </div>
            <div class="field wide">
              <label for="s-photo">Photo</label>
              <input id="s-photo" v-model="form.photo_path" type="url" maxlength="255" placeholder="https://…" />
              <small v-if="formErrors.photo_path" class="err">{{ formErrors.photo_path[0] }}</small>
            </div>
          </div>
        </section>

        <section class="form-section">
          <h3>Scolarité</h3>
          <div class="form-grid">
            <div class="field">
              <label for="s-class">Classe actuelle</label>
              <select id="s-class" v-model.number="form.classroom_id" required>
                <option :value="null" disabled>— Sélectionner —</option>
                <option v-for="c in formClassrooms" :key="c.id" :value="c.id">
                  {{ classroomLabel(c) }}
                </option>
              </select>
              <small v-if="formErrors.classroom_id" class="err">{{ formErrors.classroom_id[0] }}</small>
            </div>
            <div class="field">
              <label>Niveau d'étude</label>
              <div class="readonly-field">{{ selectedLevelLabel }}</div>
            </div>
            <div class="field">
              <label>Option / Filière</label>
              <div class="readonly-field">{{ selectedOptionLabel }}</div>
            </div>
            <div class="field">
              <label for="s-year">Année académique d'inscription</label>
              <select id="s-year" v-model.number="form.enrollment_school_year_id" required>
                <option :value="null" disabled>— Sélectionner —</option>
                <option v-for="year in schoolYears" :key="year.id" :value="year.id">
                  {{ year.name }}
                </option>
              </select>
              <small v-if="formErrors.enrollment_school_year_id" class="err">
                {{ formErrors.enrollment_school_year_id[0] }}
              </small>
            </div>
            <div class="field">
              <label for="s-status">Statut</label>
              <select id="s-status" v-model="form.enrollment_status" required>
                <option v-for="status in STATUS_OPTIONS" :key="status.value" :value="status.value">
                  {{ status.label }}
                </option>
              </select>
              <small v-if="formErrors.enrollment_status" class="err">{{ formErrors.enrollment_status[0] }}</small>
            </div>
          </div>
        </section>

        <section class="form-section">
          <h3>Administratif</h3>
          <div class="form-grid">
            <div class="field">
              <label>Matricule</label>
              <div class="readonly-field">
                {{ editing?.registration_number ?? 'Généré automatiquement' }}
              </div>
            </div>
            <div class="field">
              <label for="s-order">Numéro d'ordre</label>
              <div id="s-order" class="readonly-field">
                {{ editing?.order_number ?? 'Généré automatiquement' }}
              </div>
            </div>
            <div class="field">
              <label for="s-enrolled">Date d'inscription</label>
              <input id="s-enrolled" v-model="form.enrolled_on" type="date" required />
              <small v-if="formErrors.enrolled_on" class="err">{{ formErrors.enrolled_on[0] }}</small>
            </div>
            <div class="field">
              <label for="s-previous">École de provenance</label>
              <input id="s-previous" v-model="form.previous_school" type="text" maxlength="160" />
              <small v-if="formErrors.previous_school" class="err">{{ formErrors.previous_school[0] }}</small>
            </div>
          </div>
        </section>

        <section class="form-section">
          <h3>Parent / Tuteur</h3>
          <div class="form-grid">
            <div class="field">
              <label for="s-father">Nom et prénom(s) du père</label>
              <input id="s-father" v-model="form.father_name" type="text" maxlength="160" />
              <small v-if="formErrors.father_name" class="err">{{ formErrors.father_name[0] }}</small>
            </div>
            <div class="field">
              <label for="s-mother">Nom et prénom(s) de la mère</label>
              <input id="s-mother" v-model="form.mother_name" type="text" maxlength="160" />
              <small v-if="formErrors.mother_name" class="err">{{ formErrors.mother_name[0] }}</small>
            </div>
            <div class="field">
              <label for="s-guardian">Tuteur légal</label>
              <input id="s-guardian" v-model="form.legal_guardian_name" type="text" maxlength="160" />
              <small v-if="formErrors.legal_guardian_name" class="err">
                {{ formErrors.legal_guardian_name[0] }}
              </small>
            </div>
            <div class="field">
              <label for="s-relation">Lien de parenté</label>
              <input id="s-relation" v-model="form.guardian_relationship" type="text" maxlength="80" />
              <small v-if="formErrors.guardian_relationship" class="err">
                {{ formErrors.guardian_relationship[0] }}
              </small>
            </div>
            <div class="field">
              <label for="s-phone">Téléphone principal</label>
              <input id="s-phone" v-model="form.primary_phone" type="tel" required maxlength="32" />
              <small v-if="formErrors.primary_phone" class="err">{{ formErrors.primary_phone[0] }}</small>
            </div>
            <div class="field">
              <label for="s-phone-2">Téléphone secondaire</label>
              <input id="s-phone-2" v-model="form.secondary_phone" type="tel" maxlength="32" />
              <small v-if="formErrors.secondary_phone" class="err">{{ formErrors.secondary_phone[0] }}</small>
            </div>
            <div class="field">
              <label for="s-parent-email">Adresse e-mail</label>
              <input id="s-parent-email" v-model="form.parent_email" type="email" maxlength="160" />
              <small v-if="formErrors.parent_email" class="err">{{ formErrors.parent_email[0] }}</small>
            </div>
            <div class="field">
              <label for="s-address">Adresse / Quartier</label>
              <input id="s-address" v-model="form.residential_address" type="text" maxlength="255" />
              <small v-if="formErrors.residential_address" class="err">
                {{ formErrors.residential_address[0] }}
              </small>
            </div>
            <div class="field">
              <label for="s-father-job">Profession du père</label>
              <input id="s-father-job" v-model="form.father_profession" type="text" maxlength="120" />
              <small v-if="formErrors.father_profession" class="err">{{ formErrors.father_profession[0] }}</small>
            </div>
            <div class="field">
              <label for="s-mother-job">Profession de la mère</label>
              <input id="s-mother-job" v-model="form.mother_profession" type="text" maxlength="120" />
              <small v-if="formErrors.mother_profession" class="err">{{ formErrors.mother_profession[0] }}</small>
            </div>
          </div>
        </section>

        <section class="form-section">
          <h3>Notes internes</h3>
          <div class="field">
            <label for="s-notes">Observation</label>
            <textarea id="s-notes" v-model="form.notes" rows="3" maxlength="5000" />
            <small v-if="formErrors.notes" class="err">{{ formErrors.notes[0] }}</small>
          </div>
        </section>
        <p v-if="formError" class="alert alert-error">{{ formError }}</p>
      </form>
      <template #footer>
        <button type="button" @click="showForm = false">Annuler</button>
        <button type="submit" form="student-form" class="btn-primary" :disabled="submitting">
          {{ submitting ? 'Enregistrement…' : editing ? 'Enregistrer' : 'Créer' }}
        </button>
      </template>
    </Modal>

    <Modal
      :open="showDeleteConfirm"
      :title="deleteConfirmTitle"
      max-width="34rem"
      @close="closeDeleteConfirm"
    >
      <div class="delete-confirm">
        <div class="delete-mark" aria-hidden="true">!</div>
        <div class="delete-copy">
          <h3>Confirmer la suppression</h3>
          <p>
            Cette action supprimera définitivement
            <strong>{{ deleteCandidateCount }}</strong>
            dossier{{ deleteCandidateCount > 1 ? 's' : '' }}
            élève{{ deleteCandidateCount > 1 ? 's' : '' }}.
          </p>
        </div>

        <ul class="delete-list" aria-label="Élèves concernés">
          <li v-for="name in deletePreviewNames" :key="name">{{ name }}</li>
          <li v-if="remainingDeletePreviewCount > 0">
            + {{ remainingDeletePreviewCount }} autre{{ remainingDeletePreviewCount > 1 ? 's' : '' }}
          </li>
        </ul>

        <p class="delete-note">
          Les informations liées à ces dossiers ne seront plus affichées après validation.
        </p>
      </div>

      <template #footer>
        <button type="button" :disabled="deleteSubmitting" @click="closeDeleteConfirm">Annuler</button>
        <button
          type="button"
          class="delete-danger-btn"
          :disabled="deleteSubmitting"
          @click="confirmDeleteStudents"
        >
          {{ deleteSubmitting ? 'Suppression…' : deleteConfirmButtonLabel }}
        </button>
      </template>
    </Modal>

    <Modal
      :open="showRegistrationSummary && registrationSummaryStudent !== null"
      title="Résumé d'inscription"
      max-width="58rem"
      @close="showRegistrationSummary = false"
    >
      <article v-if="registrationSummaryStudent" class="registration-summary">
        <header class="registration-summary-header">
          <div>
            <p class="summary-eyebrow">Fiche d'inscription</p>
            <h3>{{ registrationSummaryStudent.full_name }}</h3>
            <p>
              Année scolaire {{ studentSchoolYearLabel(registrationSummaryStudent) }}
            </p>
            <div class="summary-badges">
              <span>{{ studentClassroomLabel(registrationSummaryStudent) }}</span>
              <span>{{ statusLabel(registrationSummaryStudent.enrollment_status) }}</span>
            </div>
          </div>
          <div class="summary-photo">
            <img
              v-if="registrationSummaryStudent.photo_path"
              :src="registrationSummaryStudent.photo_path"
              alt="Photo de l'élève"
            >
            <span v-else>PHOTO</span>
          </div>
        </header>

        <div class="summary-sections">
          <section v-for="section in registrationSummarySections" :key="section.title" class="summary-section">
            <h4>{{ section.title }}</h4>
            <dl>
              <div v-for="item in section.items" :key="item.label" class="summary-row">
                <dt>{{ item.label }}</dt>
                <dd>{{ item.value }}</dd>
              </div>
            </dl>
          </section>

          <section v-if="registrationPortalCredentials.length > 0" class="summary-section">
            <h4>Identifiants portail</h4>
            <div class="credential-grid">
              <article
                v-for="credential in registrationPortalCredentials"
                :key="`${credential.role}-${credential.login}`"
                class="credential-card"
              >
                <div>
                  <strong>{{ credentialRoleLabel(credential.role) }}</strong>
                  <span>{{ credential.name }}</span>
                </div>
                <dl>
                  <div>
                    <dt>{{ credentialLoginTypeLabel(credential.login_type) }}</dt>
                    <dd><code>{{ credential.login }}</code></dd>
                  </div>
                  <div>
                    <dt>Mot de passe</dt>
                    <dd>
                      <code v-if="credential.password">{{ credential.password }}</code>
                      <span v-else>Compte existant</span>
                    </dd>
                  </div>
                </dl>
              </article>
            </div>
          </section>
        </div>
      </article>

      <template #footer>
        <button type="button" @click="showRegistrationSummary = false">Fermer</button>
        <button type="button" @click="editRegistrationSummary">Modifier</button>
        <button type="button" class="btn-primary" @click="printRegistrationSummary">Imprimer</button>
      </template>
    </Modal>

    <!-- ── Modal Import CSV ── -->
    <Modal :open="showImport" title="Importer des élèves (CSV)" @close="showImport = false">
      <p style="margin: 0 0 0.75rem; font-size: 0.88rem; color: var(--text-soft)">
        Colonnes acceptées : <code>last_name</code>, <code>postnom</code>, <code>first_name</code> (obligatoires),
        <code>date_of_birth</code>, <code>gender</code>, <code>registration_number</code>,
        <code>classroom</code>, <code>email</code>, <code>notes</code>.
      </p>

      <p style="margin: 0 0 0.75rem">
        <button type="button" @click="downloadTemplate">Télécharger le modèle CSV</button>
      </p>

      <div class="field">
        <label for="csv-file">Fichier CSV</label>
        <input id="csv-file" type="file" accept=".csv,text/csv" @change="onFileChange" />
      </div>

      <p v-if="importError" class="alert alert-error">{{ importError }}</p>

      <template v-if="importResult">
        <p class="alert" style="background: var(--success-soft); color: var(--success)">
          {{ importResult.message }}
        </p>
        <details v-if="importResult.errors.length > 0" style="margin-top: 0.5rem">
          <summary>{{ importResult.errors.length }} erreur(s)</summary>
          <ul style="margin: 0.5rem 0 0; padding-left: 1.2rem; font-size: 0.85rem">
            <li v-for="(e, i) in importResult.errors" :key="i">
              <strong>Ligne {{ e.line }} :</strong> {{ e.errors.join(' · ') }}
            </li>
          </ul>
        </details>
        <details v-if="importResult.warnings.length > 0" style="margin-top: 0.5rem">
          <summary>{{ importResult.warnings.length }} avertissement(s)</summary>
          <ul style="margin: 0.5rem 0 0; padding-left: 1.2rem; font-size: 0.85rem">
            <li v-for="(w, i) in importResult.warnings" :key="i">
              <strong>Ligne {{ w.line }} :</strong> {{ w.warning }}
            </li>
          </ul>
        </details>
        <details v-if="importResult.credentials.length > 0" style="margin-top: 0.5rem">
          <summary>{{ importResult.credentials.length }} compte(s) créé(s)</summary>
          <table style="font-size: 0.85rem; margin-top: 0.5rem">
            <thead><tr><th>Email</th><th>Mot de passe provisoire</th></tr></thead>
            <tbody>
              <tr v-for="(c, i) in importResult.credentials" :key="i">
                <td>{{ c.email }}</td>
                <td><code>{{ c.password }}</code></td>
              </tr>
            </tbody>
          </table>
        </details>
      </template>

      <template #footer>
        <button type="button" @click="showImport = false">Fermer</button>
        <button type="button" class="btn-primary" :disabled="importing || !importFile" @click="submitImport">
          {{ importing ? 'Import…' : 'Importer' }}
        </button>
      </template>
    </Modal>
  </section>
</template>

<style scoped>
button + button { margin-left: 0.4rem; }
.err { display: block; color: var(--danger); font-size: 0.78rem; margin-top: 0.25rem; }
code { font-family: ui-monospace,Consolas,monospace; background: var(--bg-subtle); padding:.1rem .35rem; border-radius:4px; font-size:.78rem; }
.student-toolbar {
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
  background: var(--bg-soft);
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
.student-filters {
  display: flex;
  gap: 0.75rem;
  align-items: center;
  flex-wrap: wrap;
}
.student-filters input {
  flex: 1;
  min-width: 14rem;
}
.student-filters select {
  width: auto;
  min-width: 13rem;
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
  background: var(--bg-card);
  color: var(--accent);
  font-size: 0.8rem;
  font-weight: 800;
}
.selection-strip button:disabled {
  cursor: not-allowed;
  opacity: 0.65;
}
.selection-strip .bulk-danger {
  border-color: rgba(248, 113, 113, 0.3);
  color: var(--danger);
}
.selection-strip .bulk-danger:hover:not(:disabled) {
  background: var(--danger-soft);
}
.is-selected {
  background: var(--primary-soft);
}
.entity-name-link {
  display: inline-flex;
  max-width: 100%;
  color: var(--text);
  font-weight: 900;
  line-height: 1.25;
  text-decoration: none;
}
.entity-name-link:hover,
.entity-name-link:focus-visible {
  color: var(--primary);
  text-decoration: none;
}
.name-cell {
  min-width: 11rem;
}
.clickable-row {
  cursor: pointer;
}
.clickable-row:hover {
  background: rgba(59, 130, 246, 0.05);
}
.clickable-row:focus-visible {
  outline: 2px solid var(--primary);
  outline-offset: -2px;
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
.delete-confirm {
  display: grid;
  grid-template-columns: 2.75rem minmax(0, 1fr);
  gap: 0.9rem;
  align-items: start;
}
.delete-mark {
  width: 2.75rem;
  height: 2.75rem;
  display: grid;
  place-items: center;
  border-radius: 999px;
  background: var(--danger-soft);
  color: var(--danger);
  font-size: 1.15rem;
  font-weight: 950;
}
.delete-copy {
  display: grid;
  gap: 0.35rem;
}
.delete-copy h3 {
  margin: 0;
  color: var(--text);
  font-size: 1rem;
}
.delete-copy p {
  margin: 0;
  color: var(--text-soft);
  line-height: 1.5;
}
.delete-list {
  grid-column: 1 / -1;
  display: grid;
  gap: 0.35rem;
  margin: 0;
  padding: 0;
  list-style: none;
}
.delete-list li {
  padding: 0.55rem 0.7rem;
  border: 1px solid var(--border);
  border-radius: 8px;
  background: var(--bg-soft);
  color: var(--text);
  font-size: 0.88rem;
  font-weight: 800;
}
.delete-note {
  grid-column: 1 / -1;
  margin: 0;
  padding: 0.7rem 0.8rem;
  border: 1px solid rgba(248, 113, 113, 0.3);
  border-radius: 8px;
  background: var(--danger-soft);
  color: var(--danger);
  font-size: 0.85rem;
  font-weight: 750;
}
.delete-danger-btn {
  border-color: var(--danger);
  background: var(--danger);
  color: #fff;
}
.delete-danger-btn:hover:not(:disabled) {
  border-color: #b42318;
  background: #b42318;
}
.delete-danger-btn:disabled {
  cursor: not-allowed;
  opacity: 0.7;
}
.student-form {
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
  background: var(--bg-soft);
  color: var(--text-soft);
  font-size: 0.9rem;
  font-weight: 750;
}
textarea {
  resize: vertical;
}
.registration-summary {
  display: grid;
  gap: 1.1rem;
}
.registration-summary-header {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 7.25rem;
  gap: 1rem;
  align-items: start;
  padding-bottom: 1rem;
  border-bottom: 1px solid var(--border);
}
.summary-eyebrow {
  margin: 0 0 0.25rem;
  color: var(--text-soft);
  font-size: 0.76rem;
  font-weight: 900;
  letter-spacing: 0.04em;
  text-transform: uppercase;
}
.registration-summary h3 {
  margin: 0;
  color: var(--text);
  font-size: 1.35rem;
}
.registration-summary-header p:not(.summary-eyebrow) {
  margin: 0.35rem 0 0;
  color: var(--text-soft);
  font-weight: 750;
}
.summary-badges {
  display: flex;
  flex-wrap: wrap;
  gap: 0.45rem;
  margin-top: 0.7rem;
}
.summary-badges span {
  padding: 0.28rem 0.55rem;
  border: 1px solid var(--border);
  border-radius: 6px;
  background: var(--bg-soft);
  color: var(--text);
  font-size: 0.8rem;
  font-weight: 850;
}
.summary-photo {
  width: 7.25rem;
  aspect-ratio: 3 / 4;
  display: grid;
  place-items: center;
  justify-self: end;
  border: 1px solid var(--border);
  border-radius: 6px;
  background: var(--bg-soft);
  color: var(--text-soft);
  font-size: 0.8rem;
  font-weight: 900;
  overflow: hidden;
}
.summary-photo img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}
.summary-sections {
  display: grid;
  gap: 1rem;
}
.summary-section {
  display: grid;
  gap: 0.65rem;
}
.summary-section h4 {
  margin: 0;
  padding-bottom: 0.45rem;
  border-bottom: 1px solid var(--border);
  color: var(--text);
  font-size: 0.9rem;
  text-transform: uppercase;
}
.summary-section dl {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.7rem 1rem;
  margin: 0;
}
.summary-row {
  min-width: 0;
  padding-bottom: 0.5rem;
  border-bottom: 1px dotted var(--border-strong);
}
.summary-row dt {
  color: var(--text-soft);
  font-size: 0.75rem;
  font-weight: 850;
}
.summary-row dd {
  margin: 0.15rem 0 0;
  color: var(--text);
  font-size: 0.92rem;
  font-weight: 750;
  overflow-wrap: anywhere;
  white-space: pre-wrap;
}
.credential-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.75rem;
}
.credential-card {
  display: grid;
  gap: 0.65rem;
  padding: 0.8rem;
  border: 1px solid var(--primary-tint);
  border-radius: 8px;
  background: var(--primary-soft);
}
.credential-card > div:first-child {
  display: grid;
  gap: 0.1rem;
}
.credential-card strong {
  color: var(--primary);
  font-size: 0.88rem;
}
.credential-card span {
  color: var(--text-soft);
  font-size: 0.82rem;
  font-weight: 750;
}
.credential-card dl {
  display: grid;
  gap: 0.45rem;
  margin: 0;
}
.credential-card dt {
  color: var(--text-soft);
  font-size: 0.73rem;
  font-weight: 850;
}
.credential-card dd {
  margin: 0.15rem 0 0;
}
@media (max-width: 720px) {
  .cycle-tabs,
  .student-filters {
    align-items: stretch;
  }
  .cycle-tab {
    flex: 1 1 8rem;
  }
  .student-filters input,
  .student-filters select {
    width: 100%;
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
  .field.wide {
    grid-column: auto;
  }
  .registration-summary-header {
    grid-template-columns: 1fr;
  }
  .summary-photo {
    justify-self: start;
  }
  .summary-section dl {
    grid-template-columns: 1fr;
  }
  .credential-grid {
    grid-template-columns: 1fr;
  }
}
</style>
