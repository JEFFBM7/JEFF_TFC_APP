<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { api } from '../api/client'
import BarChart from '../components/charts/BarChart.vue'
import LineChart from '../components/charts/LineChart.vue'
import { useAuthStore } from '../stores/auth'
import { chartPercentFromAverage20, formatAveragePercentValue } from '../utils/grades'
import { useSchoolYearStore } from '../stores/schoolYear'
import type { ChartSeries, MonthlyAttendancePoint } from '../types'
import DevCalendarSimulator from '../components/DevCalendarSimulator.vue'
import SchoolCalendarBanner from '../components/SchoolCalendarBanner.vue'
import { useDevCalendarReload } from '../stores/devCalendar'
import StatCard from '../components/dashboard/StatCard.vue'
import {
  Users, BookOpen,
  AlertTriangle, FileEdit, CheckSquare,
  Inbox, BarChart2, ChevronDown, ChevronUp, ArrowUpDown,
  RefreshCw, TrendingDown, TrendingUp,
  GraduationCap, ClipboardList,
} from 'lucide-vue-next'

const schoolYearStore = useSchoolYearStore()

const auth = useAuthStore()

interface AdminDashboard {
  counts: Record<string, number>
  attendance: { total_absences: number; unjustified: number; total_lates: number; absence_rate_per_student: number }
  current_term: { id: number; name: string } | null
  period?: PeriodInfo
  available_months?: AvailableMonth[]
  available_terms?: AvailableTerm[]
  monthly_attendance?: MonthlyAttendancePoint[]
  monthly_averages?: MonthlyAveragePoint[]
  classrooms: { classroom_id: number; full_name: string; student_count: number; class_average: number | null; absences: number }[]
  insights?: DashboardInsights
}

interface DashboardInsights {
  institution_average: number | null
  institution_average_delta: number | null
  students_at_risk_count: number
  classes_with_unjustified_absences: number
  low_grade_threshold: number
  attendance_breakdown: {
    present_pct: number
    justified_absences_pct: number
    unjustified_absences_pct: number
  }
  top_students: { id: number; full_name: string; classroom: string | null; average: number }[]
  watchlist: { type: string; severity: 'danger' | 'warn'; title: string; detail: string }[]
}

interface MonthlyAveragePoint {
  value: string
  label: string
  short_label: string
  average: number | null
}

interface TeacherDashboard {
  teacher_name: string
  current_term: { id: number; name: string } | null
  assignments: {
    classroom_id: number; classroom: string
    subject_id: number; subject: string
    student_count: number; evaluations: number
    grades_entered: number; class_average: number | null
    absences: number
  }[]
  period?: PeriodInfo
  available_months?: AvailableMonth[]
  available_terms?: AvailableTerm[]
}

type PeriodKey = 'week' | 'month' | 'term' | 'year' | 'all'

interface PeriodInfo {
  key: PeriodKey
  label: string
  starts_on: string | null
  ends_on: string | null
}

interface AvailableMonth {
  value: string
  label: string
}

interface AvailableTerm {
  value: string
  label: string
}

interface ChartBar {
  id: number | string
  label: string
  value: number
  display: string
  percent: number
  tone?: 'good' | 'warn' | 'muted'
}

const adminData = ref<AdminDashboard | null>(null)
const teacherData = ref<TeacherDashboard | null>(null)
const loading = ref(false)
const error = ref('')
const selectedPeriod = ref<PeriodKey>('month')
const selectedMonth = ref(currentMonthValue())
const selectedTerm = ref('')
const showAllClassrooms = ref(false)

const MAIN_PERIOD_OPTIONS: { value: PeriodKey; label: string }[] = [
  { value: 'week', label: 'Semaine' },
  { value: 'month', label: 'Mois' },
  { value: 'term', label: 'Trimestre' },
  { value: 'year', label: 'Année' },
]

const PERIOD_OPTIONS: { value: PeriodKey; label: string }[] = [
  ...MAIN_PERIOD_OPTIONS,
  { value: 'all', label: 'Tout' },
]

const termLabel = computed(() => {
  if (adminData.value?.period) return adminData.value.period.label
  if (teacherData.value?.period) return teacherData.value.period.label
  if (adminData.value?.current_term) return adminData.value.current_term.name
  if (teacherData.value?.current_term) return teacherData.value.current_term.name
  return 'Aucun trimestre actif'
})

const availableMonths = computed<AvailableMonth[]>(() =>
  adminData.value?.available_months ?? teacherData.value?.available_months ?? [],
)

const availableTerms = computed<AvailableTerm[]>(() =>
  adminData.value?.available_terms ?? teacherData.value?.available_terms ?? [],
)

const periodDateRange = computed(() => {
  const period = adminData.value?.period ?? teacherData.value?.period
  if (!period?.starts_on || !period.ends_on) return ''
  return `${period.starts_on} - ${period.ends_on}`
})

function currentMonthValue(): string {
  const date = new Date()
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`
}

function ensureSelectedMonth(): boolean {
  const months = availableMonths.value
  if (months.length === 0 || months.some((month) => month.value === selectedMonth.value)) {
    return false
  }

  selectedMonth.value = months[0].value
  return true
}

function ensureSelectedTerm(): boolean {
  const terms = availableTerms.value
  if (terms.length === 0 || terms.some((term) => term.value === selectedTerm.value)) {
    return false
  }

  const currentTerm = adminData.value?.current_term ?? teacherData.value?.current_term
  const currentTermValue = currentTerm ? String(currentTerm.id) : ''
  selectedTerm.value = terms.some((term) => term.value === currentTermValue)
    ? currentTermValue
    : terms[0].value

  return true
}

const overviewTitle = computed(() => {
  const period = adminData.value?.period
  if (!period) return "VUE D'ENSEMBLE"
  return `VUE D'ENSEMBLE — ${period.label.toUpperCase()}`
})

const adminInsights = computed(() => adminData.value?.insights ?? null)

const adminMonthlyAverageCategories = computed(() =>
  (adminData.value?.monthly_averages ?? [])
    .filter((item) => item.average !== null)
    .map((item) => item.short_label),
)

const adminMonthlyAverageSeries = computed<ChartSeries[]>(() => {
  const rows = (adminData.value?.monthly_averages ?? []).filter((item) => item.average !== null)
  return [{ name: 'Moyenne', data: rows.map((item) => chartPercentFromAverage20(item.average as number) ?? 0) }]
})

const attendanceBreakdownRows = computed(() => {
  const breakdown = adminInsights.value?.attendance_breakdown
  if (!breakdown) return []
  return [
    { label: 'Présents', value: breakdown.present_pct, tone: 'good' as const },
    { label: 'Absences justifiées', value: breakdown.justified_absences_pct, tone: 'warn' as const },
    { label: 'Absences non justif.', value: breakdown.unjustified_absences_pct, tone: 'danger' as const },
  ]
})

const previewClassrooms = computed(() => {
  if (!adminData.value) return []
  return [...adminData.value.classrooms]
    .sort((a, b) => (b.class_average ?? -1) - (a.class_average ?? -1))
    .slice(0, showAllClassrooms.value ? adminData.value.classrooms.length : 4)
})

const hiddenClassroomsCount = computed(() => {
  if (!adminData.value) return 0
  return Math.max(0, adminData.value.classrooms.length - 4)
})

const averageDeltaLabel = computed(() => {
  const delta = adminInsights.value?.institution_average_delta
  if (delta === null || delta === undefined) return null
  const pctDelta = chartPercentFromAverage20(delta) ?? 0
  const sign = pctDelta >= 0 ? '+' : ''
  return `${sign}${pctDelta.toFixed(1).replace('.', ',')} % vs période préc.`
})

