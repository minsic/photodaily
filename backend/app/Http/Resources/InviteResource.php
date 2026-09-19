<?php

namespace App\Http\Resources;

use App\Models\Invite;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Invite
 */
class InviteResource extends JsonResource
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
            'email' => $this->email,
            'stato' => $this->status(),
            'expires_at' => $this->expires_at,
            'accepted_at' => $this->accepted_at,
            'invitato_da' => $this->whenLoaded('inviter', fn () => $this->inviter?->name),
        ];
    }
}
