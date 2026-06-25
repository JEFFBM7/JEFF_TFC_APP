<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { ClipboardList, FileText, TrendingUp, Users } from 'lucide-vue-next'
import { api, ApiError } from '../api/client'
import BarChart from '../components/charts/BarChart.vue'
import LineChart from '../components/charts/LineChart.vue'
import type {
  ApiResource,
  Paginated,
  ParentProfile,
  Student,
  StudentAttendanceSummary,
  StudentPortalStatus,
} from '../types'
import type { ChartSeries, StudentTimeline } from '../types'
import { chartPercentFromAverage20, formatAveragePercent } from '../utils/grades'
import Modal from '../components/Modal.vue'
import { useConfirmStore } from '../stores/confirm'
import ReportCardView from './ReportCardView.vue'

const props = defineProps<{ id: string | number }>()
const confirmDialog = useConfirmStore()

type StudentTab = 'profile' | 'parents' | 'evolution' | 'bulletin'
const activeTab = ref<StudentTab>('profile')

const studentTabs = computed(() => [
  {
    key: 'profile' as const,
    label: 'Fiche élève',
    icon: ClipboardList,
    description: 'Identité, scolarité et notes internes',
    count: null as number | null,
  },
  {
    key: 'parents' as const,
    label: 'Parents',
    icon: Users,
    description: 'Responsables légaux et accès famille',
    count: student.value?.parents?.length ?? 0,
  },
  {
    key: 'evolution' as const,
    label: 'Évolution',
    icon: TrendingUp,
    description: 'Résultats et assiduité',
    count: null as number | null,
  },
  {
    key: 'bulletin' as const,
    label: 'Bulletin',
    icon: FileText,
    description: 'Relevé de notes détaillé',
    count: null as number | null,
  },
])

function switchTab(tab: StudentTab): void {
  activeTab.value = tab
}

function tabPanelId(tab: StudentTab): string {
  return `student-tabpanel-${tab}`
}

const student = ref<Student | null>(null)
const attendanceSummary = ref<StudentAttendanceSummary | null>(null)
const timeline = ref<StudentTimeline | null>(null)
const allParents = ref<ParentProfile[]>([])
const loading = ref(false)
const error = ref('')

const showForm = ref(false)
const submitting = ref(false)
const formError = ref('')
const formErrors = reactive<Record<string, string[]>>({})

const form = reactive({ parent_profile_id: 0, relation: 'mere' as 'pere' | 'mere' | 'tuteur' })

type DetailField = { label: string; value: string }
type StatTone = 'neutral' | 'success' | 'warn' | 'danger'

function statusLabel(status?: Student['enrollment_status']): string {
  if (status === 'actif') return 'Actif'
  if (status === 'redoublant') return 'Redoublant'
  if (status === 'transfere') return 'Transféré'
  if (status === 'inactif') return 'Inactif'
  return '—'
}

function relationLabel(r?: string): string {
  if (r === 'pere') return 'Père'
  if (r === 'mere') return 'Mère'
  if (r === 'tuteur') return 'Tuteur'
  return r ?? '—'
}

function displayValue(value?: string | number | null): string {
  return value === undefined || value === null || String(value).trim() === '' ? '—' : String(value)
}

function formatDate(value?: string | null): string {
  if (!value) return '—'
  const date = new Date(`${value}T00:00:00`)
  if (Number.isNaN(date.getTime())) return value

  return new Intl.DateTimeFormat('fr-FR').format(date)
}

function ageLabel(value?: string | null): string {
  if (!value) return '—'
  const birthDate = new Date(`${value}T00:00:00`)
  if (Number.isNaN(birthDate.getTime())) return '—'

  const today = new Date()
  let age = today.getFullYear() - birthDate.getFullYear()
  const monthDelta = today.getMonth() - birthDate.getMonth()
  if (monthDelta < 0 || (monthDelta === 0 && today.getDate() < birthDate.getDate())) {
    age--
  }

  return age >= 0 ? `${age} ans` : '—'
}

function genderLabel(gender?: Student['gender']): string {
  if (gender === 'F') return 'Féminin'
  if (gender === 'M') return 'Masculin'
  return '—'
}

function studentPortalStatusLabel(status?: StudentPortalStatus): string {
  if (status === 'active') return 'Actif'
  if (status === 'inactive') return 'Inactif'
  if (status === 'not_created') return 'À créer'
  if (status === 'disabled_until_7e') return 'Désactivé avant la 7e'
  if (status === 'not_created_until_7e') return 'Non créé avant la 7e'
  return '—'
}