const attendancePeriodNote = computed(() => {
  const students = adminData.value?.counts.students ?? 0
  const period = adminData.value?.period
  if (!period?.starts_on || !period.ends_on) {
    return `Sur ${students} élève(s)`
  }
  const start = new Date(`${period.starts_on}T00:00:00`)
  const end = new Date(`${period.ends_on}T00:00:00`)
  const formatter = new Intl.DateTimeFormat('fr-FR', { day: 'numeric', month: 'long' })
  return `Sur ${students} élève(s) · période du ${formatter.format(start)} au ${formatter.format(end)}`
})

const teacherTotals = computed(() => {
  const assignments = teacherData.value?.assignments ?? []
  return {
    classes: new Set(assignments.map((a) => a.classroom_id)).size,
    students: assignments.reduce((sum, a) => sum + a.student_count, 0),
    evaluations: assignments.reduce((sum, a) => sum + a.evaluations, 0),
    grades: assignments.reduce((sum, a) => sum + a.grades_entered, 0),
    absences: assignments.reduce((sum, a) => sum + a.absences, 0),
  }
})

const teacherAverageBars = computed<ChartBar[]>(() => {
  const assignments = teacherData.value?.assignments ?? []
  return assignments.map((a, index) => {
    const value = a.class_average ?? 0
    return {
      id: `${a.classroom_id}-${a.subject_id}-${index}`,
      label: `${a.classroom} · ${a.subject}`,
      value,
      display: a.class_average !== null ? `${formatAveragePercentValue(a.class_average, 1)} %` : '-',
      percent: Math.max(4, Math.min(100, chartPercentFromAverage20(value) ?? 0)),
      tone: a.class_average === null ? 'muted' : value >= 10 ? 'good' : 'warn',
    }
  })
})

const teacherAverageChartSeries = computed<ChartSeries[]>(() => [
  { name: 'Moyenne', data: teacherAverageBars.value.map((bar) => chartPercentFromAverage20(bar.value) ?? 0) },
])

const teacherAverageChartCategories = computed(() =>
  teacherAverageBars.value.map((bar) => bar.label),
)

const teacherGradeBars = computed<ChartBar[]>(() => {
  const assignments = teacherData.value?.assignments ?? []
  const maxGrades = Math.max(1, ...assignments.map((a) => a.grades_entered))

  return assignments.map((a, index) => ({
    id: `${a.classroom_id}-${a.subject_id}-${index}`,
    label: `${a.classroom} · ${a.subject}`,
    value: a.grades_entered,
    display: String(a.grades_entered),
    percent: Math.max(4, (a.grades_entered / maxGrades) * 100),
    tone: a.grades_entered > 0 ? 'good' : 'muted',
  }))
})

// ── Synthèse admin ──────────────────────────────────────
const userInitials = computed(() => {
  const name = auth.user?.name?.trim() ?? ''
  if (!name) return '?'
  return name
    .split(/\s+/)
    .slice(0, 2)
    .map((part) => part.charAt(0).toUpperCase())
    .join('')
})

const todayLabel = computed(() => {
  const formatter = new Intl.DateTimeFormat('fr-FR', {
    weekday: 'long', day: 'numeric', month: 'long', year: 'numeric',
  })
  return formatter.format(new Date())
})

const schoolYearLabel = computed(
  () => schoolYearStore.selected?.name ?? schoolYearStore.current?.name ?? 'Année scolaire',
)

const sortKey = ref<'full_name' | 'student_count' | 'class_average' | 'absences'>('full_name')
const sortDesc = ref(false)

const sortedAdminClassrooms = computed(() => {
  if (!adminData.value) return []
  const classes = [...adminData.value.classrooms]
  classes.sort((a, b) => {
    let valA: any = a[sortKey.value]
    let valB: any = b[sortKey.value]
    if (valA === null) valA = -1
    if (valB === null) valB = -1
    if (valA < valB) return sortDesc.value ? 1 : -1
    if (valA > valB) return sortDesc.value ? -1 : 1
    return 0
  })
  return classes
})

const sortBy = (key: 'full_name' | 'student_count' | 'class_average' | 'absences') => {
  if (sortKey.value === key) {
    sortDesc.value = !sortDesc.value
  } else {
    sortKey.value = key
    sortDesc.value = false
  }
}

function formatScore(value: number | null | undefined): string {
  return formatAveragePercentValue(value, 1)
}

function formatScoreDisplay(value: number | null | undefined): string {
  const formatted = formatScore(value)
  return formatted === '—' ? formatted : `${formatted} %`
}

function classroomAverageTone(value: number | null | undefined): string {
  if (value === null || value === undefined) return 'muted'
  return value >= 10 ? 'good' : 'danger'
}

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  showAllClassrooms.value = false
  const query: { period: PeriodKey; month?: string; term_id?: string } = { period: selectedPeriod.value }
  if (selectedPeriod.value === 'month') {
    query.month = selectedMonth.value
  }
  if (selectedPeriod.value === 'term' && selectedTerm.value !== '') {
    query.term_id = selectedTerm.value
  }

  try {
    if (auth.hasRole('admin')) {
      const res = await api<{ data: AdminDashboard }>('/api/v1/admin/dashboard', {
        query,
      })
      adminData.value = res.data
    } else if (auth.hasRole('enseignant')) {
      const res = await api<{ data: TeacherDashboard }>('/api/v1/teacher/dashboard', {
        query,
      })
      teacherData.value = res.data
    }
  } catch {
    error.value = 'Impossible de charger le tableau de bord.'
  } finally {
    loading.value = false
  }
}

onMounted(load)
useDevCalendarReload(() => void load())
watch(selectedPeriod, () => {
  if (selectedPeriod.value === 'month' && ensureSelectedMonth()) return
  if (selectedPeriod.value === 'term' && ensureSelectedTerm()) return
  void load()
})
watch(selectedMonth, () => {
  if (selectedPeriod.value === 'month') void load()
})
watch(selectedTerm, () => {
  if (selectedPeriod.value === 'term') void load()
})
watch(availableMonths, () => {
  if (selectedPeriod.value === 'month') ensureSelectedMonth()
})
watch(availableTerms, () => {
  if (selectedPeriod.value === 'term') ensureSelectedTerm()
})
// Recharge le tableau de bord quand l'utilisateur bascule d'année.
watch(
  () => schoolYearStore.effectiveId,
  () => {
    void load()
  },
)
</script>

