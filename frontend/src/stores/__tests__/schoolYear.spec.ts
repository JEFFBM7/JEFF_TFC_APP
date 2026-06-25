import { describe, it, expect, beforeEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { useSchoolYearStore } from '../schoolYear'
import { api } from '../../api/client'

// Mock the API client
vi.mock('../../api/client', () => {
  class ApiError extends Error {
    status: number
    constructor(status: number, message: string) {
      super(message)
      this.status = status
    }
  }
  return {
    api: vi.fn(),
    ApiError
  }
})

describe('School Year Store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('initializes with default values', () => {
    const store = useSchoolYearStore()
    expect(store.years).toEqual([])
    expect(store.current).toBeNull()
    expect(store.selectedId).toBeNull()
    expect(store.loading).toBe(false)
  })

  it('fetches school years and sets current', async () => {
    const mockYears = [
      { id: 1, name: '2023-2024', is_current: false },
      { id: 2, name: '2024-2025', is_current: true },
    ]
    vi.mocked(api).mockResolvedValueOnce({ data: mockYears })

    const store = useSchoolYearStore()
    await store.fetchAll()

    expect(api).toHaveBeenCalledWith('/api/v1/school-years', { skipSchoolYear: true })
    expect(store.years).toEqual(mockYears)
    expect(store.current?.id).toBe(2)
  })

  it('handles API errors during fetchAll by keeping current if 401', async () => {
    // Import ApiError dynamically from the mocked module for the test
    const { ApiError } = await import('../../api/client')
    vi.mocked(api).mockRejectedValueOnce(new ApiError(401, 'Unauthorized'))

    const store = useSchoolYearStore()
    store.current = { id: 1, name: '2023-2024', is_current: true } as any
    await store.fetchAll()

    expect(store.years).toEqual([{ id: 1, name: '2023-2024', is_current: true }])
  })

  it('returns the correct effectiveId', () => {
    const store = useSchoolYearStore()
    store.current = { id: 2, name: '2024-2025', is_current: true } as any
    
    // By default, effectiveId is current
    expect(store.effectiveId).toBe(2)

    // When selectedId is set, effectiveId follows
    store.setSelected(1)
    expect(store.effectiveId).toBe(1)
  })
})
