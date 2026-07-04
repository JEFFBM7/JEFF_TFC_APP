<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, reactive, ref, watch } from 'vue'
import {
  Check,
  CheckCheck,
  ChevronRight,
  Clock,
  Megaphone,
  MessageSquare,
  Plus,
  RefreshCw,
  Search,
  Send,
  Users,
} from 'lucide-vue-next'
import { useRoute, useRouter } from 'vue-router'
import { api, ApiError } from '../api/client'
import { subscribeToMessageUpdates, MESSAGES_UNREAD_EVENT, type MessageRealtimeEvent } from '../api/realtime'
import BroadcastComposeModal from '../components/BroadcastComposeModal.vue'
import ComposeMessageSheet from '../components/messages/ComposeMessageSheet.vue'
import PortalMessagesApp from '../components/messages/PortalMessagesApp.vue'
import AnnouncementDetailView from '../components/messages/AnnouncementDetailView.vue'
import { usePortalDashboard } from '../composables/usePortalDashboard'
import { usePortalTopbarOverride } from '../composables/usePortalTopbarOverride'
import { useToastStore } from '../stores/toast'
import { useAuthStore } from '../stores/auth'
import {
  useMessageComposeIntentStore,
  type MessageComposeStudentTarget,
} from '../stores/messageComposeIntent'
import type { ApiResource, LevelCycle, Message, MessageContact, Paginated, Student, UserRole } from '../types'

type CommunicationSection = 'messages' | 'announcements'
type ComposeContactTypeFilter = 'all' | 'eleve' | 'parent' | 'enseignant' | 'administration'
type ComposeCycleFilter = 'all' | LevelCycle
type ComposeClassroomFilter = 'all' | number

interface AnnouncementGroup {
  key: string
  message: Message
  recipientsCount: number
}

const composeContactTypeOptions: Array<{ value: ComposeContactTypeFilter; label: string }> = [
  { value: 'all', label: 'Tous' },
  { value: 'eleve', label: 'Élèves' },
  { value: 'parent', label: 'Parents' },
  { value: 'enseignant', label: 'Enseignants' },
  { value: 'administration', label: 'Administration' },
]

const composeCycleOptions: Array<{ value: ComposeCycleFilter; label: string }> = [
  { value: 'all', label: 'Tous cycles' },
  { value: 'maternel', label: 'Maternel' },
  { value: 'primaire', label: 'Primaire' },
  { value: 'cteb', label: 'CTEB' },
  { value: 'secondaire', label: 'Secondaire' },
]

const section = ref<CommunicationSection>('messages')
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const composeIntentStore = useMessageComposeIntentStore()
let composeNavigationInFlight: Promise<void> | null = null
/** Évite de retraiter la même query ; ne pas appeler router.replace sur la query (remonte MessagesView via AdminLayout). */
const handledComposeRouteSignature = ref('')
const toast = useToastStore()
const portalTopbar = usePortalTopbarOverride()
const { childColor } = usePortalDashboard()
const staffReplyRef = ref<HTMLTextAreaElement | null>(null)
const isPortalUser = computed(() => auth.hasRole('parent', 'eleve'))
const mobileShowThread = ref(false)
const messages = ref<Message[]>([])
const loading = ref(false)
const selected = ref<Message | null>(null)
const draftRecipient = ref<MessageContact | null>(null)
const draftSubject = ref('')
const loadingMessage = ref(false)
const error = ref('')
const searchQuery = ref('')
const quickReplyBody = ref('')
const quickReplyError = ref('')
const quickReplySending = ref(false)
const announcementSummaryKey = ref<string | null>(null)
const announcementEditing = ref(false)
const announcementSaving = ref(false)
const announcementError = ref('')
const announcementForm = reactive({
  subject: '',
  body: '',
})

const composeOpen = ref(false)
const broadcastOpen = ref(false)
const contacts = ref<MessageContact[]>([])
const composeForm = reactive({
  recipient_id: '' as number | '',
  subject: '',
  body: '',
  parent_message_id: null as number | null,
})
const composeErrors = reactive<Record<string, string[]>>({})
const composeError = ref('')
const composing = ref(false)
const contactsLoading = ref(false)
const contactsError = ref('')
const contactSearch = ref('')
const contactTypeFilter = ref<ComposeContactTypeFilter>('all')
const contactCycleFilter = ref<ComposeCycleFilter>('all')
const contactClassroomFilter = ref<ComposeClassroomFilter>('all')

const unread = ref(0)

const UNREAD_UPDATED_EVENT = MESSAGES_UNREAD_EVENT
const PORTAL_THREAD_OPEN_CLASS = 'portal-thread-open'
let unsubscribeRealtime: (() => void) | null = null

const canBroadcast = computed(() =>
  auth.user?.role === 'admin' || auth.user?.role === 'secretariat',
)

const isGlobalAdmin = computed(() =>
  auth.user?.role === 'admin' && (auth.user.admin_scope ?? 'global') === 'global',
)

const isScopedAdmin = computed(() =>
  auth.user?.role === 'admin' && (auth.user.admin_scope ?? 'global') !== 'global',
)

const announcementMode = computed<'sent' | 'received'>(() => {
  if (isGlobalAdmin.value || auth.user?.role === 'secretariat') return 'sent'
  return 'received'
})

const selectedComposeContact = computed(() =>
  contacts.value.find((contact) => contact.id === Number(composeForm.recipient_id)) ?? null,
)

const availableComposeCycles = computed(() => new Set(
  contacts.value.flatMap((contact) => contact.cycles ?? []),
))

const composeClassroomOptions = computed(() => {
  const classrooms = new Map<number, NonNullable<MessageContact['classrooms']>[number]>()

  for (const contact of contacts.value) {
    for (const classroom of contact.classrooms ?? []) {
      classrooms.set(classroom.id, classroom)
    }
  }

  return [...classrooms.values()].sort((a, b) => a.name.localeCompare(b.name, 'fr'))
})

const filteredComposeContacts = computed(() => {
  const query = contactSearch.value.trim().toLowerCase()

  return contacts.value.filter((contact) => {
    if (!matchesContactTypeFilter(contact.role, contactTypeFilter.value)) {
      return false
    }

    if (
      contactCycleFilter.value !== 'all'
      && !(contact.cycles ?? []).includes(contactCycleFilter.value)
    ) {
      return false
    }

    if (
      contactClassroomFilter.value !== 'all'
      && !(contact.classrooms ?? []).some((classroom) => classroom.id === contactClassroomFilter.value)
    ) {
      return false
    }

    if (!query) {
      return true
    }

    const searchable = [
      contact.name,
      contact.email,
      roleLabel(contact.role),
      ...(contact.cycles ?? []).map(cycleLabel),
      ...(contact.classrooms ?? []).map((classroom) => classroom.name),
    ].join(' ').toLowerCase()

    return searchable.includes(query)
  })
})

const composeBodyRemaining = computed(() => Math.max(0, 5000 - composeForm.body.length))

const composeCanSubmit = computed(() =>
  Boolean(composeForm.recipient_id && composeForm.subject.trim() && composeForm.body.trim() && !composing.value),
)

const announcementCount = computed(() =>
  messages.value.filter((message) => message.is_announcement).length,
)

const announcementMessages = computed(() =>
  messages.value.filter((message) =>
    message.is_announcement
    && (announcementMode.value === 'received' || message.sender_id === auth.user?.id),
  ),
)

const announcementGroups = computed<AnnouncementGroup[]>(() => {
  const groups = new Map<string, AnnouncementGroup>()

  for (const message of announcementMessages.value) {
    const key = message.broadcast_id || `message-${message.id}`
    const current = groups.get(key)

    if (!current) {
      groups.set(key, {
        key,
        message,
        recipientsCount: message.recipients_count ?? 1,
      })
      continue
    }

    current.recipientsCount += message.recipients_count ?? 1
    const currentTime = current.message.created_at
      ? new Date(current.message.created_at).getTime()
      : 0
    const messageTime = message.created_at ? new Date(message.created_at).getTime() : 0
    if (messageTime > currentTime) {
      current.message = message
    }
  }

  return [...groups.values()].sort((a, b) => {
    const first = a.message.created_at ? new Date(a.message.created_at).getTime() : 0
    const second = b.message.created_at ? new Date(b.message.created_at).getTime() : 0
    return second - first
  })
})

const announcementDiffusionCount = computed(() => announcementGroups.value.length)
const announcementRecipientCount = computed(() =>
  announcementGroups.value.reduce((total, group) => total + group.recipientsCount, 0),
)
const unreadAnnouncementCount = computed(() =>
  announcementMessages.value.filter((message) => isUnreadForMe(message)).length,
)
const selectedAnnouncementGroup = computed(() =>
  announcementGroups.value.find((group) => group.key === announcementSummaryKey.value) ?? null,
)
const canEditSelectedAnnouncement = computed(() =>
  canBroadcast.value
    && Boolean(selectedAnnouncementGroup.value?.message.broadcast_id)
    && selectedAnnouncementGroup.value?.message.sender_id === auth.user?.id,
)
const announcementPrimaryLabel = computed(() =>
  announcementMode.value === 'sent' ? 'Diffusions envoyées' : 'Annonces reçues',
)

const announcementPrimaryHelp = computed(() =>
  announcementMode.value === 'sent'
    ? 'Historique de communication'
    : 'Informations publiées par l’école',
)

const announcementSecondaryLabel = computed(() =>
  announcementMode.value === 'sent' ? 'Destinataires' : 'Non lues',
)

const announcementSecondaryValue = computed(() =>
  announcementMode.value === 'sent' ? announcementRecipientCount.value : unreadAnnouncementCount.value,
)

const announcementSecondaryHelp = computed(() =>
  announcementMode.value === 'sent'
    ? 'Total des destinataires touchés'
    : 'À consulter dans votre espace',
)

const announcementStatusLabel = computed(() =>
  announcementMode.value === 'sent' ? 'Traçabilité' : 'Consultation',
)

const announcementStatusValue = computed(() =>
  announcementMode.value === 'sent' ? 'Conservée' : 'Disponible',
)

const announcementStatusHelp = computed(() =>
  announcementMode.value === 'sent'
    ? 'Chaque annonce reste consultable dans la messagerie'
    : 'Chaque annonce peut être ouverte et relue',
)

function visibleConversationMessages(): Message[] {
  return messages.value.filter((message) => !message.is_announcement)
}

const conversationCount = computed(() => visibleConversationMessages().length)
const unreadConversationCount = computed(() =>
  visibleConversationMessages().filter((message) => hasUnreadForMe(message)).length,
)
const activeSectionUnreadCount = computed(() =>
  section.value === 'messages' ? unreadConversationCount.value : unreadAnnouncementCount.value,
)

const hasStaffReplyText = computed(() => quickReplyBody.value.trim().length > 0)

type OutgoingStatus = 'sending' | 'sent' | 'delivered'

const pendingOutgoingReply = computed(() => {
  if (!quickReplySending.value) return null
  const body = quickReplyBody.value.trim()
  if (!body) return null
  return { body, createdAt: new Date().toISOString() }
})

const filteredMessages = computed(() => {
  const query = searchQuery.value.trim().toLowerCase()
  const source = section.value === 'messages' ? visibleConversationMessages() : messages.value
  if (!query) return source

  return source.filter((msg) => {
    const haystack = [
      peerLabel(msg),
      peerRole(msg),
      msg.subject,
      msg.body,
      msg.is_announcement ? 'annonce' : '',
    ].join(' ').toLowerCase()

    return haystack.includes(query)
  })
})

