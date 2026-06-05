<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import {
  AlertTriangle,
  AlertOctagon,
  Award,
  CheckCircle2,
  ChevronLeft,
  Clock,
  FileText,
  GraduationCap,
  TrendingUp,
} from 'lucide-vue-next'
import { usePortalDashboard } from '../composables/usePortalDashboard'
import { api, ApiError } from '../api/client'
import MiniChart from '../components/charts/MiniChart.vue'
import Modal from '../components/Modal.vue'
import type {
  AttendanceRecord,
  ChartSeries,
  Paginated,
  ReportCardData,
  ReportCardEvaluation,
  ReportCardSubject,
  StudentAttendanceSummary,
  StudentTimeline,
} from '../types'

interface TermOption {
  id: number
  name: string
  school_year: string | null
  term_type?: string
  type_label?: string
}

interface PeriodOption {
  id: number
  name: string
  position: number
}

interface TermsResponse {
  data: TermOption[]
  meta?: { recommended_term_id?: number | null }
}

interface PeriodsResponse {
  data: PeriodOption[]
  meta?: { recommended_period_id?: number | null; term_type_label?: string }
}

const props = defineProps<{ id: string | number }>()

import { chartPercentFromAverage20, formatAveragePercent } from '../utils/grades'

const { initials, formatPercentFrom20 } = usePortalDashboard()

const loading = ref(false)
const error = ref('')

const terms = ref<TermOption[]>([])
const periods = ref<PeriodOption[]>([])
const selectedTerm = ref<number | ''>('')
const selectedPeriod = ref<number | ''>('')

const report = ref<ReportCardData | null>(null)
const timeline = ref<StudentTimeline | null>(null)
const loadingReport = ref(false)
const expandedSubjects = ref<Set<number>>(new Set())

const summary = ref<StudentAttendanceSummary | null>(null)
const attendanceRows = ref<AttendanceRecord[]>([])
const loadingAtt = ref(false)

async function loadTerms(): Promise<void> {
  try {
    const res = await api<TermsResponse>(`/api/v1/parent/children/${props.id}/terms`)
    terms.value = res.data
    const recommended = res.meta?.recommended_term_id
    if (recommended && res.data.some((term) => term.id === recommended)) {
      selectedTerm.value = recommended
    } else if (res.data.length > 0) {
      selectedTerm.value = res.data[res.data.length - 1].id
    } else {
      selectedTerm.value = ''
    }
  } catch {
    terms.value = []
    selectedTerm.value = ''
  }
}

async function loadPeriods(): Promise<void> {
  periods.value = []
  selectedPeriod.value = ''
  if (!selectedTerm.value) return

  try {
    const res = await api<PeriodsResponse>(
      `/api/v1/parent/children/${props.id}/terms/${selectedTerm.value}/periods`,
    )
    periods.value = res.data
    const recommended = res.meta?.recommended_period_id
    if (recommended && res.data.some((period) => period.id === recommended)) {
      selectedPeriod.value = recommended
    }
  } catch {
    periods.value = []
  }
}

async function loadReport(): Promise<void> {
  if (!selectedTerm.value) { report.value = null; return }
  loadingReport.value = true
  expandedSubjects.value = new Set()
  try {
    const query: Record<string, string> = {}
    if (selectedPeriod.value) query.period_id = String(selectedPeriod.value)
    const res = await api<{ data: ReportCardData }>(
      `/api/v1/parent/children/${props.id}/report-card/${selectedTerm.value}`,
      { query },
    )
    report.value = res.data
  } catch {
    report.value = null
  } finally {
    loadingReport.value = false
  }
}

const miniAverageSeries = computed<ChartSeries[]>(() => [
  {
    name: 'Moyenne',
    data: (timeline.value?.term_averages ?? []).map((item) => chartPercentFromAverage20(item.average)),
  },
])

const hasMiniAverage = computed(() =>
  (timeline.value?.term_averages ?? []).some((item) => item.average !== null),
)

const weightedPoints = computed(() => {
  if (!report.value) return 0
  return report.value.subjects.reduce((total, row) => {
    return row.average === null ? total : total + row.average * row.coefficient
  }, 0)
})

const totalEvaluations = computed(() =>
  report.value?.subjects.reduce((total, row) => total + row.count, 0) ?? 0,
)

function formatNumber(value: number | null | undefined, digits = 2): string {
  if (value === null || value === undefined || Number.isNaN(value)) return '—'
  return value.toFixed(digits)
}

function formatDate(value?: string | null): string {
  if (!value) return '—'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return value
  return date.toLocaleDateString('fr-FR', { day: '2-digit', month: 'short', year: 'numeric' })
}

