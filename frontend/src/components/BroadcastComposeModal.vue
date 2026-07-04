<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { api, ApiError } from '../api/client'
import { useToastStore } from '../stores/toast'
import type { BroadcastAudienceType, ClassRoom, MessageContact, Paginated } from '../types'
import Modal from './Modal.vue'

const props = defineProps<{
  open: boolean
  contacts: MessageContact[]
}>()

const emit = defineEmits<{
  (e: 'close'): void
  (e: 'sent'): void
}>()

const classrooms = ref<ClassRoom[]>([])
const loadingClassrooms = ref(false)
const previewCount = ref<number | null>(null)
const previewError = ref('')
const sending = ref(false)
const error = ref('')
const fieldErrors = ref<Record<string, string[]>>({})

const form = reactive({
  audience_type: 'all_parents' as BroadcastAudienceType,
  classroom_id: '' as number | '',
  cycle: '' as string,
  user_ids: [] as number[],
  subject: '',
  body: '',
})

const audienceOptions: { value: BroadcastAudienceType; label: string; description: string }[] = [
  { value: 'all_parents', label: 'Parents', description: 'Tous les responsables des élèves' },
  { value: 'all_teachers', label: 'Enseignants', description: 'Toute l’équipe pédagogique' },
  { value: 'all_students', label: 'Élèves', description: 'Tous les comptes élèves actifs' },
  { value: 'all_users', label: 'Tous', description: 'Toute la communauté scolaire' },
  { value: 'classroom', label: 'Classe', description: 'Une classe précise' },
  { value: 'cycle', label: 'Cycle', description: 'Un niveau ou cycle complet' },
  { value: 'custom', label: 'Sélection', description: 'Des destinataires choisis' },
]

const cycles = [
  { value: 'maternel', label: 'Maternel' },
  { value: 'primaire', label: 'Primaire' },
  { value: 'cteb', label: 'CTEB' },
  { value: 'secondaire', label: 'Secondaire' },
]

const selectedAudience = computed(() =>
  audienceOptions.find((option) => option.value === form.audience_type) ?? audienceOptions[0],
)

const audiencePayload = computed(() => {
  const payload: Record<string, unknown> = { audience_type: form.audience_type }
  if (form.audience_type === 'classroom') payload.classroom_id = form.classroom_id || null
  if (form.audience_type === 'cycle') payload.cycle = form.cycle || null
  if (form.audience_type === 'custom') payload.user_ids = form.user_ids
  return payload
})

function roleLabel(role: string): string {
  const map: Record<string, string> = {
    admin: 'Admin',
    enseignant: 'Enseignant',
    parent: 'Parent',
    eleve: 'Élève',
    secretariat: 'Secrétariat',
  }
  return map[role] ?? role
}

async function loadClassrooms(): Promise<void> {
  if (classrooms.value.length > 0 || loadingClassrooms.value) return
  loadingClassrooms.value = true
  try {
    const res = await api<Paginated<ClassRoom>>('/api/v1/classrooms')
    classrooms.value = res.data
  } catch {
    classrooms.value = []
  } finally {
    loadingClassrooms.value = false
  }
}

async function refreshPreview(): Promise<void> {
  previewError.value = ''
  previewCount.value = null

  if (form.audience_type === 'classroom' && !form.classroom_id) return
  if (form.audience_type === 'cycle' && !form.cycle) return
  if (form.audience_type === 'custom' && form.user_ids.length === 0) return

  try {
    const res = await api<{ count: number }>('/api/v1/messages/broadcast/audience-count', {
      query: audiencePayload.value as Record<string, string | number | boolean | undefined | null>,
    })
    previewCount.value = res.count
  } catch (e) {
    previewError.value = e instanceof ApiError ? e.message : 'Aperçu indisponible.'
  }
}

