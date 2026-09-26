import { api } from './client'
import type {
  AccessMode,
  CreatedInvite,
  Family,
  Invite,
  InvitePreview,
  LoginResponse,
  Paginated,
  Photo,
  PhotoFilters,
  PhotoCalendar,
  PhotoPayload,
  Protagonist,
  ReadAccess,
  ReminderPreferences,
  SiteFamily,
  User,
  YearSummary,
} from './types'

/** Famiglia servita dall'host corrente, null sull'indirizzo principale. */
export const site = {
  get() {
    return api<{ data: { family: SiteFamily | null } }>('/site', { token: null }).then(
      (response) => response.data.family,
    )
  },
}

export const auth = {
  login(email: string, password: string) {
    return api<LoginResponse>('/login', {
      method: 'POST',
      body: { email, password, device_name: deviceName() },
    })
  },

  logout() {
    return api<void>('/logout', { method: 'POST' })
  },

  me() {
    return api<{ data: User }>('/me').then((response) => response.data)
  },
}

/** Promemoria serale: iscrizioni Web Push del dispositivo e preferenze personali. */
export const push = {
  config() {
    return api<{ data: { public_key: string | null } }>('/push/config').then((response) => response.data)
  },

  subscribe(subscription: PushSubscription) {
    const json = subscription.toJSON()
    const encoding = (PushManager as unknown as { supportedContentEncodings?: string[] }).supportedContentEncodings

    return api<void>('/push/iscrizioni', {
      method: 'POST',
      body: { endpoint: json.endpoint, keys: json.keys, content_encoding: encoding?.[0] ?? 'aes128gcm' },
    })
  },

  unsubscribe(endpoint: string) {
    return api<void>('/push/iscrizioni', { method: 'DELETE', body: { endpoint } })
  },

  setPreferences(preferences: Partial<ReminderPreferences>) {
    return api<{ data: User }>('/me/promemoria', { method: 'PATCH', body: preferences }).then(
      (response) => response.data,
    )
  },
}

export const photos = {
  list(filters: PhotoFilters = {}) {
    return api<Paginated<Photo>>('/photos', { query: photoQuery(filters) })
  },

  years() {
    return api<{ data: YearSummary[] }>('/photos/anni').then((response) => response.data)
  },

  calendar(anno?: number) {
    return api<{ data: PhotoCalendar }>('/photos/calendario', { query: { anno } }).then(
      (response) => response.data,
    )
  },

  get(id: string) {
    return api<{ data: Photo }>(`/photos/${id}`).then((response) => response.data)
  },

  create(image: File, payload: PhotoPayload) {
    const form = new FormData()

    form.append('image', image)
    form.append('data', payload.data)
    form.append('data_speciale', payload.data_speciale ? '1' : '0')
    form.append('is_draft', payload.is_draft ? '1' : '0')

    if (payload.didascalia) {
      form.append('didascalia', payload.didascalia)
    }

    return api<{ data: Photo }>('/photos', { method: 'POST', body: form }).then((response) => response.data)
  },

  update(id: string, payload: Partial<PhotoPayload>) {
    return api<{ data: Photo }>(`/photos/${id}`, { method: 'PATCH', body: payload }).then(
      (response) => response.data,
    )
  },

  remove(id: string) {
    return api<void>(`/photos/${id}`, { method: 'DELETE' })
  },
}

export const invites = {
  list() {
    return api<{ data: Invite[] }>('/invites').then((response) => response.data)
  },

  create(email: string) {
    return api<{ data: CreatedInvite }>('/invites', { method: 'POST', body: { email } }).then(
      (response) => response.data,
    )
  },

  revoke(id: string) {
    return api<void>(`/invites/${id}`, { method: 'DELETE' })
  },

  /** Pagina pubblica di accettazione: nessuna autenticazione. */
  preview(token: string) {
    return api<{ data: InvitePreview }>(`/invites/${encodeURIComponent(token)}`, { token: null }).then(
      (response) => response.data,
    )
  },

  accept(token: string, payload: { name: string; password: string; password_confirmation: string }) {
    return api<LoginResponse>(`/invites/${encodeURIComponent(token)}/accept`, {
      method: 'POST',
      body: { ...payload, device_name: deviceName() },
      token: null,
    })
  },
}

export const family = {
  update(payload: {
    protagonist_name?: string | null
    protagonist_birthdate?: string | null
    timezone?: string
  }) {
    return api<{ data: Family }>('/family', { method: 'PATCH', body: payload }).then(
      (response) => response.data,
    )
  },

  setAccessMode(accessMode: AccessMode, password?: string) {
    return api<{ data: Family }>('/family/access-mode', {
      method: 'PATCH',
      body: { access_mode: accessMode, ...(password ? { password } : {}) },
    }).then((response) => response.data)
  },
}

/**
 * Diario in sola lettura. `token` è quello ottenuto con la password condivisa
 * (null quando la famiglia è pubblica): non è mai il token dell'utente.
 */
export const publicDiary = {
  unlock(slug: string, password: string) {
    return api<ReadAccess>(`/public/${encodeURIComponent(slug)}/verify-password`, {
      method: 'POST',
      body: { password },
      token: null,
    })
  },

  years(slug: string, token: string | null) {
    return api<{ data: YearSummary[] }>(`/public/${encodeURIComponent(slug)}/photos/anni`, {
      token,
    }).then((response) => response.data)
  },

  profile(slug: string, token: string | null) {
    return api<{ data: { name: string; protagonist: Protagonist | null } }>(
      `/public/${encodeURIComponent(slug)}/profilo`,
      { token },
    ).then((response) => response.data)
  },

  list(slug: string, token: string | null, filters: PhotoFilters = {}) {
    return api<Paginated<Photo>>(`/public/${encodeURIComponent(slug)}/photos`, {
      token,
      query: photoQuery(filters),
    })
  },

  get(slug: string, token: string | null, id: string) {
    return api<{ data: Photo }>(`/public/${encodeURIComponent(slug)}/photos/${id}`, { token }).then(
      (response) => response.data,
    )
  },
}

function photoQuery(filters: PhotoFilters) {
  return {
    anno: filters.anno,
    speciali: filters.speciali ? 1 : undefined,
    stato: filters.stato,
    ordine: filters.ordine,
    page: filters.page,
    per_page: filters.per_page,
  }
}

function deviceName(): string {
  return `${navigator.platform || 'web'} · ${navigator.language}`.slice(0, 255)
}
