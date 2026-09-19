export type Role = 'admin' | 'member'

export type AccessMode = 'private' | 'password' | 'public'

export interface Plan {
  slug: string
  name: string
  max_photos: number | null
  max_storage_mb: number | null
  price_monthly_cents: number
}

export interface Family {
  id: number
  name: string
  slug: string
  access_mode: AccessMode
  storage_used_mb: number
  photos_count?: number
  plan?: Plan
}

export interface User {
  id: number
  name: string
  email: string
  role: Role
  family?: Family
}

export interface PhotoLink {
  id: number
  data: string
}

export interface Photo {
  id: number
  /** Data della foto, in formato YYYY-MM-DD. */
  data: string
  data_speciale: boolean
  didascalia: string | null
  is_draft: boolean
  /** URL firmato su R2: scade dopo un'ora. */
  image_url: string
  width: number | null
  height: number | null
  uploaded_by?: number | null
  created_at: string
  updated_at: string
  /** Presenti solo sul dettaglio. */
  precedente?: PhotoLink | null
  successiva?: PhotoLink | null
}

export interface YearSummary {
  anno: number
  foto: number
  speciali: number
}

export interface Paginated<T> {
  data: T[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
  }
}

export interface LoginResponse {
  token: string
  user: User
}

export interface PhotoFilters {
  anno?: number | null
  speciali?: boolean
  stato?: 'pubblicate' | 'bozze' | 'tutte'
  ordine?: 'asc' | 'desc'
  per_page?: number
}

export interface PhotoPayload {
  data: string
  didascalia: string | null
  data_speciale: boolean
  is_draft: boolean
}
