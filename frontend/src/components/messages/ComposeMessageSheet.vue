<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { ArrowLeft, LoaderCircle, Search, Send } from 'lucide-vue-next'
import type { LevelCycle, MessageContact } from '../../types'

type ComposeContactTypeFilter = 'all' | 'eleve' | 'parent' | 'enseignant' | 'administration'
type ComposeCycleFilter = 'all' | LevelCycle
type ComposeClassroomFilter = 'all' | number

const props = withDefaults(
  defineProps<{
    open: boolean
    isReply: boolean
    composing: boolean
    composeError: string
    composeErrors: Record<string, string[]>
    recipientId: number | ''
    subject: string
    body: string
    bodyRemaining: number
    canSubmit: boolean
    contactsLoading: boolean
    contactsError: string
    filteredContacts: MessageContact[]
    selectedContact: MessageContact | null
    contactSearch: string
    showAdminFilters?: boolean
    contactTypeFilter?: ComposeContactTypeFilter
    contactCycleFilter?: ComposeCycleFilter
    contactClassroomFilter?: ComposeClassroomFilter
    contactTypeOptions?: Array<{ value: ComposeContactTypeFilter; label: string }>
    contactCycleOptions?: Array<{ value: ComposeCycleFilter; label: string }>
    classroomOptions?: Array<{ id: number; name: string; cycle?: string | null }>
    availableCycles?: Set<LevelCycle>
  }>(),
  { showAdminFilters: false },
)

const emit = defineEmits<{
  close: []
  send: []
  'retry-contacts': []
  'select-contact': [contact: MessageContact]
  'clear-recipient': []
  'update:contactSearch': [value: string]
  'update:contactTypeFilter': [value: ComposeContactTypeFilter]
  'update:contactCycleFilter': [value: ComposeCycleFilter]
  'update:contactClassroomFilter': [value: ComposeClassroomFilter]
  'update:subject': [value: string]
  'update:body': [value: string]
}>()

const step = ref<'pick' | 'write'>('pick')

const PALETTE = ['#00a884', '#128c7e', '#34b7f1', '#7c3aed', '#ea580c', '#ec4899']

function avatarColor(id: number): string {
  return PALETTE[id % PALETTE.length]
}

function initials(name?: string): string {
  return (name ?? '')
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((p) => p.charAt(0).toUpperCase())
    .join('') || '?'
}

function roleLabel(role?: string): string {
  const labels: Record<string, string> = {
    admin: 'Administration',
    enseignant: 'Enseignant',
    parent: 'Parent',
    eleve: 'Élève',
    secretariat: 'Secrétariat',
  }
  return role ? labels[role] ?? role : ''
}

const headerTitle = computed(() => {
  if (step.value === 'write' && props.selectedContact) return props.selectedContact.name
  return props.isReply ? 'Répondre' : 'Nouvelle discussion'
})

function syncStep(): void {
  if (!props.open) return
  step.value = props.isReply || props.recipientId ? 'write' : 'pick'
}

function onBack(): void {
  if (step.value === 'write' && !props.isReply) {
    step.value = 'pick'
    emit('clear-recipient')
    return
  }
  emit('close')
}

function pick(contact: MessageContact): void {
  emit('select-contact', contact)
  step.value = 'write'
}

watch(() => props.open, (open) => {
  if (open) {
    syncStep()
    document.body.style.overflow = 'hidden'
  } else {
    document.body.style.overflow = ''
  }
})

watch(() => [props.isReply, props.recipientId], () => {
  if (props.open) syncStep()
})
</script>

