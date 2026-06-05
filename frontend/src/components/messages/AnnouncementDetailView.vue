<script setup lang="ts">
import { computed } from 'vue'
import { ChevronLeft } from 'lucide-vue-next'
import type { Message } from '../../types'

interface AnnouncementGroup {
  key: string
  message: Message
  recipientsCount: number
}

const props = defineProps<{
  group: AnnouncementGroup | null
  mode: 'sent' | 'received'
  error?: string
  editing?: boolean
  saving?: boolean
  formSubject: string
  formBody: string
  canEdit: boolean
  isPortalUser?: boolean
}>()

const emit = defineEmits<{
  (e: 'back'): void
  (e: 'start-edit'): void
  (e: 'cancel-edit'): void
  (e: 'save-edit'): void
  (e: 'update:formSubject', value: string): void
  (e: 'update:formBody', value: string): void
}>()

function formatDateTime(iso?: string): string {
  if (!iso) return '—'
  const date = new Date(iso)
  const now = new Date()
  const sameDay = date.toDateString() === now.toDateString()

  if (sameDay) {
    return date.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })
  }

  return date.toLocaleDateString('fr-FR', {
    day: 'numeric',
    month: 'short',
    year: date.getFullYear() !== now.getFullYear() ? 'numeric' : undefined,
    hour: '2-digit',
    minute: '2-digit',
  })
}

function formatDocumentDate(iso?: string): string {
  if (!iso) return '—'
  const date = new Date(iso)
  const datePart = date.toLocaleDateString('fr-FR', {
    day: 'numeric',
    month: 'long',
    year: 'numeric',
  })
  const timePart = date.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })
  return `Le ${datePart} à ${timePart}`
}

function initialsFor(name?: string): string {
  return (name ?? '')
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part.charAt(0).toUpperCase())
    .join('') || '?'
}

function roleLabel(role?: string): string {
  const map: Record<string, string> = {
    admin: 'Administration',
    enseignant: 'Enseignant',
    parent: 'Parent',
    eleve: 'Élève',
    secretariat: 'Secrétariat',
  }
  return map[role ?? ''] ?? role ?? ''
}

const selectedAnnouncementRecipients = computed(() =>
  props.group?.message.broadcast_recipients ?? [],
)

const selectedAnnouncementRecipientRoleCounts = computed(() => {
  const counts = new Map<string, number>()

  for (const recipient of selectedAnnouncementRecipients.value) {
    const label = roleLabel(recipient.role)
    counts.set(label, (counts.get(label) ?? 0) + 1)
  }

  return [...counts.entries()].map(([label, count]) => ({ label, count }))
})

const subject = computed({
  get: () => props.formSubject,
  set: (val) => emit('update:formSubject', val),
})

const body = computed({
  get: () => props.formBody,
  set: (val) => emit('update:formBody', val),
})

const senderName = computed(
  () => props.group?.message.sender?.name ?? 'Administration',
)

const sentAt = computed(() => formatDateTime(props.group?.message.created_at))

const documentDate = computed(() => formatDocumentDate(props.group?.message.created_at))

const viewTitle = computed(() => {
  if (props.isPortalUser && props.mode === 'received') return 'Communiqué officiel'
  if (props.mode === 'sent') return 'Résumé de la diffusion'
  return 'Résumé de l’annonce'
})

const sentMetaItems = computed(() => {
  if (!props.group || props.mode !== 'sent') return []

  return [
    { label: 'Destinataires', value: String(props.group.recipientsCount) },
    { label: 'Envoyée', value: sentAt.value },
    { label: 'Statut', value: 'Diffusée' },
  ]
})

const showActions = computed(
  () => props.canEdit || props.editing,
)
</script>

