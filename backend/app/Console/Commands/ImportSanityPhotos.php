<?php

namespace App\Console\Commands;

use App\Exceptions\PlanLimitExceededException;
use App\Models\Family;
use App\Models\Photo;
use App\Services\PhotoStorage;
use App\Support\PortableText;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use JsonException;
use SplFileObject;
use Symfony\Component\HttpFoundation\File\File;
use Throwable;

#[Signature('photos:import
    {family : Slug o ID della famiglia a cui assegnare le foto}
    {path : Cartella dell\'export Sanity (con data.ndjson e images/) oppure il file .ndjson}
    {--dry-run : Analizza l\'export senza caricare né scrivere nulla}')]
#[Description('Importa le foto da un export Sanity (NDJSON + cartella images/) in una famiglia')]
class ImportSanityPhotos extends Command
{
    private const DRAFT_PREFIX = 'drafts.';

    /** @var list<string> */
    private array $errors = [];

    public function handle(PhotoStorage $storage): int
    {
        $family = $this->resolveFamily((string) $this->argument('family'));

        if (! $family) {
            $this->components->error("Famiglia [{$this->argument('family')}] non trovata.");

            return self::FAILURE;
        }

        try {
            [$ndjson, $baseDir] = $this->resolvePaths((string) $this->argument('path'));
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $records = $this->readRecords($ndjson, $baseDir);

        /** @var Collection<string, Photo> $existing */
        $existing = $family->photos()->whereNotNull('sanity_id')->get()->keyBy('sanity_id');
        $new = $records->reject(fn (array $record) => $existing->has($record['sanity_id']));
        $newBytes = (int) $new->sum(fn (array $record) => filesize($record['image']));

        $this->components->twoColumnDetail('Famiglia', "{$family->name} ({$family->slug})");
        $this->components->twoColumnDetail('Documenti photo validi', (string) $records->count());
        $this->components->twoColumnDetail('  pubblicati / bozze', $records->where('is_draft', false)->count().' / '.$records->where('is_draft', true)->count());
        $this->components->twoColumnDetail('  nuovi / già importati', $new->count().' / '.($records->count() - $new->count()));
        $this->components->twoColumnDetail('Spazio per le nuove immagini', number_format($newBytes / 1024 / 1024, 1, ',', '.').' MB');
        $this->components->twoColumnDetail('Documenti scartati', (string) count($this->errors));

        try {
            $family->ensureCanStore($newBytes, $new->count());
        } catch (PlanLimitExceededException $e) {
            $this->components->error('Import bloccato dai limiti del piano: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->reportErrors();
            $this->components->info('Dry run: nessuna modifica effettuata.');

            return $this->errors === [] ? self::SUCCESS : self::FAILURE;
        }

        $created = $updated = 0;

        $this->withProgressBar($records, function (array $record) use ($storage, $family, $existing, &$created, &$updated) {
            try {
                $file = new File($record['image']);
                $filename = basename($record['image']);
                $attributes = Arr::only($record, ['sanity_id', 'data', 'data_speciale', 'didascalia', 'is_draft']);
                $photo = $existing->get($record['sanity_id']);

                if (! $photo) {
                    $storage->create($family, $file, $attributes, filename: $filename);
                    $created++;

                    return;
                }

                $photo->update(Arr::except($attributes, 'sanity_id'));

                if (basename($photo->image_path) !== $filename) {
                    $storage->replaceImage($photo, $file, $filename);
                }

                $updated++;
            } catch (Throwable $e) {
                $this->errors[] = "{$record['sanity_id']}: {$e->getMessage()}";
            }
        });

        $this->newLine(2);
        $this->reportErrors();

        $family->refresh();
        $this->components->info("Import completato: {$created} create, {$updated} aggiornate, ".count($this->errors).' errori.');
        $this->components->twoColumnDetail('Spazio usato dalla famiglia', number_format($family->storage_used_mb, 2, ',', '.').' MB');

        return $this->errors === [] ? self::SUCCESS : self::FAILURE;
    }

    private function resolveFamily(string $value): ?Family
    {
        return ctype_digit($value)
            ? Family::query()->find((int) $value)
            : Family::query()->where('slug', $value)->first();
    }

    /**
     * @return array{0: string, 1: string} [file NDJSON, cartella base per gli asset]
     */
    private function resolvePaths(string $path): array
    {
        $ndjson = is_dir($path) ? rtrim($path, '/\\').DIRECTORY_SEPARATOR.'data.ndjson' : $path;

        if (! is_file($ndjson)) {
            throw new InvalidArgumentException("File NDJSON non trovato: {$ndjson}");
        }

        return [$ndjson, dirname((string) realpath($ndjson))];
    }

    /**
     * Legge i documenti di tipo "photo". Una bozza mai pubblicata ("drafts.<id>")
     * viene importata come is_draft; se esiste anche la versione pubblicata, vince quella.
     *
     * @return Collection<int, array{sanity_id: string, is_draft: bool, data: string, data_speciale: bool, didascalia: ?string, image: string}>
     */
    private function readRecords(string $ndjson, string $baseDir): Collection
    {
        $records = [];
        $file = new SplFileObject($ndjson);

        foreach ($file as $index => $line) {
            if (! is_string($line) || trim($line) === '') {
                continue;
            }

            $lineNumber = $index + 1;

            try {
                $document = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                $this->errors[] = "riga {$lineNumber}: JSON non valido ({$e->getMessage()})";

                continue;
            }

            if (! is_array($document) || ($document['_type'] ?? null) !== 'photo') {
                continue;
            }

            try {
                $record = $this->toRecord($document, $baseDir);
            } catch (InvalidArgumentException $e) {
                $this->errors[] = "riga {$lineNumber} (".($document['_id'] ?? '?')."): {$e->getMessage()}";

                continue;
            }

            $current = $records[$record['sanity_id']] ?? null;

            if ($current === null || ($current['is_draft'] && ! $record['is_draft'])) {
                $records[$record['sanity_id']] = $record;
            }
        }

        return collect(array_values($records));
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array{sanity_id: string, is_draft: bool, data: string, data_speciale: bool, didascalia: ?string, image: string}
     */
    private function toRecord(array $document, string $baseDir): array
    {
        $id = $document['_id'] ?? null;

        if (! is_string($id) || $id === '') {
            throw new InvalidArgumentException('_id mancante');
        }

        $isDraft = str_starts_with($id, self::DRAFT_PREFIX);
        $data = $document['data'] ?? null;

        if (! is_string($data) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $data, $parts) || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            throw new InvalidArgumentException('campo "data" mancante o non nel formato YYYY-MM-DD');
        }

        return [
            'sanity_id' => $isDraft ? substr($id, strlen(self::DRAFT_PREFIX)) : $id,
            'is_draft' => $isDraft,
            'data' => $data,
            'data_speciale' => (bool) ($document['special'] ?? false),
            'didascalia' => PortableText::toPlainText($document['dida'] ?? null),
            'image' => $this->resolveImage($document['immagine'] ?? null, $baseDir),
        ];
    }

    /**
     * Risolve "image@file://./images/<hash>-<w>x<h>.jpg" nella cartella dell'export.
     */
    private function resolveImage(mixed $image, string $baseDir): string
    {
        $reference = is_array($image) ? ($image['_sanityAsset'] ?? null) : null;

        if (! is_string($reference) || ! preg_match('#^image@file://(.+)$#', $reference, $matches)) {
            throw new InvalidArgumentException('immagine mancante (immagine._sanityAsset assente)');
        }

        $relative = preg_replace('#^\./#', '', $matches[1]);
        $path = realpath($baseDir.DIRECTORY_SEPARATOR.$relative);

        if ($path === false || ! is_file($path) || ! str_starts_with($path, $baseDir.DIRECTORY_SEPARATOR)) {
            throw new InvalidArgumentException("file immagine non trovato: {$relative}");
        }

        return $path;
    }

    private function reportErrors(): void
    {
        if ($this->errors === []) {
            return;
        }

        $this->components->warn(count($this->errors).' documenti non importati:');
        $this->components->bulletList($this->errors);
    }
}
