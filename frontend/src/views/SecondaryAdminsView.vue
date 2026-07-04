<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { api, ApiError } from '../api/client'
import { useToastStore } from '../stores/toast'
import type { AdminScope, AuthUser } from '../types'
import Modal from '../components/Modal.vue'
import { useConfirmStore } from '../stores/confirm'

interface SecondaryAdmin extends AuthUser {
  is_active?: boolean
}

interface UsersResponse {
  data: SecondaryAdmin[]
}

const SECONDARY_SCOPES: { value: AdminScope; label: string }[] = [
  { value: 'primary_maternal', label: 'Cycle Primaire & Maternel' },
  { value: 'secondary_technical', label: 'Cycle Secondaire & Technique' },
]

const confirmDialog = useConfirmStore()

const items = ref<SecondaryAdmin[]>([])
const loading = ref(false)
const error = ref('')
const filterScope = ref<'' | AdminScope>('')

// Création
const showCreate = ref(false)
const createErrors = reactive<Record<string, string[]>>({})
const createError = ref('')
const submitting = ref(false)
const createForm = reactive({
  name: '',
  email: '',
  password: '',
  admin_scope: 'primary_maternal' as AdminScope,
})

// Édition
const showEdit = ref(false)
const editing = ref<SecondaryAdmin | null>(null)
const editErrors = reactive<Record<string, string[]>>({})
const editError = ref('')
const editForm = reactive({
  name: '',
  email: '',
  admin_scope: 'primary_maternal' as AdminScope,
  is_active: true,
})

// Réinitialisation mot de passe
const showResetPassword = ref(false)
const resetTarget = ref<SecondaryAdmin | null>(null)
const resetPasswordValue = ref('')
const resetError = ref('')

const filtered = computed(() =>
  filterScope.value ? items.value.filter((u) => u.admin_scope === filterScope.value) : items.value,
)

function generatePassword(): string {
  const chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789'
  let pwd = ''
  for (let i = 0; i < 12; i++) pwd += chars.charAt(Math.floor(Math.random() * chars.length))
  return pwd
}

function scopeLabel(scope: AdminScope | null | undefined): string {
  return SECONDARY_SCOPES.find((s) => s.value === scope)?.label ?? scope ?? ''
}

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    const res = await api<UsersResponse>('/api/v1/admin/users?secondary_admins_only=1')
    items.value = res.data
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Erreur de chargement.'
  } finally {
    loading.value = false
  }
}

function clearErrors(map: Record<string, string[]>): void {
  Object.keys(map).forEach((k) => delete map[k])
}

function openCreate(): void {
  createForm.name = ''
  createForm.email = ''
  createForm.password = generatePassword()
  createForm.admin_scope = 'primary_maternal'
  createError.value = ''
  clearErrors(createErrors)
  showCreate.value = true
}

async function submitCreate(): Promise<void> {
  submitting.value = true
  createError.value = ''
  clearErrors(createErrors)
  try {
    await api('/api/v1/admin/users', {
      method: 'POST',
      body: {
        ...createForm,
        role: 'admin',
      },
    })
    showCreate.value = false
    await load()
  } catch (e) {
    if (e instanceof ApiError) {
      createError.value = e.message
      if (e.errors) Object.assign(createErrors, e.errors)
    } else {
      createError.value = 'Erreur réseau.'
    }
  } finally {
    submitting.value = false
  }
}

function openEdit(user: SecondaryAdmin): void {
  editing.value = user
  editForm.name = user.name
  editForm.email = user.email
  editForm.admin_scope = (user.admin_scope as AdminScope) ?? 'primary_maternal'
  editForm.is_active = user.is_active ?? true
  editError.value = ''
  clearErrors(editErrors)
  showEdit.value = true
}

async function submitEdit(): Promise<void> {
  if (!editing.value) return
  submitting.value = true
  editError.value = ''
  clearErrors(editErrors)
  try {
    await api(`/api/v1/admin/users/${editing.value.id}`, {
      method: 'PATCH',
      body: { ...editForm },
    })
    showEdit.value = false
    await load()
  } catch (e) {
    if (e instanceof ApiError) {
      editError.value = e.message
      if (e.errors) Object.assign(editErrors, e.errors)
    } else {
      editError.value = 'Erreur réseau.'
    }
  } finally {
    submitting.value = false
  }
}

