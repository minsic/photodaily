<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsurePublicFamilyAccess;
use App\Http\Requests\IndexPhotosRequest;
use App\Http\Resources\PhotoResource;
use App\Models\Family;
use App\Models\Photo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Lettura pubblica delle foto di una famiglia (modalità "public" o "password").
 * L'accesso è già stato verificato da EnsurePublicFamilyAccess; qui le bozze
 * sono sempre escluse e non esiste nessuna rotta di scrittura.
 */
class PublicPhotoController extends Controller
{
    public function index(IndexPhotosRequest $request): AnonymousResourceCollection
    {
        $photos = $request->applyFilters($this->publishedPhotos($request))
            ->paginate($request->perPage())
            ->withQueryString();

        return PhotoResource::collection($photos);
    }

    public function years(Request $request): JsonResponse
    {
        return response()->json([
            'data' => Photo::publishedYearsFor($this->family($request)->id),
        ]);
    }

    public function show(Request $request, string $familySlug, int $photo): PhotoResource
    {
        $photo = $this->publishedPhotos($request)->findOrFail($photo);

        return PhotoResource::make($photo)->withOriginal()->withNavigation();
    }

    /**
     * @return Builder<Photo>
     */
    private function publishedPhotos(Request $request): Builder
    {
        return Photo::query()
            ->where('family_id', $this->family($request)->id)
            ->where('is_draft', false);
    }

    private function family(Request $request): Family
    {
        /** @var Family $family */
        $family = $request->attributes->get(EnsurePublicFamilyAccess::FAMILY);

        return $family;
    }
}
