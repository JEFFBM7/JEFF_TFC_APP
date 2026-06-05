import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { api, getToken, setToken, setUnauthenticatedHandler } from '../api/client'
import { disconnectRealtime } from '../api/realtime'
import { getPortalDeviceName } from '../utils/portalPwa'
import type { AuthUser, LoginResponse, UserRole } from '../types'

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