<template>
  <Teleport to="body">
    <Transition name="wa-compose">
      <div v-if="open" class="wa-compose" role="dialog" aria-modal="true" :aria-label="headerTitle">
        <header class="wa-compose__header">
          <button type="button" class="wa-compose__back" aria-label="Retour" @click="onBack">
            <ArrowLeft :size="22" />
          </button>
          <div class="wa-compose__title">
            <h2>{{ headerTitle }}</h2>
            <p v-if="step === 'write' && selectedContact">{{ roleLabel(selectedContact.role) }}</p>
            <p v-else>Choisissez un destinataire</p>
          </div>
        </header>

        <p v-if="composeError" class="wa-compose__error">{{ composeError }}</p>

        <!-- Contacts -->
        <div v-if="step === 'pick'" class="wa-compose__pick">
          <div class="wa-compose__search">
            <Search :size="18" />
            <input
              :value="contactSearch"
              type="search"
              placeholder="Rechercher un nom"
              @input="emit('update:contactSearch', ($event.target as HTMLInputElement).value)"
            >
          </div>

          <div v-if="showAdminFilters && contactTypeOptions?.length" class="wa-compose__filters">
            <div class="wa-filter-chips">
              <button
                v-for="option in contactTypeOptions"
                :key="option.value"
                type="button"
                class="wa-chip"
                :class="{ active: contactTypeFilter === option.value }"
                @click="emit('update:contactTypeFilter', option.value)"
              >
                {{ option.label }}
              </button>
            </div>
            <select
              v-if="classroomOptions"
              :value="contactClassroomFilter"
              class="wa-filter-select"
              aria-label="Classe"
              @change="(e) => {
                const v = (e.target as HTMLSelectElement).value
                emit('update:contactClassroomFilter', v === 'all' ? 'all' : Number(v))
              }"
            >
              <option value="all">Toutes les classes</option>
              <option v-for="room in classroomOptions" :key="room.id" :value="room.id">{{ room.name }}</option>
            </select>
          </div>

          <div class="wa-compose__list">
            <div v-if="contactsLoading" class="wa-compose__state">
              <LoaderCircle :size="22" class="wa-spin" />
              <span>Chargement…</span>
            </div>
            <div v-else-if="contactsError" class="wa-compose__state">
              <span>{{ contactsError }}</span>
              <button type="button" @click="emit('retry-contacts')">Réessayer</button>
            </div>
            <div v-else-if="filteredContacts.length === 0" class="wa-compose__state">
              <span>Aucun contact</span>
            </div>
            <template v-else>
              <button
                v-for="contact in filteredContacts"
                :key="contact.id"
                type="button"
                class="wa-compose__contact"
                @click="pick(contact)"
              >
                <span class="wa-compose__avatar" :style="{ background: avatarColor(contact.id) }">
                  {{ initials(contact.name) }}
                </span>
                <span class="wa-compose__contact-text">
                  <strong>{{ contact.name }}</strong>
                  <small>{{ roleLabel(contact.role) }}</small>
                </span>
              </button>
            </template>
          </div>
        </div>

        <!-- Message -->
        <div v-else class="wa-compose__write">
          <label v-if="!isReply" class="wa-compose__subject">
            <span class="sr-only">Objet</span>
            <input
              :value="subject"
              type="text"
              maxlength="255"
              placeholder="Objet (ex. Question cours, RDV…)"
              @input="emit('update:subject', ($event.target as HTMLInputElement).value)"
            >
          </label>
          <small v-if="composeErrors.subject" class="wa-field-err">{{ composeErrors.subject[0] }}</small>

          <div class="wa-compose__message">
            <textarea
              :value="body"
              maxlength="5000"
              placeholder="Écrivez votre message…"
              @input="emit('update:body', ($event.target as HTMLTextAreaElement).value)"
            />
            <span class="wa-compose__count" :class="{ low: bodyRemaining < 200 }">{{ bodyRemaining }}</span>
          </div>
          <small v-if="composeErrors.body" class="wa-field-err">{{ composeErrors.body[0] }}</small>
        </div>

        <footer v-if="step === 'write'" class="wa-compose__footer">
          <button type="button" class="wa-compose__send" :disabled="!canSubmit" @click="emit('send')">
            <LoaderCircle v-if="composing" :size="20" class="wa-spin" />
            <Send v-else :size="20" />
            <span>{{ composing ? 'Envoi…' : 'Envoyer' }}</span>
          </button>
        </footer>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.wa-compose {
  position: fixed;
  /* Teleporté dans <body> : suit la zone visible réelle (clavier mobile, iOS) */
  top: var(--vvt, 0px);
  left: 0;
  right: 0;
  height: var(--vvh, 100dvh);
  z-index: 200;
  display: flex;
  flex-direction: column;
  overflow: hidden;
  max-width: 36rem;
  margin: 0 auto;
}


.wa-compose__back {
  cursor: pointer;
}

.wa-compose__title {
  min-width: 0;
  flex: 1;
}

