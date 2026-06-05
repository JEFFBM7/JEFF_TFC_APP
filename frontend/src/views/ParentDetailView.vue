<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { api, ApiError } from '../api/client'
import type { ApiResource, ParentProfile, Student } from '../types'
import { useSchoolYearStore } from '../stores/schoolYear'

const props = defineProps<{ id: string | number }>()

const schoolYearStore = useSchoolYearStore()
const parent = ref<ParentProfile | null>(null)
const loading = ref(false)
const error = ref('')

const selectedSchoolYear = computed(() =>
  schoolYearStore.selected
    ?? schoolYearStore.years.find((year) => year.id === schoolYearStore.effectiveId)
    ?? schoolYearStore.current
    ?? null,
)

const parentName = computed(() => parent.value?.user?.name ?? 'Parent')
const parentInitials = computed(() => {
  const parts = parentName.value.split(/\s+/).filter(Boolean)
  return (parts[0]?.charAt(0) ?? 'P') + (parts[1]?.charAt(0) ?? 'A')
})

const students = computed<Student[]>(() => parent.value?.students ?? [])

const linkedClassesCount = computed(() => {
  const classIds = students.value
    .map((student) => student.classroom_id)
    .filter((id): id is number => id !== null && id !== undefined)
  return new Set(classIds).size
})

const activeChildrenCount = computed(() =>
  students.value.filter((student) => student.enrollment_status !== 'inactif').length,
)

function displayValue(value?: string | number | null): string {
  return value === undefined || value === null || String(value).trim() === '' ? '—' : String(value)
}

function relationLabel(value?: string | null): string {
  if (value === 'pere') return 'Père'
  if (value === 'mere') return 'Mère'
  if (value === 'tuteur') return 'Tuteur'
  return displayValue(value)
}

function statusLabel(status?: Student['enrollment_status']): string {
  if (status === 'actif') return 'Actif'
  if (status === 'redoublant') return 'Redoublant'
  if (status === 'transfere') return 'Transféré'
  if (status === 'inactif') return 'Inactif'
  return '—'
}

function genderLabel(gender?: Student['gender']): string {
  if (gender === 'F') return 'Féminin'
  if (gender === 'M') return 'Masculin'
  return '—'
}

