<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { api, ApiError } from '../api/client'
import type { ApiResource, SchoolYear, Term } from '../types'
import Modal from '../components/Modal.vue'

const props = defineProps<{ id: string | number }>()

const year = ref<SchoolYear | null>(null)
const loading = ref(false)
const error = ref('')

const showForm = ref(false)
const editing = ref<Term | null>(null)
const formError = ref('')
const formErrors = reactive<Record<string, string[]>>({})
const submitting = ref(false)

const form = reactive({
  name: '',
  position: 1,
  starts_on: '',
  ends_on: '',
})

function resetForm(): void {
  form.name = ''
  form.position = (year.value?.terms?.length ?? 0) + 1
  form.starts_on = ''
  form.ends_on = ''
  formError.value = ''
  Object.keys(formErrors).forEach((k) => delete formErrors[k])
}

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    const res = await api<ApiResource<SchoolYear>>(`/api/v1/school-years/${props.id}`)
    year.value = res.data
  } catch (err) {
    error.value = err instanceof ApiError ? err.message : 'Année introuvable.'
  } finally {
    loading.value = false
  }
}

function openCreate(): void {
  editing.value = null
  resetForm()
  showForm.value = true
}

function openEdit(term: Term): void {
  editing.value = term
  form.name = term.name
  form.position = term.position
  form.starts_on = term.starts_on
  form.ends_on = term.ends_on
  formError.value = ''
  Object.keys(formErrors).forEach((k) => delete formErrors[k])
  showForm.value = true
}

async function submit(): Promise<void> {
  if (!year.value) return
  submitting.value = true
  formError.value = ''
  Object.keys(formErrors).forEach((k) => delete formErrors[k])

  const payload = {
    school_year_id: year.value.id,
    name: form.name,
    position: form.position,
    starts_on: form.starts_on,
    ends_on: form.ends_on,
  }

  try {
    if (editing.value) {
      await api<ApiResource<Term>>(`/api/v1/terms/${editing.value.id}`, {
        method: 'PUT',
        body: payload,
      })
    } else {
      await api<ApiResource<Term>>('/api/v1/terms', { method: 'POST', body: payload })
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

async function remove(term: Term): Promise<void> {
  if (!confirm(`Supprimer le trimestre "${term.name}" ?`)) return
  try {
    await api(`/api/v1/terms/${term.id}`, { method: 'DELETE' })
    await load()
  } catch (err) {
    error.value = err instanceof ApiError ? err.message : 'Suppression impossible.'
  }
}

const closing = ref<number | null>(null)

async function closeTerm(term: Term): Promise<void> {
  if (!confirm(`Clôturer le trimestre "${term.name}" ?\n\nUn e-mail avec le bulletin PDF sera envoyé à chaque parent. Cette action ne peut pas être annulée facilement.`)) {
    return
  }
  closing.value = term.id
  try {
    const res = await api<{ message: string; students_notified: number; parents_notified: number }>(
      `/api/v1/terms/${term.id}/close`,
      { method: 'POST' },
    )
    alert(res.message)
    await load()
  } catch (err) {
    error.value = err instanceof ApiError ? err.message : 'Clôture impossible.'
  } finally {
    closing.value = null
  }
}

onMounted(load)
</script>

<template>
  <section>
    <p style="margin-bottom: 0.75rem">
      <RouterLink :to="{ name: 'school-years' }">← Retour à la liste</RouterLink>
    </p>

    <p v-if="error" class="alert alert-error">{{ error }}</p>

    <template v-if="year">
      <div class="card" style="margin-bottom: 1rem">
        <div class="card-header">
          <div>
            <h1 style="margin: 0">{{ year.name }}</h1>
            <p style="margin: 0.25rem 0 0; color: var(--text-soft); font-size: 0.9rem">
              {{ year.starts_on }} → {{ year.ends_on }}
            </p>
          </div>
          <span v-if="year.is_current" class="badge badge-success">Année courante</span>
        </div>
      </div>

      <div class="card">
        <div class="card-header">
          <h2 style="margin: 0">Trimestres</h2>
          <button type="button" class="btn-primary" @click="openCreate">+ Nouveau trimestre</button>
        </div>

        <div v-if="loading" class="empty-state">Chargement…</div>
        <div v-else-if="!year.terms || year.terms.length === 0" class="empty-state">
          Aucun trimestre n'est encore défini pour cette année.
        </div>

        <table v-else>
          <thead>
            <tr>
              <th style="width: 4rem">#</th>
              <th>Nom</th>
              <th>Période</th>
              <th>Statut</th>
              <th style="width: 1%; text-align: right; white-space: nowrap">Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="t in year.terms" :key="t.id">
              <td>{{ t.position }}</td>
              <td>{{ t.name }}</td>
              <td>{{ t.starts_on }} → {{ t.ends_on }}</td>
              <td>
                <span v-if="t.is_closed" class="badge badge-success">Clôturé</span>
                <span v-else class="badge badge-muted">Ouvert</span>
              </td>
              <td style="text-align: right; white-space: nowrap">
                <button
                  v-if="!t.is_closed"
                  type="button"
                  :disabled="closing === t.id"
                  @click="closeTerm(t)"
                >
                  {{ closing === t.id ? 'Clôture…' : 'Clôturer' }}
                </button>
                <button type="button" @click="openEdit(t)">Modifier</button>
                <button type="button" class="btn-danger" @click="remove(t)">Supprimer</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>

    <Modal
      :open="showForm"
      :title="editing ? 'Modifier un trimestre' : 'Nouveau trimestre'"
      @close="showForm = false"
    >
      <form id="term-form" @submit.prevent="submit">
        <div class="field">
          <label for="t-name">Nom</label>
          <input id="t-name" v-model="form.name" type="text" required maxlength="64" />
          <small v-if="formErrors.name" class="err">{{ formErrors.name[0] }}</small>
        </div>

        <div class="field">
          <label for="t-pos">Position (1 à 6)</label>
          <input id="t-pos" v-model.number="form.position" type="number" min="1" max="6" required />
          <small v-if="formErrors.position" class="err">{{ formErrors.position[0] }}</small>
        </div>

        <div class="field">
          <label for="t-starts">Date de début</label>
          <input id="t-starts" v-model="form.starts_on" type="date" required />
          <small v-if="formErrors.starts_on" class="err">{{ formErrors.starts_on[0] }}</small>
        </div>

        <div class="field">
          <label for="t-ends">Date de fin</label>
          <input id="t-ends" v-model="form.ends_on" type="date" required />
          <small v-if="formErrors.ends_on" class="err">{{ formErrors.ends_on[0] }}</small>
        </div>

        <p v-if="formError" class="alert alert-error">{{ formError }}</p>
      </form>

      <template #footer>
        <button type="button" @click="showForm = false">Annuler</button>
        <button type="submit" form="term-form" class="btn-primary" :disabled="submitting">
          {{ submitting ? 'Enregistrement…' : editing ? 'Enregistrer' : 'Créer' }}
        </button>
      </template>
    </Modal>
  </section>
</template>

<style scoped>
button + button {
  margin-left: 0.4rem;
}
.err {
  display: block;
  color: var(--danger);
  font-size: 0.78rem;
  margin-top: 0.25rem;
}
</style>