<template>
  <section
    v-if="group"
    class="announce-detail"
    :class="{ 'announce-detail--portal': isPortalUser }"
    aria-label="Détail de l’annonce"
  >
    <header v-if="!isPortalUser" class="announce-detail__toolbar">
      <button type="button" class="announce-detail__back portal-touch" @click="emit('back')">
        <ChevronLeft :size="20" aria-hidden="true" />
        Retour
      </button>
      <h1>{{ viewTitle }}</h1>
    </header>

    <div class="announce-detail__scroll">
      <p v-if="error" class="announce-alert" role="alert">{{ error }}</p>

      <div class="announce-summary">
        <dl v-if="mode === 'sent'" class="announce-meta">
          <div
            v-for="item in sentMetaItems"
            :key="item.label"
            class="announce-meta__cell"
          >
            <dt>{{ item.label }}</dt>
            <dd>{{ item.value }}</dd>
          </div>
        </dl>

        <template v-if="editing">
          <div class="announce-field">
            <label for="announcement-edit-subject">Objet</label>
            <input
              id="announcement-edit-subject"
              v-model="subject"
              type="text"
              maxlength="255"
            >
          </div>
          <div class="announce-field">
            <div class="announce-field__head">
              <label for="announcement-edit-body">Message</label>
              <span>{{ body.length }}/5000</span>
            </div>
            <textarea
              id="announcement-edit-body"
              v-model="body"
              rows="8"
              maxlength="5000"
            />
          </div>
        </template>

        <template v-else>
          <article
            class="announce-doc"
            :class="{ 'announce-doc--portal': isPortalUser && mode === 'received' }"
            role="document"
            :aria-label="`Communiqué : ${group.message.subject}`"
          >
            <header class="announce-doc__head">
              <div class="announce-doc__brand">
                <p class="announce-doc__institution">Établissement scolaire</p>
                <p v-if="!isPortalUser || mode !== 'received'" class="announce-doc__type">
                  Communiqué officiel
                </p>
              </div>
              <time
                class="announce-doc__date"
                :datetime="group.message.created_at"
              >
                {{ documentDate }}
              </time>
            </header>

            <section
              v-if="!isPortalUser || mode !== 'received'"
              class="announce-doc__subject"
              aria-labelledby="announce-doc-subject-label"
            >
              <span id="announce-doc-subject-label" class="announce-doc__label">Objet</span>
              <h2>{{ group.message.subject }}</h2>
            </section>

            <section class="announce-doc__body" aria-label="Contenu du communiqué">
              <h2
                v-if="isPortalUser && mode === 'received'"
                class="announce-doc__title"
              >
                {{ group.message.subject }}
              </h2>
              <p>{{ group.message.body }}</p>
            </section>

            <footer
              class="announce-doc__foot"
              :class="{ 'announce-doc__foot--portal': isPortalUser && mode === 'received' }"
            >
              <p class="announce-doc__closing">Pour information et disposition utile,</p>
              <div
                class="announce-detail__signatory-row"
                :class="{ 'announce-detail__signatory-row--portal': isPortalUser && mode === 'received' }"
              >
                <span
                  v-if="isPortalUser && mode === 'received'"
                  class="announce-detail__signatory-mark"
                  aria-hidden="true"
                />
                <p class="announce-doc__signatory">{{ senderName }}</p>
              </div>
            </footer>
          </article>

          <section v-if="mode === 'sent'" class="announce-recipients">
            <header class="announce-recipients__head">
              <div>
                <span class="announce-label">Destinataires</span>
                <strong>{{ selectedAnnouncementRecipients.length }} contact(s)</strong>
              </div>
              <div v-if="selectedAnnouncementRecipientRoleCounts.length" class="announce-recipients__chips">
                <span
                  v-for="item in selectedAnnouncementRecipientRoleCounts"
                  :key="item.label"
                >
                  {{ item.label }} · {{ item.count }}
                </span>
              </div>
            </header>

            <p v-if="selectedAnnouncementRecipients.length === 0" class="announce-recipients__empty">
              Liste des destinataires indisponible pour cette diffusion.
            </p>
            <ul v-else class="announce-recipients__list">
              <li v-for="recipient in selectedAnnouncementRecipients" :key="recipient.id">
                <span class="announce-recipients__avatar" aria-hidden="true">
                  {{ initialsFor(recipient.name) }}
                </span>
                <span class="announce-recipients__main">
                  <strong>{{ recipient.name }}</strong>
                  <small>{{ recipient.email }}</small>
                </span>
                <span class="announce-recipients__role">{{ roleLabel(recipient.role) }}</span>
                <span
                  class="announce-recipients__read"
                  :class="recipient.is_read ? 'is-read' : 'is-unread'"
                >
                  {{ recipient.is_read ? 'Lu' : 'Non lu' }}
                </span>
              </li>
            </ul>
          </section>
        </template>
      </div>
    </div>

    <footer v-if="showActions" class="announce-detail__actions">
      <button
        v-if="canEdit && !editing"
        type="button"
        class="announce-btn announce-btn--secondary"
        @click="emit('start-edit')"
      >
        Modifier
      </button>
      <button
        v-if="editing"
        type="button"
        class="announce-btn announce-btn--muted"
        :disabled="saving"
        @click="emit('cancel-edit')"
      >
        Annuler
      </button>
      <button
        v-if="editing"
        type="button"
        class="announce-btn announce-btn--primary"
        :disabled="saving"
        @click="emit('save-edit')"
      >
        {{ saving ? 'Enregistrement…' : 'Enregistrer' }}
      </button>
    </footer>
  </section>
