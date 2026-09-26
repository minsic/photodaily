<?php

namespace App\Console\Commands;

use App\Models\Family;
use App\Models\Photo;
use App\Services\MainImage;
use App\Services\PhotoStorage;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

#[Signature('photos:shrink-originals
    {--family= : Slug o ID della famiglia (predefinito: tutte)}
    {--dry-run : Ricodifica in memoria e dice quanto si libererebbe, senza scrivere nulla}
    {--limit= : Elabora al massimo N file (utile per una prova)}')]
#[Description('Ricodifica a 4096 px JPEG 88 le versioni principali già su R2 troppo grandi (oltre 4096 px o 5 MB)')]
class ShrinkOriginals extends Command
{
    /** Oltre questo peso la principale si ricodifica anche se entro i 4096 px. */
    private const MAX_BYTES = 5 * 1024 * 1024;

    public function handle(PhotoStorage $storage): int
    {
        $family = null;

        if ($this->option('family') !== null) {
            $value = (string) $this->option('family');
            $family = ctype_digit($value)
                ? Family::query()->find((int) $value)
                : Family::query()->where('slug', $value)->first();

            if (! $family) {
                $this->components->error("Famiglia [{$value}] non trovata.");

                return self::FAILURE;
            }
        }

        $max = MainImage::maxSide();

        // Per file, non per foto: alcune bozze importate condividono il file
        // con una foto pubblicata, e va ricodificato una volta sola.
        $paths = Photo::query()
            ->when($family, fn (Builder $query) => $query->where('family_id', $family->id))
            ->where(fn (Builder $query) => $query
                ->where('width', '>', $max)
                ->orWhere('height', '>', $max)
                ->orWhere('size_bytes', '>', self::MAX_BYTES))
            ->orderBy('image_path')
            ->distinct()
            ->when($this->option('limit'), fn (Builder $query) => $query->limit((int) $this->option('limit')))
            ->pluck('image_path');

        $dryRun = (bool) $this->option('dry-run');

        if ($paths->isEmpty()) {
            $this->components->info("Nessuna foto oltre {$max} px o 5 MB.");

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('File da ricodificare', (string) $paths->count());

        $before = 0;
        $after = 0;
        $skipped = 0;
        $errors = [];

        $this->withProgressBar($paths, function (string $path) use ($storage, $dryRun, &$before, &$after, &$skipped, &$errors) {
            try {
                $result = $storage->shrinkMainImage($path, $dryRun);

                if ($result === null) {
                    $skipped++;

                    return;
                }

                $before += $result['before'];
                $after += $result['after'];
            } catch (Throwable $e) {
                $errors[] = "{$path}: {$e->getMessage()}";
            }
        });

        $this->newLine(2);

        $mb = fn (int $bytes) => number_format($bytes / 1024 / 1024, 1, ',', '.').' MB';

        $this->components->twoColumnDetail('Prima', $mb($before));
        $this->components->twoColumnDetail('Dopo', $mb($after));
        $this->components->twoColumnDetail($dryRun ? 'Si libererebbero' : 'Liberati', $mb($before - $after));

        if ($skipped > 0) {
            $this->components->twoColumnDetail("Lasciati com'erano (non si risparmiava)", (string) $skipped);
        }

        if ($errors !== []) {
            $this->components->warn(count($errors).' file non elaborati:');
            $this->components->bulletList($errors);
        }

        $this->components->info($dryRun ? 'Prova (--dry-run): nessun file modificato.' : 'Fatto.');

        return $errors === [] ? self::SUCCESS : self::FAILURE;
    }
}
