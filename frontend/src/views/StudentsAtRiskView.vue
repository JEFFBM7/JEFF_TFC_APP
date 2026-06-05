<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { api, ApiError } from '../api/client'
import Modal from '../components/Modal.vue'
import { useStudentMessageNavigation } from '../composables/useStudentMessageNavigation'
import { useDevCalendarReload } from '../stores/devCalendar'
import { useSchoolYearStore } from '../stores/schoolYear'
import { useAuthStore } from '../stores/auth'
import type { StudentAtRisk, StudentsAtRiskMeta } from '../types'
import { formatAveragePercent } from '../utils/grades'

interface AtRiskResponse {
  data: StudentAtRisk[]
  meta: StudentsAtRiskMeta
}

type FilterType = 'all' | 'absences' | 'lates' | 'low_grade'

const TABS: { value: FilterType; label: string }[] = [
  { value: 'all', label: 'Toutes les alertes' },
  { value: 'absences', label: 'Absences' },
  { value: 'lates', label: 'Retards' },
  { value: 'low_grade', label: 'Notes faibles' },
]

const router = useRouter()
const auth = useAuthStore()
const schoolYearStore = useSchoolYearStore()
const { studentHasPortalAccess, openParentConversation, openStudentConversation } =
  useStudentMessageNavigation()

const messageTargetRow = ref<StudentAtRisk | null>(null)
const messageModalOpen = ref(false)
const messageActionError = ref('')
const activeTab = ref<FilterType>('all')
const allRows = ref<StudentAtRisk[]>([])
const meta = ref<StudentsAtRiskMeta | null>(null)
const loading = ref(false)
const error = ref('')

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    const res = await api<AtRiskResponse>('/api/v1/students-at-risk', {
      query: { type: 'all' },
    })
    allRows.value = res.data
    meta.value = res.meta
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Erreur de chargement.'
  } finally {
    loading.value = false
  }
}

const termChipLabels = computed(() => {
  const terms = meta.value?.terms
  if (terms && Object.keys(terms).length > 0) {
    return Object.values(terms).map((t) => {
      const typeLabel = t.type_label ?? 'Trimestre'
      return `${typeLabel} : ${t.name}`
    })
  }
  if (!meta.value?.term) return []
  const typeLabel = meta.value.term.type_label ?? 'Trimestre'
  return [`${typeLabel} : ${meta.value.term.name}`]
})

onMounted(load)
useDevCalendarReload(() => void load())
watch(() => schoolYearStore.effectiveId, () => void load())

const rows = computed(() => {
  if (activeTab.value === 'absences') return allRows.value.filter((r) => r.triggers.has_absence_alert)
  if (activeTab.value === 'lates') return allRows.value.filter((r) => r.triggers.has_late_alert)
  if (activeTab.value === 'low_grade') return allRows.value.filter((r) => r.triggers.has_low_grade_alert)
  return allRows.value
})

const canConfigure = computed(() => auth.user?.role === 'admin')

const isGlobalAdmin = computed(
  () => auth.user?.role === 'admin' && (auth.user.admin_scope ?? 'global') === 'global',
)

const scopeNotice = computed(() => {
  if (isGlobalAdmin.value) return null
  return meta.value?.admin_scope_label ?? auth.user?.admin_scope_label ?? null
})

const counts = computed(() => {
  const c = { absences: 0, lates: 0, low_grade: 0 }
  for (const r of allRows.value) {
    if (r.triggers.has_absence_alert) c.absences++
    if (r.triggers.has_late_alert) c.lates++
    if (r.triggers.has_low_grade_alert) c.low_grade++
  }
  return c
})

function badgeClasses(row: StudentAtRisk): string[] {
  const list: string[] = []
  if (row.triggers.has_absence_alert) list.push('badge-absence')
  if (row.triggers.has_late_alert) list.push('badge-late')
  if (row.triggers.has_low_grade_alert) list.push('badge-grade')
  return list
}

function openProfile(row: StudentAtRisk): void {
  void router.push({ name: 'student-detail', params: { id: row.id } })
}

function startMessage(row: StudentAtRisk): void {
  messageActionError.value = ''

  if (!studentHasPortalAccess(row)) {
    if (!openParentConversation(row)) {
      messageActionError.value =
        'Aucun parent n’a de compte messagerie pour cet élève. Créez d’abord le compte parent.'
    }
    return
  }

  messageTargetRow.value = row
  messageModalOpen.value = true
}

function closeMessageModal(): void {
  messageModalOpen.value = false
  messageTargetRow.value = null
}