const selectedPeer = computed(() => {
  if (selected.value) return otherParticipant(selected.value)
  return draftRecipient.value ?? undefined
})

const isDraftConversation = computed(() => Boolean(draftRecipient.value && !selected.value))

function syncPortalTopbarOverride(): void {
  if (!portalTopbar) return

  if (isPortalUser.value && section.value === 'announcements' && announcementSummaryKey.value) {
    portalTopbar.setOverride({
      onClose: closeAnnouncementSummary,
    })
    return
  }

  const peer = selectedPeer.value
  if (!isPortalUser.value || section.value !== 'messages' || !mobileShowThread.value || !peer) {
    portalTopbar.clearOverride()
    return
  }

  const subtitle = [
    roleLabel(peer.role) || 'Interlocuteur',
    selected.value?.subject,
  ].filter(Boolean).join(' · ')

  portalTopbar.setOverride({
    title: peer.name ?? 'Conversation',
    subtitle,
    avatarText: initialsFor(peer.name),
    onBack: closeMobileThread,
  })
}

const conversationMessages = computed(() => {
  if (!selected.value) return []
  return [selected.value, ...(selected.value.replies ?? [])].sort((a, b) => {
    const first = a.created_at ? new Date(a.created_at).getTime() : 0
    const second = b.created_at ? new Date(b.created_at).getTime() : 0
    return first - second
  })
})

const emptyCopy = computed(() => {
  if (unreadAnnouncementCount.value > 0) {
    return {
      title: 'Aucune conversation non lue',
      body: 'Le badge correspond à une annonce. Ouvrez l’onglet Annonces pour la consulter.',
    }
  }

  if (isPortalUser.value) {
    return {
      title: 'Aucun message',
      body: auth.hasRole('parent')
        ? 'Vos échanges avec l’école et les enseignants apparaîtront ici.'
        : 'Vos échanges avec l’école et vos enseignants apparaîtront ici.',
    }
  }

  return {
    title: 'Aucune conversation',
    body: 'Les échanges des familles, enseignants et élèves apparaîtront ici.',
  }
})

function closeMobileThread(): void {
  mobileShowThread.value = false
  selected.value = null
  draftRecipient.value = null
  draftSubject.value = ''
  quickReplyBody.value = ''
  quickReplyError.value = ''
}

function roleLabel(role?: string): string {
  const map: Record<string, string> = {
    admin: 'Admin',
    enseignant: 'Enseignant',
    parent: 'Parent',
    eleve: 'Élève',
    secretariat: 'Secrétariat',
  }
  return map[role ?? ''] ?? role ?? ''
}

function cycleLabel(cycle?: string): string {
  const map: Record<string, string> = {
    maternel: 'Maternel',
    primaire: 'Primaire',
    cteb: 'CTEB',
    secondaire: 'Secondaire',
  }
  return map[cycle ?? ''] ?? cycle ?? ''
}

function matchesContactTypeFilter(role: UserRole, filter: ComposeContactTypeFilter): boolean {
  if (filter === 'all') return true
  if (filter === 'administration') return role === 'admin' || role === 'secretariat'
  return role === filter
}

function initialsFor(name?: string): string {
  return (name ?? '')
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part.charAt(0).toUpperCase())
    .join('') || '?'
}

function otherParticipant(msg: Message): MessageContact | undefined {
  return msg.sender_id === auth.user?.id ? msg.recipient : msg.sender
}

function messagePeer(msg: Message): MessageContact | undefined {
  return otherParticipant(msg)
}

function peerLabel(msg: Message): string {
  return messagePeer(msg)?.name ?? 'Interlocuteur inconnu'
}

function peerRole(msg: Message): string {
  return roleLabel(messagePeer(msg)?.role)
}

function messagePreview(msg: Message): string {
  const text = (msg.body ?? '').replace(/\s+/g, ' ').trim()
  if (!text) return 'Aucun aperçu disponible.'
  return text.length > 96 ? `${text.slice(0, 96)}…` : text
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

function latestInThread(msg: Message): Message {
  const thread = [msg, ...(msg.replies ?? [])]
  return thread.sort((a, b) => {
    const first = a.created_at ? new Date(a.created_at).getTime() : 0
    const second = b.created_at ? new Date(b.created_at).getTime() : 0
    return second - first
  })[0] ?? msg
}

function listPreviewText(msg: Message): string {
  return messagePreview(latestInThread(msg))
}

function listPreviewTime(msg: Message): string {
  const latest = latestInThread(msg)
  return formatListTime(latest.created_at ?? msg.created_at)
}

function outgoingStatus(message: Message): OutgoingStatus {
  if (message.is_read || message.read_at) return 'delivered'
  return 'sent'
}

function listPreviewStatus(msg: Message): OutgoingStatus | null {
  const latest = latestInThread(msg)
  if (!isMine(latest)) return null
  return outgoingStatus(latest)
}

function statusLabel(status: OutgoingStatus): string {
  if (status === 'sending') return 'Envoi en cours'
  if (status === 'delivered') return 'Reçu'
  return 'Envoyé'
}

function peerId(msg: Message): number {
  return messagePeer(msg)?.id ?? msg.id
}

function isActiveConversation(msg: Message): boolean {
  if (!selected.value) return false
  return threadRootId(selected.value) === threadRootId(msg)
}

function resizeStaffReplyField(): void {
  const el = staffReplyRef.value
  if (!el) return
  el.style.height = 'auto'
  el.style.height = `${Math.min(el.scrollHeight, 120)}px`
}

function onStaffReplyInput(event: Event): void {
  quickReplyBody.value = (event.target as HTMLTextAreaElement).value
  resizeStaffReplyField()
}

function onStaffReplyKeydown(event: KeyboardEvent): void {
  if (event.key === 'Enter' && !event.shiftKey) {
    event.preventDefault()
    if (hasStaffReplyText.value && !quickReplySending.value) {
      void sendQuickReply()
    }
  }
}

function isMine(msg: Message): boolean {
  return msg.sender_id === auth.user?.id
}

function isUnreadForMe(msg: Message): boolean {
  return msg.recipient_id === auth.user?.id && !msg.is_read
}

function hasUnreadForMe(msg: Message): boolean {
  return isUnreadForMe(msg) || (msg.replies ?? []).some((reply) => isUnreadForMe(reply))
}

function threadRootId(msg: Message): number {
  return msg.parent_message_id ?? msg.id
}

function quickReplyRecipientId(): number | null {
  if (!selected.value) return draftRecipient.value?.id ?? null
  return selected.value.sender_id === auth.user?.id
    ? selected.value.recipient_id
    : selected.value.sender_id
}

function replyRecipientId(message: Message): number {
  return message.sender_id === auth.user?.id ? message.recipient_id : message.sender_id
}

function sortMessagesByRecent(items: Message[]): Message[] {
  return [...items].sort((a, b) => {
    const first = a.created_at ? new Date(a.created_at).getTime() : 0
    const second = b.created_at ? new Date(b.created_at).getTime() : 0
    return second - first
  })
}

function uniqueMessages(items: Message[]): Message[] {
  return Array.from(new Map(items.map((message) => [message.id, message])).values())
}

function replaceLoadedMessage(updated: Message): void {
  const index = messages.value.findIndex((message) => message.id === updated.id)
  if (index === -1) {
    messages.value = sortMessagesByRecent(uniqueMessages([updated, ...messages.value]))
    return
  }

  messages.value.splice(index, 1, updated)
}

async function loadMessages(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    if (section.value === 'messages') {
      const [inbox, sent] = await Promise.all([
        api<Paginated<Message>>('/api/v1/messages/inbox'),
        api<Paginated<Message>>('/api/v1/messages/sent'),
      ])
      messages.value = sortMessagesByRecent(uniqueMessages([...inbox.data, ...sent.data]))
      return
    }

    if (isScopedAdmin.value) {
      const [inbox, sent] = await Promise.all([
        api<Paginated<Message>>('/api/v1/messages/inbox'),
        api<Paginated<Message>>('/api/v1/messages/sent', { query: { announcements: true } }),
      ])
      messages.value = sortMessagesByRecent(uniqueMessages([
        ...inbox.data.filter((message) => message.is_announcement),
        ...sent.data.filter((message) => message.is_announcement),
      ]))
      return
    }

    const res = await api<Paginated<Message>>(
      announcementMode.value === 'sent'
        ? '/api/v1/messages/sent'
        : '/api/v1/messages/inbox',
      announcementMode.value === 'sent'
        ? { query: { announcements: true } }
        : {},
    )
    messages.value = sortMessagesByRecent(res.data.filter((message) => message.is_announcement))
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Erreur de chargement.'
  } finally {
    loading.value = false
  }
}

async function loadUnread(): Promise<void> {
  try {
    const res = await api<{ unread: number }>('/api/v1/messages/unread-count')
    syncUnreadCount(res.unread)
  } catch {
    /* silencieux */
  }
}

function syncUnreadCount(count: number): void {
  unread.value = count
  window.dispatchEvent(new CustomEvent(UNREAD_UPDATED_EVENT, { detail: count }))
}

function syncPortalThreadChrome(): void {
  const threadOpen = isPortalUser.value && section.value === 'messages' && mobileShowThread.value
  const announcementOpen = isPortalUser.value
    && section.value === 'announcements'
    && Boolean(announcementSummaryKey.value)
  document.body.classList.toggle(PORTAL_THREAD_OPEN_CLASS, threadOpen || announcementOpen)
}

function isSelectedThreadAffected(message?: Message): boolean {
  if (!selected.value || !message) return false
  return message.id === selected.value.id
    || message.parent_message_id === selected.value.id
    || selected.value.parent_message_id === message.id
}

function onRealtimeMessage(event: MessageRealtimeEvent): void {
  unread.value = event.unread_count

  if (event.section === section.value) {
    void loadMessages()
  }

  if (isSelectedThreadAffected(event.message) && selected.value) {
    void openMessage(selected.value)
  }
}

function syncRealtimeSubscription(): void {
  unsubscribeRealtime?.()
  unsubscribeRealtime = null

  if (auth.user?.id) {
    unsubscribeRealtime = subscribeToMessageUpdates(auth.user.id, onRealtimeMessage)
  }
}

async function openMessage(msg: Message): Promise<void> {
  quickReplyBody.value = ''
  quickReplyError.value = ''
  loadingMessage.value = true
  try {
    const hadUnread = hasUnreadForMe(msg)
    const res = await api<{ data: Message }>(`/api/v1/messages/${msg.id}`)
    selected.value = res.data
    draftRecipient.value = null
    draftSubject.value = ''
    if (hadUnread) {
      msg.is_read = true
      msg.replies?.forEach((reply) => {
        if (isUnreadForMe(reply)) reply.is_read = true
      })
    }
    await Promise.all([loadUnread(), loadMessages()])
    mobileShowThread.value = true
    if (!isPortalUser.value) {
      await focusConversationPanel()
    }
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : "Impossible d'ouvrir le message."
  } finally {
    loadingMessage.value = false
  }
}

