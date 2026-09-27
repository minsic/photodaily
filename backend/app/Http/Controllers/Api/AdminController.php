<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Family;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Pannello del servizio (solo super-admin, vedi EnsureSuperAdmin): tutti i
 * diari con piano e spazio, cambio piano, sospensione. Mai le foto.
 */
class AdminController extends Controller
{
    public function families(): JsonResponse
    {
        $families = Family::query()
            ->with(['plan', 'users' => fn ($query) => $query->where('role', Role::Admin)->orderBy('id')])
            ->withCount(['photos', 'users'])
            ->withMax('photos', 'created_at')
            ->latest('id')
            ->get();

        return response()->json([
            'data' => $families->map(fn (Family $family) => $this->familyData($family))->all(),
        ]);
    }

    public function plans(): JsonResponse
    {
        return response()->json([
            'data' => Plan::query()->orderBy('id')->get()->map(fn (Plan $plan) => [
                'slug' => $plan->slug,
                'name' => $plan->name,
                'max_photos' => $plan->max_photos,
                'max_storage_mb' => $plan->max_storage_mb,
                'is_active' => $plan->is_active,
            ])->all(),
        ]);
    }

    public function update(Request $request, Family $family): JsonResponse
    {
        $validated = $request->validate([
            'plan' => ['sometimes', 'string', Rule::exists('plans', 'slug')],
            'sospesa' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('plan', $validated)) {
            $family->plan()->associate(Plan::query()->where('slug', $validated['plan'])->firstOrFail());
        }

        if (array_key_exists('sospesa', $validated)) {
            // Sospendendo il proprio diario si chiuderebbe fuori anche dal pannello.
            if ($validated['sospesa'] && $family->id === $request->user()->family_id) {
                throw ValidationException::withMessages(['sospesa' => 'Non puoi sospendere il tuo diario.']);
            }

            $family->suspended_at = $validated['sospesa'] ? ($family->suspended_at ?? now()) : null;
        }

        $family->save();

        $family->load(['plan', 'users' => fn ($query) => $query->where('role', Role::Admin)->orderBy('id')])
            ->loadCount(['photos', 'users'])
            ->loadMax('photos', 'created_at');

        return response()->json(['data' => $this->familyData($family)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function familyData(Family $family): array
    {
        return [
            'slug' => $family->slug,
            'name' => $family->name,
            'url' => $family->url(),
            'access_mode' => $family->access_mode,
            'plan' => $family->plan?->slug,
            'photos_count' => $family->photos_count,
            'users_count' => $family->users_count,
            'admins' => $family->users->map(fn (User $user) => $user->email)->all(),
            'storage_used_mb' => $family->storage_used_mb,
            'last_upload_at' => $family->photos_max_created_at,
            'created_at' => $family->created_at,
            'suspended_at' => $family->suspended_at,
        ];
    }
}