function formatDate(value?: string | null): string {
  if (!value) return '—'
  const date = new Date(`${value}T00:00:00`)
  if (Number.isNaN(date.getTime())) return value

  return new Intl.DateTimeFormat('fr-FR').format(date)
}

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    await schoolYearStore.fetchAll()
    const res = await api<ApiResource<ParentProfile>>(`/api/v1/parents/${props.id}`)
    parent.value = res.data
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
  <section class="parent-detail-page">
    <div class="parent-topbar">
      <RouterLink class="back-link" :to="{ name: 'parents' }">Retour aux parents</RouterLink>
      <button type="button" class="btn-secondary" :disabled="loading" @click="load">
        {{ loading ? 'Actualisation...' : 'Actualiser' }}
      </button>
    </div>

    <p v-if="error" class="alert alert-error">{{ error }}</p>
    <div v-if="loading && !parent" class="empty-state">Chargement de la fiche parent...</div>

    <template v-if="parent">
      <section class="parent-profile">
        <div class="parent-avatar">{{ parentInitials.toUpperCase() }}</div>
        <div class="parent-heading">
          <p class="section-kicker">Fiche parent</p>
          <h1>{{ parentName }}</h1>
          <p>{{ parent.user?.email ?? 'Email non renseigné' }}</p>
        </div>
        <div class="profile-stats">
          <div>
            <span>Année</span>
            <strong>{{ selectedSchoolYear?.name ?? '—' }}</strong>
          </div>
          <div>
            <span>Enfants</span>
            <strong>{{ students.length }}</strong>
          </div>
          <div>
            <span>Actifs</span>
            <strong>{{ activeChildrenCount }}</strong>
          </div>
          <div>
            <span>Classes</span>
            <strong>{{ linkedClassesCount }}</strong>
          </div>
        </div>
      </section>

      <div class="parent-layout">
        <main class="parent-main">
          <section class="detail-panel">
            <div class="panel-heading">
              <div>
                <p class="section-kicker">Enfants rattachés</p>
                <h2>Suivi scolaire</h2>
              </div>
              <span>{{ students.length }} enfant(s)</span>
            </div>

            <div v-if="students.length === 0" class="empty-state compact">
              Aucun enfant rattaché pour l'année scolaire sélectionnée.
            </div>
            <div v-else class="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>Élève</th>
                    <th>Classe</th>
                    <th>Relation</th>
                    <th>Statut</th>
                    <th>Inscription</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="student in students" :key="student.id">
                    <td>
                      <RouterLink :to="{ name: 'student-detail', params: { id: student.id } }">
                        {{ student.full_name }}
                      </RouterLink>
                      <small>{{ student.registration_number ?? 'Matricule non renseigné' }}</small>
                    </td>
                    <td>
                      <RouterLink
                        v-if="selectedSchoolYear && student.classroom_id"
                        :to="{
                          name: 'school-year-class-detail',
                          params: { id: selectedSchoolYear.id, classroomId: student.classroom_id },
                        }"
                      >
                        {{ student.classroom?.full_name ?? '—' }}
                      </RouterLink>
                      <span v-else>{{ student.classroom?.full_name ?? '—' }}</span>
                    </td>
                    <td>{{ relationLabel(student.relation) }}</td>
                    <td>
                      <span class="badge" :class="student.enrollment_status === 'actif' ? 'badge-success' : 'badge-muted'">
                        {{ statusLabel(student.enrollment_status) }}
                      </span>
                    </td>
                    <td>{{ formatDate(student.enrolled_on) }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </section>
        </main>

        <aside class="parent-side">
          <section class="side-panel">
            <p class="section-kicker">Coordonnées</p>
            <dl class="detail-list">
              <div>
                <dt>Nom</dt>
                <dd>{{ parentName }}</dd>
              </div>
              <div>
                <dt>Email</dt>
                <dd>{{ displayValue(parent.user?.email) }}</dd>
              </div>
              <div>
                <dt>Téléphone</dt>
                <dd>{{ displayValue(parent.phone) }}</dd>
              </div>
              <div>
                <dt>Adresse</dt>
                <dd>{{ displayValue(parent.address) }}</dd>
              </div>
            </dl>
          </section>

          <section class="side-panel">
            <p class="section-kicker">Résumé enfants</p>
            <div v-if="students.length === 0" class="side-empty">
              Aucun enfant visible dans le contexte d'année scolaire courant.
            </div>
            <div v-else class="child-summary">
              <div v-for="student in students" :key="student.id">
                <strong>{{ student.full_name }}</strong>
                <span>{{ genderLabel(student.gender) }} · {{ student.classroom?.full_name ?? 'Classe non affectée' }}</span>
              </div>
            </div>
          </section>
        </aside>
      </div>
    </template>
  </section>
</template>

<style scoped>
.parent-detail-page {
  display: grid;
  gap: 1rem;
}

.parent-topbar {
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

.parent-profile {
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

.parent-avatar {
  width: 4.8rem;
  height: 4.8rem;
  display: grid;
  place-items: center;
  border-radius: 8px;
  background: var(--primary-soft);
  color: var(--primary);
  font-weight: 950;
}

.parent-heading {
  min-width: 0;
}

.parent-heading h1 {
  margin: 0;
  font-size: 1.45rem;
}

.parent-heading p:last-child {
  margin: 0.22rem 0 0;
  color: var(--text-soft);
  font-weight: 750;
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

.parent-layout {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(18rem, 22rem);
  gap: 1rem;
  align-items: start;
}

.parent-main,
.parent-side {
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

td small {
  display: block;
  margin-top: 0.12rem;
  color: var(--text-soft);
  font-size: 0.78rem;
}

.empty-state.compact {
  margin: 0;
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

.detail-list dt {
  color: var(--text-soft);
  font-size: 0.76rem;
  font-weight: 750;
}

.detail-list dd {
  margin: 0.18rem 0 0;
  color: var(--text);
  font-weight: 800;
  overflow-wrap: anywhere;
}

.child-summary {
  display: grid;
  gap: 0.6rem;
}

.child-summary div {
  display: grid;
  gap: 0.16rem;
  padding: 0.75rem;
  border: 1px solid var(--border);
  border-radius: 8px;
  background: var(--bg-subtle);
}

.child-summary span,
.side-empty {
  color: var(--text-soft);
  font-size: 0.82rem;
  line-height: 1.5;
}

@media (max-width: 1080px) {
  .parent-profile,
  .parent-layout {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 760px) {
  .parent-topbar,
  .panel-heading {
    align-items: stretch;
    flex-direction: column;
  }

  .profile-stats {
    grid-template-columns: 1fr;
  }
}
</style>
