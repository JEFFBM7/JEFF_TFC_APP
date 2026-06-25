<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { api, ApiError } from '../api/client'
import type { ApiResource, Paginated, SchoolYear } from '../types'
import Modal from '../components/Modal.vue'
import PromotionPanel from '../components/schoolyear/PromotionPanel.vue'
import RowActionMenu from '../components/RowActionMenu.vue'
import { useConfirmStore } from '../stores/confirm'
import { useSchoolYearStore } from '../stores/schoolYear'
import { CalendarDays, Star, BookOpen, Archive, RefreshCw, Plus } from 'lucide-vue-next'
import { useRouter } from 'vue-router'

const router = useRouter()

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

// Modale création en 2 étapes : 1) créer l'année, 2) passage de classe.
const step = ref<1 | 2>(1)
const createdYear = ref<SchoolYear | null>(null)
const passageSource = ref<SchoolYear | null>(null)
const canPromoteOnCreate = computed(() => !editing.value && items.value.length > 0)
const modalTitle = computed(() => {
  if (step.value === 2) return 'Passage de classe'
  return editing.value ? 'Modifier une année scolaire' : 'Nouvelle année scolaire'
})

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

function toIsoDate(date: Date): string {
  const y = date.getFullYear()
  const m = String(date.getMonth() + 1).padStart(2, '0')
  const d = String(date.getDate()).padStart(2, '0')
  return `${y}-${m}-${d}`
}

function addYears(value: string, years: number): string {
  const date = new Date(`${value}T00:00:00`)
  if (Number.isNaN(date.getTime())) return value
  date.setFullYear(date.getFullYear() + years)
  return toIsoDate(date)
}

function deriveName(startsOn: string, endsOn: string): string {
  const startYear = new Date(`${startsOn}T00:00:00`).getFullYear()
  const endYear = new Date(`${endsOn}T00:00:00`).getFullYear()
  if (Number.isNaN(startYear)) return ''
  return endYear > startYear ? `${startYear}-${endYear}` : `${startYear}`
}

/**
 * Calcule le nom et les dates de l'année suivante en se basant sur la dernière
 * année enregistrée (décalée d'un an). À défaut, retombe sur l'année scolaire
 * civile courante (rentrée en septembre). Les valeurs restent modifiables.
 */
function nextYearDefaults(): { name: string; starts_on: string; ends_on: string } {
  const reference = [...items.value].sort((a, b) => b.starts_on.localeCompare(a.starts_on))[0]
  if (reference) {
    const startsOn = addYears(reference.starts_on, 1)
    const endsOn = addYears(reference.ends_on, 1)
    return { name: deriveName(startsOn, endsOn), starts_on: startsOn, ends_on: endsOn }
  }

  const now = new Date()
  const startYear = now.getMonth() >= 6 ? now.getFullYear() : now.getFullYear() - 1
  const startsOn = `${startYear}-09-01`
  const endsOn = `${startYear + 1}-07-02`
  return { name: deriveName(startsOn, endsOn), starts_on: startsOn, ends_on: endsOn }
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
  step.value = 1
  createdYear.value = null
  passageSource.value = null
  resetForm()
  const defaults = nextYearDefaults()
  form.name = defaults.name
  form.starts_on = defaults.starts_on
  form.ends_on = defaults.ends_on
  showForm.value = true
}

function applyAutoFill(): void {
  const defaults = nextYearDefaults()
  form.name = defaults.name
  form.starts_on = defaults.starts_on
  form.ends_on = defaults.ends_on
}

function openEdit(item: SchoolYear): void {
  editing.value = item
  step.value = 1
  form.name = item.name
  form.starts_on = item.starts_on
  form.ends_on = item.ends_on
  form.is_current = item.is_current
  formError.value = ''
  Object.keys(formErrors).forEach((k) => delete formErrors[k])
  showForm.value = true
}

function closeForm(): void {
  showForm.value = false
  step.value = 1
  createdYear.value = null
  passageSource.value = null
}

