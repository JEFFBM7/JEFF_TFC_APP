<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { api, ApiError } from '../../api/client'
import { useConfirmStore } from '../../stores/confirm'
import type { ApiResource, Paginated, SchoolYear } from '../../types'

const props = defineProps<{ fromYear: SchoolYear }>()
const emit = defineEmits<{ (e: 'committed'): void }>()

type Decision = 'promu' | 'redouble' | 'diplome' | 'skip'

interface PromotionStudentRow {
  enrollment_id: number
  student: { id: number; full_name: string | null; registration_number: string | null }
  current_classroom: { id: number | null; full_name: string | null }
  result_average: number | null
  suggested_decision: 'promu' | 'redouble' | null
  resolution_status: 'ok' | 'graduate' | 'needs_option' | 'needs_class'
  target_level: { id: number; name: string } | null
  target_classroom_id: number | null
  warnings: string[]
  already_enrolled_classroom_id: number | null
}

interface PromotionClassroom {
  id: number
  full_name: string
  level_id: number | null
  capacity: number
  enrolled: number
}

interface PromotionPreview {
  threshold: number
  from_school_year: { id: number; name: string }
  to_school_year: { id: number; name: string }
  summary: { total: number; promote: number; repeat: number; graduate: number; to_review: number; already_promoted: number }
  students: PromotionStudentRow[]
  available_classrooms: PromotionClassroom[]
}

interface CommittedBatch {
  id: number
  promoted_count: number
  repeated_count: number
  graduated_count: number
  status: string
}

const confirmDialog = useConfirmStore()

const targetYears = ref<SchoolYear[]>([])
const toYearId = ref<number | null>(null)
const preview = ref<PromotionPreview | null>(null)
const committedBatch = ref<CommittedBatch | null>(null)

const loadingYears = ref(false)
const loadingPreview = ref(false)
const committing = ref(false)
const rollingBack = ref(false)
const error = ref('')

/** Décisions éditables, indexées par enrollment_id de l'inscription source. */
const decisions = reactive<Record<number, { decision: Decision; target_classroom_id: number | null }>>({})

const DECISION_LABELS: Record<Decision, string> = {
  promu: 'Passe en classe supérieure',
  redouble: 'Redouble',
  diplome: 'Diplômé (fin de cycle)',
  skip: 'Ne pas traiter',
}

const toYear = computed(() => targetYears.value.find((y) => y.id === toYearId.value) ?? null)

const canCommit = computed(
  () => preview.value !== null && committedBatch.value === null && toYearId.value !== null,
)

async function ensureYears(): Promise<void> {
  if (targetYears.value.length > 0) return
  loadingYears.value = true
  try {
    const res = await api<Paginated<SchoolYear>>('/api/v1/school-years')
    targetYears.value = res.data
      .filter((y) => y.id !== props.fromYear.id && !y.is_archived)
      .sort((a, b) => a.starts_on.localeCompare(b.starts_on))
    // Cible par défaut : la première année qui démarre après l'année source.
    toYearId.value = (targetYears.value.find((y) => y.starts_on > props.fromYear.starts_on)
      ?? targetYears.value[0])?.id ?? null
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Chargement des années impossible.'
  } finally {
    loadingYears.value = false
  }
}

async function runPreview(): Promise<void> {
  if (toYearId.value === null) return
  loadingPreview.value = true
  error.value = ''
  committedBatch.value = null
  try {
    const res = await api<ApiResource<PromotionPreview>>(
      `/api/v1/school-years/${props.fromYear.id}/promotion/preview`,
      { query: { to_year_id: toYearId.value } },
    )
    preview.value = res.data
    for (const key of Object.keys(decisions)) delete decisions[Number(key)]
    for (const row of res.data.students) {
      decisions[row.enrollment_id] = {
        decision: defaultDecision(row),
        target_classroom_id: row.target_classroom_id,
      }
    }
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Aperçu impossible.'
  } finally {
    loadingPreview.value = false
  }
}

function defaultDecision(row: PromotionStudentRow): Decision {
  if (row.resolution_status === 'graduate') return 'diplome'
  if (row.suggested_decision === null) return 'skip'
  return row.suggested_decision
}

function classroomsForRow(row: PromotionStudentRow): PromotionClassroom[] {
  if (!preview.value) return []
  // Si un niveau cible est résolu, on propose en priorité ses classes.
  const all = preview.value.available_classrooms
  if (row.target_level) {
    const matching = all.filter((c) => c.level_id === row.target_level?.id)
    if (matching.length > 0) return matching
  }
  return all
}