async function openAnnouncementSummary(group: AnnouncementGroup): Promise<void> {
  announcementSummaryKey.value = group.key
  announcementEditing.value = false
  announcementError.value = ''
  announcementForm.subject = group.message.subject
  announcementForm.body = group.message.body

  if (announcementMode.value !== 'received' || !isUnreadForMe(group.message)) return

  try {
    const res = await api<{ data: Message }>(`/api/v1/messages/${group.message.id}`)
    replaceLoadedMessage(res.data)
    announcementForm.subject = res.data.subject
    announcementForm.body = res.data.body
    await loadUnread()
  } catch (e) {
    announcementError.value = e instanceof ApiError ? e.message : "Impossible de marquer l'annonce comme lue."
  }
}

function closeAnnouncementSummary(): void {
  announcementSummaryKey.value = null
  announcementEditing.value = false
  announcementError.value = ''
}

function startAnnouncementEdit(): void {
  const group = selectedAnnouncementGroup.value
  if (!group) return
  announcementForm.subject = group.message.subject
  announcementForm.body = group.message.body
  announcementError.value = ''
  announcementEditing.value = true
}

async function saveAnnouncementEdit(): Promise<void> {
  const group = selectedAnnouncementGroup.value
  const broadcastId = group?.message.broadcast_id

  if (!group || !broadcastId) {
    announcementError.value = 'Cette annonce ne peut pas être modifiée.'
    return
  }

  if (!announcementForm.subject.trim() || !announcementForm.body.trim()) {
    announcementError.value = 'Objet et message sont obligatoires.'
    return
  }

  announcementSaving.value = true
  announcementError.value = ''
  try {
    await api(`/api/v1/messages/broadcast/${broadcastId}`, {
      method: 'PATCH',
      body: {
        subject: announcementForm.subject,
        body: announcementForm.body,
      },
    })
    await loadMessages()
    announcementSummaryKey.value = broadcastId
    announcementEditing.value = false
  } catch (e) {
    announcementError.value = e instanceof ApiError ? e.message : 'Modification impossible.'
  } finally {
    announcementSaving.value = false
  }
}

async function switchCommunicationSection(next: CommunicationSection): Promise<void> {
  section.value = next
  closeAnnouncementSummary()
  selected.value = null
  draftRecipient.value = null
  draftSubject.value = ''
  mobileShowThread.value = false
  searchQuery.value = ''
  quickReplyBody.value = ''
  quickReplyError.value = ''
  await loadMessages()
}

async function loadContacts(force = false): Promise<void> {
  if (!force && contacts.value.length > 0) return
  contactsLoading.value = true
  contactsError.value = ''
  try {
    const res = await api<{ data: MessageContact[]; audiences?: unknown[] }>('/api/v1/messages/contacts')
    contacts.value = res.data
  } catch (e) {
    contactsError.value = e instanceof ApiError ? e.message : 'Contacts indisponibles.'
  } finally {
    contactsLoading.value = false
  }
}

function openCompose(replyTo?: Message): void {
  composeForm.recipient_id = replyTo ? replyRecipientId(replyTo) : ''
  composeForm.subject = replyTo ? `Re: ${replyTo.subject}` : ''
  composeForm.body = ''
  composeForm.parent_message_id = replyTo?.id ?? null
  Object.keys(composeErrors).forEach((k) => delete composeErrors[k])
  composeError.value = ''
  contactSearch.value = ''
  contactTypeFilter.value = 'all'
  contactCycleFilter.value = 'all'
  contactClassroomFilter.value = 'all'
  if (isPortalUser.value) {
    mobileShowThread.value = false
  }
  composeOpen.value = true
  void loadContacts(true)
}

function clearComposeRecipient(): void {
  composeForm.recipient_id = ''
}

function openBroadcast(): void {
  broadcastOpen.value = true
  void loadContacts()
}

async function resolveContactForRecipient(recipientId: number): Promise<MessageContact | null> {
  const fromContacts = contacts.value.find((item) => item.id === recipientId)
  if (fromContacts) return fromContacts

  await loadContacts(true)
  const loaded = contacts.value.find((item) => item.id === recipientId)
  if (loaded) return loaded

  for (const message of visibleConversationMessages()) {
    const peer = messagePeer(message)
    if (peer?.id === recipientId) return peer
  }

  return null
}

async function openConversationWithRecipient(recipientId: number, subject?: string): Promise<void> {
  section.value = 'messages'

  if (messages.value.length === 0 && !loading.value) {
    await loadMessages()
  }

  const contact =
    (await resolveContactForRecipient(recipientId)) ??
    ({
      id: recipientId,
      name: 'Destinataire',
      email: '',
      role: 'parent',
    } satisfies MessageContact)

  await openDirectConversation(contact, subject)
}

async function openComposeFromStudent(
  studentId: string,
  target: 'parent' | 'student' | 'auto' = 'auto',
): Promise<void> {
  try {
    const res = await api<ApiResource<Student>>(`/api/v1/students/${studentId}`)
    const student = res.data
    const subject = `Suivi de ${student.full_name}`
    const portalActive =
      student.student_portal_status === 'active' && student.user_id != null

    const parentUser = student.parents?.find((p) => p.user?.id)?.user

    if (target === 'parent' || (target === 'auto' && !portalActive)) {
      if (parentUser?.id) {
        await openConversationWithRecipient(parentUser.id, subject)
        return
      }
    }

    if ((target === 'student' || target === 'auto') && portalActive && student.user_id) {
      await openConversationWithRecipient(student.user_id, subject)
      return
    }

    openCompose()
    composeForm.subject = subject
    if (parentUser?.id) {
      composeForm.recipient_id = parentUser.id
    }
  } catch {
    openCompose()
  }
}

function hasComposeRouteIntent(): boolean {
  if (composeIntentStore.hasPending()) {
    return true
  }

  if (typeof route.query.recipient_id === 'string' && /^\d+$/.test(route.query.recipient_id)) {
    return true
  }

  if (route.query.compose === '1' && typeof route.query.student_id === 'string' && /^\d+$/.test(route.query.student_id)) {
    return true
  }

  return route.query.compose === '1'
}

function conversationPeerId(message: Message): number | null {
  const peer = messagePeer(message)
  if (peer?.id != null) {
    return peer.id
  }

  return message.sender_id === auth.user?.id ? message.recipient_id : message.sender_id
}

function composeRouteSignature(): string {
  return JSON.stringify({
    recipient_id: route.query.recipient_id ?? null,
    subject: route.query.subject ?? null,
    compose: route.query.compose ?? null,
    student_id: route.query.student_id ?? null,
    target: route.query.target ?? null,
  })
}

async function clearComposeRouteQuery(): Promise<void> {
  const { recipient_id, subject, compose, student_id, target, ...rest } = route.query
  if (!recipient_id && !subject && compose !== '1' && !student_id && !target) {
    return
  }

  await router.replace({
    name: route.name ?? 'messages',
    query: rest,
  })
}

async function handleComposeRouteQuery(): Promise<void> {
  const routeSignature = composeRouteSignature()
  const intent = composeIntentStore.consume()

  if (!intent) {
    const hasRouteIntent =
      (typeof route.query.recipient_id === 'string' && /^\d+$/.test(route.query.recipient_id))
      || (route.query.compose === '1' && typeof route.query.student_id === 'string' && /^\d+$/.test(route.query.student_id))
      || route.query.compose === '1'

    if (!hasRouteIntent) {
      return
    }

    if (handledComposeRouteSignature.value === routeSignature) {
      return
    }
  }

  if (intent?.kind === 'student') {
    await openComposeFromStudent(String(intent.studentId), intent.target)
    handledComposeRouteSignature.value = routeSignature
    await clearComposeRouteQuery()
    return
  }

  if (intent?.kind === 'recipient') {
    await openConversationWithRecipient(intent.recipientId, intent.subject)
    handledComposeRouteSignature.value = routeSignature
    await clearComposeRouteQuery()
    return
  }

  const recipientId = typeof route.query.recipient_id === 'string' ? route.query.recipient_id : ''
  const subject = typeof route.query.subject === 'string' ? route.query.subject : undefined

  if (recipientId && /^\d+$/.test(recipientId)) {
    await openConversationWithRecipient(Number(recipientId), subject)
    handledComposeRouteSignature.value = routeSignature
    await clearComposeRouteQuery()
    return
  }

  if (route.query.compose === '1') {
    const studentId = typeof route.query.student_id === 'string' ? route.query.student_id : ''
    const targetRaw = typeof route.query.target === 'string' ? route.query.target : 'auto'
    const target: MessageComposeStudentTarget =
      targetRaw === 'parent' || targetRaw === 'student' ? targetRaw : 'auto'

    if (studentId && /^\d+$/.test(studentId)) {
      await openComposeFromStudent(studentId, target)
      handledComposeRouteSignature.value = routeSignature
      await clearComposeRouteQuery()
      return
    }

    openCompose()
    handledComposeRouteSignature.value = routeSignature
    await clearComposeRouteQuery()
  }
}

async function applyPendingConversationOpen(): Promise<void> {
  if (!composeIntentStore.hasPending() && !hasComposeRouteIntent()) {
    return
  }

  if (composeNavigationInFlight) {
    await composeNavigationInFlight
    return
  }

  composeNavigationInFlight = handleComposeRouteQuery().finally(() => {
    composeNavigationInFlight = null
  })

  await composeNavigationInFlight
}

async function focusConversationPanel(): Promise<void> {
  await nextTick()
  document.querySelector('.messages-page .chat-panel')?.scrollIntoView({ behavior: 'smooth', block: 'nearest' })
}

function selectComposeRecipient(contact: MessageContact): void {
  composeForm.recipient_id = contact.id
  delete composeErrors.recipient_id
  composeError.value = ''

  if (!composeForm.parent_message_id) {
    void openDirectConversation(contact)
  }
}

async function openDirectConversation(contact: MessageContact, subject?: string): Promise<void> {
  composeOpen.value = false
  composeForm.recipient_id = ''
  composeForm.subject = ''
  composeForm.body = ''
  quickReplyBody.value = ''
  quickReplyError.value = ''
  if (section.value !== 'messages') section.value = 'messages'

  const existing = visibleConversationMessages().find(
    (message) => conversationPeerId(message) === contact.id,
  )

  if (existing) {
    try {
      await openMessage(existing)
      draftRecipient.value = null
      draftSubject.value = ''
      return
    } catch {
      /* repasser en brouillon si l’ouverture du fil existant échoue */
    }
  }

  selected.value = null
  draftRecipient.value = contact
  draftSubject.value = subject?.trim() ?? ''
  mobileShowThread.value = true
  if (!isPortalUser.value) {
    await focusConversationPanel()
  }
}

async function sendMessage(): Promise<void> {
  const body = composeForm.body.trim()
  let subject = composeForm.subject.trim()
  if (!subject) {
    if (composeForm.parent_message_id) {
      subject = composeForm.subject.trim() || 'Re: Message'
    } else if (selectedComposeContact.value) {
      subject = `Message — ${selectedComposeContact.value.name}`
    } else {
      subject = 'Message'
    }
  }

  if (!composeForm.recipient_id || !subject || !body) {
    composeError.value = 'Choisissez un destinataire et rédigez votre message.'
    return
  }
  composing.value = true
  composeError.value = ''
  Object.keys(composeErrors).forEach((k) => delete composeErrors[k])
  try {
    await api('/api/v1/messages', {
      method: 'POST',
      body: {
        recipient_id: Number(composeForm.recipient_id),
        subject,
        body,
        parent_message_id: composeForm.parent_message_id,
      },
    })
    toast.success('Message envoyé.')
    composeOpen.value = false
    if (section.value !== 'messages') section.value = 'messages'
    await loadMessages()
  } catch (e) {
    if (e instanceof ApiError) {
      composeError.value = e.message
      if (e.errors) Object.assign(composeErrors, e.errors)
    } else {
      composeError.value = 'Envoi impossible.'
    }
  } finally {
    composing.value = false
  }
}

