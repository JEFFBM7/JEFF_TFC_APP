<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import { Check, CheckCheck, Clock, MessageSquare, Plus, Search, Send } from 'lucide-vue-next'
import { useAuthStore } from '../../stores/auth'
import { usePortalDashboard } from '../../composables/usePortalDashboard'
import type { Message, UserRole } from '../../types'

const auth = useAuthStore()
const { initials, childColor } = usePortalDashboard()

const props = defineProps<{
  searchQuery: string
  loading: boolean
  loadingMessage: boolean
  messages: Message[]
  conversationCount: number
  selected: Message | null
  selectedPeer: { name?: string; role?: UserRole } | null
  conversationMessages: Message[]
  quickReplyBody: string
  quickReplyError: string
  quickReplySending: boolean
  pendingOutgoing: { body: string; createdAt: string } | null
  showThread: boolean
  isDraft?: boolean
  draftSubject?: string
  emptyTitle: string
  emptyBody: string
}>()

const emit = defineEmits<{
  'update:searchQuery': [value: string]
  'update:quickReplyBody': [value: string]
  'open-compose': []
  'open-message': [message: Message]
  'refresh': []
  'send-reply': []
}>()

type OutgoingStatus = 'sending' | 'sent' | 'delivered'

function peer(msg: Message) {
  return msg.sender_id === auth.user?.id ? msg.recipient : msg.sender
}

function peerLabel(msg: Message): string {
  return peer(msg)?.name ?? 'Contact'
}

function peerId(msg: Message): number {
  return peer(msg)?.id ?? msg.id
}

function isMine(msg: Message): boolean {
  return msg.sender_id === auth.user?.id
}

function isUnreadForMe(msg: Message): boolean {
  return msg.recipient_id === auth.user?.id && !msg.is_read
}

function hasUnread(msg: Message): boolean {
  return isUnreadForMe(msg) || (msg.replies ?? []).some((reply) => isUnreadForMe(reply))
}

function formatListTime(value?: string): string {
  if (!value) return ''
  const date = new Date(value)
  const now = new Date()
  const sameDay =
    date.getDate() === now.getDate()
    && date.getMonth() === now.getMonth()
    && date.getFullYear() === now.getFullYear()
  if (sameDay) {
    return date.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })
  }
  return date.toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' })
}

function formatBubbleTime(value?: string): string {
  if (!value) return ''
  return new Date(value).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })
}

function preview(msg: Message): string {
  const text = (msg.body ?? '').replace(/\s+/g, ' ').trim()
  return text.length > 72 ? `${text.slice(0, 72)}…` : text || '—'
}

function latestInThread(msg: Message): Message {
  const thread = [msg, ...(msg.replies ?? [])]
  return thread.sort((a, b) => {
    const first = a.created_at ? new Date(a.created_at).getTime() : 0
    const second = b.created_at ? new Date(b.created_at).getTime() : 0
    return second - first
  })[0] ?? msg
}

function listPreviewText(msg: Message): string {
  return preview(latestInThread(msg))
}

function listPreviewTime(msg: Message): string {
  const latest = latestInThread(msg)
  return formatListTime(latest.created_at ?? msg.created_at)
}

function listPreviewStatus(msg: Message): OutgoingStatus | null {
  const latest = latestInThread(msg)
  if (!isMine(latest)) return null
  return outgoingStatus(latest)
}

function outgoingStatus(message: Message): OutgoingStatus {
  if (message.is_read || message.read_at) return 'delivered'
  return 'sent'
}

function statusLabel(status: OutgoingStatus): string {
  if (status === 'sending') return 'Envoi en cours'
  if (status === 'delivered') return 'Reçu'
  return 'Envoyé'
}

const searchHasNoResults = computed(() =>
  props.searchQuery.trim().length > 0
  && props.messages.length === 0
  && props.conversationCount > 0,
)

const hasReplyText = computed(() => props.quickReplyBody.trim().length > 0)

const replyRef = ref<HTMLTextAreaElement | null>(null)

function resizeReplyField(): void {
  const el = replyRef.value
  if (!el) return
  el.style.height = 'auto'
  el.style.height = `${Math.min(el.scrollHeight, 120)}px`
}

function onReplyInput(event: Event): void {
  emit('update:quickReplyBody', (event.target as HTMLTextAreaElement).value)
  resizeReplyField()
}

function onReplyKeydown(event: KeyboardEvent): void {
  if (event.key === 'Enter' && !event.shiftKey) {
    event.preventDefault()
    if (hasReplyText.value && !props.quickReplySending) {
      emit('send-reply')
    }
  }
}

watch(() => props.quickReplyBody, () => {
  void nextTick(resizeReplyField)
})

watch(() => props.showThread, (open) => {
  if (open) void nextTick(resizeReplyField)
})
</script>

