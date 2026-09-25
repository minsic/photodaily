/**
 * Recupero dei giorni mancanti: le foto scelte dalla galleria si raggruppano
 * per giorno di scatto e per ogni giorno se ne carica una. Funzioni pure:
 * lo stato vive nella vista, qui solo le regole.
 */

export interface PickedPhoto {
  id: string
  /** YYYY-MM-DD */
  date: string
  /** False se la data viene dal file e non dall'EXIF. */
  certain: boolean
}

export interface DayGroup {
  date: string
  photos: PickedPhoto[]
  /** Il giorno ha già una foto pubblicata: di default si salta. */
  alreadyFull: boolean
  /** Il giorno è dopo oggi (orologio della fotocamera sbagliato): non si può caricare. */
  future: boolean
}

export interface DayChoice {
  /** Foto scelta per il giorno (all'inizio la prima). */
  chosen: string | null
  /** Caricare il giorno? Di default no se ha già una foto o è nel futuro. */
  include: boolean
  caption: string
}

/** Raggruppa per giorno, dal più vecchio; dentro il giorno resta l'ordine di scelta. */
export function groupByDay(photos: PickedPhoto[], fullDays: ReadonlySet<string>, today: string): DayGroup[] {
  const byDay = new Map<string, PickedPhoto[]>()

  for (const photo of photos) {
    byDay.set(photo.date, [...(byDay.get(photo.date) ?? []), photo])
  }

  return [...byDay.entries()]
    .sort(([a], [b]) => a.localeCompare(b))
    .map(([date, items]) => ({ date, photos: items, alreadyFull: fullDays.has(date), future: date > today }))
}

export function initialChoice(group: DayGroup): DayChoice {
  return {
    chosen: group.photos[0]?.id ?? null,
    include: !group.alreadyFull && !group.future,
    caption: '',
  }
}

/**
 * Scelta dopo aver escluso o riammesso una foto: se la scelta corrente è
 * stata esclusa si passa alla prima rimasta; se non ne resta nessuna il
 * giorno non si carica.
 */
export function afterExclusion(group: DayGroup, choice: DayChoice, excluded: ReadonlySet<string>): DayChoice {
  const available = group.photos.filter((photo) => !excluded.has(photo.id))

  if (available.length === 0) {
    return { ...choice, chosen: null }
  }

  return available.some((photo) => photo.id === choice.chosen) ? choice : { ...choice, chosen: available[0]!.id }
}

export interface PlannedUpload {
  date: string
  photoId: string
  caption: string
}

/** Cosa si carica davvero: un giorno incluso, non nel futuro, con una foto scelta. */
export function plannedUploads(groups: DayGroup[], choices: ReadonlyMap<string, DayChoice>): PlannedUpload[] {
  return groups.flatMap((group) => {
    const choice = choices.get(group.date)

    return choice && choice.include && !group.future && choice.chosen
      ? [{ date: group.date, photoId: choice.chosen, caption: choice.caption.trim() }]
      : []
  })
}
