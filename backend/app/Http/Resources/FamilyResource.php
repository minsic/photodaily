<?php

namespace App\Http\Resources;

use App\Models\Family;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Family
 */
class FamilyResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'storage_used_mb' => $this->storage_used_mb,
            'photos_count' => $this->whenCounted('photos'),
            'plan' => $this->whenLoaded('plan', fn () => [
                'slug' => $this->plan->slug,
                'name' => $this->plan->name,
                'max_photos' => $this->plan->max_photos,
                'max_storage_mb' => $this->plan->max_storage_mb,
                'price_monthly_cents' => $this->plan->price_monthly_cents,
            ]),
        ];
    }
}
