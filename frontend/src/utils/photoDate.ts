import { isIsoDate, todayIso } from '@/utils/date'

export interface PhotoDate {
  /** YYYY-MM-DD */
  date: string
  /** False se non c'era la data di scatto e si è usata la data del file. */
  certain: boolean
}

/**
 * Data da un valore EXIF grezzo ("2024:05:01 18:30:12", senza fuso: è l'ora
 * locale dell'apparecchio, quindi il giorno è proprio quello scritto).
 */
export function dateFromExif(value: unknown): string | null {
  if (typeof value !== 'string') {
    return null
  }

  const match = /^(\d{4})[:-](\d{2})[:-](\d{2})/.exec(value.trim())
  const date = match ? `${match[1]}-${match[2]}-${match[3]}` : null

  return date && isIsoDate(date) && !date.startsWith('0000') ? date : null
}

/**
 * Giorno di scatto di una foto: DateTimeOriginal dell'EXIF (letto con la
 * versione leggera di exifr, caricata solo qui), altrimenti la data del file
 * nel fuso della famiglia, segnalata come incerta.
 */
export async function readPhotoDate(file: File, timeZone: string): Promise<PhotoDate> {
  try {
    const { default: exifr } = await import('exifr/dist/lite.esm.mjs')
    // Nella versione lite `pick` (globale o per blocco) va in errore: si leggono
    // IFD0 ed EXIF interi, che sono pochi byte all'inizio del file.
    const tags = await exifr.parse(file, { gps: false, interop: false, ifd1: false, reviveValues: false })
    const date = dateFromExif(tags?.DateTimeOriginal) ?? dateFromExif(tags?.CreateDate)

    if (date) {
      return { date, certain: true }
    }
  } catch {
    // File senza EXIF leggibile: si ripiega sulla data del file.
  }

  return { date: todayIso(timeZone, new Date(file.lastModified || Date.now())), certain: false }
}
