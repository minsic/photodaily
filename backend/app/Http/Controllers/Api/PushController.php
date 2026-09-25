<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Promemoria serale: iscrizioni Web Push del dispositivo e preferenze
 * dell'utente. Tutto riguarda solo chi fa la richiesta.
 */
class PushController extends Controller
{
    /** Chiave pubblica VAPID per l'iscrizione nel browser; null se il server non è configurato. */
    public function config(): JsonResponse
    {
        return response()->json(['data' => ['public_key' => config('webpush.vapid.public_key') ?: null]]);
    }

    public function subscribe(Request $request): Response
    {
        $data = $request->validate([
            'endpoint' => ['required', 'url:https', 'max:1024'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'content_encoding' => ['nullable', 'in:aesgcm,aes128gcm'],
        ]);

        // Se l'endpoint era di un altro utente (stesso browser, account diverso)
        // passa a questo: il pacchetto lo riassegna.
        $request->user()->updatePushSubscription(
            $data['endpoint'],
            $data['keys']['p256dh'],
            $data['keys']['auth'],
            $data['content_encoding'] ?? 'aes128gcm',
        );

        return response()->noContent();
    }

    public function unsubscribe(Request $request): Response
    {
        $data = $request->validate(['endpoint' => ['required', 'string', 'max:1024']]);

        $request->user()->deletePushSubscription($data['endpoint']);

        return response()->noContent();
    }

    public function updatePreferences(Request $request): UserResource
    {
        $data = $request->validate([
            'attivo' => ['sometimes', 'boolean'],
            'orario' => ['sometimes', 'date_format:H:i'],
        ], [
            'orario.date_format' => "L'orario deve essere nel formato 20:30.",
        ]);

        $user = $request->user();
        $user->forceFill(array_filter([
            'reminder_enabled' => $data['attivo'] ?? null,
            'reminder_time' => isset($data['orario']) ? $data['orario'].':00' : null,
        ], fn ($value) => $value !== null))->save();

        return UserResource::make($user->load('family.plan'));
    }
}
