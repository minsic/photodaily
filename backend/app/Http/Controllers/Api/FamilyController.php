<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsurePublicFamilyAccess;
use App\Http\Requests\UpdateFamilyRequest;
use App\Http\Resources\FamilyResource;
use App\Models\Family;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FamilyController extends Controller
{
    /**
     * Aggiorna le impostazioni della propria famiglia. Solo admin (FamilyPolicy::update).
     */
    public function update(UpdateFamilyRequest $request): FamilyResource
    {
        $family = $request->user()->family;
        $family->update($request->validated());

        return FamilyResource::make($family->load('plan')->loadCount('photos'));
    }

    /**
     * Profilo del diario in sola lettura (nome e protagonista), dietro gli
     * stessi controlli delle foto: per un diario con password arriva solo
     * dopo lo sblocco, per uno privato non arriva mai.
     */
    public function showPublic(Request $request): JsonResponse
    {
        /** @var Family $family */
        $family = $request->attributes->get(EnsurePublicFamilyAccess::FAMILY);

        return response()->json([
            'data' => [
                'name' => $family->name,
                'protagonist' => $family->protagonist(),
            ],
        ]);
    }
}
