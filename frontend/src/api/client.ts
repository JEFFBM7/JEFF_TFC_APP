export class ApiError extends Error {
  status: number
  errors?: Record<string, string[]>
  payload?: unknown

  constructor(status: number, message: string, errors?: Record<string, string[]>, payload?: unknown) {
    super(message)
    this.status = status
    this.errors = errors
    this.payload = payload
  }
}

const TOKEN_KEY = 'educonnect_token'

export function getToken(): string | null {
  return sessionStorage.getItem(TOKEN_KEY)
}

export function setToken(token: string | null): void {
  if (token === null) {
    sessionStorage.removeItem(TOKEN_KEY)
  } else {
    sessionStorage.setItem(TOKEN_KEY, token)
  }
}

let onUnauthenticated: (() => void) | null = null

export function setUnauthenticatedHandler(fn: () => void): void {
  onUnauthenticated = fn
}

type RequestBody = Record<string, unknown> | undefined

export interface ApiRequestOptions {
  method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'
  body?: RequestBody
  query?: Record<string, string | number | boolean | undefined | null>
  signal?: AbortSignal
  /**
   * Désactive l'injection automatique de `school_year_id` dans la query string.
   * À utiliser sur les endpoints qui doivent voir toutes les années (ex: gestion
   * des années elles-mêmes, messagerie, comptes utilisateurs, auth).
   */
  skipSchoolYear?: boolean
}

/**
 * Hook injectable par le store pour exposer l'année scolaire courante.
 * Découplage volontaire : le client API ne dépend pas directement de Pinia
 * pour éviter des cycles d'import.
 */
let schoolYearProvider: (() => number | null) | null = null

export function setSchoolYearProvider(fn: (() => number | null) | null): void {
  schoolYearProvider = fn
}

let devCalendarHeaderProvider: (() => Record<string, string>) | null = null

export function setDevCalendarHeaderProvider(fn: (() => Record<string, string>) | null): void {
  devCalendarHeaderProvider = fn
}

/** Liste de préfixes pour lesquels on ne veut JAMAIS injecter school_year_id. */
const SCHOOL_YEAR_BLOCKLIST = [
  '/api/v1/auth',
  '/api/v1/school-years', // gestion des années elle-même + endpoint /current
  '/api/v1/messages',
  '/api/v1/student',
  '/api/v1/parent',
  '/api/v1/users',
  '/api/v1/admin/users',
  '/api/v1/admin/login-logs',
  '/api/v1/admin/settings',
  '/api/v1/health',
]

function shouldInjectSchoolYear(path: string, options: Pick<ApiRequestOptions, 'query' | 'skipSchoolYear' | 'method'>): boolean {
  if (options.skipSchoolYear) return false
  if (options.method && options.method !== 'GET') return false
  if (options.query && Object.prototype.hasOwnProperty.call(options.query, 'school_year_id')) {
    return false
  }
  const normalized = path.startsWith('/') ? path : `/${path}`
  const queryIndex = normalized.indexOf('?')
  if (queryIndex !== -1 && new URLSearchParams(normalized.slice(queryIndex + 1)).has('school_year_id')) {
    return false
  }
  return !SCHOOL_YEAR_BLOCKLIST.some((prefix) => normalized.startsWith(prefix))
}

function buildUrl(path: string, query?: ApiRequestOptions['query']): string {
  const url = path.startsWith('/') ? path : `/${path}`
  const queryIndex = url.indexOf('?')
  const base = queryIndex === -1 ? url : url.slice(0, queryIndex)
  const params = new URLSearchParams(queryIndex === -1 ? '' : url.slice(queryIndex + 1))

  if (!query) {
    const qs = params.toString()
    return qs ? `${base}?${qs}` : base
  }
  for (const [k, v] of Object.entries(query)) {
    if (v === undefined || v === null) continue
    params.set(k, String(v))
  }
  const qs = params.toString()
  return qs ? `${base}?${qs}` : base
}

export function apiUrl(
  path: string,
  options: Pick<ApiRequestOptions, 'query' | 'skipSchoolYear' | 'method'> = {},
): string {
  let query = options.query
  const method = options.method ?? 'GET'
  if (shouldInjectSchoolYear(path, { ...options, method }) && schoolYearProvider) {
    const id = schoolYearProvider()
    if (id !== null) {
      query = { ...(query ?? {}), school_year_id: id }
    }
  }

  return buildUrl(path, query)
}

export async function api<T = unknown>(path: string, options: ApiRequestOptions = {}): Promise<T> {
  const { method = 'GET', body, signal } = options
  const headers = new Headers({
    Accept: 'application/json',
  })
  if (body !== undefined) {
    headers.set('Content-Type', 'application/json')
  }
  const token = getToken()
  if (token) {
    headers.set('Authorization', `Bearer ${token}`)
  }

  if (devCalendarHeaderProvider) {
    for (const [key, value] of Object.entries(devCalendarHeaderProvider())) {
      headers.set(key, value)
    }
  }

  const res = await fetch(apiUrl(path, { ...options, method }), {
    method,
    headers,
    body: body !== undefined ? JSON.stringify(body) : undefined,
    signal,
  })

  if (res.status === 204) {
    return undefined as T
  }

  let payload: unknown = null
  if (res.headers.get('content-type')?.includes('application/json')) {
    payload = await res.json()
  }

  if (!res.ok) {
    if (res.status === 401 && onUnauthenticated) {
      onUnauthenticated()
    }
    const obj = (payload ?? {}) as { message?: string; errors?: Record<string, string[]> }
    throw new ApiError(
      res.status,
      obj.message ?? `Erreur HTTP ${res.status}`,
      obj.errors,
      payload,
    )
  }

  return payload as T
}