function reset(): void {
  form.audience_type = 'all_parents'
  form.classroom_id = ''
  form.cycle = ''
  form.user_ids = []
  form.subject = ''
  form.body = ''
  previewCount.value = null
  previewError.value = ''
  error.value = ''
  fieldErrors.value = {}
}

async function submit(): Promise<void> {
  if (!form.subject.trim() || !form.body.trim()) {
    error.value = 'Objet et message sont obligatoires.'
    return
  }

  sending.value = true
  error.value = ''
  fieldErrors.value = {}
  try {
    await api('/api/v1/messages/broadcast', {
      method: 'POST',
      body: {
        ...audiencePayload.value,
        subject: form.subject,
        body: form.body,
      },
    })
    useToastStore().success('Annonce envoyée aux destinataires ciblés.')
    emit('sent')
    reset()
  } catch (e) {
    if (e instanceof ApiError) {
      error.value = e.message
      fieldErrors.value = e.errors ?? {}
    } else {
      error.value = 'Envoi impossible.'
    }
  } finally {
    sending.value = false
  }
}

watch(
  () => props.open,
  (open) => {
    if (open) {
      void loadClassrooms()
      void refreshPreview()
    } else {
      reset()
    }
  },
)

watch(
  () => [form.audience_type, form.classroom_id, form.cycle, form.user_ids.join(',')],
  () => void refreshPreview(),
)
</script>

<template>
  <Modal title="Nouvelle annonce" :open="open" max-width="42rem" @close="emit('close')">
    <p v-if="error" class="alert alert-error">{{ error }}</p>

    <div class="broadcast-intro">
      <p class="eyebrow">Diffusion groupée</p>
      <p>
        Préparez une annonce claire et vérifiez le nombre de destinataires avant l’envoi.
      </p>
    </div>

    <div class="field">
      <label>Audience</label>
      <div class="audience-grid" role="radiogroup" aria-label="Audience de l'annonce">
        <label
          v-for="option in audienceOptions"
          :key="option.value"
          :class="['audience-card', form.audience_type === option.value && 'is-selected']"
        >
          <input v-model="form.audience_type" type="radio" :value="option.value">
          <span>
            <strong>{{ option.label }}</strong>
            <small>{{ option.description }}</small>
          </span>
        </label>
      </div>
    </div>

    <div v-if="form.audience_type === 'classroom'" class="field">
      <label for="broadcast-classroom">Classe</label>
      <select id="broadcast-classroom" v-model.number="form.classroom_id" class="input">
        <option value="" disabled>Choisir une classe</option>
        <option v-for="c in classrooms" :key="c.id" :value="c.id">{{ c.full_name }}</option>
      </select>
    </div>

    <div v-if="form.audience_type === 'cycle'" class="field">
      <label for="broadcast-cycle">Cycle</label>
      <select id="broadcast-cycle" v-model="form.cycle" class="input">
        <option value="" disabled>Choisir un cycle</option>
        <option v-for="c in cycles" :key="c.value" :value="c.value">{{ c.label }}</option>
      </select>
    </div>

    <div v-if="form.audience_type === 'custom'" class="field">
      <label for="broadcast-users">Utilisateurs</label>
      <select id="broadcast-users" v-model="form.user_ids" class="input" multiple size="7">
        <option v-for="c in contacts" :key="c.id" :value="c.id">{{ c.name }} · {{ roleLabel(c.role) }}</option>
      </select>
    </div>

    <div class="preview-card" :class="{ muted: previewCount === null }">
      <span class="preview-icon">{{ previewCount ?? '—' }}</span>
      <span>
        <strong>
          <template v-if="previewCount !== null">{{ previewCount }} destinataire(s)</template>
          <template v-else-if="previewError">{{ previewError }}</template>
          <template v-else>Aperçu en attente</template>
        </strong>
        <small>{{ selectedAudience.label }} · {{ selectedAudience.description }}</small>
      </span>
    </div>

    <div class="field">
      <label for="broadcast-subject">Objet</label>
      <input
        id="broadcast-subject"
        v-model="form.subject"
        type="text"
        class="input"
        maxlength="255"
        placeholder="Ex. Réunion parents, rappel administratif, information urgente"
      >
      <small v-if="fieldErrors.subject" class="err">{{ fieldErrors.subject[0] }}</small>
    </div>

    <div class="field">
      <div class="label-row">
        <label for="broadcast-body">Message</label>
        <span>{{ form.body.length }}/5000</span>
      </div>
      <textarea
        id="broadcast-body"
        v-model="form.body"
        class="input"
        rows="8"
        maxlength="5000"
        placeholder="Rédigez l’annonce telle qu’elle sera reçue par les destinataires..."
      />
      <small v-if="fieldErrors.body" class="err">{{ fieldErrors.body[0] }}</small>
    </div>

    <template #footer>
      <button type="button" class="btn-muted" @click="emit('close')">Annuler</button>
      <button type="button" class="btn-primary" :disabled="sending || previewCount === 0" @click="submit">
        {{ sending ? 'Envoi…' : 'Envoyer l’annonce' }}
      </button>
    </template>
  </Modal>
