<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { ArrowLeft, CheckCircle2, Users, BarChart3, Save } from 'lucide-vue-next'
import { api, ApiError } from '../api/client'
import type { ApiResource, Evaluation, GradeRow } from '../types'
import { useAuthStore } from '../stores/auth'

const props = defineProps<{ id: string | number }>()
const auth = useAuthStore()

const evaluation = ref<Evaluation | null>(null)
const rows = ref<GradeRow[]>([])
const loading = ref(false)
const saving = ref(false)
const publishing = ref(false)
const error = ref('')
const success = ref('')

const isAdmin = computed(() => auth.user?.role === 'admin')
const isExam = computed(() => evaluation.value?.type === 'examen')

const maxValue = computed(() => evaluation.value?.max_value ?? 20)

const evaluationTypeLabel = computed(() => {
  const item = evaluation.value
  if (!item) return ''
  if (item.type_label) return item.type_label
  const labels: Record<string, string> = {
    examen: 'Examen de période',
    devoir: 'Devoir',
    oral: 'Oral',
    projet: 'Projet',
    controle: 'Interrogation',
    interrogation: 'Interrogation',
  }
  return labels[item.type] ?? item.type
})

const gradedCount = computed(() =>
  rows.value.filter((row) => row.absent || (row.value !== null && row.value !== undefined)).length,
)

const absentCount = computed(() => rows.value.filter((row) => row.absent).length)

const averageGrade = computed(() => {
  const values = rows.value
    .filter((row) => !row.absent && row.value !== null && row.value !== undefined)
    .map((row) => Number(row.value))
    .filter((value) => !Number.isNaN(value))
  if (values.length === 0) return null
  const sum = values.reduce((acc, value) => acc + value, 0)
  return Math.round((sum / values.length) * 100) / 100
})

const progressPct = computed(() => {
  if (rows.value.length === 0) return 0
  return Math.round((gradedCount.value / rows.value.length) * 100)
})

const roleHint = computed(() => {
  if (!evaluation.value) return null
  if (isExam.value && !isAdmin.value) {
    return 'Cet examen de période a été planifié par l’administration. Vous pouvez saisir les notes des élèves.'
  }
  if (!isExam.value && isAdmin.value) {
    return 'Évaluation du contrôle continu créée par un enseignant. Vous pouvez consulter et saisir les notes.'
  }
  return null
})

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    const [evalRes, gradesRes] = await Promise.all([
      api<ApiResource<Evaluation>>(`/api/v1/evaluations/${props.id}`),
      api<{ data: GradeRow[] }>(`/api/v1/evaluations/${props.id}/grades`),
    ])
    evaluation.value = evalRes.data
    rows.value = gradesRes.data
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Erreur de chargement.'
  } finally {
    loading.value = false
  }
}

async function save(): Promise<void> {
  if (!evaluation.value) return
  saving.value = true
  error.value = ''
  success.value = ''

  const max = evaluation.value.max_value ?? 20
  for (const r of rows.value) {
    if (!r.absent && r.value !== null && r.value !== undefined) {
      const v = Number(r.value)
      if (Number.isNaN(v) || v < 0 || v > max) {
        error.value = `Une note doit être comprise entre 0 et ${max} (élève : ${r.student?.full_name}).`
        saving.value = false
        return
      }
    }
  }

  try {
    await api(`/api/v1/evaluations/${evaluation.value.id}/grades`, {
      method: 'POST',
      body: {
        grades: rows.value.map((r) => ({
          student_id: r.student_id,
          value: r.absent ? null : (r.value === null || r.value === undefined ? null : Number(r.value)),
          absent: r.absent,
        })),
      },
    })
    success.value = evaluation.value.is_published
      ? 'Notes enregistrées avec succès.'
      : 'Notes enregistrées. Publiez l’évaluation pour que les parents et l’élève voient les résultats.'
    await load()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Enregistrement impossible.'
  } finally {
    saving.value = false
    setTimeout(() => (success.value = ''), 3000)
  }
}

