<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Photo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Il cuore di un membro della famiglia su una foto: PUT lo mette, DELETE lo
 * toglie. Tutti e due si possono ripetere senza effetti in più. Le foto di
 * altre famiglie rispondono 404, come ovunque.
 */
class PhotoHeartController extends Controller
{
    public function store(Request $request, Photo $photo): JsonResponse
    {
        Gate::authorize('view', $photo);

        // createOrFirst regge anche due tocchi quasi contemporanei (indice unico).
        $photo->hearts()->createOrFirst(['user_id' => $request->user()->id]);

        return $this->state($request, $photo);
    }

    public function destroy(Request $request, Photo $photo): JsonResponse
    {
        Gate::authorize('view', $photo);

        $photo->hearts()->where('user_id', $request->user()->id)->delete();

        return $this->state($request, $photo);
    }

    private function state(Request $request, Photo $photo): JsonResponse
    {
        return response()->json([
            'data' => [
                'cuori' => $photo->hearts()->count(),
                'mio_cuore' => $photo->hearts()->where('user_id', $request->user()->id)->exists(),
                'cuori_da' => $photo->heartNamesFor($request->user()),
            ],
        ]);
    }
}
