/**
 * Le date delle foto sono stringhe YYYY-MM-DD senza fuso orario: si
 * costruiscono come date locali, altrimenti new Date('2024-05-12') le
 * interpreta a mezzanotte UTC e in certi fusi mostra il giorno prima.
 */
export function parseDate(iso: string): Date {
  const [year, month, day] = iso.split('-').map(Number)

  return new Date(year, (month ?? 1) - 1, day ?? 1)
}

const longFormatter = new Intl.DateTimeFormat('it-IT', {
  weekday: 'long',
  day: 'numeric',
  month: 'long',
  year: 'numeric',
})

const shortFormatter = new Intl.DateTimeFormat('it-IT', {
  day: 'numeric',
  month: 'short',
  year: 'numeric',
})

const dayFormatter = new Intl.DateTimeFormat('it-IT', {
  weekday: 'short',
  day: 'numeric',
  month: 'long',
})

export function formatLongDate(iso: string): string {
  return longFormatter.format(parseDate(iso))
}

export function formatShortDate(iso: string): string {
  return shortFormatter.format(parseDate(iso))
}

/** Giorno e mese, senza anno: nella timeline l'anno è già nel filtro. */
export function formatDayAndMonth(iso: string): string {
  return dayFormatter.format(parseDate(iso))
}

export function yearOf(iso: string): number {
  return parseDate(iso).getFullYear()
}

/** Fuso predefinito delle famiglie (families.timezone). */
export const DEFAULT_TIMEZONE = 'Europe/Rome'

/**
 * Data di oggi (YYYY-MM-DD) in un fuso orario: quello della famiglia, non
 * quello del telefono. Il formato en-CA è già anno-mese-giorno.
 */
export function todayIso(timeZone: string = DEFAULT_TIMEZONE, now: Date = new Date()): string {
  try {
    return new Intl.DateTimeFormat('en-CA', { timeZone, year: 'numeric', month: '2-digit', day: '2-digit' }).format(now)
  } catch {
    // Fuso non riconosciuto dal browser: meglio il predefinito che un errore.
    return new Intl.DateTimeFormat('en-CA', {
      timeZone: DEFAULT_TIMEZONE,
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
    }).format(now)
  }
}

/** Una stringa è una data YYYY-MM-DD valida? */
export function isIsoDate(value: unknown): value is string {
  if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(value)) {
    return false
  }

  const [year, month, day] = value.split('-').map(Number)
  const date = new Date(Date.UTC(year, month - 1, day))

  return date.getUTCFullYear() === year && date.getUTCMonth() === month - 1 && date.getUTCDate() === day
}
