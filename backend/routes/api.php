<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\FamilyAccessController;
use App\Http\Controllers\Api\InviteController;
use App\Http\Controllers\Api\PhotoController;
use App\Http\Controllers\Api\PublicAccessController;
use App\Http\Controllers\Api\PublicPhotoController;
use App\Http\Middleware\EnsurePublicFamilyAccess;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

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
        Route::get('/photos', [PublicPhotoController::class, 'index']);
        Route::get('/photos/anni', [PublicPhotoController::class, 'years']);
        Route::get('/photos/{photo}', [PublicPhotoController::class, 'show'])->whereNumber('photo');
    });
});

// Tutto il resto richiede un token Sanctum: la famiglia è quella dell'utente autenticato.
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    Route::get('/invites', [InviteController::class, 'index']);
    Route::post('/invites', [InviteController::class, 'store']);
    Route::delete('/invites/{invite}', [InviteController::class, 'destroy'])->whereNumber('invite');

    Route::patch('/family/access-mode', [FamilyAccessController::class, 'update']);

    Route::get('/photos/anni', [PhotoController::class, 'years']);
    Route::apiResource('photos', PhotoController::class)->where(['photo' => '[0-9]+']);
});
