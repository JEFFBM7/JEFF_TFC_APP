<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { RouterLink, type RouteLocationRaw } from 'vue-router'
import {
  RefreshCw,
  Calendar,
  Users,
  Award,
  AlertTriangle,
  Clock,
  ArrowRight,
  Inbox,
  MessageSquare,
  TrendingUp,
  TrendingDown,
  Minus,
  AlertOctagon,
  ChevronRight,
} from 'lucide-vue-next'
import { api, ApiError } from '../api/client'
import { useAuthStore } from '../stores/auth'
import { usePortalDashboard } from '../composables/usePortalDashboard'

interface Wellbeing {
  average: number | null
  previous_average: number | null
  trend: 'up' | 'down' | 'stable' | null
  term_name: string | null
  class_rank: number | null
  class_size: number
  status: string
}

interface ChildSummary {
  student_id: number
  first_name: string | null
  full_name: string
  classroom: string | null
  total_absences: number
  total_lates: number
  current_average: number | null
  current_term: string | null
  wellbeing: Wellbeing
  recent: {
    attendance_alert: { triggered: boolean; consecutive: number; count_recent_30d: number }
    unjustified_absences_count: number
    new_grades: Array<{
      subject_name: string | null
      evaluation_name: string | null
      value: number | null
      held_on: string | null
    }>
  }
  upcoming: {
    evaluations: Array<{
      name: string
      subject_name: string | null
      held_on: string | null
      type_label: string | null
    }>
    justification_deadlines: Array<{ date: string | null; label: string }>
  }
}

interface DashboardAlert {
  id: string
  tone: 'danger' | 'warn' | 'info'
  title: string
  detail: string
  to: RouteLocationRaw
}

const auth = useAuthStore()
const {
  initials,
  greeting,
  todayLabel,
  childColor,
  wellbeingLabel,
  formatAverage,
  formatShortDate,
} = usePortalDashboard()

const children = ref<ChildSummary[]>([])
const unreadMessages = ref(0)
const loading = ref(false)
const error = ref('')

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    const res = await api<{ data: ChildSummary[]; unread_messages: number }>('/api/v1/parent/dashboard')
    children.value = res.data
    unreadMessages.value = res.unread_messages ?? 0
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Erreur de chargement.'
  } finally {
    loading.value = false
  }
}

const childCount = computed(() => children.value.length)

const globalAverage = computed(() => {
  const values = children.value
    .map((c) => c.current_average)
    .filter((avg): avg is number => avg !== null)
  if (values.length === 0) return null
  return Math.round((values.reduce((s, v) => s + v, 0) / values.length) * 100) / 100
})

const totalAbsences = computed(() =>
  children.value.reduce((sum, c) => sum + c.total_absences, 0),
)

const totalLates = computed(() =>
  children.value.reduce((sum, c) => sum + c.total_lates, 0),
)

const bulletinRoute = computed((): RouteLocationRaw => {
  if (children.value.length === 1) {
    return { name: 'parent-child', params: { id: children.value[0].student_id } }
  }
  return { name: 'parent-children' }
})

const priorityAlerts = computed((): DashboardAlert[] => {
  const alerts: DashboardAlert[] = []

  for (const child of children.value) {
    if (child.recent.attendance_alert?.triggered) {
      alerts.push({
        id: `alert-${child.student_id}`,
        tone: 'danger',
        title: `Alerte absentéisme — ${child.first_name ?? child.full_name}`,
        detail: `${child.recent.attendance_alert.consecutive} absences consécutives · ${child.recent.attendance_alert.count_recent_30d} sur 30 jours`,
        to: { name: 'parent-child', params: { id: child.student_id } },
      })
    }
    if (child.recent.unjustified_absences_count > 0) {
      alerts.push({
        id: `unjust-${child.student_id}`,
        tone: 'warn',
        title: `${child.recent.unjustified_absences_count} absence${child.recent.unjustified_absences_count > 1 ? 's' : ''} non justifiée${child.recent.unjustified_absences_count > 1 ? 's' : ''}`,
        detail: child.full_name,
        to: { name: 'parent-child', params: { id: child.student_id } },
      })
    }
    for (const deadline of child.upcoming.justification_deadlines) {
      alerts.push({
        id: `deadline-${child.student_id}-${deadline.date}`,
        tone: 'info',
        title: deadline.label,
        detail: `${child.full_name} · ${formatShortDate(deadline.date)}`,
        to: { name: 'parent-child', params: { id: child.student_id } },
      })
    }
  }

  if (unreadMessages.value > 0) {
    alerts.push({
      id: 'messages',
      tone: 'info',
      title: `${unreadMessages.value} message${unreadMessages.value > 1 ? 's' : ''} non lu${unreadMessages.value > 1 ? 's' : ''}`,
      detail: 'Consulter la messagerie',
      to: { name: 'messages' },
    })
  }

  return alerts.slice(0, 4)
})

