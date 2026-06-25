<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { api, ApiError } from '../api/client'
import Modal from '../components/Modal.vue'
import type { AttendanceRecord, Paginated } from '../types'

const absences = ref<AttendanceRecord[]>([])
const summary = ref<{ total_absences: number; total_lates: number } | null>(null)
const loading = ref(false)
const error = ref('')
const showJustify = ref(false)
const justifyTarget = ref<AttendanceRecord | null>(null)
const justifyForm = reactive({ justification: '' })
const submitting = ref(false)
const justifyError = ref('')

const unjustifiedCount = computed(() => absences.value.filter((item) => !item.justified && item.status === 'absent').length)
const pendingParentCount = computed(() => absences.value.filter((item) => item.justification_status === 'pending_parent').length)

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    const [dashboardRes, attendancesRes] = await Promise.all([
      api<{ data: { total_absences: number; total_lates: number } }>('/api/v1/student/dashboard'),
      api<Paginated<AttendanceRecord>>('/api/v1/student/attendances'),
    ])
    summary.value = dashboardRes.data
    absences.value = attendancesRes.data
  } catch (err) {
    error.value = err instanceof ApiError ? err.message : 'Impossible de charger vos absences.'
  } finally {
    loading.value = false
  }
}

function statusLabel(item: AttendanceRecord): string {
  if (item.justification_status === 'confirmed') return 'Confirmée'
  if (item.justification_status === 'pending_parent') return 'En attente parent'
  if (item.justification_status === 'awaiting_student') return 'À justifier aujourd’hui'
  return 'Délai dépassé'
}

function openJustify(item: AttendanceRecord): void {
  justifyTarget.value = item
  justifyForm.justification = item.student_justification ?? ''
  justifyError.value = ''
  showJustify.value = true
}

async function submitJustification(): Promise<void> {
  if (!justifyTarget.value?.id) return
  submitting.value = true
  justifyError.value = ''
  try {
    await api(`/api/v1/student/attendances/${justifyTarget.value.id}/justify`, {
      method: 'PATCH',
      body: { justification: justifyForm.justification },
    })
    showJustify.value = false
    await load()
  } catch (err) {
    justifyError.value = err instanceof ApiError ? err.message : 'Justification impossible.'
  } finally {
    submitting.value = false
  }
}

onMounted(load)
</script>