const studentInitials = computed(() => {
  if (!student.value) return 'EL'
  const parts = [student.value.last_name, student.value.first_name].filter(Boolean)
  return parts
    .map((part) => part.trim().charAt(0))
    .join('')
    .slice(0, 2)
    .toUpperCase()
})

const statusBadgeClass = computed(() => {
  if (student.value?.enrollment_status === 'actif') return 'badge-success'
  if (student.value?.enrollment_status === 'inactif') return 'badge-muted'
  return 'badge-warn'
})

const classroomName = computed(() => student.value?.classroom?.full_name ?? 'Classe non affectée')

const identityFields = computed<DetailField[]>(() => {
  if (!student.value) return []
  return [
    { label: 'Nom', value: displayValue(student.value.last_name) },
    { label: 'Postnom', value: displayValue(student.value.middle_name) },
    { label: 'Prénom(s)', value: displayValue(student.value.first_name) },
    { label: 'Date de naissance', value: formatDate(student.value.date_of_birth) },
    { label: 'Âge', value: ageLabel(student.value.date_of_birth) },
    { label: 'Lieu de naissance', value: displayValue(student.value.place_of_birth) },
    { label: 'Sexe', value: genderLabel(student.value.gender) },
    { label: 'Nationalité', value: displayValue(student.value.nationality) },
  ]
})

const schoolFields = computed<DetailField[]>(() => {
  if (!student.value) return []
  return [
    { label: 'Classe actuelle', value: classroomName.value },
    { label: "Année d'inscription", value: displayValue(student.value.enrollment_school_year?.name) },
    { label: 'Statut', value: statusLabel(student.value.enrollment_status) },
    { label: 'Portail élève', value: studentPortalStatusLabel(student.value.student_portal_status) },
    { label: 'Matricule', value: displayValue(student.value.registration_number) },
    { label: "Numéro d'ordre", value: displayValue(student.value.order_number) },
    { label: "Date d'inscription", value: formatDate(student.value.enrolled_on) },
    { label: 'École de provenance', value: displayValue(student.value.previous_school) },
  ]
})

const fatherFields = computed<DetailField[]>(() => {
  if (!student.value) return []
  return [
    { label: 'Nom complet', value: displayValue(student.value.father_name) },
    { label: 'Profession', value: displayValue(student.value.father_profession) },
  ]
})

const motherFields = computed<DetailField[]>(() => {
  if (!student.value) return []
  return [
    { label: 'Nom complet', value: displayValue(student.value.mother_name) },
    { label: 'Profession', value: displayValue(student.value.mother_profession) },
  ]
})

const guardianFields = computed<DetailField[]>(() => {
  if (!student.value) return []
  return [
    { label: 'Tuteur légal', value: displayValue(student.value.legal_guardian_name) },
    { label: 'Lien de parenté', value: displayValue(student.value.guardian_relationship) },
    { label: 'Téléphone principal', value: displayValue(student.value.primary_phone) },
    { label: 'Téléphone secondaire', value: displayValue(student.value.secondary_phone) },
    { label: 'Email', value: displayValue(student.value.parent_email) },
    { label: 'Adresse / Quartier', value: displayValue(student.value.residential_address) },
  ]
})

const latestTermAverage = computed(() => {
  const rows = timeline.value?.term_averages ?? []
  if (rows.length === 0) return null
  return rows[rows.length - 1]
})

const hasEvolutionData = computed(() =>
  (timeline.value?.term_averages?.length ?? 0) > 0
  || (timeline.value?.monthly_attendance?.length ?? 0) > 0
  || attendanceSummary.value !== null,
)

const attendanceStats = computed<Array<{ label: string; value: number | string; tone: StatTone }>>(() => {
  if (!attendanceSummary.value) return []
  return [
    { label: 'Absences', value: attendanceSummary.value.total_absences, tone: 'neutral' },
    { label: 'Non justifiées', value: attendanceSummary.value.unjustified, tone: attendanceSummary.value.unjustified > 0 ? 'danger' : 'success' },
    { label: 'Justifiées', value: attendanceSummary.value.justified, tone: 'neutral' },
    { label: 'Retards', value: attendanceSummary.value.late_count, tone: attendanceSummary.value.late_count > 0 ? 'warn' : 'success' },
  ]
})

const averageCategories = computed(() =>
  (timeline.value?.term_averages ?? []).map((item) => item.label),
)

const averageSeries = computed<ChartSeries[]>(() => [
  {
    name: 'Moyenne',
    data: (timeline.value?.term_averages ?? []).map((item) => chartPercentFromAverage20(item.average)),
  },
])

const monthlyAttendanceCategories = computed(() =>
  (timeline.value?.monthly_attendance ?? []).map((item) => item.label),
)

