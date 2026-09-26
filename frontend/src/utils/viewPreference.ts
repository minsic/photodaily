/** Vista scelta per ultima su questo dispositivo: si riparte da lì. */
export type DiaryView = 'timeline' | 'mese'

const KEY = 'photodaily.vista'

type Storage = Pick<globalThis.Storage, 'getItem' | 'setItem'>

function defaultStorage(): Storage | null {
  try {
    return globalThis.localStorage ?? null
  } catch {
    return null
  }
}

/** In navigazione privata, o con i dati del sito bloccati, localStorage può lanciare. */
export function readViewPreference(storage: Storage | null = defaultStorage()): DiaryView {
  try {
    return storage?.getItem(KEY) === 'mese' ? 'mese' : 'timeline'
  } catch {
    return 'timeline'
  }
}

export function saveViewPreference(view: DiaryView, storage: Storage | null = defaultStorage()): void {
  try {
    storage?.setItem(KEY, view)
  } catch {
    // Solo una comodità: senza, si riparte dalla timeline.
  }
}
