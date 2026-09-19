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

## Multi-tenancy

- Ogni utente appartiene a una famiglia (`users.family_id`). Il login restituisce un token Sanctum e la famiglia si ricava sempre dall'utente del token, mai dal dominio né dal payload.
- `PhotoPolicy` risponde **404** per le foto di altre famiglie, così non se ne rivela l'esistenza. L'elenco filtra per `family_id` e la navigazione precedente/successiva resta nella stessa famiglia.
- I test in `tests/Feature/TenantIsolationTest.php` coprono lettura, modifica, cancellazione, elenco, navigazione e upload tra famiglie diverse.

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
php artisan family:create giopellino "Giopellino" --admin-email=... --admin-name=... --app-url=https://giopellino.it

# Importa un export Sanity (cartella con data.ndjson e images/, oppure il file .ndjson)
php artisan photos:import giopellino /percorso/export --dry-run
php artisan photos:import giopellino /percorso/export
```

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
| POST | `/invites` | Solo admin. `email` → invia l'email di invito e restituisce anche il link (`data.url`). |
| GET | `/invites/{token}` | Pubblica. Email e nome della famiglia, per la pagina di accettazione. |
| POST | `/invites/{token}/accept` | Pubblica. `name`, `password`, `password_confirmation` → crea il membro e restituisce `{ token, user }`. |
| GET | `/photos` | Paginata. Filtri: `anno`, `speciali=1`, `stato=pubblicate\|bozze\|tutte` (default `pubblicate`), `ordine=asc\|desc` (default `desc`), `per_page` (max 500). |
| GET | `/photos/{id}` | Include `precedente` e `successiva` (`{ id, data }` oppure `null`). |
| POST | `/photos` | `multipart/form-data`: `image` (jpg/png/webp, max `PHOTOS_MAX_UPLOAD_KB`), `data` (YYYY-MM-DD), `didascalia?`, `data_speciale?`, `is_draft?`. |
| PATCH | `/photos/{id}` | `data?`, `didascalia?`, `data_speciale?`, `is_draft?` (solo metadati). |
| DELETE | `/photos/{id}` | Cancella la foto e il file su R2. |

Esempio di foto:

```json
{
  "data": {
    "id": 42,
    "data": "2024-05-01",
    "data_speciale": true,
    "didascalia": "Primo giorno al mare",
    "is_draft": false,
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

Il link di invito punta a `{families.app_url || FRONTEND_URL}/invito/{token}` (percorso configurabile con `INVITE_PATH`). Nel database si salva solo l'hash SHA-256 del token.