function confirmMessageToParent(): void {
  const row = messageTargetRow.value
  if (!row) return

  messageActionError.value = ''
  if (!openParentConversation(row)) {
    messageActionError.value =
      'Aucun parent n’a de compte messagerie pour cet élève. Créez d’abord le compte parent.'
    return
  }

  closeMessageModal()
}

function confirmMessageToStudent(): void {
  const row = messageTargetRow.value
  if (!row) return

  messageActionError.value = ''
  if (!openStudentConversation(row)) {
    messageActionError.value = 'Compte élève indisponible pour la messagerie.'
    return
  }

  closeMessageModal()
}

function fmtAvg(v: number | null | undefined): string {
  return formatAveragePercent(v, 1)
}
</script>

<template>
  <section class="page">
    <div class="card">
      <div class="card-header">
        <div>
          <h2 class="card-title">Élèves en difficulté</h2>
          <p class="card-subtitle">
            Liste des élèves dépassant un ou plusieurs seuils paramétrés.
            <RouterLink v-if="canConfigure" :to="{ name: 'settings' }" class="settings-link">Configurer les seuils →</RouterLink>
          </p>
          <p v-if="isGlobalAdmin" class="scope-note scope-note--global">
            Tous les cycles (maternel, primaire, secondaire, CTEB) — moyennes calculées par trimestre ou semestre selon la classe de l'élève.
          </p>
          <p v-else-if="scopeNotice" class="scope-note">
            Périmètre {{ scopeNotice }} — uniquement les élèves de votre cycle.
          </p>
        </div>
      </div>

      <div v-if="meta" class="thresholds-bar">
        <span class="threshold-pill">Absences consécutives ≥ {{ meta.thresholds.consecutive }}</span>
        <span class="threshold-pill">
          Absences {{ meta.thresholds.rolling }}/{{ meta.thresholds.rolling_window_days }}j
        </span>
        <span class="threshold-pill">
          Retards {{ meta.thresholds.late }}/{{ meta.thresholds.late_window_days }}j
        </span>
        <span class="threshold-pill">Moyenne &lt; {{ formatAveragePercent(meta.thresholds.low_grade, 0) }}</span>
        <span
          v-for="(label, idx) in termChipLabels"
          :key="idx"
          class="threshold-pill threshold-term"
        >
          {{ label }}
        </span>
      </div>

      <div class="tabs">
        <button
          v-for="t in TABS"
          :key="t.value"
          type="button"
          class="tab"
          :class="{ 'is-active': activeTab === t.value }"
          @click="activeTab = t.value"
        >
          {{ t.label }}
          <span v-if="t.value !== 'all'" class="tab-count">{{ counts[t.value] }}</span>
        </button>
      </div>

      <p v-if="error" class="alert alert-error">{{ error }}</p>
      <p v-if="messageActionError" class="alert alert-error">{{ messageActionError }}</p>
      <div v-if="loading" class="empty-state">Chargement…</div>
      <div v-else-if="rows.length === 0" class="empty-state">Aucun élève en alerte.</div>

      <table v-else>
        <thead>
          <tr>
            <th>Élève</th>
            <th>Classe</th>
            <th>Niveau</th>
            <th>Alertes</th>
            <th class="num">Absences (consécutives)</th>
            <th class="num">Absences (30j)</th>
            <th class="num">Retards</th>
            <th class="num">Moyenne</th>
            <th class="actions-col">Actions</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in rows" :key="row.id">
            <td>
              <button type="button" class="link-button" @click="openProfile(row)">
                {{ row.full_name }}
              </button>
            </td>
            <td>{{ row.classroom ?? '—' }}</td>
            <td>{{ row.level ?? '—' }}</td>
            <td>
              <span
                v-for="cls in badgeClasses(row)"
                :key="cls"
                class="badge"
                :class="cls"
              >
                <template v-if="cls === 'badge-absence'">Absences</template>
                <template v-else-if="cls === 'badge-late'">Retards</template>
                <template v-else>Notes</template>
              </span>
            </td>
            <td class="num">{{ row.triggers.absences_consecutive }}</td>
            <td class="num">{{ row.triggers.absences_rolling }}</td>
            <td class="num">{{ row.triggers.lates }}</td>
            <td class="num" :class="{ 'cell-danger': row.triggers.has_low_grade_alert }">
              {{ fmtAvg(row.average) }}
            </td>
            <td class="actions-col">
              <button type="button" class="action-btn" @click="openProfile(row)">Fiche</button>
              <button type="button" class="action-btn" @click="startMessage(row)">Message</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <Modal
      :open="messageModalOpen"
      title="Envoyer un message"
      :close-on-backdrop="true"
      @close="closeMessageModal"
    >
      <p v-if="messageTargetRow" class="message-target-intro">
        À qui souhaitez-vous écrire au sujet de
        <strong>{{ messageTargetRow.full_name }}</strong> ?
      </p>
      <div class="message-target-actions">
        <button type="button" class="btn-primary message-target-btn" @click="confirmMessageToParent">
          Parent
          <span
            v-if="messageTargetRow?.parent_users?.[0]"
            class="message-target-sub"
          >{{ messageTargetRow.parent_users[0].name }}</span>
        </button>
        <button type="button" class="btn-secondary message-target-btn" @click="confirmMessageToStudent">
          Élève
          <span class="message-target-sub">Compte portail actif</span>
        </button>
      </div>
      <button type="button" class="btn-link message-target-cancel" @click="closeMessageModal">
        Annuler
      </button>
    </Modal>
  </section>
