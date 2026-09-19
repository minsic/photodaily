# PhotoDaily — frontend

SPA Vue 3 (Composition API, TypeScript) su Vite, con Tailwind v4, Pinia, vue-router e PWA installabile.
Sostituisce il vecchio client su vue-cli che leggeva direttamente da Sanity: ora tutti i dati arrivano dall'API in `../backend`, autenticata con token Sanctum.

## Avvio

```sh
npm install
cp .env.example .env   # VITE_API_URL=http://photodaily.test/api
npm run dev            # http://localhost:5173
npm run build          # type-check (vue-tsc) + build in dist/
```

L'origine del dev server deve comparire in `CORS_ALLOWED_ORIGINS` nel `.env` del backend.

## Come è fatto

- `src/api/` — client `fetch` tipizzato (`client.ts`) e chiamate raggruppate per risorsa (`index.ts`). Gli errori diventano `ApiError` con messaggio già pronto e `errors` di validazione per campo; su 401 la sessione viene chiusa e si torna al login.
- `src/stores/` — `auth` (token in `localStorage`, utente, ripristino all'avvio), `photos` (anni, filtri, elenco con cache per filtro), `toasts`.
- `src/views/` — `LoginView`, `TimelineView`, `PhotoView`, `UploadView`, `EditPhotoView`, `NotFoundView`.
- `src/components/` — `AppHeader`, `FilterBar`, `TimelineItem`, `PhotoForm`, `ConfirmDialog`, `AppIcon`, `ToastList`, `PwaUpdatePrompt`.
- `src/utils/date.ts` — formattazione italiana con `Intl`; le date `YYYY-MM-DD` si costruiscono come date locali, altrimenti in certi fusi comparirebbe il giorno prima.

## Scelte

- **Timeline per anno.** Il selettore usa `GET /photos/anni` e la timeline carica in un colpo solo l'anno scelto (al massimo 366 foto), come faceva il vecchio sito ma senza scaricare l'intero archivio per calcolare gli anni.
- **Filtri**: anno, solo speciali, bozze. Le bozze restano separate dalle foto pubblicate.
- **URL firmati che scadono.** `image_url` vale un'ora: se il browser non riesce più a caricare un'immagine perché la pagina è rimasta aperta a lungo, la foto viene richiesta di nuovo una volta sola per ottenere un URL fresco.
- **Tema chiaro e scuro** dalla palette del vecchio PhotoDaily, via token CSS ridefiniti sotto `prefers-color-scheme`.
- **PWA**: viene messo in cache solo il guscio dell'app. Le foto no: gli URL firmati cambiano a ogni richiesta, quindi una cache per URL sarebbe inutile.

## Ancora da fare

- Immagini ridimensionate: la timeline scarica gli originali (anche più di 2 MB l'uno), mentre il vecchio sito usava le miniature della CDN di Sanity.
- Interfaccia per inviti, modalità di accesso della famiglia e vista pubblica.
- Barra di avanzamento durante il caricamento (serve `XMLHttpRequest`: `fetch` non espone il progresso).
