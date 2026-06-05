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
} from 'lucide-vue-next'

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

        <RouterLink
          :to="{ name: 'student-timetable' }"
          class="portal-dash-kpi portal-kpi-link"
          aria-label="Voir mon emploi du temps"
        >
          <div class="portal-dash-kpi__head">
            <span class="portal-dash-kpi__label">Ma classe</span>
            <span class="portal-dash-kpi__icon"><GraduationCap aria-hidden="true" /></span>
          </div>
          <strong class="portal-dash-kpi__value portal-dash-kpi__value--sm">{{ summary.classroom ?? '—' }}</strong>
          <span class="portal-dash-kpi__note">Emploi du temps · {{ summary.current_term ?? 'Année en cours' }}</span>
          <ArrowRight class="portal-dash-kpi__hint" aria-hidden="true" />
        </RouterLink>
      </div>
    </template>
  </section>
</template>
