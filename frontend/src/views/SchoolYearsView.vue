<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { api, ApiError } from '../api/client'
import type { ApiResource, Paginated, SchoolYear } from '../types'
import Modal from '../components/Modal.vue'
import RowActionMenu from '../components/RowActionMenu.vue'
import { useConfirmStore } from '../stores/confirm'
import { useSchoolYearStore } from '../stores/schoolYear'

const schoolYearStore = useSchoolYearStore()
const confirmDialog = useConfirmStore()
const items = ref<SchoolYear[]>([])
const loading = ref(false)
const error = ref('')

const showForm = ref(false)
const editing = ref<SchoolYear | null>(null)
const formError = ref('')
const formErrors = reactive<Record<string, string[]>>({})
const submitting = ref(false)

const dateFormatter = new Intl.DateTimeFormat('fr-FR', {
  day: '2-digit',
  month: '2-digit',
  year: 'numeric',
})

const form = reactive({
  name: '',
  starts_on: '',
  ends_on: '',
  is_current: false,
})

const currentYear = computed(() => items.value.find((item) => item.is_current) ?? null)
const archivedCount = computed(() => items.value.filter((item) => !item.is_current).length)
const totalTerms = computed(() =>
  items.value.reduce((total, item) => total + (item.terms?.length ?? 0), 0),
)

function resetForm(): void {
  form.name = ''
  form.starts_on = ''
  form.ends_on = ''
  form.is_current = false
  formError.value = ''
  Object.keys(formErrors).forEach((k) => delete formErrors[k])
}

function formatDate(value: string): string {
  const date = new Date(`${value}T00:00:00`)
  if (Number.isNaN(date.getTime())) return value
  return dateFormatter.format(date)
}

function periodLabel(item: SchoolYear): string {
  return `${formatDate(item.starts_on)} - ${formatDate(item.ends_on)}`
}

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    const res = await api<Paginated<SchoolYear>>('/api/v1/school-years', {
      skipSchoolYear: true,
    })
    items.value = res.data
    schoolYearStore.replaceYears(res.data)
  } catch (err) {
    error.value = err instanceof ApiError ? err.message : 'Impossible de charger les années.'
  } finally {
    loading.value = false
  }
}

function openCreate(): void {
  editing.value = null
  resetForm()
  showForm.value = true
}

function openEdit(item: SchoolYear): void {
  editing.value = item
  form.name = item.name
  form.starts_on = item.starts_on
  form.ends_on = item.ends_on
  form.is_current = item.is_current
  formError.value = ''
  Object.keys(formErrors).forEach((k) => delete formErrors[k])
  showForm.value = true
}

async function submit(): Promise<void> {
  submitting.value = true
  formError.value = ''
  Object.keys(formErrors).forEach((k) => delete formErrors[k])
  try {
    let saved: SchoolYear
    if (editing.value) {
      const res = await api<ApiResource<SchoolYear>>(`/api/v1/school-years/${editing.value.id}`, {
        method: 'PUT',
        body: { ...form },
      })
      saved = res.data
    } else {
      const res = await api<ApiResource<SchoolYear>>('/api/v1/school-years', {
        method: 'POST',
        body: { ...form },
      })
      saved = res.data
    }
    if (saved.is_current) {
      schoolYearStore.markCurrent(saved.id)
    }
    showForm.value = false
    await load()
  } catch (err) {
    if (err instanceof ApiError) {
      formError.value = err.message
      if (err.errors) Object.assign(formErrors, err.errors)
    } else {
      formError.value = 'Erreur réseau.'
    }
  } finally {
    submitting.value = false
  }
}

async function remove(item: SchoolYear): Promise<void> {
  const ok = await confirmDialog.ask({
    title: 'Supprimer une année scolaire',
    message: 'Cette année scolaire et ses trimestres seront supprimés.',
    details: [item.name],
    note: 'Les données rattachées à cette année peuvent devenir inaccessibles.',
    confirmLabel: 'Supprimer',
    variant: 'danger',
  })
  if (!ok) return
  try {
    await api(`/api/v1/school-years/${item.id}`, { method: 'DELETE' })
    await load()
  } catch (err) {
    error.value = err instanceof ApiError ? err.message : 'Suppression impossible.'
  }
}

async function setCurrent(item: SchoolYear): Promise<void> {
  try {
    await api<ApiResource<SchoolYear>>(`/api/v1/school-years/${item.id}`, {
      method: 'PUT',
      body: {
        name: item.name,
        starts_on: item.starts_on,
        ends_on: item.ends_on,
        is_current: true,
      },
    })
    schoolYearStore.markCurrent(item.id)
    await load()
  } catch (err) {
    error.value = err instanceof ApiError ? err.message : 'Impossible de définir l\'année courante.'
  }
}

onMounted(load)
</script>