const monthlyAttendanceSeries = computed<ChartSeries[]>(() => {
  const rows = timeline.value?.monthly_attendance ?? []
  return [
    { name: 'Absences', data: rows.map((item) => item.absences) },
    { name: 'Retards', data: rows.map((item) => item.lates) },
  ]
})

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  attendanceSummary.value = null
  timeline.value = null
  try {
    const [s, parents] = await Promise.all([
      api<ApiResource<Student>>(`/api/v1/students/${props.id}`),
      api<Paginated<ParentProfile>>('/api/v1/parents'),
    ])
    student.value = s.data
    allParents.value = parents.data
    try {
      const att = await api<{ data: StudentAttendanceSummary }>(
        `/api/v1/students/${props.id}/attendance-summary`,
      )
      attendanceSummary.value = att.data
    } catch {
      attendanceSummary.value = null
    }
    try {
      const res = await api<{ data: StudentTimeline }>(`/api/v1/students/${props.id}/timeline`)
      timeline.value = res.data
    } catch {
      timeline.value = null
    }
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Erreur de chargement.'
  } finally {
    loading.value = false
  }
}

function openAttach(): void {
  form.parent_profile_id = 0
  form.relation = 'mere'
  formError.value = ''
  Object.keys(formErrors).forEach((k) => delete formErrors[k])
  showForm.value = true
}

async function attach(): Promise<void> {
  if (!student.value) return
  submitting.value = true
  formError.value = ''
  Object.keys(formErrors).forEach((k) => delete formErrors[k])
  try {
    await api(`/api/v1/students/${student.value.id}/parents`, {
      method: 'POST',
      body: { parent_profile_id: form.parent_profile_id, relation: form.relation },
    })
    showForm.value = false
    await load()
  } catch (e) {
    if (e instanceof ApiError) {
      formError.value = e.message
      if (e.errors) Object.assign(formErrors, e.errors)
    } else {
      formError.value = 'Erreur réseau.'
    }
  } finally {
    submitting.value = false
  }
}

async function detach(parent: ParentProfile): Promise<void> {
  if (!student.value) return
  const ok = await confirmDialog.ask({
    title: 'Détacher un parent',
    message: 'Ce parent ne sera plus rattaché à cet élève.',
    details: [
      `${parent.user?.name ?? 'Parent'} - ${student.value.full_name}`,
    ],
    confirmLabel: 'Détacher',
    variant: 'warning',
  })
  if (!ok) return
  try {
    await api(`/api/v1/students/${student.value.id}/parents/${parent.id}`, { method: 'DELETE' })
    await load()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Suppression impossible.'
  }
}

onMounted(load)
</script>

