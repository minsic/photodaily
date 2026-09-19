<?php

namespace App\Http\Controllers\Api;

use App\Enums\AccessMode;
use App\Http\Controllers\Controller;
use App\Http\Requests\VerifyFamilyPasswordRequest;
use App\Models\Family;
use App\Services\FamilyReadTokens;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;

class PublicAccessController extends Controller
{
    /**
     * Scambia la password condivisa con un token di sola lettura.
     *
     * Risponde 404 identico se la famiglia non esiste o non è in modalità
     * "password", così chi indovina uno slug non scopre come è configurata.
     */
    public function verifyPassword(VerifyFamilyPasswordRequest $request, string $familySlug, FamilyReadTokens $tokens): JsonResponse
    {
        $family = Family::query()
            ->where('slug', $familySlug)
            ->where('access_mode', AccessMode::Password)
            ->first();

        abort_if($family === null, 404);

        if (! Hash::check($request->validated('password'), $family->access_password_hash)) {
            abort(401, 'Password non corretta.');
        }

        [$token, $expiresAt] = $tokens->issue($family);

        return response()->json([
            'token' => $token,
            'expires_at' => $expiresAt,
            'family' => ['name' => $family->name, 'slug' => $family->slug],
        ]);
    }
}
