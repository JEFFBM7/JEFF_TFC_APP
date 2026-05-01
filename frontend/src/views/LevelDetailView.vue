<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { api, ApiError } from '../api/client'
import type { ApiResource, ClassRoom, Level } from '../types'
import Modal from '../components/Modal.vue'

const props = defineProps<{ id: string | number }>()

const level = ref<Level | null>(null)
const loading = ref(false)
const error = ref('')

const showForm = ref(false)
const editing = ref<ClassRoom | null>(null)
const formError = ref('')
const formErrors = reactive<Record<string, string[]>>({})
const submitting = ref(false)

const form = reactive({ section: '', capacity: 30 })

function resetForm(): void {
  form.section = ''
  form.capacity = 30
  formError.value = ''
  Object.keys(formErrors).forEach((k) => delete formErrors[k])
}

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    const res = await api<ApiResource<Level>>(`/api/v1/levels/${props.id}`)
    level.value = res.data
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
  form.capacity = cr.capacity
  formError.value = ''
  Object.keys(formErrors).forEach((k) => delete formErrors[k])
  showForm.value = true
}

async function submit(): Promise<void> {
  if (!level.value) return
  submitting.value = true
  formError.value = ''
  Object.keys(formErrors).forEach((k) => delete formErrors[k])
  const payload = { level_id: level.value.id, section: form.section, capacity: form.capacity }
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
  if (!confirm(`Supprimer la classe "${cr.full_name}" ?`)) return
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
      <RouterLink :to="{ name: 'levels' }">← Retour aux niveaux</RouterLink>
    </p>

    <p v-if="error" class="alert alert-error">{{ error }}</p>

    <template v-if="level">
      <div class="card" style="margin-bottom: 1rem">
        <div class="card-header">
          <div>
            <h1 style="margin: 0">{{ level.name }}</h1>
            <p style="margin: 0.25rem 0 0; color: var(--text-soft); font-size: 0.9rem">
              Ordre d'affichage : {{ level.order }}
            </p>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-header">
          <h2 style="margin: 0">Classes</h2>
          <button type="button" class="btn-primary" @click="openCreate">+ Nouvelle classe</button>
        </div>

        <div v-if="loading" class="empty-state">Chargement…</div>
        <div v-else-if="!level.classrooms || level.classrooms.length === 0" class="empty-state">
          Aucune classe définie pour ce niveau.
        </div>

        <table v-else>
          <thead>
            <tr>
              <th>Classe</th>
              <th>Section</th>
              <th>Capacité</th>
              <th style="width: 1%; text-align: right; white-space: nowrap">Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="cr in level.classrooms" :key="cr.id">
              <td>{{ cr.full_name }}</td>
              <td>{{ cr.section }}</td>
              <td>{{ cr.capacity }} élèves</td>
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
          <label for="cr-section">Section (ex. A, B, Sci)</label>
          <input id="cr-section" v-model="form.section" type="text" required maxlength="16" />
          <small v-if="formErrors.section" class="err">{{ formErrors.section[0] }}</small>
        </div>
        <div class="field">
          <label for="cr-cap">Capacité</label>
          <input id="cr-cap" v-model.number="form.capacity" type="number" min="1" max="200" />
        </div>
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
</style>
