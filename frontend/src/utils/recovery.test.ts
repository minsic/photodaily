import { describe, expect, it } from 'vitest'

import { dateFromExif } from './photoDate'
import { afterExclusion, groupByDay, initialChoice, plannedUploads, type DayChoice, type PickedPhoto } from './recovery'
import { requeueFailed, runQueue, type QueueTask } from './uploadQueue'

/** 30 foto su tre settimane (2-22 marzo), qualcuna senza EXIF. */
function thirtyPhotos(): PickedPhoto[] {
  return Array.from({ length: 30 }, (_, i) => ({
    id: `foto-${i}`,
    date: `2026-03-${String(2 + Math.floor((i * 21) / 30)).padStart(2, '0')}`,
    certain: i % 9 !== 0,
  }))
}

describe('dateFromExif', () => {
  it('prende il giorno dalla data di scatto grezza', () => {
    expect(dateFromExif('2024:05:01 23:59:12')).toBe('2024-05-01')
    expect(dateFromExif('2024-05-01T08:00:00')).toBe('2024-05-01')
  })

  it('scarta valori vuoti, azzerati o non validi', () => {
    expect(dateFromExif('0000:00:00 00:00:00')).toBeNull()
    expect(dateFromExif('2024:02:30 10:00:00')).toBeNull()
    expect(dateFromExif(undefined)).toBeNull()
    expect(dateFromExif(1714521600)).toBeNull()
  })
})

describe('recupero di 30 foto su tre settimane', () => {
  const full = new Set(['2026-03-05', '2026-03-12', '2026-03-19'])
  const groups = groupByDay(thirtyPhotos(), full, '2026-03-31')
  const choices = new Map(groups.map((group) => [group.date, initialChoice(group)]))

  it('raggruppa per giorno, dal più vecchio', () => {
    expect(groups).toHaveLength(21)
    expect(groups[0]!.date).toBe('2026-03-02')
    expect(groups.at(-1)!.date).toBe('2026-03-22')
    expect(groups.reduce((sum, group) => sum + group.photos.length, 0)).toBe(30)
  })

  it('per ogni giorno sceglie la prima foto', () => {
    const second = groups.find((group) => group.photos.length > 1)!

    expect(choices.get(second.date)!.chosen).toBe(second.photos[0]!.id)
  })

  it('salta di default i giorni che hanno già una foto', () => {
    const skipped = groups.filter((group) => !choices.get(group.date)!.include).map((group) => group.date)

    expect(skipped).toEqual(['2026-03-05', '2026-03-12', '2026-03-19'])
    expect(plannedUploads(groups, choices)).toHaveLength(18)
  })

  it('un giorno già pieno si può caricare lo stesso, se lo si sceglie', () => {
    const forced = new Map(choices)
    forced.set('2026-03-05', { ...choices.get('2026-03-05')!, include: true })

    expect(plannedUploads(groups, forced)).toHaveLength(19)
  })

  it('porta con sé didascalia e foto scelta', () => {
    const edited = new Map(choices)
    const day = groups[0]!
    edited.set(day.date, { ...choices.get(day.date)!, caption: '  primo giorno 😀 ' })

    expect(plannedUploads(groups, edited)[0]).toEqual({
      date: '2026-03-02',
      photoId: day.photos[0]!.id,
      caption: 'primo giorno 😀',
    })
  })
})

describe('esclusione e date', () => {
  const photos: PickedPhoto[] = [
    { id: 'a', date: '2026-03-10', certain: true },
    { id: 'b', date: '2026-03-10', certain: false },
    { id: 'c', date: '2026-04-02', certain: true },
  ]
  const groups = groupByDay(photos, new Set(), '2026-03-31')
  const day = groups[0]!

  it('escludendo la foto scelta si passa alla successiva', () => {
    const choice = initialChoice(day)

    expect(afterExclusion(day, choice, new Set(['a'])).chosen).toBe('b')
    expect(afterExclusion(day, choice, new Set(['b'])).chosen).toBe('a')
  })

  it('escludendole tutte il giorno non si carica', () => {
    const choice = afterExclusion(day, initialChoice(day), new Set(['a', 'b']))
    const choices = new Map<string, DayChoice>([[day.date, choice]])

    expect(choice.chosen).toBeNull()
    expect(plannedUploads([day], choices)).toEqual([])
  })

  it('un giorno nel futuro (orologio della fotocamera sbagliato) non si carica mai', () => {
    const future = groups[1]!
    const choices = new Map([[future.date, { ...initialChoice(future), include: true }]])

    expect(future.future).toBe(true)
    expect(initialChoice(future).include).toBe(false)
    expect(plannedUploads([future], choices)).toEqual([])
  })
})

describe('runQueue', () => {
  const tasks = (n: number): QueueTask[] =>
    Array.from({ length: n }, (_, i) => ({ id: String(i), status: 'attesa' as const, error: null }))

  it('non supera il numero di invii in parallelo', async () => {
    let running = 0
    let peak = 0
    const list = tasks(10)

    await runQueue(
      list,
      async () => {
        running++
        peak = Math.max(peak, running)
        await new Promise((resolve) => setTimeout(resolve, 5))
        running--
      },
      3,
    )

    expect(peak).toBe(3)
    expect(list.every((task) => task.status === 'fatto')).toBe(true)
  })

  it('se una fallisce le altre vanno avanti, e le fallite si riprovano', async () => {
    const list = tasks(6)
    let attempts = 0

    await runQueue(
      list,
      async (task) => {
        attempts++

        if (task.id === '2' || task.id === '4') {
          throw new Error('rete')
        }
      },
      2,
      () => 'Non caricata.',
    )

    expect(list.filter((task) => task.status === 'fatto')).toHaveLength(4)
    expect(list.filter((task) => task.status === 'errore').map((task) => task.error)).toEqual([
      'Non caricata.',
      'Non caricata.',
    ])

    expect(requeueFailed(list)).toBe(2)
    await runQueue(list, async () => undefined, 2)

    expect(list.every((task) => task.status === 'fatto')).toBe(true)
    expect(attempts).toBe(6)
  })
})
