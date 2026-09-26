/** "1 anno fa", "3 anni fa". */
export function yearsAgoLabel(years: number): string {
  return years === 1 ? 'Un anno fa' : `${years} anni fa`
}

const KEY = 'photodaily.anni-fa.nascosto'

type Storage = Pick<globalThis.Storage, 'getItem' | 'setItem'>

function defaultStorage(): Storage | null {
  try {
    return globalThis.localStorage ?? null
  } catch {
    return null
  }
}

/** Chiusa per oggi su questo dispositivo: domani ricompare. */
export function isHiddenFor(today: string, storage: Storage | null = defaultStorage()): boolean {
  try {
    return storage?.getItem(KEY) === today
  } catch {
    return false
  }
}

export function hideFor(today: string, storage: Storage | null = defaultStorage()): void {
  try {
    storage?.setItem(KEY, today)
  } catch {
    // Solo una comodità: senza, la striscia resta.
  }
}