</template>

<style scoped>
.announce-detail {
  display: flex;
  flex-direction: column;
  min-height: 0;
  flex: 1 1 auto;
}

.announce-detail--portal {
  overflow: hidden;
  min-height: 100%;
  background: var(--bg-subtle);
}

.announce-detail__signatory-row {
  display: block;
}

.announce-detail__signatory-row--portal {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 0.55rem;
  margin-top: 0.45rem;
}

.announce-detail__signatory-mark {
  width: 2rem;
  height: 2px;
  flex-shrink: 0;
  border-radius: 999px;
  background: var(--primary);
  opacity: 0.55;
}

.announce-detail__toolbar {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 0.35rem;
  margin-bottom: 0.85rem;
}

.announce-detail__back {
  display: inline-flex;
  align-items: center;
  gap: 0.15rem;
  min-height: 2.75rem;
  padding: 0.35rem 0.5rem 0.35rem 0.25rem;
  border: 0;
  border-radius: var(--radius);
  background: transparent;
  color: var(--primary-dark);
  font-size: 0.88rem;
  font-weight: 800;
  cursor: pointer;
  touch-action: manipulation;
}

.announce-detail__back:focus-visible,
.announce-btn:focus-visible {
  outline: 2px solid var(--primary);
  outline-offset: 2px;
}

.announce-detail__toolbar h1 {
  margin: 0;
  font-size: 1.2rem;
  font-weight: 900;
  line-height: 1.25;
}

.announce-detail__scroll {
  flex: 1 1 auto;
  min-height: 0;
  overflow-y: auto;
  -webkit-overflow-scrolling: touch;
}

.announce-detail--portal .announce-detail__scroll {
  display: flex;
  flex-direction: column;
  flex: 1 1 auto;
  min-height: 0;
  padding: 0;
}

.announce-detail--portal .announce-summary {
  display: flex;
  flex: 1 1 auto;
  flex-direction: column;
  min-height: 0;
}

.announce-detail__actions {
  display: flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: 0.5rem;
  flex-shrink: 0;
  padding-top: 0.75rem;
  border-top: 1px solid var(--border);
  margin-top: 0.5rem;
}

.announce-detail--portal .announce-detail__actions {
  padding: 0.65rem 0 calc(0.65rem + env(safe-area-inset-bottom));
  margin-top: 0;
  background: var(--bg-card);
}

.announce-btn {
  min-height: 2.65rem;
  padding: 0.45rem 0.9rem;
  border-radius: var(--radius);
  font-size: 0.88rem;
  font-weight: 800;
  cursor: pointer;
}

.announce-btn--muted {
  border: 1px solid var(--border);
  background: var(--bg-card);
  color: var(--text);
}

.announce-btn--secondary {
  border: 1px solid var(--border);
  background: var(--bg-subtle);
  color: var(--text);
}

.announce-btn--primary {
  border: 0;
  background: var(--primary);
  color: #fff;
}

.announce-btn:disabled {
  opacity: 0.55;
  cursor: not-allowed;
}

.announce-alert {
  margin: 0 0 0.85rem;
  padding: 0.65rem 0.75rem;
  border-radius: var(--radius);
  background: var(--danger-soft);
  color: #b91c1c;
  font-size: 0.88rem;
  font-weight: 650;
}

