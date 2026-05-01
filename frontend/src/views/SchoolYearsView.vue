<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { api, ApiError } from '../api/client'
import type { ApiResource, Paginated, SchoolYear } from '../types'
import Modal from '../components/Modal.vue'

const items = ref<SchoolYear[]>([])
const loading = ref(false)
const error = ref('')

const showForm = ref(false)
const editing = ref<SchoolYear | null>(null)
const formError = ref('')
const formErrors = reactive<Record<string, string[]>>({})
const submitting = ref(false)

const form = reactive({
  name: '',
  starts_on: '',
  ends_on: '',
  is_current: false,
})

function resetForm(): void {
  form.name = ''
  form.starts_on = ''
  form.ends_on = ''
  form.is_current = false
  formError.value = ''
  Object.keys(formErrors).forEach((k) => delete formErrors[k])
}

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    const res = await api<Paginated<SchoolYear>>('/api/v1/school-years')
    items.value = res.data
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
    if (editing.value) {
      await api<ApiResource<SchoolYear>>(`/api/v1/school-years/${editing.value.id}`, {
        method: 'PUT',
        body: { ...form },
      })
    } else {
      await api<ApiResource<SchoolYear>>('/api/v1/school-years', {
        method: 'POST',
        body: { ...form },
      })
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
  if (!confirm(`Supprimer l'année "${item.name}" et ses trimestres ?`)) return
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
    await load()
  } catch (err) {
    error.value = err instanceof ApiError ? err.message : 'Impossible de définir l\'année courante.'
  }
}

onMounted(load)
</script>

<template>
  <section>
    <div class="card">
      <div class="card-header">
        <h1 style="margin: 0">Années scolaires</h1>
        <button type="button" class="btn-primary" @click="openCreate">+ Nouvelle année</button>
      </div>

      <p v-if="error" class="alert alert-error" style="margin: 1rem">{{ error }}</p>

      <div v-if="loading" class="empty-state">Chargement…</div>
      <div v-else-if="items.length === 0" class="empty-state">
        Aucune année scolaire enregistrée.
      </div>

      <table v-else>
        <thead>
          <tr>
            <th>Nom</th>
            <th>Période</th>
            <th>Statut</th>
            <th style="width: 1%; text-align: right; white-space: nowrap">Actions</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="item in items" :key="item.id">
            <td>
              <RouterLink :to="{ name: 'school-year-detail', params: { id: item.id } }">
                {{ item.name }}
              </RouterLink>
            </td>
            <td>{{ item.starts_on }} → {{ item.ends_on }}</td>
            <td>
              <span v-if="item.is_current" class="badge badge-success">Courante</span>
              <span v-else class="badge badge-muted">Archivée</span>
            </td>
            <td style="text-align: right; white-space: nowrap">
              <button v-if="!item.is_current" type="button" @click="setCurrent(item)">
                Définir comme courante
              </button>
              <button type="button" @click="openEdit(item)">Modifier</button>
              <button type="button" class="btn-danger" @click="remove(item)">Supprimer</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <Modal
      :open="showForm"
      :title="editing ? 'Modifier une année scolaire' : 'Nouvelle année scolaire'"
      @close="showForm = false"
    >
      <form id="school-year-form" @submit.prevent="submit">
        <div class="field">
          <label for="sy-name">Nom (ex. 2026-2027)</label>
          <input id="sy-name" v-model="form.name" type="text" required maxlength="32" />
          <small v-if="formErrors.name" class="err">{{ formErrors.name[0] }}</small>
        </div>

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

        <div class="field">
          <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer">
            <input v-model="form.is_current" type="checkbox" style="width: auto" />
            Définir comme année courante
          </label>
        </div>

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
