<?php

namespace App\Console\Commands;

use App\Models\Family;
use App\Models\Photo;
use App\Services\PhotoStorage;
use App\Services\ThumbnailMaker;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

#[Signature('photos:generate-medium
    {--family= : Slug o ID della famiglia (predefinito: tutte)}
    {--dry-run : Mostra quante foto verrebbero elaborate, senza scrivere nulla}
    {--force : Rigenera anche le versioni medie già presenti}
    {--limit= : Elabora al massimo N foto (utile per una prova)}')]
#[Description('Genera su R2 la versione media (lato lungo 1400px) delle foto già caricate')]
class GenerateMediumImages extends Command
{
    public function handle(PhotoStorage $storage): int
    {
        $family = null;

        if ($this->option('family') !== null) {
            $family = $this->resolveFamily((string) $this->option('family'));

            if (! $family) {
                $this->components->error("Famiglia [{$this->option('family')}] non trovata.");

                return self::FAILURE;
            }
        }

        $photos = Photo::query()
            ->when($family, fn (Builder $query) => $query->where('family_id', $family->id))
            // Senza --force si saltano le foto che hanno già la versione media:
            // il comando si può rilanciare dopo un'interruzione.
            ->when(! $this->option('force'), fn (Builder $query) => $query->whereNull('medium_path'))
            ->when($this->option('limit'), fn (Builder $query) => $query->limit((int) $this->option('limit')))
            ->orderBy('id')
            ->with('family')
            ->get();

        if ($photos->isEmpty()) {
            $this->components->info('Nessuna versione media da generare.');

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('Foto da elaborare', (string) $photos->count());
        $this->components->twoColumnDetail('Lato lungo', ThumbnailMaker::MEDIUM_SIDE.' px');

        if ($this->option('dry-run')) {
            $this->components->twoColumnDetail('Famiglie', $photos->pluck('family.slug')->unique()->implode(', '));
            $this->components->info('Prova (--dry-run): nessun file generato.');

            return self::SUCCESS;
        }

        $done = 0;
        $errors = [];

        $this->withProgressBar($photos, function (Photo $photo) use ($storage, &$done, &$errors) {
            try {
                if ($storage->generateMedium($photo)) {
                    $done++;
                } else {
                    $errors[] = "foto {$photo->id}: immagine non elaborabile";
                }
            } catch (Throwable $e) {
                $errors[] = "foto {$photo->id}: {$e->getMessage()}";
            }
        });

        $this->newLine(2);

        // Le versioni medie occupano spazio: il contatore della famiglia va rifatto.
        $photos->pluck('family')->unique('id')->each->refreshStorageUsage();

        if ($errors !== []) {
            $this->components->warn(count($errors).' foto senza versione media:');
            $this->components->bulletList($errors);
        }

        $this->components->info("Versioni medie generate: {$done}.");

        $missing = Photo::query()
            ->when($family, fn (Builder $query) => $query->where('family_id', $family->id))
            ->whereNull('medium_path')
            ->count();

        $this->components->twoColumnDetail('Foto ancora senza versione media', (string) $missing);

        return $errors === [] ? self::SUCCESS : self::FAILURE;
    }

    private function resolveFamily(string $value): ?Family
    {
        return ctype_digit($value)
            ? Family::query()->find((int) $value)
            : Family::query()->where('slug', $value)->first();
    }
}
