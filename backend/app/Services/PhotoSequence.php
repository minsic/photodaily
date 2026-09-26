<?php

namespace App\Services;

use App\Models\Photo;
use Illuminate\Database\Eloquent\Builder;

/**
 * Elenco leggero delle foto pubblicate in ordine di data, per lo slideshow:
 * solo id, data e URL delle versioni ridotte, a blocchi da 100. Il blocco
 * successivo si chiede col cursore (ULID dell'ultima foto ricevuta), così
 * gli URL firmati arrivano poco prima di servire e non scadono.
 */
class PhotoSequence
{
    public const PAGE_SIZE = 100;

    public function __construct(private readonly PhotoStorage $storage) {}

    /**
     * @param  Builder<Photo>  $photos  le foto già ristrette alla famiglia
     * @param  array{da?: string|null, a?: string|null, speciali?: bool, dopo?: string|null}  $filters
     * @return array{data: list<array<string, mixed>>, next: string|null}
     */
    public function page(Builder $photos, array $filters): array
    {
        $query = $photos->clone()
            ->where('is_draft', false)
            ->when($filters['da'] ?? null, fn (Builder $query, string $from) => $query->where('data', '>=', $from))
            ->when($filters['a'] ?? null, fn (Builder $query, string $to) => $query->where('data', '<=', $to))
            ->when($filters['speciali'] ?? false, fn (Builder $query) => $query->where('data_speciale', true));

        if (($filters['dopo'] ?? null) !== null) {
            $last = $photos->clone()->where('ulid', $filters['dopo'])->first(['id', 'data']);

            // Un cursore che non appartiene alla famiglia non apre niente.
            if ($last === null) {
                return ['data' => [], 'next' => null];
            }

            $query->where(fn (Builder $query) => $query
                ->where('data', '>', $last->data)
                ->orWhere(fn (Builder $query) => $query->where('data', $last->data)->where('id', '>', $last->id)));
        }

        $page = $query->orderBy('data')->orderBy('id')->limit(self::PAGE_SIZE + 1)->get();
        $more = $page->count() > self::PAGE_SIZE;
        $page = $page->take(self::PAGE_SIZE);

        return [
            'data' => $page->map(fn (Photo $photo) => [
                'id' => $photo->ulid,
                'data' => $photo->data,
                'data_speciale' => $photo->data_speciale,
                'medium_url' => $this->storage->mediumUrl($photo),
                'thumbnail_url' => $this->storage->thumbnailUrl($photo),
                'medium_width' => $photo->medium_width,
                'medium_height' => $photo->medium_height,
            ])->values()->all(),
            'next' => $more ? $page->last()->ulid : null,
        ];
    }
}
