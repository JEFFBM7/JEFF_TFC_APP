<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { api, ApiError, getToken } from '../api/client'
import AreaChart from '../components/charts/AreaChart.vue'
import BarChart from '../components/charts/BarChart.vue'
import LineChart from '../components/charts/LineChart.vue'
import PeriodFormModal from '../components/PeriodFormModal.vue'
import { useAuthStore } from '../stores/auth'
import type {
  ApiResource,
  ChartSeries,
  Paginated,
  SchoolClass,
  SchoolYear,
  SchoolYearClassStats,
  SchoolYearFinalStatus,
  SchoolYearStudentRow,
  SchoolYearTermStats,
  Period,
  Term,
  LevelCycle,
} from '../types'
import Modal from '../components/Modal.vue'
import PromotionPanel from '../components/schoolyear/PromotionPanel.vue'
import { useConfirmStore } from '../stores/confirm'
import { useToastStore } from '../stores/toast'
import { chartPercentFromAverage20, formatAveragePercent } from '../utils/grades'

const props = defineProps<{ id: string | number }>()
type DataViewMode = 'grid' | 'table'
type TabId =
  | 'overview'
  | 'classes'
  | 'students'
  | 'results'
  | 'attendance'
  | 'documents'
  | 'history'

const TABS: ReadonlyArray<{ id: TabId; label: string; description: string }> = [
  { id: 'overview', label: 'Résumé', description: 'Statut, dates et indicateurs clés' },
  { id: 'classes', label: 'Classes', description: 'Liste, titulaires, effectifs' },
  { id: 'students', label: 'Élèves', description: 'Inscrits, classe, statut final' },
  { id: 'results', label: 'Résultats', description: 'Moyennes, bulletins, décisions' },
  { id: 'attendance', label: 'Absences', description: 'Par élève, par classe, statistiques' },
  { id: 'documents', label: 'Documents', description: 'Bulletins, rapports, listes' },
  { id: 'history', label: 'Historique', description: 'Clôture, archivage, responsable' },
]

const auth = useAuthStore()
const confirmDialog = useConfirmStore()
const toast = useToastStore()
const route = useRoute()
const year = ref<SchoolYear | null>(null)
const schoolClasses = ref<SchoolClass[]>([])
const loading = ref(false)
const error = ref('')
const archiving = ref(false)
const generatingClasses = ref(false)
const activeTab = ref<TabId>('overview')

function resolveTabFromQuery(value: unknown): TabId | null {
  if (typeof value !== 'string') return null
  return TABS.some((tab) => tab.id === value) ? (value as TabId) : null
}

const showForm = ref(false)
const editing = ref<Term | null>(null)
const formError = ref('')
const formErrors = reactive<Record<string, string[]>>({})
const submitting = ref(false)
const closing = ref<number | null>(null)
const showPeriodForm = ref(false)
const periodTerm = ref<Term | null>(null)
const editingPeriod = ref<Period | null>(null)
const closingPeriod = ref<number | null>(null)

const dateFormatter = new Intl.DateTimeFormat('fr-FR', {
  day: '2-digit',
  month: '2-digit',
  year: 'numeric',
})
const dateTimeFormatter = new Intl.DateTimeFormat('fr-FR', {
  day: '2-digit',
  month: '2-digit',
  year: 'numeric',
  hour: '2-digit',
  minute: '2-digit',
})

const form = reactive({
  name: '',
  position: 1,
  starts_on: '',
  ends_on: '',
})

const classViewMode = ref<DataViewMode>('table')
const showAllClassRows = ref(false)
const showAllClassChartRows = ref(false)

const CLASS_CYCLE_ORDER: LevelCycle[] = ['maternel', 'primaire', 'cteb', 'secondaire']
const CLASS_PREVIEW_LIMIT = 11
const CLASS_CHART_PREVIEW_LIMIT = 7
const termViewMode = ref<DataViewMode>('grid')

const studentSearch = ref('')
const studentClassroomFilter = ref<string>('all')
const studentLevelFilter = ref<string>('all')
const studentStatusFilter = ref<string>('all')
const studentAverageFilter = ref<'all' | 'passing' | 'at_risk'>('all')
const studentPage = ref(1)
const studentPageSize = ref(10)

const LOW_GRADE_THRESHOLD = 10

const documentsClassroomId = ref<string>('')
const documentsTermId = ref<string>('')
const documentsStudentId = ref<string>('')
const attendanceFrom = ref('')
const attendanceTo = ref('')

const stats = computed(() => year.value?.stats ?? null)
const summary = computed(() => stats.value?.summary ?? null)
const terms = computed<Term[]>(() => year.value?.terms ?? [])

/** Terms du cycle primaire (trimestres) */
const termsPrimaire = computed<Term[]>(() =>
  terms.value.filter((t) => t.applicable_cycle === 'primaire' || !t.applicable_cycle)
)
/** Terms du cycle secondaire (semestres) */
const termsSecondaire = computed<Term[]>(() =>
  terms.value.filter((t) => t.applicable_cycle === 'secondaire')
)

/** Retourne "Trimestre" ou "Semestre" selon le term_type */
function termTypeLabel(term: Term): string {
  return term.term_type === 'semestre' ? 'Semestre' : 'Trimestre'
}

/** Préfixe de position : T1, T2... ou S1, S2... */
function termPositionLabel(term: Term): string {
  const prefix = term.term_type === 'semestre' ? 'S' : 'T'
  return `${prefix}${term.position}`
}

const termStats = computed<SchoolYearTermStats[]>(() => stats.value?.terms ?? [])
const classStats = computed<SchoolYearClassStats[]>(() => stats.value?.class_averages ?? [])
const classStatsWithAbsences = computed(() =>
  classStats.value.filter((c) => c.absences > 0 || c.lates > 0),
)
const studentRowsWithAbsences = computed(() =>
  studentRows.value.filter((s) => s.absences > 0 || s.lates > 0),
)

const statsByClassroomId = computed(
  () => new Map(classStats.value.map((row) => [row.classroom_id, row])),
)

const classDisplayRows = computed(() => {
  const rows: (SchoolYearClassStats & { is_base_only?: boolean })[] = []
  const seenClassroomIds = new Set<number>()

  for (const schoolClass of schoolClasses.value) {
    const divisions = schoolClass.divisions ?? []

    if (divisions.length === 0) {
      rows.push(buildEmptyClassRow({
        classroom_id: -schoolClass.id,
        classroom: schoolClass.name,
        class_code: schoolClass.name,
        school_class_id: schoolClass.id,
        cycle: schoolClass.level?.cycle ?? null,
        level_name: schoolClass.level?.name ?? null,
        option_name: schoolClass.school_option?.name ?? null,
        is_base_only: true,
      }))
      continue
    }

    for (const division of divisions) {
      seenClassroomIds.add(division.id)
      const stat = statsByClassroomId.value.get(division.id)
      rows.push(
        stat ?? buildEmptyClassRow({
          classroom_id: division.id,
          classroom: division.full_name,
          class_code: schoolClass.name,
          school_class_id: schoolClass.id,
          cycle: division.level?.cycle ?? schoolClass.level?.cycle ?? null,
          level_name: division.level?.name ?? schoolClass.level?.name ?? null,
          option_name: division.school_option?.name ?? schoolClass.school_option?.name ?? null,
          capacity: division.capacity ?? 40,
        }),
      )
    }
  }

  for (const stat of classStats.value) {
    if (!seenClassroomIds.has(stat.classroom_id)) {
      rows.push(stat)
    }
  }

  return rows
})
const monthlyAttendance = computed(() => stats.value?.monthly_attendance ?? [])
const studentRows = computed<SchoolYearStudentRow[]>(() => stats.value?.students ?? [])
const history = computed(() => stats.value?.history ?? null)

const isArchived = computed(() => !!year.value?.is_archived)
const isAdmin = computed(() => auth.hasRole('admin'))
const isGlobalAdmin = computed(
  () => auth.user?.role === 'admin' && (auth.user.admin_scope ?? 'global') === 'global',
)
const canWrite = computed(() => isAdmin.value && !isArchived.value)

const termStatsById = computed<Record<number, SchoolYearTermStats>>(() =>
  termStats.value.reduce<Record<number, SchoolYearTermStats>>((acc, item) => {
    acc[item.id] = item
    return acc
  }, {}),
)

const closedTermsRatio = computed(() => {
  const total = summary.value?.terms ?? 0
  if (total === 0) return 0
  return Math.round(((summary.value?.closed_terms ?? 0) / total) * 100)
})

const yearProgress = computed(() => {
  if (!year.value) return 0
  return progressBetween(year.value.starts_on, year.value.ends_on)
})

const monthlyAttendanceCategories = computed(() => monthlyAttendance.value.map((item) => item.label))

const monthlyAttendanceSeries = computed<ChartSeries[]>(() => [
  { name: 'Absences', data: monthlyAttendance.value.map((item) => item.absences) },
  { name: 'Retards', data: monthlyAttendance.value.map((item) => item.lates) },
])

const monthlyAttendanceIsFlat = computed(() =>
  monthlyAttendance.value.length > 0
  && monthlyAttendance.value.every((item) => item.absences === 0 && item.lates === 0),
)

const monthlyAttendanceRangeLabel = computed(() => {
  const rows = monthlyAttendance.value
  if (rows.length === 0) return ''
  if (rows.length === 1) return rows[0].label
  return `${rows[0].label} → ${rows[rows.length - 1].label}`
})

const visibleClassChartStats = computed(() =>
  showAllClassChartRows.value
    ? classStats.value
    : classStats.value.slice(0, CLASS_CHART_PREVIEW_LIMIT),
)

const hiddenClassChartCount = computed(() =>
  Math.max(0, classStats.value.length - CLASS_CHART_PREVIEW_LIMIT),
)

const classAverageCategories = computed(() =>
  visibleClassChartStats.value.map((item) => item.classroom),
)

const classAverageSeries = computed<ChartSeries[]>(() => [
  {
    name: 'Moyenne',
    data: visibleClassChartStats.value.map((item) =>
      chartPercentFromAverage20(item.grade_average),
    ),
  },
])

const classChartHeight = computed(() => {
  const count = visibleClassChartStats.value.length
  return Math.max(220, count * 48 + 72)
})

const classTableRows = computed(() => classDisplayRows.value)

const visibleClassRows = computed(() =>
  showAllClassRows.value
    ? classTableRows.value
    : classTableRows.value.slice(0, CLASS_PREVIEW_LIMIT),
)

const classTableGroups = computed(() =>
  CLASS_CYCLE_ORDER
    .map((cycle) => ({
      cycle,
      label: cycleGroupLabel(cycle),
      rows: visibleClassRows.value.filter((row) => row.cycle === cycle),
    }))
    .filter((group) => group.rows.length > 0),
)

const hiddenClassRowsCount = computed(() =>
  Math.max(0, classTableRows.value.length - CLASS_PREVIEW_LIMIT),
)

const classSummaryStats = computed(() => {
  const rows = classTableRows.value
  const students = rows.reduce((sum, row) => sum + row.student_count, 0)
  const graded = rows.filter((row) => row.grade_average !== null)
  const average = graded.length > 0
    ? graded.reduce((sum, row) => sum + (row.grade_average ?? 0), 0) / graded.length
    : null
  const now = new Date()
  const monthKey = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`
  const monthRow = monthlyAttendance.value.find((item) => item.value === monthKey)

  return {
    classes: rows.length,
    students,
    average,
    absencesMonth: monthRow?.absences ?? summary.value?.absences ?? 0,
    avgClassSize: rows.length > 0 ? students / rows.length : 0,
  }
})

const filteredStudents = computed<SchoolYearStudentRow[]>(() => {
  const q = studentSearch.value.trim().toLowerCase()
  return studentRows.value.filter((s) => {
    if (
      studentClassroomFilter.value !== 'all' &&
      String(s.classroom_id ?? '') !== studentClassroomFilter.value
    ) {
      return false
    }
    if (studentLevelFilter.value !== 'all' && (s.level ?? '') !== studentLevelFilter.value) {
      return false
    }
    if (studentStatusFilter.value !== 'all' && s.final_status !== studentStatusFilter.value) {
      return false
    }
    if (studentAverageFilter.value === 'passing') {
      if (s.grade_average === null || s.grade_average < LOW_GRADE_THRESHOLD) return false
    }
    if (studentAverageFilter.value === 'at_risk') {
      const lowGrade = s.grade_average !== null && s.grade_average < LOW_GRADE_THRESHOLD
      const highAbsences = s.unjustified_absences >= 3
      if (!lowGrade && !highAbsences) return false
    }
    if (q) {
      const haystack = `${s.full_name} ${s.registration_number ?? ''} ${s.classroom ?? ''}`.toLowerCase()
      if (!haystack.includes(q)) return false
    }
    return true
  })
})

const studentLevelOptions = computed(() =>
  [...new Set(studentRows.value.map((s) => s.level).filter(Boolean))].sort((a, b) =>
    String(a).localeCompare(String(b), 'fr'),
  ),
)

const studentSummaryStats = computed(() => {
  const now = new Date()
  const monthKey = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`
  const monthRow = monthlyAttendance.value.find((item) => item.value === monthKey)
  const graded = studentRows.value.filter((s) => s.grade_average !== null)
  const average = graded.length
    ? graded.reduce((sum, s) => sum + (s.grade_average ?? 0), 0) / graded.length
    : null

  return {
    total: studentRows.value.length,
    inProgress: decisionTotals.value.en_cours,
    average,
    absencesMonth: monthRow?.absences ?? summary.value?.absences ?? 0,
    atRisk: studentRows.value.filter((s) => {
      const lowGrade = s.grade_average !== null && s.grade_average < LOW_GRADE_THRESHOLD
      return lowGrade || s.unjustified_absences >= 3
    }).length,
  }
})

const studentTotalPages = computed(() =>
  Math.max(1, Math.ceil(filteredStudents.value.length / studentPageSize.value)),
)

const paginatedStudents = computed(() => {
  const start = (studentPage.value - 1) * studentPageSize.value
  return filteredStudents.value.slice(start, start + studentPageSize.value)
})

const studentGroups = computed(() => {
  const groups = new Map<
    string,
    {
      key: string
      classroom: string
      classroomId: number | null
      classAverage: number | null
      students: SchoolYearStudentRow[]
    }
  >()

  for (const student of paginatedStudents.value) {
    const key = student.classroom_id ? String(student.classroom_id) : 'none'
    if (!groups.has(key)) {
      const classStat = student.classroom_id
        ? statsByClassroomId.value.get(student.classroom_id)
        : undefined
      groups.set(key, {
        key,
        classroom: student.classroom ?? 'Sans classe',
        classroomId: student.classroom_id,
        classAverage: classStat?.grade_average ?? null,
        students: [],
      })
    }
    groups.get(key)!.students.push(student)
  }

  return [...groups.values()]
})

const classroomFilterOptions = computed(() => {
  const seen = new Map<number, string>()
  for (const row of classDisplayRows.value) {
    if (row.classroom_id > 0) {
      seen.set(row.classroom_id, row.classroom)
    }
  }
  for (const student of studentRows.value) {
    if (student.classroom_id && student.classroom) {
      seen.set(student.classroom_id, student.classroom)
    }
  }
  return [...seen.entries()]
    .map(([id, label]) => ({ id, label }))
    .sort((a, b) => a.label.localeCompare(b.label, 'fr'))
})

const decisionTotals = computed(() => {
  const totals: Record<SchoolYearFinalStatus, number> = { admis: 0, redouble: 0, en_cours: 0 }
  for (const s of studentRows.value) totals[s.final_status]++
  return totals
})

const successRateLabel = computed(() => {
  const value = summary.value?.success_rate
  return value === null || value === undefined ? '—' : `${value.toFixed(1).replace('.', ',')} %`
})

