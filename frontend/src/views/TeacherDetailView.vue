<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { api, ApiError } from '../api/client'
import type { ApiResource, Assignment, Paginated, Teacher } from '../types'
import { useSchoolYearStore } from '../stores/schoolYear'

const props = defineProps<{ id: string | number }>()

interface TimetableSlot {
  id: number
  classroom_id: number
  subject_id: number
  teacher_id: number
  school_year_id: number
  day_of_week: number
  starts_at: string
  ends_at: string
  room: string | null
  subject?: { id: number; name: string }
  teacher?: { id: number; name: string }
  classroom?: { id: number; full_name: string }
}

const DAYS = [
  { id: 1, label: 'Lundi' },
  { id: 2, label: 'Mardi' },
  { id: 3, label: 'Mercredi' },
  { id: 4, label: 'Jeudi' },
  { id: 5, label: 'Vendredi' },
  { id: 6, label: 'Samedi' },
]

const schoolYearStore = useSchoolYearStore()
const teacher = ref<Teacher | null>(null)
const assignments = ref<Assignment[]>([])
const timetableSlots = ref<TimetableSlot[]>([])
const loading = ref(false)
const error = ref('')

const selectedSchoolYear = computed(() =>
  schoolYearStore.selected
    ?? schoolYearStore.years.find((year) => year.id === schoolYearStore.effectiveId)
    ?? schoolYearStore.current
    ?? null,
)

const teacherName = computed(() => teacher.value?.user?.name ?? 'Enseignant')
const teacherInitials = computed(() => {
  const parts = teacherName.value.split(/\s+/).filter(Boolean)
  return (parts[0]?.charAt(0) ?? 'E') + (parts[1]?.charAt(0) ?? 'N')
})

const sortedAssignments = computed(() =>
  [...assignments.value].sort((a, b) => {
    if ((a.is_main ? 1 : 0) !== (b.is_main ? 1 : 0)) return a.is_main ? -1 : 1
    return (a.classroom?.full_name ?? '').localeCompare(b.classroom?.full_name ?? '')
  }),
)

const principalAssignments = computed(() => assignments.value.filter((assignment) => assignment.is_main))

const uniqueClassCount = computed(() => new Set(assignments.value.map((assignment) => assignment.classroom_id)).size)

const uniqueSubjectCount = computed(() => {
  const subjectIds = assignments.value
    .map((assignment) => assignment.subject_id)
    .filter((id): id is number => id !== null && id !== undefined)
  return new Set(subjectIds).size
})

const totalWeeklyHours = computed(() =>
  assignments.value.reduce((total, assignment) => total + (Number(assignment.weekly_hours) || 0), 0),
)

const slotsByDay = computed<Record<number, TimetableSlot[]>>(() => {
  const grouped: Record<number, TimetableSlot[]> = {}
  for (const day of DAYS) grouped[day.id] = []
  for (const slot of timetableSlots.value) {
    if (grouped[slot.day_of_week]) grouped[slot.day_of_week].push(slot)
  }
  for (const day of DAYS) {
    grouped[day.id].sort((a, b) => a.starts_at.localeCompare(b.starts_at))
  }
  return grouped
})

function displayValue(value?: string | number | null): string {
  return value === undefined || value === null || String(value).trim() === '' ? '—' : String(value)
}

function formatHours(value?: number | string | null): string {
  if (value === undefined || value === null || Number(value) === 0) return '—'
  return `${Number(value).toLocaleString('fr-FR', { maximumFractionDigits: 2 })} h`
}

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    await schoolYearStore.fetchAll()
    const [teacherRes, assignmentRes, timetableRes] = await Promise.all([
      api<ApiResource<Teacher>>(`/api/v1/teachers/${props.id}`),
      api<Paginated<Assignment>>('/api/v1/assignments', {
        query: { teacher_id: Number(props.id) },
      }),
      api<Paginated<TimetableSlot>>('/api/v1/timetable-slots', {
        query: { teacher_id: Number(props.id) },
      }),
    ])

    teacher.value = teacherRes.data
    assignments.value = assignmentRes.data
    timetableSlots.value = timetableRes.data
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Chargement impossible.'
  } finally {
    loading.value = false
  }
}