async function commit(): Promise<void> {
  if (!canCommit.value || toYearId.value === null) return
  const ok = await confirmDialog.ask({
    title: 'Confirmer le passage de classe',
    message: `Les élèves seront inscrits dans « ${toYear.value?.name} » selon les décisions affichées. Les comptes existants sont réutilisés.`,
    confirmLabel: 'Confirmer le passage',
    variant: 'warning',
  })
  if (!ok) return

  committing.value = true
  error.value = ''
  try {
    const payload = {
      to_year_id: toYearId.value,
      decisions: Object.entries(decisions).map(([enrollmentId, choice]) => ({
        enrollment_id: Number(enrollmentId),
        decision: choice.decision,
        target_classroom_id: choice.decision === 'promu' || choice.decision === 'redouble'
          ? choice.target_classroom_id
          : null,
      })),
    }
    const res = await api<ApiResource<CommittedBatch>>(
      `/api/v1/school-years/${props.fromYear.id}/promotion/commit`,
      { method: 'POST', body: payload },
    )
    committedBatch.value = res.data
    emit('committed')
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Passage impossible.'
  } finally {
    committing.value = false
  }
}

async function rollback(): Promise<void> {
  if (!committedBatch.value) return
  const ok = await confirmDialog.ask({
    title: 'Annuler ce passage',
    message: 'Les inscriptions créées dans l’année cible seront supprimées et les décisions annulées.',
    confirmLabel: 'Annuler le passage',
    variant: 'danger',
  })
  if (!ok) return

  rollingBack.value = true
  error.value = ''
  try {
    await api(`/api/v1/promotion-batches/${committedBatch.value.id}/rollback`, { method: 'POST' })
    committedBatch.value = null
    await runPreview()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Annulation impossible.'
  } finally {
    rollingBack.value = false
  }
}

const STATUS_HINT: Record<PromotionStudentRow['resolution_status'], string> = {
  ok: '',
  graduate: 'Fin de cycle',
  needs_option: 'Option à choisir',
  needs_class: 'Classe cible à définir',
}
</script>