const yearStatusLabel = computed(() => {
  if (isArchived.value) return 'Archivée'
  if (year.value?.is_current) return 'En cours'
  const endsAt = year.value ? new Date(`${year.value.ends_on}T23:59:59`).getTime() : 0
  if (endsAt > 0 && endsAt < Date.now()) return 'Terminée'
  return 'Inactive'
})

const yearHeaderTitle = computed(() => {
  if (!year.value) return 'ANNÉE SCOLAIRE'
  return `ANNÉE SCOLAIRE ${year.value.name.replace('-', ' – ')}`
})

const yearDateSubtitle = computed(() => {
  if (!year.value) return ''
  return `${formatShortDate(year.value.starts_on)} → ${formatShortDate(year.value.ends_on)} · ${yearStatusLabel.value}`
})

const latesThisMonth = computed(() => {
  const now = new Date()
  const key = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`
  const row = monthlyAttendance.value.find((item) => item.value === key)
  return row?.lates ?? 0
})

const termProgressRows = computed(() =>
  terms.value.map((term) => {
    const progress = progressBetween(term.starts_on, term.ends_on)
    const phase = termActivityPhase(term)

    let status = 'En cours'
    let tone: 'success' | 'primary' | 'warn' | 'muted' = 'warn'
    if (phase === 'clôturé') {
      status = 'Clôturé'
      tone = progress >= 100 ? 'success' : 'primary'
    } else if (phase === 'terminé') {
      status = 'Terminé'
      tone = 'success'
    } else if (phase === 'à venir') {
      status = 'À venir'
      tone = 'muted'
    }

    const periodCount = term.periods?.length ?? 0
    const stat = termStat(term)
    let meta = formatTermActivityMeta(term)
    if (phase === 'clôturé') {
      meta = `${periodCount} période(s) · clôturé · ${(stat?.absences ?? 0) + (stat?.grades_entered ?? 0)} relevé(s)`
    }

    return {
      id: term.id,
      name: term.name,
      range: `${formatShortDate(term.starts_on)} – ${formatShortDate(term.ends_on)}`,
      progress,
      status,
      tone,
      meta,
    }
  }),
)

const sortedTermsOverview = computed(() =>
  [...terms.value].sort((a, b) => a.position - b.position),
)

const documentsTerms = computed(() => terms.value)
const documentsClassrooms = computed(() => classStats.value)
const documentsStudents = computed(() => {
  if (!documentsClassroomId.value) return studentRows.value
  return studentRows.value.filter(
    (s) => String(s.classroom_id ?? '') === documentsClassroomId.value,
  )
})

const tabItems = computed(() =>
  TABS.map((tab) => {
    let count: number | string | null = null
    if (tab.id === 'classes') count = classDisplayRows.value.length || schoolClasses.value.length
    if (tab.id === 'students') count = studentRows.value.length
    if (tab.id === 'results') count = summary.value?.evaluations ?? 0
    if (tab.id === 'attendance') count = summary.value?.absences ?? 0
    if (tab.id === 'documents') count = classStats.value.length + terms.value.length
    if (tab.id === 'history') count = isArchived.value ? 'ARCH' : null

    return { ...tab, count }
  }),
)

function formatDate(value: string): string {
  const date = new Date(`${value}T00:00:00`)
  if (Number.isNaN(date.getTime())) return value
  return dateFormatter.format(date)
}

function formatShortDate(value: string): string {
  const date = new Date(`${value}T00:00:00`)
  if (Number.isNaN(date.getTime())) return value
  const day = date.getDate()
  const month = new Intl.DateTimeFormat('fr-FR', { month: 'short' }).format(date).replace('.', '')
  const yearNum = date.getFullYear()
  const dayLabel = day === 1 ? '1er' : String(day)
  return `${dayLabel} ${month}. ${yearNum}`
}

function formatDateTime(value: string | null | undefined): string {
  if (!value) return '—'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return value
  return dateTimeFormatter.format(date)
}

function periodLabel(start: string, end: string): string {
  return `${formatDate(start)} - ${formatDate(end)}`
}

function isDateRangeActive(start: string, end: string): boolean {
  const today = new Date()
  today.setHours(0, 0, 0, 0)
  const startsAt = new Date(`${start}T00:00:00`)
  const endsAt = new Date(`${end}T23:59:59`)
  if (Number.isNaN(startsAt.getTime()) || Number.isNaN(endsAt.getTime())) return false

  return today >= startsAt && today <= endsAt
}

function isTermCalendarActive(term: Term): boolean {
  return !term.is_closed && isDateRangeActive(term.starts_on, term.ends_on)
}

function isPeriodCalendarActive(period: { starts_on: string; ends_on: string; is_closed?: boolean }): boolean {
  return !period.is_closed && isDateRangeActive(period.starts_on, period.ends_on)
}

function countActivePeriods(term: Term): number {
  return (term.periods ?? []).filter((period) => isPeriodCalendarActive(period)).length
}

function termActivityPhase(term: Term): 'clôturé' | 'en cours' | 'à venir' | 'terminé' {
  if (term.is_closed) return 'clôturé'

  if (countActivePeriods(term) > 0) return 'en cours'

  const now = Date.now()
  const endsAt = new Date(`${term.ends_on}T23:59:59`).getTime()
  const startsAt = new Date(`${term.starts_on}T00:00:00`).getTime()

  if (!Number.isNaN(endsAt) && endsAt < now) return 'terminé'
  if (!Number.isNaN(startsAt) && startsAt > now) return 'à venir'

  const periods = term.periods ?? []
  const hasUpcomingPeriod = periods.some((period) => {
    if (period.is_closed) return false
    const periodStart = new Date(`${period.starts_on}T00:00:00`).getTime()
    return !Number.isNaN(periodStart) && periodStart > now
  })
  if (hasUpcomingPeriod) return 'à venir'

  const allPeriodsEnded = periods.length > 0 && periods.every((period) => {
    if (period.is_closed) return true
    const periodEnd = new Date(`${period.ends_on}T23:59:59`).getTime()
    return !Number.isNaN(periodEnd) && periodEnd < now
  })
  if (allPeriodsEnded) return 'terminé'

  return 'à venir'
}

function formatTermActivityMeta(term: Term): string {
  const periodCount = term.periods?.length ?? 0
  const phase = termActivityPhase(term)

  if (phase === 'clôturé') {
    const stat = termStat(term)
    const activity = (stat?.absences ?? 0) + (stat?.grades_entered ?? 0) + (stat?.evaluations ?? 0)
    return `${periodCount} période(s) · clôturé · ${activity} activité(s)`
  }

  if (phase === 'en cours') {
    const active = countActivePeriods(term)
    return `${periodCount} période(s) · ${active} en cours`
  }

  return `${periodCount} période(s) · ${phase}`
}

function averageLabel(value: number | null | undefined): string {
  return formatAveragePercent(value, 1)
}

function termOverviewMeta(term: Term): string {
  return formatTermActivityMeta(term)
}

function termOverviewStatus(term: Term): { label: string; tone: string } {
  const phase = termActivityPhase(term)
  if (phase === 'clôturé') return { label: 'Clôturé', tone: 'success' }
  if (phase === 'terminé') return { label: 'Terminé', tone: 'success' }
  if (phase === 'à venir') return { label: 'À venir', tone: 'muted' }
  return { label: 'En cours', tone: 'warn' }
}

function classroomAverageTone(value: number | null | undefined): string {
  if (value === null || value === undefined) return 'muted'
  return value >= 10 ? 'good' : 'danger'
}

function cycleGroupLabel(cycle: LevelCycle): string {
  if (cycle === 'maternel') return 'Maternelle'
  if (cycle === 'primaire') return 'Primaire'
  if (cycle === 'cteb') return 'CTEB'
  return 'Secondaire'
}

function cycleBadgeLabel(cycle: LevelCycle | null | undefined): string {
  if (!cycle) return '—'
  return cycleGroupLabel(cycle)
}

function enrollmentPercent(row: SchoolYearClassStats): number {
  const capacity = row.capacity && row.capacity > 0 ? row.capacity : 40
  return Math.min(100, Math.round((row.student_count / capacity) * 100))
}

function buildEmptyClassRow(
  row: Partial<SchoolYearClassStats> &
    Pick<SchoolYearClassStats, 'classroom_id' | 'classroom'> & { is_base_only?: boolean },
): SchoolYearClassStats & { is_base_only?: boolean } {
  return {
    class_code: row.class_code ?? row.classroom,
    school_class_id: row.school_class_id ?? null,
    cycle: row.cycle ?? null,
    level_name: row.level_name ?? null,
    option_name: row.option_name ?? null,
    capacity: row.capacity ?? 40,
    student_count: 0,
    parent_count: 0,
    teacher_count: 0,
    subject_count: 0,
    evaluations: 0,
    grades_entered: 0,
    grade_average: null,
    attendance_records: 0,
    absences: 0,
    lates: 0,
    is_base_only: row.is_base_only,
    ...row,
  }
}

function statusLabel(status: SchoolYearFinalStatus): string {
  if (status === 'admis') return 'Admis'
  if (status === 'redouble') return 'Redouble'
  return 'En cours'
}

function studentInitials(name: string): string {
  return name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part.charAt(0).toUpperCase())
    .join('')
}

function studentOption(student: SchoolYearStudentRow): string {
  if (!student.classroom_id) return '—'
  const stat = statsByClassroomId.value.get(student.classroom_id)
  return stat?.option_name ?? '—'
}

function studentGroupTone(classAverage: number | null): string {
  if (classAverage === null) return 'muted'
  return classAverage >= LOW_GRADE_THRESHOLD ? 'good' : 'danger'
}

function studentRowTone(student: SchoolYearStudentRow): string {
  if (student.grade_average !== null && student.grade_average < LOW_GRADE_THRESHOLD) return 'danger'
  if (student.unjustified_absences >= 3) return 'warn'
  return ''
}

function resetStudentPage(): void {
  studentPage.value = 1
}

function exportStudentsCsv(): void {
  const headers = ['Nom', 'Matricule', 'Classe', 'Niveau', 'Moyenne', 'Statut', 'Absences', 'Non justifiées']
  const lines = filteredStudents.value.map((s) => [
    s.full_name,
    s.registration_number ?? '',
    s.classroom ?? '',
    s.level ?? '',
    s.grade_average ?? '',
    statusLabel(s.final_status),
    String(s.absences),
    String(s.unjustified_absences),
  ])
  const csv = [headers, ...lines]
    .map((row) => row.map((cell) => `"${String(cell).replace(/"/g, '""')}"`).join(';'))
    .join('\n')
  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' })
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = `eleves-${year.value?.name ?? 'annee'}.csv`
  link.click()
  URL.revokeObjectURL(url)
}

function printStudents(): void {
  window.print()
}

function progressBetween(start: string, end: string): number {
  const startsAt = new Date(`${start}T00:00:00`).getTime()
  const endsAt = new Date(`${end}T23:59:59`).getTime()
  const now = Date.now()
  if (Number.isNaN(startsAt) || Number.isNaN(endsAt) || endsAt <= startsAt) return 0
  if (now <= startsAt) return 0
  if (now >= endsAt) return 100
  return Math.round(((now - startsAt) / (endsAt - startsAt)) * 100)
}

function termStat(term: Term): SchoolYearTermStats | undefined {
  return termStatsById.value[term.id]
}

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  showAllClassChartRows.value = false
  try {
    const res = await api<ApiResource<SchoolYear>>(`/api/v1/school-years/${props.id}`)
    year.value = res.data
    await loadSchoolClasses()
  } catch (err) {
    error.value = err instanceof ApiError ? err.message : 'Année introuvable.'
  } finally {
    loading.value = false
  }
}

async function loadSchoolClasses(): Promise<void> {
  if (!year.value) {
    schoolClasses.value = []
    return
  }
  const res = await api<Paginated<SchoolClass>>(
    `/api/v1/school-years/${year.value.id}/school-classes`,
  )
  schoolClasses.value = res.data
}

async function generateSchoolClasses(): Promise<void> {
  if (!year.value || !canWrite.value) return
  generatingClasses.value = true
  error.value = ''
  try {
    await api(`/api/v1/school-years/${year.value.id}/generate-classes`, { method: 'POST' })
    await load()
  } catch (err) {
    error.value = err instanceof ApiError ? err.message : 'Génération des classes impossible.'
  } finally {
    generatingClasses.value = false
  }
}

function openEdit(term: Term): void {
  if (!canWrite.value) return
  editing.value = term
  form.name = term.name
  form.position = term.position
  form.starts_on = term.starts_on
  form.ends_on = term.ends_on
  formError.value = ''
  Object.keys(formErrors).forEach((k) => delete formErrors[k])
  showForm.value = true
}

async function submit(): Promise<void> {
  if (!year.value || !canWrite.value) return
  submitting.value = true
  formError.value = ''
  Object.keys(formErrors).forEach((k) => delete formErrors[k])

  const payload = {
    school_year_id: year.value.id,
    name: form.name,
    position: form.position,
    starts_on: form.starts_on,
    ends_on: form.ends_on,
  }

  try {
    if (editing.value) {
      await api<ApiResource<Term>>(`/api/v1/terms/${editing.value.id}`, {
        method: 'PUT',
        body: payload,
      })
    } else {
      await api<ApiResource<Term>>('/api/v1/terms', { method: 'POST', body: payload })
    }
    showForm.value = false
    await load()
  } catch (err) {
    if (err instanceof ApiError) {
      formError.value = err.message
      if (err.errors) Object.assign(formErrors, err.errors)
    } else {
      formError.value = 'Erreur réseau.'
    }
  } finally {
    submitting.value = false
  }
}

async function remove(term: Term): Promise<void> {
  if (!canWrite.value) return
  const ok = await confirmDialog.ask({
    title: `Supprimer un ${termTypeLabel(term).toLowerCase()}`,
    message: `Ce ${termTypeLabel(term).toLowerCase()} sera supprimé.`,
    details: [term.name],
    note: `Les données associées à ce ${termTypeLabel(term).toLowerCase()} peuvent être impactées.`,
    confirmLabel: 'Supprimer',
    variant: 'danger',
  })
  if (!ok) return
  try {
    await api(`/api/v1/terms/${term.id}`, { method: 'DELETE' })
    await load()
  } catch (err) {
    error.value = err instanceof ApiError ? err.message : 'Suppression impossible.'
  }
}

async function closeTerm(term: Term): Promise<void> {
  if (!canWrite.value) return
  const ok = await confirmDialog.ask({
    title: `Clôturer le ${termTypeLabel(term).toLowerCase()}`,
    message: `Clôturer ce ${termTypeLabel(term).toLowerCase()} et envoyer les bulletins PDF aux parents ?`,
    details: [term.name],
    note: 'Cette action ne peut pas être annulée facilement.',
    confirmLabel: 'Clôturer',
    variant: 'warning',
  })
  if (!ok) return
  closing.value = term.id
  try {
    const res = await api<{ message: string; students_notified: number; parents_notified: number }>(
      `/api/v1/terms/${term.id}/close`,
      { method: 'POST' },
    )
    toast.success(res.message)
    await load()
  } catch (err) {
    error.value = err instanceof ApiError ? err.message : 'Clôture impossible.'
  } finally {
    closing.value = null
  }
}

function openCreatePeriod(term: Term): void {
  if (!canWrite.value || term.is_closed) return
  periodTerm.value = term
  editingPeriod.value = null
  showPeriodForm.value = true
}

function openEditPeriod(term: Term, period: Period): void {
  if (!canWrite.value || term.is_closed) return
  periodTerm.value = term
  editingPeriod.value = period
  showPeriodForm.value = true
}

async function onPeriodSaved(): Promise<void> {
  showPeriodForm.value = false
  periodTerm.value = null
  editingPeriod.value = null
  await load()
}