.announce-summary {
  display: grid;
  gap: 0.85rem;
}

.announce-meta {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 0.5rem;
  margin: 0;
}

.announce-meta__cell {
  display: grid;
  gap: 0.2rem;
  padding: 0.7rem 0.75rem;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: var(--bg-subtle);
}

.announce-meta__cell dt {
  margin: 0;
  color: var(--text-muted);
  font-size: 0.68rem;
  font-weight: 800;
  letter-spacing: 0.04em;
  text-transform: uppercase;
}

.announce-meta__cell dd {
  margin: 0;
  color: var(--text);
  font-size: 0.92rem;
  font-weight: 850;
  line-height: 1.25;
  overflow-wrap: anywhere;
}

.announce-doc {
  overflow: hidden;
  border: 1px solid var(--border);
  border-radius: calc(var(--radius) + 2px);
  background: var(--bg-card);
  box-shadow: var(--shadow-card);
}

.announce-doc--portal {
  display: flex;
  flex: 1 1 auto;
  flex-direction: column;
  min-height: 100%;
  border: 0;
  border-radius: calc(var(--radius) + 4px) calc(var(--radius) + 4px) 0 0;
  box-shadow: 0 -2px 24px rgba(15, 23, 42, 0.06);
}

.announce-doc__head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
  padding: 1rem 1.1rem;
  border-bottom: 2px solid var(--primary);
  background: linear-gradient(180deg, var(--primary-soft) 0%, var(--bg-card) 100%);
}

.announce-doc--portal .announce-doc__head {
  padding: 0.9rem 1rem 0.85rem;
  border-bottom-width: 1px;
  border-bottom-color: var(--border);
  background: var(--bg-card);
}

.announce-doc__institution {
  margin: 0;
  color: var(--text-muted);
  font-size: 0.68rem;
  font-weight: 800;
  letter-spacing: 0.1em;
  text-transform: uppercase;
}

.announce-doc__type {
  margin: 0.2rem 0 0;
  color: var(--text);
  font-size: 1.02rem;
  font-weight: 900;
  letter-spacing: 0.02em;
  text-transform: uppercase;
}

.announce-doc__date {
  flex-shrink: 0;
  max-width: 11rem;
  color: var(--text-soft);
  font-size: 0.76rem;
  font-weight: 700;
  line-height: 1.45;
  text-align: right;
}

.announce-doc__label {
  display: block;
  color: var(--text-muted);
  font-size: 0.68rem;
  font-weight: 800;
  letter-spacing: 0.08em;
  text-transform: uppercase;
}

.announce-doc__subject {
  padding: 0.95rem 1.1rem 0.8rem;
  border-bottom: 1px solid var(--border);
}

.announce-doc__subject h2 {
  margin: 0.35rem 0 0;
  font-size: 1.12rem;
  font-weight: 850;
  line-height: 1.4;
  overflow-wrap: anywhere;
}

.announce-doc__body {
  padding: 1rem 1.1rem 1.15rem;
}

.announce-doc--portal .announce-doc__body {
  flex: 1 1 auto;
  padding: 1.1rem 1rem 1rem;
}

.announce-doc__title {
  margin: 0 0 0.85rem;
  font-size: 1.15rem;
  font-weight: 850;
  line-height: 1.35;
  letter-spacing: -0.01em;
  overflow-wrap: anywhere;
}

.announce-doc__body p {
  margin: 0;
  color: var(--text);
  font-family: Georgia, 'Times New Roman', Times, serif;
  font-size: 1rem;
  line-height: 1.75;
  white-space: pre-wrap;
  overflow-wrap: anywhere;
}

.announce-doc--portal .announce-doc__body p {
  font-size: 1rem;
  line-height: 1.7;
}

.announce-doc__foot {
  padding: 0.85rem 1.1rem 1rem;
  border-top: 1px solid var(--border);
  background: #fbfcff;
}

.announce-doc__foot--portal {
  flex-shrink: 0;
  margin-top: auto;
  padding: 1rem 1rem calc(1rem + env(safe-area-inset-bottom));
  background: var(--bg-card);
}

