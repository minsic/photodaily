/// <reference lib="webworker" />
/**
 * Service worker di PhotoDaily (vite-plugin-pwa, strategia injectManifest).
 *
 * - Precache del guscio dell'app e fallback su index.html per le navigazioni,
 *   come prima: nessuna cache a runtime, né per /api né per le immagini di R2
 *   (gli URL firmati cambiano a ogni richiesta).
 * - Web Share Target: riceve le foto condivise dalla galleria con una POST su
 *   /condividi, le parcheggia in IndexedDB e manda l'app su /carica.
 *
 * Il service worker non fa mai chiamate all'API autenticata: non conosce il
 * token dell'utente e non deve conoscerlo.
 */
import { clientsClaim } from 'workbox-core'
import { cleanupOutdatedCaches, createHandlerBoundToURL, precacheAndRoute } from 'workbox-precaching'
import { NavigationRoute, registerRoute } from 'workbox-routing'

import { saveSharedFiles } from './utils/sharedFiles'

declare let self: ServiceWorkerGlobalScope

// registerType "autoUpdate": la versione nuova prende subito il controllo.
self.skipWaiting()
clientsClaim()

precacheAndRoute(self.__WB_MANIFEST)
cleanupOutdatedCaches()

registerRoute(new NavigationRoute(createHandlerBoundToURL('index.html'), { denylist: [/^\/api\//] }))

self.addEventListener('fetch', (event) => {
  const url = new URL(event.request.url)

  if (event.request.method === 'POST' && url.origin === self.location.origin && url.pathname === '/condividi') {
    event.respondWith(receiveShare(event.request))
  }
})

async function receiveShare(request: Request): Promise<Response> {
  try {
    const form = await request.formData()
    const files = form
      .getAll('photos')
      .filter((entry): entry is File => entry instanceof File && entry.type.startsWith('image/'))

    await saveSharedFiles(files)
  } catch {
    // Anche se qualcosa va storto si apre l'app: al peggio non trova le foto.
  }

  // 303: dopo una POST il browser apre la pagina con una GET.
  return Response.redirect('/carica?condiviso=1', 303)
}