<template>
  <section class="absences-page">
    <RouterLink class="back-link" :to="{ name: 'student-dashboard' }">Tableau de bord</RouterLink>
    <div class="page-head">
      <div>
        <h1>Mes absences</h1>
        <p>Historique des absences et retards à justifier le jour même.</p>
      </div>
      <button type="button" :disabled="loading" @click="load">
        {{ loading ? 'Chargement…' : 'Actualiser' }}
      </button>
    </div>

    <p v-if="error" class="alert alert-error">{{ error }}</p>

    <div class="summary-grid">
      <div class="summary-card warn">
        <span>Absences</span>
        <strong>{{ summary?.total_absences ?? absences.length }}</strong>
      </div>
      <div class="summary-card danger">
        <span>Non justifiées</span>
        <strong>{{ unjustifiedCount }}</strong>
      </div>
      <div class="summary-card ok">
        <span>En attente parent</span>
        <strong>{{ pendingParentCount }}</strong>
      </div>
      <div class="summary-card muted">
        <span>Retards</span>
        <strong>{{ summary?.total_lates ?? 0 }}</strong>
      </div>
    </div>

    <div v-if="loading" class="empty-state">Chargement…</div>
    <div v-else-if="absences.length === 0" class="empty-state">Aucune absence enregistrée.</div>
    <div v-else>
      <div class="absence-mobile-list">
        <article v-for="a in absences" :key="a.id ?? undefined" class="absence-card">
          <div class="absence-card-main">
            <div>
              <span class="absence-date">{{ a.date }}</span>
              <strong>{{ a.subject?.name ?? 'Cours non renseigné' }}</strong>
            </div>
            <span class="badge badge-muted" v-if="a.status === 'present'">Présent</span>
            <span class="badge badge-absence" v-else-if="a.status === 'absent'">Absent</span>
            <span class="badge badge-muted" v-else>Retard</span>
          </div>
          <div class="absence-card-status">
            <span
              class="badge"
              :class="a.justified ? 'badge-muted' : a.justification_status === 'pending_parent' ? 'badge-warn' : 'badge-danger'"
            >
              {{ statusLabel(a) }}
            </span>
          </div>
          <p class="absence-reason">{{ a.student_justification ?? a.justification ?? 'Aucun motif enregistré.' }}</p>
          <button
            v-if="a.can_student_justify"
            type="button"
            class="btn-sm absence-action"
            @click="openJustify(a)"
          >
            {{ a.student_justification ? 'Modifier le motif' : 'Justifier maintenant' }}
          </button>
        </article>
      </div>

      <div class="card table-card">
        <table>
          <thead>
            <tr>
              <th>Date</th>
              <th>Cours</th>
              <th>Statut</th>
              <th>Justification</th>
              <th>Motif</th>
              <th style="width: 1%"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="a in absences" :key="a.id ?? undefined">
              <td>{{ a.date }}</td>
              <td>{{ a.subject?.name ?? '—' }}</td>
              <td>
                <span class="badge badge-muted" v-if="a.status === 'present'">Présent</span>
                <span class="badge badge-absence" v-else-if="a.status === 'absent'">Absent</span>
                <span class="badge badge-muted" v-else>Retard</span>
              </td>
              <td>
                <span
                  class="badge"
                  :class="a.justified ? 'badge-muted' : a.justification_status === 'pending_parent' ? 'badge-warn' : 'badge-danger'"
                >
                  {{ statusLabel(a) }}
                </span>
              </td>
              <td>{{ a.student_justification ?? a.justification ?? '—' }}</td>
              <td>
                <button
                  v-if="a.can_student_justify"
                  type="button"
                  class="btn-sm"
                  @click="openJustify(a)"
                >
                  {{ a.student_justification ? 'Modifier' : 'Justifier' }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <Modal :open="showJustify" title="Justifier l'absence ou le retard" @close="showJustify = false">
      <p v-if="justifyError" class="alert alert-error">{{ justifyError }}</p>
      <p v-if="justifyTarget" class="modal-meta">
        <strong>Date :</strong> {{ justifyTarget.date }}
        <span v-if="justifyTarget.subject?.name"> · <strong>Cours :</strong> {{ justifyTarget.subject.name }}</span>
      </p>
      <div class="field">
        <label for="student-justification">Motif</label>
        <textarea
          id="student-justification"
          v-model="justifyForm.justification"
          rows="4"
          maxlength="500"
          required
          placeholder="Explique la raison de ton absence ou de ton retard."
        />
      </div>
      <template #footer>
        <button type="button" @click="showJustify = false">Annuler</button>
        <button
          type="button"
          class="btn-primary"
          :disabled="submitting || !justifyForm.justification.trim()"
          @click="submitJustification"
        >
          {{ submitting ? 'Envoi…' : 'Envoyer au responsable' }}
        </button>
      </template>
    </Modal>
  </section>
</template>

<style scoped>
.absences-page {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.back-link {
  min-height: 2.25rem;
  display: inline-flex;
  align-items: center;
  width: fit-content;
  color: var(--text-soft);
  font-weight: 800;
}

.back-link::before {
  content: '←';
  margin-right: 0.45rem;
}

.page-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
}

.page-head h1 {
  margin: 0;
}

.page-head p {
  margin: 0.25rem 0 0;
  color: var(--text-soft);
}

.summary-grid {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 0.75rem;
  margin-bottom: 1rem;
}

.summary-card {
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: var(--bg-card);
  padding: 0.85rem 1rem;
}

.summary-card span {
  display: block;
  color: var(--text-soft);
  font-size: 0.82rem;
  font-weight: 700;
}

.summary-card strong {
  display: block;
  margin-top: 0.25rem;
  font-size: 1.55rem;
  line-height: 1;
}

.summary-card.warn strong,
.summary-card.danger strong {
  color: var(--warn);
}

.summary-card.ok strong {
  color: var(--success);
}

.summary-card.muted strong {
  color: #475569;
}

.table-card {
  overflow-x: auto;
}

.absence-mobile-list {
  display: none;
}

.badge-absence {
  background: var(--warn-soft);
  color: var(--warn);
}

.modal-meta {
  margin: 0 0 0.75rem;
  color: var(--text-soft);
  font-size: 0.9rem;
}

@media (max-width: 760px) {
  .page-head {
    display: grid;
    gap: 0.75rem;
  }

  .page-head button {
    width: 100%;
    min-height: 2.75rem;
  }

  .summary-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.55rem;
  }

  .summary-card {
    padding: 0.75rem;
  }

  .summary-card span {
    font-size: 0.72rem;
    line-height: 1.15;
  }

  .summary-card strong {
    font-size: 1.35rem;
  }

  .table-card {
    display: none;
  }

  .absence-mobile-list {
    display: grid;
    gap: 0.75rem;
  }

  .absence-card {
    display: grid;
    gap: 0.7rem;
    padding: 0.9rem;
    border: 1px solid var(--border);
    border-radius: var(--radius);
    background: var(--bg-card);
    box-shadow: var(--shadow);
  }

  .absence-card-main {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 0.75rem;
  }

  .absence-card-main strong {
    display: block;
    margin-top: 0.12rem;
    color: var(--text);
    line-height: 1.25;
  }

  .absence-date {
    color: var(--text-soft);
    font-size: 0.76rem;
    font-weight: 800;
    text-transform: uppercase;
  }

  .absence-card-status {
    display: flex;
    flex-wrap: wrap;
    gap: 0.45rem;
  }

  .absence-reason {
    margin: 0;
    color: var(--text-soft);
    font-size: 0.86rem;
    line-height: 1.45;
  }

  .absence-action {
    width: 100%;
    min-height: 2.65rem;
  }
}

@media (max-width: 380px) {
  .summary-grid {
    grid-template-columns: 1fr;
  }
}
</style>
