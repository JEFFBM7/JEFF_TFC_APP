<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { RouterLink, type RouteLocationRaw } from 'vue-router'
import {
  AlertTriangle,
  Calendar,
  ChevronRight,
  Clock,
  GraduationCap,
  Inbox,
  RefreshCw,
  TrendingUp,
  TrendingDown,
  Minus,
  AlertOctagon,
  Users,
} from 'lucide-vue-next'
import { api, ApiError } from '../api/client'
import { usePortalDashboard } from '../composables/usePortalDashboard'

interface Wellbeing {
  average: number | null
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
    new_grades: Array<{ subject_name: string | null; value: number | null }>
  }
}

const {
  initials,
  todayLabel,
  childColor,
  wellbeingLabel,
  performanceFromAverage,
  avgPercent,
  formatAverage,
} = usePortalDashboard()

const children = ref<ChildSummary[]>([])
const loading = ref(false)
const error = ref('')

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    const res = await api<{ data: ChildSummary[] }>('/api/v1/parent/dashboard')
    children.value = res.data
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Erreur de chargement.'
  } finally {
    loading.value = false
  }
}

function childRoute(studentId: number): RouteLocationRaw {
  return { name: 'parent-child', params: { id: studentId } }
}

function trendIcon(trend: Wellbeing['trend']) {
  if (trend === 'up') return TrendingUp
  if (trend === 'down') return TrendingDown
  return Minus
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

const alertCount = computed(() =>
  children.value.filter((c) => c.recent.attendance_alert?.triggered).length,
)

onMounted(() => {
  void load()
})
</script>

<template>
  <section class="portal-dash portal-mobile">
    <header class="portal-dash-hero portal-dash-animate">
      <div class="portal-dash-hero__identity">
        <div class="portal-dash-hero__avatar" aria-hidden="true">
          <Users />
        </div>
        <div class="portal-dash-hero__text">
          <p class="portal-dash-hero__date">
            <Calendar aria-hidden="true" />
            <span>{{ todayLabel() }}</span>
          </p>
          <h1>Mes enfants</h1>
          <p class="portal-dash-hero__meta">
            <span>
              {{ childCount === 1 ? '1 enfant rattaché' : `${childCount} enfants rattachés` }}
            </span>
            <template v-if="alertCount > 0">
              <span aria-hidden="true">·</span>
              <span class="portal-dash-hero__tag" style="background: var(--danger-soft); color: var(--danger)">
                <AlertTriangle aria-hidden="true" />
                {{ alertCount }} alerte{{ alertCount > 1 ? 's' : '' }}
              </span>
            </template>
          </p>
        </div>
      </div>
      <button
        type="button"
        class="portal-dash-hero__refresh"
        aria-label="Rafraîchir la liste"
        :disabled="loading"
        @click="load"
      >
        <RefreshCw :class="{ 'is-spinning': loading }" aria-hidden="true" />
      </button>
    </header>

    <p v-if="error" class="alert alert-error" role="alert">{{ error }}</p>

    <div v-if="loading && children.length === 0" aria-hidden="true">
      <div class="portal-dash-skeleton" style="min-height: 7rem; margin-bottom: 0.75rem" />
      <div class="portal-dash-children-stack">
        <div v-for="i in 3" :key="i" class="portal-dash-skeleton" style="min-height: 10rem" />
      </div>
    </div>

    <div v-else-if="children.length === 0" class="portal-dash-empty portal-dash-animate">
      <Inbox aria-hidden="true" />
      <h2>Aucun enfant rattaché</h2>
      <p>Contactez le secrétariat pour associer un enfant à votre profil parent.</p>
    </div>

    <template v-else>
      <div
        class="portal-dash-summary-band portal-dash-animate portal-dash-animate--delay-1"
        aria-label="Synthèse familiale"
      >
        <div class="portal-dash-summary-band__item">
          <span>Enfants</span>
          <strong>{{ childCount }}</strong>
        </div>
        <div class="portal-dash-summary-band__item">
          <span>Moyenne</span>
          <strong>{{ formatAverage(globalAverage) }}</strong>
        </div>
        <div class="portal-dash-summary-band__item">
          <span>Absences</span>
          <strong :style="totalAbsences > 0 ? { color: 'var(--warn)' } : undefined">{{ totalAbsences }}</strong>
        </div>
      </div>

      <div class="portal-dash-children-stack portal-dash-animate portal-dash-animate--delay-2" role="list">
        <RouterLink
          v-for="(child, index) in children"
          :key="child.student_id"
          :to="childRoute(child.student_id)"
          class="portal-dash-child-card portal-kpi-link"
          :class="{
            'portal-dash-child-card--alert': child.recent.attendance_alert?.triggered,
          }"
          :style="index > 0 ? { animationDelay: `${0.08 + index * 0.05}s` } : undefined"
          role="listitem"
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
                <GraduationCap aria-hidden="true" />
                {{ child.classroom ?? 'Non affecté' }}
                <template v-if="child.current_term"> · {{ child.current_term }}</template>
              </span>
            </div>
            <span
              v-if="child.wellbeing.trend"
              class="portal-trend"
              :class="`portal-trend--${child.wellbeing.trend}`"
              :title="child.wellbeing.trend === 'up' ? 'En hausse' : child.wellbeing.trend === 'down' ? 'En baisse' : 'Stable'"
            >
              <component :is="trendIcon(child.wellbeing.trend)" aria-hidden="true" />
            </span>
          </div>

          <div class="portal-dash-child-card__progress">
            <div class="portal-dash-child-card__progress-label">
              <span>Moyenne générale</span>
              <strong>
                {{ formatAverage(child.current_average) }}
              </strong>
            </div>
            <span class="portal-dash-child-card__track" aria-hidden="true">
              <span
                class="portal-dash-child-card__fill"
                :class="`portal-dash-child-card__fill--${performanceFromAverage(child.current_average).tone}`"
                :style="{ width: `${avgPercent(child.current_average)}%` }"
              />
            </span>
          </div>

          <div
            v-if="child.recent.attendance_alert?.triggered || child.recent.unjustified_absences_count > 0 || child.total_lates > 0"
            class="portal-dash-child-card__flags"
          >
            <span
              v-if="child.recent.attendance_alert?.triggered"
              class="portal-dash-child-card__flag portal-dash-child-card__flag--danger"
            >
              <AlertOctagon aria-hidden="true" />
              Alerte absentéisme
            </span>
            <span
              v-if="child.recent.unjustified_absences_count > 0"
              class="portal-dash-child-card__flag portal-dash-child-card__flag--warn"
            >
              <AlertTriangle aria-hidden="true" />
              {{ child.recent.unjustified_absences_count }} non justifiée{{ child.recent.unjustified_absences_count > 1 ? 's' : '' }}
            </span>
            <span
              v-if="child.total_lates > 0"
              class="portal-dash-child-card__flag portal-dash-child-card__flag--warn"
            >
              <Clock aria-hidden="true" />
              {{ child.total_lates }} retard{{ child.total_lates > 1 ? 's' : '' }}
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
            Bulletin, absences et détail
            <ChevronRight aria-hidden="true" />
          </span>
        </RouterLink>
      </div>

      <p class="portal-dash-children-hint portal-dash-animate portal-dash-animate--delay-3">
        Appuyez sur une fiche pour consulter le bulletin, l'historique des absences et les évaluations à venir.
      </p>
    </template>
  </section>
</template>

<style scoped>
.portal-dash-hero__avatar svg {
  width: 1.35rem;
  height: 1.35rem;
}

.portal-dash-children-hint {
  margin: 0;
  padding: 0 0.15rem;
  color: var(--text-muted);
  font-size: 0.78rem;
  font-weight: 650;
  line-height: 1.4;
  text-align: center;
}
</style>