<template>
  <section class="promotion-panel">
    <header class="promotion-head">
      <div>
        <h3>Passage de classe</h3>
        <p class="muted">
          Faites passer les élèves admis en classe supérieure et redoubler les autres, en
          réutilisant les comptes existants. Aperçu d’abord, puis confirmation.
        </p>
      </div>
    </header>

    <div v-if="error" class="promotion-error" role="alert">{{ error }}</div>

    <div class="promotion-controls">
      <label>
        Année cible
        <select v-model.number="toYearId" @focus="ensureYears" :disabled="committedBatch !== null">
          <option v-if="toYearId === null" :value="null" disabled>Choisir une année…</option>
          <option v-for="y in targetYears" :key="y.id" :value="y.id">{{ y.name }}</option>
        </select>
      </label>
      <button class="btn-secondary" :disabled="loadingPreview || toYearId === null || committedBatch !== null" @click="runPreview">
        {{ loadingPreview ? 'Calcul…' : 'Prévisualiser le passage' }}
      </button>
      <button v-if="!targetYears.length && !loadingYears" class="btn-ghost" @click="ensureYears">Charger les années</button>
    </div>

    <!-- Résultat d'un passage confirmé -->
    <div v-if="committedBatch" class="promotion-result" role="status">
      <p>
        Passage effectué : <strong>{{ committedBatch.promoted_count }}</strong> promu(s),
        <strong>{{ committedBatch.repeated_count }}</strong> redoublant(s),
        <strong>{{ committedBatch.graduated_count }}</strong> diplômé(s).
      </p>
      <p class="muted">L’année cible prendra effet lorsqu’elle deviendra l’année courante.</p>
      <button class="btn-danger" :disabled="rollingBack" @click="rollback">
        {{ rollingBack ? 'Annulation…' : 'Annuler ce passage' }}
      </button>
    </div>

    <template v-if="preview && !committedBatch">
      <div class="promotion-summary">
        <span class="chip">Seuil de réussite : {{ preview.threshold }}/20</span>
        <span class="chip chip-ok">{{ preview.summary.promote }} promus</span>
        <span class="chip chip-warn">{{ preview.summary.repeat }} redoublants</span>
        <span class="chip">{{ preview.summary.graduate }} diplômés</span>
        <span v-if="preview.summary.to_review" class="chip chip-review">{{ preview.summary.to_review }} à vérifier</span>
        <span v-if="preview.summary.already_promoted" class="chip">{{ preview.summary.already_promoted }} déjà inscrits</span>
      </div>

      <div class="promotion-table-wrap">
        <table class="promotion-table">
          <thead>
            <tr>
              <th>Élève</th>
              <th>Classe actuelle</th>
              <th>Moyenne</th>
              <th>Décision</th>
              <th>Classe cible</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in preview.students" :key="row.enrollment_id">
              <td>
                <span class="student-name">{{ row.student.full_name }}</span>
                <small v-if="row.student.registration_number" class="muted">{{ row.student.registration_number }}</small>
                <small v-if="row.already_enrolled_classroom_id" class="muted">déjà inscrit dans l’année cible</small>
              </td>
              <td>{{ row.current_classroom.full_name ?? '—' }}</td>
              <td :class="{ 'avg-fail': row.result_average !== null && row.result_average < preview.threshold }">
                {{ row.result_average !== null ? row.result_average.toFixed(2) : 'n/a' }}
              </td>
              <td>
                <select v-model="decisions[row.enrollment_id].decision">
                  <option v-for="(label, value) in DECISION_LABELS" :key="value" :value="value">{{ label }}</option>
                </select>
              </td>
              <td>
                <template v-if="decisions[row.enrollment_id].decision === 'promu' || decisions[row.enrollment_id].decision === 'redouble'">
                  <select v-model.number="decisions[row.enrollment_id].target_classroom_id">
                    <option :value="null" disabled>Choisir une classe…</option>
                    <option v-for="c in classroomsForRow(row)" :key="c.id" :value="c.id">
                      {{ c.full_name }} ({{ c.enrolled }}/{{ c.capacity }})
                    </option>
                  </select>
                  <small v-if="STATUS_HINT[row.resolution_status]" class="hint-warn">{{ STATUS_HINT[row.resolution_status] }}</small>
                  <small v-for="w in row.warnings" :key="w" class="hint-warn">{{ w }}</small>
                </template>
                <span v-else class="muted">—</span>
              </td>
            </tr>
            <tr v-if="preview.students.length === 0">
              <td colspan="5" class="muted">Aucun élève inscrit dans cette année.</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="promotion-actions">
        <button class="btn-primary" :disabled="committing || !canCommit" @click="commit">
          {{ committing ? 'Passage en cours…' : `Confirmer le passage vers ${toYear?.name ?? ''}` }}
        </button>
      </div>
    </template>
  </section>
</template>

<style scoped>
.promotion-panel { display: flex; flex-direction: column; gap: 1rem; }
.promotion-head h3 { margin: 0 0 0.25rem; }
.muted { color: var(--color-text-muted, #6b7280); font-size: 0.85rem; }
.promotion-controls { display: flex; flex-wrap: wrap; gap: 0.75rem; align-items: flex-end; }
.promotion-controls label { display: flex; flex-direction: column; gap: 0.25rem; font-size: 0.85rem; }
.promotion-controls select { min-width: 14rem; padding: 0.4rem 0.5rem; }
.promotion-error { background: #fef2f2; color: #b91c1c; padding: 0.6rem 0.8rem; border-radius: 0.5rem; }
.promotion-result { background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 0.6rem; padding: 0.8rem 1rem; display: flex; flex-direction: column; gap: 0.5rem; align-items: flex-start; }
.promotion-summary { display: flex; flex-wrap: wrap; gap: 0.5rem; }
.chip { background: #f3f4f6; border-radius: 999px; padding: 0.2rem 0.7rem; font-size: 0.8rem; }
.chip-ok { background: #dcfce7; color: #166534; }
.chip-warn { background: #fef3c7; color: #92400e; }
.chip-review { background: #fee2e2; color: #991b1b; }
.promotion-table-wrap { overflow-x: auto; }
.promotion-table { width: 100%; border-collapse: collapse; font-size: 0.88rem; }
.promotion-table th, .promotion-table td { text-align: left; padding: 0.5rem 0.6rem; border-bottom: 1px solid #eef0f3; vertical-align: top; }
.promotion-table select { width: 100%; padding: 0.3rem 0.4rem; }
.student-name { display: block; font-weight: 600; }
.avg-fail { color: #b91c1c; font-weight: 600; }
.hint-warn { display: block; color: #92400e; margin-top: 0.2rem; }
.promotion-actions { display: flex; justify-content: flex-end; }
</style>