<template>
  <div class="msg-app">
    <!-- Liste -->
    <div v-if="!showThread" class="msg-list">
      <div class="msg-toolbar">
        <label class="msg-search">
          <Search :size="18" aria-hidden="true" />
          <input
            :value="searchQuery"
            type="search"
            placeholder="Rechercher une conversation"
            aria-label="Rechercher une conversation"
            autocomplete="off"
            @input="emit('update:searchQuery', ($event.target as HTMLInputElement).value)"
          >
        </label>
        <button
          type="button"
          class="msg-new portal-kpi-link"
          aria-label="Nouveau message"
          @click="emit('open-compose')"
        >
          <Plus :size="22" aria-hidden="true" />
        </button>
      </div>

      <div v-if="loading && messages.length === 0" class="msg-empty" role="status">
        <span class="msg-spinner" aria-hidden="true" />
        <span>Chargement des conversations…</span>
      </div>

      <div v-else-if="searchHasNoResults" class="msg-empty">
        <span class="msg-empty-icon" aria-hidden="true">
          <Search :size="22" />
        </span>
        <strong>Aucun résultat</strong>
        <p>Essayez un autre nom, rôle ou mot du message.</p>
      </div>

      <div v-else-if="messages.length === 0" class="msg-empty">
        <span class="msg-empty-icon" aria-hidden="true">
          <MessageSquare :size="24" />
        </span>
        <strong>{{ emptyTitle }}</strong>
        <p>{{ emptyBody }}</p>
        <button type="button" class="btn-primary msg-empty-cta" @click="emit('open-compose')">
          Écrire un message
        </button>
      </div>

      <ul v-else class="msg-threads" role="list">
        <li v-for="msg in messages" :key="msg.id">
          <button
            type="button"
            class="msg-thread-row portal-kpi-link"
            :class="{ 'is-unread': hasUnread(msg) }"
            :aria-label="`${hasUnread(msg) ? 'Non lu, ' : ''}Conversation avec ${peerLabel(msg)}`"
            @click="emit('open-message', msg)"
          >
            <span
              class="msg-avatar"
              :style="{ background: childColor(peerId(msg)) }"
              aria-hidden="true"
            >{{ initials(peerLabel(msg)) }}</span>
            <span class="msg-thread-body">
              <span class="msg-thread-top">
                <strong class="msg-thread-name">{{ peerLabel(msg) }}</strong>
                <time class="msg-thread-time" :datetime="latestInThread(msg).created_at">{{ listPreviewTime(msg) }}</time>
              </span>
              <span class="msg-thread-preview">
                <span
                  v-if="listPreviewStatus(msg)"
                  class="msg-thread-preview-status"
                  :aria-label="statusLabel(listPreviewStatus(msg)!)"
                >
                  <CheckCheck v-if="listPreviewStatus(msg) === 'delivered'" :size="15" aria-hidden="true" />
                  <Check v-else :size="15" aria-hidden="true" />
                </span>
                <p>{{ listPreviewText(msg) }}</p>
                <span v-if="hasUnread(msg)" class="msg-unread-pill" aria-hidden="true" />
              </span>
            </span>
          </button>
        </li>
      </ul>
    </div>

    <!-- Fil -->
    <div v-else-if="selected || selectedPeer" class="msg-thread">
      <div v-if="loadingMessage" class="msg-loading" role="status">
        <span class="msg-spinner" aria-hidden="true" />
        <span>Chargement de la conversation…</span>
      </div>

      <div v-else class="msg-bubbles" role="log" aria-live="polite" aria-relevant="additions">
        <article
          v-for="message in conversationMessages"
          :key="message.id"
          class="msg-bubble-line"
          :class="isMine(message) ? 'is-mine' : 'is-other'"
        >
          <div class="msg-bubble">
            <p class="msg-bubble-body">
              <span class="msg-bubble-text">{{ message.body }}</span>
              <span class="msg-bubble-meta">
                <time :datetime="message.created_at">{{ formatBubbleTime(message.created_at) }}</time>
                <span
                  v-if="isMine(message)"
                  class="msg-bubble-status"
                  :aria-label="statusLabel(outgoingStatus(message))"
                >
                  <CheckCheck v-if="outgoingStatus(message) === 'delivered'" :size="14" aria-hidden="true" />
                  <Check v-else :size="14" aria-hidden="true" />
                </span>
              </span>
            </p>
          </div>
        </article>

        <article
          v-if="pendingOutgoing"
          class="msg-bubble-line is-mine is-pending"
          aria-live="polite"
        >
          <div class="msg-bubble">
            <p class="msg-bubble-body">
              <span class="msg-bubble-text">{{ pendingOutgoing.body }}</span>
              <span class="msg-bubble-meta">
                <time :datetime="pendingOutgoing.createdAt">{{ formatBubbleTime(pendingOutgoing.createdAt) }}</time>
                <span class="msg-bubble-status" aria-label="Envoi en cours">
                  <Clock :size="14" class="msg-status-clock" aria-hidden="true" />
                </span>
              </span>
            </p>
          </div>
        </article>
      </div>

      <p v-if="quickReplyError" class="msg-reply-error" role="alert">{{ quickReplyError }}</p>

      <form class="msg-reply" @submit.prevent="emit('send-reply')">
        <div class="msg-reply-field">
          <label class="sr-only" for="portal-msg-reply">Votre message</label>
          <textarea
            id="portal-msg-reply"
            ref="replyRef"
            :value="quickReplyBody"
            rows="1"
            maxlength="5000"
            placeholder="Message"
            :disabled="quickReplySending"
            @input="onReplyInput"
            @keydown="onReplyKeydown"
          />
        </div>
        <Transition name="msg-send-pop">
          <button
            v-if="hasReplyText"
            type="submit"
            class="msg-send portal-kpi-link"
            :disabled="quickReplySending"
            :aria-label="quickReplySending ? 'Envoi en cours' : 'Envoyer le message'"
          >
            <Send :size="20" aria-hidden="true" />
          </button>
        </Transition>
      </form>
    </div>
  </div>
</template>
