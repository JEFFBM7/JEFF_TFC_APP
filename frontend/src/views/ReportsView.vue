<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { api, apiUrl, ApiError } from '../api/client'
import type { ClassRoom, Paginated, SchoolYear, Student, Term } from '../types'
import { useSchoolYearStore } from '../stores/schoolYear'
import { Trophy, CalendarX2, TrendingUp, Download } from 'lucide-vue-next'

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

    <div class="reports-list">
      <!-- Classement par classe -->
      <article class="report-card">
        <div class="report-icon"><Trophy :size="20" aria-hidden="true" /></div>
        <div class="report-main">
          <div class="report-text">
            <h2>Classement par moyenne</h2>
            <p>Palmarès d'une classe sur un trimestre : élève, moyenne et rang.</p>
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
            <button type="button" class="btn-primary report-export" :disabled="!rankClassroom || !rankTerm" @click="exportRanking">
              <Download :size="16" aria-hidden="true" /> Exporter CSV
            </button>
          </div>
        </div>
      </article>

      <!-- Absentéisme par classe -->
      <article class="report-card">
        <div class="report-icon"><CalendarX2 :size="20" aria-hidden="true" /></div>
        <div class="report-main">
          <div class="report-text">
            <h2>Taux d'absentéisme</h2>
            <p>Absences par classe sur une période. Laissez les dates vides pour tout l'historique.</p>
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
            <button type="button" class="btn-primary report-export" @click="exportAttendance">
              <Download :size="16" aria-hidden="true" /> Exporter CSV
            </button>
          </div>
        </div>
      </article>

      <!-- Évolution d'un élève -->
      <article class="report-card">
        <div class="report-icon"><TrendingUp :size="20" aria-hidden="true" /></div>
        <div class="report-main">
          <div class="report-text">
            <h2>Évolution d'un élève</h2>
            <p>Suivi des moyennes d'un élève trimestre par trimestre.</p>
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
            <button type="button" class="btn-primary report-export" :disabled="!evolStudent" @click="exportEvolution">
              <Download :size="16" aria-hidden="true" /> Exporter CSV
            </button>
          </div>
        </div>
      </article>
    </div>
  </section>
</template>

<style scoped>
.reports-page {
  display: flex;
  flex-direction: column;
  gap: 1.25rem;
  max-width: 860px;
}

.reports-head h1 { margin: 0 0 0.2rem; }
.reports-sub { margin: 0; color: var(--text-soft); font-size: 0.92rem; }

.reports-list {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

/* Carte rapport : icône (accent or) + texte + filtres alignés */
.report-card {
  display: flex;
  gap: 1rem;
  padding: 1.15rem 1.25rem;
  background: var(--bg-card);
  border: 1px solid var(--border);
  border-radius: 14px;
  box-shadow: var(--shadow-card);
}

.report-icon {
  display: grid;
  place-items: center;
  width: 2.6rem;
  height: 2.6rem;
  flex-shrink: 0;
  border-radius: 10px;
  background: var(--gold-soft);
  color: var(--gold);
}

.report-main {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 0.85rem;
}

.report-text h2 { margin: 0; font-size: 1.05rem; }
.report-text p { margin: 0.2rem 0 0; color: var(--text-soft); font-size: 0.86rem; }
.report-text small { color: var(--text-muted); font-weight: 400; }

/* Grille : 2 filtres qui remplissent la largeur + bouton calé au bout (pas de vide) */
.report-controls {
  display: grid;
  grid-template-columns: 1fr 1fr auto;
  gap: 0.85rem;
  align-items: end;
}

.report-controls label {
  display: flex;
  flex-direction: column;
  gap: 0.3rem;
  font-size: 0.84rem;
  margin: 0;
  min-width: 0;
}

.report-controls label span { color: var(--text-soft); font-weight: 650; }

.report-export {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  white-space: nowrap;
}

@media (max-width: 560px) {
  .report-card { flex-direction: column; }
  .report-controls { grid-template-columns: 1fr; }
  .report-export { width: 100%; justify-content: center; }
}
</style>