<template>
  <section class="school-years-page">
    <div class="page-heading">
      <div>
        <p class="eyebrow">Administration</p>
        <h1>Années scolaires</h1>
        <p class="heading-copy">
          Organiser les périodes, trimestres et archives de l'établissement.
        </p>
      </div>
      <button type="button" class="btn-primary" @click="openCreate">+ Nouvelle année</button>
    </div>

    <p v-if="error" class="alert alert-error">{{ error }}</p>

    <div class="summary-grid">
      <div class="summary-card">
        <span class="summary-label">Années</span>
        <strong>{{ items.length }}</strong>
        <span class="summary-note">enregistrées</span>
      </div>
      <div class="summary-card">
        <span class="summary-label">Courante</span>
        <strong>{{ currentYear?.name ?? '-' }}</strong>
        <span class="summary-note">{{ currentYear ? periodLabel(currentYear) : 'non définie' }}</span>
      </div>
      <div class="summary-card">
        <span class="summary-label">Trimestres</span>
        <strong>{{ totalTerms }}</strong>
        <span class="summary-note">sur toutes les années</span>
      </div>
      <div class="summary-card">
        <span class="summary-label">Archives</span>
        <strong>{{ archivedCount }}</strong>
        <span class="summary-note">années passées</span>
      </div>
    </div>

    <div class="card years-card">
      <div class="card-header years-card-header">
        <div>
          <h2>Registre des années</h2>
          <p>La ligne courante pilote les filtres, trimestres et rapports associés.</p>
        </div>
        <button type="button" class="btn-secondary" @click="load" :disabled="loading">
          {{ loading ? 'Actualisation...' : 'Actualiser' }}
        </button>
      </div>

      <div v-if="loading" class="year-list-skeleton">
        <div v-for="i in 3" :key="i" class="year-skeleton-row" />
      </div>

      <div v-else-if="items.length === 0" class="empty-state">
        Aucune année scolaire enregistrée.
      </div>

      <template v-else>
        <div class="table-wrap">
          <table class="years-table">
            <thead>
              <tr>
                <th>Nom</th>
                <th>Période</th>
                <th>Trimestres</th>
                <th>Statut</th>
                <th class="actions-heading">Actions</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(item, index) in items" :key="item.id" :class="{ current: item.is_current }">
                <td>
                  <RouterLink
                    class="year-link"
                    :to="{ name: 'school-year-detail', params: { id: item.id } }"
                  >
                    {{ item.name }}
                  </RouterLink>
                </td>
                <td>{{ periodLabel(item) }}</td>
                <td>{{ item.terms?.length ?? 0 }}</td>
                <td>
                  <span v-if="item.is_current" class="badge badge-success">Courante</span>
                  <span v-else class="badge badge-muted">Archivée</span>
                </td>
                <td class="actions-cell">
                  <RowActionMenu
                    :open-up="items.length > 3 && index >= items.length - 2"
                    :aria-label="`Actions pour ${item.name}`"
                  >
                    <RouterLink
                      :to="{ name: 'school-year-detail', params: { id: item.id } }"
                    >
                      Ouvrir
                    </RouterLink>
                    <button v-if="!item.is_current" type="button" @click="setCurrent(item)">
                      Définir courant
                    </button>
                    <button type="button" @click="openEdit(item)">Modifier</button>
                    <button type="button" class="danger-action" @click="remove(item)">Supprimer</button>
                  </RowActionMenu>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="year-mobile-list">
          <article v-for="item in items" :key="item.id" class="year-mobile-card">
            <div class="mobile-card-top">
              <div>
                <RouterLink
                  class="year-link"
                  :to="{ name: 'school-year-detail', params: { id: item.id } }"
                >
                  {{ item.name }}
                </RouterLink>
                <p>{{ periodLabel(item) }}</p>
              </div>
              <span v-if="item.is_current" class="badge badge-success">Courante</span>
              <span v-else class="badge badge-muted">Archivée</span>
            </div>
            <div class="mobile-meta">
              <span>{{ item.terms?.length ?? 0 }} trimestre(s)</span>
            </div>
            <div class="mobile-actions">
              <RowActionMenu :aria-label="`Actions pour ${item.name}`">
                <RouterLink
                  :to="{ name: 'school-year-detail', params: { id: item.id } }"
                >
                  Ouvrir
                </RouterLink>
                <button v-if="!item.is_current" type="button" @click="setCurrent(item)">
                  Courante
                </button>
                <button type="button" @click="openEdit(item)">Modifier</button>
                <button type="button" class="danger-action" @click="remove(item)">Supprimer</button>
              </RowActionMenu>
            </div>
          </article>
        </div>
      </template>
    </div>

    <Modal
      :open="showForm"
      :title="editing ? 'Modifier une année scolaire' : 'Nouvelle année scolaire'"
      @close="showForm = false"
    >
      <form id="school-year-form" class="year-form" @submit.prevent="submit">
        <div class="field">
          <label for="sy-name">Nom</label>
          <input
            id="sy-name"
            v-model="form.name"
            type="text"
            required
            maxlength="32"
            placeholder="2026-2027"
          />
          <small v-if="formErrors.name" class="err">{{ formErrors.name[0] }}</small>
        </div>

        <div class="form-grid">
          <div class="field">
            <label for="sy-starts">Date de début</label>
            <input id="sy-starts" v-model="form.starts_on" type="date" required />
            <small v-if="formErrors.starts_on" class="err">{{ formErrors.starts_on[0] }}</small>
          </div>

          <div class="field">
            <label for="sy-ends">Date de fin</label>
            <input id="sy-ends" v-model="form.ends_on" type="date" required />
            <small v-if="formErrors.ends_on" class="err">{{ formErrors.ends_on[0] }}</small>
          </div>
        </div>

        <label class="check-row">
          <input v-model="form.is_current" type="checkbox" />
          <span>
            <strong>Définir comme année courante</strong>
            <small>Les autres années seront automatiquement archivées.</small>
          </span>
        </label>

        <p v-if="formError" class="alert alert-error">{{ formError }}</p>
      </form>

      <template #footer>
        <button type="button" @click="showForm = false">Annuler</button>
        <button
          type="submit"
          form="school-year-form"
          class="btn-primary"
          :disabled="submitting"
        >
          {{ submitting ? 'Enregistrement...' : editing ? 'Enregistrer' : 'Créer' }}
        </button>
      </template>
    </Modal>
  </section>
