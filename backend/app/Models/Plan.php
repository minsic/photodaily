<?php

namespace App\Models;

use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'slug', 'max_photos', 'max_storage_mb', 'price_monthly_cents', 'is_active'])]
class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use HasFactory;

    public static function default(): self
    {
        return static::query()->where('slug', config('photodaily.default_plan'))->firstOrFail();
    }

    /**
     * @return HasMany<Family, $this>
     */
    public function families(): HasMany
    {
        return $this->hasMany(Family::class);
    }

    public function maxStorageBytes(): ?int
    {
        return $this->max_storage_mb === null ? null : $this->max_storage_mb * 1024 * 1024;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'max_photos' => 'integer',
            'max_storage_mb' => 'integer',
            'price_monthly_cents' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
