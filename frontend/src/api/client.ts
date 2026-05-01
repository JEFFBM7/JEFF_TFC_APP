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
}

function buildUrl(path: string, query?: ApiRequestOptions['query']): string {
  const url = path.startsWith('/') ? path : `/${path}`
  if (!query) return url
  const params = new URLSearchParams()
  for (const [k, v] of Object.entries(query)) {
    if (v === undefined || v === null) continue
    params.set(k, String(v))
  }
  const qs = params.toString()
  return qs ? `${url}?${qs}` : url
}

export async function api<T = unknown>(path: string, options: ApiRequestOptions = {}): Promise<T> {
  const { method = 'GET', body, query, signal } = options
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

  const res = await fetch(buildUrl(path, query), {
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