async function sendQuickReply(): Promise<void> {
  const body = quickReplyBody.value.trim()
  const recipientId = quickReplyRecipientId()
  const current = selected.value
  const draft = draftRecipient.value

  if ((!current && !draft) || !recipientId) {
    quickReplyError.value = 'Sélectionnez une conversation avant de répondre.'
    return
  }

  if (!body) {
    quickReplyError.value = 'Le message ne peut pas être vide.'
    return
  }

  quickReplySending.value = true
  quickReplyError.value = ''
  try {
    const subject = current
      ? current.subject.startsWith('Re:') ? current.subject : `Re: ${current.subject}`
      : draftSubject.value || `Message — ${draft?.name ?? 'Conversation'}`

    const res = await api<{ data: Message }>('/api/v1/messages', {
      method: 'POST',
      body: {
        recipient_id: recipientId,
        subject,
        body,
        parent_message_id: current?.id ?? null,
      },
    })
    quickReplyBody.value = ''
    if (current) {
      await Promise.all([openMessage(current), loadMessages()])
    } else {
      draftRecipient.value = null
      draftSubject.value = ''
      await loadMessages()
      await openMessage(res.data)
    }
  } catch (e) {
    quickReplyError.value = e instanceof ApiError ? e.message : 'Envoi impossible.'
  } finally {
    quickReplySending.value = false
  }
}

async function onBroadcastSent(): Promise<void> {
  broadcastOpen.value = false
  if (section.value === 'announcements') {
    await loadMessages()
  }
}

async function loadInitialMessages(): Promise<void> {
  await Promise.all([loadMessages(), loadUnread()])

  if (
    section.value === 'messages'
    && !draftRecipient.value
    && !selected.value
    && !hasComposeRouteIntent()
    && conversationCount.value === 0
    && unreadAnnouncementCount.value > 0
  ) {
    section.value = 'announcements'
    await loadMessages()
  }
}

onMounted(async () => {
  syncRealtimeSubscription()
  await loadInitialMessages()
  await applyPendingConversationOpen()
})

onUnmounted(() => {
  unsubscribeRealtime?.()
  document.body.classList.remove(PORTAL_THREAD_OPEN_CLASS)
  portalTopbar?.clearOverride()
})

watch(
  [isPortalUser, section, mobileShowThread, selectedPeer, selected, announcementSummaryKey],
  () => {
    syncPortalThreadChrome()
    syncPortalTopbarOverride()
  },
  { immediate: true },
)

watch(
  () => auth.user?.id,
  () => syncRealtimeSubscription(),
)

watch(
  () => [
    composeIntentStore.pending,
    route.query.recipient_id,
    route.query.subject,
    route.query.compose,
    route.query.student_id,
    route.query.target,
  ],
  async () => {
    if (!hasComposeRouteIntent()) return
    await applyPendingConversationOpen()
  },
)

watch(quickReplyBody, () => {
  void nextTick(resizeStaffReplyField)
})

watch([selected, draftRecipient], () => {
  void nextTick(resizeStaffReplyField)
})
</script>

