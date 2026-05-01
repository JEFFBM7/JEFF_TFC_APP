<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { api, ApiError } from '../api/client'
import type { ApiResource, Level, Paginated } from '../types'
import Modal from '../components/Modal.vue'

const items = ref<Level[]>([])
const loading = ref(false)
const error = ref('')

const showForm = ref(false)
const editing = ref<Level | null>(null)
const formError = ref('')
const formErrors = reactive<Record<string, string[]>>({})
const submitting = ref(false)

const form = reactive({ name: '', order: 0 })

function resetForm(): void {
  form.name = ''
  form.order = items.value.length
  formError.value = ''
  Object.keys(formErrors).forEach((k) => delete formErrors[k])
}

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    const res = await api<Paginated<Level>>('/api/v1/levels')
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

function openEdit(item: Level): void {
  editing.value = item
  form.name = item.name
  form.order = item.order
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
      await api<ApiResource<Level>>(`/api/v1/levels/${editing.value.id}`, {
        method: 'PUT',
        body: { ...form },
      })
    } else {
      await api<ApiResource<Level>>('/api/v1/levels', { method: 'POST', body: { ...form } })
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

async function remove(item: Level): Promise<void> {
  if (!confirm(`Supprimer le niveau "${item.name}" ?`)) return
  try {
    await api(`/api/v1/levels/${item.id}`, { method: 'DELETE' })
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
        <h1 style="margin: 0">Niveaux scolaires</h1>
        <button type="button" class="btn-primary" @click="openCreate">+ Nouveau niveau</button>
      </div>

      <p v-if="error" class="alert alert-error" style="margin: 1rem">{{ error }}</p>
      <div v-if="loading" class="empty-state">Chargement…</div>
      <div v-else-if="items.length === 0" class="empty-state">Aucun niveau enregistré.</div>

      <table v-else>
        <thead>
          <tr>
            <th style="width: 4rem">Ordre</th>
            <th>Nom</th>
            <th>Classes</th>
            <th style="width: 1%; text-align: right; white-space: nowrap">Actions</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="item in items" :key="item.id">
            <td>{{ item.order }}</td>
            <td>{{ item.name }}</td>
            <td>
              <RouterLink :to="{ name: 'level-detail', params: { id: item.id } }">
                {{ item.classrooms?.length ?? 0 }} classe(s)
              </RouterLink>
            </td>
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
      :title="editing ? 'Modifier un niveau' : 'Nouveau niveau'"
      @close="showForm = false"
    >
      <form id="level-form" @submit.prevent="submit">
        <div class="field">
          <label for="lv-name">Nom (ex. 6ème, Terminale S)</label>
          <input id="lv-name" v-model="form.name" type="text" required maxlength="64" />
          <small v-if="formErrors.name" class="err">{{ formErrors.name[0] }}</small>
        </div>
        <div class="field">
          <label for="lv-order">Ordre d'affichage</label>
          <input id="lv-order" v-model.number="form.order" type="number" min="0" max="255" />
          <small v-if="formErrors.order" class="err">{{ formErrors.order[0] }}</small>
        </div>
        <p v-if="formError" class="alert alert-error">{{ formError }}</p>
      </form>
      <template #footer>
        <button type="button" @click="showForm = false">Annuler</button>
        <button type="submit" form="level-form" class="btn-primary" :disabled="submitting">
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