async function removePeriod(period: Period): Promise<void> {
  if (!canWrite.value) return
  const ok = await confirmDialog.ask({
    title: 'Supprimer une période',
    message: 'Cette période sera supprimée si aucune évaluation n’y est rattachée.',
    details: [period.name],
    note: 'Les évaluations doivent conserver une période valide.',
    confirmLabel: 'Supprimer',
    variant: 'danger',
  })
  if (!ok) return
  try {
    await api(`/api/v1/periods/${period.id}`, { method: 'DELETE' })
    await load()
  } catch (err) {
    error.value = err instanceof ApiError ? err.message : 'Suppression impossible.'
  }
}

async function closePeriod(period: Period): Promise<void> {
  if (!canWrite.value) return
  const ok = await confirmDialog.ask({
    title: 'Clôturer la période',
    message: 'Clôturer cette période et verrouiller la saisie de notes associée ?',
    details: [period.name],
    note: 'Le bulletin reste trimestriel.',
    confirmLabel: 'Clôturer',
    variant: 'warning',
  })
  if (!ok) return
  closingPeriod.value = period.id
  try {
    await api(`/api/v1/periods/${period.id}/close`, { method: 'POST' })
    await load()
  } catch (err) {
    error.value = err instanceof ApiError ? err.message : 'Clôture impossible.'
  } finally {
    closingPeriod.value = null
  }
}

async function archiveYear(): Promise<void> {
  if (!year.value || !isAdmin.value || isArchived.value) return
  const ok = await confirmDialog.ask({
    title: 'Archiver une année scolaire',
    message: 'Cette année passera en lecture seule.',
    details: [year.value.name],
    note: 'Trimestres, notes et présences ne pourront plus être modifiés. Un administrateur pourra annuler l’archivage.',
    confirmLabel: 'Archiver',
    variant: 'warning',
  })
  if (!ok) return
  archiving.value = true
  try {
    const res = await api<ApiResource<SchoolYear>>(
      `/api/v1/school-years/${year.value.id}/archive`,
      { method: 'POST' },
    )
    year.value = res.data
    await load()
  } catch (err) {
    error.value = err instanceof ApiError ? err.message : 'Archivage impossible.'
  } finally {
    archiving.value = false
  }
}

async function unarchiveYear(): Promise<void> {
  if (!year.value || !isAdmin.value || !isArchived.value) return
  const ok = await confirmDialog.ask({
    title: 'Désarchiver une année scolaire',
    message: 'Cette année scolaire redeviendra modifiable.',
    details: [year.value.name],
    confirmLabel: 'Désarchiver',
    variant: 'warning',
  })
  if (!ok) return
  archiving.value = true
  try {
    const res = await api<ApiResource<SchoolYear>>(
      `/api/v1/school-years/${year.value.id}/unarchive`,
      { method: 'POST' },
    )
    year.value = res.data
    await load()
  } catch (err) {
    error.value = err instanceof ApiError ? err.message : 'Désarchivage impossible.'
  } finally {
    archiving.value = false
  }
}

async function downloadFile(url: string, filename: string): Promise<void> {
  const token = getToken()
  if (!token) {
    error.value = 'Authentification requise pour le téléchargement.'
    return
  }
  try {
    const res = await fetch(url, { headers: { Authorization: `Bearer ${token}` } })
    if (!res.ok) throw new Error('Téléchargement impossible.')
    const blob = await res.blob()
    const link = document.createElement('a')
    link.href = URL.createObjectURL(blob)
    link.download = filename
    link.click()
    URL.revokeObjectURL(link.href)
  } catch {
    error.value = 'Téléchargement impossible.'
  }
}

function downloadBulletinPdf(): void {
  const sId = documentsStudentId.value
  const tId = documentsTermId.value
  if (!sId || !tId) return
  const student = studentRows.value.find((s) => String(s.id) === sId)
  const term = terms.value.find((t) => String(t.id) === tId)
  void downloadFile(
    `/api/v1/students/${sId}/report-cards/${tId}/pdf`,
    `bulletin-${student?.full_name ?? sId}-${term?.name ?? tId}.pdf`,
  )
}

function downloadRankingCsv(): void {
  const cId = documentsClassroomId.value
  const tId = documentsTermId.value
  if (!cId || !tId) return
  const cls = classStats.value.find((c) => String(c.classroom_id) === cId)
  const term = terms.value.find((t) => String(t.id) === tId)
  void downloadFile(
    `/api/v1/reports/classrooms/${cId}/ranking/${tId}/csv`,
    `classement-${cls?.classroom ?? 'classe'}-${term?.name ?? 'trim'}.csv`,
  )
}

function downloadAttendanceCsv(): void {
  const params = new URLSearchParams()
  if (year.value) params.set('school_year_id', String(year.value.id))
  if (attendanceFrom.value) params.set('from', attendanceFrom.value)
  else if (year.value) params.set('from', year.value.starts_on)
  if (attendanceTo.value) params.set('to', attendanceTo.value)
  else if (year.value) params.set('to', year.value.ends_on)
  void downloadFile(
    `/api/v1/reports/attendance/csv?${params.toString()}`,
    `absenteisme-${year.value?.name ?? 'annee'}.csv`,
  )
}

function downloadEvolutionCsv(): void {
  if (!documentsStudentId.value || !year.value) return
  const student = studentRows.value.find((s) => String(s.id) === documentsStudentId.value)
  void downloadFile(
    `/api/v1/reports/students/${documentsStudentId.value}/evolution/csv?school_year_id=${year.value.id}`,
    `evolution-${student?.full_name ?? documentsStudentId.value}.csv`,
  )
}

watch([studentSearch, studentClassroomFilter, studentLevelFilter, studentStatusFilter, studentAverageFilter, studentPageSize], () => {
  resetStudentPage()
})

onMounted(() => {
  const tab = resolveTabFromQuery(route.query.tab)
  if (tab) activeTab.value = tab
  void load()
})
</script>