watch(
  () => schoolYearStore.effectiveId,
  () => {
    void load()
  },
)

onMounted(load)
</script>

<template>
  <section class="teacher-detail-page">
    <div class="teacher-topbar">
      <RouterLink class="back-link" :to="{ name: 'teachers' }">Retour aux enseignants</RouterLink>
      <button type="button" class="btn-secondary" :disabled="loading" @click="load">
        {{ loading ? 'Actualisation...' : 'Actualiser' }}
      </button>
    </div>

    <p v-if="error" class="alert alert-error">{{ error }}</p>
    <div v-if="loading && !teacher" class="empty-state">Chargement de la fiche enseignant...</div>

    <template v-if="teacher">
      <section class="teacher-profile">
        <div class="teacher-avatar">{{ teacherInitials.toUpperCase() }}</div>
        <div class="teacher-heading">
          <p class="section-kicker">Fiche enseignant</p>
          <h1>{{ teacherName }}</h1>
          <div class="teacher-meta">
            <code v-if="teacher.registration_number">{{ teacher.registration_number }}</code>
            <span>{{ displayValue(teacher.user?.email) }}</span>
          </div>
        </div>
        <div class="profile-stats">
          <div>
            <span>Année</span>
            <strong>{{ selectedSchoolYear?.name ?? '—' }}</strong>
          </div>
          <div>
            <span>Classes</span>
            <strong>{{ uniqueClassCount }}</strong>
          </div>
          <div>
            <span>Cours</span>
            <strong>{{ uniqueSubjectCount }}</strong>
          </div>
          <div>
            <span>Charge</span>
            <strong>{{ totalWeeklyHours ? formatHours(totalWeeklyHours) : '—' }}</strong>
          </div>
        </div>
      </section>

      <div class="teacher-layout">
        <main class="teacher-main">
          <section class="detail-panel">
            <div class="panel-heading">
              <div>
                <p class="section-kicker">Affectations</p>
                <h2>Classes et cours</h2>
              </div>
              <span>{{ assignments.length }} affectation(s)</span>
            </div>

            <div v-if="assignments.length === 0" class="empty-state compact">
              <template v-if="teacher.teacher_type === 'primaire'">
                Aucune classe titulaire pour cette année. Depuis
                <RouterLink :to="{ name: 'teachers' }">Enseignants</RouterLink>,
                menu <strong>⋮</strong> → <strong>Assigner une classe</strong> (tous les cours de la division).
              </template>
              <template v-else>
                Aucune affectation pour l'année scolaire sélectionnée.
              </template>
            </div>
            <div v-else class="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>Classe</th>
                    <th>Cours</th>
                    <th>Trimestre</th>
                    <th>Charge</th>
                    <th>Rôle</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="assignment in sortedAssignments" :key="assignment.id">
                    <td>
                      <RouterLink
                        v-if="selectedSchoolYear"
                        :to="{
                          name: 'school-year-class-detail',
                          params: { id: selectedSchoolYear.id, classroomId: assignment.classroom_id },
                        }"
                      >
                        {{ assignment.classroom?.full_name ?? '—' }}
                      </RouterLink>
                      <span v-else>{{ assignment.classroom?.full_name ?? '—' }}</span>
                    </td>
                    <td>{{ assignment.subject?.name ?? '—' }}</td>
                    <td>{{ assignment.term?.name ?? 'Toute l’année' }}</td>
                    <td>{{ formatHours(assignment.weekly_hours) }}</td>
                    <td>
                      <span class="badge" :class="assignment.is_main ? 'badge-success' : 'badge-muted'">
                        {{ assignment.is_main ? 'Titulaire' : 'Intervenant' }}
                      </span>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </section>

          <section class="detail-panel">
            <div class="panel-heading">
              <div>
                <p class="section-kicker">Planning</p>
                <h2>Emploi du temps</h2>
              </div>
              <span>{{ timetableSlots.length }} créneau(x)</span>
            </div>

            <div v-if="timetableSlots.length === 0" class="empty-state compact">
              Aucun créneau planifié pour cet enseignant.
            </div>
            <div v-else class="teacher-timetable">
              <article v-for="day in DAYS" :key="day.id" class="day-column">
                <h3>{{ day.label }}</h3>
                <p v-if="slotsByDay[day.id].length === 0" class="day-empty">—</p>
                <div v-else class="slot-list">
                  <div v-for="slot in slotsByDay[day.id]" :key="slot.id" class="slot-row">
                    <span>{{ slot.starts_at }} - {{ slot.ends_at }}</span>
                    <strong>{{ slot.subject?.name ?? 'Cours' }}</strong>
                    <small>
                      {{ slot.classroom?.full_name ?? 'Classe non renseignée' }}
                      <template v-if="slot.room"> · {{ slot.room }}</template>
                    </small>
                  </div>
                </div>
              </article>
            </div>
          </section>
        </main>

        <aside class="teacher-side">
          <section class="side-panel">
            <p class="section-kicker">Coordonnées</p>
            <dl class="detail-list">
              <div>
                <dt>Matricule</dt>
                <dd>
                  <code v-if="teacher.registration_number">{{ teacher.registration_number }}</code>
                  <span v-else>—</span>
                </dd>
              </div>
              <div>
                <dt>Nom</dt>
                <dd>{{ teacherName }}</dd>
              </div>
              <div>
                <dt>Email</dt>
                <dd>{{ displayValue(teacher.user?.email) }}</dd>
              </div>
              <div>
                <dt>Téléphone</dt>
                <dd>{{ displayValue(teacher.phone) }}</dd>
              </div>
              <div>
                <dt>Spécialité</dt>
                <dd>{{ displayValue(teacher.speciality) }}</dd>
              </div>
            </dl>
          </section>

          <section class="side-panel">
            <p class="section-kicker">Titulariat</p>
            <div v-if="principalAssignments.length === 0" class="side-empty">
              Aucune classe titulaire pour l'année sélectionnée.
            </div>
            <div v-else class="principal-list">
              <div v-for="assignment in principalAssignments" :key="assignment.id">
                <strong>{{ assignment.classroom?.full_name ?? 'Classe' }}</strong>
                <span>{{ assignment.subject?.name ?? 'Titulaire de classe' }}</span>
              </div>
            </div>
          </section>
        </aside>
      </div>
    </template>
  </section>
