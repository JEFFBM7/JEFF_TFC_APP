<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { api, ApiError } from '../api/client'
import type { ApiResource, AuthUser, Paginated, ParentProfile } from '../types'
import Modal from '../components/Modal.vue'
import RowActionMenu from '../components/RowActionMenu.vue'
import { useConfirmStore } from '../stores/confirm'
import { useAuthStore } from '../stores/auth'
import { useCycleTabs, type CycleFilter } from '../composables/useCycleTabs'

const auth = useAuthStore()
const { cycleTabs } = useCycleTabs()
const items = ref<ParentProfile[]>([])
const confirmDialog = useConfirmStore()
const router = useRouter()
const availableUsers = ref<AuthUser[]>([])
const loading = ref(false)
const error = ref('')
const activeCycle = ref<CycleFilter>('all')
const selectedParentIds = ref<number[]>([])
const bulkDeleting = ref(false)

const showForm = ref(false)
const editing = ref<ParentProfile | null>(null)
const formError = ref('')
const formErrors = reactive<Record<string, string[]>>({})
const submitting = ref(false)

const form = reactive({ user_id: 0, phone: '', address: '' })

const isGlobalAdmin = computed(() =>
  auth.user?.role === 'admin' && (auth.user.admin_scope ?? 'global') === 'global',
)

const visibleParentIds = computed(() => items.value.map((parent) => parent.id))

watch(cycleTabs, (tabs) => {
  if (!tabs.some((tab) => tab.value === activeCycle.value)) {
    activeCycle.value = 'all'
  }
})

const selectedCount = computed(() => selectedParentIds.value.length)

const allVisibleSelected = computed(() =>
  visibleParentIds.value.length > 0
  && visibleParentIds.value.every((id) => selectedParentIds.value.includes(id)),
)

const someVisibleSelected = computed(() =>
  selectedParentIds.value.length > 0 && !allVisibleSelected.value,
)

function parentDisplayName(item: ParentProfile): string {
  return item.user?.name ?? `Parent #${item.id}`
}

function resetForm(): void {
  form.user_id = 0
  form.phone = ''
  form.address = ''
  formError.value = ''
  Object.keys(formErrors).forEach((k) => delete formErrors[k])
}

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    const query: Record<string, string> = {}
    if (activeCycle.value !== 'all') query.cycle = activeCycle.value

    const parentsRes = await api<Paginated<ParentProfile>>('/api/v1/parents', { query })
    items.value = parentsRes.data
    if (isGlobalAdmin.value) {
      const usersRes = await api<{ data: AuthUser[] }>('/api/v1/admin/users', { query: { role: 'parent' } })
      availableUsers.value = usersRes.data
    } else {
      availableUsers.value = []
    }
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Erreur de chargement.'
  } finally {
    loading.value = false
  }
}

function setActiveCycle(cycle: CycleFilter): void {
  activeCycle.value = cycle
}

function toggleSelectAllVisible(event: Event): void {
  const checked = (event.target as HTMLInputElement).checked
  selectedParentIds.value = checked ? [...visibleParentIds.value] : []
}

function toggleParentSelection(parentId: number, event: Event): void {
  const checked = (event.target as HTMLInputElement).checked
  selectedParentIds.value = checked
    ? Array.from(new Set([...selectedParentIds.value, parentId]))
    : selectedParentIds.value.filter((id) => id !== parentId)
}

function clearSelection(): void {
  if (bulkDeleting.value) return
  selectedParentIds.value = []
}

function openCreate(): void {
  if (!isGlobalAdmin.value) return
  editing.value = null
  resetForm()
  showForm.value = true
}

function openParentDetail(item: ParentProfile): void {
  void router.push({ name: 'parent-detail', params: { id: item.id } })
}

