<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Le impostazioni personali di chi è entrato: il nome, la password e i
 * dispositivi collegati. Tocca solo il proprio account.
 */
class ProfileController extends Controller
{
    public function update(Request $request): UserResource
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ], [
            'name.required' => 'Serve un nome.',
        ]);

        $user = $request->user();
        $user->update(['name' => trim($data['name'])]);

        return UserResource::make($user->load('family.plan'));
    }

    /**
     * Con la password attuale; poi si esce da tutti gli altri dispositivi,
     * non da quello da cui la si è cambiata.
     */
    public function updatePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'password_attuale' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ], [
            'password.confirmed' => 'Le due password nuove non coincidono.',
        ]);

        $user = $request->user();

        if (! Hash::check($data['password_attuale'], $user->password)) {
            throw ValidationException::withMessages(['password_attuale' => 'La password attuale non è corretta.']);
        }

        $user->forceFill(['password' => $data['password']])->save();

        return response()->json(['data' => ['sessioni_chiuse' => $this->closeOtherSessions($request)]]);
    }

    /** "Esci dagli altri dispositivi": resta aperta solo questa sessione. */
    public function destroyOtherSessions(Request $request): JsonResponse
    {
        return response()->json(['data' => ['sessioni_chiuse' => $this->closeOtherSessions($request)]]);
    }

    private function closeOtherSessions(Request $request): int
    {
        $user = $request->user();

        return $user->tokens()->whereKeyNot($user->currentAccessToken()->getKey())->delete();
    }
}
