import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { api, getToken, setToken, setUnauthenticatedHandler } from '../api/client'
import {
  disablePushNotifications,
  enablePushNotifications,
  pushPermission,
} from '../composables/usePushNotifications'
import { disconnectRealtime } from '../api/realtime'
import { getPortalDeviceName } from '../utils/portalPwa'
import type { AuthUser, LoginResponse, UserRole } from '../types'

/**
 * `navigator.serviceWorker.ready` (utilisé par disablePushNotifications) ne se
 * résout jamais si aucun service worker n'a pris le contrôle de la page — sans
 * ce garde-fou, un logout resterait bloqué indéfiniment avant de vider la
 * session et de rediriger vers /login.
 */
function withTimeout<T>(promise: Promise<T>, ms: number): Promise<T | undefined> {
  return new Promise((resolve) => {
    const timer = setTimeout(() => resolve(undefined), ms)
    promise
      .then((value) => {
        clearTimeout(timer)
        resolve(value)
      })
      .catch(() => {
        clearTimeout(timer)
        resolve(undefined)
      })
  })
}

export const useAuthStore = defineStore('auth', () => {
  const user = ref<AuthUser | null>(null)
  const loading = ref(false)
  const initialized = ref(false)

  const isAuthenticated = computed(() => !!user.value)
  const role = computed<UserRole | null>(() => user.value?.role ?? null)

  function hasRole(...allowed: UserRole[]): boolean {
    return !!user.value && allowed.includes(user.value.role)
  }

  async function fetchMe(): Promise<void> {
    if (!getToken()) {
      user.value = null
      return
    }
    try {
      user.value = await api<AuthUser>('/api/v1/auth/me')
    } catch {
      user.value = null
      setToken(null)
    }
  }

  async function login(identifier: string, password: string): Promise<void> {
    loading.value = true
    try {
      const res = await api<LoginResponse>('/api/v1/auth/login', {
        method: 'POST',
        body: { identifier, password, device_name: getPortalDeviceName() },
      })
      setToken(res.token)
      user.value = res.user
      disconnectRealtime()
      // Re-sync silencieux de la souscription push si déjà autorisée (pas de prompt).
      if (pushPermission() === 'granted') void enablePushNotifications()
    } finally {
      loading.value = false
    }
  }

  async function logout(): Promise<void> {
    try {
      if (getToken()) {
        await api('/api/v1/auth/logout', { method: 'POST' })
      }
    } catch {
      // si le serveur refuse, on déconnecte tout de même côté client
    } finally {
      await withTimeout(disablePushNotifications().catch(() => {}), 3000)
      setToken(null)
      user.value = null
      disconnectRealtime()
    }
  }

  async function init(): Promise<void> {
    if (initialized.value) return
    setUnauthenticatedHandler(() => {
      setToken(null)
      user.value = null
      disconnectRealtime()
    })
    await fetchMe()
    initialized.value = true
    // Souscription déjà autorisée : on rafraîchit côté serveur sans re-prompter.
    if (user.value && pushPermission() === 'granted') void enablePushNotifications()
  }

  return {
    user,
    loading,
    initialized,
    isAuthenticated,
    role,
    hasRole,
    init,
    login,
    logout,
    fetchMe,
  }
})
