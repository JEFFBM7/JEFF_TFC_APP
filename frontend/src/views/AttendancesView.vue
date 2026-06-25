<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { api, ApiError } from '../api/client'
import Modal from '../components/Modal.vue'
import { useAuthStore } from '../stores/auth'
import { useSchoolYearStore } from '../stores/schoolYear'
import type {
  AttendanceRecord,
  AttendanceStatus,
  ClassRoom,
  Paginated,
  Subject,
} from '../types'

const auth = useAuthStore()
const schoolYearStore = useSchoolYearStore()

function todayISO(): string {
  return new Date().toISOString().slice(0, 10)
}

const classrooms = ref<ClassRoom[]>([])
const subjectsForClass = ref<Subject[]>([])
const rollRows = ref<AttendanceRecord[]>([])
const loadingRoll = ref(false)
const saving = ref(false)
const error = ref('')
const success = ref('')
const batchAlerts = ref<
  { student_id: number; full_name: string; reasons: string[]; consecutive?: number; last_30d?: number }[]
>([])

const classroomId = ref<number | ''>('')
/** chaîne vide = appel sans cours (subject_id null côté API) */
const subjectId = ref<number | ''>('')
const dateStr = ref(todayISO())

const recentAbsences = ref<AttendanceRecord[]>([])
const loadingRecent = ref(false)

const justifyOpen = ref(false)
const justifyTarget = ref<AttendanceRecord | null>(null)
const justifyForm = ref({ justified: true, justification: '' })
const justifySubmitting = ref(false)
const justifyError = ref('')

const canJustify = computed(() => auth.hasRole('admin', 'secretariat'))

const statusOptions: { value: AttendanceStatus; label: string }[] = [
  { value: 'present', label: 'Présent' },
  { value: 'absent', label: 'Absent' },
  { value: 'late', label: 'Retard' },
]

function alertReasonLabel(code: string): string {
  if (code === 'consecutive_3') return '3 absences injustifiées consécutives (seuil CDC)'
  if (code === 'rolling_30') return '5 absences injustifiées sur 30 jours (seuil CDC)'
  return code
}

async function loadClassrooms(): Promise<void> {
  try {
    const res = await api<Paginated<ClassRoom>>('/api/v1/classrooms')
    classrooms.value = res.data
    if (res.data.length && classroomId.value === '') {
      classroomId.value = res.data[0].id
    }
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Impossible de charger les classes.'
  }
}

async function loadSubjectsForClass(): Promise<void> {
  subjectsForClass.value = []
  if (!classroomId.value) return
  try {
    const res = await api<Paginated<Subject>>(`/api/v1/classrooms/${classroomId.value}/subjects`)
    subjectsForClass.value = res.data
  } catch {
    /* cours optionnels */
  }
}

async function loadRollCall(): Promise<void> {
  if (!classroomId.value || !dateStr.value) return
  loadingRoll.value = true
  error.value = ''
  try {
    const query: Record<string, string | number> = {
      classroom_id: Number(classroomId.value),
      date: dateStr.value,
    }
    if (subjectId.value !== '') {
      query.subject_id = Number(subjectId.value)
    }
    const res = await api<{ data: AttendanceRecord[] }>('/api/v1/attendances/roll-call', {
      query,
    })
    rollRows.value = res.data
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Chargement de l’appel impossible.'
    rollRows.value = []
  } finally {
    loadingRoll.value = false
  }
}

async function loadRecentAbsences(): Promise<void> {
  loadingRecent.value = true
  try {
    const from = new Date()
    from.setDate(from.getDate() - 30)
    const fromStr = from.toISOString().slice(0, 10)
    const res = await api<Paginated<AttendanceRecord>>('/api/v1/attendances', {
      query: { status: 'absent', from: fromStr },
    })
    recentAbsences.value = res.data
  } catch {
    recentAbsences.value = []
  } finally {
    loadingRecent.value = false
  }
}

async function saveRoll(): Promise<void> {
  if (!classroomId.value || !dateStr.value || !rollRows.value.length) return
  saving.value = true
  error.value = ''
  success.value = ''
  batchAlerts.value = []
  try {
    const body: Record<string, unknown> = {
      classroom_id: Number(classroomId.value),
      date: dateStr.value,
      records: rollRows.value.map((r) => ({ student_id: r.student_id, status: r.status })),
    }
    if (subjectId.value !== '') {
      body.subject_id = Number(subjectId.value)
    }
    const res = await api<{
      message: string
      alerts?: { student_id: number; full_name: string; reasons: string[]; consecutive?: number; last_30d?: number }[]
    }>('/api/v1/attendances/batch', { method: 'POST', body })
    success.value = res.message
    batchAlerts.value = res.alerts ?? []
    await loadRollCall()
    await loadRecentAbsences()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Enregistrement impossible.'
  } finally {
    saving.value = false
    setTimeout(() => {
      success.value = ''
    }, 5000)
  }
}

