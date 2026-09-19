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
 * Unico punto di scrittura delle immagini: carica su R2 originale e miniatura,
 * applica i limiti del piano e tiene aggiornato families.storage_used_mb.
 * Usato sia dall'API che dal comando di import, così nessun percorso può
 * aggirare i limiti.
 */
class PhotoStorage
{
    public function __construct(
        private readonly FilesystemManager $filesystem,
        private readonly ThumbnailMaker $thumbnails,
    ) {}

    public function diskName(): string
    {
        return config('photodaily.disk');
    }

    public function disk(): Filesystem
    {
        return $this->filesystem->disk($this->diskName());
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
        $thumbnail = $this->uploadThumbnail($family, $path, $this->thumbnails->fromPath($file->getPathname()));

        try {
            return DB::transaction(function () use ($family, $file, $attributes, $uploader, $path, $thumbnail, $bytes) {
                // Il lock sulla famiglia serializza gli upload concorrenti: il controllo
                // definitivo dei limiti avviene qui, dentro la transazione.
                $family = Family::query()->lockForUpdate()->findOrFail($family->id);
                $family->ensureCanStore($bytes);

                $photo = $family->photos()->create([
                    ...$attributes,
                    ...$this->dimensions($file),
                    'image_path' => $path,
                    'size_bytes' => $bytes,
                    'thumbnail_path' => $thumbnail['path'] ?? null,
                    'thumbnail_bytes' => $thumbnail['bytes'] ?? 0,
                    'uploaded_by' => $uploader?->id,
                ]);

                $family->refreshStorageUsage();

                return $photo;
            });
        } catch (Throwable $e) {
            $this->disk()->delete(array_values(array_filter([$path, $thumbnail['path'] ?? null])));

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

        $previous = [$photo->image_path, $photo->thumbnail_path];
        $path = $this->upload($family, $file, $filename);
        $thumbnail = $this->uploadThumbnail($family, $path, $this->thumbnails->fromPath($file->getPathname()));

        try {
            DB::transaction(function () use ($photo, $family, $file, $path, $thumbnail, $bytes, $delta) {
                $family = Family::query()->lockForUpdate()->findOrFail($family->id);
                $family->ensureCanStore($delta, photos: 0);

                $photo->update([
                    ...$this->dimensions($file),
                    'image_path' => $path,
                    'size_bytes' => $bytes,
                    'thumbnail_path' => $thumbnail['path'] ?? null,
                    'thumbnail_bytes' => $thumbnail['bytes'] ?? 0,
                ]);

                $family->refreshStorageUsage();
            });
        } catch (Throwable $e) {
            if (! in_array($path, $previous, true)) {
                $this->disk()->delete($path);
            }

            throw $e;
        }

        foreach ($previous as $old) {
            if ($old !== null && $old !== $path && $old !== ($thumbnail['path'] ?? null)) {
                $this->deleteIfUnused($old);
            }
        }

        return $photo;
    }

    /**
     * Genera (o rigenera) la miniatura di una foto già caricata, leggendo
     * l'originale da R2.
     */
    public function generateThumbnail(Photo $photo): bool
    {
        $thumbnail = $this->thumbnails->fromDisk($photo->image_path, $this->diskName());

        if ($thumbnail === null) {
            return false;
        }

        $previous = $photo->thumbnail_path;
        $uploaded = $this->uploadThumbnail($photo->family, $photo->image_path, $thumbnail);

        $photo->forceFill([
            'thumbnail_path' => $uploaded['path'],
            'thumbnail_bytes' => $uploaded['bytes'],
        ])->save();

        if ($previous !== null && $previous !== $uploaded['path']) {
            $this->deleteIfUnused($previous);
        }

        return true;
    }

    public function delete(Photo $photo): void
    {
        DB::transaction(function () use ($photo) {
            $family = Family::query()->lockForUpdate()->findOrFail($photo->family_id);

            $photo->delete();

            $family->refreshStorageUsage();
        });

        // I file si rimuovono solo dopo il commit: se fallisse resterebbe un
        // oggetto orfano nel bucket, mai una foto senza immagine.
        $this->deleteIfUnused($photo->image_path);

        if ($photo->thumbnail_path !== null) {
            $this->deleteIfUnused($photo->thumbnail_path);
        }
    }

    public function temporaryUrl(Photo $photo): string
    {
        return $this->signedUrl($photo->image_path);
    }

    /**
     * URL della miniatura; finché non è stata generata si ripiega sull'originale.
     */
    public function thumbnailUrl(Photo $photo): string
    {
        return $this->signedUrl($photo->thumbnail_path ?? $photo->image_path);
    }

    private function signedUrl(string $path): string
    {
        return $this->disk()->temporaryUrl(
            $path,
            now()->addMinutes(config('photodaily.url_ttl_minutes')),
        );
    }

    /**
     * Rimuove l'oggetto solo se nessuna foto lo usa più: l'import assegna un
     * percorso deterministico all'asset Sanity, quindi due documenti che
     * riutilizzano la stessa immagine condividono lo stesso file su R2.
     */
    private function deleteIfUnused(string $path): void
    {
        $inUse = Photo::query()
            ->where('image_path', $path)
            ->orWhere('thumbnail_path', $path)
            ->exists();

        if ($inUse) {
            return;
        }

        $this->disk()->delete($path);
    }

    private function upload(Family $family, File $file, ?string $filename): string
    {
        $filename ??= Str::lower((string) Str::ulid()).'.'.($file->guessExtension() ?? 'jpg');

        return $this->disk()->putFileAs("families/{$family->id}/photos", $file, $filename);
    }

    /**
     * @param  array{bytes: string, extension: string, width: int, height: int}|null  $thumbnail
     * @return array{path: string, bytes: int}|null
     */
    private function uploadThumbnail(Family $family, string $imagePath, ?array $thumbnail): ?array
    {
        if ($thumbnail === null) {
            return null;
        }

        $path = sprintf(
            'families/%d/photos/thumbs/%s.%s',
            $family->id,
            pathinfo($imagePath, PATHINFO_FILENAME),
            $thumbnail['extension'],
        );

        $this->disk()->put($path, $thumbnail['bytes']);

        return ['path' => $path, 'bytes' => strlen($thumbnail['bytes'])];
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
