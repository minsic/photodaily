<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\PlanLimitExceededException;
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
     * Spazio e foto usati rispetto al piano: una richiesta leggera, fatta dal
     * frontend prima di ogni caricamento e per il menu. Null = illimitato.
     */
    public function quota(Request $request): JsonResponse
    {
        $family = $request->user()->family->load('plan');
        $plan = $family->plan;
        $photos = $family->photos()->count();
        $usedMb = round($family->storageUsedBytes() / 1024 / 1024, 2);

        $blocked = match (true) {
            $plan->max_photos !== null && $photos >= $plan->max_photos => PlanLimitExceededException::photos($plan)->getMessage(),
            $plan->max_storage_mb !== null && $usedMb >= $plan->max_storage_mb => PlanLimitExceededException::full($plan)->getMessage(),
            default => null,
        };

        return response()->json([
            'data' => [
                'plan' => $plan->name,
                'photos' => $photos,
                'max_photos' => $plan->max_photos,
                'storage_used_mb' => $usedMb,
                'max_storage_mb' => $plan->max_storage_mb,
                // Messaggio da mostrare prima ancora di scegliere le foto.
                'blocked' => $blocked,
            ],
        ]);
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
