/**
 * Gli URL delle immagini sono firmati dal server e scadono dopo un'ora
 * (PHOTOS_URL_TTL_MINUTES). Oltre questa età i dati caricati vanno
 * considerati vecchi: le immagini non ancora scaricate darebbero 403.
 */
export const SIGNED_URLS_MAX_AGE_MS = 50 * 60 * 1000

export function signedUrlsAreStale(loadedAt: number | null): boolean {
  return loadedAt !== null && Date.now() - loadedAt > SIGNED_URLS_MAX_AGE_MS
}

/**
 * Se un'immagine non si carica si chiedono URL nuovi, ma al massimo una volta
 * per scadenza: un'immagine davvero irraggiungibile riceverebbe ogni volta
 * una firma diversa, e rinnovare a ogni errore diventerebbe un ciclo.
 */
export function canRetrySignedUrl(lastRetryAt: number | null): boolean {
  return lastRetryAt === null || Date.now() - lastRetryAt > SIGNED_URLS_MAX_AGE_MS
}
