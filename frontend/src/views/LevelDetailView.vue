<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { api, ApiError } from '../api/client'
import type { ApiResource, ClassRoom, Level, Paginated, SchoolOption, SchoolOptionFiliere } from '../types'
import Modal from '../components/Modal.vue'
import { useConfirmStore } from '../stores/confirm'

const props = defineProps<{ id: string | number }>()
const confirmDialog = useConfirmStore()
const cycleLabels = { maternel: 'Maternelle', primaire: 'Primaire', cteb: 'CTEB', secondaire: 'Secondaire' }

const FILIERE_OPTIONS: Array<{ value: SchoolOptionFiliere; label: string }> = [
  { value: 'generale', label: 'Humanités générales' },
  { value: 'technique', label: 'Humanités techniques' },
  { value: 'professionnelle', label: 'Humanités professionnelles' },
]

function filiereLabel(value?: SchoolOptionFiliere | null): string {
  if (!value) return ''
  return FILIERE_OPTIONS.find((item) => item.value === value)?.label ?? ''
}

const level = ref<Level | null>(null)
const loading = ref(false)
const error = ref('')

const showForm = ref(false)
const editing = ref<ClassRoom | null>(null)
const formError = ref('')
const formErrors = reactive<Record<string, string[]>>({})
const submitting = ref(false)
const schoolOptions = ref<SchoolOption[]>([])
const optionName = ref('')
const optionFiliere = ref<SchoolOptionFiliere | ''>('')
const optionError = ref('')
const optionCreating = ref(false)

const form = reactive<{ section: string; school_option_id: number | '' }>({
  section: '',
  school_option_id: '',
})
const isSecondary = computed(() => level.value?.cycle === 'secondaire')
const cycleLabel = computed(() => (level.value ? cycleLabels[level.value.cycle] : ''))

function resetForm(): void {
  form.section = ''
  form.school_option_id = ''
  optionName.value = ''
  optionFiliere.value = ''
  optionError.value = ''
  formError.value = ''
  Object.keys(formErrors).forEach((k) => delete formErrors[k])
}

function sortOptions(options: SchoolOption[]): SchoolOption[] {
  return [...options].sort((a, b) => a.name.localeCompare(b.name, 'fr'))
}

async function loadSchoolOptions(): Promise<void> {
  const res = await api<Paginated<SchoolOption>>('/api/v1/school-options')
  schoolOptions.value = sortOptions(res.data)
}

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    const res = await api<ApiResource<Level>>(`/api/v1/levels/${props.id}`)
    level.value = res.data
    if (res.data.cycle === 'secondaire') {
      await loadSchoolOptions()
    }
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Niveau introuvable.'
  } finally {
    loading.value = false
  }
}

function openCreate(): void {
  editing.value = null
  resetForm()
  showForm.value = true
}

function openEdit(cr: ClassRoom): void {
  editing.value = cr
  form.section = cr.section
  form.school_option_id =
    cr.school_option_id ?? schoolOptions.value.find((option) => option.name === cr.option)?.id ?? ''
  formError.value = ''
  Object.keys(formErrors).forEach((k) => delete formErrors[k])
  showForm.value = true
}

async function createSchoolOption(): Promise<void> {
  const name = optionName.value.trim()
  if (!name) return

  optionCreating.value = true
  optionError.value = ''
  try {
    const res = await api<ApiResource<SchoolOption>>('/api/v1/school-options', {
      method: 'POST',
      body: { name, filiere: optionFiliere.value || null },
    })
    schoolOptions.value = sortOptions([
      ...schoolOptions.value.filter((option) => option.id !== res.data.id),
      res.data,
    ])
    form.school_option_id = res.data.id
    optionName.value = ''
    optionFiliere.value = ''
  } catch (e) {
    optionError.value = e instanceof ApiError ? e.message : "Création de l'option impossible."
  } finally {
    optionCreating.value = false
  }
}

async function submit(): Promise<void> {
  if (!level.value) return
  submitting.value = true
  formError.value = ''
  Object.keys(formErrors).forEach((k) => delete formErrors[k])
  const payload = {
    level_id: level.value.id,
    section: form.section,
    school_option_id:
      isSecondary.value && form.school_option_id !== '' ? Number(form.school_option_id) : null,
  }
  try {
    if (editing.value) {
      await api<ApiResource<ClassRoom>>(`/api/v1/classrooms/${editing.value.id}`, {
        method: 'PUT',
        body: payload,
      })
    } else {
      await api<ApiResource<ClassRoom>>('/api/v1/classrooms', { method: 'POST', body: payload })
    }
    showForm.value = false
    await load()
  } catch (e) {
    if (e instanceof ApiError) {
      formError.value = e.message
      if (e.errors) Object.assign(formErrors, e.errors)
    } else {
      formError.value = 'Erreur réseau.'
    }
  } finally {
    submitting.value = false
  }
}

async function remove(cr: ClassRoom): Promise<void> {
  const ok = await confirmDialog.ask({
    title: 'Supprimer une classe',
    message: 'Cette classe sera supprimée.',
    details: [cr.full_name ?? 'Classe'],
    note: 'Vérifie qu’aucun élève ou planning actif ne dépend encore de cette classe.',
    confirmLabel: 'Supprimer',
    variant: 'danger',
  })
  if (!ok) return
  try {
    await api(`/api/v1/classrooms/${cr.id}`, { method: 'DELETE' })
    await load()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Suppression impossible.'
  }
}

onMounted(load)
</script>

