<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { api, apiUrl, ApiError } from '../api/client'
import type { ClassRoom, Paginated, SchoolYear, Student, Term } from '../types'
import { useSchoolYearStore } from '../stores/schoolYear'
import { Trophy, CalendarX2, TrendingUp, Download, Info, ShieldCheck, Clock } from 'lucide-vue-next'

const schoolYearStore = useSchoolYearStore()
const classrooms = ref<ClassRoom[]>([])
const students = ref<Student[]>([])

const error = ref('')

// Sélections
const rankClassroom = ref<number | ''>('')
const rankTerm = ref<number | ''>('')

const attFrom = ref('')
const attTo = ref('')

const evolStudent = ref<number | ''>('')
const evolYear = ref<number | ''>('')

const years = computed<SchoolYear[]>(() =>
  schoolYearStore.years.length > 0
    ? schoolYearStore.years
    : schoolYearStore.current ? [schoolYearStore.current] : [],
)

const terms = computed<Term[]>(() =>
  years.value
    .flatMap((year) => year.terms ?? [])
    .filter((term) => schoolYearStore.effectiveId === null || term.school_year_id === schoolYearStore.effectiveId),
)

async function loadRefs(): Promise<void> {
  try {
    const [c, s] = await Promise.all([
      api<Paginated<ClassRoom>>('/api/v1/classrooms'),
      api<Paginated<Student>>('/api/v1/students'),
      schoolYearStore.fetchAll(),
    ])
    classrooms.value = c.data
    students.value = s.data
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Chargement impossible.'
  }
}

async function downloadCsv(url: string, filename: string): Promise<void> {
  try {
    const token = sessionStorage.getItem('educonnect_token')
    const res = await fetch(apiUrl(url), {
      headers: { Authorization: `Bearer ${token}` },
    })
    if (!res.ok) throw new Error('Téléchargement impossible.')
    const blob = await res.blob()
    const a = document.createElement('a')
    a.href = URL.createObjectURL(blob)
    a.download = filename
    a.click()
    URL.revokeObjectURL(a.href)
  } catch {
    error.value = 'Téléchargement impossible.'
  }
}

function exportRanking(): void {
  if (!rankClassroom.value || !rankTerm.value) return
  const cls = classrooms.value.find((c) => c.id === rankClassroom.value)
  const trm = terms.value.find((t) => t.id === rankTerm.value)
  void downloadCsv(
    `/api/v1/reports/classrooms/${rankClassroom.value}/ranking/${rankTerm.value}/csv`,
    `classement-${cls?.full_name ?? 'classe'}-${trm?.name ?? 'trim'}.csv`,
  )
}

function exportAttendance(): void {
  const params = new URLSearchParams()
  if (attFrom.value) params.set('from', attFrom.value)
  if (attTo.value) params.set('to', attTo.value)
  const qs = params.toString()
  void downloadCsv(
    `/api/v1/reports/attendance/csv${qs ? '?' + qs : ''}`,
    `absenteisme.csv`,
  )
}

function exportEvolution(): void {
  if (!evolStudent.value) return
  const url = evolYear.value
    ? `/api/v1/reports/students/${evolStudent.value}/evolution/csv?school_year_id=${evolYear.value}`
    : `/api/v1/reports/students/${evolStudent.value}/evolution/csv`
  const s = students.value.find((st) => st.id === evolStudent.value)
  void downloadCsv(url, `evolution-${s?.full_name ?? 'eleve'}.csv`)
}

watch(
  () => schoolYearStore.effectiveId,
  async () => {
    rankClassroom.value = ''
    rankTerm.value = ''
    evolStudent.value = ''
    evolYear.value = ''
    await loadRefs()
  },
)

onMounted(loadRefs)
</script>

