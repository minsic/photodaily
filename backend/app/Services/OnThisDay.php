<?php

namespace App\Services;

use App\Models\Family;
use App\Models\Photo;
use Carbon\CarbonImmutable;

/**
 * "Un anno fa oggi": le foto pubblicate nello stesso giorno (mese e giorno)
 * degli anni passati, una per anno, con quante ce n'erano. "Oggi" è quello
 * della famiglia, nel suo fuso orario.
 */
class OnThisDay
{
    public function __construct(private readonly PhotoStorage $storage) {}

    /**
     * @return array{oggi: string, anni: list<array{anni: int, data: string, id: string, foto: int, speciale: bool, medium_url: string|null, thumbnail_url: string, medium_width: int|null, medium_height: int|null}>}
     */
    public function for(Family $family): array
    {
        $today = CarbonImmutable::parse($family->today());
        $first = Photo::query()->where('family_id', $family->id)->where('is_draft', false)->min('data');

        if ($first === null) {
            return ['oggi' => $today->toDateString(), 'anni' => []];
        }

        // Le date esatte degli anni passati: si confrontano come valori, così
        // la query usa l'indice su data ed è uguale su SQLite e MySQL. Il 29
        // febbraio esiste solo negli anni bisestili.
        $dates = [];

        for ($year = $today->year - 1; $year >= (int) substr((string) $first, 0, 4); $year--) {
            if (checkdate($today->month, $today->day, $year)) {
                $dates[$today->year - $year] = sprintf('%04d-%02d-%02d', $year, $today->month, $today->day);
            }
        }

        $photos = Photo::query()
            ->where('family_id', $family->id)
            ->where('is_draft', false)
            ->whereIn('data', array_values($dates))
            ->orderByDesc('id')
            ->get()
            ->groupBy(fn (Photo $photo) => CarbonImmutable::parse((string) $photo->data)->toDateString());

        $years = [];

        foreach ($dates as $ago => $date) {
            $group = $photos->get($date);

            if ($group === null) {
                continue;
            }

            // La speciale, se c'è, altrimenti la più recente di quel giorno.
            $photo = $group->firstWhere('data_speciale', true) ?? $group->first();

            $years[] = [
                'anni' => $ago,
                'data' => $date,
                'id' => $photo->ulid,
                'foto' => $group->count(),
                'speciale' => $group->contains('data_speciale', true),
                'medium_url' => $this->storage->mediumUrl($photo),
                'thumbnail_url' => $this->storage->thumbnailUrl($photo),
                'medium_width' => $photo->medium_width,
                'medium_height' => $photo->medium_height,
            ];
        }

        return ['oggi' => $today->toDateString(), 'anni' => $years];
    }
}
