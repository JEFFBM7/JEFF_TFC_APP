<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { api } from '../api/client'
import MiniChart from '../components/charts/MiniChart.vue'
import type {
  ChartSeries,
  ReportCardData,
  ReportCardEvaluation,
  ReportCardSubject,
  StudentTimeline,
} from '../types'
import { formatAveragePercent } from '../utils/grades'

interface TermOption {
  id: number
  name: string
  school_year: string | null
  is_closed: boolean
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

const terms = ref<TermOption[]>([])
const periods = ref<PeriodOption[]>([])
const selectedTerm = ref<number | ''>('')
const selectedPeriod = ref<number | ''>('')
const report = ref<ReportCardData | null>(null)
const timeline = ref<StudentTimeline | null>(null)
const loading = ref(false)
const error = ref('')
const expandedSubjects = ref<Set<number>>(new Set())

const dateFormatter = new Intl.DateTimeFormat('fr-FR', {
  day: '2-digit',
  month: '2-digit',
  year: 'numeric',
})

const selectedTermOption = computed(() =>
  terms.value.find((term) => term.id === selectedTerm.value) ?? null,
)

const selectedPeriodOption = computed(() =>
  periods.value.find((period) => period.id === selectedPeriod.value) ?? null,
)

const termFieldLabel = computed(() => {
  const term = selectedTermOption.value
  if (term?.term_type === 'semestre') return 'Semestre'
  if (term?.type_label) return term.type_label
  return 'Trimestre'
})

const termFieldLabelLower = computed(() => termFieldLabel.value.toLowerCase())

const showPeriodSelector = computed(() => periods.value.length > 0)

const showPeriodBreakdown = computed(
  () => !selectedPeriod.value && (report.value?.period_averages?.length ?? 0) > 0,
)

const weightedPoints = computed(() => {
  if (!report.value) return 0
  return report.value.subjects.reduce((total, row) => {
    return row.average === null ? total : total + row.average * row.coefficient
  }, 0)
})

const possiblePoints = computed(() =>
  report.value ? report.value.total_coefficient * 20 : 0,
)

const averageTone = computed(() => {
  const value = report.value?.overall_average
  if (value === null || value === undefined) return 'muted'
  if (value >= 14) return 'success'
  if (value >= 10) return 'warning'
  return 'danger'
})

const averageMention = computed(() => {
  const value = report.value?.overall_average
  if (value === null || value === undefined) return 'Non calculée'
  if (value >= 16) return 'Très bien'
  if (value >= 14) return 'Bien'
  if (value >= 12) return 'Assez bien'
  if (value >= 10) return 'Satisfaisant'
  return 'À renforcer'
})

const miniAverageSeries = computed<ChartSeries[]>(() => [
  {
    name: 'Moyenne',
    data: (timeline.value?.term_averages ?? []).map((item) => toPercentFrom20(item.average)),
  },
])

const hasMiniAverage = computed(() =>
  (timeline.value?.term_averages ?? []).some((item) => item.average !== null),
)

const publishedEvalCount = computed(() =>
  report.value?.subjects.reduce((total, row) => total + row.count, 0) ?? 0,
)

const bulletinPendingMessage = computed(() => {
  if (!report.value || report.value.overall_average !== null) return ''
  const scope = selectedPeriodOption.value?.name ?? termFieldLabelLower.value
  if (publishedEvalCount.value === 0) {
    return `Aucune note publiée pour cette ${scope}. Les résultats apparaîtront ici après publication par l’école.`
  }
  return `La moyenne sera affichée lorsque toutes les notes de cette ${scope} seront publiées.`
})

function formatNumber(value: number | null | undefined, digits = 2): string {
  if (value === null || value === undefined || Number.isNaN(value)) return '—'
  return value.toFixed(digits)
}

function toPercentFrom20(value: number | null | undefined): number | null {
  if (value === null || value === undefined || Number.isNaN(value)) return null
  return (value / 20) * 100
}

function formatPercentFrom20(value: number | null | undefined, digits = 2): string {
  const percent = toPercentFrom20(value)
  if (percent === null) return '—'
  return percent.toFixed(digits)
}

function formatDate(value?: string | null): string {
  if (!value) return '—'
  const date = new Date(`${value}T00:00:00`)
  if (Number.isNaN(date.getTime())) return value
  return dateFormatter.format(date)
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

function subjectTone(value: number | null): string {
  if (value === null) return 'muted'
  if (value >= 14) return 'success'
  if (value >= 10) return 'warning'
  return 'danger'
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

async function loadTerms(): Promise<void> {
  const res = await api<TermsResponse>('/api/v1/student/terms')
  terms.value = res.data
  const recommended = res.meta?.recommended_term_id
  if (recommended && res.data.some((term) => term.id === recommended)) {
    selectedTerm.value = recommended
  } else if (res.data.length) {
    selectedTerm.value = res.data[res.data.length - 1].id
  }
}

async function loadPeriods(): Promise<void> {
  periods.value = []
  selectedPeriod.value = ''
  if (!selectedTerm.value) return

  try {
    const res = await api<PeriodsResponse>(`/api/v1/student/terms/${selectedTerm.value}/periods`)
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
  loading.value = true
  error.value = ''
  expandedSubjects.value = new Set()
  try {
    const query: Record<string, string> = {}
    if (selectedPeriod.value) query.period_id = String(selectedPeriod.value)
    const res = await api<{ data: ReportCardData }>(`/api/v1/student/report-card/${selectedTerm.value}`, {
      query,
    })
    report.value = res.data
  } catch {
    error.value = 'Impossible de charger le bulletin.'
    report.value = null
  } finally {
    loading.value = false
  }
}

async function loadTimeline(): Promise<void> {
  try {
    const res = await api<{ data: StudentTimeline }>('/api/v1/student/timeline')
    timeline.value = res.data
  } catch {
    timeline.value = null
  }
}

watch(selectedTerm, async () => {
  await loadPeriods()
  await loadReport()
})

watch(selectedPeriod, () => void loadReport())

onMounted(async () => {
  await Promise.all([loadTerms(), loadTimeline()])
  await loadPeriods()
  await loadReport()
})
</script>

<template>
  <section class="bulletin-page portal-bulletin">
    <div class="bulletin-toolbar portal-sticky-toolbar">
      <div class="toolbar-copy">
        <span>Bulletin scolaire</span>
        <strong>{{ selectedTermOption?.name ?? `Choisir un ${termFieldLabelLower}` }}</strong>
        <small>
          {{ selectedTermOption?.school_year ?? 'Sélectionnez une période' }}
          <template v-if="selectedPeriodOption"> · {{ selectedPeriodOption.name }}</template>
        </small>
      </div>
      <div class="toolbar-actions toolbar-filters">
        <label class="term-picker">
          <span>{{ termFieldLabel }}</span>
          <select v-model.number="selectedTerm">
            <option value="" disabled>{{ `Choisir un ${termFieldLabelLower}` }}</option>
            <option v-for="t in terms" :key="t.id" :value="t.id">
              {{ t.name }} - {{ t.school_year }}
            </option>
          </select>
        </label>
        <label v-if="showPeriodSelector" class="term-picker">
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

    <p v-if="error" class="alert alert-error">{{ error }}</p>
    <p v-else-if="bulletinPendingMessage" class="alert alert-info">{{ bulletinPendingMessage }}</p>

    <div v-if="loading" class="empty-state">Chargement…</div>
    <div v-else-if="!report" class="empty-state">Sélectionnez un trimestre.</div>
    <article v-else class="report-view">
      <section class="portal-compact-band" aria-label="Identité élève">
        <div>
          <span>Élève</span>
          <strong>{{ report.student.full_name }}</strong>
        </div>
        <div>
          <span>Classe</span>
          <strong>{{ report.student.classroom ?? '—' }}</strong>
        </div>
      </section>

      <section class="report-summary">
        <div class="average-block" :class="`tone-${averageTone}`">
          <span>{{ selectedPeriodOption ? 'Moyenne de période' : `Moyenne du ${termFieldLabelLower}` }}</span>
          <strong>
            <template v-if="report.overall_average !== null">
              {{ formatPercentFrom20(report.overall_average) }}<small>%</small>
            </template>
            <template v-else>—</template>
          </strong>
          <em>{{ averageMention }}</em>
        </div>
        <div class="summary-grid">
          <div>
            <span>Total pondéré</span>
            <strong>{{ formatNumber(weightedPoints) }} / {{ formatNumber(possiblePoints) }}</strong>
          </div>
          <div>
            <span>Coefficient évalué</span>
            <strong>{{ formatNumber(report.total_coefficient) }}</strong>
          </div>
          <div>
            <span>Cours évalués</span>
            <strong>{{ report.subjects.filter((row) => row.average !== null).length }} / {{ report.subjects.length }}</strong>
          </div>
          <div>
            <span>Mention</span>
            <strong>{{ averageMention }}</strong>
          </div>
        </div>
        <div v-if="hasMiniAverage" class="mini-trend">
          <span>Évolution</span>
          <MiniChart :series="miniAverageSeries" :y-max="100" value-suffix="%" />
        </div>
      </section>

      <section v-if="showPeriodBreakdown" class="grades-section">
        <div class="portal-section-title">
          <h2>Moyennes des périodes</h2>
          <p>{{ termFieldLabel }} = moyenne des périodes</p>
        </div>

        <div class="period-mobile-list">
          <article v-for="period in report.period_averages" :key="period.period_id" class="period-mobile-card">
            <span>{{ period.name }}</span>
            <strong>{{ formatPercentFrom20(period.average) }}%</strong>
          </article>
        </div>

        <div class="report-table-wrap">
          <table class="report-table">
            <thead>
              <tr>
                <th>Période</th>
                <th class="num">Moyenne %</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="period in report.period_averages" :key="period.period_id">
                <td>{{ period.name }}</td>
                <td class="num">{{ formatPercentFrom20(period.average) }}%</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section class="grades-section">
        <div class="portal-section-title">
          <h2>Relevé des notes</h2>
          <p>Moyennes par cours · touchez « Voir les cotes » pour le détail</p>
        </div>

        <div class="subject-mobile-list">
          <article v-for="s in report.subjects" :key="s.subject_id" class="subject-mobile-card portal-grade-card">
            <div class="subject-mobile-head portal-grade-card-head">
              <strong>{{ s.subject_name }}</strong>
              <span class="score-pill" :class="`tone-${subjectTone(s.average)}`">
                {{ formatAveragePercent(s.average, 1) }}
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
            <p class="subject-status portal-grade-status">{{ s.average === null ? 'Non évalué' : s.average >= 10 ? 'Acquis' : 'À renforcer' }}</p>

            <button
              v-if="hasEvaluations(s)"
              type="button"
              class="subject-expand-button portal-touch"
              :aria-expanded="isExpanded(s.subject_id)"
              @click="toggleSubject(s.subject_id)"
            >
              {{ isExpanded(s.subject_id) ? 'Masquer les cotes' : `Voir les ${s.count} cote(s)` }}
            </button>

            <div v-if="isExpanded(s.subject_id) && hasEvaluations(s)" class="subject-evaluations">
              <article
                v-for="evaluation in subjectEvaluations(s)"
                :key="evaluation.evaluation_id"
                class="evaluation-inline-card"
              >
                <div class="evaluation-inline-head">
                  <strong>{{ evaluation.type_label ?? evaluation.type ?? 'Évaluation' }}</strong>
                  <span class="score-pill" :class="`tone-${subjectTone(evaluation.normalized_value)}`">
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

          <article v-if="report.subjects.length > 0" class="subject-mobile-card portal-grade-card portal-grade-total">
            <div class="subject-mobile-head portal-grade-card-head">
              <strong>Moyenne générale</strong>
              <span class="score-pill" :class="`tone-${subjectTone(report.overall_average)}`">
                <template v-if="report.overall_average !== null">
                  {{ formatPercentFrom20(report.overall_average) }}%
                </template>
                <template v-else>—</template>
              </span>
            </div>
            <dl class="subject-mobile-metrics portal-metrics-grid">
              <div>
                <dt>Coeff.</dt>
                <dd>{{ formatNumber(report.total_coefficient) }}</dd>
              </div>
              <div>
                <dt>Éval.</dt>
                <dd>{{ report.subjects.reduce((total, row) => total + row.count, 0) }}</dd>
              </div>
              <div class="metric-wide">
                <dt>Points</dt>
                <dd>{{ formatNumber(weightedPoints) }}</dd>
              </div>
            </dl>
            <p class="subject-status portal-grade-status">{{ averageMention }}</p>
          </article>
        </div>

        <div class="report-table-wrap">
          <table class="report-table">
            <thead>
              <tr>
                <th class="rank-col">N°</th>
                <th>Cours</th>
                <th class="num">Coef.</th>
                <th class="num">Éval.</th>
                <th class="num">Moyenne</th>
                <th class="num">Points</th>
                <th>Appréciation</th>
              </tr>
            </thead>
            <tbody>
              <template v-for="(s, index) in report.subjects" :key="s.subject_id">
                <tr
                  class="subject-row"
                  :class="{
                    'is-expandable': hasEvaluations(s),
                    'is-expanded': isExpanded(s.subject_id),
                  }"
                >
                  <td class="rank-col">{{ index + 1 }}</td>
                  <td class="subject-name">
                    <button
                      v-if="hasEvaluations(s)"
                      type="button"
                      class="expand-toggle"
                      :aria-expanded="isExpanded(s.subject_id)"
                      :aria-label="isExpanded(s.subject_id) ? 'Masquer les cotes' : 'Afficher les cotes'"
                      @click="toggleSubject(s.subject_id)"
                    >
                      {{ isExpanded(s.subject_id) ? '−' : '+' }}
                    </button>
                    {{ s.subject_name }}
                  </td>
                  <td class="num">{{ formatNumber(s.coefficient) }}</td>
                  <td class="num">{{ s.count }}</td>
                  <td class="num">
                    <span class="score-pill" :class="`tone-${subjectTone(s.average)}`">
                      {{ formatAveragePercent(s.average, 1) }}
                    </span>
                  </td>
                  <td class="num">{{ s.average === null ? '—' : formatNumber(s.average * s.coefficient) }}</td>
                  <td>{{ s.average === null ? 'Non évalué' : s.average >= 10 ? 'Acquis' : 'À renforcer' }}</td>
                </tr>
                <tr v-if="isExpanded(s.subject_id) && hasEvaluations(s)" class="subject-detail-row">
                  <td colspan="7">
                    <table class="evaluation-nested-table">
                      <thead>
                        <tr>
                          <th>Type</th>
                          <th>Objectif</th>
                          <th>Date</th>
                          <th class="num">Cote obtenue</th>
                          <th class="num">Max</th>
                          <th class="num">Cote /20</th>
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
                          <td class="num">{{ formatGradeValue(evaluation) }}</td>
                          <td class="num">{{ formatNumber(evaluation.max_value) }}</td>
                          <td class="num">
                            <span class="score-pill" :class="`tone-${subjectTone(evaluation.normalized_value)}`">
                              {{ formatNumber(evaluation.normalized_value) }}
                            </span>
                          </td>
                          <td>{{ evaluationStatus(evaluation) }}</td>
                        </tr>
                      </tbody>
                    </table>
                  </td>
                </tr>
              </template>
            </tbody>
            <tfoot v-if="report.subjects.length > 0">
              <tr>
                <td colspan="2">Totaux</td>
                <td class="num">{{ formatNumber(report.total_coefficient) }}</td>
                <td class="num">{{ report.subjects.reduce((total, row) => total + row.count, 0) }}</td>
                <td class="num">{{ formatPercentFrom20(report.overall_average) }}%</td>
                <td class="num">{{ formatNumber(weightedPoints) }}</td>
                <td>{{ averageMention }}</td>
              </tr>
            </tfoot>
          </table>
        </div>
      </section>

      <section class="appreciation-preview">
        <div>
          <h2>Appréciation de l'enseignant principal</h2>
          <p>{{ report.appreciation || 'Aucune appréciation enregistrée pour ce trimestre.' }}</p>
        </div>
      </section>
    </article>
  </section>
</template>

<style scoped>
.bulletin-page {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.portal-bulletin {
  max-width: 36rem;
  margin: 0 auto;
  width: 100%;
}

.portal-bulletin .student-band {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.65rem;
  border: 0;
  border-radius: 0;
  overflow: visible;
}

.portal-bulletin .student-band div {
  gap: 0.22rem;
  min-width: 0;
  padding: 0.75rem;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: var(--bg-card);
  box-shadow: var(--shadow);
  border-right: 0;
}

.portal-bulletin .student-band div:first-child {
  grid-column: 1 / -1;
}

.portal-bulletin .report-summary {
  grid-template-columns: 1fr;
  gap: 0.7rem;
}

.portal-bulletin .average-block {
  min-height: 0;
  align-content: center;
  justify-items: center;
  text-align: center;
  padding: 1rem;
}

.portal-bulletin .summary-grid {
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.55rem;
}

.portal-bulletin .summary-grid div {
  min-height: 4.5rem;
}

.portal-bulletin .section-title {
  display: grid;
  gap: 0.2rem;
  align-items: start;
}

.portal-bulletin .period-mobile-card {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  align-items: center;
  gap: 0.65rem;
  padding: 0.85rem 1rem;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: var(--bg-card);
  box-shadow: var(--shadow);
}

.portal-bulletin .period-mobile-card span {
  color: var(--text-soft);
  font-size: 0.72rem;
  font-weight: 850;
  text-transform: uppercase;
}

.portal-bulletin .period-mobile-card strong {
  color: var(--text);
  font-size: 1.05rem;
}

.portal-bulletin .mobile-total-card {
  border-color: var(--primary-tint);
  background: var(--primary-tint);
}

.portal-bulletin .bulletin-toolbar {
  flex-direction: column;
  align-items: stretch;
  position: sticky;
  top: 4rem;
  z-index: 8;
  padding: 0.85rem;
}

.portal-bulletin .toolbar-actions:not(.toolbar-filters) {
  flex-direction: column;
  align-items: stretch;
}

.portal-bulletin .toolbar-actions.toolbar-filters {
  display: flex;
  flex-direction: row;
  flex-wrap: nowrap;
  align-items: flex-end;
  gap: 0.55rem;
  width: 100%;
}

.toolbar-filters .term-picker {
  flex: 1 1 0;
  min-width: 0;
}

.portal-bulletin .term-picker select,
.portal-bulletin .toolbar-filters .term-picker select {
  width: 100%;
  min-width: 0;
  min-height: 2.75rem;
}

.bulletin-toolbar {
  display: flex;
  align-items: stretch;
  justify-content: space-between;
  gap: 1rem;
  padding: 0.95rem;
  border: 1px solid var(--primary-soft);
  border-radius: var(--radius);
  background:
    linear-gradient(135deg, rgba(52, 87, 255, 0.1), rgba(255, 255, 255, 0.92)),
    var(--bg-card);
  box-shadow: var(--shadow);
}

.toolbar-copy {
  display: grid;
  align-content: center;
  gap: 0.18rem;
  min-width: 0;
}

.toolbar-copy span {
  color: var(--primary);
  font-size: 0.72rem;
  font-weight: 900;
  letter-spacing: 0.06em;
  text-transform: uppercase;
}

.toolbar-copy strong {
  color: var(--text);
  font-size: 1.1rem;
  line-height: 1.15;
}

.toolbar-copy small {
  color: var(--text-soft);
  font-size: 0.82rem;
  font-weight: 750;
}

.toolbar-actions {
  display: flex;
  align-items: end;
  gap: 0.65rem;
  flex-wrap: wrap;
}

.term-picker {
  display: grid;
  gap: 0.25rem;
  margin: 0;
}

.term-picker span,
.student-band span,
.summary-grid span,
.average-block span {
  color: var(--text-soft);
  font-size: 0.72rem;
  font-weight: 850;
  text-transform: uppercase;
}

.term-picker select {
  width: auto;
  min-width: 15rem;
}

.report-view {
  display: grid;
  gap: 1rem;
  width: 100%;
}

.student-band {
  display: grid;
  grid-template-columns: 1.6fr repeat(4, minmax(0, 1fr));
  border: 1px solid var(--border-strong);
  border-radius: 8px;
  overflow: hidden;
}

.student-band div {
  display: grid;
  gap: 0.18rem;
  padding: 0.7rem 0.8rem;
  border-right: 1px solid var(--border);
}

.student-band div:last-child {
  border-right: 0;
}

.student-band strong {
  color: var(--text);
  font-size: 0.92rem;
  overflow-wrap: anywhere;
}

.report-summary {
  display: grid;
  grid-template-columns: minmax(13rem, 0.35fr) minmax(0, 1fr);
  gap: 1rem;
}

.average-block,
.summary-grid div {
  display: grid;
  align-content: center;
  gap: 0.25rem;
  padding: 0.85rem;
  border: 1px solid var(--border);
  border-radius: 8px;
  background: var(--bg-soft);
}

.average-block {
  min-height: 8.75rem;
}

.average-block strong {
  display: flex;
  align-items: baseline;
  gap: 0.25rem;
  color: var(--text);
  font-size: 2.1rem;
  line-height: 1;
}

.average-block small {
  color: var(--text-soft);
  font-size: 0.92rem;
}

.average-block em {
  color: var(--text-soft);
  font-style: normal;
  font-weight: 850;
}

.average-block.tone-success {
  border-color: rgba(74, 222, 128, 0.3);
  background: var(--success-soft);
}

.average-block.tone-success strong {
  color: var(--success);
}

.average-block.tone-warning {
  border-color: rgba(251, 191, 36, 0.3);
  background: var(--warn-soft);
}

.average-block.tone-warning strong {
  color: var(--warn);
}

.average-block.tone-danger {
  border-color: rgba(248, 113, 113, 0.3);
  background: var(--danger-soft);
}

.average-block.tone-danger strong {
  color: var(--danger);
}

.summary-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.65rem;
}

.summary-grid strong {
  color: var(--text);
  font-size: 0.95rem;
}

.mini-trend {
  display: grid;
  grid-column: 1 / -1;
  gap: 0.25rem;
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

.grades-section {
  display: grid;
  gap: 0.7rem;
}

.section-title {
  display: flex;
  justify-content: space-between;
  gap: 1rem;
  align-items: end;
}

.section-title h2,
.appreciation-preview h2 {
  margin: 0;
}

.section-title span {
  color: var(--text-soft);
  font-size: 0.8rem;
  font-weight: 800;
}

.report-table-wrap {
  overflow-x: auto;
  border: 1px solid var(--border-strong);
  border-radius: 8px;
}

.portal-bulletin .period-mobile-list,
.portal-bulletin .subject-mobile-list {
  display: grid;
  gap: 0.65rem;
}

.portal-bulletin .report-table-wrap {
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

.period-mobile-list,
.subject-mobile-list {
  display: none;
}

.report-table {
  min-width: 48rem;
  border-collapse: collapse;
}

.report-table th,
.report-table td {
  border-right: 1px solid var(--border);
}

.report-table th:last-child,
.report-table td:last-child {
  border-right: 0;
}

.report-table tfoot td {
  background: var(--bg-soft);
  color: var(--text);
  font-weight: 900;
}

.rank-col {
  width: 3rem;
  text-align: center;
}

.subject-name {
  color: var(--text);
  font-weight: 850;
}

.subject-row.is-expandable .subject-name {
  display: flex;
  align-items: center;
  gap: 0.45rem;
}

.expand-toggle {
  flex: 0 0 auto;
  width: 1.5rem;
  height: 1.5rem;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 0;
  border: 1px solid var(--border-strong);
  border-radius: 6px;
  background: var(--bg-card);
  color: var(--primary);
  font-size: 1rem;
  font-weight: 900;
  line-height: 1;
  cursor: pointer;
}

.expand-toggle:hover {
  background: var(--primary-soft);
}

.subject-detail-row td {
  padding: 0;
  background: var(--bg-subtle);
}

.evaluation-nested-table {
  width: 100%;
  border-collapse: collapse;
}

.evaluation-nested-table th,
.evaluation-nested-table td {
  padding: 0.55rem 0.75rem;
  border-top: 1px solid var(--border);
  border-right: 1px solid var(--border);
  font-size: 0.88rem;
}

.evaluation-nested-table th:last-child,
.evaluation-nested-table td:last-child {
  border-right: 0;
}

.evaluation-nested-table thead th {
  background: var(--primary-soft);
  color: var(--text-soft);
  font-size: 0.72rem;
  font-weight: 850;
  text-transform: uppercase;
}

.num {
  text-align: right;
}

.score-pill {
  min-width: 4.2rem;
  display: inline-flex;
  justify-content: center;
  padding: 0.18rem 0.45rem;
  border-radius: 999px;
  background: var(--bg-soft);
  color: var(--text-soft);
  font-weight: 900;
}

.score-pill.tone-success {
  background: var(--success-soft);
  color: var(--success);
}

.score-pill.tone-warning {
  background: var(--warn-soft);
  color: var(--warn);
}

.score-pill.tone-danger {
  background: var(--danger-soft);
  color: var(--danger);
}

.subject-expand-button {
  width: 100%;
  min-height: 2.75rem;
  padding: 0.65rem 0.85rem;
  border: 1px solid var(--primary-tint);
  border-radius: 10px;
  background: var(--primary-soft);
  color: var(--primary);
  font-size: 0.88rem;
  font-weight: 850;
  cursor: pointer;
  touch-action: manipulation;
}

.subject-evaluations {
  display: grid;
  gap: 0.55rem;
}

.evaluation-inline-card {
  display: grid;
  gap: 0.35rem;
  padding: 0.7rem;
  border: 1px solid var(--border);
  border-radius: 8px;
  background: var(--bg-subtle);
}

.evaluation-inline-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 0.75rem;
}

.evaluation-inline-head strong {
  color: var(--text);
  font-size: 0.88rem;
}

.evaluation-inline-card p {
  margin: 0;
  color: var(--text);
  font-size: 0.84rem;
}

.evaluation-inline-card small {
  color: var(--text-soft);
  font-size: 0.78rem;
  font-weight: 750;
}

.appreciation-preview {
  display: block;
}

.appreciation-preview > div {
  display: grid;
  gap: 0.45rem;
  padding: 0.9rem;
  border: 1px solid var(--border);
  border-radius: 8px;
  background: var(--bg-subtle);
}

.appreciation-preview p {
  margin: 0;
  color: var(--text);
  line-height: 1.55;
  white-space: pre-wrap;
}

@media (max-width: 900px) {
  .report-summary {
    grid-template-columns: 1fr;
  }

  .student-band,
  .summary-grid {
    grid-template-columns: 1fr;
  }

  .student-band div {
    border-right: 0;
    border-bottom: 1px solid var(--border);
  }

  .student-band div:last-child {
    border-bottom: 0;
  }
}

@media (max-width: 720px) {
  .bulletin-page {
    gap: 0.85rem;
  }

  .bulletin-toolbar {
    align-items: stretch;
    flex-direction: column;
  }

  .toolbar-actions.toolbar-filters {
    flex-direction: row;
    flex-wrap: nowrap;
  }

  .bulletin-toolbar {
    position: sticky;
    top: 4rem;
    z-index: 8;
    margin: -0.15rem -0.15rem 0;
    padding: 0.8rem;
  }

  .toolbar-copy strong {
    font-size: 1rem;
  }

  .term-picker select {
    width: 100%;
    min-width: 0;
  }

  .report-view {
    gap: 0.85rem;
  }

  .average-block,
  .summary-grid div,
  .appreciation-preview > div {
    padding: 0.8rem;
  }

  .student-band {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.65rem;
    border: 0;
    border-radius: 0;
    overflow: visible;
  }

  .student-band div {
    gap: 0.22rem;
    min-width: 0;
    padding: 0.75rem;
    border: 1px solid var(--border);
    border-radius: var(--radius);
    background: var(--bg-card);
    box-shadow: var(--shadow);
  }

  .student-band div:first-child {
    grid-column: 1 / -1;
  }

  .student-band div:last-child {
    border-bottom: 1px solid var(--border);
  }

  .student-band strong {
    font-size: 0.9rem;
  }

  .report-summary {
    gap: 0.7rem;
  }

  .average-block {
    min-height: 0;
    align-content: center;
    justify-items: center;
    text-align: center;
    padding: 1rem;
  }

  .average-block strong {
    font-size: 2.35rem;
  }

  .summary-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.55rem;
  }

  .summary-grid div {
    min-height: 4.8rem;
  }

  .section-title {
    display: grid;
    gap: 0.2rem;
    align-items: start;
  }

  .portal-bulletin .bulletin-toolbar.portal-sticky-toolbar {
    position: static;
    top: auto;
  }
}

.alert-info {
  margin: 0;
  padding: 0.75rem 0.9rem;
  border-radius: var(--radius);
  border: 1px solid var(--primary-tint);
  background: var(--primary-soft);
  color: var(--accent);
  font-size: 0.86rem;
  font-weight: 600;
}
</style>
