<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { api, ApiError } from '../api/client'
import type { ApiResource, AuthUser, Paginated, Teacher } from '../types'
import Modal from '../components/Modal.vue'

const items = ref<Teacher[]>([])
const availableUsers = ref<AuthUser[]>([])
const loading = ref(false)
const error = ref('')

const showForm = ref(false)
const editing = ref<Teacher | null>(null)
const formError = ref('')
const formErrors = reactive<Record<string, string[]>>({})
const submitting = ref(false)

const form = reactive({ user_id: 0, speciality: '', phone: '' })

function resetForm(): void {
  form.user_id = 0
  form.speciality = ''
  form.phone = ''
  formError.value = ''
  Object.keys(formErrors).forEach((k) => delete formErrors[k])
}

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    const [teachersRes, usersRes] = await Promise.all([
      api<Paginated<Teacher>>('/api/v1/teachers'),
      api<Paginated<AuthUser>>('/api/v1/admin/users', { query: { role: 'enseignant' } }),
    ])
    items.value = teachersRes.data
    availableUsers.value = usersRes.data
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

function openEdit(item: Teacher): void {
  editing.value = item
  form.user_id = item.user_id
  form.speciality = item.speciality ?? ''
  form.phone = item.phone ?? ''
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
      await api<ApiResource<Teacher>>(`/api/v1/teachers/${editing.value.id}`, {
        method: 'PUT',
        body: { ...form },
      })
    } else {
      await api<ApiResource<Teacher>>('/api/v1/teachers', { method: 'POST', body: { ...form } })
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

async function remove(item: Teacher): Promise<void> {
  if (!confirm(`Supprimer le profil enseignant de "${item.user?.name}" ?`)) return
  try {
    await api(`/api/v1/teachers/${item.id}`, { method: 'DELETE' })
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
        <h1 style="margin: 0">Enseignants</h1>
        <button type="button" class="btn-primary" @click="openCreate">+ Ajouter un enseignant</button>
      </div>

      <p v-if="error" class="alert alert-error" style="margin: 1rem">{{ error }}</p>
      <div v-if="loading" class="empty-state">Chargement…</div>
      <div v-else-if="items.length === 0" class="empty-state">
        Aucun profil enseignant. Crée d'abord un compte utilisateur avec le rôle
        <code>enseignant</code>, puis ajoute le profil ici.
      </div>

      <table v-else>
        <thead>
          <tr>
            <th>Nom</th>
            <th>Email</th>
            <th>Spécialité</th>
            <th>Téléphone</th>
            <th style="width: 1%; text-align: right; white-space: nowrap">Actions</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="item in items" :key="item.id">
            <td>{{ item.user?.name ?? '—' }}</td>
            <td>{{ item.user?.email ?? '—' }}</td>
            <td>{{ item.speciality ?? '—' }}</td>
            <td>{{ item.phone ?? '—' }}</td>
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
      :title="editing ? 'Modifier un enseignant' : 'Nouveau profil enseignant'"
      @close="showForm = false"
    >
      <form id="teacher-form" @submit.prevent="submit">
        <div class="field">
          <label for="t-uid">Utilisateur (rôle enseignant)</label>
          <select id="t-uid" v-model.number="form.user_id" required :disabled="!!editing">
            <option :value="0" disabled>-- Sélectionner un utilisateur --</option>
            <option v-for="u in availableUsers" :key="u.id" :value="u.id">
              {{ u.name }} ({{ u.email }})
            </option>
          </select>
          <small v-if="availableUsers.length === 0" style="color: var(--text-soft)">
            Aucun compte enseignant disponible. Crée d'abord un utilisateur avec le rôle
            <code>enseignant</code> depuis la gestion des utilisateurs.
          </small>
          <small v-if="formErrors.user_id" class="err">{{ formErrors.user_id[0] }}</small>
        </div>
        <div class="field">
          <label for="t-spec">Spécialité</label>
          <input id="t-spec" v-model="form.speciality" type="text" maxlength="128" />
        </div>
        <div class="field">
          <label for="t-phone">Téléphone</label>
          <input id="t-phone" v-model="form.phone" type="tel" maxlength="32" />
        </div>
        <p v-if="formError" class="alert alert-error">{{ formError }}</p>
      </form>
      <template #footer>
        <button type="button" @click="showForm = false">Annuler</button>
        <button type="submit" form="teacher-form" class="btn-primary" :disabled="submitting">
          {{ submitting ? 'Enregistrement…' : editing ? 'Enregistrer' : 'Créer' }}
        </button>
      </template>
    </Modal>
  </section>
</template>

<style scoped>
button + button { margin-left: 0.4rem; }
code { font-family: ui-monospace,Consolas,monospace; background:#f1f5f9; padding:.1rem .35rem; border-radius:4px; font-size:.78rem; }
.err { display: block; color: var(--danger); font-size: 0.78rem; margin-top: 0.25rem; }
</style>