.wa-compose__title h2 {
  margin: 0;
  overflow: hidden;
  font-size: 1.05rem;
  font-weight: 850;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.wa-compose__title p {
  margin: 0.1rem 0 0;
  font-size: 0.76rem;
}

.wa-compose__error {
  margin: 0;
  padding: 0.55rem 0.85rem;
  background: var(--danger-soft);
  color: var(--danger);
  font-size: 0.82rem;
  font-weight: 700;
}

.wa-compose__pick,
.wa-compose__write {
  display: flex;
  flex: 1;
  flex-direction: column;
  min-height: 0;
  overflow: hidden;
}

.wa-compose__search {
  display: flex;
  align-items: center;
  gap: 0.55rem;
  flex-shrink: 0;
}

.wa-compose__search input {
  flex: 1;
  border: 0;
  background: transparent;
  color: var(--text);
  font-size: 0.95rem;
  outline: none;
}

.wa-compose__filters {
  display: grid;
}

.wa-filter-chips {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
}

.wa-chip {
  border-radius: 999px;
  background: var(--bg-soft);
  color: var(--text-soft);
  border: 1px solid var(--border);
  font-size: 0.76rem;
  font-weight: 750;
  cursor: pointer;
}

.wa-chip.active {
  background: var(--primary-soft);
  color: var(--accent);
  border-color: var(--primary-tint);
}

.wa-filter-select {
  padding: 0.35rem 0.6rem;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: var(--bg-soft);
  color: var(--text);
  font-size: 0.84rem;
}

.wa-compose__list {
  flex: 1;
  overflow-y: auto;
  -webkit-overflow-scrolling: touch;
}

.wa-compose__contact {
  display: flex;
  align-items: center;
  width: 100%;
  border: 0;
  border-bottom: 1px solid var(--border);
  background: var(--bg-card);
  color: var(--text);
  text-align: left;
  cursor: pointer;
  transition: background 0.12s ease;
}

.wa-compose__contact:hover {
  background: var(--bg-subtle);
}

.wa-compose__avatar {
  display: grid;
  width: 2.85rem;
  height: 2.85rem;
  place-items: center;
  flex-shrink: 0;
  border-radius: 999px;
  color: #fff;
  font-size: 0.9rem;
  font-weight: 850;
}

.wa-compose__contact-text {
  min-width: 0;
}

.wa-compose__contact-text strong {
  display: block;
  font-size: 1rem;
  font-weight: 850;
}

.wa-compose__contact-text small {
  color: var(--text-soft);
  font-size: 0.84rem;
}

.wa-compose__state {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.55rem;
  padding: 2rem 1.25rem;
  color: var(--wa-text-soft, var(--text-soft));
  text-align: center;
}

.wa-compose__state button {
  border: 0;
  border-radius: 999px;
  padding: 0.5rem 1.1rem;
  background: var(--primary);
  color: #fff;
  font-weight: 800;
  cursor: pointer;
}

.wa-compose__write {
  display: flex;
  flex: 1;
  flex-direction: column;
  min-height: 0;
  overflow: auto;
}

.wa-compose__subject input {
  width: 100%;
  min-height: 2.75rem;
  padding: 0 0.85rem;
  border: 0;
  background: var(--bg-soft);
  color: var(--text);
  font-size: 0.94rem;
  outline: none;
}

.wa-compose__message {
  position: relative;
  flex: 1;
  display: flex;
  min-height: 0;
}

.wa-compose__message textarea {
  width: 100%;
  min-height: 12rem;
  flex: 1;
  padding: 0.85rem 0.95rem 2.1rem;
  border: 0;
  background: var(--bg-card);
  color: var(--text);
  font-size: 1.05rem;
  line-height: 1.45;
  resize: none;
  outline: none;
}

.wa-compose__count {
  position: absolute;
  right: 0.75rem;
  bottom: 0.55rem;
  color: var(--text-muted);
  font-size: 0.72rem;
  font-weight: 700;
}

.wa-compose__count.low {
  color: var(--warn);
}

.wa-field-err {
  color: var(--danger);
  font-size: 0.78rem;
  font-weight: 700;
}

.wa-compose__footer {
  flex-shrink: 0;
}

.wa-compose__send {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
  width: 100%;
  border: 0;
  border-radius: 999px;
  color: #fff;
  font-size: 0.96rem;
  font-weight: 850;
  cursor: pointer;
}

.wa-compose__send:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.wa-spin {
  animation: wa-spin 0.8s linear infinite;
}

@keyframes wa-spin {
  to { transform: rotate(360deg); }
}

.sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
  border: 0;
}

.wa-compose-enter-active,
.wa-compose-leave-active {
  transition: transform 0.28s cubic-bezier(0.4, 0, 0.2, 1), opacity 0.2s ease;
}

.wa-compose-enter-from,
.wa-compose-leave-to {
  transform: translateY(100%);
  opacity: 0.6;
}

@media (min-width: 721px) {
  .wa-compose {
    inset: auto;
    top: 50%;
    left: 50%;
    width: min(26rem, 92vw);
    height: auto;
    max-height: 88dvh;
    border-radius: 12px;
    box-shadow: 0 16px 48px rgba(11, 20, 26, 0.22);
    transform: translate(-50%, -50%);
  }

  .wa-compose-enter-from,
  .wa-compose-leave-to {
    transform: translate(-50%, -46%);
    opacity: 0;
  }
}
</style>
