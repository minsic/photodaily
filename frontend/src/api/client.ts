const baseUrl = (import.meta.env.VITE_API_URL ?? '/api').replace(/\/+$/, '')

export type ValidationErrors = Record<string, string[]>

export class ApiError extends Error {
  readonly status: number
  readonly errors: ValidationErrors

  constructor(status: number, message: string, errors: ValidationErrors = {}) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.errors = errors
  }

  get isUnauthorized(): boolean {
    return this.status === 401
  }

  get isValidation(): boolean {
    return this.status === 422
  }

  /** Primo errore di validazione del campo, se c'è. */
  field(name: string): string | undefined {
    return this.errors[name]?.[0]
  }
}

type QueryValue = string | number | boolean | null | undefined

interface RequestOptions {
  method?: 'GET' | 'POST' | 'PATCH' | 'DELETE'
  body?: unknown
  query?: Record<string, QueryValue>
  signal?: AbortSignal
}

let readToken: () => string | null = () => null
let handleUnauthorized: () => void = () => {}

/**
 * Collega il client al token dell'utente e alla reazione al 401
 * (impostati in main.ts, per non far dipendere l'API dallo store).
 */
export function configureApi(options: { token: () => string | null; onUnauthorized: () => void }): void {
  readToken = options.token
  handleUnauthorized = options.onUnauthorized
}

function buildUrl(path: string, query?: Record<string, QueryValue>): string {
  const url = new URL(`${baseUrl}${path}`, window.location.origin)

  for (const [key, value] of Object.entries(query ?? {})) {
    if (value !== null && value !== undefined && value !== '') {
      url.searchParams.set(key, String(value))
    }
  }

  return url.toString()
}

export async function api<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const { method = 'GET', body, query, signal } = options
  const token = readToken()
  const isFormData = body instanceof FormData

  const headers: Record<string, string> = { Accept: 'application/json' }

  if (token) {
    headers.Authorization = `Bearer ${token}`
  }

  if (body !== undefined && !isFormData) {
    headers['Content-Type'] = 'application/json'
  }

  let response: Response

  try {
    response = await fetch(buildUrl(path, query), {
      method,
      headers,
      signal,
      body: isFormData ? body : body === undefined ? undefined : JSON.stringify(body),
    })
  } catch (error) {
    if (error instanceof DOMException && error.name === 'AbortError') {
      throw error
    }

    throw new ApiError(0, 'Impossibile contattare il server. Controlla la connessione.')
  }

  if (response.status === 204) {
    return undefined as T
  }

  const payload = await response.json().catch(() => null)

  if (response.ok) {
    return payload as T
  }

  if (response.status === 401) {
    handleUnauthorized()
  }

  throw new ApiError(
    response.status,
    typeof payload?.message === 'string' && payload.message !== ''
      ? payload.message
      : messageForStatus(response.status),
    payload?.errors ?? {},
  )
}

function messageForStatus(status: number): string {
  switch (status) {
    case 401:
      return 'Sessione scaduta: accedi di nuovo.'
    case 403:
      return 'Non hai i permessi per questa operazione.'
    case 404:
      return 'Non trovato.'
    case 413:
      return 'Il file è troppo grande.'
    case 429:
      return 'Troppi tentativi: riprova tra qualche minuto.'
    default:
      return 'Qualcosa è andato storto. Riprova.'
  }
}