<template>
  <section
    class="messages-page"
    :class="{
      'is-portal': isPortalUser,
      'is-staff': !isPortalUser,
      'portal-mobile': isPortalUser,
      'messages-page--fixed': !isPortalUser,
      'show-thread': isPortalUser && mobileShowThread,
      'show-announcement': isPortalUser && section === 'announcements' && Boolean(announcementSummaryKey),
      'section-messages': section === 'messages',
    }"
  >
    <header v-if="!isPortalUser" class="messages-hero communication-hero">
      <div class="messages-hero-copy">
        <p class="eyebrow">Communication</p>
        <h1>
          Messagerie & annonces
          <span v-if="activeSectionUnreadCount > 0" class="badge-count">{{ activeSectionUnreadCount }}</span>
        </h1>
        <p>
          Un espace centralisé pour échanger avec la communauté scolaire et diffuser les
          informations importantes avec une interface claire et professionnelle.
        </p>
      </div>

      <div class="hero-panel">
        <div v-if="section === 'messages'" class="hero-stats">
          <span><strong>{{ unreadConversationCount }}</strong> non lus</span>
          <span><strong>{{ conversationCount }}</strong> conversations</span>
          <span><strong>{{ announcementCount }}</strong> annonces</span>
        </div>
        <div v-else class="hero-stats">
          <span><strong>{{ announcementDiffusionCount }}</strong> {{ announcementMode === 'sent' ? 'diffusions' : 'annonces' }}</span>
          <span><strong>{{ announcementSecondaryValue }}</strong> {{ announcementSecondaryLabel.toLowerCase() }}</span>
          <span><strong>{{ announcementCount }}</strong> messages</span>
        </div>
        <div v-if="section === 'messages'" class="hero-actions">
          <button v-if="canBroadcast" type="button" class="btn-secondary" @click="openBroadcast">
            Nouvelle annonce
          </button>
          <button type="button" class="btn-primary" @click="openCompose()">Nouveau message</button>
        </div>
        <div v-else class="hero-actions">
          <button v-if="canBroadcast" type="button" class="btn-primary" @click="openBroadcast">
            Créer une annonce
          </button>
        </div>
      </div>
    </header>

    <nav
      v-if="isPortalUser && !announcementSummaryKey"
      class="msg-portal-tabs"
      role="tablist"
      aria-label="Communication"
    >
      <button
        type="button"
        role="tab"
        :aria-selected="section === 'messages'"
        :class="{ active: section === 'messages' }"
        @click="switchCommunicationSection('messages')"
      >
        Discussions
        <span v-if="unreadConversationCount > 0">{{ unreadConversationCount }}</span>
      </button>
      <button
        type="button"
        role="tab"
        :aria-selected="section === 'announcements'"
        :class="{ active: section === 'announcements' }"
        @click="switchCommunicationSection('announcements')"
      >
        Annonces
        <span v-if="unreadAnnouncementCount > 0">{{ unreadAnnouncementCount }}</span>
      </button>
    </nav>

    <nav
      v-else-if="!isPortalUser && !announcementSummaryKey"
      class="communication-tabs"
      role="tablist"
      aria-label="Communication"
    >
      <button
        type="button"
        role="tab"
        :aria-selected="section === 'messages'"
        :class="{ active: section === 'messages' }"
        @click="switchCommunicationSection('messages')"
      >
        Messagerie
        <span v-if="unreadConversationCount > 0">{{ unreadConversationCount }}</span>
      </button>
      <button
        type="button"
        role="tab"
        :aria-selected="section === 'announcements'"
        :class="{ active: section === 'announcements' }"
        @click="switchCommunicationSection('announcements')"
      >
        Annonces
        <span v-if="unreadAnnouncementCount > 0">{{ unreadAnnouncementCount }}</span>
      </button>
    </nav>

    <p v-if="error" class="alert alert-error">{{ error }}</p>

    <PortalMessagesApp
      v-if="isPortalUser && section === 'messages'"
      class="msg-portal-app"
      :search-query="searchQuery"
      :loading="loading"
      :loading-message="loadingMessage"
      :messages="filteredMessages"
      :conversation-count="conversationCount"
      :selected="selected"
      :selected-peer="selectedPeer ?? null"
      :conversation-messages="conversationMessages"
      :quick-reply-body="quickReplyBody"
      :quick-reply-error="quickReplyError"
      :quick-reply-sending="quickReplySending"
      :pending-outgoing="pendingOutgoingReply"
      :show-thread="mobileShowThread"
      :is-draft="isDraftConversation"
      :draft-subject="draftSubject"
      :empty-title="emptyCopy.title"
      :empty-body="emptyCopy.body"
      @update:search-query="searchQuery = $event"
      @update:quick-reply-body="quickReplyBody = $event"
      @open-compose="openCompose()"
      @open-message="openMessage"
      @refresh="loadMessages"
      @send-reply="sendQuickReply"
    />

    <div v-else-if="section === 'messages'" class="chat-shell">
      <aside class="chat-sidebar" aria-label="Conversations">
        <header class="chat-sidebar-header">
          <div class="staff-sidebar-head">
            <h2>
              Conversations
              <span v-if="unreadConversationCount > 0" class="badge-count small">{{ unreadConversationCount }}</span>
            </h2>
            <p>{{ filteredMessages.length }} sur {{ conversationCount }}</p>
          </div>
          <button
            v-if="selected || draftRecipient"
            type="button"
            class="staff-sidebar-new"
            aria-label="Nouveau message"
            @click="openCompose()"
          >
            <Plus :size="20" aria-hidden="true" />
          </button>
        </header>

        <label class="staff-search">
          <Search :size="18" aria-hidden="true" />
          <input
            v-model="searchQuery"
            type="search"
            placeholder="Rechercher…"
            aria-label="Rechercher une conversation"
            autocomplete="off"
          >
        </label>

        <div v-if="loading && filteredMessages.length === 0" class="msg-empty staff-list-state" role="status">
          <span class="msg-spinner" aria-hidden="true" />
          <span>Chargement des conversations…</span>
        </div>
        <div v-else-if="conversationCount === 0" class="msg-empty staff-list-state">
          <span class="msg-empty-icon" aria-hidden="true">
            <MessageSquare :size="24" />
          </span>
          <strong>{{ emptyCopy.title }}</strong>
          <p>{{ emptyCopy.body }}</p>
          <button type="button" class="btn-primary msg-empty-cta" @click="openCompose()">
            Écrire un message
          </button>
        </div>
        <div v-else-if="filteredMessages.length === 0" class="msg-empty staff-list-state">
          <span class="msg-empty-icon" aria-hidden="true">
            <Search :size="22" />
          </span>
          <strong>Aucun résultat</strong>
          <p>Essayez un autre nom, rôle ou mot du message.</p>
        </div>
        <ul v-else class="msg-threads staff-threads" role="list">
          <li v-for="msg in filteredMessages" :key="msg.id">
            <button
              type="button"
              class="msg-thread-row"
              :class="{
                'is-unread': hasUnreadForMe(msg),
                'is-active': isActiveConversation(msg),
              }"
              :aria-label="`${hasUnreadForMe(msg) ? 'Non lu, ' : ''}Conversation avec ${peerLabel(msg)}`"
              @click="openMessage(msg)"
            >
              <span
                class="msg-avatar"
                :style="{ background: childColor(peerId(msg)) }"
                aria-hidden="true"
              >{{ initialsFor(peerLabel(msg)) }}</span>
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
                  <span v-if="hasUnreadForMe(msg)" class="msg-unread-pill" aria-hidden="true" />
                </span>
              </span>
            </button>
          </li>
        </ul>
        <footer class="sidebar-footer">
          <button type="button" class="staff-refresh" :disabled="loading" @click="loadMessages">
            <RefreshCw :size="16" aria-hidden="true" />
            Actualiser
          </button>
        </footer>
      </aside>

      <section class="chat-panel" aria-label="Fil de conversation">
        <div v-if="loadingMessage" class="reader-state">
          <span class="loader-dot" />
          Ouverture du message…
        </div>

        <template v-else-if="draftRecipient">
          <header class="chat-header draft-chat-header">
            <div>
              <p class="eyebrow">Nouvelle conversation</p>
              <h2>{{ selectedPeer?.name }}</h2>
              <p class="draft-chat-meta">
                {{ roleLabel(selectedPeer?.role) || 'Interlocuteur' }}
                <span v-if="draftSubject"> · {{ draftSubject }}</span>
              </p>
            </div>
          </header>

          <div class="chat-thread draft-thread">
            <p class="draft-thread-hint">
              Aucun échange précédent. Votre message ouvrira la conversation avec
              <strong>{{ selectedPeer?.name }}</strong>.
            </p>
          </div>

          <p v-if="quickReplyError" class="msg-reply-error" role="alert">{{ quickReplyError }}</p>
          <form class="msg-reply staff-msg-reply" @submit.prevent="sendQuickReply">
            <div class="msg-reply-field">
              <label class="sr-only" for="staff-msg-reply-draft">Votre message</label>
              <textarea
                id="staff-msg-reply-draft"
                ref="staffReplyRef"
                :value="quickReplyBody"
                rows="1"
                maxlength="5000"
                placeholder="Message"
                :disabled="quickReplySending"
                @input="onStaffReplyInput"
                @keydown="onStaffReplyKeydown"
              />
            </div>
            <Transition name="msg-send-pop">
              <button
                v-if="hasStaffReplyText"
                type="submit"
                class="msg-send"
                :disabled="quickReplySending"
                :aria-label="quickReplySending ? 'Envoi en cours' : 'Envoyer le message'"
              >
                <Send :size="20" aria-hidden="true" />
              </button>
            </Transition>
          </form>
        </template>

        <template v-else-if="selected">
          <header class="chat-header">
            <div class="chat-contact">
              <span
                class="msg-avatar large"
                :style="selected ? { background: childColor(peerId(selected)) } : undefined"
                aria-hidden="true"
              >{{ initialsFor(selectedPeer?.name) }}</span>
              <div class="chat-contact-copy">
                <p class="eyebrow">Conversation avec</p>
                <h2>{{ selectedPeer?.name ?? 'Interlocuteur' }}</h2>
                <p class="chat-contact-meta">
                  {{ roleLabel(selectedPeer?.role) || 'Interlocuteur' }}
                  <span> · {{ selected.subject }}</span>
                </p>
              </div>
            </div>
          </header>

          <div class="msg-bubbles staff-bubbles" role="log" aria-live="polite" aria-relevant="additions">
            <div v-if="selected.is_announcement" class="system-chip">
              Annonce envoyée à plusieurs destinataires
            </div>

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
              v-if="pendingOutgoingReply"
              class="msg-bubble-line is-mine is-pending"
              aria-live="polite"
            >
              <div class="msg-bubble">
                <p class="msg-bubble-body">
                  <span class="msg-bubble-text">{{ pendingOutgoingReply.body }}</span>
                  <span class="msg-bubble-meta">
                    <time :datetime="pendingOutgoingReply.createdAt">{{ formatBubbleTime(pendingOutgoingReply.createdAt) }}</time>
                    <span class="msg-bubble-status" aria-label="Envoi en cours">
                      <Clock :size="14" class="msg-status-clock" aria-hidden="true" />
                    </span>
                  </span>
                </p>
              </div>
            </article>
          </div>

          <p v-if="quickReplyError" class="msg-reply-error" role="alert">{{ quickReplyError }}</p>
          <form class="msg-reply staff-msg-reply" @submit.prevent="sendQuickReply">
            <div class="msg-reply-field">
              <label class="sr-only" for="staff-msg-reply">Votre message</label>
              <textarea
                id="staff-msg-reply"
                ref="staffReplyRef"
                :value="quickReplyBody"
                rows="1"
                maxlength="5000"
                placeholder="Message"
                :disabled="quickReplySending"
                @input="onStaffReplyInput"
                @keydown="onStaffReplyKeydown"
              />
            </div>
            <Transition name="msg-send-pop">
              <button
                v-if="hasStaffReplyText"
                type="submit"
                class="msg-send"
                :disabled="quickReplySending"
                :aria-label="quickReplySending ? 'Envoi en cours' : 'Envoyer le message'"
              >
                <Send :size="20" aria-hidden="true" />
              </button>
            </Transition>
          </form>
        </template>

        <div v-else class="chat-empty">
          <div class="reader-empty-card">
            <span class="reader-empty-icon">M</span>
            <h2>Communication scolaire</h2>
            <p>Sélectionnez une conversation pour afficher le fil, ou démarrez un nouvel échange.</p>
            <button type="button" class="btn-primary" @click="openCompose()">Écrire un message</button>
          </div>
        </div>
      </section>
    </div>

    <section
      v-if="section === 'announcements'"
      class="announcements-panel"
      :class="{
        'is-portal': isPortalUser,
        'is-staff': !isPortalUser,
        'is-detail': Boolean(announcementSummaryKey),
      }"
      aria-label="Annonces"
    >
      <Transition name="announce-rise">
      <AnnouncementDetailView
        v-if="announcementSummaryKey"
        :group="selectedAnnouncementGroup"
        :mode="announcementMode"
        :error="announcementError"
        :editing="announcementEditing"
        :saving="announcementSaving"
        v-model:form-subject="announcementForm.subject"
        v-model:form-body="announcementForm.body"
        :can-edit="canEditSelectedAnnouncement"
        :is-portal-user="isPortalUser"
        @back="closeAnnouncementSummary"
        @start-edit="startAnnouncementEdit"
        @cancel-edit="announcementEditing = false"
        @save-edit="saveAnnouncementEdit"
      />
      </Transition>

      <template v-if="!announcementSummaryKey">
      <div v-if="isPortalUser" class="portal-msg-announce-kpis portal-dash-animate portal-dash-animate--delay-1">
        <article class="portal-msg-announce-kpi">
          <span>{{ announcementPrimaryLabel }}</span>
          <strong>{{ announcementDiffusionCount }}</strong>
          <small>{{ announcementPrimaryHelp }}</small>
        </article>
        <article class="portal-msg-announce-kpi">
          <span>{{ announcementSecondaryLabel }}</span>
          <strong>{{ announcementSecondaryValue }}</strong>
          <small>{{ announcementSecondaryHelp }}</small>
        </article>
      </div>

      <div v-if="!isPortalUser" class="staff-announce-kpis" role="list">
        <article class="staff-announce-kpi" role="listitem">
          <span>{{ announcementPrimaryLabel }}</span>
          <strong>{{ announcementDiffusionCount }}</strong>
          <small>{{ announcementPrimaryHelp }}</small>
        </article>
        <article class="staff-announce-kpi" role="listitem">
          <span>{{ announcementSecondaryLabel }}</span>
          <strong>{{ announcementSecondaryValue }}</strong>
          <small>{{ announcementSecondaryHelp }}</small>
        </article>
        <article class="staff-announce-kpi" role="listitem">
          <span>{{ announcementStatusLabel }}</span>
          <strong>{{ announcementStatusValue }}</strong>
          <small>{{ announcementStatusHelp }}</small>
        </article>
      </div>

      <div v-if="loading" class="announcement-state staff-announce-state" role="status">
        <span class="msg-spinner" aria-hidden="true" />
        <span>Chargement des annonces…</span>
      </div>
      <div v-else-if="announcementGroups.length === 0" class="announcement-empty staff-announce-empty">
        <span class="msg-empty-icon" aria-hidden="true">
          <Megaphone :size="24" />
        </span>
        <h2>{{ announcementMode === 'sent' ? 'Aucune diffusion envoyée' : 'Aucune annonce reçue' }}</h2>
        <p>
          {{
            announcementMode === 'sent'
              ? 'Préparez une annonce pour communiquer rapidement avec les parents, élèves ou enseignants.'
              : 'Les communications importantes envoyées par l’école apparaîtront ici.'
          }}
        </p>
        <button v-if="canBroadcast" type="button" class="btn-primary" @click="openBroadcast">
          Préparer une annonce
        </button>
      </div>
      <div v-else class="announcement-list" :class="{ 'is-staff-list': !isPortalUser }">
        <article
          v-for="group in announcementGroups"
          :key="group.key"
          class="announcement-card"
          :class="{
            'is-unread': announcementMode === 'received' && isUnreadForMe(group.message),
            'is-portal-tappable': isPortalUser,
            'is-staff-tappable': !isPortalUser,
          }"
          tabindex="0"
          role="button"
          :aria-label="`Communiqué : ${group.message.subject}`"
          @click="openAnnouncementSummary(group)"
          @keydown.enter.prevent="openAnnouncementSummary(group)"
          @keydown.space.prevent="openAnnouncementSummary(group)"
        >
          <div class="announcement-card-top">
            <span class="announcement-badge">Communiqué</span>
            <time :datetime="group.message.created_at">{{ formatListTime(group.message.created_at) }}</time>
          </div>
          <h3>{{ group.message.subject }}</h3>
          <p>{{ messagePreview(group.message) }}</p>
          <div class="announcement-card-footer">
            <span v-if="announcementMode === 'sent'" class="announcement-card-recipients">
              <Users :size="15" aria-hidden="true" />
              <span>
                Destinataires
                <strong>{{ group.recipientsCount }}</strong>
              </span>
            </span>
            <span v-else class="announcement-card-sender">
              {{ group.message.sender?.name ?? 'Administration' }}
            </span>
            <span class="announcement-card-actions">
              <span
                v-if="announcementMode === 'received' && isUnreadForMe(group.message)"
                class="msg-unread-pill"
                aria-label="Non lu"
              />
              <span class="announcement-card-chevron" aria-hidden="true">
                <ChevronRight :size="18" />
              </span>
            </span>
          </div>
        </article>
      </div>
      </template>
    </section>

    <ComposeMessageSheet
      :open="composeOpen"
      :is-reply="Boolean(composeForm.parent_message_id)"
      :composing="composing"
      :compose-error="composeError"
      :compose-errors="composeErrors"
      :recipient-id="composeForm.recipient_id"
      :subject="composeForm.subject"
      :body="composeForm.body"
      :body-remaining="composeBodyRemaining"
      :can-submit="composeCanSubmit"
      :contacts-loading="contactsLoading"
      :contacts-error="contactsError"
      :filtered-contacts="filteredComposeContacts"
      :selected-contact="selectedComposeContact"
      :contact-search="contactSearch"
      @close="composeOpen = false"
      @send="sendMessage"
      @retry-contacts="loadContacts(true)"
      @select-contact="selectComposeRecipient"
      @clear-recipient="clearComposeRecipient"
      :show-admin-filters="!isPortalUser"
      :contact-type-filter="contactTypeFilter"
      :contact-cycle-filter="contactCycleFilter"
      :contact-classroom-filter="contactClassroomFilter"
      :contact-type-options="composeContactTypeOptions"
      :contact-cycle-options="composeCycleOptions"
      :classroom-options="composeClassroomOptions"
      :available-cycles="availableComposeCycles"
      @update:contact-search="contactSearch = $event"
      @update:contact-type-filter="contactTypeFilter = $event"
      @update:contact-cycle-filter="contactCycleFilter = $event"
      @update:contact-classroom-filter="contactClassroomFilter = $event"
      @update:subject="composeForm.subject = $event"
      @update:body="composeForm.body = $event"
    />

    <BroadcastComposeModal
      :open="broadcastOpen"
      :contacts="contacts"
      @close="broadcastOpen = false"
      @sent="onBroadcastSent"
    />
  </section>
