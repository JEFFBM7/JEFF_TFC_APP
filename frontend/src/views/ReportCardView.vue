<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { api, apiUrl, ApiError, getToken } from '../api/client'
import CtebBulletinSheet from '../components/bulletin/CtebBulletinSheet.vue'
import PrimaireDebutBulletinSheet from '../components/bulletin/PrimaireDebutBulletinSheet.vue'
import { isPrimaireDebutLevel } from '../data/primaryBulletinStructure'
import MiniChart from '../components/charts/MiniChart.vue'
import type { ApiResource, ChartSeries, ReportCardData, SchoolYear, Student, StudentTimeline, Term } from '../types'
import { useSchoolYearStore } from '../stores/schoolYear'
import { chartPercentFromAverage20, formatAveragePercent } from '../utils/grades'

const props = withDefaults(defineProps<{ id: string | number; embedded?: boolean }>(), {
  embedded: false,
})

const schoolYearStore = useSchoolYearStore()
const student = ref<Student | null>(null)
const selectedTerm = ref<number | null>(null)
const report = ref<ReportCardData | null>(null)
const ctebSemesterReports = ref<[ReportCardData | null, ReportCardData | null]>([null, null])
const primaryTrimesterReports = ref<[ReportCardData | null, ReportCardData | null, ReportCardData | null]>([null, null, null])
const ctebRank = ref<number | null>(null)
const ctebClassSize = ref<number | null>(null)
const primaryRank = ref<number | null>(null)
const primaryClassSize = ref<number | null>(null)
const timeline = ref<StudentTimeline | null>(null)
const loading = ref(false)
const error = ref('')

const appreciation = ref('')
const savingAppreciation = ref(false)
const appreciationMsg = ref('')

const schoolYears = computed<SchoolYear[]>(() =>
  schoolYearStore.years.length > 0
    ? schoolYearStore.years
    : schoolYearStore.current ? [schoolYearStore.current] : [],
)

const allTerms = computed<{ term: Term; year: SchoolYear }[]>(() =>
  schoolYears.value
    .flatMap((y) => (y.terms ?? []).map((t) => ({ term: t, year: y })))
    .filter(({ term }) => schoolYearStore.effectiveId === null || term.school_year_id === schoolYearStore.effectiveId),
)

const selectedTermOption = computed(() =>
  allTerms.value.find(({ term }) => term.id === selectedTerm.value) ?? null,
)

const isCtebStudent = computed(() => student.value?.classroom?.level?.cycle === 'cteb')

const isPrimaireDebutStudent = computed(() =>
  isPrimaireDebutLevel(student.value?.classroom?.level ?? null),
)

const isOfficialAnnualBulletin = computed(() =>
  isCtebStudent.value || isPrimaireDebutStudent.value,
)

const ctebSchoolYear = computed(() =>
  selectedTermOption.value?.year
  ?? schoolYears.value.find((year) => year.id === schoolYearStore.effectiveId)
  ?? schoolYearStore.current
  ?? null,
)

const ctebSemesterTerms = computed(() => {
  const yearId = ctebSchoolYear.value?.id
  if (!yearId) return [] as Array<{ term: Term; year: SchoolYear }>

  return allTerms.value
    .filter(({ term, year }) => year.id === yearId)
    .filter(({ term }) => term.applicable_cycle === 'secondaire' || term.term_type === 'semestre')
    .sort((a, b) => a.term.position - b.term.position)
    .slice(0, 2)
})

const primarySchoolYear = computed(() =>
  selectedTermOption.value?.year
  ?? schoolYears.value.find((year) => year.id === schoolYearStore.effectiveId)
  ?? schoolYearStore.current
  ?? null,
)

const primaryTrimesterTerms = computed(() => {
  const yearId = primarySchoolYear.value?.id
  if (!yearId) return [] as Array<{ term: Term; year: SchoolYear }>

  return allTerms.value
    .filter(({ term, year }) => year.id === yearId)
    .filter(({ term }) => term.applicable_cycle === 'primaire' || term.term_type === 'trimestre')
    .sort((a, b) => a.term.position - b.term.position)
    .slice(0, 3)
})

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

