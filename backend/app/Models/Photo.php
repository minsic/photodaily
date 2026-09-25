<?php

namespace App\Models;

use Database\Factories\PhotoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * La colonna "data" resta una stringa Y-m-d (nessun cast a Carbon): così il
 * valore salvato è identico su SQLite, MySQL e PostgreSQL e confrontabile
 * come stringa per la navigazione precedente/successiva.
 */
#[Fillable(['sanity_id', 'image_path', 'thumbnail_path', 'medium_path', 'size_bytes', 'thumbnail_bytes', 'medium_bytes', 'width', 'height', 'medium_width', 'medium_height', 'data', 'data_speciale', 'didascalia', 'uploaded_by', 'is_draft'])]
class Photo extends Model
{
    /** @use HasFactory<PhotoFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'data_speciale' => false,
        'is_draft' => false,
    ];

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
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * Anni che contengono foto pubblicate, con i relativi conteggi.
     * Il raggruppamento è in PHP: estrarre l'anno in SQL richiede funzioni
     * diverse su SQLite, MySQL e PostgreSQL.
     *
     * @return Collection<int, array{anno: int, foto: int, speciali: int}>
     */
    public static function publishedYearsFor(int $familyId): Collection
    {
        return static::query()
            ->where('family_id', $familyId)
            ->where('is_draft', false)
            ->get(['data', 'data_speciale'])
            ->groupBy(fn (self $photo) => substr((string) $photo->data, 0, 4))
            ->map(fn ($photos, string $anno) => [
                'anno' => (int) $anno,
                'foto' => $photos->count(),
                'speciali' => $photos->where('data_speciale', true)->count(),
            ])
            ->sortByDesc('anno')
            ->values();
    }

    /**
     * Foto più recente fra quelle che precedono questa (stessa famiglia, stesso stato bozza).
     */
    public function previous(): ?self
    {
        return $this->siblings()
            ->where(fn (Builder $query) => $query
                ->where('data', '<', $this->data)
                ->orWhere(fn (Builder $query) => $query->where('data', $this->data)->where('id', '<', $this->id)))
            ->orderByDesc('data')
            ->orderByDesc('id')
            ->first(['id', 'data']);
    }

    /**
     * Foto meno recente fra quelle che seguono questa (stessa famiglia, stesso stato bozza).
     */
    public function next(): ?self
    {
        return $this->siblings()
            ->where(fn (Builder $query) => $query
                ->where('data', '>', $this->data)
                ->orWhere(fn (Builder $query) => $query->where('data', $this->data)->where('id', '>', $this->id)))
            ->orderBy('data')
            ->orderBy('id')
            ->first(['id', 'data']);
    }

    /**
     * @return Builder<self>
     */
    private function siblings(): Builder
    {
        return static::query()
            ->where('family_id', $this->family_id)
            ->where('is_draft', $this->is_draft);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'thumbnail_bytes' => 'integer',
            'medium_bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'medium_width' => 'integer',
            'medium_height' => 'integer',
            'data_speciale' => 'boolean',
            'is_draft' => 'boolean',
        ];
    }
}
