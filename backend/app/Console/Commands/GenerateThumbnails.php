<?php

namespace App\Console\Commands;

use App\Models\Family;
use App\Models\Photo;
use App\Services\PhotoStorage;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

#[Signature('photos:generate-thumbnails
    {family? : Slug o ID della famiglia (predefinito: tutte)}
    {--force : Rigenera anche le miniature già presenti}
    {--limit= : Elabora al massimo N foto (utile per una prova)}')]
#[Description('Genera su R2 le miniature mancanti delle foto già caricate')]
class GenerateThumbnails extends Command
{
    public function handle(PhotoStorage $storage): int
    {
        $family = null;

        if ($this->argument('family') !== null) {
            $family = $this->resolveFamily((string) $this->argument('family'));

            if (! $family) {
                $this->components->error("Famiglia [{$this->argument('family')}] non trovata.");

                return self::FAILURE;
            }
        }

        $photos = Photo::query()
            ->when($family, fn (Builder $query) => $query->where('family_id', $family->id))
            // Senza --force si saltano le foto che hanno già la miniatura:
            // il comando si può rilanciare dopo un'interruzione.
            ->when(! $this->option('force'), fn (Builder $query) => $query->whereNull('thumbnail_path'))
            ->when($this->option('limit'), fn (Builder $query) => $query->limit((int) $this->option('limit')))
            ->orderBy('id')
            ->with('family')
            ->get();

        if ($photos->isEmpty()) {
            $this->components->info('Nessuna miniatura da generare.');

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('Foto da elaborare', (string) $photos->count());

        $done = 0;
        $errors = [];

        $this->withProgressBar($photos, function (Photo $photo) use ($storage, &$done, &$errors) {
            try {
                if ($storage->generateThumbnail($photo)) {
                    $done++;
                } else {
                    $errors[] = "foto {$photo->id}: immagine non elaborabile";
                }
            } catch (Throwable $e) {
                $errors[] = "foto {$photo->id}: {$e->getMessage()}";
            }
        });

        $this->newLine(2);

        // Le miniature occupano spazio: il contatore della famiglia va rifatto.
        $photos->pluck('family')->unique('id')->each->refreshStorageUsage();

        if ($errors !== []) {
            $this->components->warn(count($errors).' foto senza miniatura:');
            $this->components->bulletList($errors);
        }

        $this->components->info("Miniature generate: {$done}.");

        $missing = Photo::query()
            ->when($family, fn (Builder $query) => $query->where('family_id', $family->id))
            ->whereNull('thumbnail_path')
            ->count();

        $this->components->twoColumnDetail('Foto ancora senza miniatura', (string) $missing);

        return $errors === [] ? self::SUCCESS : self::FAILURE;
    }

    private function resolveFamily(string $value): ?Family
    {
        return ctype_digit($value)
            ? Family::query()->find((int) $value)
            : Family::query()->where('slug', $value)->first();
    }
}
