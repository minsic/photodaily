<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\IndexPhotosRequest;
use App\Http\Requests\StorePhotoRequest;
use App\Http\Requests\UpdatePhotoRequest;
use App\Http\Resources\PhotoResource;
use App\Models\Photo;
use App\Services\PhotoStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class PhotoController extends Controller
{
    /**
     * Filtri: anno, speciali=1, stato=pubblicate|bozze|tutte, ordine=asc|desc, per_page.
     */
    public function index(IndexPhotosRequest $request): AnonymousResourceCollection
    {
        $stato = $request->input('stato', 'pubblicate');

        $photos = $request->applyFilters(
            Photo::query()
                ->where('family_id', $request->user()->family_id)
                ->when($stato !== 'tutte', fn ($query) => $query->where('is_draft', $stato === 'bozze')),
        )->paginate($request->perPage())->withQueryString();

        return PhotoResource::collection($photos);
    }

    public function show(Photo $photo): PhotoResource
    {
        Gate::authorize('view', $photo);

        return PhotoResource::make($photo)->withNavigation();
    }

    public function store(StorePhotoRequest $request, PhotoStorage $storage): JsonResponse
    {
        $user = $request->user();

        $photo = $storage->create(
            $user->family,
            $request->file('image'),
            $request->safe()->except('image'),
            $user,
        );

        return PhotoResource::make($photo)->response()->setStatusCode(201);
    }

    public function update(UpdatePhotoRequest $request, Photo $photo): PhotoResource
    {
        $photo->update($request->validated());

        return PhotoResource::make($photo);
    }

    public function destroy(Photo $photo, PhotoStorage $storage): Response
    {
        Gate::authorize('delete', $photo);

        $storage->delete($photo);

        return response()->noContent();
    }
}
