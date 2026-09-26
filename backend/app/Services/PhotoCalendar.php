<?php

namespace App\Services;

use App\Models\Family;
use App\Models\Photo;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;

/**
 * I giorni di un anno per il calendario: quali hanno una foto, quali sono
 * ancora vuoti. I giorni si contano dalla nascita del protagonista (o dalla
 * prima foto, se la data di nascita manca) fino a oggi nel fuso della famiglia.
 */
class PhotoCalendar
{
    /**
     * @return array{
     *     anno: int,
     *     oggi: string,
     *     inizio: string|null,
     *     fine: string|null,
     *     giorni: list<array{data: string, foto_id: string, foto: int, speciale: bool}>,
     *     bozze: list<array{data: string, foto_id: string}>,
     *     vuoti: list<string>,
     *     pieni: int,
     *     totali: int
     * }
     */
    public function forYear(Family $family, int $year): array
    {
        $today = $family->today();
        [$from, $to] = [sprintf('%04d-01-01', $year), sprintf('%04d-12-31', $year)];

        $photos = Photo::query()
            ->where('family_id', $family->id)
            ->whereBetween('data', [$from, $to])
            ->orderBy('data')
            ->orderByDesc('id')
            ->get(['id', 'ulid', 'data', 'is_draft', 'data_speciale']);

        // Per ogni giorno si apre la foto più recente; si conta quante ce ne sono.
        $days = $photos->where('is_draft', false)->groupBy(fn (Photo $photo) => $this->day($photo->data))
            ->map(fn ($group, string $day) => [
                'data' => $day,
                'foto_id' => $group->first()->ulid,
                'foto' => $group->count(),
                'speciale' => $group->contains('data_speciale', true),
            ]);

        $drafts = $photos->where('is_draft', true)->groupBy(fn (Photo $photo) => $this->day($photo->data))
            ->map(fn ($group, string $day) => ['data' => $day, 'foto_id' => $group->first()->ulid]);

        [$start, $end] = $this->range($family, $from, min($to, $today));
        $empty = [];
        $total = 0;

        if ($start !== null) {
            foreach (CarbonPeriod::create($start, $end) as $date) {
                $total++;

                if (! $days->has($date->toDateString())) {
                    $empty[] = $date->toDateString();
                }
            }
        }

        return [
            'anno' => $year,
            'oggi' => $today,
            'inizio' => $start,
            'fine' => $start === null ? null : $end,
            'giorni' => $days->values()->all(),
            'bozze' => $drafts->values()->all(),
            'vuoti' => $empty,
            'pieni' => $total - count($empty),
            'totali' => $total,
        ];
    }

    /**
     * Intervallo dei giorni che "dovrebbero" avere una foto, dentro l'anno.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function range(Family $family, string $yearStart, string $end): array
    {
        $first = $family->protagonist()['birthdate'] ?? $this->firstPhotoDay($family);

        if ($first === null) {
            return [null, null];
        }

        $start = max($yearStart, $first);

        return $start > $end ? [null, null] : [$start, $end];
    }

    private function firstPhotoDay(Family $family): ?string
    {
        $first = Photo::query()->where('family_id', $family->id)->where('is_draft', false)->min('data');

        return $first === null ? null : $this->day($first);
    }

    /** La colonna è una date: su SQLite può tornare con l'ora, su MySQL no. */
    private function day(mixed $value): string
    {
        return CarbonImmutable::parse((string) $value)->toDateString();
    }
}