</template>

<style scoped>
.teacher-detail-page {
  display: grid;
  gap: 1rem;
}

.teacher-topbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
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

.teacher-profile {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr) minmax(28rem, 0.8fr);
  gap: 1rem;
  align-items: center;
  padding: 1rem;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: var(--bg-card);
  box-shadow: var(--shadow-card);
}

.teacher-avatar {
  width: 4.8rem;
  height: 4.8rem;
  display: grid;
  place-items: center;
  border-radius: 8px;
  background: var(--primary-soft);
  color: var(--primary);
  font-weight: 950;
}

.teacher-heading {
  min-width: 0;
}

.teacher-heading h1 {
  margin: 0;
  font-size: 1.45rem;
}

.teacher-heading p:last-child {
  margin: 0.22rem 0 0;
  color: var(--text-soft);
  font-weight: 750;
}

.teacher-meta {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 0.55rem;
  margin-top: 0.35rem;
  color: var(--text-soft);
  font-size: 0.88rem;
  font-weight: 750;
}

.teacher-meta code {
  padding: 0.12rem 0.45rem;
  border-radius: 6px;
  background: #f1f5f9;
  color: var(--text);
  font-family: ui-monospace, Consolas, monospace;
  font-size: 0.82rem;
  font-weight: 800;
}

