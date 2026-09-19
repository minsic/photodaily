<?php

namespace App\Models;

use App\Enums\AccessMode;
use App\Exceptions\PlanLimitExceededException;
use Database\Factories\FamilyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;

#[Fillable(['name', 'slug', 'plan_id', 'app_url'])]
#[Hidden(['access_password_hash'])]
class Family extends Model
{
    /** @use HasFactory<FamilyFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'access_mode' => AccessMode::Private->value,
    ];

    protected static function booted(): void
    {
        static::creating(function (Family $family) {
            $family->plan_id ??= Plan::default()->id;
        });
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * @return HasMany<Photo, $this>
     */
    public function photos(): HasMany
    {
        return $this->hasMany(Photo::class);
    }

    /**
     * @return HasMany<Invite, $this>
     */
    public function invites(): HasMany
    {
        return $this->hasMany(Invite::class);
    }

    /**
     * Blocca il salvataggio se aggiungere $photos foto per $bytes byte
     * supererebbe i limiti del piano della famiglia.
     *
     * @throws PlanLimitExceededException
     */
    public function ensureCanStore(int $bytes, int $photos = 1): void
    {
        $plan = $this->plan;

        if ($photos > 0 && $plan->max_photos !== null && $this->photos()->count() + $photos > $plan->max_photos) {
            throw PlanLimitExceededException::photos($plan);
        }

        if ($bytes > 0 && $plan->max_storage_mb !== null) {
            $used = $this->storageUsedBytes();

            if ($used + $bytes > $plan->maxStorageBytes()) {
                throw PlanLimitExceededException::storage($plan, $used, $bytes);
            }
        }
    }

    public function storageUsedBytes(): int
    {
        return (int) $this->photos()->sum('size_bytes');
    }

    /**
     * Ricalcola storage_used_mb dalla dimensione reale dei file salvati.
     */
    public function refreshStorageUsage(): void
    {
        $this->forceFill([
            'storage_used_mb' => round($this->storageUsedBytes() / 1024 / 1024, 2),
        ])->save();
    }

    /**
     * Cambia la modalità di accesso in sola lettura. Uscire dalla modalità
     * "password" azzera l'hash, e questo invalida i token già emessi.
     */
    public function changeAccessMode(AccessMode $mode, ?string $password = null): void
    {
        if ($mode === AccessMode::Password && blank($password)) {
            throw new InvalidArgumentException('La modalità "password" richiede una password condivisa.');
        }

        $this->forceFill([
            'access_mode' => $mode,
            'access_password_hash' => $mode === AccessMode::Password ? Hash::make($password) : null,
        ])->save();
    }

    public function inviteUrl(string $token): string
    {
        $base = rtrim($this->app_url ?: config('photodaily.frontend_url'), '/');

        return $base.str_replace('{token}', $token, config('photodaily.invite_path'));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'storage_used_mb' => 'float',
            'access_mode' => AccessMode::class,
        ];
    }
}