<template>
  <section class="school-year-detail-page">
    <nav class="detail-breadcrumb" aria-label="Fil d'Ariane">
      <RouterLink :to="{ name: 'school-years' }">Années scolaires</RouterLink>
      <span aria-hidden="true">/</span>
      <strong>{{ year?.name ?? 'Détail' }}</strong>
    </nav>

    <p v-if="error" class="alert alert-error">{{ error }}</p>

    <div v-if="loading && !year" class="detail-skeleton">
      <div class="skeleton-block hero" />
      <div class="skeleton-grid">
        <div v-for="i in 4" :key="i" class="skeleton-block" />
      </div>
    </div>

    <template v-else-if="year">
      <!-- Hero -->
      <div class="detail-hero" :class="{ archived: isArchived, current: year.is_current && !isArchived }">
        <div class="detail-hero-main">
          <h1>{{ yearHeaderTitle }}</h1>
          <p class="hero-date-line">{{ yearDateSubtitle }}</p>
          <div class="hero-badges" aria-label="Indicateurs principaux">
            <span v-if="year.is_current && !isArchived" class="hero-badge primary">Année courante</span>
            <span v-else-if="isArchived" class="hero-badge warn">Archivée</span>
            <span class="hero-badge">{{ summary?.students ?? 0 }} élèves</span>
            <span class="hero-badge">{{ summary?.classes ?? 0 }} classes</span>
            <span class="hero-badge">{{ summary?.parents ?? 0 }} parents</span>
          </div>
        </div>
        <div class="hero-side">
          <div class="hero-actions">
            <button
              v-if="isAdmin && !isArchived"
              type="button"
              class="btn-secondary archive-btn"
              :disabled="archiving"
              @click="archiveYear"
            >
              {{ archiving ? 'Archivage…' : "Archiver l'année" }}
            </button>
            <button
              v-else-if="isAdmin && isArchived"
              type="button"
              class="btn-secondary archive-btn"
              :disabled="archiving"
              @click="unarchiveYear"
            >
              {{ archiving ? 'Désarchivage…' : 'Désarchiver' }}
            </button>
          </div>
          <div class="hero-status-card" aria-label="Suivi de l'année">
            <div class="hero-status-row">
              <span>Avancement calendrier</span>
              <strong>{{ yearProgress }}%</strong>
            </div>
            <div class="progress-track hero-progress">
              <span :style="{ width: `${yearProgress}%` }" />
            </div>
            <div class="hero-status-row">
              <span>Termes clôturés</span>
              <strong>{{ summary?.closed_terms ?? 0 }} / {{ summary?.terms ?? 0 }}</strong>
            </div>
            <div class="hero-status-row">
              <span>Périodes clôturées</span>
              <strong>{{ summary?.closed_periods ?? 0 }} / {{ summary?.periods ?? 0 }}</strong>
            </div>
          </div>
        </div>
      </div>

      <!-- Read-only banner if archived -->
      <div v-if="isArchived" class="archived-banner" role="status">
        <div class="archived-icon" aria-hidden="true">LS</div>
        <strong>Année archivée — lecture seule</strong>
      </div>

      <!-- Tab navigation -->
      <nav class="year-tabs" role="tablist" aria-label="Sections de l'année scolaire">
        <button
          v-for="tab in tabItems"
          :key="tab.id"
          type="button"
          role="tab"
          :aria-selected="activeTab === tab.id"
          :class="{ active: activeTab === tab.id }"
          :title="tab.description"
          @click="activeTab = tab.id"
        >
          <span>{{ tab.label }}</span>
          <small v-if="tab.count !== null">{{ tab.count }}</small>
        </button>
      </nav>

      <!-- ───────── Résumé ───────── -->
      <div v-show="activeTab === 'overview'" role="tabpanel" class="tab-panel">
        <div class="kpi-groups">
          <section class="kpi-group">
            <div class="kpi-group-title"><span>Identité de l'année</span></div>
            <div class="kpi-grid identity">
              <div class="kpi-card">
                <span class="kpi-label">Année scolaire</span>
                <strong>{{ year.name.replace('-', '–') }}</strong>
                <span>{{ formatShortDate(year.starts_on) }} → {{ formatShortDate(year.ends_on) }}</span>
              </div>
              <div class="kpi-card highlight">
                <span class="kpi-label">Statut</span>
                <strong>
                  <template v-if="isArchived">Archivée</template>
                  <template v-else-if="year.is_current">Courante</template>
                  <template v-else>Inactive</template>
                </strong>
                <span>
                  {{ summary?.closed_terms ?? 0 }} / {{ summary?.terms ?? 0 }} terme(s) clôturé(s)
                </span>
              </div>
              <div class="kpi-card">
                <span class="kpi-label">Élèves inscrits</span>
                <strong>{{ summary?.students ?? 0 }}</strong>
                <span>{{ summary?.classes ?? 0 }} classes</span>
              </div>
              <div class="kpi-card">
                <span class="kpi-label">Taux de réussite</span>
                <strong>{{ successRateLabel }}</strong>
                <span>{{ summary?.students_passing ?? 0 }} / {{ summary?.students_evaluated ?? 0 }} évalués</span>
              </div>
            </div>
          </section>

          <section class="kpi-group">
            <div class="kpi-group-title"><span>Activité pédagogique</span></div>
            <div class="kpi-grid performance">
              <div class="kpi-card">
                <span class="kpi-label">Évaluations</span>
                <strong>{{ summary?.evaluations ?? 0 }}</strong>
                <span>{{ summary?.grades_entered ?? 0 }} notes</span>
              </div>
              <div class="kpi-card">
                <span class="kpi-label">Moyenne générale</span>
                <strong>{{ averageLabel(summary?.grade_average) }}</strong>
                <span>/20</span>
              </div>
              <div class="kpi-card warn">
                <span class="kpi-label">Absences</span>
                <strong>{{ summary?.absences ?? 0 }}</strong>
                <span>{{ summary?.unjustified_absences ?? 0 }} non justifiées</span>
              </div>
              <div class="kpi-card">
                <span class="kpi-label">Retards</span>
                <strong>{{ summary?.lates ?? 0 }}</strong>
                <span>{{ latesThisMonth }} ce mois</span>
              </div>
            </div>
          </section>
        </div>

        <div class="analytics-grid">
          <div class="card progress-card">
            <div class="card-header">
              <h2>Progression annuelle</h2>
            </div>
            <div class="progress-body">
              <div class="term-progress-item global">
                <div class="term-progress-head">
                  <span>Calendrier global</span>
                  <strong>{{ yearProgress }}%</strong>
                </div>
                <div class="progress-track slim">
                  <span class="fill-primary" :style="{ width: `${yearProgress}%` }" />
                </div>
              </div>

              <div v-for="row in termProgressRows" :key="row.id" class="term-progress-item">
                <div class="term-progress-head">
                  <div>
                    <strong>{{ row.name }}</strong>
                    <small>{{ row.range }}</small>
                  </div>
                  <span class="term-status-badge" :class="row.tone">{{ row.status }}</span>
                </div>
                <div class="progress-track slim">
                  <span :class="`fill-${row.tone}`" :style="{ width: `${row.progress}%` }" />
                </div>
                <p class="term-progress-meta">{{ row.meta }}</p>
              </div>
            </div>
          </div>

          <div class="card chart-card">
            <div class="card-header">
              <h2>Assiduité mensuelle</h2>
            </div>
            <div v-if="monthlyAttendance.length" class="chart-surface">
              <LineChart
                v-if="!monthlyAttendanceIsFlat"
                :series="monthlyAttendanceSeries"
                :categories="monthlyAttendanceCategories"
                :height="240"
              />
              <div v-else class="chart-empty-compact">
                <p>Aucune absence ni retard enregistré sur l'année.</p>
                <span class="chart-empty-range">{{ monthlyAttendanceRangeLabel }}</span>
              </div>
            </div>
            <div v-else class="empty-state">Aucune donnée d'assiduité.</div>
          </div>
        </div>

        <section v-if="sortedTermsOverview.length" class="kpi-group terms-overview">
          <div class="kpi-group-title"><span>Termes &amp; périodes</span></div>
          <div class="card terms-table-card">
            <table>
              <thead>
                <tr>
                  <th>Terme</th>
                  <th>Période</th>
                  <th>Activité</th>
                  <th>Statut</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="term in sortedTermsOverview" :key="term.id">
                  <td><strong>{{ term.name }}</strong></td>
                  <td>{{ formatShortDate(term.starts_on) }} → {{ formatShortDate(term.ends_on) }}</td>
                  <td>{{ termOverviewMeta(term) }}</td>
                  <td>
                    <span class="badge" :class="`badge-${termOverviewStatus(term).tone}`">
                      {{ termOverviewStatus(term).label }}
                    </span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>
      </div>

      <!-- ───────── Classes ───────── -->
      <div v-show="activeTab === 'classes'" role="tabpanel" class="tab-panel">
        <section class="classes-section">
          <div class="section-heading class-section-heading">
            <h2>Classes de l'année</h2>
            <div class="section-toolbar">
              <div class="view-toggle" aria-label="Affichage des classes">
                <button
                  type="button"
                  title="Vue grille"
                  :class="{ active: classViewMode === 'grid' }"
                  @click="classViewMode = 'grid'"
                >
                  <span class="toggle-icon toggle-icon-grid" aria-hidden="true"></span>
                  Grille
                </button>
                <button
                  type="button"
                  title="Vue tableau"
                  :class="{ active: classViewMode === 'table' }"
                  @click="classViewMode = 'table'"
                >
                  <span class="toggle-icon toggle-icon-list" aria-hidden="true"></span>
                  Tableau
                </button>
              </div>
              <button
                v-if="canWrite"
                type="button"
                class="btn-secondary"
                :disabled="generatingClasses"
                @click="generateSchoolClasses"
              >
                {{ generatingClasses ? 'Génération…' : 'Générer les classes de base' }}
              </button>
            </div>
          </div>

          <div v-if="classDisplayRows.length" class="class-summary-bar">
            <div class="class-summary-item">
              <strong>{{ classSummaryStats.classes }}</strong>
              <span>Classes</span>
            </div>
            <div class="class-summary-item">
              <strong>{{ classSummaryStats.students }}</strong>
              <span>Élèves</span>
            </div>
            <div class="class-summary-item good">
              <strong>{{ averageLabel(classSummaryStats.average) }}</strong>
              <span>Moy. générale</span>
            </div>
            <div class="class-summary-item warn">
              <strong>{{ classSummaryStats.absencesMonth }}</strong>
              <span>Absences (mois)</span>
            </div>
            <div class="class-summary-item">
              <strong>{{ classSummaryStats.avgClassSize.toFixed(1).replace('.', ',') }}</strong>
              <span>Effectif moyen</span>
            </div>
          </div>

          <div v-if="schoolClasses.length === 0" class="card empty-state">
            Aucune classe de base générée pour cette année.
            <button
              v-if="canWrite"
              type="button"
              class="btn-secondary"
              :disabled="generatingClasses"
              @click="generateSchoolClasses"
            >
              Générer les classes de base
            </button>
          </div>

          <div v-else-if="schoolClasses.length > 0 && classDisplayRows.length === 0" class="card empty-state">
            Aucune classe à afficher pour cette année.
          </div>

          <template v-else-if="classViewMode === 'grid'">
            <div class="class-data-grid">
              <RouterLink
                v-for="item in visibleClassRows"
                :key="item.classroom_id"
                class="class-data-card class-data-link"
                :to="{
                  name: 'school-year-class-detail',
                  params: { id: props.id, classroomId: item.classroom_id },
                }"
                :title="`Voir les détails de ${item.classroom}`"
              >
                <div class="class-data-top">
                  <div>
                    <h3>{{ item.classroom }}</h3>
                    <p>{{ cycleBadgeLabel(item.cycle) }} · {{ item.level_name ?? '—' }}</p>
                  </div>
                  <span class="cycle-badge" :class="item.cycle ? `cycle-${item.cycle}` : ''">
                    {{ cycleBadgeLabel(item.cycle) }}
                  </span>
                </div>
                <div class="class-data-metrics">
                  <span><strong>{{ item.student_count }}</strong> Élèves</span>
                  <span><strong>{{ averageLabel(item.grade_average) }}</strong> Moyenne</span>
                  <span><strong>{{ item.absences }}</strong> Absences</span>
                </div>
              </RouterLink>
            </div>
          </template>

          <div v-else class="card class-table-card grouped-class-table">
            <table>
              <thead>
                <tr>
                  <th>Classe</th>
                  <th>Cycle</th>
                  <th>Niveau</th>
                  <th>Option</th>
                  <th>Effectif</th>
                  <th>Moy.</th>
                  <th>Absences</th>
                </tr>
              </thead>
              <tbody>
                <template v-for="group in classTableGroups" :key="group.cycle">
                  <tr class="cycle-group-row">
                    <td colspan="7">
                      <strong>{{ group.label.toUpperCase() }}</strong>
                      <span>{{ group.rows.length }} classe(s)</span>
                    </td>
                  </tr>
                  <tr v-for="item in group.rows" :key="item.classroom_id">
                    <td class="class-link-cell">
                      <RouterLink
                        v-if="item.classroom_id > 0"
                        class="class-table-link"
                        :to="{
                          name: 'school-year-class-detail',
                          params: { id: props.id, classroomId: item.classroom_id },
                        }"
                      >
                        <strong>{{ item.class_code ?? item.classroom }}</strong>
                      </RouterLink>
                      <strong v-else>{{ item.class_code ?? item.classroom }}</strong>
                    </td>
                    <td>
                      <span v-if="item.cycle" class="cycle-badge" :class="`cycle-${item.cycle}`">
                        {{ cycleBadgeLabel(item.cycle) }}
                      </span>
                      <span v-else class="text-muted">—</span>
                    </td>
                    <td>{{ item.level_name ?? '—' }}</td>
                    <td>{{ item.option_name ?? '—' }}</td>
                    <td class="enrollment-cell">
                      <div class="enrollment-bar-wrap">
                        <div class="enrollment-bar-track">
                          <span class="enrollment-bar-fill" :style="{ width: `${enrollmentPercent(item)}%` }" />
                        </div>
                        <strong>{{ item.student_count }}</strong>
                      </div>
                    </td>
                    <td>
                      <span class="class-average" :class="classroomAverageTone(item.grade_average)">
                        {{ averageLabel(item.grade_average) }}
                      </span>
                    </td>
                    <td>{{ item.absences }}</td>
                  </tr>
                </template>
              </tbody>
            </table>
          </div>

          <footer v-if="classDisplayRows.length" class="class-footer-bar">
            <div class="class-footer-stats">
              <span>{{ classTableRows.length }} classes au total</span>
              <span>{{ summary?.students ?? 0 }} élèves inscrits</span>
              <span>{{ summary?.assigned_teachers ?? 0 }} enseignants</span>
              <span>{{ summary?.assigned_subjects ?? 0 }} cours configurés</span>
            </div>
            <div class="class-footer-actions">
              <span>Affichage {{ Math.min(visibleClassRows.length, classTableRows.length) }} / {{ classTableRows.length }}</span>
              <button
                v-if="hiddenClassRowsCount > 0 && !showAllClassRows"
                type="button"
                class="link-btn"
                @click="showAllClassRows = true"
              >
                Voir toutes les classes →
              </button>
            </div>
          </footer>
        </section>
      </div>

      <!-- ───────── Élèves ───────── -->
      <div v-show="activeTab === 'students'" role="tabpanel" class="tab-panel students-tab">
        <section class="students-section">
          <div class="section-heading students-heading">
            <h2>Élèves inscrits</h2>
            <div class="section-toolbar students-actions">
              <button type="button" class="btn-secondary" @click="exportStudentsCsv">
                Exporter
              </button>
              <button type="button" class="btn-secondary" @click="printStudents">
                Imprimer
              </button>
              <RouterLink
                v-if="canWrite"
                class="btn-primary enroll-btn"
                :to="{ name: 'students' }"
              >
                Inscrire un élève
              </RouterLink>
            </div>
          </div>

          <div v-if="studentRows.length" class="class-summary-bar students-summary-bar">
            <div class="class-summary-item">
              <strong>{{ studentSummaryStats.total }}</strong>
              <span>Inscrits</span>
            </div>
            <div class="class-summary-item good">
              <strong>{{ studentSummaryStats.inProgress }}</strong>
              <span>Statut en cours</span>
            </div>
            <div class="class-summary-item good">
              <strong>{{ averageLabel(studentSummaryStats.average) }}</strong>
              <span>Moy. générale</span>
            </div>
            <div class="class-summary-item warn">
              <strong>{{ studentSummaryStats.absencesMonth }}</strong>
              <span>Absences (mois)</span>
            </div>
            <div class="class-summary-item danger">
              <strong>{{ studentSummaryStats.atRisk }}</strong>
              <span>Élèves à risque</span>
            </div>
          </div>

          <div class="student-filters-panel">
            <label class="class-filter compact student-search-field">
              <span aria-hidden="true">Recherche</span>
              <input
                v-model="studentSearch"
                type="search"
                placeholder="Nom, matricule…"
                aria-label="Rechercher un élève"
              />
            </label>
            <label class="class-filter compact">
              <span>Classe</span>
              <select v-model="studentClassroomFilter">
                <option value="all">Toutes les classes</option>
                <option
                  v-for="item in classroomFilterOptions"
                  :key="item.id"
                  :value="String(item.id)"
                >
                  {{ item.label }}
                </option>
              </select>
            </label>
            <label class="class-filter compact">
              <span>Niveau</span>
              <select v-model="studentLevelFilter">
                <option value="all">Tous les niveaux</option>
                <option v-for="level in studentLevelOptions" :key="String(level)" :value="level">
                  {{ level }}
                </option>
              </select>
            </label>
            <label class="class-filter compact">
              <span>Statut</span>
              <select v-model="studentStatusFilter">
                <option value="all">Tous</option>
                <option value="en_cours">En cours</option>
                <option value="admis">Admis</option>
                <option value="redouble">Redouble</option>
              </select>
            </label>
            <label class="class-filter compact">
              <span>Moyenne</span>
              <select v-model="studentAverageFilter">
                <option value="all">Toutes</option>
                <option value="passing">≥ 10</option>
                <option value="at_risk">À surveiller</option>
              </select>
            </label>
          </div>

          <div v-if="year.is_current" class="active-filter-chips">
            <span class="filter-chip active">Année courante</span>
          </div>

          <div v-if="studentRows.length === 0" class="card empty-state">
            Aucun élève rattaché aux classes de cette année.
          </div>

          <div v-else-if="filteredStudents.length === 0" class="card empty-state">
            Aucun élève ne correspond aux filtres sélectionnés.
          </div>

          <div v-else class="card students-table-card grouped-students-table">
            <table>
              <thead>
                <tr>
                  <th>Élève</th>
                  <th>Matricule</th>
                  <th>Classe</th>
                  <th>Niveau</th>
                  <th>Option</th>
                  <th>Moyenne</th>
                  <th>Statut</th>
                  <th>Absences</th>
                </tr>
              </thead>
              <tbody>
                <template v-for="group in studentGroups" :key="group.key">
                  <tr class="student-group-row" :class="studentGroupTone(group.classAverage)">
                    <td :colspan="8">
                      <strong>{{ group.classroom.toUpperCase() }}</strong>
                      <span>· {{ group.students.length }} élève(s)</span>
                      <span v-if="group.classAverage !== null">
                        · moy. {{ averageLabel(group.classAverage) }}
                      </span>
                    </td>
                  </tr>
                  <tr
                    v-for="student in group.students"
                    :key="student.id"
                    class="student-data-row"
                    :class="studentRowTone(student)"
                  >
                    <td>
                      <RouterLink
                        class="student-cell-link"
                        :to="{ name: 'student-detail', params: { id: student.id } }"
                      >
                        <span class="student-avatar" aria-hidden="true">{{ studentInitials(student.full_name) }}</span>
                        <span class="student-name-block">
                          <strong>{{ student.full_name }}</strong>
                          <small>{{ student.registration_number ?? '—' }}</small>
                        </span>
                      </RouterLink>
                    </td>
                    <td>{{ student.registration_number ?? '—' }}</td>
                    <td>{{ student.classroom ?? '—' }}</td>
                    <td>{{ student.level ?? '—' }}</td>
                    <td>{{ studentOption(student) }}</td>
                    <td>
                      <span class="class-average" :class="classroomAverageTone(student.grade_average)">
                        {{ averageLabel(student.grade_average) }}
                      </span>
                    </td>
                    <td>
                      <span class="status-pill" :class="`status-${student.final_status}`">
                        {{ statusLabel(student.final_status) }}
                      </span>
                    </td>
                    <td>
                      {{ student.absences }} / {{ student.unjustified_absences }} nj
                    </td>
                  </tr>
                </template>
              </tbody>
            </table>
          </div>

          <footer v-if="filteredStudents.length" class="students-footer-bar">
            <span>
              Affichage de {{ paginatedStudents.length }} sur {{ filteredStudents.length }} élève(s)
            </span>
            <div class="students-pagination">
              <label class="page-size-select">
                <span>Lignes</span>
                <select v-model.number="studentPageSize">
                  <option :value="10">10</option>
                  <option :value="25">25</option>
                  <option :value="50">50</option>
                </select>
              </label>
              <button
                type="button"
                class="page-btn"
                :disabled="studentPage <= 1"
                @click="studentPage -= 1"
              >
                ‹
              </button>
              <span>{{ studentPage }} / {{ studentTotalPages }}</span>
              <button
                type="button"
                class="page-btn"
                :disabled="studentPage >= studentTotalPages"
                @click="studentPage += 1"
              >
                ›
              </button>
            </div>
          </footer>
        </section>
      </div>

      <!-- ───────── Résultats ───────── -->
      <div v-show="activeTab === 'results'" role="tabpanel" class="tab-panel">
        <section class="results-section">
          <div v-if="isGlobalAdmin && year" class="card promotion-card">
            <PromotionPanel :from-year="year" @committed="load" />
          </div>

          <div class="kpi-grid decisions">
            <div class="kpi-card">
              <span class="kpi-label">Décisions — Admis</span>
              <strong>{{ decisionTotals.admis }}</strong>
              <span>élève(s)</span>
            </div>
            <div class="kpi-card warn">
              <span class="kpi-label">Décisions — Redouble</span>
              <strong>{{ decisionTotals.redouble }}</strong>
              <span>élève(s)</span>
            </div>
            <div class="kpi-card">
              <span class="kpi-label">En cours</span>
              <strong>{{ decisionTotals.en_cours }}</strong>
              <span>non encore évalués</span>
            </div>
            <div class="kpi-card">
              <span class="kpi-label">Taux de réussite</span>
              <strong>{{ successRateLabel }}</strong>
              <span>sur {{ summary?.students_evaluated ?? 0 }} évalué(s)</span>
            </div>
          </div>

          <div class="card class-chart-card">
            <div class="card-header">
              <div>
                <h2>Moyennes par classe</h2>
                <p>Comparatif des moyennes annuelles par classe</p>
              </div>
            </div>
            <div v-if="classStats.length === 0" class="empty-state">
              Aucune moyenne disponible pour le moment.
            </div>
            <template v-else>
              <BarChart
                :series="classAverageSeries"
                :categories="classAverageCategories"
                :height="classChartHeight"
                :horizontal="true"
                :y-max="20"
                tooltip-suffix="%"
              />
              <div v-if="classStats.length > CLASS_CHART_PREVIEW_LIMIT" class="chart-expand-bar">
                <span>
                  {{ visibleClassChartStats.length }} / {{ classStats.length }} classes affichées
                </span>
                <button
                  v-if="!showAllClassChartRows"
                  type="button"
                  class="link-btn"
                  @click="showAllClassChartRows = true"
                >
                  Afficher plus ({{ hiddenClassChartCount }}) →
                </button>
                <button
                  v-else
                  type="button"
                  class="link-btn"
                  @click="showAllClassChartRows = false"
                >
                  Afficher moins ↑
                </button>
              </div>
            </template>
          </div>

	          <div class="section-heading term-section-heading">
	            <h2>Structure pédagogique</h2>
            <div class="section-toolbar">
              <div class="view-toggle" aria-label="Affichage des termes">
                <button
                  type="button"
                  title="Vue grille"
                  :class="{ active: termViewMode === 'grid' }"
                  @click="termViewMode = 'grid'"
                >
                  <span class="toggle-icon toggle-icon-grid" aria-hidden="true"></span>
                  Grille
                </button>
                <button
                  type="button"
                  title="Vue tableau"
                  :class="{ active: termViewMode === 'table' }"
                  @click="termViewMode = 'table'"
                >
                  <span class="toggle-icon toggle-icon-list" aria-hidden="true"></span>
                  Tableau
                </button>
              </div>
              <button type="button" class="btn-secondary" :disabled="loading" @click="load">
                {{ loading ? 'Actualisation…' : 'Actualiser' }}
              </button>
            </div>
          </div>

          <div v-if="terms.length === 0" class="card empty-action">
            <div class="empty-icon" aria-hidden="true">+</div>
            <h3>Aucun terme défini</h3>
          </div>

          <template v-else>
            <!-- ─── Vue Grille : deux sections Primaire / Secondaire ─── -->
            <div v-if="termViewMode === 'grid'">
              <!-- Primaire (Trimestres) -->
              <div v-if="termsPrimaire.length > 0" class="term-section-group">
                <div class="term-group-label">
                  <span class="cycle-badge cycle-primaire">Primaire &amp; Maternel</span>
                  <span class="term-group-sub">{{ termsPrimaire.length }} Trimestre(s) &middot; {{ termsPrimaire.reduce((n, t) => n + (t.periods?.length ?? 0), 0) }} Période(s)</span>
                </div>
                <div class="term-grid">
                  <article v-for="term in termsPrimaire" :key="term.id" class="term-card">
                    <div class="term-card-top">
                      <div>
                        <span class="term-position">{{ termPositionLabel(term) }}</span>
                        <h3>{{ term.name }}</h3>
                        <p>{{ periodLabel(term.starts_on, term.ends_on) }}</p>
                      </div>
                      <span v-if="term.is_closed" class="badge badge-success">Clôturé</span>
                      <span v-else-if="isTermCalendarActive(term)" class="badge badge-warn">En cours</span>
                      <span v-else class="badge badge-muted">Ouvert</span>
                    </div>

                    <div class="term-progress">
                      <div class="progress-track slim">
                        <span :style="{ width: `${progressBetween(term.starts_on, term.ends_on)}%` }" />
                      </div>
                    </div>

                    <div class="period-list">
                      <div v-for="period in term.periods ?? []" :key="period.id" class="period-row">
                        <div>
                          <strong>{{ period.name }}</strong>
                          <span>{{ periodLabel(period.starts_on, period.ends_on) }}</span>
                        </div>
                        <div class="period-actions">
                          <span v-if="period.is_closed" class="badge badge-success">Clôturée</span>
                          <span v-else-if="isPeriodCalendarActive(period)" class="badge badge-warn">En cours</span>
                          <span v-else class="badge badge-muted">Ouverte</span>
                          <button
                            v-if="canWrite && !term.is_closed && !period.is_closed"
                            type="button" class="micro-button"
                            :disabled="closingPeriod === period.id"
                            @click="closePeriod(period)"
                          >{{ closingPeriod === period.id ? '...' : 'Clôturer' }}</button>
                          <button v-if="canWrite && !term.is_closed" type="button" class="micro-button" @click="openEditPeriod(term, period)">Modifier</button>
                          <button v-if="canWrite && !term.is_closed" type="button" class="micro-button danger" @click="removePeriod(period)">Supprimer</button>
                        </div>
                      </div>
                      <button
                        v-if="canWrite && !term.is_closed && (term.periods?.length ?? 0) < 2"
                        type="button" class="add-period-button"
                        @click="openCreatePeriod(term)"
                      >+ Période</button>
                    </div>

                    <div class="term-metrics">
                      <span><strong>{{ termStat(term)?.evaluations ?? 0 }}</strong> Évaluations</span>
                      <span><strong>{{ termStat(term)?.grades_entered ?? 0 }}</strong> Notes</span>
                      <span><strong>{{ averageLabel(termStat(term)?.grade_average) }}</strong> Moyenne</span>
                      <span><strong>{{ termStat(term)?.absences ?? 0 }}</strong> Absences</span>
                    </div>

                    <div class="term-actions">
                      <button
                        v-if="canWrite && !term.is_closed"
                        type="button" class="close-term-button"
                        :disabled="closing === term.id"
                        @click="closeTerm(term)"
                      >
                        <span class="lock-mark" aria-hidden="true"></span>
                        {{ closing === term.id ? 'Clôture…' : 'Clôturer' }}
                      </button>
                      <details v-if="canWrite" class="term-menu">
                        <summary aria-label="Actions secondaires">⋮</summary>
                        <div class="term-menu-list">
                          <button type="button" @click="openEdit(term)">Modifier</button>
                          <button type="button" class="btn-danger" @click="remove(term)">Supprimer</button>
                        </div>
                      </details>
                    </div>
                  </article>
                </div>
              </div>

              <!-- Secondaire / Technique (Semestres) -->
              <div v-if="termsSecondaire.length > 0" class="term-section-group">
                <div class="term-group-label">
                  <span class="cycle-badge cycle-secondaire">Secondaire &amp; Technique</span>
                  <span class="term-group-sub">{{ termsSecondaire.length }} Semestre(s) &middot; {{ termsSecondaire.reduce((n, t) => n + (t.periods?.length ?? 0), 0) }} Période(s)</span>
                </div>
                <div class="term-grid">
                  <article v-for="term in termsSecondaire" :key="term.id" class="term-card term-card-semestre">
                    <div class="term-card-top">
                      <div>
                        <span class="term-position semestre">{{ termPositionLabel(term) }}</span>
                        <h3>{{ term.name }}</h3>
                        <p>{{ periodLabel(term.starts_on, term.ends_on) }}</p>
                      </div>
                      <span v-if="term.is_closed" class="badge badge-success">Clôturé</span>
                      <span v-else-if="isTermCalendarActive(term)" class="badge badge-warn">En cours</span>
                      <span v-else class="badge badge-muted">Ouvert</span>
                    </div>

                    <div class="term-progress">
                      <div class="progress-track slim">
                        <span :style="{ width: `${progressBetween(term.starts_on, term.ends_on)}%` }" />
                      </div>
                    </div>

                    <div class="period-list">
                      <div v-for="period in term.periods ?? []" :key="period.id" class="period-row">
                        <div>
                          <strong>{{ period.name }}</strong>
                          <span>{{ periodLabel(period.starts_on, period.ends_on) }}</span>
                        </div>
                        <div class="period-actions">
                          <span v-if="period.is_closed" class="badge badge-success">Clôturée</span>
                          <span v-else-if="isPeriodCalendarActive(period)" class="badge badge-warn">En cours</span>
                          <span v-else class="badge badge-muted">Ouverte</span>
                          <button
                            v-if="canWrite && !term.is_closed && !period.is_closed"
                            type="button" class="micro-button"
                            :disabled="closingPeriod === period.id"
                            @click="closePeriod(period)"
                          >{{ closingPeriod === period.id ? '...' : 'Clôturer' }}</button>
                          <button v-if="canWrite && !term.is_closed" type="button" class="micro-button" @click="openEditPeriod(term, period)">Modifier</button>
                          <button v-if="canWrite && !term.is_closed" type="button" class="micro-button danger" @click="removePeriod(period)">Supprimer</button>
                        </div>
                      </div>
                      <button
                        v-if="canWrite && !term.is_closed && (term.periods?.length ?? 0) < 2"
                        type="button" class="add-period-button"
                        @click="openCreatePeriod(term)"
                      >+ Période</button>
                    </div>

                    <div class="term-metrics">
                      <span><strong>{{ termStat(term)?.evaluations ?? 0 }}</strong> Évaluations</span>
                      <span><strong>{{ termStat(term)?.grades_entered ?? 0 }}</strong> Notes</span>
                      <span><strong>{{ averageLabel(termStat(term)?.grade_average) }}</strong> Moyenne</span>
                      <span><strong>{{ termStat(term)?.absences ?? 0 }}</strong> Absences</span>
                    </div>

                    <div class="term-actions">
                      <button
                        v-if="canWrite && !term.is_closed"
                        type="button" class="close-term-button"
                        :disabled="closing === term.id"
                        @click="closeTerm(term)"
                      >
                        <span class="lock-mark" aria-hidden="true"></span>
                        {{ closing === term.id ? 'Clôture…' : 'Clôturer' }}
                      </button>
                      <details v-if="canWrite" class="term-menu">
                        <summary aria-label="Actions secondaires">⋮</summary>
                        <div class="term-menu-list">
                          <button type="button" @click="openEdit(term)">Modifier</button>
                          <button type="button" class="btn-danger" @click="remove(term)">Supprimer</button>
                        </div>
                      </details>
                    </div>
                  </article>
                </div>
              </div>
            </div>

            <div v-else class="card term-table-card">
              <table>
                <thead>
                  <tr>
                    <th>#</th>
                    <th>Nom</th>
                    <th>Période</th>
                    <th>Évaluations</th>
                    <th>Notes</th>
                    <th>Moyenne</th>
                    <th>Absences</th>
                    <th>Retards</th>
                    <th>Statut</th>
                    <th v-if="canWrite">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="term in terms" :key="term.id">
	                    <td>{{ term.position }}</td>
	                    <td>{{ term.name }}</td>
	                    <td>
	                      <div class="period-table-list">
	                        <span>{{ periodLabel(term.starts_on, term.ends_on) }}</span>
	                        <span v-for="period in term.periods ?? []" :key="period.id">
	                          {{ period.name }} · {{ periodLabel(period.starts_on, period.ends_on) }}
	                        </span>
	                        <button
	                          v-if="canWrite && !term.is_closed && (term.periods?.length ?? 0) < 2"
	                          type="button"
	                          class="micro-button"
	                          @click="openCreatePeriod(term)"
	                        >
	                          + Période
	                        </button>
	                      </div>
	                    </td>
                    <td>{{ termStat(term)?.evaluations ?? 0 }}</td>
                    <td>{{ termStat(term)?.grades_entered ?? 0 }}</td>
                    <td>{{ averageLabel(termStat(term)?.grade_average) }}</td>
                    <td>{{ termStat(term)?.absences ?? 0 }}</td>
                    <td>{{ termStat(term)?.lates ?? 0 }}</td>
                    <td>
                      <span v-if="term.is_closed" class="badge badge-success">Clôturé</span>
                      <span v-else class="badge badge-muted">Ouvert</span>
                    </td>
                    <td v-if="canWrite">
                      <div class="table-actions">
                        <button
                          v-if="!term.is_closed"
                          type="button"
                          class="close-term-button"
                          :disabled="closing === term.id"
                          @click="closeTerm(term)"
                        >
                          <span class="lock-mark" aria-hidden="true"></span>
                          {{ closing === term.id ? 'Clôture…' : 'Clôturer' }}
                        </button>
                        <details class="term-menu">
                          <summary aria-label="Actions secondaires">⋮</summary>
                          <div class="term-menu-list align-right">
                            <button type="button" @click="openEdit(term)">Modifier</button>
                            <button type="button" class="btn-danger" @click="remove(term)">
                              Supprimer
                            </button>
                          </div>
                        </details>
                      </div>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </template>
        </section>
      </div>

      <!-- ───────── Absences ───────── -->
      <div v-show="activeTab === 'attendance'" role="tabpanel" class="tab-panel">
        <section class="attendance-section">
          <div class="kpi-grid performance">
            <div class="kpi-card warn">
              <span class="kpi-label">Total absences</span>
              <strong>{{ summary?.absences ?? 0 }}</strong>
              <span>{{ summary?.unjustified_absences ?? 0 }} non justifiée(s)</span>
            </div>
            <div class="kpi-card">
              <span class="kpi-label">Retards</span>
              <strong>{{ summary?.lates ?? 0 }}</strong>
              <span>cumulés sur l'année</span>
            </div>
            <div class="kpi-card">
              <span class="kpi-label">Présences enregistrées</span>
              <strong>{{ summary?.attendance_records ?? 0 }}</strong>
              <span>relevés saisis</span>
            </div>
            <div class="kpi-card">
              <span class="kpi-label">Élèves concernés</span>
              <strong>{{ studentRows.filter((s) => s.absences > 0).length }}</strong>
              <span>au moins une absence</span>
            </div>
          </div>

          <div class="card chart-card">
            <div class="card-header">
              <div>
                <h2>Statistiques mensuelles</h2>
              </div>
            </div>
            <div v-if="monthlyAttendance.length" class="chart-surface">
              <AreaChart
                v-if="!monthlyAttendanceIsFlat"
                :series="monthlyAttendanceSeries"
                :categories="monthlyAttendanceCategories"
                :height="260"
              />
              <div v-else class="chart-empty-compact">
                <p>Aucune absence ni retard enregistré sur l'année.</p>
                <span class="chart-empty-range">{{ monthlyAttendanceRangeLabel }}</span>
              </div>
            </div>
            <div v-else class="empty-state">Aucune donnée d'assiduité.</div>
          </div>

          <div class="card class-table-card">
            <div class="card-header">
              <h2>
                Absences par classe
                <span v-if="classStatsWithAbsences.length" class="count-pill">
                  {{ classStatsWithAbsences.length }}
                </span>
              </h2>
            </div>
            <template v-if="classStatsWithAbsences.length">
              <table>
                <thead>
                  <tr>
                    <th>Classe</th>
                    <th class="num-col">Élèves</th>
                    <th class="num-col">Absences</th>
                    <th class="num-col">Retards</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="item in classStatsWithAbsences" :key="item.classroom_id">
                    <td><strong>{{ item.classroom }}</strong></td>
                    <td class="num-col">{{ item.student_count }}</td>
                    <td class="num-col">
                      <span class="abs-val abs-val--warn">{{ item.absences }}</span>
                    </td>
                    <td class="num-col">{{ item.lates }}</td>
                  </tr>
                </tbody>
              </table>
            </template>
            <div v-else class="attendance-empty">
              <span class="attendance-empty-icon">✓</span>
              <p>Aucune absence enregistrée pour cette période.</p>
            </div>
          </div>

          <div class="card class-table-card">
            <div class="card-header">
              <h2>
                Absences par élève
                <span v-if="studentRowsWithAbsences.length" class="count-pill">
                  {{ studentRowsWithAbsences.length }}
                </span>
              </h2>
            </div>
            <template v-if="studentRowsWithAbsences.length">
              <table>
                <thead>
                  <tr>
                    <th>Élève</th>
                    <th>Classe</th>
                    <th class="num-col">Absences</th>
                    <th class="num-col">Non justif.</th>
                    <th class="num-col">Retards</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="student in studentRowsWithAbsences" :key="student.id">
                    <td>
                      <RouterLink
                        class="student-link"
                        :to="{ name: 'student-detail', params: { id: student.id } }"
                      >
                        {{ student.full_name }}
                      </RouterLink>
                    </td>
                    <td>{{ student.classroom ?? '—' }}</td>
                    <td class="num-col">
                      <span class="abs-val abs-val--warn">{{ student.absences }}</span>
                    </td>
                    <td class="num-col">
                      <span v-if="student.unjustified_absences > 0" class="abs-val abs-val--danger">
                        {{ student.unjustified_absences }}
                      </span>
                      <span v-else class="text-muted">—</span>
                    </td>
                    <td class="num-col">{{ student.lates || '—' }}</td>
                  </tr>
                </tbody>
              </table>
            </template>
            <div v-else class="attendance-empty">
              <span class="attendance-empty-icon">✓</span>
              <p>Aucune absence enregistrée pour cette période.</p>
            </div>
          </div>
        </section>
      </div>

      <!-- ───────── Documents ───────── -->
      <div v-show="activeTab === 'documents'" role="tabpanel" class="tab-panel">
        <section class="documents-section">
          <div class="card document-card">
            <div class="card-header">
              <h2>Bulletins PDF</h2>
            </div>
            <div class="document-form">
              <label>
                <span>Classe (filtre)</span>
                <select v-model="documentsClassroomId">
                  <option value="">Toutes les classes</option>
                  <option
                    v-for="item in documentsClassrooms"
                    :key="item.classroom_id"
                    :value="String(item.classroom_id)"
                  >
                    {{ item.classroom }}
                  </option>
                </select>
              </label>
              <label>
                <span>Élève</span>
                <select v-model="documentsStudentId" :disabled="documentsStudents.length === 0">
                  <option value="">— Sélectionner —</option>
                  <option
                    v-for="student in documentsStudents"
                    :key="student.id"
                    :value="String(student.id)"
                  >
                    {{ student.full_name }}{{ student.classroom ? ` · ${student.classroom}` : '' }}
                  </option>
                </select>
              </label>
              <label>
                <span>Trimestre</span>
                <select v-model="documentsTermId" :disabled="documentsTerms.length === 0">
                  <option value="">— Sélectionner —</option>
                  <option
                    v-for="term in documentsTerms"
                    :key="term.id"
                    :value="String(term.id)"
                  >
                    {{ term.name }}
                  </option>
                </select>
              </label>
              <button
                type="button"
                class="btn-primary"
                :disabled="!documentsStudentId || !documentsTermId"
                @click="downloadBulletinPdf"
              >
                Télécharger PDF
              </button>
            </div>
          </div>

          <div class="card document-card">
            <div class="card-header">
              <h2>Rapports CSV</h2>
            </div>
            <div class="document-form">
              <label>
                <span>Classe</span>
                <select v-model="documentsClassroomId">
                  <option value="">— Choisir —</option>
                  <option
                    v-for="item in documentsClassrooms"
                    :key="item.classroom_id"
                    :value="String(item.classroom_id)"
                  >
                    {{ item.classroom }}
                  </option>
                </select>
              </label>
              <label>
                <span>Trimestre</span>
                <select v-model="documentsTermId">
                  <option value="">— Choisir —</option>
                  <option
                    v-for="term in documentsTerms"
                    :key="term.id"
                    :value="String(term.id)"
                  >
                    {{ term.name }}
                  </option>
                </select>
              </label>
              <button
                type="button"
                class="btn-secondary"
                :disabled="!documentsClassroomId || !documentsTermId"
                @click="downloadRankingCsv"
              >
                Classement par moyenne
              </button>
              <button
                type="button"
                class="btn-secondary"
                :disabled="!documentsStudentId"
                @click="downloadEvolutionCsv"
              >
                Évolution de l'élève
              </button>
            </div>
            <div class="document-form">
              <label>
                <span>Du</span>
                <input v-model="attendanceFrom" type="date" />
              </label>
              <label>
                <span>Au</span>
                <input v-model="attendanceTo" type="date" />
              </label>
              <button type="button" class="btn-secondary" @click="downloadAttendanceCsv">
                Absentéisme par classe (CSV)
              </button>
            </div>
          </div>

          <div class="card document-card">
            <div class="card-header">
              <div>
                <h2>Listes officielles</h2>
                <p>Consulte et exporte la liste des élèves d'une classe.</p>
              </div>
            </div>
            <div class="document-list">
              <RouterLink
                v-for="item in classStats"
                :key="item.classroom_id"
                class="document-link"
                :to="{
                  name: 'school-year-class-detail',
                  params: { id: props.id, classroomId: item.classroom_id },
                }"
              >
                <strong>{{ item.classroom }}</strong>
                <span>{{ item.student_count }} élève(s) · {{ item.teacher_count }} enseignant(s)</span>
              </RouterLink>
              <p v-if="classStats.length === 0" class="empty-state">Aucune classe disponible.</p>
            </div>
          </div>
        </section>
      </div>

      <!-- ───────── Historique ───────── -->
      <div v-show="activeTab === 'history'" role="tabpanel" class="tab-panel">
        <section class="history-section">
          <div class="kpi-grid identity">
            <div class="kpi-card">
              <span class="kpi-label">Date de clôture</span>
              <strong>{{ formatDateTime(history?.closed_at ?? year.closed_at) }}</strong>
              <span>{{ history?.closed_at || year.closed_at ? 'Année clôturée' : 'Pas encore clôturée' }}</span>
            </div>
            <div class="kpi-card">
              <span class="kpi-label">Date d'archivage</span>
              <strong>{{ formatDateTime(history?.archived_at ?? year.archived_at) }}</strong>
              <span>{{ isArchived ? 'Archivée' : 'Non archivée' }}</span>
            </div>
            <div class="kpi-card">
              <span class="kpi-label">Utilisateur responsable</span>
              <strong>{{ history?.archived_by?.name ?? year.archived_by?.name ?? '—' }}</strong>
              <span>{{ history?.archived_by?.email ?? year.archived_by?.email ?? 'Aucun archivage enregistré' }}</span>
            </div>
            <div class="kpi-card">
              <span class="kpi-label">Trimestres clôturés</span>
              <strong>{{ summary?.closed_terms ?? 0 }} / {{ summary?.terms ?? 0 }}</strong>
              <span>{{ closedTermsRatio }}% du parcours</span>
            </div>
          </div>

          <div class="card class-table-card">
            <div class="card-header">
              <div>
                <h2>Historique des trimestres</h2>
              </div>
            </div>
            <table>
              <thead>
                <tr>
                  <th>#</th>
                  <th>Nom</th>
                  <th>Période</th>
                  <th>Statut</th>
                  <th>Date de clôture</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="term in terms" :key="term.id">
                  <td>T{{ term.position }}</td>
                  <td>{{ term.name }}</td>
                  <td>{{ periodLabel(term.starts_on, term.ends_on) }}</td>
                  <td>
                    <span v-if="term.is_closed" class="badge badge-success">Clôturé</span>
                    <span v-else class="badge badge-muted">Ouvert</span>
                  </td>
                  <td>{{ formatDateTime(term.closed_at) }}</td>
                </tr>
                <tr v-if="terms.length === 0">
                  <td colspan="5" class="empty-state">Aucun trimestre.</td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>
      </div>
    </template>

    <PeriodFormModal
      :open="showPeriodForm"
      :term="periodTerm"
      :period="editingPeriod"
      @close="showPeriodForm = false"
      @saved="onPeriodSaved"
    />

    <Modal
      :open="showForm"
      :title="editing ? 'Modifier un trimestre' : 'Nouveau trimestre'"
      @close="showForm = false"
    >
      <form id="term-form" @submit.prevent="submit">
        <div class="field">
          <label for="t-name">Nom</label>
          <input id="t-name" v-model="form.name" type="text" required maxlength="64" />
          <small v-if="formErrors.name" class="err">{{ formErrors.name[0] }}</small>
        </div>

        <div class="form-grid">
          <div class="field">
	            <label for="t-pos">Position (1 à 3)</label>
	            <input id="t-pos" v-model.number="form.position" type="number" min="1" max="3" required />
            <small v-if="formErrors.position" class="err">{{ formErrors.position[0] }}</small>
          </div>

          <div class="field">
            <label for="t-starts">Date de début</label>
            <input id="t-starts" v-model="form.starts_on" type="date" required />
            <small v-if="formErrors.starts_on" class="err">{{ formErrors.starts_on[0] }}</small>
          </div>
        </div>

        <div class="field">
          <label for="t-ends">Date de fin</label>
          <input id="t-ends" v-model="form.ends_on" type="date" required />
          <small v-if="formErrors.ends_on" class="err">{{ formErrors.ends_on[0] }}</small>
        </div>

        <p v-if="formError" class="alert alert-error">{{ formError }}</p>
      </form>

      <template #footer>
        <button type="button" @click="showForm = false">Annuler</button>
        <button type="submit" form="term-form" class="btn-primary" :disabled="submitting">
          {{ submitting ? 'Enregistrement...' : editing ? 'Enregistrer' : 'Créer' }}
        </button>
      </template>
    </Modal>
  </section>