const decisionLabel = computed(() => {
  const value = report.value?.overall_average
  if (value === null || value === undefined) return 'En attente'
  return value >= 10 ? 'Résultat satisfaisant' : 'Suivi pédagogique requis'
})

const miniAverageSeries = computed<ChartSeries[]>(() => [
  {
    name: 'Moyenne',
    data: (timeline.value?.term_averages ?? []).map((item) => chartPercentFromAverage20(item.average)),
  },
])

const hasMiniAverage = computed(() =>
  (timeline.value?.term_averages ?? []).some((item) => item.average !== null),
)

function formatNumber(value: number | null | undefined, digits = 2): string {
  if (value === null || value === undefined || Number.isNaN(value)) return '—'
  return value.toFixed(digits)
}

function subjectTone(value: number | null): string {
  if (value === null) return 'muted'
  if (value >= 14) return 'success'
  if (value >= 10) return 'warning'
  return 'danger'
}

async function loadStudentAndYears(): Promise<void> {
  const [s, , timelineRes] = await Promise.all([
    api<ApiResource<Student>>(`/api/v1/students/${props.id}`),
    schoolYearStore.fetchAll(),
    api<{ data: StudentTimeline }>(`/api/v1/students/${props.id}/timeline`).catch(() => null),
  ])
  student.value = s.data
  timeline.value = timelineRes?.data ?? null
  if (s.data.classroom?.level?.cycle === 'cteb') {
    const yearId = schoolYearStore.effectiveId ?? schoolYears.value[0]?.id ?? null
    const yearTerms = yearId
      ? allTerms.value.filter(({ year }) => year.id === yearId)
      : allTerms.value
    selectedTerm.value = yearTerms[0]?.term.id ?? allTerms.value[0]?.term.id ?? null
    await loadCtebBulletin()
    return
  }

  if (isPrimaireDebutLevel(s.data.classroom?.level ?? null)) {
    const yearId = schoolYearStore.effectiveId ?? schoolYears.value[0]?.id ?? null
    const yearTerms = yearId
      ? allTerms.value.filter(({ year }) => year.id === yearId)
      : allTerms.value
    selectedTerm.value = yearTerms[0]?.term.id ?? allTerms.value[0]?.term.id ?? null
    await loadPrimaireBulletin()
    return
  }

  if (!selectedTerm.value && allTerms.value.length > 0) {
    selectedTerm.value = allTerms.value[0].term.id
    await loadReport()
  }
}

async function loadPrimaryRanking(termId: number): Promise<void> {
  primaryRank.value = null
  primaryClassSize.value = null
  if (!student.value?.classroom_id) return

  try {
    const res = await api<{ data: Array<{ rank: number; student_id: number }> }>(
      `/api/v1/classrooms/${student.value.classroom_id}/ranking/${termId}`,
    )
    primaryClassSize.value = res.data.length
    const row = res.data.find((item) => item.student_id === student.value?.id)
    primaryRank.value = row?.rank ?? null
  } catch {
    primaryRank.value = null
    primaryClassSize.value = null
  }
}

async function loadPrimaireBulletin(): Promise<void> {
  if (!student.value || primaryTrimesterTerms.value.length === 0) return
  loading.value = true
  error.value = ''
  report.value = null
  primaryTrimesterReports.value = [null, null, null]
  appreciation.value = ''
  appreciationMsg.value = ''

  try {
    const reports = await Promise.all(
      primaryTrimesterTerms.value.map(async ({ term }) => {
        const res = await api<{ data: ReportCardData & { appreciation?: string | null } }>(
          `/api/v1/students/${student.value!.id}/report-cards/${term.id}`,
        )
        return res.data
      }),
    )

    primaryTrimesterReports.value = [
      reports[0] ?? null,
      reports[1] ?? null,
      reports[2] ?? null,
    ]

    const lastTerm = primaryTrimesterTerms.value[primaryTrimesterTerms.value.length - 1]?.term
    if (lastTerm) {
      await loadPrimaryRanking(lastTerm.id)
    }

    appreciation.value = reports.find((item) => item?.appreciation)?.appreciation ?? ''
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Erreur de chargement du bulletin primaire.'
  } finally {
    loading.value = false
  }
}

