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
            // Vede il pannello del servizio su photodaily.app/admin.
            'super_admin' => (bool) $this->is_super_admin,
            // Promemoria serale: preferenze personali, l'orario è nel fuso della famiglia.
            // Nuova email in attesa di conferma (col link mandato a quell'indirizzo).
            'email_in_attesa' => $this->pending_email !== null && $this->pending_email_expires_at?->isFuture()
                ? $this->pending_email
                : null,
            'promemoria' => [
                'attivo' => (bool) $this->reminder_enabled,
                'orario' => substr((string) ($this->reminder_time ?? '20:30'), 0, 5),
                // Riepilogo della settimana via email, la domenica sera.
                'riepilogo' => (bool) ($this->weekly_digest_enabled ?? true),
            ],
            'family' => FamilyResource::make($this->whenLoaded('family')),
        ];
    }
}