async function publish(): Promise<void> {
  if (!evaluation.value) return
  publishing.value = true
  error.value = ''
  try {
    await api(`/api/v1/evaluations/${evaluation.value.id}/publish`, { method: 'POST' })
    success.value = 'Évaluation publiée.'
    await load()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Erreur lors de la publication.'
  } finally {
    publishing.value = false
    setTimeout(() => (success.value = ''), 3000)
  }
}

async function unpublish(): Promise<void> {
  if (!evaluation.value) return
  publishing.value = true
  error.value = ''
  try {
    await api(`/api/v1/evaluations/${evaluation.value.id}/unpublish`, { method: 'POST' })
    success.value = 'Évaluation dépubliée.'
    await load()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Erreur lors de la dépublication.'
  } finally {
    publishing.value = false
    setTimeout(() => (success.value = ''), 3000)
  }
}

onMounted(load)
</script>

<template>
  <section class="grade-entry-page">
    <RouterLink :to="{ name: 'evaluations' }" class="back-link">
      <ArrowLeft aria-hidden="true" />
      Retour aux évaluations
    </RouterLink>

    <p v-if="error" class="alert alert-error">{{ error }}</p>
    <p v-if="success" class="alert alert-success">{{ success }}</p>

    <template v-if="evaluation">
      <header class="eval-header card">
        <div class="eval-header-main">
          <div class="eval-badges">
            <span class="badge" :class="evaluation.is_published ? 'badge-published' : 'badge-draft'">
              <CheckCircle2 v-if="evaluation.is_published" aria-hidden="true" />
              {{ evaluation.is_published ? 'Publié' : 'Brouillon' }}
            </span>
            <span class="badge" :class="isExam ? 'badge-exam' : 'badge-continuous'">
              {{ evaluationTypeLabel }}
            </span>
            <span class="component-chip">{{ isExam ? 'Composante examen · 60 %' : 'Contrôle continu · 40 %' }}</span>
          </div>
          <h1>{{ evaluation.name }}</h1>
          <p class="eval-context">
            {{ evaluation.classroom?.full_name }}
            · {{ evaluation.subject?.name }}
            · {{ evaluation.held_on }}
            · Note sur {{ maxValue }}
          </p>
        </div>

        <div class="header-actions">
          <template v-if="!evaluation.is_published">
            <button type="button" class="btn-secondary" :disabled="publishing" @click="publish">
              {{ publishing ? 'Publication…' : 'Publier' }}
            </button>
          </template>
          <template v-else-if="isAdmin">
            <button type="button" class="btn-secondary" :disabled="publishing" @click="unpublish">
              Dépublier
            </button>
          </template>
          <button type="button" class="btn-primary save-btn" :disabled="saving || rows.length === 0" @click="save">
            <Save aria-hidden="true" />
            {{ saving ? 'Enregistrement…' : 'Enregistrer' }}
          </button>
        </div>
      </header>

      <div v-if="roleHint" class="role-hint" :class="isExam ? 'exam' : 'continuous'">
        {{ roleHint }}
      </div>

      <div
        v-if="!evaluation.is_published && !isAdmin"
        class="publish-notice"
        role="status"
      >
        <strong>Visibilité parents / élève</strong>
        <p>
          Les notes enregistrées restent en <em>brouillon</em> tant que vous n’avez pas cliqué sur
          <strong>Publier</strong>. C’est pour cela que le suivi enfant affiche encore « Non évalué ».
        </p>
      </div>

      <div class="stats-row">
        <article class="stat-card">
          <Users aria-hidden="true" />
          <div>
            <span>Élèves</span>
            <strong>{{ rows.length }}</strong>
          </div>
        </article>
        <article class="stat-card">
          <CheckCircle2 aria-hidden="true" />
          <div>
            <span>Notes saisies</span>
            <strong>{{ gradedCount }} / {{ rows.length }}</strong>
          </div>
        </article>
        <article class="stat-card">
          <BarChart3 aria-hidden="true" />
          <div>
            <span>Moyenne</span>
            <strong>{{ averageGrade !== null ? averageGrade + ' / ' + maxValue : '—' }}</strong>
          </div>
        </article>
        <article class="stat-card warn" v-if="absentCount > 0">
          <span>Absents</span>
          <strong>{{ absentCount }}</strong>
        </article>
      </div>

      <div class="progress-bar" role="progressbar" :aria-valuenow="progressPct" aria-valuemin="0" aria-valuemax="100">
        <div class="progress-fill" :style="{ width: progressPct + '%' }" />
        <span class="progress-label">{{ progressPct }} % complété</span>
      </div>

      <div class="card grades-card">
        <div v-if="loading" class="empty-state">Chargement…</div>
        <div v-else-if="rows.length === 0" class="empty-state">
          Aucun élève dans cette classe. Affectez d’abord les élèves à la classe.
        </div>

        <div v-else class="table-wrap">
          <table class="grades-table">
            <thead>
              <tr>
                <th>#</th>
                <th>Élève</th>
                <th class="grade-col">Note / {{ maxValue }}</th>
                <th class="absent-col">Absent</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="(r, index) in rows"
                :key="r.student_id"
                :class="{ absent: r.absent, filled: !r.absent && r.value !== null && r.value !== undefined }"
              >
                <td class="index-col">{{ index + 1 }}</td>
                <td>
                  <strong>{{ r.student?.full_name }}</strong>
                </td>
                <td class="grade-col">
                  <input
                    v-model.number="r.value"
                    type="number"
                    step="0.25"
                    min="0"
                    :max="maxValue"
                    :disabled="r.absent"
                    placeholder="—"
                    :aria-label="'Note pour ' + (r.student?.full_name ?? 'élève')"
                  />
                </td>
                <td class="absent-col">
                  <label class="absent-toggle">
                    <input
                      v-model="r.absent"
                      type="checkbox"
                      @change="r.absent && (r.value = null)"
                    />
                    <span>Absent</span>
                  </label>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>
  </section>