.section-kicker {
  margin: 0 0 0.18rem;
  color: var(--text-soft);
  font-size: 0.72rem;
  font-weight: 900;
  letter-spacing: 0.06em;
  text-transform: uppercase;
}

.profile-stats {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 0.6rem;
}

.profile-stats div {
  display: grid;
  gap: 0.18rem;
  padding: 0.75rem;
  border: 1px solid var(--border);
  border-radius: 8px;
  background: var(--bg-subtle);
}

.profile-stats span {
  color: var(--text-soft);
  font-size: 0.72rem;
  font-weight: 850;
  text-transform: uppercase;
}

.profile-stats strong {
  color: var(--text);
  overflow-wrap: anywhere;
}

.teacher-layout {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(18rem, 22rem);
  gap: 1rem;
  align-items: start;
}

.teacher-main,
.teacher-side {
  display: grid;
  gap: 1rem;
}

.detail-panel,
.side-panel {
  display: grid;
  gap: 1rem;
  padding: 1rem;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: var(--bg-card);
  box-shadow: var(--shadow-card);
}

.panel-heading {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
}

.panel-heading h2 {
  margin: 0;
}

.panel-heading > span {
  color: var(--text-soft);
  font-size: 0.82rem;
  font-weight: 850;
}

.table-wrap {
  overflow-x: auto;
}

.empty-state.compact {
  margin: 0;
}

.teacher-timetable {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 0.65rem;
}

.day-column {
  min-height: 10rem;
  display: grid;
  align-content: start;
  gap: 0.6rem;
  padding: 0.75rem;
  border: 1px solid var(--border);
  border-radius: 8px;
  background: var(--bg-subtle);
}

.day-column h3 {
  margin: 0;
  color: var(--text-soft);
  font-size: 0.86rem;
  text-align: center;
}

.day-empty {
  margin: 1.2rem 0 0;
  color: var(--text-soft);
  text-align: center;
}

.slot-list {
  display: grid;
  gap: 0.45rem;
}

.slot-row {
  display: grid;
  gap: 0.12rem;
  padding: 0.55rem;
  border: 1px solid var(--border);
  border-left: 3px solid var(--primary);
  border-radius: 6px;
  background: #fff;
}

.slot-row span,
.slot-row small,
.detail-list dt,
.principal-list span {
  color: var(--text-soft);
  font-size: 0.76rem;
  font-weight: 750;
}

.slot-row strong {
  color: var(--text);
  font-size: 0.88rem;
}

.detail-list {
  display: grid;
  gap: 0.8rem;
  margin: 0;
}

.detail-list div {
  padding-bottom: 0.65rem;
  border-bottom: 1px dotted #cbd5e1;
}

.detail-list div:last-child {
  padding-bottom: 0;
  border-bottom: 0;
}

.detail-list dd {
  margin: 0.18rem 0 0;
  color: var(--text);
  font-weight: 800;
  overflow-wrap: anywhere;
}

.detail-list code {
  padding: 0.1rem 0.35rem;
  border-radius: 4px;
  background: #f1f5f9;
  font-family: ui-monospace, Consolas, monospace;
  font-size: 0.84rem;
}

.principal-list {
  display: grid;
  gap: 0.6rem;
}

.principal-list div {
  display: grid;
  gap: 0.16rem;
  padding: 0.75rem;
  border: 1px solid var(--border);
  border-radius: 8px;
  background: var(--bg-subtle);
}

.side-empty {
  margin: 0;
  color: var(--text-soft);
  line-height: 1.5;
}

@media (max-width: 1080px) {
  .teacher-profile,
  .teacher-layout {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 760px) {
  .teacher-topbar,
  .panel-heading {
    align-items: stretch;
    flex-direction: column;
  }

  .profile-stats,
  .teacher-timetable {
    grid-template-columns: 1fr;
  }
}
</style>
