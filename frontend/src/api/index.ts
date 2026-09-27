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
  PhotoMonth,
  OnThisDay,
  PhotoPayload,
  Protagonist,
  Quota,
  HeartState,
  SequenceFilters,
  SequencePage,
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

  /** Il proprio nome (quello accanto ai cuori e negli inviti). */
  updateProfile(name: string) {
    return api<{ data: User }>('/me', { method: 'PATCH', body: { name } }).then((response) => response.data)
  },

  /** Con la password attuale; chiude le sessioni sugli altri dispositivi. */
  changePassword(payload: { password_attuale: string; password: string; password_confirmation: string }) {
    return api<{ data: { sessioni_chiuse: number } }>('/me/password', { method: 'PUT', body: payload }).then(
      (response) => response.data,
    )
  },

  closeOtherSessions() {
    return api<{ data: { sessioni_chiuse: number } }>('/me/sessioni', { method: 'DELETE' }).then(
      (response) => response.data,
    )
  },

  /** Stessa risposta che l'account esista o no. */
  forgotPassword(email: string) {
    return api<{ message: string }>('/password/dimenticata', { method: 'POST', body: { email }, token: null })
  },

  /** Imposta la password nuova col token dell'email e apre la sessione. */
  resetPassword(payload: { token: string; email: string; password: string; password_confirmation: string }) {
    return api<LoginResponse>('/password/nuova', {
      method: 'POST',
      body: { ...payload, device_name: deviceName() },
      token: null,
    })
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

  /** Mette (true) o toglie (false) il proprio cuore; si può ripetere. */
  heart(id: string, on: boolean) {
    return api<{ data: HeartState }>(`/photos/${id}/cuore`, { method: on ? 'PUT' : 'DELETE' }).then(
      (response) => response.data,
    )
  },

  onThisDay() {
    return api<{ data: OnThisDay }>('/photos/anni-fa').then((response) => response.data)
  },

  month(anno: number, mese: number) {
    return api<{ data: PhotoMonth }>('/photos/mese', { query: { anno, mese } }).then((response) => response.data)
  },

  /** Foto pubblicate in ordine di data, a blocchi da 100: per lo slideshow. */
  sequence(filters: SequenceFilters) {
    return api<SequencePage>('/photos/sequenza', { query: sequenceQuery(filters) })
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
  /** Spazio e foto usati rispetto al piano: si chiede prima di ogni caricamento. */
  quota() {
    return api<{ data: Quota }>('/family/quota').then((response) => response.data)
  },

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

  onThisDay(slug: string, token: string | null) {
    return api<{ data: OnThisDay }>(`/public/${encodeURIComponent(slug)}/photos/anni-fa`, { token }).then(
      (response) => response.data,
    )
  },

  month(slug: string, token: string | null, anno: number, mese: number) {
    return api<{ data: PhotoMonth }>(`/public/${encodeURIComponent(slug)}/photos/mese`, {
      token,
      query: { anno, mese },
    }).then((response) => response.data)
  },

  calendar(slug: string, token: string | null, anno: number) {
    return api<{ data: PhotoCalendar }>(`/public/${encodeURIComponent(slug)}/photos/calendario`, {
      token,
      query: { anno },
    }).then((response) => response.data)
  },

  sequence(slug: string, token: string | null, filters: SequenceFilters) {
    return api<SequencePage>(`/public/${encodeURIComponent(slug)}/photos/sequenza`, {
      token,
      query: sequenceQuery(filters),
    })
  },

  get(slug: string, token: string | null, id: string) {
    return api<{ data: Photo }>(`/public/${encodeURIComponent(slug)}/photos/${id}`, { token }).then(
      (response) => response.data,
    )
  },
}

function sequenceQuery(filters: SequenceFilters) {
  return { da: filters.da, a: filters.a, speciali: filters.speciali ? 1 : undefined, dopo: filters.dopo }
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