</template>

<style scoped>
.school-year-detail-page {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.detail-breadcrumb {
  width: fit-content;
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
  color: var(--text-muted);
  font-size: 0.8rem;
  font-weight: 700;
}

.detail-breadcrumb a {
  color: var(--primary);
  transition: color 0.15s ease;
}

.detail-breadcrumb a:hover {
  color: var(--primary-dark);
  text-decoration: underline;
}

.detail-breadcrumb strong {
  color: var(--text);
  font-weight: 800;
}

.detail-hero {
  display: flex;
  align-items: stretch;
  justify-content: space-between;
  gap: 1.25rem;
  padding: 1.5rem 1.65rem;
  border: 1px solid var(--border);
  border-radius: 16px;
  background: var(--bg-card);
  box-shadow: var(--shadow-card);
  transition: box-shadow 0.2s ease;
}

.detail-hero-main {
  min-width: 0;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
}

.detail-hero h1,
.section-heading h2,
.term-card h3 {
  margin: 0;
  font-size: 1.75rem;
  font-weight: 900;
  letter-spacing: -0.03em;
  color: var(--text);
}

.hero-date-line {
  margin: 0.4rem 0 0;
  color: var(--text-soft);
  font-size: 0.86rem;
  font-weight: 500;
}

.hero-badges {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
  margin-top: 1rem;
}

.hero-badge {
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  min-height: 1.75rem;
  padding: 0.22rem 0.7rem;
  border: 1px solid var(--border);
  border-radius: 999px;
  background: var(--bg-subtle);
  color: var(--text-soft);
  font-size: 0.74rem;
  font-weight: 700;
  letter-spacing: 0.01em;
}

.hero-badge.primary {
  border-color: var(--primary-tint);
  background: var(--primary-soft);
  color: var(--primary-dark);
  font-weight: 800;
}

.hero-badge.warn {
  border-color: rgba(251, 191, 36, 0.3);
  background: var(--warn-soft);
  color: var(--warn);
}

.detail-hero.current {
  border-color: var(--primary-tint);
  background: linear-gradient(140deg, var(--bg-card) 0%, var(--bg-subtle) 55%, var(--bg-card) 100%);
  box-shadow: 0 4px 12px rgba(37, 99, 235, 0.15), 0 1px 3px rgba(37, 99, 235, 0.08);
}

.detail-hero p,
.section-heading p,
.term-card p,
.card-header p {
  margin: 0.25rem 0 0;
  color: var(--text-soft);
  font-size: 0.9rem;
}

.hero-meta-strip {
  display: flex;
  flex-wrap: wrap;
  gap: 0.35rem;
  margin-top: 0.75rem;
}

.hero-meta-strip span,
.tab-context span {
  display: inline-flex;
  align-items: center;
  min-height: 1.65rem;
  padding: 0.18rem 0.55rem;
  border: 1px solid var(--border);
  border-radius: 999px;
  background: var(--bg-subtle);
  color: var(--text-soft);
  font-size: 0.76rem;
  font-weight: 800;
}

.eyebrow {
  margin: 0 0 0.12rem;
  color: var(--text-soft);
  font-size: 0.73rem;
  font-weight: 800;
  letter-spacing: 0.08em;
  text-transform: uppercase;
}

.hero-actions,
.section-heading,
.term-card-top,
.term-actions,
.progress-meta,
.closed-track > div {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
}

.hero-actions,
.term-actions {
  flex-wrap: wrap;
  justify-content: flex-end;
}

.hero-side {
  min-width: min(22rem, 100%);
  display: grid;
  align-content: start;
  justify-items: end;
  gap: 0.7rem;
}

.hero-status-card {
  width: min(22rem, 100%);
  display: grid;
  gap: 0.6rem;
  padding: 0.9rem 1rem;
  border: 1px solid var(--border);
  border-radius: 12px;
  background: linear-gradient(160deg, var(--bg-card) 0%, var(--bg-soft) 100%);
  box-shadow: var(--shadow);
}

.hero-status-top,
.hero-status-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
}

