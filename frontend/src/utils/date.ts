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

export function todayIso(): string {
  const now = new Date()

  return [
    now.getFullYear(),
    String(now.getMonth() + 1).padStart(2, '0'),
    String(now.getDate()).padStart(2, '0'),
  ].join('-')
}
