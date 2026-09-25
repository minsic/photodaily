# PhotoDaily API

Backend Laravel 13 multi-tenant per PhotoDaily, il diario fotografico di famiglia (una foto al giorno).
Sostituisce il vecchio backend Sanity pubblico: ogni richiesta è autenticata e ogni utente vede solo le foto della propria famiglia.

## Setup locale (Herd)

```sh
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed   # crea la famiglia "giopellino" con admin@giopellino.test / password
php artisan test
```

Il sito è collegato a Herd come `http://photodaily.test` (le API rispondono su `http://photodaily.test/api`).

Le immagini vanno **solo** su R2, anche in locale: per caricare foto servono le variabili `R2_*` in `.env` (conviene usare un bucket di sviluppo separato). Nei test il disco è sostituito da `Storage::fake('r2')`.

### Cloudflare R2

1. Crea un bucket **privato** (niente dominio pubblico e niente `r2.dev`).
2. Crea un token API R2 con permesso "Object Read & Write" limitato al bucket.
3. Compila `R2_ACCESS_KEY_ID`, `R2_SECRET_ACCESS_KEY`, `R2_BUCKET`, `R2_ENDPOINT` (`https://<ACCOUNT_ID>.r2.cloudflarestorage.com`).

I client ricevono solo URL firmati che scadono dopo `PHOTOS_URL_TTL_MINUTES` minuti.

### Miniature

Ogni foto ha, accanto all'originale, una miniatura da 400px di lato lungo (WebP se il driver la supporta, altrimenti JPEG, qualità 80) salvata in `families/{id}/photos/thumbs/`. Sull'archivio giopellino pesa circa l'1% dell'originale.

- Viene generata al caricamento, con la libreria immagini di Laravel (`intervention/image`, driver GD), applicando l'orientamento EXIF.
- Gli elenchi restituiscono **solo** `thumbnail_url`; il dettaglio restituisce anche `image_url` a piena risoluzione.
- Finché una foto non ha la miniatura, `thumbnail_url` ripiega sull'originale, così nulla si rompe durante il recupero dello storico.
- Le miniature rientrano nel conteggio di `families.storage_used_mb`.

Durante la generazione il limite di memoria di PHP viene alzato a `THUMBNAIL_MEMORY_LIMIT` (512 MB di default): GD lavora su bitmap non compresse, e una foto da 16 megapixel ne occupa circa 64 MB, oltre i 128 MB tipici di `php.ini`.

In produzione `upload_max_filesize` e `post_max_size` devono stare sopra `PHOTOS_MAX_UPLOAD_KB` (che vale 20 MB): `post_max_size` comprende anche gli altri campi del form, quindi conviene tenerlo un po' più alto del limite delle immagini.

## Multi-tenancy

- Ogni utente appartiene a una famiglia (`users.family_id`). Il login restituisce un token Sanctum e la famiglia si ricava sempre dall'utente del token, mai dal dominio né dal payload.
- `PhotoPolicy` risponde **404** per le foto di altre famiglie, così non se ne rivela l'esistenza. L'elenco filtra per `family_id` e la navigazione precedente/successiva resta nella stessa famiglia.
- I test in `tests/Feature/TenantIsolationTest.php` coprono lettura, modifica, cancellazione, elenco, navigazione e upload tra famiglie diverse.

### Indirizzi delle famiglie

- `FRONTEND_URL` è l'indirizzo principale del servizio (in produzione `https://photodaily.app`). Ogni famiglia è servita su `<slug>.photodaily.app` e, se ha `families.custom_domain`, anche sul suo dominio (es. `giopellino.it`, con o senza `www.`). Schema e porta vengono da `FRONTEND_URL`: in sviluppo `http://giopellino.localhost:5173`.
- Frontend e API stanno sullo stesso host (`/api`), così il middleware `ResolveHostFamily` riconosce la famiglia dall'host della richiesta. Serve solo a restringere, non a scegliere i dati:
  - `GET /api/site` dice al frontend di quale diario è l'host (`family: null` sull'host principale, 404 su host sconosciuti);
  - sull'host di una famiglia il login accetta solo i suoi membri, le rotte autenticate rispondono 403 a token di altre famiglie e gli inviti di altre famiglie danno 404.
- `GET /api/tls/ask?domain=...` risponde 200 solo per l'host principale e per gli host delle famiglie: lo usa Caddy prima di chiedere un certificato (on-demand TLS).
- Gli slug sono etichette DNS (minuscole, cifre, trattini) ed escludono quelli riservati (`Family::RESERVED_SLUGS`: `www`, `api`, `admin`...).
- In sviluppo il proxy di Vite inoltra `/api` a Herd e passa l'host originale in `X-Forwarded-Host`: per questo `.env` locale ha `TRUSTED_PROXIES=127.0.0.1`. In produzione lasciarlo vuoto.