</template>

<style scoped>
.messages-page {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.messages-page.is-portal {
  gap: 0;
}

.messages-hero {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
  padding: 1.25rem;
  border: 1px solid var(--border-strong);
  border-radius: calc(var(--radius) + 8px);
  background:
    radial-gradient(circle at top right, rgba(59, 130, 246, 0.18), transparent 18rem),
    linear-gradient(135deg, var(--bg-card) 0%, var(--bg-subtle) 100%);
  box-shadow: var(--shadow-card);
}

.messages-hero-copy {
  max-width: 44rem;
}

.messages-hero h1 {
  margin: 0;
  font-size: clamp(1.55rem, 2.2vw, 2.05rem);
}

.messages-hero p {
  margin: 0.45rem 0 0;
  color: var(--text-soft);
}

.eyebrow,
.panel-kicker,
.thread-label,
.metric-label {
  margin: 0;
  color: var(--text-soft);
  font-size: 0.72rem;
  font-weight: 850;
  letter-spacing: 0.08em;
  text-transform: uppercase;
}

.hero-actions {
  display: flex;
  gap: 0.55rem;
  flex-wrap: wrap;
  justify-content: flex-end;
}

.message-metrics {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 0.75rem;
}

.metric-card {
  padding: 1rem;
  border: 1px solid var(--border);
  border-radius: calc(var(--radius) + 4px);
  background: var(--bg-card);
  box-shadow: var(--shadow);
}

.metric-card strong {
  display: block;
  margin: 0.2rem 0;
  color: var(--text);
  font-size: 1.65rem;
  line-height: 1.1;
}

.metric-card span:last-child {
  color: var(--text-soft);
  font-size: 0.84rem;
}

.layout-messages {
  display: grid;
  grid-template-columns: minmax(320px, 0.45fr) minmax(0, 1fr);
  gap: 1rem;
  min-height: 66vh;
}

.msg-sidebar,
.msg-content {
  min-width: 0;
}

.msg-sidebar {
  display: flex;
  flex-direction: column;
  border: 1px solid var(--border);
  border-radius: calc(var(--radius) + 8px);
  overflow: hidden;
  background: var(--bg-card);
  box-shadow: var(--shadow-card);
}

.sidebar-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 1rem;
  border-bottom: 1px solid var(--border);
  background: linear-gradient(180deg, var(--bg-card) 0%, var(--bg-subtle) 100%);
}

.sidebar-top h2 {
  margin: 0.15rem 0 0;
}

.btn-ghost,
.btn-muted,
.btn-sm {
  font-size: 0.85rem;
  border-radius: 999px;
}

.btn-ghost {
  min-height: 2rem;
  padding: 0.3rem 0.7rem;
  background: transparent;
  color: var(--text-soft);
}

.msg-list {
  list-style: none;
  margin: 0;
  padding: 0;
  overflow-y: auto;
  flex: 1;
}

.msg-item {
  border-bottom: 1px solid var(--border);
}

.msg-item:last-child {
  border-bottom: 0;
}

.msg-row {
  display: grid;
  grid-template-columns: 2.5rem minmax(0, 1fr);
  gap: 0.75rem;
  width: 100%;
  min-height: auto;
  padding: 0.85rem 1rem;
  border: 0;
  border-radius: 0;
  background: transparent;
  color: inherit;
  text-align: left;
  box-shadow: none;
}

.msg-row:hover {
  background: var(--primary-tint);
}

.msg-item-active .msg-row {
  background: var(--primary-soft);
  box-shadow: inset 3px 0 0 var(--primary);
}

.msg-item-unread .msg-from,
.msg-item-unread .msg-subject {
  font-weight: 800;
}

.msg-avatar,
.mini-avatar {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex: 0 0 auto;
  border-radius: 999px;
  background: linear-gradient(135deg, var(--primary-soft), var(--bg-soft));
  color: var(--primary);
  font-weight: 850;
}

.msg-avatar {
  width: 2.5rem;
  height: 2.5rem;
  font-size: 0.78rem;
}

.mini-avatar {
  width: 2rem;
  height: 2rem;
  font-size: 0.72rem;
}

.mini-avatar.muted {
  background: var(--bg-soft);
  color: var(--text-soft);
}

.msg-summary {
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
}

.msg-meta {
  display: flex;
  justify-content: space-between;
  gap: 0.6rem;
  color: var(--text-soft);
  font-size: 0.78rem;
}

.msg-meta > span:first-child {
  min-width: 0;
}

.msg-from {
  color: var(--text);
  font-size: 0.9rem;
}

.role-chip {
  margin-left: 0.35rem;
  color: var(--text-muted);
  font-size: 0.72rem;
}

.msg-date {
  flex: 0 0 auto;
  white-space: nowrap;
}

.msg-subject,
.msg-preview {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.msg-subject {
  color: var(--text);
  font-size: 0.94rem;
}

.msg-preview {
  color: var(--text-soft);
  font-size: 0.82rem;
}

.msg-footer {
  min-height: 1.15rem;
  display: flex;
  align-items: center;
  gap: 0.45rem;
  color: var(--text-soft);
  font-size: 0.76rem;
}

.msg-replies,
.unread-dot {
  display: inline-flex;
  align-items: center;
  border-radius: 999px;
  font-weight: 750;
}

.msg-replies {
  color: var(--primary);
}

.unread-dot {
  padding: 0.1rem 0.45rem;
  background: var(--danger-soft);
  color: var(--danger);
}

.announcement-badge {
  display: inline-flex;
  align-items: center;
  margin-right: 0.35rem;
  padding: 0.12rem 0.45rem;
  border-radius: 999px;
  background: var(--primary-soft);
  color: var(--accent);
  font-size: 0.68rem;
  font-weight: 850;
  text-transform: uppercase;
  vertical-align: middle;
}

.announcement-badge.large {
  font-size: 0.72rem;
}

.msg-content {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.msg-card {
  margin: 0;
  border-radius: calc(var(--radius) + 8px);
}

.message-reader-header,
.reply-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
  padding: 1.1rem 1.25rem;
  border-bottom: 1px solid var(--border);
  background: linear-gradient(180deg, var(--bg-card) 0%, var(--bg-subtle) 100%);
}

.reader-title {
  min-width: 0;
}

.reader-title h2 {
  margin: 0.25rem 0 0;
  font-size: clamp(1.15rem, 1.7vw, 1.5rem);
}

.thread-participants {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 0.55rem;
  margin-top: 0.75rem;
}

.participant {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  color: var(--text-soft);
  font-size: 0.86rem;
}

.participant strong {
  color: var(--text);
}

.participant em {
  display: block;
  color: var(--text-muted);
  font-size: 0.75rem;
  font-style: normal;
}

.participant-separator {
  color: var(--text-muted);
}

.message-date-line {
  margin: 0.65rem 0 0;
  color: var(--text-soft);
  font-size: 0.83rem;
}

.reply-card {
  border-left: 4px solid var(--primary-tint);
}

.reply-heading {
  margin: 0.35rem 0 0;
  color: var(--text-soft);
  font-size: 0.9rem;
  text-transform: uppercase;
  letter-spacing: 0.06em;
}

.msg-body {
  padding: 1.25rem;
  white-space: pre-wrap;
  color: var(--text);
  font-size: 0.98rem;
  line-height: 1.75;
}

.reader-empty,
.reader-state,
.list-state,
.list-empty {
  display: flex;
  align-items: center;
  justify-content: center;
  color: var(--text-soft);
}

.reader-empty,
.reader-state {
  min-height: 100%;
  border: 1px dashed var(--border-strong);
  border-radius: calc(var(--radius) + 8px);
  background: rgba(255, 255, 255, 0.62);
}

.reader-empty-card {
  max-width: 28rem;
  padding: 2rem;
  text-align: center;
}

.reader-empty-card h2 {
  margin: 0.5rem 0;
}

.reader-empty-card p {
  margin: 0 0 1rem;
}

.reader-empty-icon,
.empty-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 999px;
  background: var(--primary-soft);
  color: var(--primary);
  font-weight: 850;
}

.reader-empty-icon {
  width: 3rem;
  height: 3rem;
  font-size: 1.35rem;
}

.list-state,
.list-empty {
  min-height: 18rem;
  padding: 1.5rem;
  flex-direction: column;
  gap: 0.55rem;
  text-align: center;
}

.list-empty p {
  max-width: 18rem;
  margin: 0;
}

.empty-icon {
  width: 2.5rem;
  height: 2.5rem;
}

.loader-dot {
  width: 0.7rem;
  height: 0.7rem;
  margin-right: 0.5rem;
  border-radius: 999px;
  background: var(--primary);
  animation: pulse 0.9s ease-in-out infinite alternate;
}

.badge-count {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 1.3rem;
  height: 1.3rem;
  margin-left: 0.4rem;
  padding: 0 0.35rem;
  border-radius: 999px;
  background: var(--danger, #dc2626);
  color: white;
  font-size: 0.75rem;
  font-weight: 800;
  vertical-align: middle;
}

.badge-count.small {
  min-width: 1.1rem;
  height: 1.1rem;
  font-size: 0.7rem;
}

.compose-shell {
  display: grid;
  gap: 0.95rem;
}

.compose-modal {
  display: grid;
  gap: 0.9rem;
}

.compose-modal .alert {
  margin: 0;
}

.compose-panel {
  display: grid;
  gap: 0.75rem;
  padding: 0.95rem;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: var(--bg-card);
}

.compose-panel-heading {
  display: flex;
  align-items: flex-start;
  gap: 0.65rem;
}

.compose-panel-heading label {
  margin: 0;
  color: var(--text);
  font-size: 0.9rem;
}

.compose-panel-heading p {
  margin: 0.08rem 0 0;
  color: var(--text-soft);
  font-size: 0.78rem;
}

.compose-panel-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex: 0 0 auto;
  width: 2rem;
  height: 2rem;
  border-radius: var(--radius);
  background: var(--primary-soft);
  color: var(--primary);
}

.selected-recipient {
  display: flex;
  align-items: center;
  gap: 0.65rem;
  min-width: 0;
  padding: 0.7rem;
  border: 1px solid var(--primary-tint);
  border-radius: var(--radius);
  background: var(--bg-soft);
}

.selected-recipient > span:last-child,
.contact-option-main {
  min-width: 0;
}

