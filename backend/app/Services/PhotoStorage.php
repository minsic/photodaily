<?php

namespace App\Services;

use App\Exceptions\PlanLimitExceededException;
use App\Models\Family;
use App\Models\Photo;
use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\File\File;
use Throwable;

/**
 * Unico punto di scrittura delle immagini: carica su R2, applica i limiti del
 * piano e tiene aggiornato families.storage_used_mb. Usato sia dall'API che
 * dal comando di import, così nessun percorso può aggirare i limiti.
 */
class PhotoStorage
{
    public function __construct(private readonly FilesystemManager $filesystem) {}

    public function disk(): Filesystem
    {
        return $this->filesystem->disk(config('photodaily.disk'));
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws PlanLimitExceededException
     */
    public function create(Family $family, File $file, array $attributes, ?User $uploader = null, ?string $filename = null): Photo
    {
        $bytes = $file->getSize();

        // Controllo anticipato per non caricare su R2 un file che verrebbe rifiutato.
        $family->ensureCanStore($bytes);

        $path = $this->upload($family, $file, $filename);

        try {
            return DB::transaction(function () use ($family, $file, $attributes, $uploader, $path, $bytes) {
                // Il lock sulla famiglia serializza gli upload concorrenti: il controllo
                // definitivo dei limiti avviene qui, dentro la transazione.
                $family = Family::query()->lockForUpdate()->findOrFail($family->id);
                $family->ensureCanStore($bytes);

                $photo = $family->photos()->create([
                    ...$attributes,
                    ...$this->dimensions($file),
                    'image_path' => $path,
                    'size_bytes' => $bytes,
                    'uploaded_by' => $uploader?->id,
                ]);

                $family->refreshStorageUsage();

                return $photo;
            });
        } catch (Throwable $e) {
            $this->disk()->delete($path);

            throw $e;
        }
    }

    /**
     * Sostituisce l'immagine di una foto esistente (usato dall'import quando
     * l'asset Sanity di un documento è cambiato).
     *
     * @throws PlanLimitExceededException
     */
    public function replaceImage(Photo $photo, File $file, ?string $filename = null): Photo
    {
        $bytes = $file->getSize();
        $delta = max(0, $bytes - $photo->size_bytes);
        $family = $photo->family;

        $family->ensureCanStore($delta, photos: 0);

        $oldPath = $photo->image_path;
        $path = $this->upload($family, $file, $filename);

        try {
            DB::transaction(function () use ($photo, $family, $file, $path, $bytes, $delta) {
                $family = Family::query()->lockForUpdate()->findOrFail($family->id);
                $family->ensureCanStore($delta, photos: 0);

                $photo->update([
                    ...$this->dimensions($file),
                    'image_path' => $path,
                    'size_bytes' => $bytes,
                ]);

                $family->refreshStorageUsage();
            });
        } catch (Throwable $e) {
            if ($path !== $oldPath) {
                $this->disk()->delete($path);
            }

            throw $e;
        }

        if ($oldPath !== $path) {
            $this->disk()->delete($oldPath);
        }

        return $photo;
    }

    public function delete(Photo $photo): void
    {
        DB::transaction(function () use ($photo) {
            $family = Family::query()->lockForUpdate()->findOrFail($photo->family_id);

            $photo->delete();

            $family->refreshStorageUsage();
        });

        // Il file si rimuove solo dopo il commit: se fallisse resterebbe un
        // oggetto orfano nel bucket, mai una foto senza immagine.
        $this->disk()->delete($photo->image_path);
    }

    public function temporaryUrl(Photo $photo): string
    {
        return $this->disk()->temporaryUrl(
            $photo->image_path,
            now()->addMinutes(config('photodaily.url_ttl_minutes')),
        );
    }

    private function upload(Family $family, File $file, ?string $filename): string
    {
        $filename ??= Str::lower((string) Str::ulid()).'.'.($file->guessExtension() ?? 'jpg');

        return $this->disk()->putFileAs("families/{$family->id}/photos", $file, $filename);
    }

    /**
     * @return array{width: int|null, height: int|null}
     */
    private function dimensions(File $file): array
    {
        $size = @getimagesize($file->getPathname());

        return [
            'width' => $size[0] ?? null,
            'height' => $size[1] ?? null,
        ];
    }
}