function formatDateTime(value?: string | null): string {
  if (!value) return '—'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return value
  return date.toLocaleDateString('fr-FR', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

function formatGradeValue(row: ReportCardEvaluation): string {
  if (row.absent) return 'Absent'
  if (row.value === null || row.value === undefined) return '—'
  return formatNumber(row.value)
}

function evaluationStatus(row: ReportCardEvaluation): string {
  if (row.absent) return 'Absent'
  if (row.normalized_value === null || row.normalized_value === undefined) return 'Non coté'
  return row.normalized_value >= 10 ? 'Acquis' : 'À renforcer'
}

function subjectEvaluations(subject: ReportCardSubject): ReportCardEvaluation[] {
  return subject.evaluations ?? []
}

function hasEvaluations(subject: ReportCardSubject): boolean {
  return subjectEvaluations(subject).length > 0
}

function isExpanded(subjectId: number): boolean {
  return expandedSubjects.value.has(subjectId)
}

function toggleSubject(subjectId: number): void {
  const next = new Set(expandedSubjects.value)
  if (next.has(subjectId)) next.delete(subjectId)
  else next.add(subjectId)
  expandedSubjects.value = next
}

function attendanceValidationLabel(attendance: AttendanceRecord): string {
  if (attendance.justified) return 'Confirmée'
  if (attendance.justification_status === 'expired') return 'Délai dépassé'
  if (attendance.justification_status === 'awaiting_student') return 'En attente élève'
  if (attendance.can_parent_confirm) return 'À confirmer'
  return 'Non confirmable'
}

function attendanceValidationClass(attendance: AttendanceRecord): string {
  if (attendance.justified) return 'badge-success'
  if (attendance.justification_status === 'expired') return 'badge-danger'
  if (attendance.can_parent_confirm) return 'badge-warn'
  return 'badge-muted'
}

function attendanceDeadlineHelp(attendance: AttendanceRecord): string {
  if (attendance.justified) return 'Justification validée.'
  if (attendance.justification_status === 'expired') return 'Le délai de justification est dépassé.'
  if (attendance.justification_status === 'pending_parent') {
    return `Motif envoyé par l’élève${attendance.student_justified_at ? ` le ${formatDateTime(attendance.student_justified_at)}` : ''}.`
  }
  if (attendance.justification_status === 'awaiting_student') return 'L’élève peut encore soumettre un motif.'
  return 'Aucune action disponible.'
}

const studentName = computed(() =>
  report.value?.student.full_name ?? summary.value?.full_name ?? 'Élève',
)

const studentClassroom = computed(() =>
  report.value?.student.classroom ?? 'Classe non renseignée',
)

const selectedTermOption = computed(() =>
  terms.value.find((term) => term.id === selectedTerm.value) ?? null,
)

const selectedTermLabel = computed(() =>
  report.value?.term.name ?? selectedTermOption.value?.name ?? '',
)

const selectedPeriodOption = computed(() =>
  periods.value.find((period) => period.id === selectedPeriod.value) ?? null,
)

const showPeriodSelector = computed(() => periods.value.length > 0)

const showPeriodBreakdown = computed(
  () => !selectedPeriod.value && (report.value?.period_averages?.length ?? 0) > 0,
)

const termFieldLabel = computed(() => {
  const term = selectedTermOption.value
  if (term?.term_type === 'semestre') return 'Semestre'
  if (term?.type_label) return term.type_label
  return 'Trimestre'
})

const termFieldLabelLower = computed(() => termFieldLabel.value.toLowerCase())

const currentAverage = computed(() => report.value?.overall_average ?? null)

const averageTone = computed<'good' | 'warn' | 'muted'>(() => {
  if (currentAverage.value === null) return 'muted'
  return currentAverage.value >= 10 ? 'good' : 'warn'
})

const pendingConfirmations = computed(() =>
  attendanceRows.value.filter((row) => row.can_parent_confirm && !row.justified).length,
)

const attendanceTone = computed<'good' | 'warn' | 'danger'>(() => {
  if (summary.value?.alert?.triggered) return 'danger'
  if ((summary.value?.unjustified ?? 0) > 0 || pendingConfirmations.value > 0) return 'warn'
  return 'good'
})

const averageProgress = computed(() => {
  if (currentAverage.value === null) return 0
  return Math.max(2, Math.min(100, (currentAverage.value / 20) * 100))
})

const averageStatusLabel = computed(() => {
  if (currentAverage.value === null) return 'Non publié'
  if (currentAverage.value >= 14) return 'Très bon niveau'
  if (currentAverage.value >= 10) return 'Satisfaisant'
  if (currentAverage.value >= 8) return 'À renforcer'
  return 'Suivi rapproché'
})

const averageStatusHelp = computed(() => {
  if (currentAverage.value === null) {
    return `La moyenne du ${termFieldLabelLower.value} apparaîtra dès publication.`
  }
  if (currentAverage.value >= 10) return 'Les résultats sont au-dessus du seuil de réussite.'
  return 'Consultez les cours faibles pour accompagner les révisions.'
})

const attendanceStatusLabel = computed(() => {
  if (!summary.value) return 'Indisponible'
  if (summary.value.alert?.triggered) return 'Alerte assiduité'
  if (pendingConfirmations.value > 0) return `${pendingConfirmations.value} à confirmer`
  if (summary.value.total_absences === 0 && summary.value.late_count === 0) return 'Rien à signaler'
  return 'À suivre'
})

const attendanceStatusHelp = computed(() => {
  if (!summary.value) return 'Les données d’assiduité ne sont pas encore disponibles.'
  if (summary.value.alert?.triggered) return 'Le seuil défini par l’école est atteint.'
  if (pendingConfirmations.value > 0) return 'Validez les motifs proposés par l’élève.'
  if (summary.value.unjustified > 0) return 'Certaines absences restent non justifiées.'
  return 'Les absences et retards sont à jour.'
})

const reportSubjectsCount = computed(() => report.value?.subjects.length ?? 0)

async function loadTimeline(): Promise<void> {
  try {
    const res = await api<{ data: StudentTimeline }>(`/api/v1/parent/children/${props.id}/timeline`)
    timeline.value = res.data
  } catch {
    timeline.value = null
  }
}

async function loadAttendance(): Promise<void> {
  loadingAtt.value = true
  try {
    const [summRes, attRes] = await Promise.all([
      api<{ data: StudentAttendanceSummary }>(`/api/v1/parent/children/${props.id}/attendance-summary`),
      api<Paginated<AttendanceRecord>>(`/api/v1/parent/children/${props.id}/attendances`),
    ])
    summary.value = summRes.data
    attendanceRows.value = attRes.data
  } catch {
    summary.value = null
    attendanceRows.value = []
  } finally {
    loadingAtt.value = false
  }
}

// ── Confirmation d'une justification élève par le parent ──
const showJustify = ref(false)
const justifyTarget = ref<AttendanceRecord | null>(null)
const justifyForm = reactive({ justification: '' })
const justifying = ref(false)
const justifyError = ref('')

function openJustify(att: AttendanceRecord): void {
  justifyTarget.value = att
  justifyForm.justification = att.student_justification ?? att.justification ?? ''
  justifyError.value = ''
  showJustify.value = true
}

async function submitJustify(): Promise<void> {
  if (!justifyTarget.value?.id) return
  justifying.value = true
  justifyError.value = ''
  try {
    await api(
      `/api/v1/parent/children/${props.id}/attendances/${justifyTarget.value.id}/justify`,
      { method: 'PATCH', body: { justification: justifyForm.justification } },
    )
    showJustify.value = false
    await loadAttendance()
  } catch (e) {
    justifyError.value = e instanceof ApiError ? e.message : 'Justification impossible.'
  } finally {
    justifying.value = false
  }
}

watch(selectedTerm, async () => {
  await loadPeriods()
  await loadReport()
})

watch(selectedPeriod, () => { void loadReport() })

watch(
  () => props.id,
  async () => {
    await loadTerms()
    await loadPeriods()
    await loadReport()
  },
)

onMounted(async () => {
  loading.value = true
  await Promise.all([loadTerms(), loadAttendance(), loadTimeline()])
  await loadPeriods()
  await loadReport()
  loading.value = false
})
</script>

<template>
  <section class="portal-dash portal-child-track portal-mobile">
    <RouterLink class="portal-child-back" :to="{ name: 'parent-children' }">
      <ChevronLeft aria-hidden="true" />
      Mes enfants
    </RouterLink>

    <p v-if="error" class="alert alert-error" role="alert">{{ error }}</p>

    <div v-if="loading" aria-hidden="true">
      <div class="portal-dash-skeleton" style="min-height: 8rem; margin-bottom: 0.75rem" />
      <div class="portal-dash-skeleton" style="min-height: 4rem; margin-bottom: 0.75rem" />
      <div class="portal-dash-skeleton" style="min-height: 14rem" />
    </div>

    <template v-else>
      <header
        class="portal-dash-hero portal-child-hero portal-dash-animate"
        :class="`portal-child-hero--${averageTone}`"
      >
        <div class="portal-dash-hero__identity">
          <div class="portal-dash-hero__avatar" aria-hidden="true">{{ initials(studentName) }}</div>
          <div class="portal-dash-hero__text">
            <p class="portal-dash-hero__date">
              <GraduationCap aria-hidden="true" />
              <span>Suivi enfant</span>
            </p>
            <h1>{{ studentName }}</h1>
            <p class="portal-dash-hero__meta">
              <span>{{ studentClassroom }}</span>
              <template v-if="selectedTermLabel">
                <span aria-hidden="true">·</span>
                <span class="portal-dash-hero__tag portal-dash-hero__tag--muted">{{ selectedTermLabel }}</span>
              </template>
              <template v-if="selectedPeriodOption">
                <span aria-hidden="true">·</span>
                <span class="portal-dash-hero__tag">{{ selectedPeriodOption.name }}</span>
              </template>
            </p>
          </div>
        </div>
        <div class="portal-child-hero__score" aria-label="Moyenne du trimestre">
          <span class="portal-child-hero__score-label">Moyenne</span>
          <strong class="portal-child-hero__score-value">
            {{ formatAveragePercent(currentAverage, 1) }}
          </strong>
          <em class="portal-child-hero__score-hint">{{ averageStatusLabel }}</em>
        </div>
      </header>

      <div
        class="portal-dash-summary-band portal-dash-animate portal-dash-animate--delay-1"
        aria-label="Synthèse scolaire"
      >
        <div class="portal-dash-summary-band__item">
          <span>Moyenne</span>
          <strong>{{ formatNumber(currentAverage) }}</strong>
        </div>
        <div class="portal-dash-summary-band__item">
          <span>Absences</span>
          <strong :style="(summary?.total_absences ?? 0) > 0 ? { color: 'var(--warn)' } : undefined">
            {{ summary?.total_absences ?? '—' }}
          </strong>
        </div>
        <div class="portal-dash-summary-band__item">
          <span>Retards</span>
          <strong :style="(summary?.late_count ?? 0) > 0 ? { color: 'var(--warn)' } : undefined">
            {{ summary?.late_count ?? '—' }}
          </strong>
        </div>
      </div>

      <div v-if="summary?.alert?.triggered" class="portal-dash-alerts portal-dash-animate portal-dash-animate--delay-1">
        <div class="portal-dash-alert portal-dash-alert--danger" role="alert">
          <AlertOctagon class="portal-dash-alert__icon" aria-hidden="true" />
          <div class="portal-dash-alert__body">
            <strong>Seuil d'alerte d'absentéisme atteint</strong>
            <span>
              {{ summary.alert.consecutive }} consécutives ·
              {{ summary.alert.count_recent_30d }} sur 30 jours
            </span>
          </div>
        </div>
      </div>

      <div class="portal-child-status portal-dash-animate portal-dash-animate--delay-2">
        <article class="portal-child-status__card" :class="`portal-child-status__card--${averageTone}`">
          <div class="portal-child-status__icon"><Award aria-hidden="true" /></div>
          <div class="portal-child-status__body">
            <span>Résultats</span>
            <strong>{{ averageStatusLabel }}</strong>
            <p>{{ averageStatusHelp }}</p>
            <div class="portal-child-progress" aria-hidden="true">
              <span
                :class="`span--${averageTone}`"
                :style="{ width: `${averageProgress}%` }"
              />
            </div>
          </div>
        </article>

        <article class="portal-child-status__card" :class="`portal-child-status__card--${attendanceTone}`">
          <div class="portal-child-status__icon"><AlertTriangle aria-hidden="true" /></div>
          <div class="portal-child-status__body">
            <span>Assiduité</span>
            <strong>{{ attendanceStatusLabel }}</strong>
            <p>{{ attendanceStatusHelp }}</p>
          </div>
        </article>

        <article
          v-if="pendingConfirmations > 0"
          class="portal-child-status__card portal-child-status__card--warn"
        >
          <div class="portal-child-status__icon"><CheckCircle2 aria-hidden="true" /></div>
          <div class="portal-child-status__body">
            <span>Validations parent</span>
            <strong>{{ pendingConfirmations }} à confirmer</strong>
            <p>Des justifications élève attendent votre validation.</p>
          </div>
        </article>
      </div>

      <!-- ── Bulletin ─────────────────────────────────────── -->
      <section class="portal-child-section portal-dash-animate portal-dash-animate--delay-2" aria-labelledby="bulletin-heading">
        <div class="portal-child-section__head">
          <div>
            <p class="portal-child-section__kicker">
              <FileText aria-hidden="true" />
              Bulletin
            </p>
            <h2 id="bulletin-heading">Résultats scolaires</h2>
            <p class="portal-child-section__sub">
              {{ report ? `${reportSubjectsCount} cours évalué${reportSubjectsCount > 1 ? 's' : ''}` : 'Moyenne et notes par cours' }}
            </p>
          </div>
          <div class="portal-child-filters">
            <label class="portal-child-term-picker">
              <span>{{ termFieldLabel }}</span>
              <select v-model.number="selectedTerm">
                <option value="" disabled>{{ `Choisir un ${termFieldLabelLower}` }}</option>
                <option v-for="t in terms" :key="t.id" :value="t.id">
                  {{ t.name }} ({{ t.school_year }})
                </option>
              </select>
            </label>
            <label v-if="showPeriodSelector" class="portal-child-term-picker">
              <span>Période</span>
              <select v-model.number="selectedPeriod">
                <option :value="''">{{ `Tout le ${termFieldLabelLower}` }}</option>
                <option v-for="p in periods" :key="p.id" :value="p.id">
                  {{ p.name }}
                </option>
              </select>
            </label>
          </div>
        </div>

        <div class="portal-child-section__body">

        <div v-if="loadingReport" class="empty-state">Chargement du bulletin…</div>
        <p
          v-else-if="report && report.subjects.length > 0 && totalEvaluations === 0"
          class="portal-publish-hint"
        >
          Aucune note publiée pour ce {{ termFieldLabelLower }}. Les enseignants doivent publier leurs évaluations
          après la saisie pour qu’elles apparaissent ici.
        </p>
        <template v-else-if="report && report.subjects.length > 0">
          <div
            v-if="report.overall_average !== null"
            class="portal-average-banner"
            :class="{ good: report.overall_average >= 10, warn: report.overall_average < 10 }"
          >
            <div>
              <span>{{ selectedPeriodOption ? 'Moyenne de période' : 'Moyenne générale' }}</span>
              <p>{{ averageStatusHelp }}</p>
            </div>
            <strong>{{ formatAveragePercent(report.overall_average, 1) }}</strong>
          </div>

          <section v-if="showPeriodBreakdown" class="portal-child-periods" aria-label="Moyennes par période">
            <h3 class="portal-child-periods__title">Moyennes des périodes</h3>
            <p class="portal-child-periods__sub">{{ termFieldLabel }} = moyenne des périodes</p>
            <div class="portal-child-periods__grid">
              <article
                v-for="period in report.period_averages"
                :key="period.period_id"
                class="portal-child-periods__card"
              >
                <span>{{ period.name }}</span>
                <strong>{{ formatPercentFrom20(period.average) }}%</strong>
              </article>
            </div>
          </section>

          <div v-if="hasMiniAverage" class="mini-trend">
            <span>
              <TrendingUp class="inline-icon" aria-hidden="true" />
              Évolution des moyennes
            </span>
            <MiniChart :series="miniAverageSeries" :height="96" :y-max="100" value-suffix="%" />
          </div>

          <div class="portal-section-title grades-heading">
            <h2>Relevé des notes</h2>
            <p>Coeff. · évaluations · points par cours · touchez « Voir les cotes »</p>
          </div>

          <div class="subject-mobile-list">
            <article v-for="s in report.subjects" :key="s.subject_id" class="subject-mobile-card portal-grade-card">
              <div class="subject-mobile-head portal-grade-card-head">
                <strong>{{ s.subject_name }}</strong>
                <span class="subject-score" :class="{ good: (s.average ?? 0) >= 10, warn: (s.average ?? 0) < 10 && s.average !== null }">
                  {{ s.average !== null ? formatAveragePercent(s.average, 1) : '—' }}
                </span>
              </div>
              <dl class="subject-mobile-metrics portal-metrics-grid">
                <div>
                  <dt>Coeff.</dt>
                  <dd>{{ formatNumber(s.coefficient) }}</dd>
                </div>
                <div>
                  <dt>Éval.</dt>
                  <dd>{{ s.count }}</dd>
                </div>
                <div class="metric-wide">
                  <dt>Points</dt>
                  <dd>{{ s.average === null ? '—' : formatNumber(s.average * s.coefficient) }}</dd>
                </div>
              </dl>
              <p class="portal-grade-status">
                {{ s.average === null ? 'Non évalué' : s.average >= 10 ? 'Acquis' : 'À renforcer' }}
              </p>
              <button
                v-if="hasEvaluations(s)"
                type="button"
                class="subject-expand-button portal-touch"
                :aria-expanded="isExpanded(s.subject_id)"
                @click="toggleSubject(s.subject_id)"
              >
                {{ isExpanded(s.subject_id) ? 'Masquer les cotes' : `Voir les ${subjectEvaluations(s).length} cote(s)` }}
              </button>

              <div v-if="isExpanded(s.subject_id) && hasEvaluations(s)" class="subject-evaluations">
                <article
                  v-for="evaluation in subjectEvaluations(s)"
                  :key="evaluation.evaluation_id"
                  class="evaluation-inline-card"
                >
                  <div class="evaluation-inline-head">
                    <strong>{{ evaluation.type_label ?? evaluation.type ?? 'Évaluation' }}</strong>
                    <span class="subject-score" :class="{ good: (evaluation.normalized_value ?? 0) >= 10, warn: (evaluation.normalized_value ?? 0) < 10 && evaluation.normalized_value !== null }">
                      {{ formatGradeValue(evaluation) }}
                    </span>
                  </div>
                  <p>{{ evaluation.name || '—' }}</p>
                  <small>
                    {{ formatDate(evaluation.held_on) }}
                    · Max {{ formatNumber(evaluation.max_value) }}
                    · /20 {{ formatNumber(evaluation.normalized_value) }}
                    · {{ evaluationStatus(evaluation) }}
                  </small>
                </article>
              </div>
            </article>

            <article class="subject-mobile-card portal-grade-card portal-grade-total">
              <div class="subject-mobile-head portal-grade-card-head">
                <strong>Moyenne générale</strong>
                <span class="subject-score" :class="{ good: (report.overall_average ?? 0) >= 10, warn: (report.overall_average ?? 0) < 10 && report.overall_average !== null }">
                  {{ report.overall_average !== null ? formatAveragePercent(report.overall_average, 1) : '—' }}
                </span>
              </div>
              <dl class="subject-mobile-metrics portal-metrics-grid">
                <div>
                  <dt>Coeff.</dt>
                  <dd>{{ formatNumber(report.total_coefficient) }}</dd>
                </div>
                <div>
                  <dt>Éval.</dt>
                  <dd>{{ totalEvaluations }}</dd>
                </div>
                <div class="metric-wide">
                  <dt>Points</dt>
                  <dd>{{ formatNumber(weightedPoints) }}</dd>
                </div>
              </dl>
            </article>
          </div>

          <div class="report-table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Cours</th>
                  <th style="text-align: right">Coeff.</th>
                  <th style="text-align: right">Éval.</th>
                  <th style="text-align: right">Moyenne</th>
                </tr>
              </thead>
              <tbody>
                <template v-for="s in report.subjects" :key="s.subject_id">
                <tr>
                  <td>
                    <button
                      v-if="hasEvaluations(s)"
                      type="button"
                      class="table-expand-button"
                      :aria-expanded="isExpanded(s.subject_id)"
                      @click="toggleSubject(s.subject_id)"
                    >
                      {{ isExpanded(s.subject_id) ? '−' : '+' }}
                    </button>
                    {{ s.subject_name }}
                  </td>
                  <td style="text-align: right">{{ s.coefficient }}</td>
                  <td style="text-align: right">{{ s.count }}</td>
                  <td style="text-align: right; font-weight: 600" :class="{ good: (s.average ?? 0) >= 10, warn: (s.average ?? 0) < 10 && s.average !== null }">
                    {{ s.average !== null ? s.average.toFixed(2) : '—' }}
                  </td>
                </tr>
                <tr v-if="isExpanded(s.subject_id) && hasEvaluations(s)" class="subject-detail-row">
                  <td colspan="4">
                    <table class="evaluation-nested-table">
                      <thead>
                        <tr>
                          <th>Type</th>
                          <th>Objectif</th>
                          <th>Date</th>
                          <th style="text-align: right">Cote</th>
                          <th style="text-align: right">Max</th>
                          <th style="text-align: right">/20</th>
                          <th>Statut</th>
                        </tr>
                      </thead>
                      <tbody>
                        <tr
                          v-for="evaluation in subjectEvaluations(s)"
                          :key="evaluation.evaluation_id"
                        >
                          <td>{{ evaluation.type_label ?? evaluation.type ?? 'Évaluation' }}</td>
                          <td>{{ evaluation.name || '—' }}</td>
                          <td>{{ formatDate(evaluation.held_on) }}</td>
                          <td style="text-align: right">{{ formatGradeValue(evaluation) }}</td>
                          <td style="text-align: right">{{ formatNumber(evaluation.max_value) }}</td>
                          <td style="text-align: right">{{ formatNumber(evaluation.normalized_value) }}</td>
                          <td>{{ evaluationStatus(evaluation) }}</td>
                        </tr>
                      </tbody>
                    </table>
                  </td>
                </tr>
                </template>
              </tbody>
              <tfoot>
                <tr>
                  <td colspan="3" style="text-align: right; font-weight: 700">Moyenne générale</td>
                  <td style="text-align: right; font-weight: 700; font-size: 1.05rem" :class="{ good: (report.overall_average ?? 0) >= 10, warn: (report.overall_average ?? 0) < 10 && report.overall_average !== null }">
                    {{ report.overall_average !== null ? formatAveragePercent(report.overall_average, 1) : '—' }}
                  </td>
                </tr>
              </tfoot>
            </table>
          </div>
        </template>
        <div v-else class="portal-child-empty">
          <strong>Aucune note publiée</strong>
          <p>
            {{
              selectedTerm
                ? `Les résultats apparaîtront ici une fois le ${termFieldLabelLower} évalué.`
                : `Choisissez un ${termFieldLabelLower} pour consulter le bulletin.`
            }}
          </p>
        </div>
        </div>
      </section>

      <!-- ── Absences ─────────────────────────────────────── -->
      <section class="portal-child-section portal-dash-animate portal-dash-animate--delay-3" aria-labelledby="attendance-heading">
        <div class="portal-child-section__head">
          <div>
            <p class="portal-child-section__kicker">
              <Clock aria-hidden="true" />
              Assiduité
            </p>
            <h2 id="attendance-heading">Absences et retards</h2>
            <p class="portal-child-section__sub">{{ attendanceStatusHelp }}</p>
          </div>
          <span class="portal-child-section__chip" :class="`portal-child-section__chip--${attendanceTone}`">
            {{ attendanceStatusLabel }}
          </span>
        </div>

        <div class="portal-child-section__body">
          <div v-if="loadingAtt" class="portal-child-empty">Chargement…</div>
          <template v-else-if="summary">
            <dl class="portal-dash-child-card__stats portal-dash-child-card__stats--quad">
              <div
                class="portal-dash-child-card__stat"
                :class="{
                  'portal-dash-child-card__stat--warn': summary.total_absences > 0,
                  'portal-dash-child-card__stat--danger': summary.alert?.triggered,
                }"
              >
                <dt>Absences</dt>
                <dd>{{ summary.total_absences }}</dd>
              </div>
              <div
                class="portal-dash-child-card__stat"
                :class="{ 'portal-dash-child-card__stat--warn': summary.unjustified > 0 }"
              >
                <dt>Non justifiées</dt>
                <dd>{{ summary.unjustified }}</dd>
              </div>
              <div class="portal-dash-child-card__stat">
                <dt>Justifiées</dt>
                <dd>{{ summary.justified }}</dd>
              </div>
              <div
                class="portal-dash-child-card__stat"
                :class="{ 'portal-dash-child-card__stat--warn': summary.late_count > 0 }"
              >
                <dt>Retards</dt>
                <dd>{{ summary.late_count }}</dd>
              </div>
            </dl>

            <div v-if="attendanceRows.length" class="portal-child-attendance-list">
              <article
                v-for="(a, index) in attendanceRows"
                :key="`${a.id ?? 'attendance'}-${index}`"
                class="portal-child-attendance-card"
                :class="{ 'portal-child-attendance-card--action': a.can_parent_confirm && !a.justified }"
              >
                <div class="portal-child-attendance-card__head">
                  <div>
                    <span class="portal-child-attendance-card__date">{{ formatDate(a.date) }}</span>
                    <strong class="portal-child-attendance-card__subject">{{ a.subject?.name ?? 'Cours non renseigné' }}</strong>
                  </div>
                  <span class="badge" :class="a.status === 'absent' ? 'badge-warn' : 'badge-muted'">
                    {{ a.status === 'absent' ? 'Absent' : 'Retard' }}
                  </span>
                </div>
                <p v-if="a.student_justification" style="margin:0;font-size:0.84rem;color:var(--text-soft)">
                  {{ a.student_justification }}
                </p>
                <small class="attendance-deadline">{{ attendanceDeadlineHelp(a) }}</small>
                <div class="portal-child-attendance-card__foot">
                  <span class="badge" :class="attendanceValidationClass(a)">
                    {{ attendanceValidationLabel(a) }}
                  </span>
                  <button
                    v-if="a.can_parent_confirm && a.id"
                    type="button"
                    class="btn-confirm"
                    @click="openJustify(a)"
                  >
                    Confirmer
                  </button>
                </div>
              </article>
            </div>

            <div v-else class="portal-child-empty">
              <strong>Aucune absence récente</strong>
              <p>Les absences et retards à suivre apparaîtront ici.</p>
            </div>
          </template>
          <div v-else class="portal-child-empty">
            <strong>Assiduité indisponible</strong>
            <p>Impossible de récupérer les informations d'assiduité pour le moment.</p>
          </div>
        </div>
      </section>
    </template>

    <Modal :open="showJustify" title="Confirmer la justification" @close="showJustify = false">
      <p v-if="justifyError" class="alert alert-error">{{ justifyError }}</p>
      <p v-if="justifyTarget" style="margin: 0 0 0.75rem; font-size: 0.9rem">
        <strong>Date :</strong> {{ formatDate(justifyTarget.date) }}
        <span v-if="justifyTarget.subject?.name"> · <strong>Cours :</strong> {{ justifyTarget.subject.name }}</span>
      </p>
      <p v-if="justifyTarget?.student_justification" class="alert" style="background:#f8fafc;border:1px solid var(--border);color:var(--text)">
        {{ justifyTarget.student_justification }}
      </p>
      <div class="field">
        <label for="just-text">Commentaire du responsable</label>
        <textarea
          id="just-text"
          v-model="justifyForm.justification"
          rows="4"
          maxlength="500"
          placeholder="Confirmez ou complétez le motif soumis par l'élève."
        />
      </div>
      <template #footer>
        <button type="button" @click="showJustify = false">Annuler</button>
        <button
          type="button"
          class="btn-primary"
          :disabled="justifying"
          @click="submitJustify"
        >
          {{ justifying ? 'Confirmation…' : 'Confirmer' }}
        </button>
      </template>
    </Modal>
  </section>
</template>

<style scoped>
.parent-child-page {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.portal-mobile {
  max-width: 36rem;
  margin: 0 auto;
  width: 100%;
}

.portal-mobile .subject-mobile-list {
  display: grid;
  gap: 0.65rem;
}

.portal-mobile .report-table-wrap {
  display: none;
}

.subject-mobile-metrics {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.55rem;
  margin: 0;
}

.subject-mobile-metrics div {
  display: grid;
  gap: 0.15rem;
  padding: 0.65rem 0.7rem;
  border-radius: 8px;
  background: var(--bg-subtle);
}

.subject-mobile-metrics .metric-wide {
  grid-column: 1 / -1;
}

.subject-mobile-metrics dt {
  color: var(--text-soft);
  font-size: 0.72rem;
  font-weight: 850;
  text-transform: uppercase;
}

.subject-mobile-metrics dd {
  margin: 0;
  color: var(--text);
  font-size: 1rem;
  font-weight: 850;
}

.subject-mobile-card {
  display: grid;
  gap: 0.75rem;
  padding: 1rem;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: var(--bg-card);
  box-shadow: var(--shadow);
}

.subject-mobile-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 0.75rem;
}

.subject-mobile-head strong {
  min-width: 0;
  color: var(--text);
  font-size: 0.95rem;
  line-height: 1.3;
}

.subject-status {
  margin: 0;
  color: var(--text-soft);
  font-size: 0.84rem;
  font-weight: 750;
}

.subject-score {
  flex: 0 0 auto;
  min-width: 3.8rem;
  padding: 0.28rem 0.6rem;
  border-radius: 999px;
  background: #f2f4f7;
  color: var(--text-soft);
  font-weight: 900;
  text-align: center;
}

.subject-score.good {
  background: var(--success-soft);
  color: var(--success);
}

.subject-score.warn {
  background: var(--warn-soft);
  color: var(--warn);
}

.subject-total-card {
  border-color: #d5e0ff;
  background: var(--primary-tint);
}

.back-link {
  min-height: 2.25rem;
  display: inline-flex;
  align-items: center;
  width: fit-content;
  color: var(--text-soft);
  font-weight: 800;
}

.back-link::before {
  content: '←';
  margin-right: 0.45rem;
}

.section-card {
  margin-bottom: 0.25rem;
}

.card-header h2 {
  margin: 0;
}

.att-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(8rem, 1fr));
  gap: 0.75rem;
  margin: 0;
  padding: 1rem;
  text-align: center;
}
.att-grid dt { font-size: 0.78rem; color: var(--text-soft); margin: 0; }
.att-grid dd { font-size: 1.2rem; font-weight: 700; margin: 0.2rem 0 0; }
.good { color: #16a34a; }
.warn { color: #ea580c; }
.badge-warn { background: #fff7ed; color: #9a3412; }
.badge-danger { background: #fef3f2; color: #b42318; }

.attendance-alert {
  margin: 0.75rem 0 0;
  border: 1px solid #fdba74;
  background: #fff7ed;
  color: #9a3412;
}

.attendance-followup {
  display: grid;
  gap: 0.55rem;
  margin-top: 0.75rem;
}

.attendance-followup h3 {
  margin: 0;
  color: var(--text-soft);
  font-size: 0.9rem;
}

.attendance-table-wrap,
.report-table-wrap {
  overflow-x: auto;
}

.attendance-table-wrap table {
  min-width: 42rem;
}

.report-table-wrap table {
  min-width: 32rem;
}

.attendance-mobile-list,
.subject-mobile-list {
  display: none;
}

.portal-mobile .attendance-mobile-list {
  display: grid;
  gap: 0.7rem;
}

.portal-mobile .attendance-table-wrap {
  display: none;
}

.portal-mobile .att-grid {
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.55rem;
  padding: 0 0.85rem 0.85rem;
  text-align: left;
}

.portal-mobile .att-grid div {
  padding: 0.65rem;
  border-radius: 8px;
  background: var(--bg-subtle);
}

.portal-mobile .report-controls {
  flex-direction: column;
  align-items: stretch;
}

.portal-mobile .report-controls select,
.portal-mobile .report-controls button {
  width: 100%;
  min-height: 2.75rem;
}

.portal-bulletin-card {
  overflow: visible;
}

.portal-bulletin-card .report-header {
  display: grid;
  gap: 0.75rem;
  padding: 0;
  border: 0;
  background: transparent;
}

.portal-bulletin-card .card-header {
  padding: 0.85rem;
}

.term-picker-label {
  display: grid;
  gap: 0.25rem;
  width: 100%;
}

.term-picker-label span {
  color: var(--text-soft);
  font-size: 0.72rem;
  font-weight: 850;
  text-transform: uppercase;
}

.portal-average-banner {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  margin: 0 0.85rem 0.75rem;
  padding: 0.9rem 1rem;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: var(--bg-subtle);
}

.portal-average-banner span {
  color: var(--text-soft);
  font-size: 0.72rem;
  font-weight: 850;
  text-transform: uppercase;
}

.portal-average-banner strong {
  display: flex;
  align-items: baseline;
  gap: 0.2rem;
  font-size: 1.65rem;
  line-height: 1;
}

.portal-average-banner small {
  font-size: 0.9rem;
  font-weight: 750;
  color: var(--text-soft);
}

.portal-average-banner.good {
  border-color: #bbf7d0;
  background: #f0fdf4;
}

.portal-average-banner.good strong {
  color: var(--success);
}

.portal-average-banner.warn {
  border-color: #fed7aa;
  background: #fff7ed;
}

.portal-average-banner.warn strong {
  color: var(--warn);
}

.grades-heading {
  padding: 0 0.85rem;
}

.portal-publish-hint {
  margin: 0 0.85rem 0.75rem;
  padding: 0.7rem 0.85rem;
  border-radius: var(--radius);
  border: 1px solid #bfdbfe;
  background: #eff6ff;
  color: #1e40af;
  font-size: 0.84rem;
  line-height: 1.45;
}

.portal-mobile .mini-trend {
  margin: 0 0.85rem 0.75rem;
  max-width: none;
}

.portal-mobile .subject-mobile-list {
  display: grid;
  gap: 0.65rem;
  padding: 0 0.85rem 0.85rem;
}

.portal-mobile .report-table-wrap {
  display: none;
}

.portal-empty-block {
  padding: 1.25rem 0.85rem;
  text-align: center;
}

.portal-empty-block strong {
  display: block;
  margin-bottom: 0.35rem;
}

.portal-empty-block p {
  margin: 0;
  color: var(--text-soft);
  font-size: 0.88rem;
}

.portal-mobile .attendance-card-foot .btn-sm {
  min-height: 2.75rem;
  padding: 0.5rem 0.9rem;
}

.report-header {
  flex-wrap: wrap;
  gap: 0.75rem;
}

.report-controls {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.report-controls select {
  min-width: 10rem;
}

.mini-trend {
  display: grid;
  gap: 0.25rem;
  max-width: 28rem;
  margin-bottom: 0.75rem;
  padding: 0.55rem 0.75rem;
  border: 1px solid var(--border);
  border-radius: 8px;
  background: var(--bg-subtle);
}
.mini-trend span {
  color: var(--text-soft);
  font-size: 0.72rem;
  font-weight: 850;
  text-transform: uppercase;
}
.btn-sm {
  font-size: 0.85rem;
  padding: 0.4rem 0.8rem;
  border-radius: 6px;
  border: 1px solid var(--border);
  background: var(--bg-card);
  cursor: pointer;
}
.btn-sm:hover:not(:disabled) { background: var(--primary-soft); }
.btn-sm:disabled { opacity: 0.5; }

@media (max-width: 720px) {
  .card-header,
  .report-header,
  .report-controls {
    align-items: stretch;
    flex-direction: column;
  }

  .report-controls select,
  .report-controls button {
    width: 100%;
    min-height: 2.65rem;
  }

  .att-grid {
    padding: 0 0.85rem 0.85rem;
    text-align: left;
  }

  .attendance-table-wrap {
    display: none;
  }

  .attendance-mobile-list {
    display: grid;
    gap: 0.7rem;
  }

  .attendance-card {
    display: grid;
    gap: 0.65rem;
    padding: 0.85rem;
    border: 1px solid var(--border);
    border-radius: var(--radius);
    background: var(--bg-card);
    box-shadow: var(--shadow);
  }

  .attendance-card-head,
  .attendance-card-foot,
  .subject-mobile-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 0.7rem;
  }

  .attendance-card-head span:first-child {
    display: block;
    color: var(--text-soft);
    font-size: 0.74rem;
    font-weight: 850;
    text-transform: uppercase;
  }

  .attendance-card-head strong,
  .subject-mobile-head strong {
    display: block;
    min-width: 0;
    margin-top: 0.12rem;
    color: var(--text);
    line-height: 1.25;
  }

  .attendance-card p {
    margin: 0;
    color: var(--text-soft);
    font-size: 0.86rem;
    line-height: 1.45;
  }

  .attendance-card-foot {
    align-items: center;
    flex-wrap: wrap;
  }

  .attendance-card-foot .btn-sm {
    min-height: 2.45rem;
    margin-left: auto;
  }
}

.child-back-link {
  width: fit-content;
  gap: 0.45rem;
  color: var(--text-soft);
  text-decoration: none;
}

.child-back-link::before {
  content: '←';
}

.inline-icon,
.btn-icon {
  width: 1rem;
  height: 1rem;
  flex-shrink: 0;
}

.child-loading {
  display: grid;
  gap: 0.8rem;
}

.child-loading-hero,
.child-loading-grid span {
  border-radius: var(--radius);
  background: #e8eef8;
  animation: child-pulse 1.35s ease-in-out infinite;
}

.child-loading-hero {
  min-height: 8.5rem;
}

.child-loading-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 0.65rem;
}

.child-loading-grid span {
  min-height: 6rem;
}

@keyframes child-pulse {
  0%,
  100% { opacity: 1; }
  50% { opacity: 0.52; }
}

.child-hero {
  position: relative;
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  gap: 1rem;
  align-items: center;
  overflow: hidden;
  padding: 1.05rem;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: linear-gradient(135deg, #ffffff 0%, #fff7fb 50%, #f5f7ff 100%);
  box-shadow: var(--shadow-card);
}

.child-hero::after {
  content: '';
  position: absolute;
  right: -2.5rem;
  bottom: -3.5rem;
  width: 9rem;
  height: 9rem;
  border-radius: 999px;
  background: rgba(236, 72, 153, 0.11);
}

.child-hero.tone-good {
  background: linear-gradient(135deg, #ffffff 0%, #f0fdf4 100%);
}

.child-hero.tone-warn {
  background: linear-gradient(135deg, #ffffff 0%, #fff7ed 100%);
}

.child-hero-main {
  position: relative;
  z-index: 1;
  display: flex;
  align-items: center;
  gap: 0.85rem;
  min-width: 0;
}

.child-avatar {
  display: grid;
  width: 3.25rem;
  height: 3.25rem;
  place-items: center;
  flex-shrink: 0;
  border-radius: 999px;
  background: linear-gradient(135deg, #ec4899, #7c3aed);
  color: #fff;
  font-size: 1.05rem;
  font-weight: 900;
  box-shadow: 0 10px 20px rgba(124, 58, 237, 0.18);
}

.child-identity {
  min-width: 0;
}

.eyebrow,
.child-meta,
.portal-section-kicker,
.mini-trend span {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
}

.eyebrow {
  margin: 0;
  color: #a855f7;
  font-size: 0.72rem;
  font-weight: 850;
  letter-spacing: 0.04em;
  text-transform: uppercase;
}

.child-identity h1 {
  margin: 0.15rem 0 0;
  overflow-wrap: anywhere;
  font-size: 1.25rem;
  line-height: 1.15;
}

.child-meta {
  margin: 0.45rem 0 0;
  flex-wrap: wrap;
  color: var(--text-soft);
  font-size: 0.82rem;
  font-weight: 700;
}

.child-hero-score {
  position: relative;
  z-index: 1;
  display: grid;
  min-width: 7.2rem;
  gap: 0.15rem;
  justify-items: end;
  padding: 0.75rem;
  border: 1px solid rgba(255, 255, 255, 0.78);
  border-radius: var(--radius);
  background: rgba(255, 255, 255, 0.72);
  box-shadow: var(--shadow);
}

.child-hero-score span,
.child-hero-score em,
.insight-body span,
.status-chip,
.term-picker-label span,
.portal-average-banner span {
  color: var(--text-soft);
  font-size: 0.72rem;
  font-weight: 850;
  text-transform: uppercase;
}

.child-hero-score strong {
  display: flex;
  align-items: baseline;
  gap: 0.12rem;
  color: var(--text);
  font-size: 1.8rem;
  font-weight: 950;
  line-height: 1;
}

.child-hero-score small {
  color: var(--text-soft);
  font-size: 0.9rem;
  font-weight: 800;
}

.child-hero-score em {
  font-style: normal;
  text-align: right;
  text-transform: none;
}

.insight-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 0.65rem;
}

.insight-card {
  display: flex;
  gap: 0.65rem;
  min-width: 0;
  padding: 0.85rem;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: var(--bg-card);
  box-shadow: var(--shadow);
}

.insight-card.tone-good {
  border-color: #bbf7d0;
  background: #f6fef9;
}

.insight-card.tone-warn {
  border-color: #fedf89;
  background: #fffcf5;
}

.insight-card.tone-danger {
  border-color: #fecdca;
  background: #fffbfa;
}

.insight-icon {
  display: grid;
  width: 2.15rem;
  height: 2.15rem;
  place-items: center;
  flex-shrink: 0;
  border-radius: 10px;
  background: var(--primary-soft);
  color: var(--primary);
}

.insight-card.tone-good .insight-icon {
  background: var(--success-soft);
  color: var(--success);
}

.insight-card.tone-warn .insight-icon {
  background: var(--warn-soft);
  color: var(--warn);
}

.insight-card.tone-danger .insight-icon {
  background: var(--danger-soft);
  color: var(--danger);
}

.insight-icon svg {
  width: 1.1rem;
  height: 1.1rem;
}

.insight-body {
  display: grid;
  gap: 0.18rem;
  min-width: 0;
}

.insight-body strong {
  color: var(--text);
  font-size: 0.95rem;
  font-weight: 900;
  line-height: 1.2;
}

.insight-body p {
  margin: 0;
  color: var(--text-soft);
  font-size: 0.78rem;
  line-height: 1.35;
}

.progress-track {
  height: 0.38rem;
  overflow: hidden;
  margin-top: 0.25rem;
  border-radius: 999px;
  background: rgba(15, 23, 42, 0.08);
}

.progress-track span {
  display: block;
  height: 100%;
  border-radius: inherit;
  background: #94a3b8;
}

.progress-track span.good {
  background: var(--success);
}

.progress-track span.warn {
  background: var(--warn);
}

.child-section-card,
.portal-bulletin-card {
  overflow: visible;
}

.portal-bulletin-card .report-header,
.child-section-card .section-header {
  display: flex;
  align-items: flex-start;
  gap: 0.8rem;
  padding: 1rem 1.15rem;
  border-bottom: 1px solid var(--border);
  background: linear-gradient(180deg, #ffffff 0%, #fbfcff 100%);
}

.portal-section-title {
  margin: 0;
}

.portal-section-title h2 {
  margin: 0;
}

.portal-mobile .report-controls,
.report-controls {
  display: flex;
  flex-direction: row;
  align-items: end;
  gap: 0.55rem;
}

.term-picker-label {
  min-width: 12rem;
  margin: 0;
}

.portal-mobile .report-controls button,
.report-controls button {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.45rem;
  width: auto;
  white-space: nowrap;
}

.portal-average-banner {
  margin: 0 0.95rem 0.8rem;
  padding: 0.95rem;
}

.portal-average-banner div {
  display: grid;
  gap: 0.18rem;
}

.portal-average-banner p {
  margin: 0;
  color: var(--text-soft);
  font-size: 0.82rem;
  font-weight: 650;
}

.mini-trend {
  margin: 0 0.95rem 0.8rem;
}

.portal-mobile .subject-mobile-list {
  padding: 0 0.95rem 0.95rem;
}

.subject-mobile-card {
  gap: 0.75rem;
}

.status-chip {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-height: 1.9rem;
  padding: 0.32rem 0.7rem;
  border-radius: 999px;
  white-space: nowrap;
}

.status-chip.tone-good {
  background: var(--success-soft);
  color: var(--success);
}

.status-chip.tone-warn {
  background: var(--warn-soft);
  color: var(--warn);
}

.status-chip.tone-danger {
  background: var(--danger-soft);
  color: var(--danger);
}

.attendance-alert {
  margin: 0 0.95rem 0.85rem;
}

.attendance-followup {
  padding: 0 0.95rem 0.95rem;
}

.attendance-card.needs-action {
  border-color: #fedf89;
  background: #fffcf5;
}

.btn-confirm {
  border-color: #d5e0ff;
  background: var(--primary-soft);
  color: var(--primary);
}

.btn-confirm:hover:not(:disabled) {
  border-color: #c7d5ff;
  background: #e5edff;
}

@media (max-width: 720px) {
  .child-hero {
    grid-template-columns: 1fr;
    align-items: stretch;
    padding: 0.95rem;
  }

  .child-hero-score {
    grid-template-columns: 1fr auto;
    align-items: center;
    justify-items: start;
    min-width: 0;
  }

  .child-hero-score strong {
    grid-row: span 2;
    justify-self: end;
  }

  .insight-grid {
    grid-template-columns: 1fr;
  }

  .portal-bulletin-card .report-header,
  .child-section-card .section-header,
  .portal-mobile .report-controls {
    align-items: stretch;
    flex-direction: column;
  }

  .term-picker-label,
  .portal-mobile .report-controls select,
  .portal-mobile .report-controls button {
    width: 100%;
    min-width: 0;
  }

  .portal-mobile .report-controls button {
    min-height: 2.75rem;
  }
}

@media (max-width: 420px) {
  .child-hero-main {
    align-items: flex-start;
  }

  .child-avatar {
    width: 2.75rem;
    height: 2.75rem;
    font-size: 0.95rem;
  }

  .child-identity h1 {
    font-size: 1.12rem;
  }

  .portal-average-banner,
  .attendance-card-head {
    align-items: flex-start;
    flex-direction: column;
  }
}

.mini-trend {
  min-height: 8rem;
  border-color: #d8e2ff;
  background:
    linear-gradient(135deg, rgba(52, 87, 255, 0.08), rgba(124, 58, 237, 0.05)),
    #ffffff;
}

.mini-trend :deep(.apexcharts-canvas),
.mini-trend :deep(svg) {
  background: transparent !important;
}

.subject-expand-button {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-height: 2.35rem;
  border-color: #d5e0ff;
  background: var(--primary-soft);
  color: var(--primary);
  font-size: 0.85rem;
  font-weight: 850;
}

.subject-expand-button:hover {
  border-color: #c7d5ff;
  background: #e5edff;
}

.subject-evaluations {
  display: grid;
  gap: 0.55rem;
}

.evaluation-inline-card {
  display: grid;
  gap: 0.35rem;
  padding: 0.75rem;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: #fbfcff;
}

.evaluation-inline-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 0.75rem;
}

.evaluation-inline-head strong {
  min-width: 0;
  color: var(--text);
  font-size: 0.9rem;
  line-height: 1.25;
}

.evaluation-inline-card p {
  margin: 0;
  color: var(--text);
  font-size: 0.86rem;
  font-weight: 700;
}

.evaluation-inline-card small,
.attendance-deadline {
  display: block;
  color: var(--text-soft);
  font-size: 0.78rem;
  font-weight: 650;
  line-height: 1.4;
}

.table-expand-button {
  display: inline-grid;
  width: 1.6rem;
  height: 1.6rem;
  min-height: 0;
  place-items: center;
  margin-right: 0.45rem;
  padding: 0;
  border-color: #d5e0ff;
  border-radius: 999px;
  background: var(--primary-soft);
  color: var(--primary);
  font-weight: 900;
}

.subject-detail-row > td {
  padding: 0;
  background: var(--bg-subtle);
}

.evaluation-nested-table {
  min-width: 48rem;
  font-size: 0.86rem;
}

.evaluation-nested-table th,
.evaluation-nested-table td {
  padding: 0.55rem 0.7rem;
}

td .attendance-deadline {
  margin-top: 0.2rem;
}
</style>
