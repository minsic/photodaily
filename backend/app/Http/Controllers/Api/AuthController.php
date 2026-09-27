<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureFamilyActive;
use App\Http\Middleware\ResolveHostFamily;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::query()
            ->where('email', mb_strtolower($request->validated('email')))
            ->first();

        $hostFamily = ResolveHostFamily::from($request);

        // Sull'indirizzo di una famiglia entrano solo i suoi membri; stesso
        // messaggio delle credenziali sbagliate, per non dire che l'account esiste.
        if (! $user
            || ! Hash::check($request->validated('password'), $user->password)
            || ($hostFamily !== null && $user->family_id !== $hostFamily->id)) {
            throw ValidationException::withMessages([
                'email' => 'Credenziali non valide.',
            ]);
        }

        // Solo dopo la password giusta: a chi non la conosce non si dice niente.
        if ($user->family?->isSuspended()) {
            throw ValidationException::withMessages(['email' => EnsureFamilyActive::MESSAGE]);
        }

        return response()->json([
            'token' => $user->createToken($request->validated('device_name') ?? 'spa')->plainTextToken,
            'user' => UserResource::make($user->load('family.plan')),
        ]);
    }

    /**
     * Chiude la sessione corrente e quelle collegate dal passaggio fra
     * photodaily.app e il diario (vedi DiaryHandoff), in tutti e due i versi.
     */
    public function logout(Request $request): Response
    {
        $current = $request->user()->currentAccessToken();
        $origins = array_filter([$current->getKey(), $current->linked_token_id]);

        $request->user()->tokens()
            ->where(fn ($query) => $query->whereIn('id', $origins)->orWhereIn('linked_token_id', $origins))
            ->delete();

        return response()->noContent();
    }

    public function me(Request $request): UserResource
    {
        return UserResource::make($request->user()->load([
            'family' => fn ($query) => $query->with('plan')->withCount('photos'),
        ]));
    }
}