async function submit(): Promise<void> {
  submitting.value = true
  formError.value = ''
  Object.keys(formErrors).forEach((k) => delete formErrors[k])
  try {
    if (editing.value) {
      const res = await api<ApiResource<SchoolYear>>(`/api/v1/school-years/${editing.value.id}`, {
        method: 'PUT',
        body: { ...form },
      })
      if (res.data.is_current) schoolYearStore.markCurrent(res.data.id)
      closeForm()
      await load()
      return
    }

    // Création. Si d'autres années existent, on enchaîne sur le passage de classe :
    // l'année est créée NON courante (sinon le passage serait bloqué).
    const promote = canPromoteOnCreate.value
    const res = await api<ApiResource<SchoolYear>>('/api/v1/school-years', {
      method: 'POST',
      body: promote ? { ...form, is_current: false } : { ...form },
    })
    const saved = res.data

    if (promote) {
      passageSource.value =
        [...items.value].sort((a, b) => b.starts_on.localeCompare(a.starts_on))[0] ?? null
      createdYear.value = saved
      step.value = 2
      await load() // la nouvelle année apparaît ; la modale reste ouverte
      return
    }

    if (saved.is_current) schoolYearStore.markCurrent(saved.id)
    closeForm()
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

/** Étape 2 terminée : éventuellement rendre la nouvelle année courante, puis fermer. */
async function finishCreate(makeCurrent: boolean): Promise<void> {
  if (makeCurrent && createdYear.value) {
    await setCurrent(createdYear.value)
  }
  closeForm()
  await load()
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
        <p class="eyebrow">Scolarité</p>
        <h1>Années scolaires</h1>
        <p class="heading-copy">
          Organiser les périodes, trimestres et archives de l'établissement.
        </p>
      </div>
      <button type="button" class="btn-primary page-cta" @click="openCreate">
        <Plus class="cta-icon" aria-hidden="true" />
        Nouvelle année
      </button>
    </div>

    <p v-if="error" class="alert alert-error">{{ error }}</p>

    <div class="summary-grid">
      <div class="summary-card">
        <div class="summary-card-header">
          <span class="summary-label">Années</span>
          <div class="summary-icon"><CalendarDays /></div>
        </div>
        <strong>{{ items.length }}</strong>
        <span class="summary-note">enregistrées</span>
      </div>
      <div class="summary-card summary-card--current">
        <div class="summary-card-header">
          <span class="summary-label">Courante</span>
          <div class="summary-icon summary-icon--current"><Star /></div>
        </div>
        <strong>{{ currentYear?.name ?? '—' }}</strong>
        <span class="summary-note">{{ currentYear ? periodLabel(currentYear) : 'non définie' }}</span>
      </div>
      <div class="summary-card">
        <div class="summary-card-header">
          <span class="summary-label">Trimestres</span>
          <div class="summary-icon"><BookOpen /></div>
        </div>
        <strong>{{ totalTerms }}</strong>
        <span class="summary-note">sur toutes les années</span>
      </div>
      <div class="summary-card">
        <div class="summary-card-header">
          <span class="summary-label">Archives</span>
          <div class="summary-icon summary-icon--muted"><Archive /></div>
        </div>
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
        <button type="button" class="btn-secondary refresh-btn" @click="load" :disabled="loading">
          <RefreshCw class="refresh-icon" :class="{ spinning: loading }" aria-hidden="true" />
          {{ loading ? 'Actualisation…' : 'Actualiser' }}
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
              <tr
                v-for="(item, index) in items"
                :key="item.id"
                :class="{ current: item.is_current }"
                class="clickable-row"
                @click="router.push({ name: 'school-year-detail', params: { id: item.id } })"
              >
                <td>
                  <span class="year-name">{{ item.name }}</span>
                </td>
                <td>{{ periodLabel(item) }}</td>
                <td>{{ item.terms?.length ?? 0 }}</td>
                <td>
                  <span v-if="item.is_current" class="badge badge-success">Courante</span>
                  <span v-else class="badge badge-muted">Archivée</span>
                </td>
                <td class="actions-cell" @click.stop>
                  <RowActionMenu
                    :open-up="items.length > 3 && index >= items.length - 2"
                    :aria-label="`Actions pour ${item.name}`"
                  >
                    <RouterLink :to="{ name: 'school-year-detail', params: { id: item.id } }">
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
          <article
            v-for="item in items"
            :key="item.id"
            class="year-mobile-card"
            :class="{ 'year-mobile-card--current': item.is_current }"
            @click="router.push({ name: 'school-year-detail', params: { id: item.id } })"
          >
            <div class="mobile-card-top">
              <div>
                <span class="year-name">{{ item.name }}</span>
                <p>{{ periodLabel(item) }}</p>
              </div>
              <span v-if="item.is_current" class="badge badge-success">Courante</span>
              <span v-else class="badge badge-muted">Archivée</span>
            </div>
            <div class="mobile-meta">
              <span>{{ item.terms?.length ?? 0 }} trimestre(s)</span>
            </div>
            <div class="mobile-actions" @click.stop>
              <RowActionMenu :aria-label="`Actions pour ${item.name}`">
                <RouterLink :to="{ name: 'school-year-detail', params: { id: item.id } }">
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
      :title="modalTitle"
      :size="step === 2 ? 'xlarge' : 'default'"
      @close="closeForm"
    >
      <!-- ÉTAPE 1 : création de l'année -->
      <form v-if="step === 1" id="school-year-form" class="year-form" @submit.prevent="submit">
        <div v-if="!editing" class="autofill-hint">
          <span>
            Nom et dates pré-remplis pour l'année suivante. Vous pouvez les modifier librement.
          </span>
          <button type="button" class="autofill-btn" @click="applyAutoFill">
            <RefreshCw class="autofill-icon" aria-hidden="true" />
            Recalculer
          </button>
        </div>

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

        <!-- Année courante : caché en mode passage (on la rendra courante après le passage) -->
        <label v-if="editing || !canPromoteOnCreate" class="check-row">
          <input v-model="form.is_current" type="checkbox" />
          <span>
            <strong>Définir comme année courante</strong>
            <small>Les autres années seront automatiquement archivées.</small>
          </span>
        </label>
        <p v-else class="autofill-hint passage-hint">
          <span>
            Après création, vous pourrez <strong>faire passer les élèves de l'année précédente</strong>
            vers cette nouvelle année (étape suivante).
          </span>
        </p>

        <p v-if="formError" class="alert alert-error">{{ formError }}</p>
      </form>

      <!-- ÉTAPE 2 : passage de classe vers la nouvelle année -->
      <div v-else-if="step === 2 && createdYear && passageSource" class="passage-step">
        <p class="autofill-hint">
          <span>
            Année <strong>{{ createdYear.name }}</strong> créée (pas encore courante). Faites passer
            les élèves de <strong>{{ passageSource.name }}</strong> vers cette nouvelle année, ou
            cliquez « Terminer » pour le faire plus tard.
          </span>
        </p>
        <PromotionPanel :fromYear="passageSource" :lockTo="createdYear" @committed="load" />
      </div>

      <template #footer>
        <template v-if="step === 1">
          <button type="button" @click="closeForm">Annuler</button>
          <button
            type="submit"
            form="school-year-form"
            class="btn-primary"
            :disabled="submitting"
          >
            {{ submitting ? 'Enregistrement...' : editing ? 'Enregistrer' : (canPromoteOnCreate ? 'Créer et continuer' : 'Créer') }}
          </button>
        </template>
        <template v-else>
          <button type="button" @click="finishCreate(false)">Terminer</button>
          <button type="button" class="btn-primary" @click="finishCreate(true)">
            Rendre {{ createdYear?.name }} courante &amp; terminer
          </button>
        </template>
      </template>
    </Modal>
  </section>
</template>

<style scoped>
/* ── Page shell ── */
.school-years-page {
  display: flex;
  flex-direction: column;
  gap: 1.35rem;
}

/* ── Page heading ── */
.page-heading {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
}

.page-heading h1 { margin: 0; }

.eyebrow {
  margin: 0 0 0.12rem;
  color: var(--primary);
  font-size: 0.7rem;
  font-weight: 800;
  letter-spacing: 0.1em;
  text-transform: uppercase;
}

.heading-copy {
  max-width: 42rem;
  margin: 0.3rem 0 0;
  color: var(--text-soft);
  font-size: 0.92rem;
}

.page-cta {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  white-space: nowrap;
  flex-shrink: 0;
}

.cta-icon { width: 0.95rem; height: 0.95rem; }

/* ── Summary cards ── */
.summary-grid {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 1rem;
}

.summary-card {
  position: relative;
  min-height: 7rem;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  gap: 0.35rem;
  padding: 1.1rem 1.15rem 1rem;
  border: 1px solid var(--border);
  border-top: 3px solid var(--border);
  border-radius: var(--radius);
  background: var(--bg-card);
  box-shadow: var(--shadow-card);
  transition: box-shadow 0.2s ease, transform 0.2s ease;
  overflow: hidden;
}

.summary-card:hover {
  box-shadow: var(--shadow-hover);
}

/* Current year card gets sky-blue top accent */
.summary-card--current {
  border-top-color: var(--primary);
  background: linear-gradient(160deg, var(--bg-card) 0%, var(--primary-soft) 100%);
}

.summary-card--current::after {
  content: '';
  position: absolute;
  top: -20%;
  right: -10%;
  width: 45%;
  height: 70%;
  background: radial-gradient(circle, rgba(3, 105, 161, 0.07) 0%, transparent 70%);
  pointer-events: none;
}

.summary-card-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  margin-bottom: 0.45rem;
}

.summary-label {
  color: var(--text-soft);
  font-size: 0.68rem;
  font-weight: 800;
  letter-spacing: 0.1em;
  text-transform: uppercase;
}

.summary-icon {
  width: 2rem;
  height: 2rem;
  display: grid;
  place-items: center;
  border-radius: 8px;
  background: var(--bg-subtle);
  color: var(--text-soft);
  flex-shrink: 0;
}

.summary-icon svg { width: 0.95rem; height: 0.95rem; stroke-width: 2; }

.summary-icon--current {
  background: var(--primary-soft);
  color: var(--primary);
}

.summary-icon--muted {
  background: var(--bg-soft);
  color: var(--text-muted);
}

.summary-card strong {
  display: block;
  overflow: hidden;
  color: var(--text);
  font-size: 1.55rem;
  font-weight: 900;
  line-height: 1;
  text-overflow: ellipsis;
  white-space: nowrap;
  letter-spacing: -0.02em;
}

.summary-card--current strong { color: var(--primary-dark); }

.summary-note {
  display: block;
  overflow: hidden;
  color: var(--text-muted);
  font-size: 0.75rem;
  font-weight: 600;
  text-overflow: ellipsis;
  white-space: nowrap;
  margin-top: 0.25rem;
}

/* ── Table card ── */
.years-card-header h2 { margin: 0; }

.years-card-header p {
  margin: 0.2rem 0 0;
  color: var(--text-soft);
  font-size: 0.84rem;
}

.refresh-btn {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
}

.refresh-icon { width: 0.85rem; height: 0.85rem; }
.spinning { animation: spin 0.85s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }

.table-wrap { overflow: visible; }
.years-card  { overflow: visible; }
.years-table { min-width: 58rem; }

/* Current row — left accent + subtle tint */
.years-table tr.current td {
  background: linear-gradient(90deg, rgba(3, 105, 161, 0.04) 0%, rgba(3, 105, 161, 0.01) 100%);
}

.years-table tr.current td:first-child {
  border-left: 3px solid var(--primary);
  padding-left: calc(0.85rem - 3px);
}

/* Ligne cliquable — toute la tr navigue */
.clickable-row {
  cursor: pointer;
  transition: background 0.15s ease;
}

.clickable-row:hover td {
  background: var(--bg-subtle) !important;
}

.clickable-row:active td {
  background: var(--primary-soft) !important;
}

.year-name {
  font-weight: 800;
  color: var(--text);
}

/* Carte mobile cliquable */
.year-mobile-card {
  cursor: pointer;
  transition: box-shadow 0.15s ease, transform 0.15s ease;
}

.year-mobile-card:hover {
  box-shadow: var(--shadow-hover);
}

.year-mobile-card--current {
  border-left: 3px solid var(--primary);
}

.actions-heading { text-align: right; }
.actions-cell    { text-align: right; white-space: nowrap; }

/* ── Mobile cards ── */
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

/* ── Skeleton ── */
.year-list-skeleton {
  display: grid;
  gap: 0.65rem;
  padding: 1rem;
}

.year-skeleton-row {
  height: 3.4rem;
  border-radius: var(--radius);
  background: linear-gradient(90deg, var(--bg-subtle) 0%, var(--bg-soft) 50%, var(--bg-subtle) 100%);
  background-size: 200% 100%;
  animation: shimmer 1.4s infinite ease-in-out;
}

@keyframes shimmer {
  0%   { background-position: 200% 0; }
  100% { background-position: -200% 0; }
}

/* ── Form ── */
.autofill-hint {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 0.7rem 0.85rem;
  border: 1px solid var(--primary-tint);
  border-radius: var(--radius);
  background: var(--primary-soft);
  color: var(--text-soft);
  font-size: 0.82rem;
  line-height: 1.35;
}

.autofill-btn {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  flex-shrink: 0;
  padding: 0.35rem 0.65rem;
  border: 1px solid var(--primary-tint);
  border-radius: var(--radius);
  background: var(--bg-card);
  color: var(--primary);
  font-size: 0.78rem;
  font-weight: 700;
  cursor: pointer;
  transition: background 0.15s ease, border-color 0.15s ease;
}

.autofill-btn:hover {
  background: var(--primary-soft);
  border-color: var(--primary);
}

.autofill-icon { width: 0.8rem; height: 0.8rem; }

.form-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.75rem;
}

.check-row {
  display: flex;
  align-items: flex-start;
  gap: 0.65rem;
  padding: 0.8rem;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: var(--bg-subtle);
  cursor: pointer;
  transition: border-color 0.15s ease, background 0.15s ease;
}

.check-row:has(input:checked) {
  border-color: var(--primary-tint);
  background: var(--primary-soft);
}

.check-row input { width: auto; margin-top: 0.15rem; }

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

/* ── Responsive ── */
@media (max-width: 920px) {
  .summary-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}

@media (max-width: 720px) {
  .page-heading { flex-direction: column; }
  .page-heading .btn-primary { width: 100%; justify-content: center; }
  .summary-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .table-wrap   { display: none; }
  .year-mobile-list { display: grid; }
  .form-grid    { grid-template-columns: 1fr; }
}

@media (max-width: 480px) {
  .summary-grid { grid-template-columns: 1fr; }
}
</style>
