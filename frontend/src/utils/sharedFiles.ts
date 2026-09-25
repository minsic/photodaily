/**
 * Foto condivise dalla galleria (Web Share Target): il service worker le
 * riceve con una POST su /condividi e le parcheggia qui, in IndexedDB;
 * l'app le riprende da /carica?condiviso=1, anche dopo un login.
 *
 * Usato sia dal service worker sia dalla pagina: solo API standard, niente
 * dipendenze e nessuna chiamata all'API del server.
 */

const DB_NAME = 'photodaily-condivisi'
const STORE = 'files'
const KEY = 'in-attesa'

function open(): Promise<IDBDatabase> {
  return new Promise((resolve, reject) => {
    const request = indexedDB.open(DB_NAME, 1)

    request.onupgradeneeded = () => request.result.createObjectStore(STORE)
    request.onsuccess = () => resolve(request.result)
    request.onerror = () => reject(request.error)
  })
}

function run<T>(mode: IDBTransactionMode, action: (store: IDBObjectStore) => IDBRequest<T>): Promise<T> {
  return open().then(
    (db) =>
      new Promise<T>((resolve, reject) => {
        const transaction = db.transaction(STORE, mode)
        const request = action(transaction.objectStore(STORE))

        transaction.oncomplete = () => {
          db.close()
          resolve(request.result)
        }
        transaction.onerror = () => {
          db.close()
          reject(transaction.error)
        }
      }),
  )
}

/** Sostituisce le foto in attesa con queste (l'ultima condivisione vince). */
export async function saveSharedFiles(files: File[]): Promise<void> {
  await run('readwrite', (store) => store.put(files, KEY))
}

/** Restituisce le foto in attesa e le dimentica. */
export async function takeSharedFiles(): Promise<File[]> {
  try {
    const files = await run<File[] | undefined>('readonly', (store) => store.get(KEY))

    await run('readwrite', (store) => store.delete(KEY))

    return (files ?? []).filter((file) => file instanceof Blob)
  } catch {
    return []
  }
}