async function loadCtebRanking(termId: number): Promise<void> {
  ctebRank.value = null
  ctebClassSize.value = null
  if (!student.value?.classroom_id) return

  try {
    const res = await api<{ data: Array<{ rank: number; student_id: number }> }>(
      `/api/v1/classrooms/${student.value.classroom_id}/ranking/${termId}`,
    )
    ctebClassSize.value = res.data.length
    const row = res.data.find((item) => item.student_id === student.value?.id)
    ctebRank.value = row?.rank ?? null
  } catch {
    ctebRank.value = null
    ctebClassSize.value = null
  }
}

async function loadCtebBulletin(): Promise<void> {
  if (!student.value || ctebSemesterTerms.value.length === 0) return
  loading.value = true
  error.value = ''
  report.value = null
  ctebSemesterReports.value = [null, null]
  appreciation.value = ''
  appreciationMsg.value = ''

  try {
    const reports = await Promise.all(
      ctebSemesterTerms.value.map(async ({ term }) => {
        const res = await api<{ data: ReportCardData & { appreciation?: string | null } }>(
          `/api/v1/students/${student.value!.id}/report-cards/${term.id}`,
        )
        return res.data
      }),
    )

    ctebSemesterReports.value = [
      reports[0] ?? null,
      reports[1] ?? null,
    ]

    const lastTerm = ctebSemesterTerms.value[ctebSemesterTerms.value.length - 1]?.term
    if (lastTerm) {
      await loadCtebRanking(lastTerm.id)
    }

    appreciation.value = reports.find((item) => item?.appreciation)?.appreciation ?? ''
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Erreur de chargement du bulletin CTEB.'
  } finally {
    loading.value = false
  }
}

async function loadReport(): Promise<void> {
  if (!student.value) return
  if (isCtebStudent.value) {
    await loadCtebBulletin()
    return
  }
  if (isPrimaireDebutStudent.value) {
    await loadPrimaireBulletin()
    return
  }

  if (!selectedTerm.value) return
  loading.value = true
  error.value = ''
  report.value = null
  appreciation.value = ''
  appreciationMsg.value = ''
  try {
    const res = await api<{ data: ReportCardData & { appreciation?: string | null } }>(
      `/api/v1/students/${student.value.id}/report-cards/${selectedTerm.value}`,
    )
    report.value = res.data
    appreciation.value = res.data.appreciation ?? ''
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Erreur de chargement.'
  } finally {
    loading.value = false
  }
}

const appreciationTermId = computed(() => {
  if (isCtebStudent.value) {
    return ctebSemesterTerms.value[ctebSemesterTerms.value.length - 1]?.term.id ?? null
  }
  if (isPrimaireDebutStudent.value) {
    return primaryTrimesterTerms.value[primaryTrimesterTerms.value.length - 1]?.term.id ?? null
  }
  return selectedTerm.value
})

async function saveAppreciation(): Promise<void> {
  if (!appreciationTermId.value || !student.value || !appreciation.value.trim()) return
  savingAppreciation.value = true
  appreciationMsg.value = ''
  try {
    await api(`/api/v1/students/${student.value.id}/appreciations/${appreciationTermId.value}`, {
      method: 'PUT',
      body: { content: appreciation.value },
    })
    appreciationMsg.value = 'Appréciation enregistrée.'
    setTimeout(() => (appreciationMsg.value = ''), 3000)
  } catch (e) {
    appreciationMsg.value = e instanceof ApiError ? e.message : 'Enregistrement impossible.'
  } finally {
    savingAppreciation.value = false
  }
}

function downloadPdf(): void {
  const termId = isCtebStudent.value
    ? ctebSemesterTerms.value[ctebSemesterTerms.value.length - 1]?.term.id
    : isPrimaireDebutStudent.value
      ? primaryTrimesterTerms.value[primaryTrimesterTerms.value.length - 1]?.term.id
      : selectedTerm.value
  if (!termId || !student.value) return
  const token = getToken()
  if (!token) return
  fetch(apiUrl(`/api/v1/students/${student.value.id}/report-cards/${termId}/pdf`), {
    headers: { Authorization: `Bearer ${token}` },
  })
    .then((r) => {
      if (!r.ok) throw new Error('Téléchargement impossible')
      return r.blob()
    })
    .then((blob) => {
      const url = URL.createObjectURL(blob)
      const a = document.createElement('a')
      a.href = url
      a.download = `bulletin-${student.value!.full_name}.pdf`
      a.click()
      URL.revokeObjectURL(url)
    })
    .catch((e) => (error.value = e.message))
}

