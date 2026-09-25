# PhotoDaily — frontend

SPA Vue 3 (Composition API, TypeScript) su Vite, con Tailwind v4, Pinia, vue-router e PWA installabile.
Sostituisce il vecchio client su vue-cli che leggeva direttamente da Sanity: ora tutti i dati arrivano dall'API in `../backend`, autenticata con token Sanctum.

## Avvio

```sh
npm install
cp .env.example .env   # VITE_API_URL=/api
npm run dev            # http://localhost:5173 (indirizzo principale)
                       # http://giopellino.localhost:5173 (diario della famiglia giopellino)
npm run build          # type-check (vue-tsc) + build in dist/
```

Frontend e API stanno sullo stesso host: in sviluppo il proxy di Vite inoltra `/api` a `http://photodaily.test` e passa l'host originale in `X-Forwarded-Host` (il backend lo accetta perché il suo `.env` ha `TRUSTED_PROXIES=127.0.0.1`). I sottodomini di `localhost` puntano già al proprio computer, senza toccare il file hosts.

## Come è fatto

- `src/api/` — client `fetch` tipizzato (`client.ts`) e chiamate raggruppate per risorsa (`index.ts`). Gli errori diventano `ApiError` con messaggio già pronto e `errors` di validazione per campo; su 401 la sessione viene chiusa e si torna al login.
- `src/stores/` — `auth` (token in `localStorage`, utente, ripristino all'avvio), `photos` (anni, filtri, elenco con cache per filtro), `toasts`.
- `src/views/` — `LoginView`, `TimelineView`, `PhotoView`, `UploadView`, `EditPhotoView`, `SettingsView`, `AcceptInviteView`, `PublicTimelineView`, `PublicPhotoView`, `NotFoundView`.
- `src/components/` — `AppHeader`, `FilterBar`, `TimelineItem`, `PhotoForm`, `ConfirmDialog`, `AppIcon`, `ToastList`, `PwaUpdatePrompt`.
- `src/utils/date.ts` — formattazione italiana con `Intl`; le date `YYYY-MM-DD` si costruiscono come date locali, altrimenti in certi fusi comparirebbe il giorno prima.

## Scelte

- **Timeline per anno.** Il selettore usa `GET /photos/anni` e la timeline carica in un colpo solo l'anno scelto (al massimo 366 foto), come faceva il vecchio sito ma senza scaricare l'intero archivio per calcolare gli anni.
- **Filtri**: anno, solo speciali, bozze. Le bozze restano separate dalle foto pubblicate.
- **Miniature.** La timeline carica solo `thumbnail_url` (400px di lato lungo, circa 10-20 KB l'una); l'originale si scarica solo aprendo la foto, dove la miniatura fa da segnaposto mentre arriva.
- **URL firmati che scadono.** `image_url` vale un'ora: se il browser non riesce più a caricare un'immagine perché la pagina è rimasta aperta a lungo, la foto viene richiesta di nuovo una volta sola per ottenere un URL fresco.
- **Tema chiaro e scuro** dalla palette del vecchio PhotoDaily, via token CSS ridefiniti sotto `prefers-color-scheme`.
- **Sezione impostazioni** (`/impostazioni`, solo admin): invita nuovi membri, elenca e revoca gli inviti, sceglie chi può vedere il diario e mostra il link da condividere.
- **Indirizzo = famiglia.** All'avvio `GET /api/site` dice di quale diario è l'host (store `site`). Sull'indirizzo di una famiglia il login mostra il suo nome e, se il diario è pubblico o con password, chi non ha un account finisce direttamente sul diario in sola lettura. Un sottodominio sconosciuto mostra "Diario non trovato".
- **Diario pubblico** (`/pub/:slug`): timeline e dettaglio in sola lettura, senza alcuna azione di scrittura. In modalità password chiede la password condivisa e conserva il token di sola lettura per quello slug; se la famiglia è privata (o lo slug non esiste) mostra la stessa pagina "non è pubblico", senza distinguere i due casi. Su queste pagine, e su quella di accettazione invito, viene forzato `robots: noindex, nofollow`.
- **PWA**: viene messo in cache solo il guscio dell'app. Le foto no: gli URL firmati cambiano a ogni richiesta, quindi una cache per URL sarebbe inutile.

## Ancora da fare

- Barra di avanzamento durante il caricamento (serve `XMLHttpRequest`: `fetch` non espone il progresso).
