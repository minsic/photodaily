<?php

namespace App\Console\Commands;

use App\Models\Family;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('family:list')]
#[Description('Elenca le famiglie: piano, foto, spazio usato e ultimo caricamento')]
class ListFamilies extends Command
{
    public function handle(): int
    {
        $families = Family::query()
            ->with('plan')
            ->withCount('photos')
            ->withMax('photos', 'created_at')
            ->orderBy('slug')
            ->get();

        if ($families->isEmpty()) {
            $this->components->info('Nessuna famiglia.');

            return self::SUCCESS;
        }

        $this->table(
            ['Slug', 'Nome', 'Piano', 'Accesso', 'Foto', 'Spazio', 'Ultimo caricamento'],
            $families->map(fn (Family $family) => [
                $family->slug,
                $family->name,
                $family->plan?->slug ?? '-',
                $family->access_mode->value,
                $family->photos_count,
                number_format($family->storage_used_mb, 1, ',', '.').' MB',
                $family->photos_max_created_at === null
                    ? '-'
                    : CarbonImmutable::parse($family->photos_max_created_at)->timezone($family->timezone())->format('d/m/Y H:i'),
            ])->all(),
        );

        return self::SUCCESS;
    }
}