const recentActivity = computed(() => {
  const items: Array<{ id: string; tone: string; title: string; meta: string }> = []

  for (const child of children.value) {
    for (const grade of child.recent.new_grades.slice(0, 2)) {
      items.push({
        id: `grade-${child.student_id}-${grade.evaluation_name}`,
        tone: 'success',
        title: `${child.first_name ?? child.full_name} — ${grade.subject_name ?? 'Matière'}`,
        meta: grade.value !== null
          ? `Note ${grade.value.toFixed(1)}/20 · ${formatShortDate(grade.held_on)}`
          : formatShortDate(grade.held_on),
      })
    }
    for (const ev of child.upcoming.evaluations.slice(0, 1)) {
      items.push({
        id: `eval-${child.student_id}-${ev.name}`,
        tone: 'warn',
        title: `Évaluation à venir — ${child.first_name ?? child.full_name}`,
        meta: `${ev.subject_name ?? ev.name} · ${formatShortDate(ev.held_on)}`,
      })
    }
  }

  return items.slice(0, 5)
})

function childRoute(id: number): RouteLocationRaw {
  return { name: 'parent-child', params: { id } }
}

function trendIcon(trend: Wellbeing['trend']) {
  if (trend === 'up') return TrendingUp
  if (trend === 'down') return TrendingDown
  return Minus
}

function trendLabel(trend: Wellbeing['trend']): string {
  if (trend === 'up') return 'En hausse'
  if (trend === 'down') return 'En baisse'
  if (trend === 'stable') return 'Stable'
  return ''
}

onMounted(() => {
  void load()
})
</script>

