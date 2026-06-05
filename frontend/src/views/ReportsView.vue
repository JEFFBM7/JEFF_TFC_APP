<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { api, apiUrl, ApiError } from '../api/client'
import type { ClassRoom, Paginated, SchoolYear, Student, Term } from '../types'
import { useSchoolYearStore } from '../stores/schoolYear'

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
  <section>
    <h1 style="margin: 0 0 0.25rem">Rapports &amp; Exports</h1>
    <p class="text-soft" style="margin: 0 0 1.25rem; font-size: 0.93rem">
      Téléchargez des exports CSV pour le pilotage pédagogique (CDC §4.7).
    </p>

    <p v-if="error" class="alert alert-error">{{ error }}</p>

    <!-- Classement par classe -->
    <div class="card" style="margin-bottom: 1rem">
      <div class="card-header"><h2 style="margin: 0">Classement par moyenne</h2></div>
      <div class="row">
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
        <button type="button" class="btn-primary" :disabled="!rankClassroom || !rankTerm" @click="exportRanking">
          Exporter CSV
        </button>
      </div>
    </div>

    <!-- Absentéisme par classe -->
    <div class="card" style="margin-bottom: 1rem">
      <div class="card-header"><h2 style="margin: 0">Taux d'absentéisme par classe</h2></div>
      <div class="row">
        <label>
          <span>Du</span>
          <input v-model="attFrom" type="date" />
        </label>
        <label>
          <span>Au</span>
          <input v-model="attTo" type="date" />
        </label>
        <button type="button" class="btn-primary" @click="exportAttendance">Exporter CSV</button>
      </div>
    </div>

    <!-- Évolution d'un élève -->
    <div class="card">
      <div class="card-header"><h2 style="margin: 0">Évolution d'un élève (multi-trimestres)</h2></div>
      <div class="row">
        <label>
          <span>Élève</span>
          <select v-model.number="evolStudent">
            <option value="">— Choisir —</option>
            <option v-for="s in students" :key="s.id" :value="s.id">{{ s.full_name }}</option>
          </select>
        </label>
        <label>
          <span>Année (optionnel)</span>
          <select v-model.number="evolYear">
            <option value="">Contexte actuel</option>
            <option v-for="y in years" :key="y.id" :value="y.id">{{ y.name }}</option>
          </select>
        </label>
        <button type="button" class="btn-primary" :disabled="!evolStudent" @click="exportEvolution">
          Exporter CSV
        </button>
      </div>
    </div>
  </section>
</template>

<style scoped>
.text-soft { color: var(--text-soft); }
.row {
  padding: 1rem;
  display: flex;
  gap: 1rem;
  align-items: flex-end;
  flex-wrap: wrap;
}
.row label {
  display: flex;
  flex-direction: column;
  gap: 0.3rem;
  font-size: 0.88rem;
}
.row label span { color: var(--text-soft); }
</style>