<template>
  <section class="reports-page">
    <header class="reports-head">
      <h1>Rapports &amp; exports</h1>
      <p class="reports-sub">Téléchargez des exports CSV pour le pilotage pédagogique.</p>
    </header>

    <p v-if="error" class="alert alert-error">{{ error }}</p>

    <!-- Grille 3 colonnes -->
    <div class="reports-grid">
      <!-- Classement par classe -->
      <article class="report-card">
        <div class="report-card-top">
          <div class="report-icon"><Trophy :size="20" aria-hidden="true" /></div>
          <div class="report-text">
            <h2>Classement par moyenne</h2>
            <p>Palmarès d'une classe sur un trimestre : élève, moyenne et rang.</p>
          </div>
        </div>
        <div class="report-controls">
          <label>
            <span>Classe</span>
            <select v-model.number="rankClassroom">
              <option value="">— Choisir —</option>
              <option v-for="c in classrooms" :key="c.id" :value="c.id">{{ c.full_name }}</option>
            </select>
          </label>
          <label>
            <span>Trimestre</span>
            <select v-model.number="rankTerm">
              <option value="">— Choisir —</option>
              <option v-for="t in terms" :key="t.id" :value="t.id">{{ t.name }}</option>
            </select>
          </label>
        </div>
        <button type="button" class="btn-primary report-export" :disabled="!rankClassroom || !rankTerm" @click="exportRanking">
          <Download :size="16" aria-hidden="true" /> Exporter CSV
        </button>
      </article>

      <!-- Absentéisme -->
      <article class="report-card">
        <div class="report-card-top">
          <div class="report-icon"><CalendarX2 :size="20" aria-hidden="true" /></div>
          <div class="report-text">
            <h2>Taux d'absentéisme</h2>
            <p>Absences par classe sur une période. Laissez les dates vides pour tout l'historique.</p>
          </div>
        </div>
        <div class="report-controls">
          <label>
            <span>Du</span>
            <input v-model="attFrom" type="date" />
          </label>
          <label>
            <span>Au</span>
            <input v-model="attTo" type="date" />
          </label>
        </div>
        <button type="button" class="btn-primary report-export" @click="exportAttendance">
          <Download :size="16" aria-hidden="true" /> Exporter CSV
        </button>
      </article>

      <!-- Évolution d'un élève -->
      <article class="report-card">
        <div class="report-card-top">
          <div class="report-icon"><TrendingUp :size="20" aria-hidden="true" /></div>
          <div class="report-text">
            <h2>Évolution d'un élève</h2>
            <p>Suivi des moyennes d'un élève trimestre par trimestre.</p>
          </div>
        </div>
        <div class="report-controls">
          <label>
            <span>Élève</span>
            <select v-model.number="evolStudent">
              <option value="">— Choisir —</option>
              <option v-for="s in students" :key="s.id" :value="s.id">{{ s.full_name }}</option>
            </select>
          </label>
          <label>
            <span>Année <small>(optionnel)</small></span>
            <select v-model.number="evolYear">
              <option value="">Contexte actuel</option>
              <option v-for="y in years" :key="y.id" :value="y.id">{{ y.name }}</option>
            </select>
          </label>
        </div>
        <button type="button" class="btn-primary report-export" :disabled="!evolStudent" @click="exportEvolution">
          <Download :size="16" aria-hidden="true" /> Exporter CSV
        </button>
      </article>
    </div>

    <!-- Bandeau info -->
    <div class="reports-info">
      <div class="info-item">
        <Info :size="16" aria-hidden="true" />
        <div>
          <strong>À propos des exports</strong>
          <p>Fichiers CSV (séparateur point-virgule, encodage UTF-8) compatibles Excel, Google Sheets et LibreOffice.</p>
        </div>
      </div>
      <div class="info-item">
        <ShieldCheck :size="16" aria-hidden="true" />
        <div>
          <strong>Données sécurisées</strong>
          <p>Seuls les utilisateurs autorisés peuvent accéder aux exports.</p>
        </div>
      </div>
      <div class="info-item">
        <Clock :size="16" aria-hidden="true" />
        <div>
          <strong>Historique conservé</strong>
          <p>Les exports couvrent l'historique disponible en base.</p>
        </div>
      </div>
    </div>
  </section>
</template>

<style scoped>
.reports-page {
  display: flex;
  flex-direction: column;
  gap: 1.5rem;
}

.reports-head h1 { margin: 0 0 0.2rem; }
.reports-sub { margin: 0; color: var(--text-soft); font-size: 0.92rem; }

/* ── Grille 3 colonnes ── */
.reports-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 1rem;
}

.report-card {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  padding: 1.25rem;
  background: var(--bg-card);
  border: 1px solid var(--border);
  border-radius: 14px;
  box-shadow: var(--shadow-card);
}

.report-card-top {
  display: flex;
  gap: 0.85rem;
  align-items: flex-start;
}

.report-icon {
  display: grid;
  place-items: center;
  width: 2.4rem;
  height: 2.4rem;
  flex-shrink: 0;
  border-radius: 10px;
  background: var(--gold-soft);
  color: var(--gold);
}

.report-text h2 { margin: 0; font-size: 1rem; line-height: 1.3; }
.report-text p  { margin: 0.3rem 0 0; color: var(--text-soft); font-size: 0.83rem; line-height: 1.45; }

/* Filtres empilés (1 par ligne dans chaque carte colonne) */
.report-controls {
  display: flex;
  flex-direction: column;
  gap: 0.7rem;
  flex: 1;
}

.report-controls label {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  font-size: 0.84rem;
  margin: 0;
}

.report-controls label span { color: var(--text-soft); font-weight: 650; }
.report-controls small { color: var(--text-muted); font-weight: 400; }

.report-export {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0.4rem;
  width: 100%;
  margin-top: auto;
}

/* ── Bandeau info ── */
.reports-info {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 1rem;
  padding: 1rem 1.25rem;
  background: var(--bg-card);
  border: 1px solid var(--border);
  border-radius: 14px;
}

.info-item {
  display: flex;
  gap: 0.65rem;
  align-items: flex-start;
  color: var(--text-muted);
}

.info-item svg { margin-top: 0.15rem; flex-shrink: 0; }

.info-item strong {
  display: block;
  font-size: 0.85rem;
  font-weight: 700;
  color: var(--text-soft);
  margin-bottom: 0.2rem;
}

.info-item p {
  margin: 0;
  font-size: 0.8rem;
  line-height: 1.45;
}

/* ── Responsive ── */
@media (max-width: 860px) {
  .reports-grid  { grid-template-columns: 1fr; }
  .reports-info  { grid-template-columns: 1fr; }
}
</style>