async function toggleActive(user: SecondaryAdmin): Promise<void> {
  try {
    await api(`/api/v1/admin/users/${user.id}`, {
      method: 'PATCH',
      body: { is_active: !(user.is_active ?? true) },
    })
    await load()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Erreur réseau.'
  }
}

async function remove(user: SecondaryAdmin): Promise<void> {
  const ok = await confirmDialog.ask({
    title: "Supprimer l'administrateur",
    message: `Supprimer définitivement « ${user.name} » ?`,
    note: 'Cette action est irréversible.',
    confirmLabel: 'Supprimer',
    variant: 'danger',
  })
  if (!ok) return
  try {
    await api(`/api/v1/admin/users/${user.id}`, { method: 'DELETE' })
    toast.success('Administrateur secondaire supprimé.')
    await load()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Erreur réseau.'
  }
}

function openReset(user: SecondaryAdmin): void {
  resetTarget.value = user
  resetPasswordValue.value = generatePassword()
  resetError.value = ''
  showResetPassword.value = true
}

async function submitReset(): Promise<void> {
  if (!resetTarget.value) return
  submitting.value = true
  resetError.value = ''
  try {
    await api(`/api/v1/admin/users/${resetTarget.value.id}/reset-password`, {
      method: 'POST',
      body: { password: resetPasswordValue.value },
    })
    showResetPassword.value = false
  } catch (e) {
    resetError.value = e instanceof ApiError ? e.message : 'Erreur réseau.'
  } finally {
    submitting.value = false
  }
}

onMounted(load)
</script>

