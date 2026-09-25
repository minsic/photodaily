<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Photos Disk
    |--------------------------------------------------------------------------
    |
    | Disco (S3-compatibile) su cui vengono salvate le immagini. Le immagini
    | non vengono mai salvate su disco locale: il bucket deve essere privato
    | e i client ricevono solo URL firmati a scadenza.
    |
    */

    'disk' => env('PHOTOS_DISK', 'r2'),

    'url_ttl_minutes' => (int) env('PHOTOS_URL_TTL_MINUTES', 60),

    'max_upload_kb' => (int) env('PHOTOS_MAX_UPLOAD_KB', 20480),

    // Memoria concessa mentre si genera una miniatura: GD lavora su bitmap
    // non compresse, quindi una foto da 12 megapixel ne chiede una sessantina di MB.
    'thumbnail_memory_limit' => env('THUMBNAIL_MEMORY_LIMIT', '512M'),

    /*
    |--------------------------------------------------------------------------
    | Public read-only access
    |--------------------------------------------------------------------------
    |
    | Durata del token di sola lettura rilasciato alle famiglie in modalità
    | "password" (vedi App\Services\FamilyReadTokens).
    |
    */

    'public_token_ttl_days' => (int) env('PUBLIC_TOKEN_TTL_DAYS', 7),

    /*
    |--------------------------------------------------------------------------
    | Plans
    |--------------------------------------------------------------------------
    */

    'default_plan' => env('DEFAULT_PLAN', 'beta'),

    /*
    |--------------------------------------------------------------------------
    | Timezone
    |--------------------------------------------------------------------------
    |
    | Fuso orario con cui si stabilisce che giorno è "oggi" per una famiglia.
    |
    */

    'default_timezone' => env('DEFAULT_FAMILY_TIMEZONE', 'Europe/Rome'),

    /*
    |--------------------------------------------------------------------------
    | Hosts
    |--------------------------------------------------------------------------
    |
    | FRONTEND_URL è l'indirizzo principale del servizio (https://photodaily.app).
    | Ogni famiglia è servita su <slug>.<host principale> e, se lo ha, sul suo
    | dominio proprio; schema e porta sono quelli di FRONTEND_URL. In sviluppo
    | http://localhost:5173 dà http://giopellino.localhost:5173.
    |
    | TRUSTED_PROXIES: IP dei proxy da cui accettare X-Forwarded-Host
    | (in sviluppo 127.0.0.1, per il proxy di Vite; in produzione vuoto).
    |
    */

    'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173'),

    'trusted_proxies' => env('TRUSTED_PROXIES'),

    /*
    |--------------------------------------------------------------------------
    | Invites
    |--------------------------------------------------------------------------
    |
    | Il link di invito punta all'indirizzo della famiglia (Family::url()).
    |
    */

    'invite_path' => env('INVITE_PATH', '/invite/{token}'),

    'invite_ttl_days' => (int) env('INVITE_TTL_DAYS', 7),

];