<template>
  <section class="student-page">
    <div class="student-topbar">
      <RouterLink class="back-link" :to="{ name: 'students' }">Retour aux élèves</RouterLink>
      <div class="topbar-actions">
        <button type="button" class="btn-secondary" :disabled="loading" @click="load">
          {{ loading ? 'Actualisation...' : 'Actualiser' }}
        </button>
      </div>
    </div>

    <p v-if="error" class="alert alert-error">{{ error }}</p>
    <div v-if="loading && !student" class="empty-state">Chargement de la fiche élève...</div>

    <template v-if="student">
      <section class="profile-panel">
        <div class="student-identity">
          <div class="student-avatar">
            <img v-if="student.photo_path" :src="student.photo_path" alt="Photo de l'élève">
            <span v-else>{{ studentInitials }}</span>
          </div>
          <div class="identity-copy">
            <div class="identity-heading">
              <h1>{{ student.full_name }}</h1>
              <span class="badge" :class="statusBadgeClass">
                {{ statusLabel(student.enrollment_status) }}
              </span>
            </div>
            <p>
              {{ classroomName }}
              <template v-if="student.enrollment_school_year?.name">
                · {{ student.enrollment_school_year.name }}
              </template>
            </p>
            <div class="identity-tags">
              <code v-if="student.registration_number">{{ student.registration_number }}</code>
              <span>{{ genderLabel(student.gender) }}</span>
              <span>{{ ageLabel(student.date_of_birth) }}</span>
            </div>
          </div>
        </div>

        <div class="profile-metrics">
          <div>
            <span>Inscription</span>
            <strong>{{ formatDate(student.enrolled_on) }}</strong>
          </div>
          <div>
            <span>Numéro d'ordre</span>
            <strong>{{ displayValue(student.order_number) }}</strong>
          </div>
          <div>
            <span>Parents liés</span>
            <strong>{{ student.parents?.length ?? 0 }}</strong>
          </div>
          <div>
            <span>Portail élève</span>
            <strong>{{ studentPortalStatusLabel(student.student_portal_status) }}</strong>
          </div>
        </div>
      </section>

      <nav class="student-tabs" role="tablist" aria-label="Sections de la fiche élève">
        <button
          v-for="tab in studentTabs"
          :key="tab.key"
          type="button"
          role="tab"
          :id="`student-tab-${tab.key}`"
          :aria-selected="activeTab === tab.key"
          :aria-controls="tabPanelId(tab.key)"
          :title="tab.description"
          :class="{ active: activeTab === tab.key }"
          @click="switchTab(tab.key)"
        >
          <component :is="tab.icon" :size="16" aria-hidden="true" class="tab-icon" />
          <span class="tab-label">{{ tab.label }}</span>
          <span v-if="tab.count !== null && tab.count > 0" class="tab-count">{{ tab.count }}</span>
        </button>
      </nav>

      <!-- Fiche élève -->
      <div
        v-show="activeTab === 'profile'"
        :id="tabPanelId('profile')"
        class="student-layout"
        role="tabpanel"
        aria-labelledby="student-tab-profile"
      >
        <main class="student-main">
          <section class="card info-card">
            <div class="card-header">
              <div>
                <p class="section-kicker">Dossier administratif</p>
                <h2>Identité et scolarité</h2>
                <p class="section-lead">Informations d'état civil et de scolarité de l'élève.</p>
              </div>
            </div>
            <div class="info-blocks">
              <section class="info-block">
                <h3>Identification</h3>
                <dl class="detail-grid">
                  <div v-for="field in identityFields" :key="field.label">
                    <dt>{{ field.label }}</dt>
                    <dd>{{ field.value }}</dd>
                  </div>
                </dl>
              </section>

              <section class="info-block">
                <h3>Scolarité</h3>
                <dl class="detail-grid">
                  <div v-for="field in schoolFields" :key="field.label">
                    <dt>{{ field.label }}</dt>
                    <dd>{{ field.value }}</dd>
                  </div>
                </dl>
              </section>
            </div>
          </section>
        </main>

        <aside class="student-side">
          <section class="side-card notes-card">
            <div class="side-card-header">
              <p class="section-kicker">Observation</p>
              <h2>Notes internes</h2>
            </div>
            <p>{{ student.notes || 'Aucune note interne enregistrée.' }}</p>
          </section>

          <section class="side-card side-nav-hint">
            <p class="section-kicker">Aller plus loin</p>
            <button type="button" class="side-link-btn" @click="switchTab('parents')">
              <Users :size="16" aria-hidden="true" />
              Voir les parents
            </button>
            <button type="button" class="side-link-btn" @click="switchTab('evolution')">
              <TrendingUp :size="16" aria-hidden="true" />
              Voir l'évolution
            </button>
          </section>
        </aside>
      </div>

      <!-- Parents -->
      <div
        v-show="activeTab === 'parents'"
        :id="tabPanelId('parents')"
        class="tab-panel parents-panel"
        role="tabpanel"
        aria-labelledby="student-tab-parents"
      >
        <section class="card info-card">
          <div class="card-header">
            <div>
              <p class="section-kicker">Responsables</p>
              <h2>Parent / tuteur</h2>
              <p class="section-lead">Coordonnées déclarées dans le dossier administratif.</p>
            </div>
          </div>
          <div class="guardian-cards">
            <article class="guardian-card">
              <h3>Père</h3>
              <dl class="detail-grid guardian-grid">
                <div v-for="field in fatherFields" :key="field.label">
                  <dt>{{ field.label }}</dt>
                  <dd>{{ field.value }}</dd>
                </div>
              </dl>
            </article>
            <article class="guardian-card">
              <h3>Mère</h3>
              <dl class="detail-grid guardian-grid">
                <div v-for="field in motherFields" :key="field.label">
                  <dt>{{ field.label }}</dt>
                  <dd>{{ field.value }}</dd>
                </div>
              </dl>
            </article>
            <article class="guardian-card guardian-card--wide">
              <h3>Tuteur &amp; contact</h3>
              <dl class="detail-grid guardian-grid">
                <div v-for="field in guardianFields" :key="field.label">
                  <dt>{{ field.label }}</dt>
                  <dd>{{ field.value }}</dd>
                </div>
              </dl>
            </article>
          </div>
        </section>

        <section class="card parents-card">
          <div class="card-header parents-header">
            <div>
              <p class="section-kicker">Accès famille</p>
              <h2>Parents / Tuteurs rattachés</h2>
              <p class="section-lead">
                Comptes portail liés à cet élève pour la consultation des notes et messages.
              </p>
            </div>
            <button type="button" class="btn-primary" @click="openAttach">
              <Users :size="16" aria-hidden="true" />
              Rattacher un parent
            </button>
          </div>

          <div v-if="!student.parents || student.parents.length === 0" class="parents-empty">
            <Users :size="28" aria-hidden="true" class="parents-empty-icon" />
            <h3>Aucun parent rattaché</h3>
            <p>Rattachez un compte parent pour lui donner accès au portail famille.</p>
            <button type="button" class="btn-primary" @click="openAttach">Rattacher un parent</button>
          </div>
          <div v-else class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Parent</th>
                  <th>Contact</th>
                  <th>Relation</th>
                  <th class="actions-col">Actions</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="p in student.parents" :key="p.id">
                  <td>
                    <strong>{{ p.user?.name ?? '—' }}</strong>
                    <small>{{ p.address ?? 'Adresse non renseignée' }}</small>
                  </td>
                  <td>
                    <span>{{ p.user?.email ?? '—' }}</span>
                    <small>{{ p.phone ?? 'Téléphone non renseigné' }}</small>
                  </td>
                  <td><span class="badge badge-muted">{{ relationLabel(p.relation) }}</span></td>
                  <td class="actions-cell">
                    <button type="button" class="btn-danger" @click="detach(p)">Détacher</button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>
      </div>

      <!-- Évolution -->
      <div
        v-show="activeTab === 'evolution'"
        :id="tabPanelId('evolution')"
        class="tab-panel evolution-panel"
        role="tabpanel"
        aria-labelledby="student-tab-evolution"
      >
        <div v-if="!hasEvolutionData && !loading" class="evolution-empty">
          <TrendingUp :size="32" aria-hidden="true" class="evolution-empty-icon" />
          <h3>Pas encore de données</h3>
          <p>Les moyennes et l'assiduité apparaîtront ici dès qu'elles seront enregistrées.</p>
        </div>

        <template v-else>
          <div class="evolution-kpis" role="list">
            <article
              v-if="latestTermAverage"
              class="evolution-kpi evolution-kpi--highlight"
              role="listitem"
            >
              <span>Dernière moyenne</span>
              <strong>{{ formatAveragePercent(latestTermAverage.average, 1) }}</strong>
              <small>{{ latestTermAverage.label }}</small>
            </article>
            <article
              v-for="stat in attendanceStats"
              :key="stat.label"
              class="evolution-kpi"
              :class="`tone-${stat.tone}`"
              role="listitem"
            >
              <span>{{ stat.label }}</span>
              <strong>{{ stat.value }}</strong>
            </article>
          </div>

          <p v-if="attendanceSummary?.alert?.triggered" class="attendance-alert evolution-alert">
            <strong>Seuil d'alerte atteint</strong>
            <span>
              {{ attendanceSummary.alert.consecutive }} absence(s) consécutive(s),
              {{ attendanceSummary.alert.count_recent_30d }} sur 30 jours.
            </span>
          </p>

          <div class="evolution-charts">
            <section class="card chart-card">
              <div class="card-header">
                <div>
                  <p class="section-kicker">Résultats</p>
                  <h2>Moyenne par trimestre</h2>
                </div>
              </div>
              <div class="chart-body">
                <LineChart
                  v-if="averageCategories.length"
                  :series="averageSeries"
                  :categories="averageCategories"
                  :height="280"
                  :y-max="100"
                  tooltip-suffix="%"
                />
                <p v-else class="empty-state compact">Aucune moyenne disponible.</p>
              </div>
            </section>

            <section class="card chart-card">
              <div class="card-header">
                <div>
                  <p class="section-kicker">Assiduité</p>
                  <h2>Absences et retards par mois</h2>
                </div>
              </div>
              <div class="chart-body">
                <BarChart
                  v-if="monthlyAttendanceCategories.length"
                  :series="monthlyAttendanceSeries"
                  :categories="monthlyAttendanceCategories"
                  :height="280"
                />
                <p v-else class="empty-state compact">Aucune donnée d'assiduité.</p>
              </div>
            </section>
          </div>
        </template>
      </div>

      <!-- Bulletin -->
      <div
        v-show="activeTab === 'bulletin'"
        :id="tabPanelId('bulletin')"
        class="bulletin-tab-panel"
        role="tabpanel"
        aria-labelledby="student-tab-bulletin"
      >
        <ReportCardView :id="props.id" embedded />
      </div>
    </template>

    <Modal :open="showForm" title="Rattacher un parent" @close="showForm = false">
      <form id="attach-form" @submit.prevent="attach">
        <div class="field">
          <label for="ap-pid">Parent</label>
          <select id="ap-pid" v-model.number="form.parent_profile_id" required>
            <option :value="0" disabled>-- Sélectionner --</option>
            <option v-for="p in allParents" :key="p.id" :value="p.id">
              {{ p.user?.name }} ({{ p.user?.email }})
            </option>
          </select>
          <small v-if="formErrors.parent_profile_id" class="err">{{ formErrors.parent_profile_id[0] }}</small>
        </div>
        <div class="field">
          <label for="ap-rel">Relation</label>
          <select id="ap-rel" v-model="form.relation" required>
            <option value="mere">Mère</option>
            <option value="pere">Père</option>
            <option value="tuteur">Tuteur</option>
          </select>
          <small v-if="formErrors.relation" class="err">{{ formErrors.relation[0] }}</small>
        </div>
        <p v-if="formError" class="alert alert-error">{{ formError }}</p>
      </form>
      <template #footer>
        <button type="button" @click="showForm = false">Annuler</button>
        <button type="submit" form="attach-form" class="btn-primary" :disabled="submitting">
          {{ submitting ? 'Enregistrement…' : 'Rattacher' }}
        </button>
      </template>
    </Modal>
  </section>