function openEdit(item: ParentProfile): void {
  editing.value = item
  form.user_id = item.user_id
  form.phone = item.phone ?? ''
  form.address = item.address ?? ''
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
      await api<ApiResource<ParentProfile>>(`/api/v1/parents/${editing.value.id}`, {
        method: 'PUT',
        body: { ...form },
      })
    } else {
      await api<ApiResource<ParentProfile>>('/api/v1/parents', {
        method: 'POST',
        body: { ...form },
      })
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

async function remove(item: ParentProfile): Promise<void> {
  const ok = await confirmDialog.ask({
    title: 'Supprimer un parent',
    message: 'Ce profil parent sera supprimé.',
    details: [parentDisplayName(item)],
    note: 'Les liens avec les élèves associés seront retirés.',
    confirmLabel: 'Supprimer',
    variant: 'danger',
  })
  if (!ok) return
  try {
    await api(`/api/v1/parents/${item.id}`, { method: 'DELETE' })
    await load()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Suppression impossible.'
  }
}

async function removeSelected(): Promise<void> {
  const ids = [...selectedParentIds.value]
  if (ids.length === 0 || bulkDeleting.value) return

  const names = ids.map((id) => parentDisplayName(items.value.find((item) => item.id === id) ?? { id } as ParentProfile))
  const details = names.length > 5
    ? [...names.slice(0, 5), `${names.length - 5} autre(s)`]
    : names

  const ok = await confirmDialog.ask({
    title: 'Supprimer les parents sélectionnés',
    message: `${ids.length} profil(s) parent seront supprimés.`,
    details,
    note: 'Les liens avec les élèves associés seront retirés.',
    confirmLabel: 'Supprimer',
    variant: 'danger',
  })
  if (!ok) return

  bulkDeleting.value = true
  error.value = ''
  const failedIds: number[] = []

  try {
    for (const id of ids) {
      try {
        await api(`/api/v1/parents/${id}`, { method: 'DELETE' })
      } catch {
        failedIds.push(id)
      }
    }

    selectedParentIds.value = failedIds
    await load()

    if (failedIds.length > 0) {
      error.value = `${failedIds.length} suppression(s) impossible(s) sur ${ids.length}.`
    }
  } finally {
    bulkDeleting.value = false
  }
}

watch(activeCycle, load)
watch(items, () => {
  const visibleIds = new Set(visibleParentIds.value)
  selectedParentIds.value = selectedParentIds.value.filter((id) => visibleIds.has(id))
})

onMounted(load)
</script>

<template>
  <section>
    <div class="card">
      <div class="card-header">
        <h1 style="margin: 0">Parents</h1>
        <button v-if="isGlobalAdmin" type="button" class="btn-primary" @click="openCreate">
          + Ajouter un parent
        </button>
      </div>

      <div class="parent-toolbar">
        <div class="cycle-tabs" role="tablist" aria-label="Filtrer les parents par cycle">
          <button
            v-for="tab in cycleTabs"
            :key="tab.value"
            type="button"
            class="cycle-tab"
            :class="{ active: activeCycle === tab.value }"
            role="tab"
            :aria-selected="activeCycle === tab.value"
            @click="setActiveCycle(tab.value)"
          >
            {{ tab.label }}
          </button>
        </div>
        <div v-if="selectedCount > 0" class="selection-strip" role="status">
          <div class="selection-summary">
            <strong>{{ selectedCount }}</strong>
            <span>parent(s) sélectionné(s)</span>
          </div>
          <div class="bulk-actions" aria-label="Actions groupées">
            <button type="button" :disabled="bulkDeleting" @click="clearSelection">
              Désélectionner
            </button>
            <button
              type="button"
              class="bulk-danger"
              :disabled="bulkDeleting"
              @click="removeSelected"
            >
              {{ bulkDeleting ? 'Suppression…' : 'Supprimer' }}
            </button>
          </div>
        </div>
      </div>

      <p v-if="error" class="alert alert-error" style="margin: 1rem">{{ error }}</p>
      <div v-if="loading" class="empty-state">Chargement…</div>
      <div v-else-if="items.length === 0" class="empty-state">
        Aucun parent dans votre périmètre.
      </div>

      <table v-else>
        <thead>
          <tr>
            <th class="select-col">
              <input
                type="checkbox"
                aria-label="Sélectionner tous les parents affichés"
                :checked="allVisibleSelected"
                :indeterminate="someVisibleSelected"
                :disabled="bulkDeleting"
                @change="toggleSelectAllVisible"
              />
            </th>
            <th>Nom</th>
            <th>Email</th>
            <th>Téléphone</th>
            <th>Enfants</th>
            <th style="width: 1%; text-align: right; white-space: nowrap">Actions</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="(p, index) in items"
            :key="p.id"
            class="clickable-row"
            :class="{ 'is-selected': selectedParentIds.includes(p.id) }"
            tabindex="0"
            role="link"
            @click="openParentDetail(p)"
            @keydown.enter.prevent="openParentDetail(p)"
            @keydown.space.prevent="openParentDetail(p)"
          >
            <td class="select-col" @click.stop @keydown.stop>
              <input
                type="checkbox"
                :aria-label="`Sélectionner ${parentDisplayName(p)}`"
                :checked="selectedParentIds.includes(p.id)"
                :disabled="bulkDeleting"
                @change="toggleParentSelection(p.id, $event)"
              />
            </td>
            <td class="name-cell">
              <RouterLink
                class="entity-name-link"
                :to="{ name: 'parent-detail', params: { id: p.id } }"
                @click.stop
                @keydown.stop
              >
                {{ parentDisplayName(p) }}
              </RouterLink>
            </td>
            <td>{{ p.user?.email ?? '—' }}</td>
            <td>{{ p.phone ?? '—' }}</td>
            <td>
              <span class="badge badge-muted">{{ p.students_count ?? 0 }} enfant(s)</span>
            </td>
            <td style="text-align: right; white-space: nowrap" @click.stop @keydown.stop>
              <RowActionMenu
                :open-up="index >= items.length - 2"
                :aria-label="`Actions pour ${p.user?.name ?? 'parent'}`"
              >
                <RouterLink :to="{ name: 'parent-detail', params: { id: p.id } }">
                  Voir la fiche
                </RouterLink>
                <button type="button" @click="openEdit(p)">Modifier</button>
                <button type="button" class="danger-action" @click="remove(p)">Supprimer</button>
              </RowActionMenu>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <Modal
      :open="showForm"
      :title="editing ? 'Modifier un parent' : 'Nouveau profil parent'"
      @close="showForm = false"
    >
      <form id="parent-form" @submit.prevent="submit">
        <div class="field">
          <label for="p-uid">Utilisateur (rôle parent)</label>
          <select id="p-uid" v-model.number="form.user_id" required :disabled="!!editing">
            <option :value="0" disabled>-- Sélectionner --</option>
            <option v-if="editing?.user" :value="editing.user.id">
              {{ editing.user.name }} ({{ editing.user.email }})
            </option>
            <option v-for="u in availableUsers" :key="u.id" :value="u.id">
              {{ u.name }} ({{ u.email }})
            </option>
          </select>
          <small v-if="availableUsers.length === 0" style="color: var(--text-soft)">
            Aucun compte parent disponible. Crée-le dans <strong>Utilisateurs</strong>.
          </small>
          <small v-if="formErrors.user_id" class="err">{{ formErrors.user_id[0] }}</small>
        </div>
        <div class="field">
          <label for="p-phone">Téléphone</label>
          <input id="p-phone" v-model="form.phone" type="tel" maxlength="32" />
        </div>
        <div class="field">
          <label for="p-addr">Adresse</label>
          <input id="p-addr" v-model="form.address" type="text" maxlength="255" />
        </div>
        <p v-if="formError" class="alert alert-error">{{ formError }}</p>
      </form>
      <template #footer>
        <button type="button" @click="showForm = false">Annuler</button>
        <button type="submit" form="parent-form" class="btn-primary" :disabled="submitting">
          {{ submitting ? 'Enregistrement…' : editing ? 'Enregistrer' : 'Créer' }}
        </button>
      </template>
    </Modal>
  </section>
</template>

<style scoped>
button + button { margin-left: 0.4rem; }
.err { display: block; color: var(--danger); font-size: 0.78rem; margin-top: 0.25rem; }
code { font-family: ui-monospace,Consolas,monospace; background:#f1f5f9; padding:.1rem .35rem; border-radius:4px; font-size:.78rem; }
.entity-name-link {
  display: inline-flex;
  max-width: 100%;
  color: var(--text);
  font-weight: 900;
  line-height: 1.25;
  text-decoration: none;
}
.entity-name-link:hover,
.entity-name-link:focus-visible {
  color: var(--primary);
  text-decoration: none;
}
.name-cell {
  min-width: 11rem;
}
.clickable-row {
  cursor: pointer;
}
.clickable-row:hover {
  background: #f8fbff;
}
.clickable-row:focus-visible {
  outline: 2px solid var(--primary);
  outline-offset: -2px;
}
.is-selected {
  background: #f8fbff;
}
.select-col {
  width: 2.6rem;
  text-align: center;
}
.select-col input {
  width: 1rem;
  height: 1rem;
  margin: 0;
}
.parent-toolbar {
  display: grid;
  gap: 0.75rem;
  padding: 1rem;
  border-bottom: 1px solid var(--border);
}
.cycle-tabs {
  display: flex;
  align-items: center;
  gap: 0.45rem;
  flex-wrap: wrap;
}
.cycle-tab {
  min-height: 2.35rem;
  padding: 0.45rem 0.8rem;
  border: 1px solid var(--border);
  border-radius: 8px;
  background: #fff;
  color: var(--text-soft);
  font-size: 0.86rem;
  font-weight: 800;
}
.cycle-tab:hover {
  border-color: var(--primary);
  color: var(--primary);
}
.cycle-tab.active {
  border-color: var(--primary);
  background: var(--primary);
  color: #fff;
  box-shadow: 0 8px 18px rgb(37 99 235 / 16%);
}
.selection-strip {
  display: inline-flex;
  align-items: center;
  gap: 0.75rem;
  width: fit-content;
  padding: 0.5rem 0.7rem;
  border: 1px solid #bfdbfe;
  border-radius: 8px;
  background: #eff6ff;
  color: var(--text);
  font-size: 0.86rem;
}
.selection-summary,
.bulk-actions {
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
}
.selection-strip strong {
  color: var(--primary);
}
.selection-strip button {
  min-height: 1.8rem;
  padding: 0.2rem 0.55rem;
  border: 1px solid #bfdbfe;
  border-radius: 6px;
  background: #fff;
  color: var(--primary);
  font-size: 0.8rem;
  font-weight: 800;
}
.selection-strip button:disabled {
  cursor: not-allowed;
  opacity: 0.65;
}
.selection-strip .bulk-danger {
  border-color: #fecdd3;
  color: var(--danger);
}
.selection-strip .bulk-danger:hover:not(:disabled) {
  background: #fff1f2;
}
@media (max-width: 720px) {
  .cycle-tabs {
    align-items: stretch;
  }
  .cycle-tab {
    flex: 1 1 8rem;
  }
  .selection-strip {
    width: 100%;
    justify-content: space-between;
  }
  .selection-summary,
  .bulk-actions {
    flex-wrap: wrap;
  }
}
</style>