.hero-status-top span,
.hero-status-row span {
  color: var(--text-soft);
  font-size: 0.75rem;
  font-weight: 700;
}

.hero-status-top strong,
.hero-status-row strong {
  color: var(--text);
  font-size: 0.84rem;
  font-weight: 800;
  letter-spacing: -0.01em;
}

.hero-progress {
  height: 6px;
  border-radius: 999px;
  background: var(--primary-soft);
  overflow: hidden;
}

.hero-progress span {
  display: block;
  height: 100%;
  border-radius: inherit;
  background: linear-gradient(90deg, var(--primary), var(--accent));
  transition: width 0.6s cubic-bezier(0.16, 1, 0.3, 1);
}

.kpi-groups {
  display: grid;
  gap: 1rem;
}

.kpi-group {
  display: grid;
  gap: 0.75rem;
  padding: 1rem 1.1rem;
  border: 1px solid var(--border);
  border-radius: 14px;
  background: var(--bg-card);
  box-shadow: var(--shadow);
}

.kpi-group-title {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  color: var(--primary);
  font-size: 0.68rem;
  font-weight: 800;
  letter-spacing: 0.1em;
  text-transform: uppercase;
}

.kpi-group-title::after {
  content: '';
  flex: 1;
  height: 1px;
  background: var(--primary-tint);
}

.kpi-grid {
  display: grid;
  gap: 0.85rem;
}

.kpi-grid.structure {
  grid-template-columns: repeat(5, minmax(0, 1fr));
}

.kpi-grid.performance,
.kpi-grid.identity,
.kpi-grid.decisions {
  grid-template-columns: repeat(4, minmax(0, 1fr));
}

.kpi-card {
  position: relative;
  min-height: 6.8rem;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  gap: 0.4rem;
  padding: 1rem 1.05rem;
  border: 1px solid var(--border);
  border-top: 3px solid var(--border);
  border-radius: 12px;
  background: var(--bg-card);
  box-shadow: var(--shadow);
  transition: box-shadow 0.2s ease, transform 0.2s ease;
  overflow: hidden;
}

.kpi-card:hover {
  box-shadow: var(--shadow-hover);
}

.kpi-card.warn {
  border-top-color: var(--warn);
}

.kpi-card.highlight {
  border-top-color: var(--primary);
  background: linear-gradient(160deg, var(--bg-card) 0%, var(--bg-subtle) 100%);
}

.kpi-card.highlight strong {
  color: var(--primary-dark);
}

.kpi-label {
  color: var(--text-muted);
  font-size: 0.67rem;
  font-weight: 800;
  letter-spacing: 0.1em;
  text-transform: uppercase;
}

.kpi-card strong {
  display: block;
  color: var(--text);
  font-size: 1.6rem;
  font-weight: 900;
  line-height: 1;
  letter-spacing: -0.025em;
}

.kpi-card span:last-child {
  color: var(--text-muted);
  font-size: 0.74rem;
  font-weight: 600;
}

.analytics-grid {
  display: grid;
  grid-template-columns: minmax(280px, 0.75fr) minmax(0, 1.4fr);
  align-items: stretch;
  gap: 1rem;
}

.chart-card {
  overflow: hidden;
  display: flex;
  flex-direction: column;
}

.chart-surface {
  padding: 0 0.35rem 0.85rem;
  flex: 1;
  display: flex;
  flex-direction: column;
  justify-content: center;
}

.chart-surface :deep(.apexcharts-canvas) {
  margin: 0 auto;
}

.chart-empty-compact {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 0.75rem;
  margin: 1rem 1.25rem 1.25rem;
  padding: 2.5rem 1.5rem;
  border-radius: 14px;
  background:
    radial-gradient(ellipse at 50% 0%, rgba(59, 130, 246, 0.07) 0%, transparent 65%),
    var(--bg-subtle);
  text-align: center;
}

.chart-empty-compact::before {
  content: '✓';
  display: grid;
  place-items: center;
  width: 2.75rem;
  height: 2.75rem;
  border-radius: 50%;
  background: var(--success-soft);
  border: 1.5px solid rgba(74, 222, 128, 0.25);
  color: var(--success);
  font-size: 1.1rem;
  font-weight: 800;
  line-height: 1;
  flex-shrink: 0;
}

.chart-empty-compact p {
  margin: 0;
  color: var(--text);
  font-size: 0.9rem;
  font-weight: 700;
}

.chart-empty-range {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  padding: 0.2rem 0.75rem;
  border-radius: 999px;
  background: var(--bg-card);
  border: 1px solid var(--border);
  color: var(--text-muted);
  font-size: 0.74rem;
  font-weight: 600;
  letter-spacing: 0.02em;
}

.progress-body {
  display: grid;
  gap: 1rem;
  padding: 1.1rem 1.25rem 1.25rem;
}

.term-progress-item {
  display: grid;
  gap: 0.4rem;
}

.term-progress-item.global {
  padding-bottom: 0.75rem;
  border-bottom: 1px solid var(--border);
}

.term-progress-item.global .term-progress-head span {
  font-size: 0.8rem;
  font-weight: 700;
  color: var(--text-soft);
}