</template>

<style scoped>
.student-page {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.student-topbar {
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

.topbar-actions {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  flex-wrap: wrap;
}

.profile-panel {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(18rem, 0.5fr);
  gap: 1rem;
  align-items: stretch;
  padding: 1.15rem;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: var(--bg-card);
  box-shadow: var(--shadow-card);
}

.student-identity {
  display: flex;
  align-items: center;
  gap: 1rem;
  min-width: 0;
}

.student-avatar {
  width: 5rem;
  height: 5rem;
  flex: 0 0 auto;
  display: grid;
  place-items: center;
  border: 1px solid var(--primary-soft);
  border-radius: 8px;
  background: var(--primary-soft);
  color: var(--primary);
  font-size: 1.35rem;
  font-weight: 950;
  overflow: hidden;
}

.student-avatar img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.identity-copy {
  min-width: 0;
  display: grid;
  gap: 0.4rem;
}

.identity-heading {
  display: flex;
  align-items: center;
  gap: 0.55rem;
  flex-wrap: wrap;
}

.identity-heading h1 {
  margin: 0;
  font-size: 1.45rem;
}

.identity-copy p {
  margin: 0;
  color: var(--text-soft);
  font-size: 0.92rem;
  font-weight: 700;
}

.identity-tags {
  display: flex;
  align-items: center;
  gap: 0.45rem;
  flex-wrap: wrap;
}

.identity-tags span,
code {
  font-family: inherit;
  padding: 0.22rem 0.55rem;
  border: 1px solid var(--border);
  border-radius: 6px;
  background: var(--bg-soft);
  color: var(--text);
  font-size: 0.8rem;
  font-weight: 800;
}

code {
  font-family: ui-monospace, Consolas, monospace;
}

.profile-metrics {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 0.7rem;
}

.profile-metrics div {
  display: grid;
  align-content: center;
  gap: 0.22rem;
  padding: 0.75rem;
  border: 1px solid var(--border);
  border-radius: 8px;
  background: var(--bg-subtle);
}

.profile-metrics span {
  color: var(--text-soft);
  font-size: 0.72rem;
  font-weight: 850;
  text-transform: uppercase;
}

.profile-metrics strong {
  color: var(--text);
  font-size: 0.95rem;
  overflow-wrap: anywhere;
}

.section-lead {
  margin: 0.35rem 0 0;
  color: var(--text-soft);
  font-size: 0.86rem;
  font-weight: 600;
  line-height: 1.45;
  max-width: 42rem;
}

.student-tabs {
  display: flex;
  gap: 0.35rem;
  padding: 0.35rem;
  border: 1px solid var(--border);
  border-radius: 10px;
  background: var(--bg-subtle);
  overflow-x: auto;
  -webkit-overflow-scrolling: touch;
  scrollbar-width: thin;
}

.student-tabs button {
  min-height: 2.5rem;
  padding: 0.5rem 0.9rem;
  border: 0;
  border-radius: 8px;
  background: transparent;
  color: var(--text-soft);
  font-weight: 800;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
  white-space: nowrap;
  flex: 0 0 auto;
  transition: background 0.18s ease, color 0.18s ease, box-shadow 0.18s ease;
}

.student-tabs button:hover {
  color: var(--text);
  background: rgb(255 255 255 / 0.55);
}

.student-tabs button:focus-visible {
  outline: 2px solid var(--primary);
  outline-offset: 2px;
}

.student-tabs button.active {
  background: var(--bg-card);
  color: var(--primary);
  box-shadow: 0 1px 4px rgb(15 23 42 / 0.1);
}

.tab-icon {
  flex-shrink: 0;
  opacity: 0.85;
}

.tab-count {
  min-width: 1.35rem;
  padding: 0.1rem 0.4rem;
  border-radius: 999px;
  background: var(--primary-soft);
  color: var(--primary);
  font-size: 0.72rem;
  font-weight: 900;
  line-height: 1.2;
  text-align: center;
}

.tab-panel {
  display: grid;
  gap: 1rem;
  min-width: 0;
}

.bulletin-tab-panel {
  min-width: 0;
}

.side-nav-hint {
  display: grid;
  gap: 0.55rem;
}

.side-link-btn {
  min-height: 2.5rem;
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.55rem 0.75rem;
  border: 1px solid var(--border);
  border-radius: 8px;
  background: var(--bg-soft);
  color: var(--text);
  font-size: 0.86rem;
  font-weight: 750;
  cursor: pointer;
  text-align: left;
  transition: border-color 0.18s ease, background 0.18s ease;
}

.side-link-btn:hover {
  border-color: var(--primary-tint);
  background: var(--primary-soft);
  color: var(--primary);
}

.guardian-cards {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.85rem;
  padding: 0 1.15rem 1.15rem;
}

.guardian-card {
  display: grid;
  gap: 0.65rem;
  padding: 1rem;
  border: 1px solid var(--border);
  border-radius: 10px;
  background: linear-gradient(180deg, var(--bg-card) 0%, var(--bg-soft) 100%);
}

.guardian-card--wide {
  grid-column: 1 / -1;
}

.guardian-card h3 {
  margin: 0;
  font-size: 0.92rem;
  color: var(--primary);
}

.guardian-grid {
  gap: 0.65rem 1rem;
}

.guardian-grid div {
  padding-bottom: 0.45rem;
}

.parents-header .btn-primary {
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
  min-height: 2.5rem;
  white-space: nowrap;
}

.parents-empty {
  display: grid;
  justify-items: center;
  gap: 0.55rem;
  padding: 2rem 1.25rem;
  text-align: center;
}

.parents-empty-icon {
  color: var(--text-soft);
  opacity: 0.55;
}

.parents-empty h3 {
  margin: 0;
  font-size: 1.05rem;
}

.parents-empty p {
  margin: 0;
  max-width: 26rem;
  color: var(--text-soft);
  font-size: 0.9rem;
  line-height: 1.5;
}

.evolution-kpis {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(9rem, 1fr));
  gap: 0.75rem;
}

.evolution-kpi {
  display: grid;
  gap: 0.2rem;
  padding: 0.9rem 1rem;
  border: 1px solid var(--border);
  border-radius: 10px;
  background: var(--bg-card);
  box-shadow: var(--shadow-card);
}

.evolution-kpi span {
  color: var(--text-soft);
  font-size: 0.72rem;
  font-weight: 850;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.evolution-kpi strong {
  font-size: 1.5rem;
  line-height: 1.1;
  font-variant-numeric: tabular-nums;
}

.evolution-kpi small {
  color: var(--text-soft);
  font-size: 0.78rem;
  font-weight: 700;
}

.evolution-kpi--highlight {
  border-color: var(--primary-tint);
  background: linear-gradient(145deg, var(--primary-soft) 0%, var(--bg-soft) 100%);
}

.evolution-kpi--highlight strong {
  color: var(--primary);
}

.evolution-kpi.tone-success {
  border-color: rgba(74, 222, 128, 0.3);
  background: var(--success-soft);
}

.evolution-kpi.tone-success strong {
  color: var(--success);
}

.evolution-kpi.tone-warn {
  border-color: rgba(251, 191, 36, 0.3);
  background: var(--warn-soft);
}

.evolution-kpi.tone-warn strong {
  color: var(--warn);
}

.evolution-kpi.tone-danger {
  border-color: rgba(248, 113, 113, 0.3);
  background: var(--danger-soft);
}

.evolution-kpi.tone-danger strong {
  color: var(--danger);
}

.evolution-alert {
  margin: 0;
}

.evolution-charts {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 1rem;
}

.chart-card .card-header {
  padding: 1rem 1.15rem 0;
}

.chart-body {
  padding: 0.5rem 1rem 1.15rem;
}

.evolution-empty {
  display: grid;
  justify-items: center;
  gap: 0.55rem;
  padding: 3rem 1.5rem;
  border: 1px dashed var(--border);
  border-radius: var(--radius);
  background: var(--bg-subtle);
  text-align: center;
}

.evolution-empty-icon {
  color: var(--text-soft);
  opacity: 0.5;
}

.evolution-empty h3 {
  margin: 0;
}

.evolution-empty p {
  margin: 0;
  max-width: 24rem;
  color: var(--text-soft);
  font-size: 0.9rem;
}

.student-layout {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(18rem, 22rem);
  gap: 1rem;
  align-items: start;
}

.student-main,
.student-side {
  display: grid;
  gap: 1rem;
}

.info-card .card-header,
.parents-card .card-header {
  align-items: flex-start;
}

.section-kicker {
  margin: 0 0 0.18rem;
  color: var(--text-soft);
  font-size: 0.72rem;
  font-weight: 900;
  letter-spacing: 0.06em;
  text-transform: uppercase;
}

.card-header h2,
.side-card h2 {
  margin: 0;
}

.info-blocks {
  display: grid;
  gap: 1rem;
  padding: 0 1.15rem 1.15rem;
}

.info-block {
  display: grid;
  gap: 0.75rem;
}

.info-block + .info-block {
  padding-top: 1rem;
  border-top: 1px solid var(--border);
}

.info-block h3 {
  margin: 0;
  color: var(--text);
  font-size: 0.95rem;
}

.detail-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr));
  gap: 0.85rem 1.1rem;
  margin: 0;
}