.announce-doc__closing {
  margin: 0;
  color: var(--text-soft);
  font-size: 0.86rem;
  font-style: italic;
  line-height: 1.45;
}

.announce-doc__foot--portal .announce-doc__closing {
  font-size: 0.84rem;
}

.announce-doc__signatory {
  margin: 0.45rem 0 0;
  color: var(--text);
  font-size: 0.92rem;
  font-weight: 850;
  text-align: right;
}

.announce-doc__foot--portal .announce-doc__signatory {
  margin: 0;
  font-size: 0.95rem;
}

.announce-label {
  display: block;
  color: var(--text-muted);
  font-size: 0.68rem;
  font-weight: 800;
  letter-spacing: 0.04em;
  text-transform: uppercase;
}

.announce-field {
  display: grid;
  gap: 0.35rem;
}

.announce-field label {
  font-size: 0.84rem;
  font-weight: 750;
  color: var(--text);
}

.announce-field__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
}

.announce-field__head span {
  color: var(--text-muted);
  font-size: 0.76rem;
}

.announce-field input,
.announce-field textarea {
  width: 100%;
  padding: 0.6rem 0.75rem;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: var(--bg-card);
  font: inherit;
  font-size: 1rem;
  line-height: 1.4;
}

.announce-field textarea {
  min-height: 8rem;
  resize: vertical;
}

.announce-recipients {
  overflow: hidden;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: var(--bg-card);
}

.announce-recipients__head {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  justify-content: space-between;
  gap: 0.5rem;
  padding: 0.75rem 0.9rem;
  border-bottom: 1px solid var(--border);
  background: var(--bg-subtle);
}

.announce-recipients__head strong {
  display: block;
  margin-top: 0.15rem;
  font-size: 0.92rem;
}

.announce-recipients__chips {
  display: flex;
  flex-wrap: wrap;
  gap: 0.35rem;
}

.announce-recipients__chips span {
  padding: 0.15rem 0.5rem;
  border-radius: 999px;
  background: var(--bg-card);
  color: var(--text-soft);
  font-size: 0.72rem;
  font-weight: 700;
}

.announce-recipients__empty {
  margin: 0;
  padding: 1rem 0.9rem;
  color: var(--text-soft);
  font-size: 0.88rem;
}

.announce-recipients__list {
  margin: 0;
  padding: 0;
  list-style: none;
  max-height: 14rem;
  overflow-y: auto;
}

.announce-recipients__list li {
  display: grid;
  grid-template-columns: auto 1fr auto auto;
  align-items: center;
  gap: 0.55rem;
  padding: 0.65rem 0.9rem;
  border-bottom: 1px solid var(--border);
}

.announce-recipients__list li:last-child {
  border-bottom: 0;
}

.announce-recipients__avatar {
  display: grid;
  width: 2.15rem;
  height: 2.15rem;
  place-items: center;
  border-radius: 50%;
  background: var(--primary-soft);
  color: var(--primary-dark);
  font-size: 0.75rem;
  font-weight: 850;
}

.announce-recipients__main {
  min-width: 0;
}

.announce-recipients__main strong {
  display: block;
  font-size: 0.88rem;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.announce-recipients__main small {
  display: block;
  color: var(--text-muted);
  font-size: 0.74rem;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.announce-recipients__role,
.announce-recipients__read {
  font-size: 0.68rem;
  font-weight: 800;
  letter-spacing: 0.02em;
  text-transform: uppercase;
}

.announce-recipients__role {
  color: var(--text-soft);
}

.announce-recipients__read.is-read {
  color: #15803d;
}

.announce-recipients__read.is-unread {
  color: #b45309;
}

@media (max-width: 480px) {
  .announce-doc__head {
    flex-direction: column;
    gap: 0.65rem;
  }

  .announce-doc__date {
    max-width: none;
    text-align: left;
  }

  .announce-meta {
    grid-template-columns: 1fr;
  }

  .announce-recipients__list li {
    grid-template-columns: auto 1fr;
    grid-template-rows: auto auto;
  }

  .announce-recipients__role,
  .announce-recipients__read {
    grid-column: 2;
  }
}
</style>