.selected-recipient strong,
.contact-option-main strong {
  display: block;
  overflow: hidden;
  color: var(--text);
  font-size: 0.92rem;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.selected-recipient small,
.contact-option-main small {
  display: block;
  overflow: hidden;
  color: var(--text-soft);
  font-size: 0.78rem;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.recipient-avatar {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex: 0 0 auto;
  width: 2.35rem;
  height: 2.35rem;
  border-radius: 999px;
  background: var(--primary-soft);
  color: var(--primary);
  font-size: 0.75rem;
  font-weight: 850;
}

.recipient-avatar.compact {
  width: 2rem;
  height: 2rem;
  font-size: 0.7rem;
}

.contact-search-field,
.compose-input-with-icon {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr);
  align-items: center;
  gap: 0.55rem;
  min-height: 2.45rem;
  padding: 0 0.72rem;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: var(--bg-soft);
  color: var(--text-muted);
  transition:
    border-color 0.15s ease,
    box-shadow 0.15s ease,
    background 0.15s ease;
}

.contact-search-field:focus-within,
.compose-input-with-icon:focus-within,
.compose-textarea-wrap:focus-within {
  border-color: var(--primary);
  background: var(--bg-card);
  box-shadow: 0 0 0 3px rgba(52, 87, 255, 0.14);
}

.contact-search-field input,
.compose-input-with-icon input,
.compose-textarea-wrap textarea {
  border: 0;
  border-radius: 0;
  background: transparent;
  box-shadow: none;
}

.contact-search-field input,
.compose-input-with-icon input {
  min-height: 2.35rem;
  padding: 0;
}

.contact-search-field input:focus,
.compose-input-with-icon input:focus,
.compose-textarea-wrap textarea:focus {
  box-shadow: none;
}

.compose-filter-row {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: 0.65rem;
}

.compose-filter-group {
  display: grid;
  gap: 0.35rem;
}

.compose-filter-group > span {
  color: var(--text-soft);
  font-size: 0.72rem;
  font-weight: 800;
  letter-spacing: 0.06em;
  text-transform: uppercase;
}

.compose-filter-tabs {
  display: flex;
  flex-wrap: wrap;
  gap: 0.35rem;
}

.compose-filter-tab {
  min-height: 1.9rem;
  padding: 0.28rem 0.62rem;
  border-color: var(--border);
  border-radius: 999px;
  background: var(--bg-card);
  color: var(--text-soft);
  font-size: 0.78rem;
  font-weight: 750;
  box-shadow: none;
}

.compose-filter-tab:hover {
  border-color: var(--primary-tint);
  background: var(--bg-soft);
  color: var(--primary);
}

.compose-filter-tab.active {
  border-color: var(--primary);
  background: var(--primary);
  color: #ffffff;
}

.compose-filter-tab:disabled {
  opacity: 0.42;
  color: var(--text-muted);
}

.compose-filter-select {
  min-height: 2.15rem;
  padding: 0.34rem 0.65rem;
  border-radius: 999px;
  background: var(--bg-card);
  color: var(--text);
  font-size: 0.82rem;
  font-weight: 650;
}

.contact-picker {
  display: grid;
  max-height: 14rem;
  overflow-y: auto;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: var(--bg-card);
}

.contact-option {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr) auto;
  align-items: center;
  gap: 0.7rem;
  min-height: 3.45rem;
  padding: 0.65rem 0.75rem;
  border: 0;
  border-bottom: 1px solid var(--border);
  border-radius: 0;
  background: transparent;
  box-shadow: none;
  text-align: left;
}

.contact-option:last-child {
  border-bottom: 0;
}

.contact-option:hover {
  background: var(--bg-soft);
}

.contact-option.is-selected {
  background: var(--primary-soft);
  box-shadow: inset 3px 0 0 var(--primary);
}

.contact-role-chip {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  max-width: 8rem;
  overflow: hidden;
  padding: 0.18rem 0.5rem;
  border-radius: 999px;
  background: var(--bg-soft);
  color: var(--text-soft);
  font-size: 0.7rem;
  font-weight: 800;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.contact-picker-state {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0.55rem;
  min-height: 5.5rem;
  padding: 1rem;
  color: var(--text-soft);
  font-size: 0.86rem;
  text-align: center;
}

.contact-picker-state.error-state {
  flex-direction: column;
  color: var(--danger);
}

.compose-field {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}

.compose-field.body-field {
  margin-top: 0.15rem;
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

.label-row span.is-low {
  color: var(--danger);
  font-weight: 750;
}

.compose-textarea-wrap {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr);
  align-items: flex-start;
  gap: 0.55rem;
  min-height: 12rem;
  padding: 0.72rem;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: var(--bg-card);
  color: var(--text-muted);
  transition:
    border-color 0.15s ease,
    box-shadow 0.15s ease;
}

.compose-textarea-wrap textarea {
  min-height: 10.6rem;
  padding: 0;
  resize: vertical;
}

.compose-send-button {
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
}

.spin-icon {
  animation: spin 0.8s linear infinite;
}

.err {
  display: block;
  color: var(--danger);
  font-size: 0.78rem;
}

.btn-sm {
  min-height: 2rem;
  padding: 0.35rem 0.75rem;
  border: 1px solid var(--border);
  background: var(--bg-card);
}

.btn-sm:hover {
  background: var(--primary-soft);
}

.btn-danger-sm {
  color: var(--danger, #dc2626);
}

.btn-danger-sm:hover {
  background: var(--danger-soft);
}

.btn-muted {
  padding: 0.45rem 0.9rem;
  border: 1px solid var(--border);
  background: transparent;
}

@keyframes pulse {
  from {
    transform: scale(0.75);
    opacity: 0.45;
  }
  to {
    transform: scale(1);
    opacity: 1;
  }
}

@keyframes spin {
  to {
    transform: rotate(360deg);
  }
}

@media (max-width: 980px) {
  .messages-hero,
  .message-reader-header {
    flex-direction: column;
  }

  .hero-actions {
    width: 100%;
    justify-content: flex-start;
  }

  .message-metrics {
    grid-template-columns: 1fr;
  }

  .layout-messages {
    grid-template-columns: 1fr;
  }

  .msg-sidebar {
    max-height: none;
  }

  .reader-empty,
  .reader-state {
    min-height: 20rem;
  }
}

@media (max-width: 560px) {
  .messages-hero,
  .metric-card,
  .message-reader-header,
  .reply-header,
  .msg-body {
    padding: 0.9rem;
  }

  .msg-row {
    grid-template-columns: 1fr;
  }

  .msg-avatar {
    display: none;
  }

  .msg-meta,
  .thread-participants {
    align-items: flex-start;
    flex-direction: column;
  }

  .compose-panel {
    padding: 0.75rem;
  }

  .contact-option {
    grid-template-columns: auto minmax(0, 1fr);
  }

  .contact-role-chip {
    grid-column: 2;
    justify-self: flex-start;
  }
}

/* Layout messagerie instantanee */
.chat-shell {
  display: grid;
  grid-template-columns: minmax(320px, 380px) minmax(0, 1fr);
  flex: 1 1 auto;
  min-height: 0;
  overflow: hidden;
  border: 1px solid var(--border);
  border-radius: calc(var(--radius) + 10px);
  background: var(--bg-card);
  box-shadow: var(--shadow-card);
}

.chat-sidebar {
  display: flex;
  min-width: 0;
  min-height: 0;
  flex-direction: column;
  border-right: 1px solid var(--border);
  background: var(--bg-card);
}

.chat-sidebar-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 0.8rem;
  padding: 1rem;
  border-bottom: 1px solid var(--border);
  background: var(--bg-soft);
}

.chat-sidebar-header h1 {
  margin: 0.15rem 0 0;
  font-size: 1.18rem;
}

.chat-sidebar-header p:last-child {
  margin: 0.2rem 0 0;
  color: var(--text-soft);
  font-size: 0.8rem;
}

.sidebar-actions {
  display: flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: 0.35rem;
}

.icon-button {
  min-height: 2rem;
  padding: 0.35rem 0.65rem;
  border-radius: 999px;
  background: var(--bg-card);
  color: var(--text-soft);
  font-size: 0.78rem;
  box-shadow: none;
}

.icon-button.primary {
  border-color: var(--primary);
  background: var(--primary);
  color: #ffffff;
}

.chat-search {
  padding: 0.7rem 0.85rem;
  border-bottom: 1px solid var(--border);
  background: var(--bg-card);
}

.chat-search input {
  min-height: 2.35rem;
  border-radius: 999px;
  background: var(--bg-soft);
  padding-inline: 0.95rem;
}

.chat-sidebar > .list-state,
.chat-sidebar > .list-empty,
.chat-sidebar > .msg-list {
  flex: 1 1 auto;
  min-height: 0;
  overflow-y: auto;
  -webkit-overflow-scrolling: touch;
  scrollbar-width: none;
  -ms-overflow-style: none;
}

.chat-sidebar > .list-state::-webkit-scrollbar,
.chat-sidebar > .list-empty::-webkit-scrollbar,
.chat-sidebar > .msg-list::-webkit-scrollbar {
  display: none;
}

.chat-sidebar > .list-state,
.chat-sidebar > .list-empty {
  min-height: 0;
}

.msg-list {
  min-height: 0;
  margin: 0;
  padding: 0;
  list-style: none;
}

.conversation-row {
  display: grid;
  grid-template-columns: 2.75rem minmax(0, 1fr);
  gap: 0.75rem;
  width: 100%;
  min-height: 4.8rem;
  padding: 0.75rem 0.9rem;
  border: 0;
  border-radius: 0;
  background: transparent;
  color: var(--text);
  text-align: left;
  box-shadow: none;
}

.conversation-row:hover {
  background: var(--bg-soft);
}

.msg-item-active .conversation-row {
  background: var(--primary-soft);
  box-shadow: inset 4px 0 0 var(--primary);
}

.conversation-row .msg-avatar {
  width: 2.75rem;
  height: 2.75rem;
  background: var(--primary-soft);
  color: var(--primary);
}

.msg-meta {
  align-items: baseline;
}

.msg-from {
  display: inline-block;
  max-width: 9.5rem;
  overflow: hidden;
  text-overflow: ellipsis;
  vertical-align: bottom;
  white-space: nowrap;
}

.msg-date {
  color: var(--text-muted);
  font-size: 0.72rem;
}

.msg-preview {
  margin-top: 0.05rem;
}

.sidebar-footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
  padding: 0.65rem 0.85rem;
  border-top: 1px solid var(--border);
  background: var(--bg-card);
  color: var(--text-muted);
  font-size: 0.78rem;
}

.chat-panel {
  display: grid;
  min-width: 0;
  min-height: 0;
  grid-template-rows: auto minmax(0, 1fr) auto;
  background: var(--bg-soft);
}

.chat-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  padding: 0.85rem 1rem;
  border-bottom: 1px solid var(--border);
  background: rgba(255, 255, 255, 0.96);
}

.chat-contact {
  display: flex;
  min-width: 0;
  align-items: center;
  gap: 0.75rem;
}

.msg-avatar.large {
  width: 2.85rem;
  height: 2.85rem;
}