function openJustify(row: AttendanceRecord): void {
  if (!row.id) return
  justifyTarget.value = row
  justifyForm.value = {
    justified: true,
    justification: row.justification ?? '',
  }
  justifyError.value = ''
  justifyOpen.value = true
}

function closeJustify(): void {
  justifyOpen.value = false
  justifyTarget.value = null
}

async function submitJustify(): Promise<void> {
  if (!justifyTarget.value?.id) return
  justifySubmitting.value = true
  justifyError.value = ''
  try {
    await api(`/api/v1/attendances/${justifyTarget.value.id}/justify`, {
      method: 'PATCH',
      body: {
        justified: justifyForm.value.justified,
        justification: justifyForm.value.justification || null,
      },
    })
    closeJustify()
    await loadRecentAbsences()
    await loadRollCall()
  } catch (e) {
    justifyError.value = e instanceof ApiError ? e.message : 'Mise à jour impossible.'
  } finally {
    justifySubmitting.value = false
  }
}

watch(classroomId, async () => {
  subjectId.value = ''
  await loadSubjectsForClass()
  await loadRollCall()
})

watch([dateStr, subjectId], () => {
  void loadRollCall()
})

// Recharge classes + appel quand l'utilisateur bascule d'année.
watch(
  () => schoolYearStore.effectiveId,
  async () => {
    classroomId.value = ''
    subjectId.value = ''
    rollRows.value = []
    await loadClassrooms()
    await loadSubjectsForClass()
    await loadRollCall()
    await loadRecentAbsences()
  },
)

onMounted(async () => {
  await loadClassrooms()
  await loadSubjectsForClass()
  await loadRollCall()
  await loadRecentAbsences()
})
</script>

