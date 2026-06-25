<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { api, ApiError } from '../api/client'
import { useAuthStore } from '../stores/auth'
import { usePortalDashboard } from '../composables/usePortalDashboard'
import {
  RefreshCw,
  Calendar,
  GraduationCap,
  AlertTriangle,
  AlertOctagon,
  Clock,
  TrendingUp,
  ArrowRight,
  Inbox,
  BookOpen,
  FileWarning,
  Calculator,
  FlaskConical,
  Globe,
} from 'lucide-vue-next'

interface RecentGrade {
  id: number
  subject: string | null
  teacher: string | null
  evaluation_name: string | null
  value: number | null
  class_average: number | null
  max: number
  published_at: string | null
}

interface StudentSummary {
  student_id: number
  full_name: string
  first_name?: string | null
  classroom: string | null
  current_term: string | null
  current_average: number | null
  total_absences: number
  total_lates: number
  alert: { triggered: boolean; consecutive: number; count_recent_30d: number }
  unjustified_absences: number
  recent_grades_count: number
  recent_grades: RecentGrade[]
}

const auth = useAuthStore()
const {
  initials,
  greeting,
  todayLabel,
  performanceFromAverage,
  avgPercent,
  formatPercentFrom20,
} = usePortalDashboard()

const summary = ref<StudentSummary | null>(null)
const loading = ref(false)
const error = ref('')

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    const res = await api<{ data: StudentSummary }>('/api/v1/student/dashboard')
    summary.value = res.data
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Erreur de chargement.'
  } finally {
    loading.value = false
  }
}

const displayName = computed(() => {
  const fromProfile = summary.value?.first_name?.trim()
  if (fromProfile) return fromProfile
  const name = summary.value?.full_name?.trim() || auth.user?.name?.trim() || ''
  return name.split(/\s+/).filter(Boolean).at(-1) || 'toi'
})

const performance = computed(() => performanceFromAverage(summary.value?.current_average ?? null))

const averageInsight = computed(() => {
  const avg = summary.value?.current_average ?? null
  if (avg === null) return { label: 'Écart à 50%', value: '—', tone: 'muted' as const }
  const gap = Math.abs((avg - 10) * 5).toFixed(2)
  if (avg >= 10) {
    return { label: 'Marge', value: `+${gap} pts`, tone: 'good' as const }
  }
  return {
    label: 'Pour atteindre 50%',
    value: `${gap} pts`,
    tone: avg >= 8 ? 'warn' as const : 'danger' as const,
  }
})

const absenceNote = computed(() => {
  const count = summary.value?.total_absences ?? 0
  if (summary.value?.alert?.triggered) return "Seuil d'alerte atteint"
  if (count === 0) return 'Aucune absence'
  return count === 1 ? '1 absence à suivre' : `${count} absences à suivre`
})

const lateNote = computed(() => {
  const count = summary.value?.total_lates ?? 0
  if (count === 0) return 'Aucun retard'
  return count === 1 ? '1 retard enregistré' : `${count} retards enregistrés`
})

const recentGrades = computed(() => summary.value?.recent_grades ?? [])

function gradeTone(g: RecentGrade): 'good' | 'warn' | 'danger' {
  if (g.value === null) return 'warn'
  if (g.value >= 10) return 'good'
  if (g.value >= 8) return 'warn'
  return 'danger'
}

function formatGrade(v: number | null): string {
  if (v === null) return '—'
  return Number.isInteger(v) ? `${v}` : v.toFixed(1)
}

function subjectIcon(name: string | null): typeof BookOpen {
  const n = (name ?? '').toLowerCase()
  if (/(math|calcul|algèb|géom)/.test(n)) return Calculator
  if (/(physi|chim|svt|bio|science|techn)/.test(n)) return FlaskConical
  if (/(hist|géo|geo)/.test(n)) return Globe
  return BookOpen
}

const ARC_PATH = 'M 26 98 A 74 74 0 0 1 174 98'
const gaugePercent = computed(() => avgPercent(summary.value?.current_average ?? null))

const arcAriaLabel = computed(() => {
  const pct = formatPercentFrom20(summary.value?.current_average ?? null)
  if (pct === '—') return 'Pas encore de moyenne enregistrée'
  return `Moyenne générale : ${pct} pour cent, ${performance.value.label}`
})

onMounted(() => {
  void load()
})
</script>