<template>
  <section class="dashboard-page" :class="{ 'dashboard-page--admin': !!adminData }">
    <!-- En-tête admin — éditorial institutionnel -->
    <header v-if="adminData" class="admin-hero admin-reveal">
      <div class="admin-hero__grain" aria-hidden="true" />
      <div class="admin-hero__glow" aria-hidden="true" />
      <div class="admin-hero__content">
        <div class="admin-hero__main">
          <p class="admin-hero__eyebrow">
            <span class="admin-hero__dot" aria-hidden="true" />
            {{ schoolYearLabel }}
          </p>
          <h1 class="admin-hero__title">
            <span class="admin-hero__title-small">Tableau de bord administrateur</span>
            <span class="admin-hero__title-main">Complexe Scolaire <em>MALUNGA</em></span>
          </h1>
          <p class="admin-hero__meta">{{ todayLabel }}</p>
        </div>
        <div class="admin-hero__aside">
          <div class="admin-hero__toolbar" aria-label="Filtrer par période">
            <div class="admin-period-pills">
              <button
                v-for="option in MAIN_PERIOD_OPTIONS"
                :key="option.value"
                type="button"
                :class="{ active: selectedPeriod === option.value }"
                @click="selectedPeriod = option.value"
              >
                {{ option.label }}
              </button>
            </div>
            <label v-if="selectedPeriod === 'month'" class="admin-period-select custom-dropdown">
              <span>Mois</span>
              <div class="select-wrapper">
                <select v-model="selectedMonth" :disabled="availableMonths.length === 0">
                  <option v-for="month in availableMonths" :key="month.value" :value="month.value">{{ month.label }}</option>
                </select>
                <ChevronDown class="select-icon" />
              </div>
            </label>
            <label v-if="selectedPeriod === 'term'" class="admin-period-select custom-dropdown">
              <span>Trimestre</span>
              <div class="select-wrapper">
                <select v-model="selectedTerm" :disabled="availableTerms.length === 0">
                  <option v-for="term in availableTerms" :key="term.value" :value="term.value">{{ term.label }}</option>
                </select>
                <ChevronDown class="select-icon" />
              </div>
            </label>
            <button
              type="button"
              class="admin-refresh-btn"
              aria-label="Rafraîchir les données"
              :disabled="loading"
              @click="load"
            >
              <RefreshCw :class="{ spinning: loading }" />
            </button>
          </div>
          <SchoolCalendarBanner variant="below" />
        </div>
      </div>
    </header>

    <!-- En-tête enseignant / autres -->
    <header v-else class="dashboard-hero">
      <div class="hero-identity">
        <div class="hero-avatar" aria-hidden="true">{{ userInitials }}</div>
        <div class="hero-text">
          <p class="eyebrow"><span>{{ todayLabel }}</span></p>
          <h1>Bonjour, {{ auth.user?.name }}</h1>
          <p class="hero-meta">
            <span class="hero-tag">
              <GraduationCap class="hero-tag-icon" />
              <span>{{ termLabel }}</span>
            </span>
            <template v-if="periodDateRange">
              <span class="hero-meta-sep">·</span>
              <span class="hero-meta-range">{{ periodDateRange }}</span>
            </template>
          </p>
        </div>
      </div>
      <div class="hero-actions">
        <div class="hero-pill"><span>{{ auth.user?.role }}</span></div>
        <button type="button" class="icon-btn refresh-btn" aria-label="Rafraîchir" :disabled="loading" @click="load">
          <RefreshCw :class="{ spinning: loading }" />
        </button>
      </div>
    </header>

    <div class="toolbar" v-if="!adminData && auth.hasRole('admin', 'enseignant')">
      <div class="period-filter" aria-label="Filtrer par période">
        <button
          v-for="option in PERIOD_OPTIONS"
          :key="option.value"
          type="button"
          :class="{ active: selectedPeriod === option.value }"
          @click="selectedPeriod = option.value"
        >
          {{ option.label }}
        </button>
      </div>
      <label v-if="selectedPeriod === 'month'" class="period-select custom-dropdown">
        <span>Mois</span>
        <div class="select-wrapper">
          <select v-model="selectedMonth" :disabled="availableMonths.length === 0">
            <option v-for="month in availableMonths" :key="month.value" :value="month.value">{{ month.label }}</option>
            <option v-if="availableMonths.length === 0" :value="selectedMonth">Mois courant</option>
          </select>
          <ChevronDown class="select-icon" />
        </div>
      </label>
      <label v-if="selectedPeriod === 'term'" class="period-select custom-dropdown">
        <span>Trimestre</span>
        <div class="select-wrapper">
          <select v-model="selectedTerm" :disabled="availableTerms.length === 0">
            <option v-for="term in availableTerms" :key="term.value" :value="term.value">{{ term.label }}</option>
            <option v-if="availableTerms.length === 0" value="">Aucun trimestre</option>
          </select>
          <ChevronDown class="select-icon" />
        </div>
      </label>
    </div>

    <p v-if="error" class="alert alert-error">{{ error }}</p>

    <div v-if="loading" class="skeleton-wrapper" :class="{ 'admin-skeleton': !!adminData || auth.hasRole('admin') }">
      <div class="kpi-grid overview-grid">
        <div v-for="i in 4" :key="i" class="kpi-card skeleton"></div>
      </div>
      <div class="analytics-grid" style="margin-top: 1rem;">
        <div class="card chart-card skeleton skeleton-tall"></div>
        <div class="card chart-card skeleton skeleton-tall"></div>
      </div>
    </div>

    <!-- Admin -->
    <template v-else-if="adminData">
      <section class="admin-metrics admin-reveal" style="--reveal-order: 1">
        <div class="admin-section-head">
          <span class="admin-section-num" aria-hidden="true">01</span>
          <div class="admin-section-copy">
            <h2>{{ overviewTitle }}</h2>
            <p v-if="periodDateRange">{{ periodDateRange }}</p>
          </div>
        </div>
        <div class="admin-metrics-grid">
          <StatCard
            variant="editorial"
            label="Élèves inscrits"
            :value="adminData.counts.students"
            :note="`${adminData.counts.classrooms} classes actives`"
            :icon="Users"
            class="admin-metric-card"
            style="--reveal-order: 1"
          />
          <StatCard
            variant="editorial"
            label="Moyenne générale"
            :value="formatScoreDisplay(adminInsights?.institution_average)"
            :note="averageDeltaLabel ?? 'Sur les classes notées'"
            :icon="GraduationCap"
            tone="good"
            class="admin-metric-card"
            style="--reveal-order: 2"
          />
          <StatCard
            variant="editorial"
            label="Absences non justif."
            :value="adminData.attendance.unjustified"
            :note="`${adminInsights?.classes_with_unjustified_absences ?? 0} classes concernées`"
            :icon="AlertTriangle"
            tone="warn"
            class="admin-metric-card"
            style="--reveal-order: 3"
          />
          <StatCard
            variant="editorial"
            label="Élèves à surveiller"
            :value="adminInsights?.students_at_risk_count ?? 0"
            :note="`Moy. < ${adminInsights?.low_grade_threshold ?? 10}`"
            :icon="TrendingDown"
            tone="danger"
            class="admin-metric-card"
            style="--reveal-order: 4"
          />
        </div>
      </section>

      <div class="admin-chart-grid admin-reveal" style="--reveal-order: 2">
        <div class="admin-panel admin-panel--chart">
          <div class="admin-panel__head">
            <span class="admin-section-num" aria-hidden="true">02</span>
            <div>
              <h2>Évolution mensuelle des moyennes</h2>
              <p>Tendance des résultats sur l'année scolaire</p>
            </div>
          </div>
          <div class="admin-panel__body">
            <LineChart
              v-if="adminMonthlyAverageCategories.length"
              :series="adminMonthlyAverageSeries"
              :categories="adminMonthlyAverageCategories"
              :height="300"
              :y-max="20"
              tooltip-suffix="%"
              :colors="['#2d6a4f']"
            />
            <div v-else class="empty-state-pro admin-empty">
              <BarChart2 class="empty-icon" />
              <p>Aucune moyenne mensuelle disponible.</p>
            </div>
          </div>
        </div>

        <div class="admin-panel admin-panel--attendance">
          <div class="admin-panel__head">
            <span class="admin-section-num" aria-hidden="true">03</span>
            <div>
              <h2>Assiduité globale</h2>
              <p>{{ attendancePeriodNote }}</p>
            </div>
          </div>
          <div class="admin-attendance-bars">
            <div v-for="(row, rowIdx) in attendanceBreakdownRows" :key="row.label" class="admin-attendance-row">
              <div class="admin-attendance-head">
                <span>{{ row.label }}</span>
                <strong>{{ row.value.toFixed(1).replace('.', ',') }}%</strong>
              </div>
              <div class="admin-attendance-track">
                <span
                  class="admin-attendance-fill"
                  :class="row.tone"
                  :style="{ width: `${row.value}%`, animationDelay: `${rowIdx * 0.12}s` }"
                />
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="admin-bottom-grid admin-reveal" style="--reveal-order: 3">
        <div class="admin-panel admin-panel--classes">
          <div class="admin-panel__head admin-panel__head--split">
            <h2><ClipboardList class="admin-panel-icon" /> Résultats par classe</h2>
            <button
              v-if="hiddenClassroomsCount > 0 && !showAllClassrooms"
              type="button"
              class="admin-link-btn"
              @click="showAllClassrooms = true"
            >
              Voir tout
            </button>
          </div>
          <ul v-if="previewClassrooms.length" class="admin-class-list">
            <li v-for="c in previewClassrooms" :key="c.classroom_id" class="admin-class-row">
              <div class="admin-class-info">
                <strong>{{ c.full_name }}</strong>
                <span>{{ c.student_count }} élèves · {{ c.absences }} abs.</span>
              </div>
              <span class="admin-class-score" :class="classroomAverageTone(c.class_average)">
                {{ formatScoreDisplay(c.class_average) }}
              </span>
            </li>
          </ul>
          <div v-else class="empty-state-pro admin-empty compact">
            <p>Aucune classe avec des résultats.</p>
          </div>
          <p v-if="hiddenClassroomsCount > 0 && !showAllClassrooms" class="admin-panel-foot">
            + {{ hiddenClassroomsCount }} autres classes
          </p>
        </div>

        <div class="admin-panel admin-panel--top">
          <div class="admin-panel__head">
            <h2><TrendingUp class="admin-panel-icon good" /> Top performers</h2>
          </div>
          <ol v-if="(adminInsights?.top_students ?? []).length" class="admin-top-list">
            <li v-for="(student, idx) in adminInsights?.top_students ?? []" :key="student.id" class="admin-top-row">
              <span class="admin-top-rank">{{ idx + 1 }}</span>
              <div class="admin-top-info">
                <strong>{{ student.full_name }}</strong>
                <small v-if="student.classroom">{{ student.classroom }}</small>
              </div>
              <span class="admin-top-score">{{ formatScoreDisplay(student.average) }}</span>
            </li>
          </ol>
          <div v-else class="empty-state-pro admin-empty compact">
            <p>Aucun élève classé pour ce trimestre.</p>
          </div>
        </div>

        <div class="admin-panel admin-panel--watch">
          <div class="admin-panel__head">
            <h2><TrendingDown class="admin-panel-icon danger" /> À surveiller</h2>
          </div>
          <div v-if="(adminInsights?.watchlist ?? []).length" class="admin-watchlist">
            <article
              v-for="(alert, idx) in adminInsights?.watchlist ?? []"
              :key="`${alert.type}-${alert.title}-${idx}`"
              class="admin-watch-alert"
              :class="alert.severity"
            >
              <strong>{{ alert.title }}</strong>
              <span>{{ alert.detail }}</span>
            </article>
          </div>
          <div v-else class="empty-state-pro admin-empty compact">
            <p>Tout est dans la norme pour cette période.</p>
          </div>
        </div>
      </div>

      <details v-if="adminData.classrooms.length > 0" class="admin-panel admin-panel--table admin-reveal expandable-table" style="--reveal-order: 4">
        <summary>Détail complet par classe</summary>
        <div class="table-responsive">
          <table>
            <thead>
              <tr>
                <th @click="sortBy('full_name')" class="sortable">
                  Classe
                  <ArrowUpDown class="sort-icon" v-if="sortKey !== 'full_name'" />
                  <ChevronDown class="sort-icon" v-else-if="sortDesc" />
                  <ChevronUp class="sort-icon" v-else />
                </th>
                <th @click="sortBy('student_count')" class="sortable text-right">Effectif</th>
                <th @click="sortBy('class_average')" class="sortable text-right">Moyenne</th>
                <th @click="sortBy('absences')" class="sortable text-right">Absences</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="c in sortedAdminClassrooms" :key="c.classroom_id">
                <td>{{ c.full_name }}</td>
                <td class="text-right">{{ c.student_count }}</td>
                <td class="text-right">
                  <span v-if="c.class_average !== null" class="badge" :class="c.class_average >= 10 ? 'badge-success' : 'badge-danger'">
                    {{ formatScoreDisplay(c.class_average) }}
                  </span>
                  <span v-else class="text-muted">—</span>
                </td>
                <td class="text-right">{{ c.absences }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </details>
    </template>

    <!-- Enseignant -->
    <template v-else-if="teacherData">
      <div class="kpi-grid teacher">
        <div class="kpi-card">
          <div class="kpi-card-header">
            <span class="kpi-label">Classes</span>
            <div class="kpi-icon"><BookOpen /></div>
          </div>
          <span class="kpi-value">{{ teacherTotals.classes }}</span>
          <span class="kpi-note">Affectées</span>
        </div>
        <div class="kpi-card">
          <div class="kpi-card-header">
            <span class="kpi-label">Élèves</span>
            <div class="kpi-icon"><Users /></div>
          </div>
          <span class="kpi-value">{{ teacherTotals.students }}</span>
          <span class="kpi-note">Suivis</span>
        </div>
        <div class="kpi-card">
          <div class="kpi-card-header">
            <span class="kpi-label">Évaluations</span>
            <div class="kpi-icon"><FileEdit /></div>
          </div>
          <span class="kpi-value">{{ teacherTotals.evaluations }}</span>
          <span class="kpi-note">Trimestre</span>
        </div>
        <div class="kpi-card">
          <div class="kpi-card-header">
            <span class="kpi-label">Notes saisies</span>
            <div class="kpi-icon"><CheckSquare /></div>
          </div>
          <span class="kpi-value">{{ teacherTotals.grades }}</span>
          <span class="kpi-note">Toutes classes</span>
        </div>
        <div class="kpi-card warn">
          <div class="kpi-card-header">
            <span class="kpi-label">Absences</span>
            <div class="kpi-icon"><AlertTriangle /></div>
          </div>
          <span class="kpi-value">{{ teacherTotals.absences }}</span>
          <span class="kpi-note">Sur mes cours</span>
        </div>
      </div>

      <template v-if="teacherData.assignments.length > 0">
        <div class="analytics-grid">
          <div class="card chart-card chart-card-wide">
            <div class="card-header">
            <div>
              <h2>Moyennes par affectation</h2>
              <p>Classe et cours</p>
            </div>
          </div>
            <BarChart
              :series="teacherAverageChartSeries"
              :categories="teacherAverageChartCategories"
              :height="320"
              :horizontal="true"
              :y-max="20"
              tooltip-suffix="%"
            />
          </div>

          <div class="card chart-card">
            <div class="card-header">
              <div>
                <h2>Saisie des notes</h2>
                <p>Volume par cours</p>
              </div>
            </div>
            <div class="mini-bars">
              <div v-for="bar in teacherGradeBars" :key="bar.id" class="mini-bar">
                <span>{{ bar.label }}</span>
                <div class="mini-track">
                  <i :style="{ width: `${bar.percent}%` }" :data-tooltip="`Notes: ${bar.display}`" />
                </div>
                <strong>{{ bar.display }}</strong>
              </div>
            </div>
          </div>
        </div>

        <div class="card data-card">
          <div class="card-header"><h2>Mes classes &amp; cours</h2></div>
          <table>
            <thead>
              <tr>
                <th>Classe</th>
                <th>Cours</th>
                <th style="text-align: right">Élèves</th>
                <th style="text-align: right">Évaluations</th>
                <th style="text-align: right">Notes saisies</th>
                <th style="text-align: right">Moyenne</th>
                <th style="text-align: right">Absences</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(a, i) in teacherData.assignments" :key="i">
                <td>{{ a.classroom }}</td>
                <td>{{ a.subject }}</td>
                <td style="text-align: right">{{ a.student_count }}</td>
                <td style="text-align: right">{{ a.evaluations }}</td>
                <td style="text-align: right">{{ a.grades_entered }}</td>
                <td style="text-align: right">
                  <span v-if="a.class_average !== null" class="badge" :class="a.class_average >= 10 ? 'badge-success' : 'badge-danger'">
                    <span class="badge-dot"></span>
                    {{ formatAveragePercentValue(a.class_average, 1) }} %
                  </span>
                  <span v-else class="text-muted">-</span>
                </td>
                <td style="text-align: right">{{ a.absences }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>

      <div v-else class="empty-state-pro">
        <Inbox class="empty-icon" />
        <p>Aucune affectation trouvée pour votre profil enseignant.</p>
      </div>
    </template>

    <DevCalendarSimulator v-if="auth.hasRole('admin', 'enseignant', 'secretariat')" class="dashboard-dev-calendar" />
  </section>
</template>

<style scoped>
.dashboard-dev-calendar {
  margin-top: 1.5rem;
}

.dashboard-page {
  display: flex;
  flex-direction: column;
  gap: 1.25rem;
  color: var(--text);
}

.overview-grid {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 0.85rem;
}

.admin-bottom-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 1rem;
  align-items: start;
}

.empty-state-pro.compact {
  min-height: 4rem;
  padding: 1.25rem;
}

/* ══════════════════════════════════════════════════════════
   Dashboard admin — thème éditorial institutionnel
   ══════════════════════════════════════════════════════════ */
.dashboard-page--admin {
  --admin-ink: #1a2744;
  --admin-cream: #faf7f2;
  --admin-gold: #c9a227;
  --admin-sage: #2d6a4f;
  --admin-terra: #c45c26;
  --admin-wine: #9b2c2c;
  --admin-muted: #6b7289;
  --admin-border: rgba(26, 39, 68, 0.1);
  gap: 1.5rem;
}

/* Hero */
.admin-hero {
  position: relative;
  border-radius: 18px;
  overflow: hidden;
  border: 1px solid var(--admin-border);
  background:
    linear-gradient(128deg, #1a2744 0%, #243656 42%, #1e3350 100%);
  box-shadow: 0 24px 48px rgba(26, 39, 68, 0.18);
}

.admin-hero__grain {
  position: absolute;
  inset: 0;
  opacity: 0.35;
  background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.85' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='0.5'/%3E%3C/svg%3E");
  pointer-events: none;
  mix-blend-mode: overlay;
}

.admin-hero__glow {
  position: absolute;
  top: -30%;
  right: -5%;
  width: 50%;
  height: 120%;
  background: radial-gradient(ellipse, rgba(201, 162, 39, 0.22) 0%, transparent 65%);
  pointer-events: none;
}

.admin-hero__content {
  position: relative;
  display: grid;
  grid-template-columns: minmax(0, 1.2fr) minmax(280px, 0.9fr);
  gap: 1.5rem 2rem;
  padding: 1.65rem 1.75rem 1.5rem;
  align-items: start;
}

.admin-hero__eyebrow {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  margin: 0 0 0.65rem;
  font-size: 0.72rem;
  font-weight: 600;
  letter-spacing: 0.14em;
  text-transform: uppercase;
  color: rgba(250, 247, 242, 0.72);
}

.admin-hero__dot {
  width: 0.45rem;
  height: 0.45rem;
  border-radius: 50%;
  background: var(--admin-gold);
  box-shadow: 0 0 12px rgba(201, 162, 39, 0.6);
}

.admin-hero__title {
  margin: 0;
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
}

.admin-hero__title-small {
  font-size: 0.82rem;
  font-weight: 500;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: rgba(250, 247, 242, 0.55);
}

.admin-hero__title-main {
  font-size: clamp(1.55rem, 3vw, 2.15rem);
  font-weight: 800;
  line-height: 1.08;
  letter-spacing: -0.02em;
  color: #faf7f2;
}

.admin-hero__title-main em {
  font-style: italic;
  color: #e8c96a;
}

.admin-hero__meta {
  margin: 0.55rem 0 0;
  font-size: 0.88rem;
  color: rgba(250, 247, 242, 0.5);
  text-transform: capitalize;
}

.admin-hero__aside {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 0.35rem;
}

.admin-hero__toolbar {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 0.5rem;
  justify-content: flex-end;
}

.admin-period-pills {
  display: inline-flex;
  gap: 0.2rem;
  padding: 0.22rem;
  border-radius: 11px;
  background: rgba(0, 0, 0, 0.22);
  border: 1px solid rgba(255, 255, 255, 0.08);
  backdrop-filter: blur(8px);
}

.admin-period-pills button {
  min-height: 2rem;
  padding: 0.3rem 0.8rem;
  border: 0;
  border-radius: 8px;
  background: transparent;
  color: rgba(250, 247, 242, 0.6);
  font-size: 0.76rem;
  font-weight: 600;
  box-shadow: none;
  cursor: pointer;
  transition: all 0.22s ease;
}

.admin-period-pills button:hover {
  color: #faf7f2;
  background: rgba(255, 255, 255, 0.06);
  transform: none;
}

.admin-period-pills button.active {
  background: rgba(201, 162, 39, 0.9);
  color: #1a2744;
  font-weight: 700;
}

.admin-period-select {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  min-height: 2.1rem;
  padding: 0.2rem 0.5rem 0.2rem 0.6rem;
  border-radius: 10px;
  border: 1px solid rgba(255, 255, 255, 0.12);
  background: rgba(0, 0, 0, 0.18);
  margin: 0;
}

.admin-period-select span {
  font-size: 0.65rem;
  font-weight: 600;
  letter-spacing: 0.1em;
  text-transform: uppercase;
  color: rgba(250, 247, 242, 0.5);
}

.admin-period-select select {
  min-height: 1.75rem;
  min-width: 7.5rem;
  padding: 0.2rem 1.6rem 0.2rem 0.4rem;
  border: 0;
  background: transparent;
  color: #faf7f2;
  font-size: 0.78rem;
  font-weight: 600;
  box-shadow: none;
}

.admin-period-select .select-icon {
  color: rgba(232, 201, 106, 0.9);
}

.admin-refresh-btn {
  display: grid;
  place-items: center;
  width: 2.15rem;
  height: 2.15rem;
  border-radius: 10px;
  border: 1px solid rgba(255, 255, 255, 0.12);
  background: rgba(0, 0, 0, 0.18);
  color: rgba(250, 247, 242, 0.7);
  cursor: pointer;
  box-shadow: none;
  transition: all 0.2s ease;
}

.admin-refresh-btn:hover:not(:disabled) {
  background: rgba(201, 162, 39, 0.2);
  color: #e8c96a;
  border-color: rgba(201, 162, 39, 0.35);
  transform: none;
}

.admin-refresh-btn svg {
  width: 1rem;
  height: 1rem;
}

/* Sections */
.admin-section-head {
  display: flex;
  align-items: flex-start;
  gap: 1rem;
  margin-bottom: 1rem;
}

.admin-section-num {
  font-size: 2.5rem;
  font-weight: 800;
  line-height: 1;
  color: rgba(201, 162, 39, 0.35);
  user-select: none;
}

.admin-section-copy h2 {
  margin: 0;
  font-size: 1.15rem;
  font-weight: 800;
  color: var(--admin-ink);
  letter-spacing: -0.01em;
}

.admin-section-copy p {
  margin: 0.2rem 0 0;
  font-size: 0.82rem;
  color: var(--admin-muted);
}

.admin-metrics-grid {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 1rem;
}

.admin-metric-card {
  animation: adminMetricIn 0.55s cubic-bezier(0.16, 1, 0.3, 1) both;
  animation-delay: calc(var(--reveal-order, 0) * 0.08s);
}

@keyframes adminMetricIn {
  from { opacity: 0; transform: translateY(14px) scale(0.98); }
  to { opacity: 1; transform: translateY(0) scale(1); }
}

/* Panels */
.admin-panel {
  border-radius: 16px;
  border: 1px solid var(--admin-border);
  background: linear-gradient(160deg, #ffffff 0%, var(--admin-cream) 100%);
  box-shadow: 0 8px 28px rgba(26, 39, 68, 0.06);
  overflow: hidden;
}

.admin-panel__head {
  display: flex;
  align-items: flex-start;
  gap: 0.85rem;
  padding: 1.1rem 1.25rem 0.85rem;
  border-bottom: 1px solid var(--admin-border);
}

.admin-panel__head--split {
  align-items: center;
  justify-content: space-between;
}

.admin-panel__head h2 {
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
  margin: 0;
  font-size: 1rem;
  font-weight: 700;
  color: var(--admin-ink);
}

.admin-panel__head p {
  margin: 0.2rem 0 0;
  font-size: 0.8rem;
  color: var(--admin-muted);
}

.admin-panel__body {
  padding: 0.5rem 0.75rem 1rem;
}

.admin-panel-icon {
  width: 1rem;
  height: 1rem;
  color: var(--admin-gold);
}

.admin-panel-icon.good { color: var(--admin-sage); }
.admin-panel-icon.danger { color: var(--admin-wine); }

.admin-panel--chart { grid-column: span 1; }
.admin-panel--attendance { display: flex; flex-direction: column; }

.admin-link-btn {
  border: 0;
  background: transparent;
  color: var(--admin-gold);
  font-size: 0.78rem;
  font-weight: 700;
  cursor: pointer;
  padding: 0;
  letter-spacing: 0.02em;
}

.admin-link-btn:hover { text-decoration: underline; }

/* Listes */
.admin-class-list,
.admin-top-list {
  list-style: none;
  margin: 0;
  padding: 0.65rem 1rem 1rem;
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
}

.admin-class-row,
.admin-top-row {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  align-items: center;
  gap: 0.75rem;
  padding: 0.7rem 0.85rem;
  border-radius: 10px;
  background: rgba(26, 39, 68, 0.03);
  border: 1px solid transparent;
  transition: border-color 0.2s ease, background 0.2s ease;
}

.admin-class-row:hover,
.admin-top-row:hover {
  background: rgba(201, 162, 39, 0.06);
  border-color: rgba(201, 162, 39, 0.15);
}

.admin-top-row {
  grid-template-columns: 2.1rem minmax(0, 1fr) auto;
}

.admin-class-info,
.admin-top-info {
  min-width: 0;
  display: grid;
  gap: 0.12rem;
}

.admin-class-info strong,
.admin-top-info strong {
  font-size: 0.86rem;
  font-weight: 600;
  color: var(--admin-ink);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.admin-class-info span,
.admin-top-info small {
  font-size: 0.74rem;
  color: var(--admin-muted);
}

.admin-class-score,
.admin-top-score {
  font-size: 1.05rem;
  font-weight: 800;
}

.admin-class-score.good,
.admin-top-score { color: var(--admin-sage); }
.admin-class-score.danger { color: var(--admin-wine); }
.admin-class-score.muted { color: var(--admin-muted); }

.admin-top-rank {
  display: grid;
  place-items: center;
  width: 2rem;
  height: 2rem;
  border-radius: 8px;
  background: rgba(201, 162, 39, 0.12);
  color: #8a6f1a;
  font-size: 0.85rem;
  font-weight: 800;
}

.admin-panel-foot {
  margin: 0;
  padding: 0 1rem 1rem;
  font-size: 0.78rem;
  color: var(--admin-muted);
}

/* Watchlist */
.admin-watchlist {
  display: flex;
  flex-direction: column;
  gap: 0.45rem;
  padding: 0.65rem 1rem 1rem;
}

.admin-watch-alert {
  display: grid;
  gap: 0.15rem;
  padding: 0.75rem 0.85rem;
  border-radius: 10px;
  border: 1px solid transparent;
}

.admin-watch-alert strong {
  font-size: 0.84rem;
  font-weight: 600;
  color: var(--admin-ink);
}

.admin-watch-alert span {
  font-size: 0.76rem;
  color: var(--admin-muted);
}

.admin-watch-alert.danger {
  background: rgba(155, 44, 44, 0.07);
  border-color: rgba(155, 44, 44, 0.15);
}

.admin-watch-alert.warn {
  background: rgba(196, 92, 38, 0.08);
  border-color: rgba(196, 92, 38, 0.18);
}

/* Assiduité */
.admin-attendance-bars {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  padding: 0.85rem 1.25rem 1.25rem;
  flex: 1;
}

.admin-attendance-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 0.4rem;
  font-size: 0.82rem;
  color: var(--admin-muted);
}

.admin-attendance-head strong {
  font-size: 0.95rem;
  font-weight: 800;
  color: var(--admin-ink);
}

.admin-attendance-track {
  height: 0.65rem;
  border-radius: 999px;
  background: rgba(26, 39, 68, 0.06);
  overflow: hidden;
}

.admin-attendance-fill {
  display: block;
  height: 100%;
  border-radius: inherit;
  min-width: 2px;
  animation: adminBarGrow 0.8s cubic-bezier(0.16, 1, 0.3, 1) both;
  transform-origin: left center;
}

@keyframes adminBarGrow {
  from { transform: scaleX(0); }
  to { transform: scaleX(1); }
}

.admin-attendance-fill.good {
  background: linear-gradient(90deg, #40916c, var(--admin-sage));
}

.admin-attendance-fill.warn {
  background: linear-gradient(90deg, #e07a3a, var(--admin-terra));
}

.admin-attendance-fill.danger {
  background: linear-gradient(90deg, #c44, var(--admin-wine));
}

.admin-empty {
  color: var(--admin-muted);
}

.admin-empty .empty-icon {
  color: rgba(26, 39, 68, 0.15);
}

/* Table dépliable */
.dashboard-page--admin .expandable-table summary {
  cursor: pointer;
  padding: 1rem 1.25rem;
  font-weight: 700;
  font-size: 0.95rem;
  color: var(--admin-ink);
  list-style: none;
}

.dashboard-page--admin .expandable-table summary::-webkit-details-marker {
  display: none;
}

.dashboard-page--admin .expandable-table[open] summary {
  border-bottom: 1px solid var(--admin-border);
}

.dashboard-page--admin .expandable-table table {
  font-size: 0.88rem;
}

.dashboard-page--admin .expandable-table th {
  font-size: 0.72rem;
  font-weight: 600;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: var(--admin-muted);
}

/* Animations d'entrée */
.admin-reveal {
  animation: adminReveal 0.6s cubic-bezier(0.16, 1, 0.3, 1) both;
  animation-delay: calc(var(--reveal-order, 0) * 0.1s);
}

@keyframes adminReveal {
  from { opacity: 0; transform: translateY(16px); }
  to { opacity: 1; transform: translateY(0); }
}

@media (prefers-reduced-motion: reduce) {
  .admin-reveal,
  .admin-metric-card,
  .admin-attendance-fill {
    animation: none;
  }
}

/* Responsive admin */
@media (max-width: 1050px) {
  .admin-hero__content {
    grid-template-columns: 1fr;
  }

  .admin-hero__aside {
    align-items: stretch;
  }

  .admin-hero__toolbar {
    justify-content: flex-start;
  }

  .admin-metrics-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: 720px) {
  .admin-hero__content {
    padding: 1.25rem;
  }

  .admin-metrics-grid,
  .admin-bottom-grid {
    grid-template-columns: 1fr;
  }

  .admin-period-pills {
    width: 100%;
    overflow-x: auto;
  }
}

/* ── Hero ────────────────────────────────────────────────── */
.dashboard-hero {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  padding: 1.15rem 1.25rem;
  border: 1px solid #d9e2f1;
  border-radius: var(--radius);
  background:
    linear-gradient(135deg, rgba(255, 255, 255, 0.94), rgba(248, 250, 252, 0.9)),
    linear-gradient(90deg, #0f766e, #2563eb);
  box-shadow: 0 12px 28px rgba(15, 23, 42, 0.07);
}
.hero-identity { display: flex; align-items: center; gap: 1rem; min-width: 0; }
.hero-avatar {
  width: 3.2rem; height: 3.2rem;
  display: grid; place-items: center;
  border-radius: 50%;
  background: linear-gradient(135deg, #0f766e, var(--primary));
  color: white;
  font-size: 1.15rem;
  font-weight: 850;
  letter-spacing: 0.02em;
  box-shadow: 0 8px 18px rgba(15, 118, 110, 0.22);
  flex-shrink: 0;
}
.hero-text { min-width: 0; }
.dashboard-hero h1 {
  margin: 0.15rem 0 0;
  font-size: 1.4rem;
  line-height: 1.2;
}

.eyebrow {
  display: inline-flex; align-items: center; gap: 0.4rem;
  margin: 0;
  color: var(--primary);
  font-size: 0.72rem;
  font-weight: 800;
  letter-spacing: 0.06em;
  text-transform: capitalize;
}
.eyebrow-icon { width: 0.9rem; height: 0.9rem; }

.hero-meta {
  display: flex; flex-wrap: wrap; align-items: center; gap: 0.4rem;
  margin: 0.45rem 0 0;
  color: var(--text-soft);
  font-size: 0.86rem;
}
.hero-tag {
  display: inline-flex; align-items: center; gap: 0.35rem;
  padding: 0.2rem 0.55rem;
  border-radius: 999px;
  background: var(--primary-soft);
  color: var(--primary);
  font-weight: 700;
  font-size: 0.78rem;
}
.hero-tag-icon { width: 0.85rem; height: 0.85rem; }
.hero-meta-sep { color: var(--text-muted); }

.hero-pill {
  display: inline-flex;
  align-items: center;
  min-height: 2.2rem;
  padding: 0.35rem 0.75rem;
  border: 1px solid #d5e0ff;
  border-radius: 999px;
  background: #f8fafc;
  color: #334155;
  font-size: 0.74rem;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.hero-actions {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 0.6rem;
  flex-wrap: wrap;
}

.icon-btn {
  display: grid; place-items: center;
  width: 2.4rem; height: 2.4rem;
  border-radius: 999px;
  border: 1px solid var(--border);
  background: white;
  color: var(--text-soft);
  cursor: pointer;
  transition: all 0.2s ease;
}
.icon-btn:hover:not(:disabled) {
  background: var(--primary-soft);
  color: var(--primary);
  border-color: #d5e0ff;
}
.icon-btn:disabled { opacity: 0.6; cursor: progress; }
.icon-btn svg { width: 1.05rem; height: 1.05rem; }
.icon-btn .spinning { animation: spin 0.85s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }

/* ── Toolbar (sticky filtres) ───────────────────────────── */
.toolbar {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 0.6rem;
  padding: 0.55rem 0.75rem;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: rgba(255, 255, 255, 0.92);
  backdrop-filter: saturate(180%) blur(8px);
  -webkit-backdrop-filter: saturate(180%) blur(8px);
  box-shadow: 0 10px 24px rgba(15, 23, 42, 0.06);
  position: sticky;
  top: 0.6rem;
  z-index: 10;
}

.period-filter {
  display: inline-flex;
  align-items: center;
  gap: 0.18rem;
  padding: 0.25rem;
  border: 1px solid var(--border);
  border-radius: 8px;
  background: #f8fafc;
  box-shadow: inset 0 1px 2px rgba(0,0,0,0.05);
}

.period-filter button {
  min-height: 1.9rem;
  padding: 0.28rem 0.75rem;
  border: 0;
  border-radius: 6px;
  background: transparent;
  color: var(--text-soft);
  box-shadow: none;
  font-size: 0.76rem;
  font-weight: 700;
  transition: all 0.2s ease;
  cursor: pointer;
}

.period-filter button:hover {
  color: var(--text);
}

.period-filter button.active {
  background: white;
  color: var(--primary);
  box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}

.period-select {
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
  min-height: 2.35rem;
  margin: 0;
  padding: 0.22rem 0.28rem 0.22rem 0.65rem;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: var(--bg-card);
  box-shadow: var(--shadow);
}

.period-select span {
  color: var(--text-soft);
  font-size: 0.72rem;
  font-weight: 800;
  letter-spacing: 0.05em;
  text-transform: uppercase;
}

.period-select select {
  width: auto;
  min-width: 10rem;
  min-height: 1.9rem;
  padding: 0.28rem 1.9rem 0.28rem 0.6rem;
  border: 0;
  background-color: var(--primary-soft);
  color: var(--primary);
  font-size: 0.78rem;
  font-weight: 800;
  box-shadow: none;
}

.period-select select:focus {
  box-shadow: 0 0 0 3px rgba(52, 87, 255, 0.14);
}

.kpi-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
  gap: 0.85rem;
}

.kpi-grid.teacher {
  grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
}

.kpi-card {
  min-height: 7.4rem;
  background: var(--bg-card);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  box-shadow: 0 8px 20px rgba(15, 23, 42, 0.05);
  padding: 0.9rem;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  gap: 0.35rem;
}

.kpi-card.warn {
  border-color: #fedf89;
}

.kpi-card.danger {
  border-color: #fecdca;
}

.kpi-label {
  color: var(--text-soft);
  font-size: 0.72rem;
  font-weight: 800;
  letter-spacing: 0.05em;
  text-transform: uppercase;
}

.kpi-value {
  color: var(--text);
  font-size: 1.7rem;
  font-weight: 850;
  line-height: 1;
}

.kpi-note {
  color: var(--text-muted);
  font-size: 0.76rem;
  font-weight: 650;
}

.analytics-grid {
  display: grid;
  grid-template-columns: minmax(0, 1.45fr) minmax(280px, 0.75fr);
  gap: 1rem;
  align-items: start;
}

.admin-kpi-strip {
  display: grid;
  grid-template-columns: repeat(5, minmax(0, 1fr));
  gap: 0.85rem;
}

.admin-chart-grid {
  display: grid;
  grid-template-columns: minmax(0, 2fr) minmax(280px, 1fr);
  gap: 1rem;
  align-items: stretch;
}

.admin-follow-grid {
  display: grid;
  grid-template-columns: minmax(0, 1.15fr) minmax(240px, 0.95fr) minmax(240px, 0.95fr);
  gap: 1rem;
  align-items: start;
}

.chart-card,
.data-card {
  min-width: 0;
}

.chart-card-wide {
  min-height: 20rem;
}

.attendance-card {
  display: flex;
  flex-direction: column;
}

.attendance-card .donut-wrap {
  flex: 1;
}

.admin-follow-grid .chart-card {
  min-height: 0;
}

.chart-card .card-header h2,
.data-card .card-header h2 {
  margin: 0;
  font-size: 1rem;
  line-height: 1.2;
}

.chart-card .card-header p,
.data-card .card-header p {
  margin: 0.2rem 0 0;
  color: var(--text-soft);
  font-size: 0.82rem;
}

.bar-chart {
  display: flex;
  flex-direction: column;
  gap: 0.8rem;
  padding: 1rem 1.15rem 1.15rem;
}

.bar-row {
  display: grid;
  grid-template-columns: minmax(7rem, 10rem) minmax(0, 1fr) 3.5rem;
  align-items: center;
  gap: 0.75rem;
}

.bar-label {
  overflow: hidden;
  color: var(--text);
  font-size: 0.84rem;
  font-weight: 700;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.bar-track,
.mini-track {
  position: relative;
  height: 0.62rem;
  overflow: hidden;
  border-radius: 999px;
  background: #edf2ff;
}

.bar-fill,
.mini-track i {
  position: absolute;
  inset: 0 auto 0 0;
  min-width: 0.45rem;
  border-radius: inherit;
  background: linear-gradient(90deg, #3457ff, #6f8cff);
}

.bar-fill.good {
  background: linear-gradient(90deg, #3457ff, #6f8cff);
}

.bar-fill.warn {
  background: linear-gradient(90deg, #f79009, #fdb022);
}

.bar-fill.muted {
  background: #cbd5e1;
}

.bar-value {
  text-align: right;
  color: var(--primary);
  font-size: 0.82rem;
  font-weight: 850;
}

.bar-value.warn {
  color: var(--warn);
}

.bar-value.muted {
  color: var(--text-muted);
}

.donut-wrap {
  min-height: 10.5rem;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0.85rem;
  padding: 0.75rem 0.85rem 0.9rem;
}

.admin-follow-grid .column-chart {
  min-height: 11.75rem;
  padding: 0.85rem 1rem 1rem;
}

.admin-follow-grid .column-track {
  height: 7.25rem;
}

.donut {
  width: 7.4rem;
  height: 7.4rem;
  border-radius: 50%;
  display: grid;
  place-items: center;
  box-shadow: inset 0 0 0 1px rgba(52, 87, 255, 0.08);
}

.donut-hole {
  width: 4.5rem;
  height: 4.5rem;
  border-radius: 50%;
  background: var(--bg-card);
  display: grid;
  place-items: center;
  align-content: center;
  box-shadow: 0 0 0 1px var(--border);
}

.donut-hole strong {
  color: var(--text);
  font-size: 1.18rem;
  font-weight: 850;
  line-height: 1;
}

.donut-hole span {
  color: var(--text-soft);
  font-size: 0.68rem;
  font-weight: 700;
}

.legend {
  display: grid;
  gap: 0.4rem;
  color: var(--text-soft);
  font-size: 0.72rem;
  font-weight: 700;
}

.legend span {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
}

.legend-dot {
  width: 0.55rem;
  height: 0.55rem;
  border-radius: 50%;
}

.legend-dot.primary {
  background: #3457ff;
}

.legend-dot.secondary {
  background: #7b97ff;
}

.legend-dot.soft {
  background: #c8d7ff;
}

.column-chart {
  min-height: 16rem;
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(4.5rem, 1fr));
  align-items: end;
  gap: 0.85rem;
  padding: 1rem 1.15rem 1.25rem;
}

.column-item {
  min-width: 0;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.35rem;
  color: var(--text-soft);
  font-size: 0.72rem;
  font-weight: 750;
  text-align: center;
}

.column-track {
  width: 2.15rem;
  height: 10rem;
  display: flex;
  align-items: end;
  border-radius: 999px;
  background: #edf2ff;
  overflow: hidden;
}

.column-fill {
  width: 100%;
  min-height: 0.45rem;
  border-radius: inherit;
  background: linear-gradient(180deg, #7b97ff, #3457ff);
}

.column-fill.warn {
  background: linear-gradient(180deg, #fdb022, #f79009);
}

.column-fill.good {
  background: linear-gradient(180deg, #86a0ff, #3457ff);
}

.column-item strong {
  color: var(--text);
  font-size: 0.86rem;
}

.column-item span {
  width: 100%;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.mini-bars {
  display: flex;
  flex-direction: column;
  gap: 0.82rem;
  padding: 1rem;
}

.mini-bar {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 4.5rem 2.5rem;
  align-items: center;
  gap: 0.65rem;
}

.mini-bar span {
  overflow: hidden;
  color: var(--text);
  font-size: 0.8rem;
  font-weight: 700;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.mini-bar strong {
  color: var(--primary);
  font-size: 0.82rem;
  text-align: right;
}

.data-card {
  margin-top: 0.25rem;
}

.good {
  color: var(--success);
}

.low {
  color: var(--warn);
}

@media (max-width: 1050px) {
  .analytics-grid,
  .admin-chart-grid,
  .admin-follow-grid,
  .admin-bottom-grid {
    grid-template-columns: 1fr;
  }

  .overview-grid,
  .admin-kpi-strip {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: 720px) {
  .dashboard-hero {
    flex-direction: column;
  }

  .hero-actions {
    width: 100%;
    align-items: stretch;
    justify-content: flex-start;
  }

  .period-filter {
    width: 100%;
    overflow-x: auto;
  }

  .period-select {
    width: 100%;
    justify-content: space-between;
  }

  .period-select select {
    flex: 1;
    min-width: 0;
  }

  .kpi-grid,
  .kpi-grid.teacher,
  .admin-kpi-strip,
  .overview-grid {
    grid-template-columns: 1fr;
  }

  .bar-row {
    grid-template-columns: 1fr;
    gap: 0.35rem;
  }

  .bar-value {
    text-align: left;
  }

  .mini-bar {
    grid-template-columns: 1fr;
    gap: 0.35rem;
  }

  .mini-bar strong {
    text-align: left;
  }
}

/* Utilitaires et Skeletons */
.table-responsive {
  width: 100%;
  overflow-x: auto;
  -webkit-overflow-scrolling: touch;
}

.text-right {
  text-align: right;
}

.font-bold {
  font-weight: 700;
}

@keyframes pulse {
  0%, 100% { opacity: 1; }
  50% { opacity: 0.5; }
}

.skeleton {
  animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
  background: #e2e8f0;
  border-color: transparent !important;
  box-shadow: none !important;
}

.skeleton-wrapper {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.skeleton-tall {
  min-height: 20rem;
}
/* Tooltips */
[data-tooltip] { position: relative; cursor: pointer; }
[data-tooltip]:hover::after { content: attr(data-tooltip); position: absolute; bottom: 100%; left: 50%; transform: translateX(-50%); margin-bottom: 0.4rem; background: #1e293b; color: white; padding: 0.35rem 0.6rem; border-radius: 6px; font-size: 0.72rem; font-weight: 600; white-space: nowrap; z-index: 20; pointer-events: none; opacity: 0; animation: tooltipFade 0.2s forwards; }
@keyframes tooltipFade { to { opacity: 1; transform: translateX(-50%) translateY(0); } }

/* Table Sorting and Badges */
.sortable { cursor: pointer; user-select: none; transition: background 0.2s; }
.sortable:hover { background: rgba(0,0,0,0.02); }
.sort-icon { width: 0.85rem; height: 0.85rem; vertical-align: middle; margin-left: 0.25rem; opacity: 0.4; }
.sortable:hover .sort-icon { opacity: 0.8; }

.badge { display: inline-flex; align-items: center; gap: 0.35rem; padding: 0.25rem 0.65rem; border-radius: 999px; font-size: 0.75rem; font-weight: 750; background: var(--bg-soft); color: var(--text); }
.badge-success { background: #ecfdf3; color: #027a48; }
.badge-warn { background: #fffaeb; color: #b54708; }
.badge-danger { background: #fef3f2; color: #b42318; }
.badge-dot { width: 0.4rem; height: 0.4rem; border-radius: 50%; }
.badge-success .badge-dot { background: #12b76a; }
.badge-warn .badge-dot { background: #f79009; }
.badge-danger .badge-dot { background: #f04438; }

/* Custom Dropdowns */
.period-select.custom-dropdown { padding-right: 0.5rem; position: relative; }
.select-wrapper { position: relative; display: flex; align-items: center; }
.select-wrapper select { appearance: none; padding-right: 1.8rem; background: transparent; position: relative; z-index: 1; cursor: pointer; }
.select-icon { position: absolute; right: 0.4rem; width: 1rem; height: 1rem; color: var(--primary); pointer-events: none; }

/* KPI Cards Layout */
.kpi-card-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 0.5rem; }
.kpi-icon { width: 2.2rem; height: 2.2rem; padding: 0.4rem; background: var(--bg-soft); border-radius: 8px; color: var(--primary); display: grid; place-items: center; }
.kpi-icon svg { width: 100%; height: 100%; stroke-width: 2; }
.kpi-card.warn .kpi-icon { background: #fff8e6; color: #f79009; }
.kpi-card.danger .kpi-icon { background: #fef3f2; color: #f04438; }

/* Empty States */
.empty-state-pro { display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 3rem 1rem; text-align: center; color: var(--text-soft); }
.empty-icon { width: 3rem; height: 3rem; margin-bottom: 0.8rem; color: #cbd5e1; stroke-width: 1.5; }
.empty-state-pro p { margin: 0; font-size: 0.88rem; font-weight: 500; }

/* ── Animations entry ─────────────────────────────────── */
@keyframes fadeInUp {
  from { opacity: 0; transform: translateY(8px); }
  to { opacity: 1; transform: translateY(0); }
}
.fade-in {
  animation: fadeInUp 0.45s cubic-bezier(0.16, 1, 0.3, 1) both;
}

/* ── Responsive Hero ─────────────────────────────────── */
@media (max-width: 720px) {
  .dashboard-hero {
    flex-direction: column;
    align-items: stretch;
  }
  .hero-actions { justify-content: space-between; }
  .toolbar { position: static; }
}
</style>