<template>
  <section>
    <div class="card">
      <div class="card-header">
        <div>
          <h1 style="margin: 0">Administrateurs secondaires</h1>
          <p style="margin: 0.25rem 0 0; color: var(--text-soft); font-size: 0.9rem">
            Gérez les administrateurs délégués à un cycle (Primaire & Maternel ou Secondaire & Technique).
          </p>
        </div>
        <div style="display: flex; gap: 0.5rem; align-items: center">
          <select v-model="filterScope" style="width: auto">
            <option value="">Tous les cycles</option>
            <option v-for="s in SECONDARY_SCOPES" :key="s.value" :value="s.value">{{ s.label }}</option>
          </select>
          <button type="button" class="btn-primary" @click="openCreate">+ Nouvel admin secondaire</button>
        </div>
      </div>

      <p v-if="error" class="alert alert-error" style="margin: 1rem">{{ error }}</p>
      <div v-if="loading" class="empty-state">Chargement…</div>
      <div v-else-if="filtered.length === 0" class="empty-state">Aucun administrateur secondaire.</div>

      <table v-else>
        <thead>
          <tr>
            <th>Nom</th>
            <th>Email</th>
            <th>Périmètre</th>
            <th>Statut</th>
            <th style="text-align: right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="u in filtered" :key="u.id">
            <td>{{ u.name }}</td>
            <td>{{ u.email }}</td>
            <td>
              <span class="badge badge-admin">{{ scopeLabel(u.admin_scope) }}</span>
            </td>
            <td>
              <span class="badge" :class="u.is_active === false ? 'badge-eleve' : 'badge-enseignant'">
                {{ u.is_active === false ? 'Désactivé' : 'Actif' }}
              </span>
            </td>
            <td style="text-align: right; white-space: nowrap">
              <button type="button" @click="openEdit(u)">Modifier</button>
              <button type="button" @click="toggleActive(u)" style="margin-left: 0.25rem">
                {{ u.is_active === false ? 'Activer' : 'Désactiver' }}
              </button>
              <button type="button" @click="openReset(u)" style="margin-left: 0.25rem">Mot de passe</button>
              <button type="button" class="btn-danger" @click="remove(u)" style="margin-left: 0.25rem">
                Supprimer
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Création -->
    <Modal :open="showCreate" title="Nouvel administrateur secondaire" @close="showCreate = false">
      <form id="sa-create-form" @submit.prevent="submitCreate">
        <div class="field">
          <label for="sa-name">Nom complet</label>
          <input id="sa-name" v-model="createForm.name" type="text" required maxlength="255" />
          <small v-if="createErrors.name" class="err">{{ createErrors.name[0] }}</small>
        </div>
        <div class="field">
          <label for="sa-email">Email</label>
          <input id="sa-email" v-model="createForm.email" type="email" required maxlength="255" />
          <small v-if="createErrors.email" class="err">{{ createErrors.email[0] }}</small>
        </div>
        <div class="field">
          <label for="sa-scope">Périmètre</label>
          <select id="sa-scope" v-model="createForm.admin_scope" required>
            <option v-for="s in SECONDARY_SCOPES" :key="s.value" :value="s.value">{{ s.label }}</option>
          </select>
          <small v-if="createErrors.admin_scope" class="err">{{ createErrors.admin_scope[0] }}</small>
        </div>
        <div class="field">
          <label for="sa-pwd">Mot de passe (min. 8 caractères)</label>
          <div style="display: flex; gap: 0.5rem">
            <input id="sa-pwd" v-model="createForm.password" type="text" required minlength="8" />
            <button type="button" @click="createForm.password = generatePassword()">Générer</button>
          </div>
          <small style="color: var(--text-soft)">
            Notez ce mot de passe et communiquez-le à l'utilisateur.
          </small>
          <small v-if="createErrors.password" class="err">{{ createErrors.password[0] }}</small>
        </div>
        <p v-if="createError" class="alert alert-error">{{ createError }}</p>
      </form>
      <template #footer>
        <button type="button" @click="showCreate = false">Annuler</button>
        <button type="submit" form="sa-create-form" class="btn-primary" :disabled="submitting">
          {{ submitting ? 'Création…' : 'Créer' }}
        </button>
      </template>
    </Modal>

    <!-- Édition -->
    <Modal :open="showEdit" :title="`Modifier ${editing?.name ?? ''}`" @close="showEdit = false">
      <form id="sa-edit-form" @submit.prevent="submitEdit">
        <div class="field">
          <label for="sa-edit-name">Nom complet</label>
          <input id="sa-edit-name" v-model="editForm.name" type="text" required maxlength="255" />
          <small v-if="editErrors.name" class="err">{{ editErrors.name[0] }}</small>
        </div>
        <div class="field">
          <label for="sa-edit-email">Email</label>
          <input id="sa-edit-email" v-model="editForm.email" type="email" required maxlength="255" />
          <small v-if="editErrors.email" class="err">{{ editErrors.email[0] }}</small>
        </div>
        <div class="field">
          <label for="sa-edit-scope">Périmètre</label>
          <select id="sa-edit-scope" v-model="editForm.admin_scope" required>
            <option v-for="s in SECONDARY_SCOPES" :key="s.value" :value="s.value">{{ s.label }}</option>
          </select>
          <small v-if="editErrors.admin_scope" class="err">{{ editErrors.admin_scope[0] }}</small>
        </div>
        <div class="field">
          <label style="display: flex; align-items: center; gap: 0.5rem">
            <input v-model="editForm.is_active" type="checkbox" />
            <span>Compte actif</span>
          </label>
        </div>
        <p v-if="editError" class="alert alert-error">{{ editError }}</p>
      </form>
      <template #footer>
        <button type="button" @click="showEdit = false">Annuler</button>
        <button type="submit" form="sa-edit-form" class="btn-primary" :disabled="submitting">
          {{ submitting ? 'Enregistrement…' : 'Enregistrer' }}
        </button>
      </template>
    </Modal>

    <!-- Mot de passe -->
    <Modal
      :open="showResetPassword"
      :title="`Réinitialiser le mot de passe – ${resetTarget?.name ?? ''}`"
      @close="showResetPassword = false"
    >
      <form id="sa-reset-form" @submit.prevent="submitReset">
        <div class="field">
          <label for="sa-reset-pwd">Nouveau mot de passe (min. 8 caractères)</label>
          <div style="display: flex; gap: 0.5rem">
            <input id="sa-reset-pwd" v-model="resetPasswordValue" type="text" required minlength="8" />
            <button type="button" @click="resetPasswordValue = generatePassword()">Générer</button>
          </div>
          <small style="color: var(--text-soft)">
            Notez ce mot de passe et communiquez-le à l'utilisateur.
          </small>
        </div>
        <p v-if="resetError" class="alert alert-error">{{ resetError }}</p>
      </form>
      <template #footer>
        <button type="button" @click="showResetPassword = false">Annuler</button>
        <button type="submit" form="sa-reset-form" class="btn-primary" :disabled="submitting">
          {{ submitting ? 'Mise à jour…' : 'Réinitialiser' }}
        </button>
      </template>
    </Modal>
  </section>
</template>