<template>
  <section>
    <p style="margin-bottom: 0.75rem">
      <RouterLink :to="{ name: 'levels' }">← Retour aux classes</RouterLink>
    </p>

    <p v-if="error" class="alert alert-error">{{ error }}</p>

    <template v-if="level">
      <div class="card" style="margin-bottom: 1rem">
        <div class="card-header">
          <div>
            <h1 style="margin: 0">{{ level.name }}</h1>
            <p style="margin: 0.25rem 0 0; color: var(--text-soft); font-size: 0.9rem">
              <span translate="no">{{ cycleLabel }}</span> · Ordre d'affichage : {{ level.order }}
            </p>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-header">
          <h2 style="margin: 0" translate="no">Classes</h2>
          <button type="button" class="btn-primary" @click="openCreate">+ Nouvelle classe</button>
        </div>

        <div v-if="loading" class="empty-state">Chargement…</div>
        <div v-else-if="!level.classrooms || level.classrooms.length === 0" class="empty-state">
          Aucune classe définie pour ce niveau de classe.
        </div>

        <table v-else>
          <thead>
            <tr>
              <th>Classe</th>
              <th v-if="isSecondary">Option</th>
              <th v-if="isSecondary">Filière</th>
              <th>Section</th>
              <th>Effectif</th>
              <th style="width: 1%; text-align: right; white-space: nowrap">Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="cr in level.classrooms" :key="cr.id">
              <td>{{ cr.full_name }}</td>
              <td v-if="isSecondary">{{ cr.option }}</td>
              <td v-if="isSecondary">
                <span v-if="cr.school_option?.filiere" class="filiere-badge" :class="`filiere-${cr.school_option.filiere}`">
                  {{ filiereLabel(cr.school_option.filiere) }}
                </span>
                <span v-else class="filiere-empty">—</span>
              </td>
              <td>{{ cr.section }}</td>
              <td>{{ cr.student_count ?? 0 }} élève(s)</td>
              <td style="text-align: right; white-space: nowrap">
                <button type="button" @click="openEdit(cr)">Modifier</button>
                <button type="button" class="btn-danger" @click="remove(cr)">Supprimer</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>

    <Modal
      :open="showForm"
      :title="editing ? 'Modifier une classe' : 'Nouvelle classe'"
      @close="showForm = false"
    >
      <form id="cr-form" @submit.prevent="submit">
        <div class="field">
          <label for="cr-section">Section (ex. A, B)</label>
          <input id="cr-section" v-model="form.section" type="text" required maxlength="16" />
          <small v-if="formErrors.section" class="err">{{ formErrors.section[0] }}</small>
        </div>
        <div v-if="isSecondary" class="field">
          <label for="cr-option">Option (ex. Mécanique)</label>
          <select id="cr-option" v-model="form.school_option_id" required>
            <option value="" disabled>— Sélectionner une option —</option>
            <option v-for="option in schoolOptions" :key="option.id" :value="option.id">
              {{ option.name }}{{ option.filiere ? ` — ${filiereLabel(option.filiere)}` : '' }}
            </option>
          </select>
          <small v-if="formErrors.option" class="err">{{ formErrors.option[0] }}</small>
          <small v-if="formErrors.school_option_id" class="err">
            {{ formErrors.school_option_id[0] }}
          </small>
        </div>
        <div v-if="isSecondary" class="option-create-row">
          <input
            v-model="optionName"
            type="text"
            maxlength="64"
            placeholder="Nouvelle option"
            @keydown.enter.prevent="createSchoolOption"
          />
          <select v-model="optionFiliere" aria-label="Filière de la nouvelle option">
            <option value="">Filière (optionnelle)</option>
            <option v-for="filiere in FILIERE_OPTIONS" :key="filiere.value" :value="filiere.value">
              {{ filiere.label }}
            </option>
          </select>
          <button type="button" :disabled="optionCreating || !optionName.trim()" @click="createSchoolOption">
            {{ optionCreating ? 'Création…' : 'Créer' }}
          </button>
        </div>
        <small v-if="optionError" class="err">{{ optionError }}</small>
        <p v-if="formError" class="alert alert-error">{{ formError }}</p>
      </form>
      <template #footer>
        <button type="button" @click="showForm = false">Annuler</button>
        <button type="submit" form="cr-form" class="btn-primary" :disabled="submitting">
          {{ submitting ? 'Enregistrement…' : editing ? 'Enregistrer' : 'Créer' }}
        </button>
      </template>
    </Modal>
  </section>
</template>

<style scoped>
button + button { margin-left: 0.4rem; }
.err { display: block; color: var(--danger); font-size: 0.78rem; margin-top: 0.25rem; }
.option-create-row {
  align-items: center;
  display: grid;
  gap: 0.5rem;
  grid-template-columns: minmax(0, 1fr) minmax(0, auto) auto;
  margin-bottom: 0.9rem;
}
.option-create-row select { min-height: 2.35rem; min-width: 11rem; }
.option-create-row button { margin-left: 0; }
.filiere-badge {
  display: inline-flex;
  align-items: center;
  padding: 0.18rem 0.55rem;
  border-radius: 999px;
  font-size: 0.74rem;
  font-weight: 700;
  letter-spacing: 0.01em;
  white-space: nowrap;
}
.filiere-generale {
  background: var(--primary-soft);
  color: #3730a3;
  border: 1px solid #c7d2fe;
}
.filiere-technique {
  background: var(--warn-soft);
  color: var(--warn);
  border: 1px solid rgba(251, 191, 36, 0.3);
}
.filiere-professionnelle {
  background: var(--success-soft);
  color: var(--success);
  border: 1px solid rgba(74, 222, 128, 0.3);
}
.filiere-empty { color: var(--text-soft); font-size: 0.85rem; }
@media (max-width: 640px) {
  .option-create-row { grid-template-columns: 1fr; }
}
</style>
