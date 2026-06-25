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
import { useDevCalendarReload } from '../stores/devCalendar'
import StatCard from '../components/dashboard/StatCard.vue'
import {
  Users, BookOpen,
  AlertTriangle, FileEdit, CheckSquare,
  Inbox, BarChart2, ChevronDown, ChevronUp, ArrowUpDown,
  RefreshCw, TrendingDown,
  GraduationCap, ClipboardList, Medal, ShieldAlert, Info,
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
      <div class="admin-hero__glow admin-hero__glow--2" aria-hidden="true" />
      <div class="admin-hero__content">
        <div class="admin-hero__main">
          <div class="admin-hero__badge">
            <span class="admin-hero__dot" aria-hidden="true" />
            <span>{{ schoolYearLabel }}</span>
            <span class="admin-hero__badge-sep" aria-hidden="true">·</span>
            <span>COURANTE</span>
          </div>
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
              <span>MOIS</span>
              <div class="select-wrapper">
                <select v-model="selectedMonth" :disabled="availableMonths.length === 0">
                  <option v-for="month in availableMonths" :key="month.value" :value="month.value">{{ month.label }}</option>
                </select>
                <ChevronDown class="select-icon" />
              </div>
            </label>
            <label v-if="selectedPeriod === 'term'" class="admin-period-select custom-dropdown">
              <span>TRIMESTRE</span>
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
        </div>
      </div>
      <div class="admin-hero__bottom-line" aria-hidden="true" />
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
          <div class="admin-section-num-wrap">
            <span class="admin-section-num" aria-hidden="true">01</span>
          </div>
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
            :note="'Sur les classes notées'"
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
            <div class="admin-panel__head-badge">02</div>
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
              :height="280"
              :y-max="20"
              tooltip-suffix="%"
              :colors="['#3b82f6']"
            />
            <div v-else class="empty-state-pro admin-empty">
              <BarChart2 class="empty-icon" />
              <p>Aucune moyenne mensuelle disponible.</p>
            </div>
          </div>
        </div>

        <div class="admin-panel admin-panel--attendance">
          <div class="admin-panel__head">
            <div class="admin-panel__head-badge">03</div>
            <div>
              <h2>Assiduité globale</h2>
              <p>{{ attendancePeriodNote }}</p>
            </div>
          </div>
          <div class="admin-attendance-bars">
            <div v-for="(row, rowIdx) in attendanceBreakdownRows" :key="row.label" class="admin-attendance-row">
              <div class="admin-attendance-head">
                <div class="admin-attendance-dot" :class="row.tone" aria-hidden="true" />
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
                <div class="admin-class-name-row">
                  <strong>{{ c.full_name }}</strong>
                  <span class="admin-class-score" :class="classroomAverageTone(c.class_average)">
                    {{ formatScoreDisplay(c.class_average) }}
                  </span>
                </div>
                <span class="admin-class-meta">{{ c.student_count }} élèves · {{ c.absences }} abs.</span>
                <div class="admin-class-bar-track" aria-hidden="true">
                  <div
                    class="admin-class-bar-fill"
                    :class="classroomAverageTone(c.class_average)"
                    :style="{ width: c.class_average !== null ? `${Math.min(100, (c.class_average / 20) * 100)}%` : '0%' }"
                  />
                </div>
              </div>
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
            <h2><Medal class="admin-panel-icon good" /> Top performers</h2>
          </div>
          <ol v-if="(adminInsights?.top_students ?? []).length" class="admin-top-list">
            <li v-for="(student, idx) in adminInsights?.top_students ?? []" :key="student.id" class="admin-top-row">
              <span
                class="admin-top-rank"
                :class="{ 'rank-gold': idx === 0, 'rank-silver': idx === 1, 'rank-bronze': idx === 2 }"
              >{{ idx + 1 }}</span>
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
            <h2><ShieldAlert class="admin-panel-icon danger" /> À surveiller</h2>
          </div>
          <div v-if="(adminInsights?.watchlist ?? []).length" class="admin-watchlist">
            <article
              v-for="(alert, idx) in adminInsights?.watchlist ?? []"
              :key="`${alert.type}-${alert.title}-${idx}`"
              class="admin-watch-alert"
              :class="alert.severity"
            >
              <div class="admin-watch-icon" :class="alert.severity" aria-hidden="true">
                <AlertTriangle v-if="alert.severity === 'danger'" />
                <Info v-else />
              </div>
              <div class="admin-watch-body">
                <strong>{{ alert.title }}</strong>
                <span>{{ alert.detail }}</span>
              </div>
            </article>
          </div>
          <div v-else class="empty-state-pro admin-empty compact">
            <div class="empty-all-good">
              <span class="empty-all-good-icon" aria-hidden="true">✓</span>
              <p>Tout est dans la norme pour cette période.</p>
            </div>
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
/* ══════════════════════════════════════════════════════════
   Tokens & page shell
   ══════════════════════════════════════════════════════════ */
.dashboard-dev-calendar { margin-top: 1.5rem; }

.dashboard-page {
  display: flex;
  flex-direction: column;
  gap: 1.5rem;
  color: var(--text);
}

/* ══════════════════════════════════════════════════════════
   Dashboard admin — design system éditorial
   ══════════════════════════════════════════════════════════ */
.dashboard-page--admin {
  --admin-ink:    #e2eaf8;
  --admin-cream:  #0d1f4a;
  --admin-gold:   #3b82f6;
  --admin-gold-2: #60a5fa;
  --admin-sage:   #4ade80;
  --admin-terra:  #fb923c;
  --admin-wine:   #f87171;
  --admin-muted:  #8aadcf;
  --admin-border: rgba(255, 255, 255, 0.08);
  --admin-shadow: 0 4px 6px rgba(0, 0, 0, 0.3), 0 12px 28px rgba(0, 0, 0, 0.4);
  gap: 1.75rem;
}

/* ── Hero ── */
.admin-hero {
  position: relative;
  border-radius: 20px;
  overflow: hidden;
  border: 1px solid rgba(255,255,255,0.06);
  background: linear-gradient(130deg, #0f1c35 0%, #1a2f52 45%, #152845 100%);
  box-shadow:
    0 1px 0 rgba(255,255,255,0.06) inset,
    0 32px 56px rgba(15, 28, 53, 0.22);
}

.admin-hero__grain {
  position: absolute;
  inset: 0;
  opacity: 0.25;
  background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='0.5'/%3E%3C/svg%3E");
  pointer-events: none;
  mix-blend-mode: overlay;
}

.admin-hero__glow {
  position: absolute;
  top: -40%;
  right: -8%;
  width: 55%;
  height: 130%;
  background: radial-gradient(ellipse, rgba(59, 130, 246,0.18) 0%, transparent 62%);
  pointer-events: none;
}

.admin-hero__glow--2 {
  top: auto;
  right: auto;
  bottom: -30%;
  left: -5%;
  width: 40%;
  height: 90%;
  background: radial-gradient(ellipse, rgba(37, 99, 235, 0.12) 0%, transparent 60%);
}

.admin-hero__content {
  position: relative;
  display: grid;
  grid-template-columns: minmax(0, 1.3fr) minmax(260px, 0.85fr);
  gap: 1.5rem 2rem;
  padding: 1.8rem 2rem 1.7rem;
  align-items: center;
}

.admin-hero__badge {
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
  margin: 0 0 0.85rem;
  font-size: 0.68rem;
  font-weight: 700;
  letter-spacing: 0.15em;
  text-transform: uppercase;
  color: rgba(96, 165, 250, 0.75);
}

.admin-hero__badge-sep { opacity: 0.45; }

.admin-hero__dot {
  width: 0.5rem;
  height: 0.5rem;
  border-radius: 50%;
  background: var(--admin-gold-2);
  box-shadow: 0 0 0 3px rgba(96, 165, 250, 0.2), 0 0 12px rgba(96, 165, 250, 0.5);
  animation: pulse-dot 2.4s ease infinite;
}

@keyframes pulse-dot {
  0%, 100% { box-shadow: 0 0 0 3px rgba(96, 165, 250, 0.2), 0 0 12px rgba(96, 165, 250, 0.5); }
  50%       { box-shadow: 0 0 0 5px rgba(96, 165, 250, 0.08), 0 0 20px rgba(96, 165, 250, 0.35); }
}

.admin-hero__title { margin: 0; display: flex; flex-direction: column; gap: 0.25rem; }

.admin-hero__title-small {
  font-size: 0.76rem;
  font-weight: 500;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: rgba(96, 165, 250, 0.72);
}

.admin-hero__title-main {
  font-size: clamp(1.6rem, 3.2vw, 2.25rem);
  font-weight: 900;
  line-height: 1.06;
  letter-spacing: -0.025em;
  color: #f1f6ff;
}

.admin-hero__title-main em {
  font-style: italic;
  background: linear-gradient(90deg, #60a5fa, #93c5fd);
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
  background-clip: text;
}

.admin-hero__meta {
  margin: 0.7rem 0 0;
  font-size: 0.84rem;
  color: rgba(96, 165, 250, 0.66);
  text-transform: capitalize;
  letter-spacing: 0.01em;
}

.admin-hero__aside {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 0.5rem;
}

.admin-hero__toolbar {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 0.45rem;
  justify-content: flex-end;
}

.admin-period-pills {
  display: inline-flex;
  gap: 0.18rem;
  padding: 0.2rem;
  border-radius: 12px;
  background: rgba(0,0,0,0.28);
  border: 1px solid rgba(255,255,255,0.07);
  backdrop-filter: blur(10px);
}

.admin-period-pills button {
  min-height: 2.05rem;
  padding: 0.28rem 0.85rem;
  border: 0;
  border-radius: 9px;
  background: transparent;
  color: rgba(226, 234, 248, 0.55);
  font-size: 0.74rem;
  font-weight: 600;
  letter-spacing: 0.02em;
  box-shadow: none;
  cursor: pointer;
  transition: color 0.18s ease, background 0.18s ease;
}

.admin-period-pills button:hover {
  color: #f1f6ff;
  background: rgba(255,255,255,0.05);
  transform: none;
}

.admin-period-pills button.active {
  background: linear-gradient(135deg, rgba(59, 130, 246,0.92), rgba(224,192,60,0.88));
  color: #ffffff;
  font-weight: 800;
  box-shadow: 0 2px 8px rgba(59, 130, 246,0.3);
}

.admin-period-select {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  min-height: 2.15rem;
  padding: 0.18rem 0.45rem 0.18rem 0.65rem;
  border-radius: 10px;
  border: 1px solid rgba(255,255,255,0.1);
  background: rgba(0,0,0,0.2);
  backdrop-filter: blur(8px);
  margin: 0;
}

.admin-period-select > span {
  font-size: 0.6rem;
  font-weight: 700;
  letter-spacing: 0.12em;
  text-transform: uppercase;
  color: rgba(96, 165, 250, 0.72);
  white-space: nowrap;
}

.admin-period-select select {
  min-height: 1.75rem;
  min-width: 7.5rem;
  padding: 0.2rem 1.5rem 0.2rem 0.35rem;
  border: 0;
  background: transparent;
  color: #f1f6ff;
  font-size: 0.78rem;
  font-weight: 600;
  box-shadow: none;
}
.admin-period-select select option {
  background: #1e2d4a;
  color: #e2eaf8;
}

.admin-period-select .select-icon { color: rgba(96, 165, 250,0.8); }

.admin-refresh-btn {
  display: grid;
  place-items: center;
  width: 2.2rem;
  height: 2.2rem;
  border-radius: 10px;
  border: 1px solid rgba(255,255,255,0.1);
  background: rgba(0,0,0,0.2);
  color: rgba(226, 234, 248,0.65);
  cursor: pointer;
  box-shadow: none;
  transition: all 0.2s ease;
}

.admin-refresh-btn:hover:not(:disabled) {
  background: rgba(59, 130, 246,0.18);
  color: var(--admin-gold-2);
  border-color: rgba(59, 130, 246,0.3);
  transform: none;
}

.admin-refresh-btn svg { width: 0.95rem; height: 0.95rem; }

.admin-hero__bottom-line {
  position: absolute;
  bottom: 0;
  left: 0;
  right: 0;
  height: 1.5px;
  background: linear-gradient(90deg, transparent 0%, rgba(59, 130, 246,0.5) 35%, rgba(59, 130, 246,0.7) 60%, transparent 100%);
}

/* ── Section heads ── */
.admin-section-head {
  display: flex;
  align-items: center;
  gap: 0.85rem;
  margin-bottom: 1.15rem;
}

.admin-section-num-wrap {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 2.1rem;
  height: 2.1rem;
  border-radius: 10px;
  background: rgba(59, 130, 246, 0.1);
  border: 1px solid rgba(59, 130, 246, 0.2);
  flex-shrink: 0;
}

.admin-section-num {
  font-size: 0.82rem;
  font-weight: 800;
  letter-spacing: 0.04em;
  color: #60a5fa;
  user-select: none;
}

.admin-section-copy h2 {
  margin: 0;
  font-size: 1.1rem;
  font-weight: 800;
  color: var(--admin-ink);
  letter-spacing: -0.015em;
}

.admin-section-copy p {
  margin: 0.15rem 0 0;
  font-size: 0.8rem;
  color: var(--admin-muted);
}

/* ── Metrics grid ── */
.admin-metrics-grid {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 1.1rem;
}

.admin-metric-card {
  animation: adminMetricIn 0.5s cubic-bezier(0.16, 1, 0.3, 1) both;
  animation-delay: calc(var(--reveal-order, 0) * 0.08s);
}

@keyframes adminMetricIn {
  from { opacity: 0; transform: translateY(18px) scale(0.97); }
  to   { opacity: 1; transform: translateY(0) scale(1); }
}

/* ── Panels ── */
.admin-panel {
  border-radius: 16px;
  border: 1px solid var(--admin-border);
  background: var(--bg-card);
  box-shadow: var(--admin-shadow);
  overflow: hidden;
  transition: box-shadow 0.25s ease;
}

.admin-panel:hover {
  box-shadow:
    0 4px 6px rgba(15, 28, 53, 0.06),
    0 16px 36px rgba(15, 28, 53, 0.1);
}

.admin-panel__head {
  display: flex;
  align-items: center;
  gap: 0.8rem;
  padding: 1.05rem 1.3rem 0.9rem;
  border-bottom: 1px solid var(--admin-border);
  background: linear-gradient(180deg, var(--bg-card), var(--bg-soft));
}

.admin-panel__head-badge {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 1.85rem;
  height: 1.85rem;
  border-radius: 8px;
  background: rgba(59, 130, 246, 0.1);
  border: 1px solid rgba(59, 130, 246, 0.18);
  font-size: 0.72rem;
  font-weight: 800;
  letter-spacing: 0.03em;
  color: #60a5fa;
  flex-shrink: 0;
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
  font-size: 0.95rem;
  font-weight: 700;
  color: var(--admin-ink);
}

.admin-panel__head p {
  margin: 0.15rem 0 0;
  font-size: 0.77rem;
  color: var(--admin-muted);
}

.admin-panel__body {
  padding: 0.75rem 0.85rem 1rem;
}

.admin-panel-icon {
  width: 1rem;
  height: 1rem;
  color: var(--admin-gold);
  flex-shrink: 0;
}

.admin-panel-icon.good   { color: var(--admin-sage); }
.admin-panel-icon.danger { color: var(--admin-wine); }

.admin-panel--chart      { grid-column: span 1; }
.admin-panel--attendance { display: flex; flex-direction: column; }

.admin-link-btn {
  border: 0;
  background: transparent;
  color: var(--admin-gold);
  font-size: 0.75rem;
  font-weight: 700;
  cursor: pointer;
  padding: 0.2rem 0.5rem;
  border-radius: 6px;
  letter-spacing: 0.02em;
  transition: background 0.15s ease, color 0.15s ease;
}

.admin-link-btn:hover {
  background: rgba(59, 130, 246, 0.08);
  color: #60a5fa;
  text-decoration: none;
}

/* ── Charts grid ── */
.admin-chart-grid {
  display: grid;
  grid-template-columns: minmax(0, 1.9fr) minmax(270px, 1fr);
  gap: 1.1rem;
  align-items: stretch;
}

/* ── Bottom grid ── */
.admin-bottom-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 1.1rem;
  align-items: stretch;
}

/* Hauteur uniforme : les 3 panneaux s'alignent sur le plus haut,
   et leur contenu (liste ou état vide) occupe l'espace restant. */
.admin-bottom-grid > .admin-panel {
  display: flex;
  flex-direction: column;
}

.admin-bottom-grid .admin-class-list,
.admin-bottom-grid .admin-top-list,
.admin-bottom-grid .admin-watchlist {
  flex: 1;
}

.admin-bottom-grid .admin-empty {
  flex: 1;
  display: grid;
  place-items: center;
}

/* ── Class list ── */
.admin-class-list,
.admin-top-list {
  list-style: none;
  margin: 0;
  padding: 0.6rem 0.85rem 1rem;
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}

.admin-class-row {
  padding: 0.85rem 0.9rem;
  border-radius: 10px;
  background: rgba(255, 255, 255, 0.02);
  border: 1px solid transparent;
  transition: border-color 0.2s ease, background 0.2s ease;
  cursor: default;
}

.admin-class-row:hover {
  background: rgba(59, 130, 246, 0.06);
  border-color: rgba(59, 130, 246, 0.15);
}

.admin-class-info { min-width: 0; }

.admin-class-name-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
  margin-bottom: 0.18rem;
}

.admin-class-name-row strong {
  font-size: 0.87rem;
  font-weight: 600;
  color: var(--admin-ink);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  min-width: 0;
}

.admin-class-meta {
  display: block;
  font-size: 0.73rem;
  color: var(--admin-muted);
  margin-bottom: 0.5rem;
}

/* Mini score bar */
.admin-class-bar-track {
  height: 4px;
  border-radius: 999px;
  background: rgba(255, 255, 255, 0.07);
  overflow: hidden;
}

.admin-class-bar-fill {
  height: 100%;
  border-radius: inherit;
  min-width: 2px;
  transition: width 0.7s cubic-bezier(0.16, 1, 0.3, 1);
  animation: barGrowIn 0.7s cubic-bezier(0.16, 1, 0.3, 1) both 0.3s;
  transform-origin: left;
}

@keyframes barGrowIn {
  from { transform: scaleX(0); }
  to   { transform: scaleX(1); }
}

.admin-class-bar-fill.good    { background: linear-gradient(90deg, #1e7a4f, #34c274); }
.admin-class-bar-fill.danger  { background: linear-gradient(90deg, #8b1a1a, #e05252); }
.admin-class-bar-fill.muted   { background: var(--border-strong); }

.admin-class-score {
  font-size: 0.9rem;
  font-weight: 800;
  flex-shrink: 0;
}

.admin-class-score.good   { color: var(--admin-sage); }
.admin-class-score.danger { color: var(--admin-wine); }
.admin-class-score.muted  { color: var(--admin-muted); }

/* ── Top performers ── */
.admin-top-row {
  display: grid;
  grid-template-columns: 2.1rem minmax(0, 1fr) auto;
  align-items: center;
  gap: 0.7rem;
  padding: 0.8rem 0.9rem;
  border-radius: 10px;
  background: rgba(255, 255, 255, 0.02);
  border: 1px solid transparent;
  transition: border-color 0.2s ease, background 0.2s ease;
}

.admin-top-row:hover {
  background: rgba(59, 130, 246, 0.06);
  border-color: rgba(59, 130, 246, 0.15);
}

.admin-top-info {
  min-width: 0;
  display: grid;
  gap: 0.1rem;
}

.admin-top-info strong {
  font-size: 0.86rem;
  font-weight: 600;
  color: var(--admin-ink);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.admin-top-info small {
  font-size: 0.73rem;
  color: var(--admin-muted);
}

.admin-top-score {
  font-size: 0.95rem;
  font-weight: 800;
  color: var(--admin-sage);
}

.admin-top-rank {
  display: grid;
  place-items: center;
  width: 2rem;
  height: 2rem;
  border-radius: 8px;
  font-size: 0.82rem;
  font-weight: 800;
  background: rgba(59, 130, 246, 0.1);
  color: #60a5fa;
  border: 1px solid rgba(59, 130, 246, 0.18);
}

.admin-top-rank.rank-gold {
  background: linear-gradient(135deg, rgba(255, 215, 0, 0.2), rgba(201, 162, 39, 0.15));
  color: #9a7310;
  border-color: rgba(255, 215, 0, 0.35);
  box-shadow: 0 0 0 2px rgba(255, 215, 0, 0.12);
}

.admin-top-rank.rank-silver {
  background: linear-gradient(135deg, rgba(180, 188, 204, 0.2), rgba(148, 163, 184, 0.15));
  color: #5a6b7e;
  border-color: rgba(148, 163, 184, 0.35);
}

.admin-top-rank.rank-bronze {
  background: linear-gradient(135deg, rgba(196, 127, 94, 0.18), rgba(185, 110, 75, 0.12));
  color: #8b5a40;
  border-color: rgba(196, 127, 94, 0.3);
}

.admin-panel-foot {
  margin: 0;
  padding: 0 1.1rem 1rem;
  font-size: 0.76rem;
  color: var(--admin-muted);
}

/* ── Watchlist ── */
.admin-watchlist {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  padding: 0.65rem 0.85rem 1rem;
}

.admin-watch-alert {
  display: flex;
  align-items: flex-start;
  gap: 0.65rem;
  padding: 0.8rem 0.9rem;
  border-radius: 10px;
  border: 1px solid transparent;
}

.admin-watch-icon {
  display: grid;
  place-items: center;
  width: 1.75rem;
  height: 1.75rem;
  border-radius: 7px;
  flex-shrink: 0;
}

.admin-watch-icon svg {
  width: 0.9rem;
  height: 0.9rem;
}

.admin-watch-icon.danger {
  background: rgba(248, 113, 113, 0.12);
  color: var(--admin-wine);
  border: 1px solid rgba(248, 113, 113, 0.2);
}

.admin-watch-icon.warn {
  background: rgba(251, 191, 36, 0.12);
  color: var(--admin-terra);
  border: 1px solid rgba(251, 191, 36, 0.2);
}

.admin-watch-body {
  min-width: 0;
  display: grid;
  gap: 0.1rem;
}

.admin-watch-body strong {
  font-size: 0.83rem;
  font-weight: 600;
  color: var(--admin-ink);
  line-height: 1.2;
}

.admin-watch-body span {
  font-size: 0.75rem;
  color: var(--admin-muted);
}

.admin-watch-alert.danger {
  background: rgba(248, 113, 113, 0.06);
  border-color: rgba(248, 113, 113, 0.18);
}

.admin-watch-alert.warn {
  background: rgba(251, 191, 36, 0.06);
  border-color: rgba(251, 191, 36, 0.18);
}

/* ── Attendance bars ── */
.admin-attendance-bars {
  display: flex;
  flex-direction: column;
  gap: 1.15rem;
  padding: 1rem 1.3rem 1.4rem;
  flex: 1;
}

.admin-attendance-row { display: flex; flex-direction: column; gap: 0.45rem; }

.admin-attendance-head {
  display: flex;
  align-items: center;
  gap: 0.55rem;
  font-size: 0.82rem;
  color: var(--admin-muted);
}

.admin-attendance-head strong {
  margin-left: auto;
  font-size: 0.9rem;
  font-weight: 800;
  color: var(--admin-ink);
}

.admin-attendance-dot {
  width: 0.5rem;
  height: 0.5rem;
  border-radius: 50%;
  flex-shrink: 0;
}

.admin-attendance-dot.good   { background: var(--admin-sage); }
.admin-attendance-dot.warn   { background: var(--admin-terra); }
.admin-attendance-dot.danger { background: var(--admin-wine); }

.admin-attendance-track {
  height: 8px;
  border-radius: 999px;
  background: rgba(255, 255, 255, 0.07);
  overflow: hidden;
}

.admin-attendance-fill {
  display: block;
  height: 100%;
  border-radius: inherit;
  min-width: 3px;
  animation: adminBarGrow 0.9s cubic-bezier(0.16, 1, 0.3, 1) both;
  transform-origin: left center;
}

@keyframes adminBarGrow {
  from { transform: scaleX(0); }
  to   { transform: scaleX(1); }
}

.admin-attendance-fill.good   { background: linear-gradient(90deg, #1e9a5e, var(--admin-sage)); }
.admin-attendance-fill.warn   { background: linear-gradient(90deg, #e08030, var(--admin-terra)); }
.admin-attendance-fill.danger { background: linear-gradient(90deg, #c44, var(--admin-wine)); }

/* ── Empty states ── */
.admin-empty { color: var(--admin-muted); }
.admin-empty .empty-icon { color: rgba(15, 28, 53, 0.12); }

.empty-all-good {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.5rem;
  padding: 1.5rem 1rem;
  text-align: center;
}

.empty-all-good-icon {
  width: 2.5rem;
  height: 2.5rem;
  border-radius: 50%;
  background: var(--success-soft);
  border: 1px solid rgba(74, 222, 128, 0.25);
  color: var(--admin-sage);
  display: grid;
  place-items: center;
  font-size: 1rem;
  font-weight: 800;
}

.empty-all-good p {
  margin: 0;
  font-size: 0.83rem;
  color: var(--admin-muted);
  font-weight: 500;
}

/* ── Table dépliable ── */
.dashboard-page--admin .expandable-table summary {
  cursor: pointer;
  padding: 1rem 1.3rem;
  font-weight: 700;
  font-size: 0.92rem;
  color: var(--admin-ink);
  list-style: none;
  transition: background 0.15s ease;
}

.dashboard-page--admin .expandable-table summary:hover { background: rgba(255, 255, 255, 0.03); }
.dashboard-page--admin .expandable-table summary::-webkit-details-marker { display: none; }

.dashboard-page--admin .expandable-table[open] summary {
  border-bottom: 1px solid var(--admin-border);
}

.dashboard-page--admin .expandable-table table { font-size: 0.87rem; }

.dashboard-page--admin .expandable-table th {
  font-size: 0.7rem;
  font-weight: 700;
  letter-spacing: 0.07em;
  text-transform: uppercase;
  color: var(--admin-muted);
}

/* ── Animations ── */
.admin-reveal {
  animation: adminReveal 0.55s cubic-bezier(0.16, 1, 0.3, 1) both;
  animation-delay: calc(var(--reveal-order, 0) * 0.1s);
}

@keyframes adminReveal {
  from { opacity: 0; transform: translateY(14px); }
  to   { opacity: 1; transform: translateY(0); }
}

@media (prefers-reduced-motion: reduce) {
  .admin-reveal,
  .admin-metric-card,
  .admin-attendance-fill,
  .admin-class-bar-fill,
  .admin-hero__dot { animation: none; }
}

/* ── Responsive admin ── */
@media (max-width: 1100px) {
  .admin-hero__content { grid-template-columns: 1fr; align-items: stretch; }
  .admin-hero__aside   { align-items: stretch; }
  .admin-hero__toolbar { justify-content: flex-start; }
  .admin-metrics-grid  { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .admin-chart-grid    { grid-template-columns: 1fr; }
  .admin-bottom-grid   { grid-template-columns: 1fr; }
}

@media (max-width: 720px) {
  .admin-hero__content { padding: 1.35rem 1.25rem; }
  .admin-metrics-grid  { grid-template-columns: 1fr; }
  .admin-period-pills  { width: 100%; overflow-x: auto; }
}

/* ══════════════════════════════════════════════════════════
   Hero teacher / autres rôles
   ══════════════════════════════════════════════════════════ */
.dashboard-hero {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  padding: 1.25rem 1.5rem;
  border: 1px solid var(--border-strong);
  border-radius: 18px;
  background:
    radial-gradient(ellipse at top right, rgba(59, 130, 246, 0.12), transparent 60%),
    linear-gradient(135deg, #0d1f4a 0%, #0a1836 100%);
  box-shadow: 0 8px 32px rgba(0, 0, 0, 0.4);
}

.hero-identity { display: flex; align-items: center; gap: 1rem; min-width: 0; }

.hero-avatar {
  width: 3.4rem; height: 3.4rem;
  display: grid; place-items: center;
  border-radius: 50%;
  background: linear-gradient(135deg, #0f766e, var(--primary));
  color: white;
  font-size: 1.2rem;
  font-weight: 900;
  letter-spacing: 0.02em;
  box-shadow: 0 8px 20px rgba(15, 118, 110, 0.25);
  flex-shrink: 0;
}

.hero-text { min-width: 0; }

.dashboard-hero h1 {
  margin: 0.12rem 0 0;
  font-size: 1.45rem;
  line-height: 1.2;
  font-weight: 800;
}

.eyebrow {
  display: inline-flex; align-items: center; gap: 0.4rem;
  margin: 0;
  color: var(--primary);
  font-size: 0.7rem;
  font-weight: 800;
  letter-spacing: 0.07em;
  text-transform: capitalize;
}

.hero-meta {
  display: flex; flex-wrap: wrap; align-items: center; gap: 0.4rem;
  margin: 0.5rem 0 0;
  color: var(--text-soft);
  font-size: 0.85rem;
}

.hero-tag {
  display: inline-flex; align-items: center; gap: 0.35rem;
  padding: 0.22rem 0.6rem;
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
  padding: 0.35rem 0.8rem;
  border: 1px solid var(--border-strong);
  border-radius: 999px;
  background: var(--primary-soft);
  color: var(--accent);
  font-size: 0.74rem;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 0.05em;
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
  border-radius: 50%;
  border: 1px solid var(--border);
  background: var(--bg-soft);
  color: var(--text-soft);
  cursor: pointer;
  transition: all 0.2s ease;
}

.icon-btn:hover:not(:disabled) {
  background: var(--primary-soft);
  color: var(--accent);
  border-color: var(--border-strong);
}

.icon-btn:disabled { opacity: 0.5; cursor: progress; }
.icon-btn svg { width: 1rem; height: 1rem; }
.spinning { animation: spin 0.85s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }

/* ── Toolbar (teacher) ── */
.toolbar {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 0.6rem;
  padding: 0.55rem 0.8rem;
  border: 1px solid var(--border);
  border-radius: 12px;
  background: rgba(13, 31, 74, 0.9);
  backdrop-filter: saturate(180%) blur(10px);
  box-shadow: 0 8px 24px rgba(0, 0, 0, 0.4);
  position: sticky;
  top: 0.6rem;
  z-index: 10;
}

.period-filter {
  display: inline-flex;
  align-items: center;
  gap: 0.15rem;
  padding: 0.22rem;
  border: 1px solid var(--border);
  border-radius: 9px;
  background: var(--bg-soft);
}

.period-filter button {
  min-height: 1.9rem;
  padding: 0.26rem 0.78rem;
  border: 0;
  border-radius: 7px;
  background: transparent;
  color: var(--text-soft);
  box-shadow: none;
  font-size: 0.76rem;
  font-weight: 700;
  transition: all 0.18s ease;
  cursor: pointer;
}

.period-filter button:hover { color: var(--text); }

.period-filter button.active {
  background: var(--bg-card);
  color: var(--accent);
  box-shadow: 0 1px 4px rgba(0,0,0,0.3);
}

.period-select {
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
  min-height: 2.3rem;
  margin: 0;
  padding: 0.2rem 0.3rem 0.2rem 0.65rem;
  border: 1px solid var(--border);
  border-radius: 10px;
  background: var(--bg-card);
  box-shadow: 0 1px 3px rgba(0,0,0,0.05);
}

.period-select span {
  color: var(--text-soft);
  font-size: 0.7rem;
  font-weight: 800;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  white-space: nowrap;
}

.period-select select {
  width: auto;
  min-width: 10rem;
  min-height: 1.9rem;
  padding: 0.26rem 1.8rem 0.26rem 0.5rem;
  border: 0;
  background-color: var(--primary-soft);
  color: var(--primary);
  font-size: 0.78rem;
  font-weight: 800;
  box-shadow: none;
  border-radius: 6px;
}
.period-select select option {
  background: var(--bg-card);
  color: var(--text);
}

/* ── KPI cards (teacher) ── */
.kpi-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
  gap: 0.9rem;
}

.kpi-grid.teacher { grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); }

.kpi-card {
  min-height: 7.5rem;
  background: var(--bg-card);
  border: 1px solid var(--border);
  border-radius: 14px;
  box-shadow: 0 4px 12px rgba(15, 23, 42, 0.05);
  padding: 1rem;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  gap: 0.35rem;
  transition: box-shadow 0.2s ease, transform 0.2s ease;
}

.kpi-card:hover { box-shadow: 0 8px 24px rgba(15, 23, 42, 0.08); }
.kpi-card.warn  { border-color: rgba(251, 191, 36, 0.3); }
.kpi-card.danger { border-color: rgba(248, 113, 113, 0.3); }

.kpi-label {
  color: var(--text-soft);
  font-size: 0.7rem;
  font-weight: 800;
  letter-spacing: 0.06em;
  text-transform: uppercase;
}

.kpi-value {
  color: var(--text);
  font-size: 1.75rem;
  font-weight: 900;
  line-height: 1;
}

.kpi-note {
  color: var(--text-muted);
  font-size: 0.75rem;
  font-weight: 600;
}

.analytics-grid {
  display: grid;
  grid-template-columns: minmax(0, 1.45fr) minmax(280px, 0.75fr);
  gap: 1rem;
  align-items: start;
}

.chart-card,
.data-card { min-width: 0; }

.chart-card-wide { min-height: 20rem; }

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

.mini-bars { display: flex; flex-direction: column; gap: 0.82rem; padding: 1rem; }

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

.mini-bar strong { color: var(--primary); font-size: 0.82rem; text-align: right; }

.mini-track {
  position: relative;
  height: 0.62rem;
  overflow: hidden;
  border-radius: 999px;
  background: var(--bg-subtle);
}

.mini-track i {
  position: absolute;
  inset: 0 auto 0 0;
  min-width: 0.45rem;
  border-radius: inherit;
  background: linear-gradient(90deg, #3457ff, #6f8cff);
}

.data-card { margin-top: 0.25rem; }

.good { color: var(--success); }
.low  { color: var(--warn); }

/* ── Responsive teacher/generic ── */
@media (max-width: 1050px) {
  .analytics-grid { grid-template-columns: 1fr; }
}

@media (max-width: 720px) {
  .dashboard-hero { flex-direction: column; align-items: stretch; }
  .hero-actions   { justify-content: space-between; width: 100%; }
  .period-filter  { width: 100%; overflow-x: auto; }
  .period-select  { width: 100%; justify-content: space-between; }
  .period-select select { flex: 1; min-width: 0; }
  .kpi-grid, .kpi-grid.teacher { grid-template-columns: 1fr; }
  .mini-bar { grid-template-columns: 1fr; gap: 0.35rem; }
  .mini-bar strong { text-align: left; }
  .toolbar { position: static; }
}

/* ── Utilitaires ── */
.overview-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 0.85rem; }

.table-responsive { width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; }
.text-right { text-align: right; }

@keyframes pulse {
  0%, 100% { opacity: 1; }
  50%       { opacity: 0.5; }
}

.skeleton {
  animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
  background: var(--bg-subtle);
  border-color: transparent !important;
  box-shadow: none !important;
}

.skeleton-wrapper { display: flex; flex-direction: column; gap: 1rem; }
.skeleton-tall    { min-height: 20rem; }

[data-tooltip] { position: relative; cursor: pointer; }
[data-tooltip]:hover::after {
  content: attr(data-tooltip);
  position: absolute; bottom: 100%; left: 50%;
  transform: translateX(-50%);
  margin-bottom: 0.4rem;
  background: #0d1f4a; color: var(--text);
  border: 1px solid var(--border-strong);
  padding: 0.35rem 0.6rem;
  border-radius: 6px; font-size: 0.72rem; font-weight: 600;
  white-space: nowrap; z-index: 20; pointer-events: none;
  opacity: 0;
  animation: tooltipFade 0.2s forwards;
}
@keyframes tooltipFade { to { opacity: 1; transform: translateX(-50%) translateY(0); } }

.sortable { cursor: pointer; user-select: none; transition: background 0.18s; }
.sortable:hover { background: rgba(255,255,255,0.03); }
.sort-icon { width: 0.82rem; height: 0.82rem; vertical-align: middle; margin-left: 0.25rem; opacity: 0.4; }
.sortable:hover .sort-icon { opacity: 0.8; }

.badge { display: inline-flex; align-items: center; gap: 0.35rem; padding: 0.25rem 0.65rem; border-radius: 999px; font-size: 0.75rem; font-weight: 750; }
.badge-success { background: var(--success-soft); color: var(--success); }
.badge-warn    { background: var(--warn-soft); color: var(--warn); }
.badge-danger  { background: var(--danger-soft); color: var(--danger); }
.badge-dot { width: 0.4rem; height: 0.4rem; border-radius: 50%; }
.badge-success .badge-dot { background: #12b76a; }
.badge-warn    .badge-dot { background: var(--warn); }
.badge-danger  .badge-dot { background: var(--danger); }

.period-select.custom-dropdown { padding-right: 0.5rem; position: relative; }
.select-wrapper { position: relative; display: flex; align-items: center; }
.select-wrapper select { appearance: none; padding-right: 1.8rem; background: transparent; position: relative; z-index: 1; cursor: pointer; }
.select-icon { position: absolute; right: 0.4rem; width: 1rem; height: 1rem; color: var(--primary); pointer-events: none; }

.kpi-card-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 0.5rem; }
.kpi-icon { width: 2.2rem; height: 2.2rem; padding: 0.4rem; background: var(--bg-soft); border-radius: 8px; color: var(--primary); display: grid; place-items: center; }
.kpi-icon svg { width: 100%; height: 100%; stroke-width: 2; }
.kpi-card.warn .kpi-icon   { background: var(--warn-soft); color: var(--warn); }
.kpi-card.danger .kpi-icon { background: var(--danger-soft); color: var(--danger); }

.empty-state-pro { display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 3rem 1rem; text-align: center; color: var(--text-soft); }
.empty-state-pro.compact { min-height: 4rem; padding: 1.25rem; }
.empty-icon { width: 3rem; height: 3rem; margin-bottom: 0.8rem; color: #cbd5e1; stroke-width: 1.5; }
.empty-state-pro p { margin: 0; font-size: 0.87rem; font-weight: 500; }

@keyframes fadeInUp {
  from { opacity: 0; transform: translateY(8px); }
  to   { opacity: 1; transform: translateY(0); }
}
.fade-in { animation: fadeInUp 0.45s cubic-bezier(0.16, 1, 0.3, 1) both; }
</style>