</template>

<style scoped>
.grade-entry-page {
  display: flex;
  flex-direction: column;
  gap: 0.85rem;
}

.back-link {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  color: var(--text-soft);
  text-decoration: none;
  font-size: 0.88rem;
  font-weight: 650;
}

.back-link :deep(svg) {
  width: 1rem;
  height: 1rem;
}

.back-link:hover {
  color: var(--primary);
}

.alert-success {
  padding: 0.65rem 0.85rem;
  border-radius: var(--radius);
  background: var(--success-soft, var(--success-soft));
  color: var(--success, #039855);
  font-size: 0.9rem;
}

.eval-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
  flex-wrap: wrap;
  padding: 1rem 1.1rem;
}

.eval-badges {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
  align-items: center;
  margin-bottom: 0.45rem;
}

.eval-header h1 {
  margin: 0;
  font-size: 1.35rem;
  font-weight: 800;
}

.eval-context {
  margin: 0.35rem 0 0;
  color: var(--text-soft);
  font-size: 0.88rem;
}

.component-chip {
  font-size: 0.76rem;
  font-weight: 700;
  color: var(--text-muted);
}

.header-actions {
  display: flex;
  gap: 0.5rem;
  align-items: center;
  flex-wrap: wrap;
}

.save-btn {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
}

.save-btn :deep(svg) {
  width: 1rem;
  height: 1rem;
}

.role-hint {
  padding: 0.65rem 0.85rem;
  border-radius: var(--radius);
  font-size: 0.84rem;
  line-height: 1.45;
}

.role-hint.exam {
  background: var(--primary-soft);
  color: var(--accent);
  border: 1px solid var(--primary-tint);
}

