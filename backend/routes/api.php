<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\EmailChangeController;
use App\Http\Controllers\Api\FamilyAccessController;
use App\Http\Controllers\Api\FamilyController;
use App\Http\Controllers\Api\InviteController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\PhotoController;
use App\Http\Controllers\Api\PhotoHeartController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\PublicAccessController;
use App\Http\Controllers\Api\PublicPhotoController;
use App\Http\Controllers\Api\PushController;
use App\Http\Controllers\Api\RegistrationController;
use App\Http\Controllers\Api\SiteController;
use App\Http\Middleware\EnsureFamilyActive;
use App\Http\Middleware\EnsureHostFamilyMember;
use App\Http\Middleware\EnsurePublicFamilyAccess;
use App\Http\Middleware\EnsureSuperAdmin;
use Illuminate\Support\Facades\Route;

// Di quale diario è l'host corrente (vedi ResolveHostFamily).
Route::get('/site', [SiteController::class, 'show'])->middleware('throttle:public');

// Chiamata solo da Caddy prima di chiedere un certificato per un dominio nuovo.
Route::get('/tls/ask', [SiteController::class, 'tlsAsk']);

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

// Nuovo diario dall'indirizzo principale, poi ingresso sul sottodominio creato.
Route::post('/registrati', [RegistrationController::class, 'store'])->middleware('throttle:signup');
Route::post('/entra', [RegistrationController::class, 'handoff'])->middleware('throttle:invites');

Route::middleware('throttle:password-reset')->group(function () {
    Route::post('/password/dimenticata', [PasswordResetController::class, 'forgot']);
    Route::post('/password/nuova', [PasswordResetController::class, 'reset']);
    // Dal link al nuovo indirizzo: basta il token, anche senza essere entrati.
    Route::post('/email/conferma', [EmailChangeController::class, 'confirm']);
});

Route::middleware('throttle:invites')->group(function () {
    Route::get('/invites/{token}', [InviteController::class, 'show']);
    Route::post('/invites/{token}/accept', [InviteController::class, 'accept']);
});

// Sola lettura pubblica, secondo families.access_mode. Nessuna scrittura qui:
// upload, modifica, cancellazione e inviti restano sulle rotte autenticate.
Route::prefix('public/{family_slug}')->group(function () {
    Route::post('/verify-password', [PublicAccessController::class, 'verifyPassword'])
        ->middleware('throttle:public-password');

    Route::middleware(['throttle:public', EnsurePublicFamilyAccess::class])->group(function () {
        Route::get('/profilo', [FamilyController::class, 'showPublic']);
        Route::get('/photos', [PublicPhotoController::class, 'index']);
        Route::get('/photos/anni', [PublicPhotoController::class, 'years']);
        Route::get('/photos/sequenza', [PublicPhotoController::class, 'sequence']);
        Route::get('/photos/mese', [PublicPhotoController::class, 'month']);
        Route::get('/photos/anni-fa', [PublicPhotoController::class, 'onThisDay']);
        Route::get('/photos/calendario', [PublicPhotoController::class, 'calendar']);
        Route::get('/photos/{photo}', [PublicPhotoController::class, 'show'])->whereUlid('photo');
    });
});

// Tutto il resto richiede un token Sanctum: la famiglia è quella dell'utente autenticato.
Route::middleware(['auth:sanctum', EnsureHostFamilyMember::class, EnsureFamilyActive::class])->group(function () {
    // Uscire si può anche con il diario sospeso.
    Route::post('/logout', [AuthController::class, 'logout'])->withoutMiddleware(EnsureFamilyActive::class);
    Route::get('/me', [AuthController::class, 'me']);
    Route::patch('/me/promemoria', [PushController::class, 'updatePreferences']);
    Route::patch('/me', [ProfileController::class, 'update']);
    Route::put('/me/password', [ProfileController::class, 'updatePassword'])->middleware('throttle:login');
    Route::delete('/me/sessioni', [ProfileController::class, 'destroyOtherSessions']);
    Route::post('/me/email', [EmailChangeController::class, 'request'])->middleware('throttle:login');
    Route::delete('/me/email', [EmailChangeController::class, 'cancel']);

    Route::get('/push/config', [PushController::class, 'config']);
    Route::post('/push/iscrizioni', [PushController::class, 'subscribe']);
    Route::delete('/push/iscrizioni', [PushController::class, 'unsubscribe']);

    Route::get('/invites', [InviteController::class, 'index']);
    Route::post('/invites', [InviteController::class, 'store']);
    Route::delete('/invites/{invite}', [InviteController::class, 'destroy'])->whereUlid('invite');

    Route::get('/family/quota', [FamilyController::class, 'quota']);
    Route::patch('/family', [FamilyController::class, 'update']);
    Route::patch('/family/access-mode', [FamilyAccessController::class, 'update']);

    Route::get('/photos/anni', [PhotoController::class, 'years']);
    Route::get('/photos/calendario', [PhotoController::class, 'calendar']);
    Route::get('/photos/sequenza', [PhotoController::class, 'sequence']);
    Route::get('/photos/mese', [PhotoController::class, 'month']);
    Route::get('/photos/anni-fa', [PhotoController::class, 'onThisDay']);
    Route::put('/photos/{photo}/cuore', [PhotoHeartController::class, 'store'])->where('photo', '[0-7][0-9a-hjkmnp-tv-zA-HJKMNP-TV-Z]{25}');
    Route::delete('/photos/{photo}/cuore', [PhotoHeartController::class, 'destroy'])->where('photo', '[0-7][0-9a-hjkmnp-tv-zA-HJKMNP-TV-Z]{25}');
    Route::apiResource('photos', PhotoController::class)->where(['photo' => '[0-7][0-9a-hjkmnp-tv-zA-HJKMNP-TV-Z]{25}']);
});

// Pannello del servizio, solo su photodaily.app e solo per i super-admin.
Route::middleware(['auth:sanctum', EnsureSuperAdmin::class])->prefix('admin')->group(function () {
    Route::get('/famiglie', [AdminController::class, 'families']);
    Route::get('/piani', [AdminController::class, 'plans']);
    Route::patch('/famiglie/{family:slug}', [AdminController::class, 'update']);
});