<template>
  <section class="portal-dash portal-mobile">
    <header class="portal-dash-hero portal-dash-animate">
      <div class="portal-dash-hero__identity">
        <div class="portal-dash-hero__avatar" aria-hidden="true">{{ initials(auth.user?.name ?? '') }}</div>
        <div class="portal-dash-hero__text">
          <p class="portal-dash-hero__date">
            <Calendar aria-hidden="true" />
            <span>{{ todayLabel() }}</span>
          </p>
          <h1>{{ greeting() }}, {{ auth.user?.name }}</h1>
          <p class="portal-dash-hero__meta">
            <span>Portail parent</span>
            <template v-if="childCount > 0">
              <span aria-hidden="true">·</span>
              <span class="portal-dash-hero__tag">
                <Users aria-hidden="true" />
                {{ childCount }} enfant{{ childCount > 1 ? 's' : '' }} suivi{{ childCount > 1 ? 's' : '' }}
              </span>
            </template>
          </p>
        </div>
      </div>
      <button
        type="button"
        class="portal-dash-hero__refresh"
        aria-label="Rafraîchir le tableau de bord"
        :disabled="loading"
        @click="load"
      >
        <RefreshCw :class="{ 'is-spinning': loading }" aria-hidden="true" />
      </button>
    </header>

    <p v-if="error" class="alert alert-error" role="alert">{{ error }}</p>

    <div v-if="loading && children.length === 0" aria-hidden="true">
      <div class="portal-dash-skeleton" style="min-height: 7rem; margin-bottom: 0.75rem" />
      <div class="portal-dash-kpis">
        <div v-for="i in 4" :key="i" class="portal-dash-skeleton" style="min-height: 6.75rem" />
      </div>
    </div>

    <div v-else-if="childCount === 0" class="portal-dash-empty portal-dash-animate">
      <Inbox aria-hidden="true" />
      <h2>Aucun enfant rattaché</h2>
      <p>
        Votre profil parent n'a pas encore d'enfants associés. Contactez le secrétariat pour effectuer le rattachement.
      </p>
    </div>

    <template v-else>
      <div v-if="priorityAlerts.length" class="portal-dash-alerts portal-dash-animate portal-dash-animate--delay-1" role="region" aria-label="Alertes prioritaires">
        <RouterLink
          v-for="alert in priorityAlerts"
          :key="alert.id"
          :to="alert.to"
          class="portal-dash-alert portal-kpi-link"
          :class="`portal-dash-alert--${alert.tone}`"
        >
          <AlertOctagon v-if="alert.tone === 'danger'" class="portal-dash-alert__icon" aria-hidden="true" />
          <AlertTriangle v-else-if="alert.tone === 'warn'" class="portal-dash-alert__icon" aria-hidden="true" />
          <MessageSquare v-else class="portal-dash-alert__icon" aria-hidden="true" />
          <div class="portal-dash-alert__body">
            <strong>{{ alert.title }}</strong>
            <span>{{ alert.detail }}</span>
          </div>
          <ArrowRight class="portal-dash-alert__arrow" aria-hidden="true" />
        </RouterLink>
      </div>

      <div class="portal-dash-kpis portal-dash-animate portal-dash-animate--delay-2" aria-label="Indicateurs globaux">
        <RouterLink :to="{ name: 'parent-children' }" class="portal-dash-kpi portal-kpi-link">
          <div class="portal-dash-kpi__head">
            <span class="portal-dash-kpi__label">Enfants</span>
            <span class="portal-dash-kpi__icon"><Users aria-hidden="true" /></span>
          </div>
          <strong class="portal-dash-kpi__value">{{ childCount }}</strong>
          <span class="portal-dash-kpi__note">Voir le détail</span>
          <ArrowRight class="portal-dash-kpi__hint" aria-hidden="true" />
        </RouterLink>

        <RouterLink :to="bulletinRoute" class="portal-dash-kpi portal-kpi-link">
          <div class="portal-dash-kpi__head">
            <span class="portal-dash-kpi__label">Moyenne globale</span>
            <span class="portal-dash-kpi__icon"><Award aria-hidden="true" /></span>
          </div>
          <strong class="portal-dash-kpi__value">{{ formatAverage(globalAverage) }}</strong>
          <span class="portal-dash-kpi__note">Sur 20 · période en cours</span>
        </RouterLink>

        <RouterLink
          :to="bulletinRoute"
          class="portal-dash-kpi portal-kpi-link"
          :class="{ 'portal-dash-kpi--warn': totalAbsences > 0 }"
        >
          <div class="portal-dash-kpi__head">
            <span class="portal-dash-kpi__label">Absences</span>
            <span class="portal-dash-kpi__icon"><AlertTriangle aria-hidden="true" /></span>
          </div>
          <strong class="portal-dash-kpi__value">{{ totalAbsences }}</strong>
          <span class="portal-dash-kpi__note">Cumul année scolaire</span>
        </RouterLink>

        <RouterLink
          :to="bulletinRoute"
          class="portal-dash-kpi portal-kpi-link"
          :class="{ 'portal-dash-kpi--warn': totalLates > 0 }"
        >
          <div class="portal-dash-kpi__head">
            <span class="portal-dash-kpi__label">Retards</span>
            <span class="portal-dash-kpi__icon"><Clock aria-hidden="true" /></span>
          </div>
          <strong class="portal-dash-kpi__value">{{ totalLates }}</strong>
          <span class="portal-dash-kpi__note">Cumul année scolaire</span>
        </RouterLink>
      </div>

      <section class="portal-dash-section portal-dash-animate portal-dash-animate--delay-2" aria-labelledby="children-heading">
        <div class="portal-dash-section__head">
          <h2 id="children-heading">Suivi par enfant</h2>
          <RouterLink :to="{ name: 'parent-children' }">Tout voir</RouterLink>
        </div>
        <div class="portal-dash-section" style="gap: 0.55rem">
          <RouterLink
            v-for="child in children"
            :key="child.student_id"
            :to="childRoute(child.student_id)"
            class="portal-dash-child-card portal-kpi-link"
            :aria-label="`Suivi de ${child.full_name}`"
          >
            <div class="portal-dash-child-card__top">
              <span
                class="portal-dash-child-card__avatar"
                :style="{ background: childColor(child.student_id) }"
                aria-hidden="true"
              >{{ initials(child.full_name) }}</span>
              <div class="portal-dash-child-card__info">
                <div class="portal-dash-child-card__name-row">
                  <span class="portal-dash-child-card__name">{{ child.full_name }}</span>
                  <span
                    class="portal-status-pill"
                    :class="`portal-status-pill--${wellbeingLabel(child.wellbeing.status).tone}`"
                  >
                    {{ wellbeingLabel(child.wellbeing.status).label }}
                  </span>
                </div>
                <span class="portal-dash-child-card__class">
                  <Users aria-hidden="true" />
                  {{ child.classroom ?? 'Non affecté' }}
                  <template v-if="child.current_term"> · {{ child.current_term }}</template>
                </span>
                <span class="portal-dash-child-card__avg">
                  Moyenne <strong>{{ formatAverage(child.current_average) }}</strong>
                </span>
              </div>
              <span
                v-if="child.wellbeing.trend"
                class="portal-trend"
                :class="`portal-trend--${child.wellbeing.trend}`"
                :title="trendLabel(child.wellbeing.trend)"
              >
                <component :is="trendIcon(child.wellbeing.trend)" aria-hidden="true" />
              </span>
            </div>
            <dl class="portal-dash-child-card__stats">
              <div
                class="portal-dash-child-card__stat"
                :class="{
                  'portal-dash-child-card__stat--warn': child.total_absences > 0,
                  'portal-dash-child-card__stat--danger': child.recent.attendance_alert?.triggered,
                }"
              >
                <dt>Absences</dt>
                <dd>{{ child.total_absences }}</dd>
              </div>
              <div
                class="portal-dash-child-card__stat"
                :class="{ 'portal-dash-child-card__stat--warn': child.total_lates > 0 }"
              >
                <dt>Retards</dt>
                <dd>{{ child.total_lates }}</dd>
              </div>
              <div class="portal-dash-child-card__stat">
                <dt>Classement</dt>
                <dd>
                  <template v-if="child.wellbeing.class_rank && child.wellbeing.class_size">
                    {{ child.wellbeing.class_rank }}/{{ child.wellbeing.class_size }}
                  </template>
                  <template v-else>—</template>
                </dd>
              </div>
            </dl>
            <span class="portal-dash-child-card__foot">
              Voir le bulletin et les absences
              <ChevronRight aria-hidden="true" />
            </span>
          </RouterLink>
        </div>
      </section>

      <section
        v-if="recentActivity.length"
        class="portal-dash-section portal-dash-animate portal-dash-animate--delay-3"
        aria-labelledby="activity-heading"
      >
        <div class="portal-dash-section__head">
          <h2 id="activity-heading">Activité récente</h2>
        </div>
        <div class="portal-dash-feed">
          <div v-for="item in recentActivity" :key="item.id" class="portal-dash-feed__item">
            <span class="portal-dash-feed__dot" :class="`portal-dash-feed__dot--${item.tone}`" aria-hidden="true" />
            <div class="portal-dash-feed__copy">
              <strong>{{ item.title }}</strong>
              <span>{{ item.meta }}</span>
            </div>
          </div>
        </div>
      </section>
    </template>
  </section>
</template>
