<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { api, ApiError } from '../api/client'
import type { AdminScope, AuthUser, UserRole } from '../types'
import Modal from '../components/Modal.vue'

interface UsersResponse {
  data: AuthUser[]
}

const ROLES: { value: UserRole; label: string }[] = [
  { value: 'admin', label: 'Administrateur' },
  { value: 'secretariat', label: 'Secrétariat' },
  { value: 'enseignant', label: 'Enseignant' },
  { value: 'parent', label: 'Parent' },
  { value: 'eleve', label: 'Élève' },
]

const ADMIN_SCOPES: { value: AdminScope; label: string }[] = [
  { value: 'global', label: 'Administrateur général' },
  { value: 'primary_maternal', label: 'Admin cycle Primaire & Maternel' },
  { value: 'secondary_technical', label: 'Admin cycle Secondaire & Technique' },
]

const items = ref<AuthUser[]>([])
const loading = ref(false)
const error = ref('')
const filterRole = ref<'' | UserRole>('')

const showForm = ref(false)
const formError = ref('')
const formErrors = reactive<Record<string, string[]>>({})
const submitting = ref(false)

const form = reactive({
  name: '',
  email: '',
  password: '',
  role: 'enseignant' as UserRole,
  admin_scope: '' as AdminScope | '',
})

const filtered = computed(() =>
  filterRole.value ? items.value.filter((u) => u.role === filterRole.value) : items.value,
)

function resetForm(): void {
  form.name = ''
  form.email = ''
  form.password = ''
  form.role = 'enseignant'
  form.admin_scope = ''
  formError.value = ''
  Object.keys(formErrors).forEach((k) => delete formErrors[k])
}

function generatePassword(): void {
  const chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789'
  let pwd = ''
  for (let i = 0; i < 12; i++) pwd += chars.charAt(Math.floor(Math.random() * chars.length))
  form.password = pwd
}

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    const res = await api<UsersResponse>('/api/v1/admin/users')
    items.value = res.data
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Erreur de chargement.'
  } finally {
    loading.value = false
  }
}

function openCreate(): void {
  resetForm()
  generatePassword()
  showForm.value = true
}

async function submit(): Promise<void> {
  submitting.value = true
  formError.value = ''
  Object.keys(formErrors).forEach((k) => delete formErrors[k])
  try {
    await api<AuthUser>('/api/v1/admin/users', {
      method: 'POST',
      body: {
        ...form,
        admin_scope: form.role === 'admin' ? form.admin_scope : null,
      },
    })
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

function roleLabel(role: UserRole): string {
  return ROLES.find((r) => r.value === role)?.label ?? role
}

function displayRole(user: AuthUser): string {
  return user.role === 'admin' && user.admin_scope_label ? user.admin_scope_label : roleLabel(user.role)
}

function badgeClass(role: UserRole): string {
  const map: Record<UserRole, string> = {
    admin: 'badge-admin',
    secretariat: 'badge-secretariat',
    enseignant: 'badge-enseignant',
    parent: 'badge-parent',
    eleve: 'badge-eleve',
  }
  return map[role]
}

onMounted(load)
</script>

<template>
  <section>
    <div class="card">
      <div class="card-header">
        <h1 style="margin: 0">Utilisateurs</h1>
        <div style="display: flex; gap: 0.5rem; align-items: center">
          <select v-model="filterRole" style="width: auto">
            <option value="">Tous les rôles</option>
            <option v-for="r in ROLES" :key="r.value" :value="r.value">{{ r.label }}</option>
          </select>
          <button type="button" class="btn-primary" @click="openCreate">+ Nouvel utilisateur</button>
        </div>
      </div>

      <p v-if="error" class="alert alert-error" style="margin: 1rem">{{ error }}</p>
      <div v-if="loading" class="empty-state">Chargement…</div>
      <div v-else-if="filtered.length === 0" class="empty-state">Aucun utilisateur.</div>

      <table v-else>
        <thead>
          <tr>
            <th>Nom</th>
            <th>Email</th>
            <th>Rôle</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="u in filtered" :key="u.id">
            <td>{{ u.name }}</td>
            <td>{{ u.email }}</td>
            <td>
              <span class="badge" :class="badgeClass(u.role)">{{ displayRole(u) }}</span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <Modal :open="showForm" title="Nouvel utilisateur" @close="showForm = false">
      <form id="user-form" @submit.prevent="submit">
        <div class="field">
          <label for="u-name">Nom complet</label>
          <input id="u-name" v-model="form.name" type="text" required maxlength="255" />
          <small v-if="formErrors.name" class="err">{{ formErrors.name[0] }}</small>
        </div>
        <div v-if="form.role === 'admin'" class="field">
          <label for="u-admin-scope">Type d'administrateur</label>
          <select id="u-admin-scope" v-model="form.admin_scope" required>
            <option value="" disabled>Choisir un périmètre</option>
            <option v-for="scope in ADMIN_SCOPES" :key="scope.value" :value="scope.value">
              {{ scope.label }}
            </option>
          </select>
          <small v-if="formErrors.admin_scope" class="err">{{ formErrors.admin_scope[0] }}</small>
        </div>
        <div class="field">
          <label for="u-email">Email</label>
          <input id="u-email" v-model="form.email" type="email" required maxlength="255" />
          <small v-if="formErrors.email" class="err">{{ formErrors.email[0] }}</small>
        </div>
        <div class="field">
          <label for="u-role">Rôle</label>
          <select id="u-role" v-model="form.role" required>
            <option v-for="r in ROLES" :key="r.value" :value="r.value">{{ r.label }}</option>
          </select>
          <small v-if="formErrors.role" class="err">{{ formErrors.role[0] }}</small>
        </div>
        <div class="field">
          <label for="u-pwd">Mot de passe (min. 8 caractères)</label>
          <div style="display: flex; gap: 0.5rem">
            <input id="u-pwd" v-model="form.password" type="text" required minlength="8" />
            <button type="button" @click="generatePassword">Générer</button>
          </div>
          <small style="color: var(--text-soft)">
            Note ce mot de passe et communique-le à l'utilisateur (envoi email à venir).
          </small>
          <small v-if="formErrors.password" class="err">{{ formErrors.password[0] }}</small>
        </div>
        <p v-if="formError" class="alert alert-error">{{ formError }}</p>
      </form>
      <template #footer>
        <button type="button" @click="showForm = false">Annuler</button>
        <button type="submit" form="user-form" class="btn-primary" :disabled="submitting">
          {{ submitting ? 'Création…' : 'Créer' }}
        </button>
      </template>
    </Modal>
  </section>
</template>

<style scoped>
.err { display: block; color: var(--danger); font-size: 0.78rem; margin-top: 0.25rem; }

.badge-admin { background: #fee2e2; color: #b91c1c; }
.badge-secretariat { background: #fef3c7; color: #92400e; }
.badge-enseignant { background: #dbeafe; color: #1d4ed8; }
.badge-parent { background: #ede9fe; color: #5b21b6; }
.badge-eleve { background: #d1fae5; color: #047857; }
</style>