.detail-grid div {
  min-width: 0;
  padding-bottom: 0.6rem;
  border-bottom: 1px dotted var(--border-strong);
}

.detail-grid dt {
  margin: 0;
  color: var(--text-soft);
  font-size: 0.76rem;
  font-weight: 800;
}

.detail-grid dd {
  margin: 0.2rem 0 0;
  color: var(--text);
  font-size: 0.9rem;
  font-weight: 750;
  overflow-wrap: anywhere;
}

.parents-header {
  gap: 0.75rem;
}

.table-wrap {
  overflow-x: auto;
}

td strong,
td small,
td span {
  display: block;
}

td small {
  margin-top: 0.12rem;
  color: var(--text-soft);
  font-size: 0.78rem;
}

.actions-col,
.actions-cell {
  width: 1%;
  text-align: right;
  white-space: nowrap;
}

.side-card {
  display: grid;
  gap: 1rem;
  padding: 1rem;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: var(--bg-card);
  box-shadow: var(--shadow-card);
}

.side-card-header {
  display: grid;
  gap: 0.1rem;
}

.attendance-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.65rem;
}

.attendance-stat {
  display: grid;
  gap: 0.18rem;
  padding: 0.75rem;
  border: 1px solid var(--border);
  border-radius: 8px;
  background: var(--bg-soft);
}