.role-hint.continuous {
  background: var(--success-soft);
  color: var(--success);
  border: 1px solid rgba(74, 222, 128, 0.3);
}

.stats-row {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(9rem, 1fr));
  gap: 0.65rem;
}

.stat-card {
  display: flex;
  align-items: center;
  gap: 0.65rem;
  padding: 0.75rem 0.85rem;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: var(--bg-card);
}

.stat-card :deep(svg) {
  width: 1.15rem;
  height: 1.15rem;
  color: var(--primary);
  flex-shrink: 0;
}

.stat-card span {
  display: block;
  font-size: 0.72rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--text-muted);
}

.stat-card strong {
  font-size: 1.1rem;
  font-weight: 800;
}

.stat-card.warn {
  border-color: rgba(251, 191, 36, 0.3);
  background: var(--warn-soft);
}

.progress-bar {
  position: relative;
  height: 0.55rem;
  border-radius: 999px;
  background: var(--bg-subtle);
  border: 1px solid var(--border);
  overflow: hidden;
}

.progress-fill {
  height: 100%;
  background: linear-gradient(90deg, var(--primary), #60a5fa);
  transition: width 0.25s ease;
}

.progress-label {
  position: absolute;
  right: 0;
  top: calc(100% + 0.25rem);
  font-size: 0.72rem;
  color: var(--text-muted);
  font-weight: 650;
}

.grades-card {
  margin-top: 0.35rem;
  overflow: hidden;
}

.table-wrap {
  overflow-x: auto;
}

.grades-table {
  width: 100%;
  min-width: 32rem;
}

.grades-table th {
  font-size: 0.72rem;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--text-muted);
}

.index-col {
  width: 2.5rem;
  color: var(--text-muted);
  font-size: 0.82rem;
}

.grade-col {
  width: 9rem;
}

.absent-col {
  width: 7rem;
  text-align: center;
}

.grades-table tr.filled {
  background: var(--success-soft);
}

.grades-table tr.absent {
  background: var(--warn-soft);
}

.grades-table input[type='number'] {
  width: 100%;
  padding: 0.35rem 0.5rem;
  font-size: 0.92rem;
  font-weight: 700;
}

.absent-toggle {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  cursor: pointer;
  font-size: 0.82rem;
  font-weight: 650;
}

.absent-toggle input {
  width: auto;
}

.badge-published,
.badge-draft,
.badge-exam,
.badge-continuous {
  display: inline-flex;
  align-items: center;
  gap: 0.2rem;
  border-radius: 999px;
  padding: 2px 8px;
  font-size: 0.72rem;
  font-weight: 700;
}

.badge-published { background: var(--success-soft); color: var(--success); }
.badge-draft { background: var(--warn-soft); color: var(--warn); }
.badge-exam { background: var(--primary-soft); color: var(--accent); }
.badge-continuous { background: var(--success-soft); color: var(--success); }

.badge-published :deep(svg) {
  width: 0.85rem;
  height: 0.85rem;
}

.publish-notice {
  margin-bottom: 0.85rem;
  padding: 0.75rem 0.9rem;
  border-radius: var(--radius);
  border: 1px solid rgba(251, 191, 36, 0.3);
  background: var(--warn-soft);
  color: var(--warn);
}

.publish-notice strong {
  display: block;
  margin-bottom: 0.25rem;
  font-size: 0.88rem;
}

.publish-notice p {
  margin: 0;
  font-size: 0.84rem;
  line-height: 1.45;
}

.empty-state {
  padding: 2rem 1rem;
  text-align: center;
  color: var(--text-soft);
}

@media (max-width: 640px) {
  .eval-header {
    flex-direction: column;
  }

  .header-actions {
    width: 100%;
  }

  .header-actions .btn-primary,
  .header-actions .btn-secondary {
    flex: 1;
  }
}
</style>
