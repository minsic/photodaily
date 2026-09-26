<?php

namespace App\Models;

use Database\Factories\InviteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['email', 'token', 'invited_by', 'accepted_at', 'expires_at'])]
#[Hidden(['token'])]
class Invite extends Model
{
    /** @use HasFactory<InviteFactory> */
    use HasFactory, HasUlids;

    /**
     * L'ULID è l'identificativo pubblico (URL e API); l'id numerico resta interno.
     *
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Stato leggibile dell'invito, per l'elenco in impostazioni.
     */
    public function status(): string
    {
        return match (true) {
            $this->accepted_at !== null => 'accettato',
            $this->expires_at->isPast() => 'scaduto',
            default => 'pendente',
        };
    }

    /**
     * Invito ancora utilizzabile corrispondente al token in chiaro.
     *
     * @param  Builder<self>  $query
     */
    public function scopePendingWithToken(Builder $query, string $token): void
    {
        $query->where('token', static::hashToken($token))
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now());
    }

    /**
     * @return BelongsTo<Family, $this>
     */
    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'accepted_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
