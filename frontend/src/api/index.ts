import { api } from './client'
import type {
  LoginResponse,
  Paginated,
  Photo,
  PhotoFilters,
  PhotoPayload,
  User,
  YearSummary,
} from './types'

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

export const photos = {
  list(filters: PhotoFilters = {}) {
    return api<Paginated<Photo>>('/photos', {
      query: {
        anno: filters.anno,
        speciali: filters.speciali ? 1 : undefined,
        stato: filters.stato,
        ordine: filters.ordine,
        per_page: filters.per_page,
      },
    })
  },

  years() {
    return api<{ data: YearSummary[] }>('/photos/anni').then((response) => response.data)
  },

  get(id: number) {
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

  update(id: number, payload: Partial<PhotoPayload>) {
    return api<{ data: Photo }>(`/photos/${id}`, { method: 'PATCH', body: payload }).then(
      (response) => response.data,
    )
  },

  remove(id: number) {
    return api<void>(`/photos/${id}`, { method: 'DELETE' })
  },
}

function deviceName(): string {
  return `${navigator.platform || 'web'} · ${navigator.language}`.slice(0, 255)
}