</template>

<style scoped>
.page { display: flex; flex-direction: column; gap: 1rem; }
.card-title { margin: 0; font-size: 1.05rem; font-weight: 800; color: var(--text); }
.card-subtitle { margin: 0.2rem 0 0; color: var(--text-soft); font-size: 0.85rem; }
.scope-note {
  margin: 0.45rem 0 0;
  font-size: 0.82rem;
  font-weight: 600;
  color: var(--primary);
}
.scope-note--global {
  color: var(--text-soft);
  font-weight: 500;
}
.settings-link { font-weight: 700; color: var(--primary); margin-left: 0.5rem; }

.thresholds-bar {
  display: flex;
  flex-wrap: wrap;
  gap: 0.45rem;
  padding: 0.75rem 1rem;
  border-bottom: 1px solid var(--border);
}

.threshold-pill {
  display: inline-flex;
  align-items: center;
  padding: 0.25rem 0.6rem;
  border: 1px solid var(--border);
  border-radius: 999px;
  font-size: 0.77rem;
  font-weight: 700;
  color: var(--text-soft);
  background: #f8fafc;
}
.threshold-term {
  background: var(--primary-soft);
  border-color: #d5e0ff;
  color: var(--primary);
}

.tabs { display: flex; gap: 0.35rem; padding: 0.75rem 1rem; flex-wrap: wrap; }
.tab {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  padding: 0.45rem 0.85rem;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  font-size: 0.85rem;
  font-weight: 700;
  background: var(--bg-card);
  color: var(--text-soft);
  cursor: pointer;
}
.tab:hover { color: var(--primary); border-color: #cfd9ef; }
.tab.is-active { background: var(--primary); color: white; border-color: var(--primary); }
.tab-count {
  background: rgba(255,255,255,0.25);
  border-radius: 999px;
  padding: 0.05rem 0.45rem;
  font-size: 0.7rem;
}
.tab:not(.is-active) .tab-count { background: var(--bg); color: var(--text-soft); }

.num { text-align: right; font-variant-numeric: tabular-nums; }
.actions-col { white-space: nowrap; }
.actions-col .action-btn {
  padding: 0.25rem 0.55rem;
  font-size: 0.78rem;
  margin-right: 0.25rem;
}

.link-button {
  background: none; border: 0; padding: 0;
  color: var(--primary); font-weight: 700; cursor: pointer;
  text-align: left;
}
.link-button:hover { text-decoration: underline; }

.cell-danger { color: var(--danger); font-weight: 700; }

.badge { margin-right: 0.25rem; }
.badge-absence { background: #fee2e2; color: #b91c1c; }
.badge-late { background: #fef3c7; color: #92400e; }
.badge-grade { background: #fde68a; color: #92400e; }

.message-target-intro {
  margin: 0 0 1rem;
  color: var(--text-soft);
  line-height: 1.45;
}

.message-target-actions {
  display: flex;
  flex-direction: column;
  gap: 0.55rem;
}

.message-target-btn {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 0.15rem;
  width: 100%;
  text-align: left;
}

.message-target-sub {
  font-size: 0.78rem;
  font-weight: 500;
  opacity: 0.85;
}

.message-target-cancel {
  margin-top: 0.75rem;
  padding: 0;
  border: none;
  background: none;
  color: var(--text-soft);
  cursor: pointer;
  font-size: 0.85rem;
}

.message-target-cancel:hover {
  color: var(--text);
}
</style>
