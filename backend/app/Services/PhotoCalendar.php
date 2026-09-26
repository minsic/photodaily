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
    public function __construct(private readonly PhotoStorage $storage) {}

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
    public function forYear(Family $family, int $year, bool $withDrafts = true): array
    {
        $today = $family->today();
        [$from, $to] = [sprintf('%04d-01-01', $year), sprintf('%04d-12-31', $year)];

        $photos = Photo::query()
            ->where('family_id', $family->id)
            ->whereBetween('data', [$from, $to])
            // Chi guarda da fuori (diario pubblico) non vede le bozze.
            ->when(! $withDrafts, fn ($query) => $query->where('is_draft', false))
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
     * Un mese per la vista Mese: per ogni giorno la foto da aprire (la più
     * recente fra le pubblicate, altrimenti una bozza) con la miniatura, e
     * quanti giorni hanno la foto su quanti ne sono passati.
     *
     * @return array{
     *     anno: int,
     *     mese: int,
     *     oggi: string,
     *     inizio_diario: string|null,
     *     giorni: list<array{data: string, id: string, thumbnail_url: string, foto: int, bozze: int, speciale: bool}>,
     *     pieni: int,
     *     totali: int
     * }
     */
    public function forMonth(Family $family, int $year, int $month, bool $withDrafts = true): array
    {
        $today = $family->today();
        $from = sprintf('%04d-%02d-01', $year, $month);
        $to = CarbonImmutable::parse($from)->endOfMonth()->toDateString();

        $photos = Photo::query()
            ->where('family_id', $family->id)
            ->whereBetween('data', [$from, $to])
            ->when(! $withDrafts, fn ($query) => $query->where('is_draft', false))
            ->orderBy('data')
            ->orderByDesc('id')
            ->get(['id', 'ulid', 'family_id', 'data', 'is_draft', 'data_speciale', 'image_path', 'thumbnail_path']);

        $days = $photos->groupBy(fn (Photo $photo) => $this->day($photo->data))
            ->map(function ($group, string $day) {
                $published = $group->where('is_draft', false);
                $shown = $published->first() ?? $group->first();

                return [
                    'data' => $day,
                    'id' => $shown->ulid,
                    'thumbnail_url' => $this->storage->thumbnailUrl($shown),
                    'foto' => $published->count(),
                    'bozze' => $group->count() - $published->count(),
                    'speciale' => $published->contains('data_speciale', true),
                ];
            });

        [$start, $end] = $this->range($family, $from, min($to, $today));
        $total = 0;
        $full = 0;

        if ($start !== null) {
            foreach (CarbonPeriod::create($start, $end) as $date) {
                $total++;
                $full += ($days->get($date->toDateString())['foto'] ?? 0) > 0 ? 1 : 0;
            }
        }

        return [
            'anno' => $year,
            'mese' => $month,
            'oggi' => $today,
            // Il primo giorno del diario: la vista Mese non scorre più indietro.
            'inizio_diario' => $family->protagonist()['birthdate'] ?? $this->firstPhotoDay($family),
            'giorni' => $days->values()->all(),
            'pieni' => $full,
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