<template>
  <section>
    <h1 style="margin: 0 0 1rem">Présences &amp; absences</h1>
    <p class="text-soft" style="margin: 0 0 1rem; font-size: 0.95rem">
      Appel par classe et par date. Les absences sont enregistrées comme non justifiées par défaut. La direction ou le
      secrétariat peut les justifier ci-dessous.
    </p>

    <p v-if="error" class="alert alert-error">{{ error }}</p>
    <p
      v-if="success"
      class="alert"
      style="background: var(--success-soft); color: var(--success)"
    >
      {{ success }}
    </p>

    <div v-if="batchAlerts.length > 0" class="alert" style="background: var(--warn-soft); color: var(--warn); border: 1px solid rgba(251, 191, 36, 0.3)">
      <strong>Alertes absentéisme (CDC)</strong>
      <ul style="margin: 0.5rem 0 0 1rem">
        <li v-for="a in batchAlerts" :key="a.student_id">
          <strong>{{ a.full_name }}</strong>
          —
          <span v-for="(r, i) in a.reasons" :key="i">
            {{ alertReasonLabel(r) }}
            <template v-if="i < a.reasons.length - 1"> · </template>
          </span>
          <span v-if="a.consecutive != null"> (série : {{ a.consecutive }})</span>
          <span v-if="a.last_30d != null"> — 30 j. : {{ a.last_30d }}</span>
        </li>
      </ul>
    </div>

    <div class="card" style="margin-bottom: 1.25rem">
      <div class="card-header">
        <h2 style="margin: 0; font-size: 1.1rem">Feuille d’appel</h2>
      </div>
      <div style="display: flex; flex-wrap: wrap; gap: 1rem; margin-bottom: 1rem; align-items: flex-end">
        <label class="form-field" style="min-width: 12rem">
          <span>Classe</span>
          <select v-model.number="classroomId" class="input">
            <option value="" disabled>Choisir…</option>
            <option v-for="c in classrooms" :key="c.id" :value="c.id">{{ c.full_name }}</option>
          </select>
        </label>
        <label class="form-field" style="min-width: 11rem">
          <span>Date</span>
          <input v-model="dateStr" type="date" class="input" />
        </label>
        <label class="form-field" style="min-width: 14rem">
          <span>Cours (optionnel)</span>
          <select v-model="subjectId" class="input">
            <option value="">Journée / sans cours précis</option>
            <option v-for="s in subjectsForClass" :key="s.id" :value="s.id">{{ s.name }}</option>
          </select>
        </label>
        <button type="button" class="btn-primary" :disabled="saving || !rollRows.length" @click="saveRoll">
          {{ saving ? 'Enregistrement…' : 'Enregistrer l’appel' }}
        </button>
      </div>

      <div v-if="loadingRoll" class="empty-state">Chargement…</div>
      <div v-else-if="!classroomId" class="empty-state">Sélectionnez une classe.</div>
      <table v-else-if="rollRows.length">
        <thead>
          <tr>
            <th>Élève</th>
            <th style="width: 11rem">Statut</th>
            <th v-if="canJustify" style="width: 7rem">Justifiée</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="r in rollRows" :key="r.student_id">
            <td>{{ r.student?.full_name }}</td>
            <td>
              <select v-model="r.status" class="input">
                <option v-for="opt in statusOptions" :key="opt.value" :value="opt.value">
                  {{ opt.label }}
                </option>
              </select>
            </td>
            <td v-if="canJustify">
              <span v-if="r.status === 'absent'" class="badge" :class="r.justified ? 'badge-muted' : 'badge-warn'">
                {{ r.justified ? 'Oui' : 'Non' }}
              </span>
              <span v-else class="text-soft">—</span>
            </td>
          </tr>
        </tbody>
      </table>
      <p v-else class="empty-state">Aucun élève dans cette classe.</p>
    </div>

    <div class="card">
      <div class="card-header">
        <h2 style="margin: 0; font-size: 1.1rem">Absences récentes (30 jours)</h2>
      </div>
      <div v-if="loadingRecent" class="empty-state">Chargement…</div>
      <table v-else-if="recentAbsences.length">
        <thead>
          <tr>
            <th>Date</th>
            <th>Élève</th>
            <th>Cours</th>
            <th>Justifiée</th>
            <th v-if="canJustify" style="width: 8rem">Action</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in recentAbsences" :key="row.id ?? row.student_id + (row.date ?? '')">
            <td>{{ row.date }}</td>
            <td>{{ row.student?.full_name ?? '—' }}</td>
            <td>{{ row.subject?.name ?? '—' }}</td>
            <td>
              <span v-if="row.justified" class="badge badge-muted">Oui</span>
              <span v-else class="badge badge-warn">Non</span>
            </td>
            <td v-if="canJustify">
              <button type="button" class="btn-sm" :disabled="!row.id" @click="openJustify(row)">
                Justifier
              </button>
            </td>
          </tr>
        </tbody>
      </table>
      <p v-else class="empty-state">Aucune absence sur cette période.</p>
    </div>

    <Modal title="Justification d’absence" :open="justifyOpen" @close="closeJustify">
      <p v-if="justifyError" class="alert alert-error">{{ justifyError }}</p>
      <template v-if="justifyTarget">
        <p style="margin: 0 0 0.75rem; font-size: 0.9rem">
          <strong>{{ justifyTarget.student?.full_name }}</strong>
          · {{ justifyTarget.date }}
        </p>
        <label class="form-field">
          <span>Justifiée</span>
          <select v-model="justifyForm.justified" class="input">
            <option :value="true">Oui</option>
            <option :value="false">Non</option>
          </select>
        </label>
        <label class="form-field">
          <span>Motif / référence</span>
          <textarea v-model="justifyForm.justification" class="input" rows="3" placeholder="Ex. certificat médical…" />
        </label>
      </template>
      <template #footer>
        <button type="button" class="btn-muted" @click="closeJustify">Annuler</button>
        <button type="button" class="btn-primary" :disabled="justifySubmitting" @click="submitJustify">
          {{ justifySubmitting ? 'Envoi…' : 'Enregistrer' }}
        </button>
      </template>
    </Modal>
  </section>
</template>

<style scoped>
.text-soft {
  color: var(--text-soft);
}
.form-field {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  font-size: 0.88rem;
}
.form-field span {
  color: var(--text-soft);
}
.badge-warn {
  background: var(--warn-soft);
  color: var(--warn);
}
.btn-sm {
  font-size: 0.85rem;
  padding: 0.35rem 0.65rem;
  border-radius: 6px;
  border: 1px solid var(--border);
  background: var(--bg-card);
  cursor: pointer;
}
.btn-sm:hover:not(:disabled) {
  background: var(--primary-soft);
}
.btn-sm:disabled {
  opacity: 0.5;
}
.btn-muted {
  padding: 0.45rem 0.9rem;
  border-radius: 8px;
  border: 1px solid var(--border);
  background: transparent;
  cursor: pointer;
}
</style>