<template>
  <section class="portal-dash portal-mobile">
    <header class="portal-dash-hero portal-dash-animate">
      <div class="portal-dash-hero__identity">
        <div class="portal-dash-hero__avatar" aria-hidden="true">
          {{ initials(summary?.full_name || auth.user?.name || '') }}
        </div>
        <div class="portal-dash-hero__text">
          <p class="portal-dash-hero__date">
            <Calendar aria-hidden="true" />
            <span>{{ todayLabel() }}</span>
          </p>
          <h1>{{ greeting() }}, {{ displayName }}</h1>
          <p class="portal-dash-hero__meta">
            <span>Portail élève</span>
            <template v-if="summary?.classroom">
              <span aria-hidden="true">·</span>
              <span class="portal-dash-hero__tag">
                <GraduationCap aria-hidden="true" />
                {{ summary.classroom }}
              </span>
            </template>
            <template v-if="summary?.current_term">
              <span aria-hidden="true">·</span>
              <span class="portal-dash-hero__tag portal-dash-hero__tag--muted">{{ summary.current_term }}</span>
            </template>
          </p>
        </div>
      </div>
      <button
        type="button"
        class="portal-dash-hero__refresh"
        aria-label="Rafraîchir"
        :disabled="loading"
        @click="load"
      >
        <RefreshCw :class="{ 'is-spinning': loading }" aria-hidden="true" />
      </button>
    </header>

    <p v-if="error" class="alert alert-error" role="alert">{{ error }}</p>

    <div v-if="loading && !summary" aria-hidden="true">
      <div class="portal-dash-skeleton" style="min-height: 7rem; margin-bottom: 0.75rem" />
      <div class="portal-dash-skeleton" style="min-height: 12rem; margin-bottom: 0.75rem" />
      <div class="portal-dash-kpis">
        <div v-for="i in 3" :key="i" class="portal-dash-skeleton" style="min-height: 6.75rem" />
      </div>
    </div>

    <div v-else-if="!summary" class="portal-dash-empty portal-dash-animate">
      <Inbox aria-hidden="true" />
      <h2>Aucune donnée</h2>
      <p>Ton profil élève n'est pas encore associé à une classe. Contacte le secrétariat.</p>
    </div>

    <template v-else>
      <RouterLink
        v-if="summary.alert?.triggered"
        :to="{ name: 'student-absences' }"
        class="portal-dash-alert portal-dash-alert--danger portal-kpi-link portal-dash-animate portal-dash-animate--delay-1"
      >
        <AlertOctagon class="portal-dash-alert__icon" aria-hidden="true" />
        <div class="portal-dash-alert__body">
          <strong>Seuil d'alerte d'absentéisme atteint</strong>
          <span>
            {{ summary.alert.consecutive }} consécutives ·
            {{ summary.alert.count_recent_30d }} sur 30 jours.
            Consulte tes absences.
          </span>
        </div>
        <ArrowRight class="portal-dash-alert__arrow" aria-hidden="true" />
      </RouterLink>

      <RouterLink
        :to="{ name: 'student-bulletin' }"
        class="portal-dash-perf portal-performance-link portal-dash-animate portal-dash-animate--delay-2"
        :class="`portal-dash-perf--${performance.tone}`"
        aria-label="Voir mon bulletin et mes notes"
      >
        <div class="portal-dash-perf__head">
          <span class="portal-dash-perf__eyebrow">
            <TrendingUp aria-hidden="true" />
            <span>Moyenne générale {{ summary.current_term ?? '' }}</span>
          </span>
          <span class="portal-status-pill" :class="`portal-status-pill--${performance.tone}`">
            {{ performance.label }}
          </span>
        </div>

        <div class="portal-dash-perf__gauge" role="img" :aria-label="arcAriaLabel">
          <svg viewBox="0 0 200 112" aria-hidden="true">
            <path class="portal-dash-perf__arc-track" :d="ARC_PATH" pathLength="100" />
            <path
              class="portal-dash-perf__arc-fill"
              :class="`portal-dash-perf__arc-fill--${performance.tone}`"
              :d="ARC_PATH"
              pathLength="100"
              :style="{
                strokeDasharray: '100',
                strokeDashoffset: 100 - gaugePercent,
              }"
            />
          </svg>
          <div class="portal-dash-perf__arc-center" aria-hidden="true">
            <strong class="portal-dash-perf__arc-value">{{ formatPercentFrom20(summary.current_average) }}</strong>
            <span class="portal-dash-perf__arc-unit">%</span>
          </div>
        </div>

        <p class="portal-dash-perf__message">
          <span>{{ performance.message }}</span>
          <ArrowRight aria-hidden="true" style="width:1rem;height:1rem;margin-left:auto;opacity:0.5" />
        </p>

        <dl class="portal-dash-perf__insights">
          <div class="portal-dash-perf__insight">
            <dt>{{ averageInsight.label }}</dt>
            <dd :class="`portal-status-pill--${averageInsight.tone}`" style="background:none;padding:0">
              {{ averageInsight.value }}
            </dd>
          </div>
          <div class="portal-dash-perf__insight">
            <dt>Période</dt>
            <dd>{{ summary.current_term ?? 'En cours' }}</dd>
          </div>
        </dl>
      </RouterLink>

      <div class="portal-dash-kpis portal-dash-animate portal-dash-animate--delay-3" aria-label="Indicateurs scolaires">
        <RouterLink
          :to="{ name: 'student-absences' }"
          class="portal-dash-kpi portal-kpi-link"
          :class="{
            'portal-dash-kpi--danger': summary.alert?.triggered,
            'portal-dash-kpi--warn': summary.total_absences > 0 && !summary.alert?.triggered,
          }"
          aria-label="Voir mes absences"
        >
          <div class="portal-dash-kpi__head">
            <span class="portal-dash-kpi__label">Absences</span>
            <span class="portal-dash-kpi__icon"><AlertTriangle aria-hidden="true" /></span>
          </div>
          <strong class="portal-dash-kpi__value">{{ summary.total_absences }}</strong>
          <span class="portal-dash-kpi__note">{{ absenceNote }}</span>
          <ArrowRight class="portal-dash-kpi__hint" aria-hidden="true" />
        </RouterLink>

        <RouterLink
          :to="{ name: 'student-absences' }"
          class="portal-dash-kpi portal-kpi-link"
          :class="{ 'portal-dash-kpi--warn': summary.total_lates > 0 }"
          aria-label="Voir mes retards"
        >
          <div class="portal-dash-kpi__head">
            <span class="portal-dash-kpi__label">Retards</span>
            <span class="portal-dash-kpi__icon"><Clock aria-hidden="true" /></span>
          </div>
          <strong class="portal-dash-kpi__value">{{ summary.total_lates }}</strong>
          <span class="portal-dash-kpi__note">{{ lateNote }}</span>
          <ArrowRight class="portal-dash-kpi__hint" aria-hidden="true" />
        </RouterLink>
      </div>

      <!-- Absence à justifier -->
      <RouterLink
        v-if="summary.unjustified_absences > 0"
        :to="{ name: 'student-absences' }"
        class="portal-dash-notif portal-kpi-link portal-dash-notif--warn portal-dash-animate portal-dash-animate--delay-3"
      >
        <span class="portal-dash-notif__icon portal-dash-notif__icon--warn"><FileWarning aria-hidden="true" /></span>
        <span class="portal-dash-notif__body">
          <strong>{{ summary.unjustified_absences }} absence{{ summary.unjustified_absences > 1 ? 's' : '' }} à justifier</strong>
          <span>En attente de justification</span>
        </span>
        <ArrowRight class="portal-dash-notif__arrow" aria-hidden="true" />
      </RouterLink>

      <!-- Notes récemment publiées -->
      <section
        v-if="recentGrades.length > 0"
        class="portal-grades portal-dash-animate portal-dash-animate--delay-3"
        aria-label="Notes récemment publiées"
      >
        <header class="portal-grades__head">
          <h2>Dernières notes publiées</h2>
          <RouterLink :to="{ name: 'student-bulletin' }" class="portal-grades__all">Tout voir</RouterLink>
        </header>

        <RouterLink
          v-for="g in recentGrades"
          :key="g.id"
          :to="{ name: 'student-bulletin' }"
          class="portal-grade-card portal-kpi-link"
        >
          <span class="portal-grade-card__icon" :class="`portal-grade-card__icon--${gradeTone(g)}`">
            <component :is="subjectIcon(g.subject)" aria-hidden="true" />
          </span>

          <div class="portal-grade-card__main">
            <strong class="portal-grade-card__subject">{{ g.subject ?? 'Matière' }}</strong>
            <span v-if="g.teacher" class="portal-grade-card__teacher">Prof. {{ g.teacher }}</span>
            <span class="portal-grade-card__score">
              <strong :class="`portal-grade-card__value--${gradeTone(g)}`">{{ formatGrade(g.value) }}</strong>
              <span class="portal-grade-card__max">/ {{ g.max }}</span>
            </span>
          </div>

          <div class="portal-grade-card__class">
            <span class="portal-grade-card__class-label">Moy. classe : {{ formatGrade(g.class_average) }}</span>
            <span class="portal-grade-card__bar" aria-hidden="true">
              <span class="portal-grade-card__bar-fill" :style="{ width: `${Math.min(100, ((g.class_average ?? 0) / g.max) * 100)}%` }" />
            </span>
          </div>
        </RouterLink>
      </section>
    </template>
  </section>