</template>

<style scoped>
.broadcast-intro {
  margin: -0.2rem 0 1rem;
  padding: 0.9rem 1rem;
  border: 1px solid #d9e2ff;
  border-radius: calc(var(--radius) + 4px);
  background: linear-gradient(135deg, #ffffff 0%, #f6f8ff 100%);
}

.broadcast-intro p {
  margin: 0.3rem 0 0;
  color: var(--text-soft);
}

.eyebrow {
  margin: 0;
  color: var(--text-soft);
  font-size: 0.72rem;
  font-weight: 850;
  letter-spacing: 0.08em;
  text-transform: uppercase;
}

.audience-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.45rem;
}

.audience-card {
  position: relative;
  display: grid;
  grid-template-columns: auto minmax(0, 1fr);
  align-items: center;
  gap: 0.65rem;
  min-height: 4rem;
  padding: 0.65rem 0.75rem;
  border: 1px solid var(--border);
  border-radius: calc(var(--radius) + 2px);
  background: var(--bg-subtle);
  color: var(--text);
  cursor: pointer;
  transition:
    border-color 0.15s ease,
    background 0.15s ease,
    box-shadow 0.15s ease;
}

.audience-card:hover,
.audience-card.is-selected {
  border-color: #c7d5ff;
  background: var(--primary-soft);
}

.audience-card.is-selected {
  box-shadow: inset 3px 0 0 var(--primary);
}

.audience-card input {
  width: auto;
  margin: 0;
}

.audience-card strong,
.audience-card small {
  display: block;
}

.audience-card small {
  margin-top: 0.1rem;
  color: var(--text-soft);
  font-size: 0.76rem;
  font-weight: 500;
}

.preview-card {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  margin: 0.4rem 0 1rem;
  padding: 0.85rem 1rem;
  border: 1px solid #c7d5ff;
  border-radius: calc(var(--radius) + 4px);
  background: #f5f8ff;
  color: var(--primary);
}

.preview-card.muted {
  border-color: var(--border);
  background: var(--bg-subtle);
  color: var(--text-soft);
}

.preview-card strong,
.preview-card small {
  display: block;
}

.preview-card small {
  color: var(--text-soft);
  font-size: 0.8rem;
}

.preview-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 2.4rem;
  height: 2.4rem;
  padding: 0 0.35rem;
  border-radius: 999px;
  background: var(--bg-card);
  font-weight: 850;
  box-shadow: var(--shadow);
}

.label-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
}

.label-row span {
  color: var(--text-muted);
  font-size: 0.78rem;
}

.err {
  display: block;
  color: var(--danger);
  font-size: 0.78rem;
}

@media (max-width: 560px) {
  .audience-grid {
    grid-template-columns: 1fr;
  }
}
</style>
