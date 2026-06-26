import { api } from '../api/client'

/** Convertit la clé VAPID (base64url) en Uint8Array pour pushManager.subscribe. */
function urlBase64ToUint8Array(base64String: string): Uint8Array {
  const padding = '='.repeat((4 - (base64String.length % 4)) % 4)
  const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/')
  const raw = atob(base64)
  const output = new Uint8Array(raw.length)
  for (let i = 0; i < raw.length; i++) output[i] = raw.charCodeAt(i)
  return output
}

export function isPushSupported(): boolean {
  return (
    typeof navigator !== 'undefined'
    && 'serviceWorker' in navigator
    && typeof window !== 'undefined'
    && 'PushManager' in window
    && 'Notification' in window
  )
}

export function pushPermission(): NotificationPermission | 'unsupported' {
  if (!isPushSupported()) return 'unsupported'
  return Notification.permission
}

export type EnablePushResult = { ok: boolean; reason?: 'unsupported' | 'denied' | 'no-key' | 'error' }

/**
 * Demande l'autorisation puis abonne l'appareil au Web Push et enregistre la
 * souscription côté serveur. Doit idéalement être appelé depuis un geste
 * utilisateur (obligatoire sur iOS, PWA installée).
 */
export async function enablePushNotifications(): Promise<EnablePushResult> {
  if (!isPushSupported()) return { ok: false, reason: 'unsupported' }

  try {
    const permission = await Notification.requestPermission()
    if (permission !== 'granted') return { ok: false, reason: 'denied' }

    const registration = await navigator.serviceWorker.ready
    const { public_key } = await api<{ public_key: string | null }>('/api/v1/push/public-key', {
      skipSchoolYear: true,
    })
    if (!public_key) return { ok: false, reason: 'no-key' }

    let subscription = await registration.pushManager.getSubscription()
    if (!subscription) {
      subscription = await registration.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: urlBase64ToUint8Array(public_key) as BufferSource,
      })
    }

    const json = subscription.toJSON()
    await api('/api/v1/push/subscribe', {
      method: 'POST',
      skipSchoolYear: true,
      body: { endpoint: json.endpoint, keys: json.keys },
    })

    return { ok: true }
  } catch {
    return { ok: false, reason: 'error' }
  }
}

/** Désabonne l'appareil (déconnexion) et informe le serveur. */
export async function disablePushNotifications(): Promise<void> {
  if (!isPushSupported()) return
  try {
    const registration = await navigator.serviceWorker.ready
    const subscription = await registration.pushManager.getSubscription()
    if (!subscription) return
    try {
      await api('/api/v1/push/unsubscribe', {
        method: 'POST',
        skipSchoolYear: true,
        body: { endpoint: subscription.endpoint },
      })
    } catch {
      /* le serveur a peut-être déjà purgé la souscription */
    }
    await subscription.unsubscribe()
  } catch {
    /* ignore */
  }
}
