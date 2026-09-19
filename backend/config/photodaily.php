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
    | Invites
    |--------------------------------------------------------------------------
    |
    | Il link di invito punta al frontend della famiglia (families.app_url) o,
    | se non impostato, a FRONTEND_URL.
    |
    */

    'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173'),

    'invite_path' => env('INVITE_PATH', '/invito/{token}'),

    'invite_ttl_days' => (int) env('INVITE_TTL_DAYS', 7),

];
