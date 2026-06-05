import { setActivePinia, createPinia } from 'pinia'
import { describe, it, expect, beforeEach, vi } from 'vitest'
import { useAuthStore } from '../auth'
import * as client from '../../api/client'

// Mocker le client API
vi.mock('../../api/client', () => ({
  api: vi.fn(),
  getToken: vi.fn(),
  setToken: vi.fn(),
  setUnauthenticatedHandler: vi.fn(),
}))

describe('Auth Store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('initializes correctly', async () => {
    const store = useAuthStore()
    expect(store.initialized).toBe(false)
    expect(store.isAuthenticated).toBe(false)
    expect(store.user).toBeNull()
  })

  it('fetchMe updates user when authenticated', async () => {
    const store = useAuthStore()
    const mockUser = { id: 1, name: 'Admin', email: 'admin@test.com', role: 'admin' }
    
    vi.mocked(client.getToken).mockReturnValue('fake-token')
    vi.mocked(client.api).mockResolvedValue(mockUser)

    await store.fetchMe()

    expect(client.api).toHaveBeenCalledWith('/api/v1/auth/me')
    expect(store.user).toEqual(mockUser)
    expect(store.isAuthenticated).toBe(true)
    expect(store.role).toBe('admin')
  })

  it('hasRole works correctly', async () => {
    const store = useAuthStore()
    
    // Non authentifié
    expect(store.hasRole('admin')).toBe(false)

    // Authentifié en tant qu'admin
    store.user = { id: 1, name: 'Admin', email: 'admin@test.com', role: 'admin' } as any
    expect(store.hasRole('admin')).toBe(true)
    expect(store.hasRole('enseignant')).toBe(false)
    expect(store.hasRole('admin', 'enseignant')).toBe(true)
  })

  it('login sets token and user', async () => {
    const store = useAuthStore()
    const mockRes = {
      token: 'new-token',
      user: { id: 2, name: 'Prof', email: 'prof@test.com', role: 'enseignant' }
    }
    
    vi.mocked(client.api).mockResolvedValue(mockRes)

    await store.login('prof@test.com', 'password')

    expect(client.api).toHaveBeenCalledWith('/api/v1/auth/login', {
      method: 'POST',
      body: { identifier: 'prof@test.com', password: 'password', device_name: 'spa-web' }
    })
    expect(client.setToken).toHaveBeenCalledWith('new-token')
    expect(store.user).toEqual(mockRes.user)
    expect(store.isAuthenticated).toBe(true)
  })

  it('logout clears token and user', async () => {
    const store = useAuthStore()
    store.user = { id: 2, name: 'Prof', email: 'prof@test.com', role: 'enseignant' } as any
    vi.mocked(client.getToken).mockReturnValue('some-token')
    vi.mocked(client.api).mockResolvedValue({})

    await store.logout()

    expect(client.api).toHaveBeenCalledWith('/api/v1/auth/logout', { method: 'POST' })
    expect(client.setToken).toHaveBeenCalledWith(null)
    expect(store.user).toBeNull()
    expect(store.isAuthenticated).toBe(false)
  })
})