.attendance-stat span {
  color: var(--text-soft);
  font-size: 0.72rem;
  font-weight: 850;
  text-transform: uppercase;
}

.attendance-stat strong {
  color: var(--text);
  font-size: 1.35rem;
  line-height: 1;
}

.attendance-stat.tone-success {
  border-color: rgba(74, 222, 128, 0.3);
  background: var(--success-soft);
}

.attendance-stat.tone-success strong {
  color: var(--success);
}

.attendance-stat.tone-warn {
  border-color: rgba(251, 191, 36, 0.3);
  background: var(--warn-soft);
}

.attendance-stat.tone-warn strong {
  color: var(--warn);
}

.attendance-stat.tone-danger {
  border-color: rgba(248, 113, 113, 0.3);
  background: var(--danger-soft);
}

.attendance-stat.tone-danger strong {
  color: var(--danger);
}

.attendance-alert {
  display: grid;
  gap: 0.25rem;
  margin: 0;
  padding: 0.75rem 0.85rem;
  border: 1px solid rgba(251, 191, 36, 0.3);
  border-radius: 8px;
  background: var(--warn-soft);
  color: var(--warn);
  font-size: 0.86rem;
}

.side-empty,
.notes-card p {
  margin: 0;
  color: var(--text-soft);
  font-size: 0.9rem;
  line-height: 1.55;
}

.empty-state.compact {
  margin: 0;
  border-radius: 0;
  border-left: 0;
  border-right: 0;
}

.err {
  display: block;
  color: var(--danger);
  font-size: 0.78rem;
  margin-top: 0.25rem;
}

@media (max-width: 980px) {
  .profile-panel,
  .student-layout {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 720px) {
  .student-topbar,
  .parents-header {
    align-items: stretch;
    flex-direction: column;
  }

  .topbar-actions,
  .parents-header button {
    width: 100%;
  }

  .student-tabs button {
    padding: 0.5rem 0.7rem;
  }

  .tab-label {
    font-size: 0.82rem;
  }

  .student-identity {
    align-items: flex-start;
  }

  .profile-metrics,
  .guardian-cards,
  .evolution-charts,
  .attendance-grid {
    grid-template-columns: 1fr;
  }

  .parents-header .btn-primary {
    width: 100%;
    justify-content: center;
  }

  .identity-heading h1 {
    font-size: 1.2rem;
  }
}
</style>