</template>

<style scoped>
.school-years-page {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.page-heading {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
}

.page-heading h1 {
  margin: 0;
}

.eyebrow {
  margin: 0 0 0.12rem;
  color: var(--text-soft);
  font-size: 0.73rem;
  font-weight: 800;
  letter-spacing: 0.08em;
  text-transform: uppercase;
}

.heading-copy {
  max-width: 42rem;
  margin: 0.28rem 0 0;
  color: var(--text-soft);
  font-size: 0.94rem;
}

.summary-grid {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 0.85rem;
}

.summary-card {
  min-height: 6.3rem;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  gap: 0.35rem;
  padding: 0.9rem;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: var(--bg-card);
  box-shadow: var(--shadow);
}

.summary-label {
  color: var(--text-soft);
  font-size: 0.72rem;
  font-weight: 800;
  letter-spacing: 0.05em;
  text-transform: uppercase;
}

.summary-card strong {
  overflow: hidden;
  color: var(--text);
  font-size: 1.45rem;
  font-weight: 850;
  line-height: 1;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.summary-note {
  overflow: hidden;
  color: var(--text-muted);
  font-size: 0.76rem;
  font-weight: 650;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.years-card-header h2 {
  margin: 0;
}

.years-card-header p {
  margin: 0.2rem 0 0;
  color: var(--text-soft);
  font-size: 0.86rem;
}

.table-wrap {
  overflow: visible;
}

.years-card {
  overflow: visible;
}

.years-table {
  min-width: 58rem;
}

.years-table tr.current td {
  background: #fbfdff;
}

.year-link {
  font-weight: 800;
}

.actions-heading {
  text-align: right;
}

.actions-cell {
  text-align: right;
  white-space: nowrap;
}

.year-mobile-list {
  display: none;
  padding: 0.8rem;
  gap: 0.75rem;
}

.year-mobile-card {
  padding: 0.85rem;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: var(--bg-card);
}

.mobile-card-top {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 0.75rem;
}

.mobile-card-top p,
.mobile-meta {
  margin: 0.25rem 0 0;
  color: var(--text-soft);
  font-size: 0.84rem;
}

.mobile-actions {
  display: flex;
  justify-content: flex-start;
  margin-top: 0.75rem;
}

.year-list-skeleton {
  display: grid;
  gap: 0.65rem;
  padding: 1rem;
}

.year-skeleton-row {
  height: 3.4rem;
  border-radius: var(--radius);
  background: linear-gradient(90deg, #f2f4f7, #f8faff, #f2f4f7);
  background-size: 180% 100%;
  animation: shimmer 1.2s infinite linear;
}

.form-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.75rem;
}

.check-row {
  display: flex;
  align-items: flex-start;
  gap: 0.65rem;
  padding: 0.78rem;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: var(--bg-subtle);
  cursor: pointer;
}

.check-row input {
  width: auto;
  margin-top: 0.15rem;
}

.check-row strong {
  display: block;
  color: var(--text);
  font-size: 0.9rem;
}

.check-row small {
  display: block;
  color: var(--text-soft);
  font-size: 0.78rem;
  margin-top: 0.12rem;
}

.err {
  display: block;
  color: var(--danger);
  font-size: 0.78rem;
  margin-top: 0.25rem;
}

@keyframes shimmer {
  from {
    background-position: 120% 0;
  }

  to {
    background-position: -120% 0;
  }
}

@media (max-width: 920px) {
  .summary-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: 720px) {
  .page-heading {
    flex-direction: column;
  }

  .page-heading .btn-primary {
    width: 100%;
  }

  .summary-grid {
    grid-template-columns: 1fr;
  }

  .table-wrap {
    display: none;
  }

  .year-mobile-list {
    display: grid;
  }

  .form-grid {
    grid-template-columns: 1fr;
  }
}
</style>
