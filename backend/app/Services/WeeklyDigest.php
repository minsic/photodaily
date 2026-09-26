<?php

namespace App\Services;

use App\Models\Family;
use App\Models\Photo;
use App\Models\PhotoHeart;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;

/**
 * Il riepilogo della settimana di una famiglia (da lunedì a domenica, nel
 * suo fuso): una foto per giorno, quanti giorni hanno la foto, quali no,
 * quanti cuori sono arrivati. Solo foto pubblicate, solo di quella famiglia.
 */
class WeeklyDigest
{
    public function __construct(private readonly PhotoStorage $storage) {}

    /**
     * @return array{
     *     da: string,
     *     a: string,
     *     giorni: list<array{data: string, id: string, speciale: bool, foto: int, thumbnail_url: string}>,
     *     pieni: int,
     *     totali: int,
     *     mancanti: list<string>,
     *     cuori: int
     * }
     */
    public function for(Family $family, CarbonImmutable $day): array
    {
        $from = $day->startOfWeek(CarbonImmutable::MONDAY)->toDateString();
        $to = $day->endOfWeek(CarbonImmutable::SUNDAY)->toDateString();

        $photos = Photo::query()
            ->where('family_id', $family->id)
            ->where('is_draft', false)
            ->whereBetween('data', [$from, $to])
            ->orderBy('data')
            ->orderByDesc('id')
            ->get()
            ->groupBy(fn (Photo $photo) => CarbonImmutable::parse((string) $photo->data)->toDateString());

        $days = $photos->map(function ($group, string $date) {
            $photo = $group->firstWhere('data_speciale', true) ?? $group->first();

            return [
                'data' => $date,
                'id' => $photo->ulid,
                'speciale' => $group->contains('data_speciale', true),
                'foto' => $group->count(),
                'thumbnail_url' => $this->storage->emailThumbnailUrl($photo),
            ];
        })->values();

        // Si contano i giorni dalla nascita (o dalla prima foto): una famiglia
        // nata mercoledì non ha "mancato" lunedì e martedì.
        $start = max($from, $family->protagonist()['birthdate'] ?? $from);
        $missing = [];
        $total = 0;

        if ($start <= $to) {
            foreach (CarbonPeriod::create($start, $to) as $date) {
                $total++;

                if (! $photos->has($date->toDateString())) {
                    $missing[] = $date->toDateString();
                }
            }
        }

        $hearts = PhotoHeart::query()
            ->whereHas('photo', fn ($query) => $query->where('family_id', $family->id))
            ->whereBetween('created_at', [
                CarbonImmutable::parse($from, $family->timezone())->startOfDay()->utc(),
                CarbonImmutable::parse($to, $family->timezone())->endOfDay()->utc(),
            ])
            ->count();

        return [
            'da' => $from,
            'a' => $to,
            'giorni' => $days->all(),
            'pieni' => $days->count(),
            'totali' => $total,
            'mancanti' => $missing,
            'cuori' => $hearts,
        ];
    }
}