## Accesso in sola lettura (`families.access_mode`)

Ogni famiglia sceglie come esporre le proprie foto in lettura. La scrittura (upload, modifica, cancellazione, inviti) resta **sempre** riservata ai membri autenticati, in ogni modalità.

| `access_mode` | Lettura pubblica |
| --- | --- |
| `private` (default) | Nessuna: le rotte pubbliche rispondono 404, esattamente come per una famiglia inesistente. |
| `password` | Serve la password condivisa, scambiata con un token di sola lettura. |
| `public` | Chiunque abbia il link legge le foto. |

Le bozze (`is_draft`) non sono mai visibili sulle rotte pubbliche, qualunque query string venga passata.

Il token di sola lettura è **stateless**, senza tabella di revoca: è una stringa cifrata con la `APP_KEY` (AES-256 con HMAC) che contiene id famiglia, scadenza e un'impronta dell'hash della password corrente. Cambiare la password condivisa, o uscire dalla modalità `password` (che azzera l'hash), cambia l'impronta e invalida di colpo tutti i token già emessi. Rispetto alla data di cambio password non soffre della granularità al secondo e copre anche la semplice rotazione della password. Ruotare la `APP_KEY` invalida anch'essa tutti i token.

Il token non è un token Sanctum e non è accettato dalle rotte autenticate: per scrivere serve sempre un account vero.

## Piani e limiti

- Tabella `plans` (`max_photos`, `max_storage_mb`, `price_monthly_cents`, `is_active`), dove `null` vuol dire nessun limite.
- Il piano `beta` (senza limiti) viene creato dalla migration ed è il default di ogni nuova famiglia (`DEFAULT_PLAN`).
- `PhotoStorage` è l'unico punto di scrittura delle immagini, per l'API e per l'import. Blocca l'upload se si superano `max_photos` o `max_storage_mb`, prima con un controllo anticipato e poi con un controllo definitivo in transazione con lock sulla famiglia. L'errore è un 403 con messaggio leggibile:

  ```json
  { "message": "Hai raggiunto il limite di 100 foto previsto dal piano \"Free\". ...", "code": "plan_limit_exceeded", "limit": "max_photos", "plan": "free" }
  ```
- Ogni foto salva la dimensione reale del file (`photos.size_bytes`). A ogni upload o cancellazione `families.storage_used_mb` viene ricalcolato dalla somma dei byte, quindi non accumula errori di arrotondamento.
- Non c'è nessuna integrazione di pagamento: esistono solo la struttura dati e l'applicazione dei limiti.

## Comandi Artisan

```sh
# Crea una famiglia con il suo primo admin (la password viene chiesta, oppure generata con --no-interaction)
php artisan family:create giopellino "Giopellino" --admin-email=... --admin-name=... --domain=giopellino.it

# Importa un export Sanity (cartella con data.ndjson e images/, oppure il file .ndjson)
php artisan photos:import giopellino /percorso/export --dry-run
php artisan photos:import giopellino /percorso/export

# Genera le miniature mancanti delle foto già caricate (circa 1 secondo a foto)
php artisan photos:generate-thumbnails giopellino --limit=3   # prova
php artisan photos:generate-thumbnails                        # tutte le famiglie
php artisan photos:generate-thumbnails giopellino --force     # rigenera anche quelle presenti

# Genera la versione media (lato lungo 1400px, WebP) usata dalla timeline
php artisan photos:generate-medium --family=giopellino --dry-run   # quante foto elaborerebbe
php artisan photos:generate-medium --family=giopellino             # solo quelle che non l'hanno
php artisan photos:generate-medium --force                          # rigenera tutto, tutte le famiglie
```

`photos:generate-thumbnails` salta le foto che hanno già una miniatura, quindi si può rilanciare dopo un'interruzione senza creare doppioni. Elenca le foto che non è riuscito a elaborare e in quel caso termina con exit code 1.

L'import:
- legge solo i documenti `_type: "photo"`;
- `_id` con prefisso `drafts.` diventa `is_draft = true`, e `sanity_id` viene salvato senza il prefisso. Se esiste anche la versione pubblicata, vince quella;
- appiattisce `dida` (Portable Text) concatenando i `children[].text` di ogni blocco, un blocco per riga;
- risolve `immagine._sanityAsset` (`image@file://./images/<hash>-<w>x<h>.jpg`) nella cartella dell'export e lo carica su R2 come `families/{id}/photos/<hash>-<w>x<h>.jpg`;
- è idempotente: se lo si rilancia aggiorna i metadati delle foto già importate senza duplicarle;
- verifica i limiti del piano **prima** di caricare qualunque file;
- elenca i documenti scartati (data non valida, immagine mancante) e in quel caso termina con exit code 1.

## API

Tutte le rotte hanno il prefisso `/api`, rispondono in JSON e, salvo dove indicato, richiedono `Authorization: Bearer <token>`.

| Metodo | Rotta | Note |
| --- | --- | --- |
| POST | `/login` | `email`, `password`, `device_name?` → `{ token, user }`. Limite di 5 tentativi al minuto. |
| POST | `/logout` | Revoca il token corrente. |
| GET | `/me` | Utente, famiglia, piano, `photos_count`, `storage_used_mb`. |
| GET | `/invites` | Solo admin. Inviti della propria famiglia con `stato` (pendente/accettato/scaduto) e scadenza. |
| POST | `/invites` | Solo admin. `email` → invia l'email di invito e restituisce anche il link (`data.url`). |
| DELETE | `/invites/{id}` | Solo admin. Revoca un invito non ancora accettato (422 se già accettato, 404 se di un'altra famiglia). |
| GET | `/invites/{token}` | Pubblica. Email e nome della famiglia, per la pagina di accettazione. |
| POST | `/invites/{token}/accept` | Pubblica. `name`, `password`, `password_confirmation` → crea il membro e restituisce `{ token, user }`. |
| GET | `/photos` | Paginata, solo `thumbnail_url`. Filtri: `anno`, `speciali=1`, `stato=pubblicate\|bozze\|tutte` (default `pubblicate`), `ordine=asc\|desc` (default `desc`), `per_page` (max 500). |
| GET | `/photos/anni` | Anni con foto pubblicate e relativi conteggi, per il selettore della timeline. |
| GET | `/photos/{id}` | Miniatura e originale, più `precedente` e `successiva` (`{ id, data }` oppure `null`). |
| POST | `/photos` | `multipart/form-data`: `image` (jpg/png/webp, max `PHOTOS_MAX_UPLOAD_KB`), `data` (YYYY-MM-DD), `didascalia?`, `data_speciale?`, `is_draft?`. |
| PATCH | `/photos/{id}` | `data?`, `didascalia?`, `data_speciale?`, `is_draft?` (solo metadati). |
| DELETE | `/photos/{id}` | Cancella la foto e il file su R2. |
| PATCH | `/family/access-mode` | Solo admin. `access_mode` (`private\|password\|public`), `password` (obbligatoria e min 8 caratteri con `password`). |

Rotte pubbliche, senza autenticazione Sanctum, soggette a `families.access_mode`:

| Metodo | Rotta | Note |
| --- | --- | --- |
| POST | `/public/{family_slug}/verify-password` | Solo in modalità `password` (altrimenti 404). `password` → `{ token, expires_at }`, token valido 7 giorni (`PUBLIC_TOKEN_TTL_DAYS`). Max 5 tentativi al minuto per IP. |
| GET | `/public/{family_slug}/photos` | Stessi filtri (`anno`, `speciali`, `ordine`, `per_page`), solo miniature, bozze sempre escluse. |
| GET | `/public/{family_slug}/photos/anni` | Anni con foto pubblicate, per il selettore della vista pubblica. |
| GET | `/public/{family_slug}/photos/{id}` | Con `precedente` e `successiva`. |

In modalità `password` il token va passato come `Authorization: Bearer <token>` (consigliato) oppure `?access_token=<token>`; senza token la risposta è 401. Le foto pubbliche usano gli stessi `image_url` firmati a scadenza, ma non espongono `uploaded_by`.

Esempio di foto:

```json
{
  "data": {
    "id": 42,
    "data": "2024-05-01",
    "data_speciale": true,
    "didascalia": "Primo giorno al mare",
    "is_draft": false,
    "thumbnail_url": "https://<bucket>.<account>.r2.cloudflarestorage.com/...&X-Amz-Signature=...",
    "image_url": "https://<bucket>.<account>.r2.cloudflarestorage.com/...&X-Amz-Signature=...",
    "width": 1080,
    "height": 1350,
    "uploaded_by": 3,
    "created_at": "...",
    "updated_at": "...",
    "precedente": { "id": 41, "data": "2024-04-30" },
    "successiva": null
  }
}
```

Il link di invito punta all'indirizzo della famiglia (`Family::url()`, vedi sotto) seguito da `/invite/{token}` (percorso configurabile con `INVITE_PATH`). Nel database si salva solo l'hash SHA-256 del token.
