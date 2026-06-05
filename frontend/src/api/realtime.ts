import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
import { getToken } from './client'
import type { Message } from '../types'

declare global {
  interface Window {
    Pusher: typeof Pusher
  }
}

export interface MessageRealtimeEvent {
  type: string
  section: 'messages' | 'announcements'
  unread_count: number
  message?: Message
  broadcast_id?: string
  recipients_count?: number
  updated_count?: number
}

export const MESSAGES_REALTIME_EVENT = 'messages:realtime-updated'
export const MESSAGES_UNREAD_EVENT = 'messages:unread-updated'

type Listener = (event: MessageRealtimeEvent) => void

let echo: Echo<'reverb'> | null = null
const channelListeners = new Map<string, Set<Listener>>()
const subscribedChannels = new Set<string>()

function reverbPort(): number {
  return Number(import.meta.env.VITE_REVERB_PORT ?? 8080)
}

function reverbScheme(): 'http' | 'https' {
  return import.meta.env.VITE_REVERB_SCHEME === 'https' ? 'https' : 'http'
}

function refreshEchoAuth(): void {
  if (!echo) return

  const connector = echo.connector as {
    options?: { auth?: { headers?: Record<string, string> } }
  }

  if (connector.options?.auth?.headers) {
    connector.options.auth.headers.Authorization = `Bearer ${getToken() ?? ''}`
  }
}

function getEcho(): Echo<'reverb'> {
  refreshEchoAuth()

  if (echo) return echo

  window.Pusher = Pusher

  const scheme = reverbScheme()
  const port = reverbPort()

  echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY ?? 'educonnect-local-key',
    wsHost: import.meta.env.VITE_REVERB_HOST ?? window.location.hostname,
    wsPort: port,
    wssPort: port,
    forceTLS: scheme === 'https',
    enabledTransports: ['ws', 'wss'],
    authEndpoint: '/broadcasting/auth',
    auth: {
      headers: {
        Authorization: `Bearer ${getToken() ?? ''}`,
        Accept: 'application/json',
      },
    },
  })

  return echo
}

function dispatchBrowserEvents(event: MessageRealtimeEvent): void {
  window.dispatchEvent(new CustomEvent(MESSAGES_UNREAD_EVENT, { detail: event.unread_count }))
  window.dispatchEvent(new CustomEvent(MESSAGES_REALTIME_EVENT, { detail: event }))
}

function ensureChannel(userId: number): string {
  const channelName = `users.${userId}`

  if (subscribedChannels.has(channelName)) {
    return channelName
  }

  refreshEchoAuth()

  const channel = getEcho().private(channelName)
  channel.listen('.messages.updated', (payload: MessageRealtimeEvent) => {
    dispatchBrowserEvents(payload)
    channelListeners.get(channelName)?.forEach((listener) => listener(payload))
  })

  subscribedChannels.add(channelName)
  return channelName
}

export function subscribeToMessageUpdates(
  userId: number,
  callback: (event: MessageRealtimeEvent) => void,
): () => void {
  const channelName = ensureChannel(userId)

  let listeners = channelListeners.get(channelName)
  if (!listeners) {
    listeners = new Set()
    channelListeners.set(channelName, listeners)
  }

  listeners.add(callback)

  return () => {
    listeners?.delete(callback)

    if (listeners && listeners.size === 0) {
      channelListeners.delete(channelName)
      subscribedChannels.delete(channelName)
      getEcho().leave(channelName)
    }
  }
}

export function disconnectRealtime(): void {
  channelListeners.clear()
  subscribedChannels.clear()
  echo?.disconnect()
  echo = null
}
