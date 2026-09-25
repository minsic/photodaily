<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
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
            'email' => $this->email,
            'role' => $this->role,
            // Promemoria serale: preferenze personali, l'orario è nel fuso della famiglia.
            'promemoria' => [
                'attivo' => (bool) $this->reminder_enabled,
                'orario' => substr((string) ($this->reminder_time ?? '20:30'), 0, 5),
            ],
            'family' => FamilyResource::make($this->whenLoaded('family')),
        ];
    }
}
