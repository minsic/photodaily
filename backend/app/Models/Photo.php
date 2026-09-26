<?php

namespace App\Models;

use Database\Factories\PhotoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
     * @return HasMany<PhotoHeart, $this>
     */
    public function hearts(): HasMany
    {
        return $this->hasMany(PhotoHeart::class);
    }

    /**
     * Quanti cuori ha la foto (cuori) e, per un membro, se c'è anche il suo
     * (mio_cuore): una sola query per tutta la pagina, non una per foto.
     *
     * @param  Builder<self>  $query
     */
    public function scopeWithHearts(Builder $query, ?User $viewer = null): void
    {
        $query->withCount('hearts as cuori')
            ->when($viewer, fn (Builder $query) => $query->withExists([
                'hearts as mio_cuore' => fn (Builder $hearts) => $hearts->where('user_id', $viewer->id),
            ]));
    }

    /**
     * Chi ha messo il cuore, tranne chi guarda (per lui c'è mio_cuore), dal primo.
     *
     * @return list<string>
     */
    public function heartNamesFor(User $viewer): array
    {
        return $this->hearts()
            ->where('user_id', '!=', $viewer->id)
            ->with('user:id,name')
            ->oldest()
            ->get()
            ->map(fn (PhotoHeart $heart) => $heart->user->name)
            ->values()
            ->all();
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
            ->first(['id', 'ulid', 'data', 'family_id', 'image_path', 'thumbnail_path', 'medium_path', 'medium_width', 'medium_height']);
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
            ->first(['id', 'ulid', 'data', 'family_id', 'image_path', 'thumbnail_path', 'medium_path', 'medium_width', 'medium_height']);
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