</template>

<style scoped>
.portal-dash-notifs {
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
  margin-top: 0.25rem;
}

.portal-dash-notif {
  display: flex;
  align-items: center;
  gap: 0.85rem;
  padding: 0.9rem 1rem;
  background: var(--bg-card);
  border: 1px solid var(--border);
  border-radius: 14px;
  text-decoration: none;
  color: var(--text);
  transition: background 0.15s;
}

.portal-dash-notif:hover {
  background: var(--bg-subtle);
  text-decoration: none;
}

.portal-dash-notif--warn {
  border-color: rgba(var(--warn-rgb, 234,179,8), 0.35);
  background: rgba(var(--warn-rgb, 234,179,8), 0.05);
}

.portal-dash-notif__icon {
  display: grid;
  place-items: center;
  width: 2.4rem;
  height: 2.4rem;
  border-radius: 10px;
  flex-shrink: 0;
}

.portal-dash-notif__icon--grade {
  background: rgba(59,130,246,0.12);
  color: var(--accent);
}

.portal-dash-notif__icon--warn {
  background: rgba(234,179,8,0.12);
  color: var(--warn);
}

.portal-dash-notif__icon svg { width: 1.1rem; height: 1.1rem; }

.portal-dash-notif__body {
  display: flex;
  flex-direction: column;
  gap: 0.1rem;
  flex: 1;
  min-width: 0;
}

