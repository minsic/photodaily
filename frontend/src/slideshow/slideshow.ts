import type { SequenceFilters, SequenceItem, SequencePage } from '@/api/types'

/** Cosa mostrare, scelto nel pannello del lettore. */
export type SlideshowScope = 'da-qui' | 'mese' | 'anno' | 'speciali' | 'tutto'

/** Lento e Normale in dissolvenza; Crescita a 8 foto al secondo. */
export type SlideshowSpeed = 'lento' | 'normale' | 'crescita'

export const SPEED_DELAY: Record<SlideshowSpeed, number> = {
  lento: 5000,
  normale: 3000,
  crescita: 125,
}

/** Foto precaricate davanti a quella mostrata: in Crescita ne passano 8 al secondo. */
export const BUFFER_AHEAD: Record<SlideshowSpeed, number> = {
  lento: 2,
  normale: 3,
  crescita: 20,
}

/** Filtri dell'endpoint per una scelta del pannello, a partire dalla foto aperta. */
export function scopeFilters(scope: SlideshowScope, date: string): SequenceFilters {
  const [year, month] = [date.slice(0, 4), date.slice(5, 7)]

  switch (scope) {
    case 'da-qui':
      return { da: date }
    case 'mese': {
      const last = new Date(Date.UTC(Number(year), Number(month), 0)).getUTCDate()

      return { da: `${year}-${month}-01`, a: `${year}-${month}-${String(last).padStart(2, '0')}` }
    }
    case 'anno':
      return { da: `${year}-01-01`, a: `${year}-12-31` }
    case 'speciali':
      return { speciali: true }
    default:
      return {}
  }
}

/** Il blocco successivo si chiede quando ne restano meno di così. */
export const REFILL_WHEN_LEFT = 40

/**
 * Le foto dello slideshow, a blocchi. Il blocco successivo arriva prima di
 * finire quello corrente, così gli URL firmati sono sempre freschi e non si
 * resta mai senza foto da mostrare.
 */
export class SlideFeed {
  items: SequenceItem[] = []
  private cursor: string | null = null
  private started = false
  private pending: Promise<void> | null = null
  private readonly fetchPage: (filters: SequenceFilters) => Promise<SequencePage>
  private readonly filters: SequenceFilters

  constructor(fetchPage: (filters: SequenceFilters) => Promise<SequencePage>, filters: SequenceFilters) {
    this.fetchPage = fetchPage
    this.filters = filters
  }

  /** Nessun altro blocco da chiedere. */
  get complete(): boolean {
    return this.started && this.cursor === null
  }

  /** Chiede il blocco successivo se, dalla posizione `index`, ne restano pochi. */
  ensure(index: number): Promise<void> {
    if (this.pending) {
      return this.pending
    }

    if (this.complete || (this.started && this.items.length - index > REFILL_WHEN_LEFT)) {
      return Promise.resolve()
    }

    this.pending = this.fetchPage({ ...this.filters, ...(this.cursor ? { dopo: this.cursor } : {}) })
      .then((page) => {
        this.items.push(...page.data)
        this.cursor = page.next
        this.started = true
      })
      .finally(() => {
        this.pending = null
      })

    return this.pending
  }

  /** Per il ciclo: si riparte da capo con URL nuovi. */
  reset(): void {
    this.items = []
    this.cursor = null
    this.started = false
  }
}

/** URL da mostrare per una foto dello slideshow. */
export function slideSrc(item: SequenceItem): string {
  return item.medium_url ?? item.thumbnail_url
}

/**
 * Immagini scaricate e decodificate prima di mostrarle. Se la prossima non
 * è pronta lo slideshow aspetta (rallenta) invece di mostrare un buco; una
 * che non si carica si salta.
 */
export class ImageBuffer {
  private states = new Map<string, 'loading' | 'ready' | 'error'>()
  private readonly load: (src: string) => Promise<void>

  constructor(load: (src: string) => Promise<void>) {
    this.load = load
  }

  fill(sources: string[]): void {
    for (const src of sources) {
      if (this.states.has(src)) {
        continue
      }

      this.states.set(src, 'loading')
      this.load(src).then(
        () => this.states.has(src) && this.states.set(src, 'ready'),
        () => this.states.has(src) && this.states.set(src, 'error'),
      )
    }
  }

  /** Si può mostrare: pronta, oppure fallita (e allora la si salta). */
  settled(src: string): boolean {
    const state = this.states.get(src)

    return state === 'ready' || state === 'error'
  }

  failed(src: string): boolean {
    return this.states.get(src) === 'error'
  }

  /** Dimentica quelle già passate: il buffer non cresce all'infinito. */
  keepOnly(sources: Set<string>): void {
    for (const src of this.states.keys()) {
      if (!sources.has(src)) {
        this.states.delete(src)
      }
    }
  }

  clear(): void {
    this.states.clear()
  }
}

/** Scarica e decodifica un'immagine senza mostrarla. */
export function decodeImage(src: string): Promise<void> {
  const image = new Image()

  image.decoding = 'async'
  image.src = src

  return image.decode()
}
