<?php

namespace App\Console\Commands;

use App\Models\Family;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

#[Signature('photos:recalc-storage {--family= : Slug o ID della famiglia (predefinito: tutte)}')]
#[Description('Ricalcola lo spazio usato da ogni famiglia: principale, medium e miniatura di ogni foto')]
class RecalcStorage extends Command
{
    public function handle(): int
    {
        $value = $this->option('family');
        $families = Family::query()
            ->when($value !== null, fn (Builder $query) => ctype_digit((string) $value)
                ? $query->whereKey((int) $value)
                : $query->where('slug', $value))
            ->orderBy('id')
            ->get();

        if ($families->isEmpty()) {
            $this->components->error('Nessuna famiglia trovata.');

            return self::FAILURE;
        }

        foreach ($families as $family) {
            $previous = $family->storage_used_mb;
            $family->refreshStorageUsage();

            $this->components->twoColumnDetail(
                $family->slug,
                $previous === $family->storage_used_mb
                    ? "{$family->storage_used_mb} MB"
                    : "{$previous} → {$family->storage_used_mb} MB",
            );
        }

        return self::SUCCESS;
    }
}
