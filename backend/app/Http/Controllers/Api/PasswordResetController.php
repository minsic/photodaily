<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveHostFamily;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

/**
 * "Password dimenticata": un link via email, valido un'ora, per sceglierne
 * una nuova. Sull'indirizzo di una famiglia vale solo per i suoi membri,
 * come il login.
 */
class PasswordResetController extends Controller
{
    private const SENT = "Se l'indirizzo ha un account, tra poco arriva un'email con il link per scegliere la nuova password.";

    private const INVALID = 'Il link non è valido o è scaduto: chiedine uno nuovo.';

    /**
     * Stessa risposta che l'account esista o no, così non si scopre quali
     * email sono registrate.
     */
    public function forgot(Request $request): JsonResponse
    {
        $email = mb_strtolower(trim((string) $request->validate(['email' => ['required', 'email', 'max:255']])['email']));
        $user = $this->userFor($request, $email);

        if ($user !== null) {
            // Un link al minuto per account (auth.passwords.users.throttle).
            Password::sendResetLink(['email' => $user->email]);
        }

        return response()->json(['message' => self::SENT], 202);
    }

    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'confirmed', PasswordRule::defaults()],
            'device_name' => ['nullable', 'string', 'max:255'],
        ]);

        $user = $this->userFor($request, mb_strtolower(trim($data['email'])));

        $status = $user === null ? Password::INVALID_TOKEN : Password::reset(
            ['email' => $user->email, 'token' => $data['token'], 'password' => $data['password']],
            function (User $user, string $password) {
                $user->forceFill(['password' => $password])->save();
                // Chi conosceva la vecchia password non resta dentro da nessun dispositivo.
                $user->tokens()->delete();
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['token' => self::INVALID]);
        }

        return response()->json([
            'token' => $user->createToken($data['device_name'] ?? 'spa')->plainTextToken,
            'user' => UserResource::make($user->fresh()->load('family.plan')),
        ]);
    }

    /** L'account, se esiste e appartiene alla famiglia dell'indirizzo (o si è su quello principale). */
    private function userFor(Request $request, string $email): ?User
    {
        $user = User::query()->where('email', $email)->first();
        $hostFamily = ResolveHostFamily::from($request);

        return $user === null || ($hostFamily !== null && $user->family_id !== $hostFamily->id) ? null : $user;
    }
}
