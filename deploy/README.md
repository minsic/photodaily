# Deploy di PhotoDaily

Una VPS (OVH VPS-1, Ubuntu 24.04) serve tutto: frontend, API, database.
Le foto stanno su Cloudflare R2, quindi il disco del server contiene solo codice, database e log.

```
Internet ─▶ Caddy :443 ─┬─ /api/*  ─▶ PHP-FPM 8.4 (Laravel) ─▶ MySQL 8
  photodaily.app        │                                    └─▶ R2 (foto)
  <slug>.photodaily.app └─ resto   ─▶ frontend/dist (SPA Vue)
  domini delle famiglie
```

- **Header di sicurezza**: Caddy aggiunge Content-Security-Policy (niente script o stili inline, immagini solo da sé e dal bucket R2), Permissions-Policy, HSTS e affini. Se il frontend comincia a usare un'origine nuova va aggiunta lì; `npm run preview:csp` nel frontend prova la build in locale con gli stessi header.
- **Caddy** chiede da solo i certificati HTTPS al primo accesso a ogni host (on-demand TLS), ma solo se `GET /api/tls/ask` conferma che l'host è `photodaily.app` o di una famiglia.
- **Release**: ogni deploy crea `/var/www/photodaily/releases/<data>`. Il link `current` passa alla nuova release solo quando dipendenze, build e migrazioni sono andate a buon fine; si tengono le ultime 5.
- **Condivisi fra release**: `shared/.env` e `shared/storage` (log, cache, sessioni).
- **Worker della coda**: servizio systemd `photodaily-queue`; lo scheduler di Laravel gira da cron.
- **Backup**: dump del database ogni notte alle 3:30 in `/var/backups/photodaily` (14 giorni), più il backup automatico giornaliero di OVH dell'intera VPS.

## File

| File | Dove finisce sul server |
|---|---|
| `provision.sh` | si lancia una volta, prepara tutto il server |
| `deploy.sh` | `/usr/local/bin/photodaily-deploy` |
| `backup.sh` | `/usr/local/bin/photodaily-backup`, lanciato da `/etc/cron.d/photodaily` |
| `Caddyfile` | `/etc/caddy/Caddyfile` |
| `photodaily-queue.service` | `/etc/systemd/system/` |
| `env.production.example` | `/var/www/photodaily/shared/.env`, con `APP_KEY` e `DB_PASSWORD` generate |

## Prima installazione

1. **DNS** (Cloudflare, proxy disattivato, "nuvola grigia"): record `A` (IPv4) e `AAAA` (IPv6) per `photodaily.app` e per `*.photodaily.app` verso la VPS.
   Per il dominio proprio di una famiglia: `A` (o `CNAME` verso `<slug>.photodaily.app`) per il dominio e per `www.`.

2. **Configurazione del server**, entrando come `ubuntu` (l'utente creato da OVH):
   ```sh
   git clone https://github.com/minsic/photodaily.git ~/photodaily
   sudo ACME_EMAIL=tua@email.it bash ~/photodaily/deploy/provision.sh
   ```
   Lo script crea l'utente `photodaily` con le stesse chiavi SSH di `ubuntu`. Se trova una chiave, disattiva l'accesso con password e quello di root.

3. **Completa `.env`**: `sudo -u photodaily nano /var/www/photodaily/shared/.env` →
   - `R2_*`: bucket `photodaily-prod` in giurisdizione UE (endpoint `https://<account>.eu.r2.cloudflarestorage.com`), con una chiave API limitata a quel bucket, mai quella di sviluppo;
   - `MAIL_*`: login e chiave SMTP di Brevo, poi `MAIL_MAILER=smtp`.

4. **Primo deploy** e avvio del worker:
   ```sh
   sudo -iu photodaily photodaily-deploy
   sudo systemctl start photodaily-queue
   ```

5. **Prima famiglia**:
   ```sh
   cd /var/www/photodaily/current/backend
   sudo -u photodaily php artisan family:create giopellino "Giopellino" --admin-email=... --domain=giopellino.it
   ```

## Dopo aver modificato `.env`

La configurazione è in cache e OPcache non ricontrolla i file: senza questi due comandi PHP-FPM continua a usare i valori vecchi (la riga di comando invece vede subito quelli nuovi, quindi un test con `tinker` può passare mentre il sito sbaglia).
```sh
cd /var/www/photodaily/current/backend
php artisan config:cache && sudo systemctl reload php8.4-fpm && php artisan queue:restart
```
Anche un deploy completo li esegue.

## Deploy successivi

```sh
ssh photodaily@<ip> photodaily-deploy
```

Se il deploy fallisce a metà, il sito resta sulla release precedente e lo script dice quale cartella controllare.

**Tornare indietro** alla release precedente:
```sh
cd /var/www/photodaily
ln -sfn "$(ls -1dt releases/*/ | sed -n 2p)" current.new && mv -Tf current.new current
sudo systemctl reload php8.4-fpm && php current/backend/artisan queue:restart
```
Attenzione: le migrazioni già eseguite non vengono annullate.

## Backup fuori dalla VPS (facoltativo)

`backup.sh` copia il dump anche su un remote rclone chiamato `photodaily-backup`, se esiste. Per usare un bucket R2 dedicato:
```sh
sudo rclone config create photodaily-backup s3 provider=Cloudflare \
    access_key_id=... secret_access_key=... endpoint=https://<ACCOUNT_ID>.r2.cloudflarestorage.com
```

## Controlli utili

```sh
systemctl status caddy php8.4-fpm mysql photodaily-queue
tail -f /var/www/photodaily/shared/storage/logs/laravel-*.log
tail -f /var/log/caddy/photodaily.log
journalctl -u photodaily-queue -f
```
