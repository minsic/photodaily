export type Role = 'admin' | 'member'

export type AccessMode = 'private' | 'password' | 'public'

export interface Plan {
  slug: string
  name: string
  max_photos: number | null
  max_storage_mb: number | null
  price_monthly_cents: number
}

/** Di chi è il diario: serve a scrivere l'età sotto le foto. */
export interface Protagonist {
  name: string | null
  /** YYYY-MM-DD */
  birthdate: string | null
}

/** Quanto dice GET /site della famiglia servita dall'host corrente. */
export interface SiteFamily {
  name: string
  slug: string
  access_mode: AccessMode
  timezone: string
  /** Solo per i diari pubblici: per gli altri lo si chiede dopo l'accesso. */
  protagonist: Protagonist | null
}

export interface Family {
  id: number
  name: string
  slug: string
  custom_domain: string | null
  /** Indirizzo del diario: dominio proprio o <slug>.photodaily.app. */
  url: string
  access_mode: AccessMode
  protagonist: Protagonist | null
  /** Fuso con cui si decide che giorno è "oggi" (es. Europe/Rome). */
  timezone: string
  storage_used_mb: number
  photos_count?: number
  plan?: Plan
}

export interface ReminderPreferences {
  attivo: boolean
  /** HH:MM, nel fuso della famiglia. */
  orario: string
}

export interface User {
  id: number
  name: string
  email: string
  role: Role
  promemoria: ReminderPreferences
  family?: Family
}

export interface PhotoLink {
  /** ULID: l'id numerico resta nel backend. */
  id: string
  data: string
}

export interface Photo {
  /** ULID: l'id numerico resta nel backend. */
  id: string
  /** Data della foto, in formato YYYY-MM-DD. */
  data: string
  data_speciale: boolean
  didascalia: string | null
  is_draft: boolean
  /** Miniatura (lato lungo 400px): sempre presente. */
  thumbnail_url: string
  /** Versione per la timeline (lato lungo 1400px): null finché il server non l'ha generata. */
  medium_url: string | null
  /** Immagine a piena risoluzione: solo sul dettaglio. URL firmato, scade dopo un'ora. */
  image_url?: string
  /** Dimensioni dell'originale, come salvato (senza tener conto della rotazione EXIF). */
  width: number | null
  height: number | null
  /** Dimensioni della versione media, già ruotata: le proporzioni giuste da mostrare. */
  medium_width: number | null
  medium_height: number | null
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

export type InviteStatus = 'pendente' | 'accettato' | 'scaduto'

export interface Invite {
  /** ULID: l'id numerico resta nel backend. */
  id: string
  email: string
  stato: InviteStatus
  expires_at: string
  accepted_at: string | null
  invitato_da?: string | null
}

/** Risposta alla creazione di un invito: include il link da condividere. */
export interface CreatedInvite {
  /** ULID: l'id numerico resta nel backend. */
  id: string
  email: string
  expires_at: string
  url: string
}

export interface InvitePreview {
  email: string
  family: { name: string }
  expires_at: string
}

export interface ReadAccess {
  token: string
  expires_at: string
  family: { name: string; slug: string }
}

export interface PhotoFilters {
  anno?: number | null
  speciali?: boolean
  stato?: 'pubblicate' | 'bozze' | 'tutte'
  ordine?: 'asc' | 'desc'
  page?: number
  per_page?: number
}

export interface PhotoPayload {
  data: string
  didascalia: string | null
  data_speciale: boolean
  is_draft: boolean
}

/** GET /photos/calendario: i giorni di un anno con e senza foto. */
/** Uso del piano. I massimi null vogliono dire illimitato. */
export interface Quota {
  plan: string
  photos: number
  max_photos: number | null
  storage_used_mb: number
  max_storage_mb: number | null
  /** Messaggio già pronto se non si può caricare niente, altrimenti null. */
  blocked: string | null
}

export interface PhotoCalendar {
  anno: number
  /** Oggi nel fuso della famiglia. */
  oggi: string
  /** Primo e ultimo giorno che "dovrebbero" avere una foto (null se nessuno). */
  inizio: string | null
  fine: string | null
  giorni: { data: string; foto_id: string; foto: number; speciale: boolean }[]
  bozze: { data: string; foto_id: string }[]
  vuoti: string[]
  pieni: number
  totali: number
}
