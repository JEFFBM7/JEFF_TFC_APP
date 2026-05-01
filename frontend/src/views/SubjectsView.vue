<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { api, ApiError } from '../api/client'
import type { ApiResource, Paginated, Subject } from '../types'
import Modal from '../components/Modal.vue'

const items = ref<Subject[]>([])
const loading = ref(false)
const error = ref('')

const showForm = ref(false)
const editing = ref<Subject | null>(null)
const formError = ref('')
const formErrors = reactive<Record<string, string[]>>({})
const submitting = ref(false)

const form = reactive({ name: '', description: '' })

function resetForm(): void {
  form.name = ''
  form.description = ''
  formError.value = ''
  Object.keys(formErrors).forEach((k) => delete formErrors[k])
}

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    const res = await api<Paginated<Subject>>('/api/v1/subjects')
    items.value = res.data
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Erreur de chargement.'
  } finally {
    loading.value = false
  }
}

function openCreate(): void {
  editing.value = null
  resetForm()
  showForm.value = true
}

function openEdit(item: Subject): void {
  editing.value = item
  form.name = item.name
  form.description = item.description ?? ''
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
      await api<ApiResource<Subject>>(`/api/v1/subjects/${editing.value.id}`, {
        method: 'PUT',
        body: { ...form },
      })
    } else {
      await api<ApiResource<Subject>>('/api/v1/subjects', { method: 'POST', body: { ...form } })
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

async function remove(item: Subject): Promise<void> {
  if (!confirm(`Supprimer la matière "${item.name}" ?`)) return
  try {
    await api(`/api/v1/subjects/${item.id}`, { method: 'DELETE' })
    await load()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Suppression impossible.'
  }
}

onMounted(load)
</script>

<template>
  <section>
    <div class="card">
      <div class="card-header">
        <h1 style="margin: 0">Matières</h1>
        <button type="button" class="btn-primary" @click="openCreate">+ Nouvelle matière</button>
      </div>

      <p v-if="error" class="alert alert-error" style="margin: 1rem">{{ error }}</p>
      <div v-if="loading" class="empty-state">Chargement…</div>
      <div v-else-if="items.length === 0" class="empty-state">Aucune matière enregistrée.</div>

      <table v-else>
        <thead>
          <tr>
            <th>Nom</th>
            <th>Description</th>
            <th style="width: 1%; text-align: right; white-space: nowrap">Actions</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="item in items" :key="item.id">
            <td>{{ item.name }}</td>
            <td style="color: var(--text-soft)">{{ item.description ?? '—' }}</td>
            <td style="text-align: right; white-space: nowrap">
              <button type="button" @click="openEdit(item)">Modifier</button>
              <button type="button" class="btn-danger" @click="remove(item)">Supprimer</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <Modal
      :open="showForm"
      :title="editing ? 'Modifier une matière' : 'Nouvelle matière'"
      @close="showForm = false"
    >
      <form id="subject-form" @submit.prevent="submit">
        <div class="field">
          <label for="s-name">Nom</label>
          <input id="s-name" v-model="form.name" type="text" required maxlength="128" />
          <small v-if="formErrors.name" class="err">{{ formErrors.name[0] }}</small>
        </div>
        <div class="field">
          <label for="s-desc">Description (optionnel)</label>
          <textarea id="s-desc" v-model="form.description" rows="2" maxlength="255" />
        </div>
        <p v-if="formError" class="alert alert-error">{{ formError }}</p>
      </form>
      <template #footer>
        <button type="button" @click="showForm = false">Annuler</button>
        <button type="submit" form="subject-form" class="btn-primary" :disabled="submitting">
          {{ submitting ? 'Enregistrement…' : editing ? 'Enregistrer' : 'Créer' }}
        </button>
      </template>
    </Modal>
  </section>
</template>

<style scoped>
button + button { margin-left: 0.4rem; }
.err { display: block; color: var(--danger); font-size: 0.78rem; margin-top: 0.25rem; }
</style>
