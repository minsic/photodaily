<?php

namespace App\Http\Controllers\Api;

use App\Enums\AccessMode;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateAccessModeRequest;
use App\Http\Resources\FamilyResource;

class FamilyAccessController extends Controller
{
    /**
     * Cambia la modalità di accesso in sola lettura della propria famiglia.
     * Solo admin (FamilyPolicy::updateAccessMode).
     */
    public function update(UpdateAccessModeRequest $request): FamilyResource
    {
        $family = $request->user()->family;

        $family->changeAccessMode(
            AccessMode::from($request->validated('access_mode')),
            $request->validated('password'),
        );

        return FamilyResource::make($family->load('plan')->loadCount('photos'));
    }
}