.chat-contact h2 {
  margin: 0;
  overflow: hidden;
  font-size: 1rem;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.chat-contact-copy {
  min-width: 0;
}

.chat-contact-meta,
.chat-contact p {
  margin: 0.15rem 0 0;
  overflow: hidden;
  color: var(--text-soft);
  font-size: 0.8rem;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.chat-contact-meta span,
.chat-contact p span {
  margin: 0 0.25rem;
  color: var(--text-muted);
}

.chat-thread {
  min-height: 0;
  padding: 1.2rem 1.4rem;
  overflow-y: auto;
  scrollbar-width: none;
  -ms-overflow-style: none;
}

.chat-thread::-webkit-scrollbar {
  display: none;
}

.draft-chat-header h2 {
  margin: 0.15rem 0 0;
  font-size: 1.05rem;
}

.draft-chat-meta {
  margin: 0.2rem 0 0;
  color: var(--text-soft);
  font-size: 0.82rem;
}

.draft-thread {
  display: flex;
  align-items: center;
  justify-content: center;
}

.draft-thread-hint {
  max-width: 26rem;
  margin: 0;
  padding: 1rem 1.1rem;
  border: 1px dashed var(--border);
  border-radius: calc(var(--radius) + 4px);
  background: rgba(255, 255, 255, 0.88);
  color: var(--text-soft);
  font-size: 0.9rem;
  line-height: 1.5;
  text-align: center;
}

.system-chip {
  width: fit-content;
  max-width: min(100%, 30rem);
  margin: 0 auto 1rem;
  padding: 0.35rem 0.75rem;
  border-radius: 999px;
  background: rgba(255, 255, 255, 0.72);
  color: var(--text-soft);
  font-size: 0.78rem;
}

.bubble-row {
  display: flex;
  margin: 0.32rem 0;
}

.bubble-row.is-mine {
  justify-content: flex-end;
}

.bubble-row.is-other {
  justify-content: flex-start;
}

.bubble {
  position: relative;
  width: fit-content;
  max-width: min(68%, 42rem);
  padding: 0.58rem 0.76rem 0.42rem;
  border-radius: 1.05rem;
  background: rgba(255, 255, 255, 0.94);
  color: var(--text);
}

.is-mine .bubble {
  border-bottom-right-radius: 0.28rem;
  background: var(--primary-soft);
  color: var(--accent);
}

.is-other .bubble {
  border-bottom-left-radius: 0.28rem;
}

.bubble-meta {
  display: flex;
  gap: 0.4rem;
  margin-bottom: 0.16rem;
  color: rgba(16, 24, 40, 0.55);
  font-size: 0.73rem;
}

.bubble-meta strong {
  color: currentColor;
  font-weight: 850;
}

.bubble p {
  margin: 0;
  white-space: pre-wrap;
  overflow-wrap: anywhere;
  line-height: 1.55;
}

.bubble-announcement {
  display: inline-flex;
  margin-bottom: 0.3rem !important;
  padding: 0.1rem 0.45rem;
  border-radius: 999px;
  background: var(--primary-soft);
  color: var(--accent);
  font-size: 0.68rem;
  font-weight: 850;
  text-transform: uppercase;
}

.bubble-footer {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 0.5rem;
  margin-top: 0.25rem;
  color: rgba(16, 42, 86, 0.56);
  font-size: 0.7rem;
}

.bubble-footer button {
  min-height: auto;
  padding: 0;
  border: 0;
  background: transparent;
  color: var(--text-muted);
  font-size: 0.7rem;
  opacity: 0;
  box-shadow: none;
}

.bubble:hover .bubble-footer button,
.bubble-footer button:focus-visible {
  opacity: 1;
}

.bubble-footer button:hover {
  color: var(--danger);
  background: transparent;
}

.chat-composer {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  gap: 0.65rem;
  padding: 0.85rem 1rem;
  border-top: 1px solid var(--border);
  background: rgba(255, 255, 255, 0.96);
}

.chat-composer textarea {
  min-height: 2.6rem;
  max-height: 8rem;
  resize: vertical;
  border-radius: 1.35rem;
  background: var(--bg-soft);
  padding: 0.72rem 0.95rem;
}

.chat-composer .btn-primary {
  align-self: end;
  min-height: 2.6rem;
  border-radius: 999px;
}

.composer-error {
  grid-column: 1 / -1;
  margin: 0;
  color: var(--danger);
  font-size: 0.82rem;
}

.chat-empty,
.reader-state {
  grid-row: 1 / -1;
  display: flex;
  align-items: center;
  justify-content: center;
  background:
    linear-gradient(rgba(246, 248, 252, 0.9), rgba(246, 248, 252, 0.9)),
    radial-gradient(circle at center, rgba(52, 87, 255, 0.09), transparent 22rem);
}

.chat-empty .reader-empty-card {
  border: 1px solid var(--border);
  border-radius: calc(var(--radius) + 8px);
  background: rgba(255, 255, 255, 0.84);
  box-shadow: var(--shadow-card);
}

@media (max-width: 1020px) {
  .messages-page--fixed .chat-shell {
    grid-template-columns: 1fr;
    grid-template-rows: minmax(0, 1fr);
  }

  .messages-page--fixed .chat-sidebar {
    min-height: 0;
    border-right: 0;
    border-bottom: 1px solid var(--border);
  }

  .messages-page--fixed .chat-panel {
    min-height: 0;
  }

}

@media (max-width: 640px) {
  .chat-sidebar-header,
  .chat-header,
  .chat-composer {
    align-items: stretch;
    grid-template-columns: 1fr;
    flex-direction: column;
  }

  .sidebar-actions {
    justify-content: flex-start;
  }

  .bubble {
    max-width: 88%;
  }

  .chat-thread {
    padding: 0.8rem;
  }
}

/* Hero et onglets de communication */
.communication-hero {
  align-items: stretch;
  margin-bottom: 0;
}

.hero-panel {
  display: flex;
  min-width: min(100%, 28rem);
  flex-direction: column;
  justify-content: space-between;
  gap: 1rem;
  padding: 0.85rem;
  border: 1px solid rgba(52, 87, 255, 0.14);
  border-radius: calc(var(--radius) + 6px);
  background: rgba(255, 255, 255, 0.72);
  backdrop-filter: blur(10px);
}

.hero-stats {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 0.5rem;
}

.hero-stats span {
  display: flex;
  min-height: 4rem;
  flex-direction: column;
  justify-content: center;
  padding: 0.6rem;
  border: 1px solid var(--border);
  border-radius: calc(var(--radius) + 2px);
  background: var(--bg-card);
  color: var(--text-soft);
  font-size: 0.78rem;
}

.hero-stats strong {
  color: var(--text);
  font-size: 1.25rem;
}

.communication-tabs {
  display: flex;
  gap: 0.45rem;
  width: fit-content;
  max-width: 100%;
  padding: 0.35rem;
  border: 1px solid var(--border);
  border-radius: 999px;
  background: var(--bg-card);
  box-shadow: var(--shadow);
}

.communication-tabs button {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.45rem;
  min-width: 10rem;
  border: 0;
  border-radius: 999px;
  background: transparent;
  color: var(--text-soft);
  box-shadow: none;
}

.communication-tabs button.active {
  background: var(--primary);
  color: #ffffff;
}

.communication-tabs span {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 1.35rem;
  height: 1.35rem;
  padding: 0 0.35rem;
  border-radius: 999px;
  background: rgba(16, 24, 40, 0.08);
  font-size: 0.72rem;
  font-weight: 850;
}

.communication-tabs button.active span {
  background: rgba(255, 255, 255, 0.22);
}

.announcements-panel {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  min-height: 0;
  padding: 1rem;
  border: 1px solid var(--border);
  border-radius: calc(var(--radius) + 10px);
  background:
    radial-gradient(circle at top right, rgba(59, 130, 246, 0.1), transparent 18rem),
    var(--bg-card);
  box-shadow: var(--shadow-card);
}

.announcement-summary-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 0.75rem;
}

.announcement-summary-grid article {
  padding: 1rem;
  border: 1px solid var(--border);
  border-radius: calc(var(--radius) + 4px);
  background: var(--bg-subtle);
}

.announcement-summary-grid span,
.announcement-summary-grid small {
  display: block;
  color: var(--text-soft);
}

.announcement-summary-grid span {
  font-size: 0.75rem;
  font-weight: 850;
  letter-spacing: 0.06em;
  text-transform: uppercase;
}

.announcement-summary-grid strong {
  display: block;
  margin: 0.25rem 0;
  color: var(--text);
  font-size: 1.35rem;
}

.announcement-state,
.announcement-empty {
  display: flex;
  flex: 1;
  min-height: 18rem;
  align-items: center;
  justify-content: center;
  color: var(--text-soft);
  text-align: center;
}

.announcement-empty {
  flex-direction: column;
  gap: 0.65rem;
  padding: 2rem;
  border: 1px dashed var(--border-strong);
  border-radius: calc(var(--radius) + 6px);
  background: var(--bg-soft);
}

.announcement-empty h2,
.announcement-empty p {
  margin: 0;
}

.announcement-list {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(18rem, 1fr));
  gap: 0.85rem;
}

.announcement-card {
  display: flex;
  min-height: 13rem;
  flex-direction: column;
  justify-content: space-between;
  gap: 0.85rem;
  padding: 1rem;
  border: 1px solid var(--border);
  border-radius: calc(var(--radius) + 6px);
  background: var(--bg-card);
  box-shadow: var(--shadow);
  transition:
    transform 0.15s ease,
    box-shadow 0.15s ease,
    border-color 0.15s ease;
}

.announcement-card:hover {
  border-color: var(--primary-tint);
  box-shadow: var(--shadow-card);
}

.announcement-card-top,
.announcement-card-footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
}

.announcement-card-top time {
  color: var(--text-muted);
  font-size: 0.78rem;
}

.announcement-card h3 {
  margin: 0;
  font-size: 1.05rem;
}

.announcement-card p {
  margin: 0;
  color: var(--text-soft);
}

.announcement-card-footer {
  color: var(--text-soft);
  font-size: 0.84rem;
}

.announcement-card-footer strong {
  color: var(--text);
}

@media (max-width: 980px) {
  .communication-hero {
    flex-direction: column;
  }

  .hero-panel {
    width: 100%;
  }

  .announcement-summary-grid {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 640px) {
  .hero-stats,
  .announcement-list {
    grid-template-columns: 1fr;
  }

  .communication-tabs {
    width: 100%;
  }

  .communication-tabs button {
    min-width: 0;
    flex: 1;
  }

  .announcements-panel {
    padding: 0.75rem;
  }
}

/* Vue fixe : seuls les panneaux internes défilent. */
.messages-page.messages-page--fixed {
  flex: 1 1 auto;
  min-height: 0;
  max-height: 100%;
  overflow: hidden;
  gap: 0.65rem;
}

.messages-page.messages-page--fixed .communication-hero,
.messages-page.messages-page--fixed .communication-tabs,
.messages-page.messages-page--fixed > .alert {
  flex: 0 0 auto;
}

.messages-page.messages-page--fixed .communication-hero {
  padding: 0.75rem 1rem;
}

.messages-page.messages-page--fixed .communication-hero .messages-hero-copy p:not(.eyebrow) {
  display: none;
}

.messages-page.messages-page--fixed .hero-panel {
  padding: 0.55rem;
}

.messages-page.messages-page--fixed .hero-stats span {
  min-height: 2.75rem;
}

.messages-page.messages-page--fixed .chat-shell,
.messages-page.messages-page--fixed .announcements-panel {
  flex: 1 1 auto;
  min-height: 0;
  overflow: hidden;
}

.messages-page.messages-page--fixed .announcement-summary-grid,
.messages-page.messages-page--fixed .staff-announce-kpis {
  flex: 0 0 auto;
}

.messages-page.messages-page--fixed .announcement-list,
.messages-page.messages-page--fixed .announcement-state,
.messages-page.messages-page--fixed .announcement-empty {
  flex: 1 1 auto;
  min-height: 0;
  overflow-y: auto;
  -webkit-overflow-scrolling: touch;
  padding-right: 0.15rem;
  scrollbar-width: none;
  -ms-overflow-style: none;
}

.messages-page.messages-page--fixed .announcement-list::-webkit-scrollbar,
.messages-page.messages-page--fixed .announcement-state::-webkit-scrollbar,
.messages-page.messages-page--fixed .announcement-empty::-webkit-scrollbar {
  display: none;
}
</style>