.term-progress-item.global .term-progress-head strong {
  font-size: 1rem;
  font-weight: 900;
  color: var(--primary-dark);
}

.term-progress-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 0.75rem;
}

.term-progress-head strong {
  display: block;
  font-size: 0.87rem;
  font-weight: 700;
  color: var(--text);
}

.term-progress-head small {
  display: block;
  margin-top: 0.08rem;
  color: var(--text-muted);
  font-size: 0.72rem;
}

.term-progress-meta {
  margin: 0;
  color: var(--text-muted);
  font-size: 0.72rem;
}

.term-status-badge {
  display: inline-flex;
  align-items: center;
  gap: 0.25rem;
  min-height: 1.4rem;
  padding: 0.1rem 0.55rem;
  border-radius: 999px;
  font-size: 0.68rem;
  font-weight: 800;
  letter-spacing: 0.02em;
  white-space: nowrap;
}

.term-status-badge.success {
  background: var(--success-soft);
  color: var(--success);
}

.term-status-badge.primary {
  background: var(--primary-soft);
  color: var(--primary-dark);
}

.term-status-badge.warn {
  background: var(--warn-soft);
  color: var(--warn);
}

.term-status-badge.muted {
  background: var(--bg-subtle);
  color: var(--text-muted);
}

.progress-track span.fill-primary {
  background: linear-gradient(90deg, var(--primary), var(--accent));
}

.progress-track span.fill-success {
  background: linear-gradient(90deg, #16a34a, #4ade80);
}

.progress-track span.fill-warn {
  background: linear-gradient(90deg, var(--warn), #fbbf24);
}

.progress-track span.fill-muted {
  background: var(--border-strong);
}

.terms-overview {
  margin-top: 0.25rem;
}

.terms-table-card {
  border: 0;
  box-shadow: none;
  background: transparent;
  padding: 0;
}

.terms-table-card table {
  width: 100%;
}

.badge-primary {
  background: var(--primary-soft);
  color: var(--primary);
}

.progress-track {
  height: 8px;
  overflow: hidden;
  border-radius: 999px;
  background: var(--bg-subtle);
}

.progress-track.slim {
  height: 0.52rem;
}

.progress-track span {
  display: block;
  height: 100%;
  min-width: 0.4rem;
  border-radius: inherit;
  background: linear-gradient(90deg, #3457ff, #6f8cff);
}

.progress-meta {
  color: var(--text-soft);
  font-size: 0.82rem;
  font-weight: 700;
}

.progress-meta strong,
.closed-track strong {
  color: var(--primary);
}

.closed-track {
  display: grid;
  gap: 0.45rem;
  padding-top: 0.25rem;
}

.closed-track span {
  color: var(--text-soft);
  font-size: 0.84rem;
  font-weight: 750;
}

.monthly-chart {
  min-height: 18rem;
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(4.2rem, 1fr));
  align-items: end;
  gap: 0.7rem;
  padding: 1rem 1.15rem 1.2rem;
}

.month-column {
  position: relative;
  min-width: 0;
  display: grid;
  justify-items: center;
  gap: 0.35rem;
  color: var(--text-soft);
  font-size: 0.72rem;
  font-weight: 750;
  text-align: center;
}

.month-column:focus-visible {
  outline: 3px solid rgba(52, 87, 255, 0.2);
  outline-offset: 4px;
  border-radius: var(--radius);
}

.column-track {
  position: relative;
  width: 2.1rem;
  height: 10rem;
  overflow: hidden;
  display: flex;
  align-items: end;
  border-radius: 999px;
  background: var(--bg-subtle);
}

.column-track span {
  position: absolute;
  inset: auto 0 0;
  min-height: 0.35rem;
  border-radius: inherit;
}

.late-fill {
  background: rgba(59, 130, 246, 0.35);
}

.absence-fill {
  background: linear-gradient(180deg, var(--warn), #fb923c);
}

.chart-tooltip {
  position: absolute;
  left: 50%;
  bottom: calc(100% + 0.6rem);
  z-index: 5;
  min-width: 9rem;
  display: grid;
  gap: 0.15rem;
  padding: 0.55rem 0.65rem;
  border: 1px solid var(--border-strong);
  border-radius: var(--radius);
  background: var(--bg-card);
  color: var(--text);
  font-size: 0.76rem;
  font-weight: 700;
  text-align: left;
  box-shadow: 0 12px 24px rgba(0, 0, 0, 0.5);
  opacity: 0;
  pointer-events: none;
  transform: translate(-50%, 0.35rem);
  transition: opacity 0.15s ease, transform 0.15s ease;
}

.chart-tooltip span {
  color: rgba(255, 255, 255, 0.78);
}

.month-column:hover .chart-tooltip,
.month-column:focus-visible .chart-tooltip {
  opacity: 1;
  transform: translate(-50%, 0);
}

.terms-section {
  display: grid;
  gap: 0.85rem;
}

.classes-section {
  display: grid;
  gap: 0.85rem;
}

.filter-chip {
  flex: 0 0 auto;
  min-height: 1.85rem;
  padding: 0.25rem 0.7rem;
  border: 1px solid var(--border);
  border-radius: 999px;
  background: var(--bg-subtle);
  color: var(--text-soft);
  font-size: 0.78rem;
  font-weight: 700;
  cursor: pointer;
}

.filter-chip.active {
  border-color: var(--primary-tint);
  background: var(--primary-soft);
  color: var(--accent);
}

.class-filter.compact {
  min-width: 10rem;
}

.class-summary-bar {
  display: grid;
  grid-template-columns: repeat(5, minmax(0, 1fr));
  gap: 0.65rem;
  padding: 0.75rem;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: var(--bg-subtle);
}

.class-summary-item {
  display: grid;
  gap: 0.15rem;
  text-align: center;
}

.class-summary-item strong {
  font-size: 1.15rem;
  font-weight: 850;
  line-height: 1;
}

.class-summary-item span {
  color: var(--text-soft);
  font-size: 0.68rem;
  font-weight: 800;
  letter-spacing: 0.05em;
  text-transform: uppercase;
}

.class-summary-item.good strong {
  color: var(--success);
}

.class-summary-item.warn strong {
  color: var(--warn);
}

.class-summary-item.danger strong {
  color: var(--danger);
}

.grouped-class-table table {
  min-width: 56rem;
}

.cycle-group-row td {
  padding-top: 0.85rem;
  padding-bottom: 0.35rem;
  border-bottom: 0;
  background: transparent;
}

.cycle-group-row strong {
  font-size: 0.72rem;
  letter-spacing: 0.07em;
}

.cycle-group-row span {
  margin-left: 0.55rem;
  color: var(--text-muted);
  font-size: 0.74rem;
  font-weight: 650;
}

.enrollment-cell {
  min-width: 7rem;
}

.enrollment-bar-wrap {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  align-items: center;
  gap: 0.55rem;
}

.enrollment-bar-track {
  height: 0.42rem;
  border-radius: 999px;
  background: var(--bg-subtle);
  overflow: hidden;
}

.enrollment-bar-fill {
  display: block;
  height: 100%;
  border-radius: inherit;
  background: linear-gradient(90deg, var(--primary), #60a5fa);
}

.class-average.good {
  color: var(--success);
  font-weight: 800;
}

.class-average.danger {
  color: var(--danger);
  font-weight: 800;
}

.class-average.muted {
  color: var(--text-muted);
}

.class-footer-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  flex-wrap: wrap;
  padding: 0.65rem 0.85rem;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: var(--bg-card);
}

.class-footer-stats,
.class-footer-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.65rem;
  color: var(--text-soft);
  font-size: 0.78rem;
  font-weight: 650;
}

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

.link-btn {
  border: 0;
  background: transparent;
  color: var(--primary);
  font-size: 0.78rem;
  font-weight: 700;
  cursor: pointer;
  padding: 0;
}

.link-btn:hover {
  text-decoration: underline;
}

.class-section-heading {
  align-items: flex-end;
}

.section-toolbar {
  display: flex;
  align-items: flex-end;
  justify-content: flex-end;
  gap: 0.55rem;
  flex-wrap: wrap;
}

.view-toggle {
  display: inline-flex;
  align-items: center;
  gap: 0.18rem;
  padding: 0.22rem;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: var(--bg-soft);
}

.view-toggle button {
  min-height: 2rem;
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  padding: 0.3rem 0.62rem;
  border: 0;
  background: transparent;
  color: var(--text-soft);
  box-shadow: none;
  font-size: 0.78rem;
  font-weight: 800;
}

.view-toggle button.active {
  background: var(--bg-card);
  color: var(--primary);
  box-shadow: var(--shadow);
}

.toggle-icon {
  width: 0.88rem;
  height: 0.88rem;
  display: inline-block;
  color: currentColor;
}

.toggle-icon-grid {
  background:
    linear-gradient(currentColor 0 0) 0 0 / 0.34rem 0.34rem no-repeat,
    linear-gradient(currentColor 0 0) 100% 0 / 0.34rem 0.34rem no-repeat,
    linear-gradient(currentColor 0 0) 0 100% / 0.34rem 0.34rem no-repeat,
    linear-gradient(currentColor 0 0) 100% 100% / 0.34rem 0.34rem no-repeat;
}

.toggle-icon-list {
  background:
    linear-gradient(currentColor 0 0) 0 0.08rem / 100% 0.14rem no-repeat,
    linear-gradient(currentColor 0 0) 0 0.38rem / 100% 0.14rem no-repeat,
    linear-gradient(currentColor 0 0) 0 0.68rem / 100% 0.14rem no-repeat;
}

.class-filter {
  min-width: 14rem;
  margin: 0;
}

.class-filter span {
  display: block;
  margin-bottom: 0.25rem;
  color: var(--text-soft);
  font-size: 0.72rem;
  font-weight: 800;
  letter-spacing: 0.05em;
  text-transform: uppercase;
}

.class-filter select {
  min-height: 2.25rem;
  font-size: 0.88rem;
  font-weight: 700;
}

.class-data-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
  gap: 0.85rem;
}

.class-data-card {
  display: grid;
  gap: 0.85rem;
  padding: 0.95rem;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: var(--bg-card);
  box-shadow: var(--shadow);
}

.class-data-link {
  width: 100%;
  color: inherit;
  font: inherit;
  text-align: left;
  cursor: pointer;
  text-decoration: none;
}

.class-data-link:hover {
  border-color: var(--primary);
  color: inherit;
  text-decoration: none;
}

.class-data-link:focus-visible {
  outline: 3px solid var(--primary-tint);
  outline-offset: 2px;
}

.class-data-top {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 0.75rem;
}

.class-data-top h3 {
  margin: 0;
}

.class-data-top p {
  margin: 0.2rem 0 0;
  color: var(--text-soft);
  font-size: 0.82rem;
  font-weight: 650;
}

.class-data-metrics {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 0.45rem;
}

.class-data-metrics span {
  min-width: 0;
  padding: 0.55rem;
  border-radius: var(--radius);
  background: var(--bg-subtle);
  color: var(--text-soft);
  font-size: 0.7rem;
  font-weight: 750;
}

.class-data-metrics strong {
  display: block;
  color: var(--text);
  font-size: 1rem;
  line-height: 1.1;
}

.class-data-footer {
  display: flex;
  flex-wrap: wrap;
  gap: 0.35rem;
}

.class-data-footer span {
  padding: 0.22rem 0.5rem;
  border-radius: 999px;
  background: rgba(255, 255, 255, 0.08);
  color: var(--text-soft);
  font-size: 0.72rem;
  font-weight: 750;
}

.class-chart-card,
.class-table-card {
  overflow-x: auto;
}

.num-col {
  text-align: right;
  font-variant-numeric: tabular-nums;
}

.abs-val--warn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 1.75rem;
  padding: 0.1rem 0.4rem;
  border-radius: 6px;
  background: var(--warn-soft);
  color: var(--warn);
  font-weight: 800;
  font-size: 0.88rem;
}

.abs-val--danger {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 1.75rem;
  padding: 0.1rem 0.4rem;
  border-radius: 6px;
  background: var(--danger-soft);
  color: var(--danger);
  font-weight: 800;
  font-size: 0.88rem;
}

.count-pill {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 1.4rem;
  height: 1.4rem;
  padding: 0 0.4rem;
  border-radius: 999px;
  background: var(--warn-soft);
  color: var(--warn);
  font-size: 0.7rem;
  font-weight: 800;
  margin-left: 0.5rem;
  vertical-align: middle;
}

.attendance-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.6rem;
  padding: 2.5rem 1rem;
  text-align: center;
}

.attendance-empty-icon {
  display: grid;
  place-items: center;
  width: 2.5rem;
  height: 2.5rem;
  border-radius: 50%;
  background: var(--success-soft);
  border: 1.5px solid rgba(74, 222, 128, 0.25);
  color: var(--success);
  font-size: 1rem;
  font-weight: 800;
}

.attendance-empty p {
  margin: 0;
  color: var(--text-soft);
  font-size: 0.87rem;
  font-weight: 600;
}

.chart-expand-bar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem 1rem;
  padding: 0.65rem 1.15rem 0.9rem;
  border-top: 1px solid var(--border);
  color: var(--text-soft);
  font-size: 0.82rem;
  font-weight: 650;
}

.class-bars {
  display: grid;
  gap: 0.85rem;
  min-width: 44rem;
  padding: 1rem 1.15rem;
}

.class-bar-row {
  display: grid;
  grid-template-columns: minmax(12rem, 17rem) minmax(0, 1fr) 4rem;
  align-items: center;
  gap: 0.85rem;
}

.class-label {
  min-width: 0;
}