watch(
  () => schoolYearStore.effectiveId,
  async () => {
    selectedTerm.value = null
    report.value = null
    ctebSemesterReports.value = [null, null]
    primaryTrimesterReports.value = [null, null, null]
    appreciation.value = ''
    appreciationMsg.value = ''
    await loadStudentAndYears()
  },
)

onMounted(loadStudentAndYears)
</script>

<template>
  <section class="bulletin-page">
    <div class="bulletin-toolbar" :class="{ 'is-embedded': props.embedded }">
      <RouterLink v-if="!props.embedded" class="back-link" :to="{ name: 'student-detail', params: { id: props.id } }">
        Retour à la fiche élève
      </RouterLink>

      <div class="toolbar-actions">
        <label v-if="isOfficialAnnualBulletin" class="term-picker">
          <span>Année scolaire</span>
          <select
            :value="(isCtebStudent ? ctebSchoolYear : primarySchoolYear)?.id ?? ''"
            @change="(e) => {
              const yearId = Number((e.target as HTMLSelectElement).value)
              const firstTerm = allTerms.find(({ year }) => year.id === yearId)?.term.id ?? null
              selectedTerm = firstTerm
              if (isCtebStudent) loadCtebBulletin()
              else loadPrimaireBulletin()
            }"
          >
            <option v-for="year in schoolYears" :key="year.id" :value="year.id">
              {{ year.name }}
            </option>
          </select>
        </label>
        <label v-else class="term-picker">
          <span>Trimestre</span>
          <select v-model.number="selectedTerm" @change="loadReport">
            <option :value="null" disabled>Choisir un trimestre</option>
            <option v-for="t in allTerms" :key="t.term.id" :value="t.term.id">
              {{ t.term.name }} - {{ t.year.name }}
            </option>
          </select>
        </label>
        <button
          v-if="report || (isCtebStudent && ctebSemesterReports.some(Boolean)) || (isPrimaireDebutStudent && primaryTrimesterReports.some(Boolean))"
          type="button"
          class="btn-primary"
          @click="downloadPdf"
        >
          Télécharger PDF
        </button>
      </div>
    </div>

    <p v-if="error" class="alert alert-error">{{ error }}</p>
    <div v-if="loading" class="empty-state">Calcul du bulletin...</div>
    <div v-else-if="student && !report && !(isCtebStudent && ctebSemesterReports.some(Boolean)) && !(isPrimaireDebutStudent && primaryTrimesterReports.some(Boolean))" class="empty-state">
      {{ isOfficialAnnualBulletin ? 'Sélectionnez une année scolaire pour afficher le bulletin officiel.' : 'Sélectionnez un trimestre pour afficher le bulletin.' }}
    </div>

    <CtebBulletinSheet
      v-if="student && isCtebStudent && ctebSemesterReports.some(Boolean)"
      :student="student"
      :school-year-name="ctebSchoolYear?.name ?? '—'"
      :semester-reports="ctebSemesterReports"
      :rank="ctebRank"
      :class-size="ctebClassSize"
      :appreciation="appreciation"
    />

    <PrimaireDebutBulletinSheet
      v-if="student && isPrimaireDebutStudent && primaryTrimesterReports.some(Boolean)"
      :student="student"
      :school-year-name="primarySchoolYear?.name ?? '—'"
      :trimester-reports="primaryTrimesterReports"
      :rank="primaryRank"
      :class-size="primaryClassSize"
      :appreciation="appreciation"
    />

    <template v-if="student && report && !isOfficialAnnualBulletin">
      <article class="report-view">
        <section class="student-band">
          <div>
            <span>Élève</span>
            <strong>{{ student.full_name }}</strong>
          </div>
          <div>
            <span>Matricule</span>
            <strong>{{ student.registration_number ?? '—' }}</strong>
          </div>
          <div>
            <span>Classe</span>
            <strong>{{ student.classroom?.full_name ?? '—' }}</strong>
          </div>
          <div>
            <span>Trimestre</span>
            <strong>{{ report.term.name }}</strong>
          </div>
          <div>
            <span>Année scolaire</span>
            <strong>{{ selectedTermOption?.year.name ?? '—' }}</strong>
          </div>
        </section>

        <section class="report-summary">
          <div class="average-block" :class="`tone-${averageTone}`">
            <span>Moyenne générale</span>
            <strong>{{ formatAveragePercent(report.overall_average, 1) }}</strong>
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
              <span>Décision</span>
              <strong>{{ decisionLabel }}</strong>
            </div>
          </div>
          <div v-if="hasMiniAverage" class="mini-trend">
            <span>Évolution</span>
            <MiniChart :series="miniAverageSeries" :y-max="100" value-suffix="%" />
          </div>
        </section>

        <section v-if="report.period_averages?.length" class="grades-section">
          <div class="section-title">
            <h2>Moyennes des périodes</h2>
            <span>Trimestre = moyenne des deux périodes</span>
          </div>

          <div class="report-table-wrap compact">
            <table class="report-table">
              <thead>
                <tr>
                  <th>Période</th>
                  <th class="num">Moyenne</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="period in report.period_averages" :key="period.period_id">
                  <td>{{ period.name }}</td>
                  <td class="num">{{ formatAveragePercent(period.average, 1) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

        <section class="grades-section">
          <div class="section-title">
            <h2>Relevé des notes</h2>
            <span>Moyennes en pourcentage</span>
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
                <tr v-for="(row, index) in report.subjects" :key="row.subject_id">
                  <td class="rank-col">{{ index + 1 }}</td>
                  <td class="subject-name">{{ row.subject_name }}</td>
                  <td class="num">{{ formatNumber(row.coefficient) }}</td>
                  <td class="num">{{ row.count }}</td>
                  <td class="num">
                    <span class="score-pill" :class="`tone-${subjectTone(row.average)}`">
                      {{ formatAveragePercent(row.average, 1) }}
                    </span>
                  </td>
                  <td class="num">
                    {{ row.average === null ? '—' : formatNumber(row.average * row.coefficient) }}
                  </td>
                  <td>{{ row.average === null ? 'Non évalué' : row.average >= 10 ? 'Acquis' : 'À renforcer' }}</td>
                </tr>
                <tr v-if="report.subjects.length === 0">
                  <td colspan="7" class="empty-row">Aucun cours n'est attaché à la classe de cet élève.</td>
                </tr>
              </tbody>
              <tfoot v-if="report.subjects.length > 0">
                <tr>
                  <td colspan="2">Totaux</td>
                  <td class="num">{{ formatNumber(report.total_coefficient) }}</td>
                  <td class="num">{{ report.subjects.reduce((total, row) => total + row.count, 0) }}</td>
                  <td class="num">{{ formatAveragePercent(report.overall_average, 1) }}</td>
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
          <div class="signature-grid">
            <span>Titulaire</span>
            <span>Direction</span>
            <span>Parent / Tuteur</span>
          </div>
        </section>
      </article>

    </template>

    <section
      v-if="student && (report || (isCtebStudent && ctebSemesterReports.some(Boolean)) || (isPrimaireDebutStudent && primaryTrimesterReports.some(Boolean)))"
      class="appreciation-editor"
    >
      <div class="editor-heading">
        <div>
          <h2>Appréciation</h2>
          <p>Cette mention apparaît sur le bulletin et dans le PDF.</p>
        </div>
      </div>
      <div class="editor-body">
        <textarea
          v-model="appreciation"
          rows="4"
          maxlength="2000"
          placeholder="Saisir l'appréciation pour ce trimestre..."
        />
        <div class="editor-actions">
          <button
            type="button"
            class="btn-primary"
            :disabled="savingAppreciation || !appreciation.trim()"
            @click="saveAppreciation"
          >
            {{ savingAppreciation ? 'Enregistrement...' : "Enregistrer l'appréciation" }}
          </button>
          <span v-if="appreciationMsg">{{ appreciationMsg }}</span>
        </div>
      </div>
    </section>
  </section>
</template>

<style scoped>
.bulletin-page {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.bulletin-page :deep(.cteb-sheet) {
  box-shadow: 0 12px 40px rgb(15 23 42 / 0.08);
}

@media print {
  .bulletin-toolbar,
  .appreciation-editor {
    display: none !important;
  }

  .bulletin-page :deep(.cteb-sheet) {
    box-shadow: none;
    border-width: 1px;
  }
}

.bulletin-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
}

.bulletin-toolbar.is-embedded {
  justify-content: flex-end;
}

.back-link {
  min-height: 2.25rem;
  display: inline-flex;
  align-items: center;
  color: var(--text-soft);
  font-weight: 800;
}

.back-link::before {
  content: '←';
  margin-right: 0.45rem;
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

.term-picker span {
  color: var(--text-soft);
  font-size: 0.74rem;
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

.student-band span,
.summary-grid span,
.average-block span {
  color: var(--text-soft);
  font-size: 0.72rem;
  font-weight: 850;
  text-transform: uppercase;
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

.average-block {
  display: grid;
  gap: 0.35rem;
  align-content: center;
  padding: 1rem;
  border: 1px solid var(--border);
  border-radius: 8px;
  background: #f8fafc;
}

.average-block strong {
  display: flex;
  align-items: baseline;
  gap: 0.25rem;
  color: var(--text);
  font-size: 2.2rem;
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
  border-color: #bbf7d0;
  background: #f0fdf4;
}

.average-block.tone-success strong {
  color: var(--success);
}

.average-block.tone-warning {
  border-color: #fed7aa;
  background: #fff7ed;
}

.average-block.tone-warning strong {
  color: var(--warn);
}

.average-block.tone-danger {
  border-color: #fecdd3;
  background: #fff1f2;
}

.average-block.tone-danger strong {
  color: var(--danger);
}

.summary-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.65rem;
}

.summary-grid div {
  display: grid;
  align-content: center;
  gap: 0.2rem;
  padding: 0.75rem;
  border: 1px solid var(--border);
  border-radius: 8px;
  background: var(--bg-subtle);
}

.summary-grid strong {
  color: var(--text);
  font-size: 0.95rem;
}

.mini-trend {
  display: grid;
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
.appreciation-preview h2,
.appreciation-editor h2 {
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
  background: #f8fafc;
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

.num {
  text-align: right;
}

.score-pill {
  min-width: 4.2rem;
  display: inline-flex;
  justify-content: center;
  padding: 0.18rem 0.45rem;
  border-radius: 999px;
  background: #f2f4f7;
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

.empty-row {
  padding: 1.2rem;
  color: var(--text-soft);
  text-align: center;
}

.appreciation-preview {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(15rem, 0.45fr);
  gap: 1rem;
  padding-top: 0.3rem;
}

.appreciation-preview > div:first-child {
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

.signature-grid {
  display: grid;
  grid-template-columns: 1fr;
  gap: 0.65rem;
}

.signature-grid span {
  min-height: 3.15rem;
  display: flex;
  align-items: end;
  justify-content: center;
  padding-bottom: 0.35rem;
  border: 1px solid var(--border);
  border-radius: 8px;
  color: var(--text-soft);
  font-size: 0.78rem;
  font-weight: 850;
}

.appreciation-editor {
  display: grid;
  gap: 0.75rem;
  padding-top: 1rem;
  border-top: 1px solid var(--border);
}

.editor-heading {
  display: grid;
  gap: 0.12rem;
}

.editor-heading p {
  margin: 0;
  color: var(--text-soft);
  font-size: 0.86rem;
}

.editor-body {
  display: grid;
  gap: 0.75rem;
}

.editor-actions {
  display: flex;
  align-items: center;
  gap: 0.65rem;
  flex-wrap: wrap;
}

.editor-actions span {
  color: var(--success);
  font-size: 0.88rem;
  font-weight: 800;
}

@media (max-width: 900px) {
  .report-summary,
  .appreciation-preview {
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
  .bulletin-toolbar,
  .toolbar-actions {
    align-items: stretch;
    flex-direction: column;
  }

  .term-picker select,
  .toolbar-actions button {
    width: 100%;
  }

  .report-view {
    gap: 0.85rem;
  }
}
</style>