.portal-dash-notif__body strong {
  font-size: 0.88rem;
  font-weight: 700;
}

.portal-dash-notif__body span {
  font-size: 0.76rem;
  color: var(--text-soft);
}

.portal-dash-notif__arrow {
  width: 1rem;
  height: 1rem;
  opacity: 0.4;
  flex-shrink: 0;
}

/* ── Notes récentes ── */
.portal-grades {
  display: flex;
  flex-direction: column;
  gap: 0.65rem;
  margin-top: 0.5rem;
}

.portal-grades__head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 0.5rem;
  padding: 0 0.15rem;
}

.portal-grades__head h2 {
  margin: 0;
  font-size: 0.92rem;
  font-weight: 800;
  color: var(--text);
}

.portal-grades__all {
  font-size: 0.76rem;
  font-weight: 700;
  color: var(--accent);
  text-decoration: none;
}

.portal-grade-card {
  display: flex;
  align-items: center;
  gap: 0.85rem;
  padding: 0.85rem 1rem;
  background: var(--bg-card);
  border: 1px solid var(--border);
  border-radius: 16px;
  text-decoration: none;
  color: var(--text);
  transition: background 0.15s, border-color 0.15s;
}

.portal-grade-card:hover {
  background: var(--bg-subtle);
  text-decoration: none;
}

.portal-grade-card__icon {
  display: grid;
  place-items: center;
  width: 2.7rem;
  height: 2.7rem;
  border-radius: 12px;
  flex-shrink: 0;
  background: rgba(59, 130, 246, 0.12);
  color: var(--accent);
}

.portal-grade-card__icon--good { background: rgba(34, 197, 94, 0.12); color: var(--success); }
.portal-grade-card__icon--warn { background: rgba(234, 179, 8, 0.12); color: var(--warn); }
.portal-grade-card__icon--danger { background: rgba(239, 68, 68, 0.12); color: var(--danger); }

.portal-grade-card__icon svg { width: 1.3rem; height: 1.3rem; }

.portal-grade-card__main {
  display: flex;
  flex-direction: column;
  gap: 0.05rem;
  flex: 1;
  min-width: 0;
}

.portal-grade-card__subject {
  font-size: 0.92rem;
  font-weight: 800;
  line-height: 1.2;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.portal-grade-card__teacher {
  font-size: 0.74rem;
  color: var(--text-soft);
}

.portal-grade-card__score {
  display: flex;
  align-items: baseline;
  gap: 0.2rem;
  margin-top: 0.25rem;
}

.portal-grade-card__score strong {
  font-size: 1.35rem;
  font-weight: 900;
  line-height: 1;
}

.portal-grade-card__value--good { color: var(--success); }
.portal-grade-card__value--warn { color: var(--warn); }
.portal-grade-card__value--danger { color: var(--danger); }

.portal-grade-card__max {
  font-size: 0.78rem;
  color: var(--text-muted);
  font-weight: 600;
}

.portal-grade-card__class {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 0.35rem;
  flex-shrink: 0;
  width: 6.2rem;
}

.portal-grade-card__class-label {
  font-size: 0.62rem;
  font-weight: 800;
  letter-spacing: 0.02em;
  text-transform: uppercase;
  color: var(--text-muted);
  text-align: right;
  line-height: 1.2;
}

.portal-grade-card__bar {
  display: block;
  width: 100%;
  height: 0.32rem;
  border-radius: 999px;
  background: var(--bg-subtle);
  overflow: hidden;
}

.portal-grade-card__bar-fill {
  display: block;
  height: 100%;
  border-radius: 999px;
  background: var(--accent);
}
</style>