.class-label strong {
  display: block;
  overflow: hidden;
  color: var(--text);
  font-size: 0.9rem;
  font-weight: 800;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.class-label span {
  display: block;
  overflow: hidden;
  color: var(--text-soft);
  font-size: 0.76rem;
  font-weight: 650;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.bar-track {
  height: 0.64rem;
  overflow: hidden;
  border-radius: 999px;
  background: var(--bg-subtle);
}

.bar-fill {
  display: block;
  height: 100%;
  min-width: 0.45rem;
  border-radius: inherit;
  background: linear-gradient(90deg, var(--primary), var(--accent));
}

.bar-fill.warn {
  background: linear-gradient(90deg, var(--warn), #fb923c);
}

.bar-fill.muted {
  background: var(--border-strong);
}

.bar-value {
  color: var(--primary);
  font-size: 0.84rem;
  font-weight: 850;
  text-align: right;
}

/* ── Badges cycle ── */
.cycle-badge {
  display: inline-block;
  padding: 0.2rem 0.6rem;
  border-radius: 999px;
  font-size: 0.68rem;
  font-weight: 700;
  letter-spacing: 0.02em;
  white-space: nowrap;
}

.cycle-maternel { background: rgba(251, 191, 36, 0.15); color: var(--warn); }
.cycle-primaire { background: rgba(74, 222, 128, 0.12); color: var(--success); }
.cycle-cteb { background: rgba(59, 130, 246, 0.15); color: var(--accent); }
.cycle-secondaire { background: rgba(192, 132, 252, 0.15); color: #c084fc; }

/* ── Division pills ── */
.division-badges {
  display: inline-flex;
  flex-wrap: wrap;
  gap: 0.3rem;
}

.division-pill {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 1.6rem;
  padding: 0.15rem 0.45rem;
  border-radius: 999px;
  background: var(--primary-tint, var(--primary-soft));
  color: var(--primary);
  font-size: 0.7rem;
  font-weight: 800;
}

.text-muted {
  color: var(--text-muted, #94a3b8);
}

.class-table-card table {
  min-width: 76rem;
}

.class-table-link,
.class-table-link strong,
.class-table-link span {
  display: block;
}

.class-table-link {
  color: inherit;
  text-decoration: none;
}

.class-table-link:hover {
  color: var(--primary);
  text-decoration: none;
}

.class-table-link span {
  margin-top: 0.15rem;
  color: var(--primary);
  font-size: 0.72rem;
  font-weight: 800;
}

.term-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
  gap: 0.85rem;
}

.term-card {
  display: grid;
  gap: 0.85rem;
  padding: 0.95rem;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: var(--bg-card);
  box-shadow: var(--shadow);
}

.term-position {
  display: inline-flex;
  margin-bottom: 0.35rem;
  color: var(--primary);
  font-size: 0.72rem;
  font-weight: 850;
  letter-spacing: 0.05em;
  text-transform: uppercase;
}

.term-metrics {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 0.45rem;
}

.term-metrics span {
  min-width: 0;
  padding: 0.55rem;
  border-radius: var(--radius);
  background: var(--bg-subtle);
  color: var(--text-soft);
  font-size: 0.72rem;
  font-weight: 750;
}

.term-metrics strong {
  display: block;
  color: var(--text);
  font-size: 1rem;
  line-height: 1.1;
}

.period-list,
.period-table-list {
  display: grid;
  gap: 0.45rem;
}

.period-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.65rem;
  padding: 0.55rem;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: var(--bg-subtle);
}

.period-row strong,
.period-row span,
.period-table-list span {
  display: block;
}

.period-row span,
.period-table-list span {
  color: var(--text-soft);
  font-size: 0.76rem;
  font-weight: 700;
}

.period-actions {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 0.35rem;
  flex-wrap: wrap;
}

.micro-button,
.add-period-button {
  min-height: 1.8rem;
  padding: 0.24rem 0.5rem;
  font-size: 0.74rem;
  font-weight: 800;
}

.micro-button.danger {
  border-color: rgba(248, 113, 113, 0.3);
  background: var(--danger-soft);
  color: var(--danger);
}

.add-period-button {
  width: fit-content;
}

.term-actions {
  justify-content: flex-start;
}

.term-actions button,
.table-actions button {
  min-height: 2.05rem;
  padding: 0.34rem 0.68rem;
  font-size: 0.86rem;
}

.close-term-button {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  border-color: rgba(251, 191, 36, 0.3);
  background: var(--warn-soft);
  color: var(--warn);
  font-weight: 850;
}

.close-term-button:hover {
  border-color: rgba(251, 191, 36, 0.5);
  background: rgba(251, 191, 36, 0.15);
}

.lock-mark {
  position: relative;
  width: 0.86rem;
  height: 0.72rem;
  display: inline-block;
  border: 2px solid currentColor;
  border-radius: 0.16rem;
  transform: translateY(0.08rem);
}

.lock-mark::before {
  content: '';
  position: absolute;
  left: 50%;
  bottom: calc(100% - 0.08rem);
  width: 0.48rem;
  height: 0.42rem;
  border: 2px solid currentColor;
  border-bottom: 0;
  border-radius: 0.5rem 0.5rem 0 0;
  transform: translateX(-50%);
}

.term-menu {
  position: relative;
}

.term-menu summary {
  width: 2.05rem;
  min-height: 2.05rem;
  display: grid;
  place-items: center;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: var(--bg-card);
  color: var(--text-soft);
  font-size: 1.1rem;
  font-weight: 900;
  line-height: 1;
  cursor: pointer;
  list-style: none;
}

.term-menu summary::-webkit-details-marker {
  display: none;
}

.term-menu[open] summary,
.term-menu summary:hover {
  border-color: var(--border-strong);
  background: var(--bg-subtle);
  color: var(--text);
}

.term-menu-list {
  position: absolute;
  top: calc(100% + 0.35rem);
  left: 0;
  z-index: 8;
  min-width: 9rem;
  display: grid;
  gap: 0.3rem;
  padding: 0.4rem;
  border: 1px solid var(--border-strong);
  border-radius: var(--radius);
  background: var(--bg-card);
  box-shadow: 0 16px 32px rgba(0, 0, 0, 0.5);
}

.term-menu-list.align-right {
  right: 0;
  left: auto;
}

.term-menu-list button {
  width: 100%;
  justify-content: flex-start;
  text-align: left;
}

.table-actions {
  display: inline-flex;
  align-items: center;
  justify-content: flex-end;
  gap: 0.4rem;
  white-space: nowrap;
}

.term-table-card {
  overflow-x: auto;
}

.term-table-card table {
  min-width: 68rem;
}

.empty-action {
  display: grid;
  justify-items: center;
  gap: 0.65rem;
  padding: 2rem 1rem;
  text-align: center;
}

.empty-action h3 {
  margin: 0;
}

.empty-action p {
  max-width: 28rem;
  margin: 0;
  color: var(--text-soft);
}

.empty-icon {
  width: 3rem;
  height: 3rem;
  display: grid;
  place-items: center;
  border-radius: 50%;
  background: var(--primary-soft);
  color: var(--primary);
  font-size: 1.4rem;
  font-weight: 850;
}

.form-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.75rem;
}

.err {
  display: block;
  color: var(--danger);
  font-size: 0.78rem;
  margin-top: 0.25rem;
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
  background: linear-gradient(90deg, #0a1836, #0f2455, #0a1836);
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

/* ─── Hero archive state ─── */
.detail-hero.archived {
  background: linear-gradient(135deg, rgba(251, 191, 36, 0.08) 0%, rgba(251, 191, 36, 0.04) 100%);
  border-color: rgba(251, 191, 36, 0.3);
}

.archive-btn {
  font-weight: 800;
}

/* ─── Read-only banner ─── */
.archived-banner {
  display: flex;
  align-items: flex-start;
  gap: 0.85rem;
  padding: 0.95rem 1.1rem;
  border: 1px solid rgba(251, 191, 36, 0.3);
  border-radius: var(--radius);
  background: linear-gradient(135deg, rgba(251, 191, 36, 0.1) 0%, rgba(251, 191, 36, 0.05) 100%);
  box-shadow: var(--shadow);
}

.archived-banner strong {
  color: var(--warn);
  font-size: 0.92rem;
  font-weight: 800;
}

.archived-icon {
  width: 2.4rem;
  height: 2.4rem;
  display: grid;
  place-items: center;
  border-radius: 50%;
  background: var(--warn-soft);
  color: var(--warn);
  font-size: 0.78rem;
  font-weight: 900;
  letter-spacing: 0.04em;
}

/* ─── Tabs ─── */
.year-tabs {
  display: flex;
  flex-wrap: nowrap;
  gap: 0.2rem;
  overflow-x: auto;
  padding: 0.35rem 0 0;
  border-bottom: 1px solid var(--border);
  background: transparent;
  scrollbar-width: none;
}

.year-tabs::-webkit-scrollbar { display: none; }

.year-tabs button {
  flex: 0 0 auto;
  min-height: 2.45rem;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.45rem;
  padding: 0.4rem 0.85rem 0.58rem;
  border: 0;
  border-bottom: 2px solid transparent;
  background: transparent;
  color: var(--text-soft);
  box-shadow: none;
  font-size: 0.84rem;
  font-weight: 700;
  letter-spacing: 0.01em;
  border-radius: 0;
  transition: color 0.15s ease;
}

.year-tabs button small {
  min-width: 1.3rem;
  padding: 0.04rem 0.38rem;
  border-radius: 999px;
  background: var(--bg-subtle);
  color: var(--text-muted);
  font-size: 0.65rem;
  font-weight: 800;
  line-height: 1.4;
  transition: background 0.15s ease, color 0.15s ease;
}

.year-tabs button:hover {
  color: var(--text);
  background: var(--bg-subtle);
}

.year-tabs button.active {
  background: transparent;
  color: var(--primary-dark);
  border-bottom-color: var(--primary);
  font-weight: 800;
  box-shadow: none;
}

.year-tabs button.active small {
  background: var(--primary);
  color: white;
}

.active-tab-header {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  gap: 1rem;
  padding: 0.95rem 1.1rem;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: linear-gradient(180deg, var(--bg-card) 0%, var(--bg-soft) 100%);
  box-shadow: var(--shadow);
}

.active-tab-header h2 {
  margin: 0;
}

.active-tab-header p:last-child {
  margin: 0.22rem 0 0;
  color: var(--text-soft);
  font-size: 0.9rem;
}

.tab-context {
  display: flex;
  justify-content: flex-end;
  flex-wrap: wrap;
  gap: 0.35rem;
}

.tab-panel {
  display: grid;
  gap: 0.85rem;
}

/* ─── Badge variants ─── */
.badge-warn {
  background: var(--warn-soft);
  color: var(--warn);
  border: 1px solid rgba(251, 191, 36, 0.3);
}

/* ─── Students tab ─── */
.students-section,
.results-section,
.attendance-section,
.documents-section,
.history-section {
  display: grid;
  gap: 0.85rem;
}

.student-search {
  min-height: 2.25rem;
  min-width: 16rem;
  padding: 0.4rem 0.75rem;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: var(--bg-card);
  font-size: 0.88rem;
  font-weight: 600;
}

.students-heading {
  align-items: flex-start;
}

.students-actions {
  align-items: center;
}

.enroll-btn {
  display: inline-flex;
  align-items: center;
  min-height: 2.35rem;
  padding: 0.45rem 0.85rem;
  border-radius: var(--radius);
  font-size: 0.86rem;
  font-weight: 750;
  text-decoration: none;
}

.students-summary-bar {
  margin-top: 0.15rem;
}

.student-filters-panel {
  display: grid;
  grid-template-columns: minmax(12rem, 1.4fr) repeat(4, minmax(9rem, 1fr));
  gap: 0.65rem;
  align-items: end;
  padding: 0.85rem 1rem;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: var(--bg-card);
}

.student-search-field span {
  visibility: hidden;
}

.student-search-field input {
  width: 100%;
  min-height: 2.25rem;
  font-size: 0.88rem;
  font-weight: 700;
}

.active-filter-chips {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
}

.grouped-students-table table {
  min-width: 68rem;
}

.student-group-row td {
  padding-top: 0.85rem;
  padding-bottom: 0.35rem;
  border-bottom: 0;
  background: var(--bg-subtle);
}

.student-group-row strong {
  font-size: 0.72rem;
  letter-spacing: 0.06em;
}

.student-group-row span {
  margin-left: 0.45rem;
  color: var(--text-soft);
  font-size: 0.74rem;
  font-weight: 650;
}

.student-group-row.danger td {
  background: var(--danger-soft);
}

.student-data-row.danger {
  background: rgba(248, 113, 113, 0.06);
}

.student-data-row.warn {
  background: rgba(251, 191, 36, 0.06);
}

.student-cell-link {
  display: inline-flex;
  align-items: center;
  gap: 0.65rem;
  color: inherit;
  text-decoration: none;
}

.student-cell-link:hover strong {
  color: var(--primary);
}

.student-avatar {
  width: 2rem;
  height: 2rem;
  display: grid;
  place-items: center;
  border-radius: 999px;
  background: var(--primary-soft);
  color: var(--primary);
  font-size: 0.72rem;
  font-weight: 850;
  flex-shrink: 0;
}

.student-name-block {
  display: grid;
  gap: 0.05rem;
  min-width: 0;
}

.student-name-block strong {
  font-size: 0.86rem;
}

.student-name-block small {
  color: var(--text-muted);
  font-size: 0.72rem;
}

.students-footer-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  flex-wrap: wrap;
  padding: 0.65rem 0.85rem;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: var(--bg-card);
  color: var(--text-soft);
  font-size: 0.78rem;
}

.students-pagination {
  display: inline-flex;
  align-items: center;
  gap: 0.55rem;
}

.page-size-select {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  margin: 0;
  color: var(--text-soft);
  font-size: 0.76rem;
}

.page-size-select select {
  min-height: 1.9rem;
  padding: 0.2rem 0.45rem;
}

.page-btn {
  min-width: 2rem;
  min-height: 2rem;
  border: 1px solid var(--border);
  border-radius: 8px;
  background: var(--bg-subtle);
  color: var(--text);
  cursor: pointer;
}

.page-btn:disabled {
  opacity: 0.45;
  cursor: not-allowed;
}

.students-table-card {
  overflow-x: auto;
}

.students-table-card table {
  min-width: 56rem;
}

.student-link {
  color: var(--primary);
  font-weight: 750;
  text-decoration: none;
}

.student-link:hover {
  text-decoration: underline;
}

.status-pill {
  display: inline-flex;
  align-items: center;
  padding: 0.18rem 0.55rem;
  border-radius: 999px;
  font-size: 0.75rem;
  font-weight: 800;
  letter-spacing: 0.02em;
}

.status-pill.status-admis {
  background: var(--success-soft);
  color: var(--success);
}

.status-pill.status-redouble {
  background: var(--danger-soft);
  color: var(--danger);
}

.status-pill.status-en_cours {
  background: var(--primary-soft);
  color: var(--accent);
}

.status-hint {
  display: block;
  margin-top: 0.15rem;
  color: var(--text-soft);
  font-size: 0.7rem;
  font-weight: 650;
}

/* ─── Documents tab ─── */
.document-card {
  padding: 1rem 1.15rem 1.15rem;
}

.document-form {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(13rem, 1fr));
  gap: 0.75rem;
  align-items: end;
  margin-top: 0.75rem;
}

.document-form label {
  display: flex;
  flex-direction: column;
  gap: 0.3rem;
  font-size: 0.78rem;
  font-weight: 700;
  color: var(--text-soft);
}

.document-form select,
.document-form input[type='date'] {
  min-height: 2.25rem;
  padding: 0.35rem 0.6rem;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: var(--bg-card);
  font-size: 0.88rem;
  font-weight: 600;
}

.document-form button {
  min-height: 2.4rem;
}

.document-list {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr));
  gap: 0.6rem;
  padding: 0 1.15rem 1.15rem;
}

.document-link {
  display: grid;
  gap: 0.2rem;
  padding: 0.7rem 0.85rem;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: var(--bg-card);
  color: inherit;
  text-decoration: none;
  transition: border-color 0.15s ease, transform 0.15s ease;
}

.document-link:hover {
  border-color: var(--primary);
  text-decoration: none;
}

.document-link strong {
  color: var(--text);
  font-size: 0.95rem;
  font-weight: 800;
}

.document-link span {
  color: var(--text-soft);
  font-size: 0.8rem;
  font-weight: 650;
}

@media (max-width: 1160px) {
  .kpi-grid.structure,
  .kpi-grid.performance,
  .kpi-grid.identity,
  .kpi-grid.decisions {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }

  .analytics-grid {
    grid-template-columns: 1fr;
  }

}

@media (max-width: 720px) {
  .detail-hero,
  .section-heading,
  .active-tab-header {
    flex-direction: column;
    align-items: stretch;
  }

  .hero-actions,
  .hero-side,
  .hero-status-card,
  .section-heading .btn-secondary {
    width: 100%;
  }

  .hero-side {
    justify-items: stretch;
  }

  .hero-actions .btn-primary {
    width: 100%;
  }

  .section-toolbar,
  .view-toggle {
    width: 100%;
  }

  .view-toggle button {
    flex: 1;
    justify-content: center;
  }

  .class-filter,
  .student-search-field,
  .student-filters-panel {
    width: 100%;
  }

  .student-filters-panel {
    grid-template-columns: 1fr;
  }

  .students-actions {
    flex-wrap: wrap;
  }

  .students-footer-bar {
    flex-direction: column;
    align-items: stretch;
  }

  .class-summary-bar {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .class-footer-bar {
    flex-direction: column;
    align-items: stretch;
  }

  .kpi-grid.structure,
  .kpi-grid.performance,
  .kpi-grid.identity,
  .kpi-grid.decisions,
  .skeleton-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .term-metrics {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .form-grid {
    grid-template-columns: 1fr;
  }

  .tab-context {
    justify-content: flex-start;
  }
}
</style>
