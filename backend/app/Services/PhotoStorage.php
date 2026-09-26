<?php

namespace App\Services;

use App\Exceptions\PlanLimitExceededException;
use App\Jobs\GeneratePhotoVariants;
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
 * Unico punto di scrittura delle immagini: carica su R2 la versione
 * principale (al massimo 4096 px, senza metadati: vedi MainImage, mai
 * l'originale grezzo), applica i limiti del piano e tiene aggiornato
 * families.storage_used_mb. Versione media e miniatura le genera dopo il job
 * GeneratePhotoVariants, così una raffica di upload non tiene occupati i
 * processi web. Usato sia dall'API che dal comando di import, così nessun
 * percorso può aggirare i limiti.
 */
class PhotoStorage
{
    /** Colonne delle versioni ridotte, finché il job non le genera. */
    private const NO_VARIANTS = [
        'thumbnail_path' => null,
        'thumbnail_bytes' => 0,
        'medium_path' => null,
        'medium_bytes' => 0,
        'medium_width' => null,
        'medium_height' => null,
    ];

    public function __construct(
        private readonly FilesystemManager $filesystem,
        private readonly ThumbnailMaker $thumbnails,
        private readonly MainImage $mainImage,
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
        ['file' => $file, 'temporary' => $temporary, 'width' => $width, 'height' => $height] = $this->mainImage->prepare($file);

        try {
            $bytes = $file->getSize();

            // Controllo anticipato per non caricare su R2 un file che verrebbe rifiutato.
            $family->ensureCanStore($bytes);

            $path = $this->upload($family, $file, $filename);

            try {
                $photo = DB::transaction(function () use ($family, $attributes, $uploader, $path, $bytes, $width, $height) {
                    // Il lock sulla famiglia serializza gli upload concorrenti: il controllo
                    // definitivo dei limiti avviene qui, dentro la transazione.
                    $family = Family::query()->lockForUpdate()->findOrFail($family->id);
                    $family->ensureCanStore($bytes);

                    $photo = $family->photos()->create([
                        ...$attributes,
                        'width' => $width,
                        'height' => $height,
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
        } finally {
            if ($temporary !== null) {
                @unlink($temporary);
            }
        }

        GeneratePhotoVariants::dispatch($photo);

        return $photo;
    }

    /**
     * Sostituisce l'immagine di una foto esistente (usato dall'import quando
     * l'asset Sanity di un documento è cambiato).
     *
     * @throws PlanLimitExceededException
     */
    public function replaceImage(Photo $photo, File $file, ?string $filename = null): Photo
    {
        ['file' => $file, 'temporary' => $temporary, 'width' => $width, 'height' => $height] = $this->mainImage->prepare($file);

        try {
            $bytes = $file->getSize();
            $delta = max(0, $bytes - $photo->size_bytes);
            $family = $photo->family;

            $family->ensureCanStore($delta, photos: 0);

            $previous = [$photo->image_path, $photo->thumbnail_path, $photo->medium_path];
            $path = $this->upload($family, $file, $filename);

            try {
                DB::transaction(function () use ($photo, $family, $path, $bytes, $delta, $width, $height) {
                    $family = Family::query()->lockForUpdate()->findOrFail($family->id);
                    $family->ensureCanStore($delta, photos: 0);

                    // Le versioni ridotte vecchie non valgono più: le rifà il job.
                    $photo->update([
                        'width' => $width,
                        'height' => $height,
                        ...self::NO_VARIANTS,
                        'image_path' => $path,
                        'size_bytes' => $bytes,
                    ]);

                    $family->refreshStorageUsage();
                });
            } catch (Throwable $e) {
                if (! in_array($path, $previous, true)) {
                    $this->disk()->delete($path);
                }

                throw $e;
            }
        } finally {
            if ($temporary !== null) {
                @unlink($temporary);
            }
        }

        foreach ($previous as $old) {
            if ($old !== null && $old !== $path) {
                $this->deleteIfUnused($old);
            }
        }

        GeneratePhotoVariants::dispatch($photo);

        return $photo;
    }

    /**
     * Genera versione media e miniatura leggendo l'originale da R2 (lo fa il
     * job GeneratePhotoVariants dopo ogni upload). False se l'immagine non è
     * elaborabile: la foto resta visibile, con l'originale al posto della miniatura.
     */
    public function generateVariants(Photo $photo): bool
    {
        $source = tempnam(sys_get_temp_dir(), 'photodaily-originale');

        try {
            file_put_contents($source, $this->disk()->get($photo->image_path));

            $variants = $this->thumbnails->variantsFromPath($source);
        } finally {
            @unlink($source);
        }

        if ($variants['medium'] === null || $variants['thumbnail'] === null) {
            return false;
        }

        $previous = [$photo->thumbnail_path, $photo->medium_path];
        $medium = $this->uploadVariant($photo->family, $photo->image_path, $variants['medium'], 'medium');
        $thumbnail = $this->uploadVariant($photo->family, $photo->image_path, $variants['thumbnail'], 'thumbs');

        $photo->forceFill([
            'thumbnail_path' => $thumbnail['path'],
            'thumbnail_bytes' => $thumbnail['bytes'],
            'medium_path' => $medium['path'],
            'medium_bytes' => $medium['bytes'],
            'medium_width' => $variants['medium']['width'],
            'medium_height' => $variants['medium']['height'],
        ])->save();

        foreach ($previous as $old) {
            if ($old !== null && $old !== $thumbnail['path'] && $old !== $medium['path']) {
                $this->deleteIfUnused($old);
            }
        }

        $photo->family->refreshStorageUsage();

        return true;
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
        $uploaded = $this->uploadVariant($photo->family, $photo->image_path, $thumbnail, 'thumbs');

        $photo->forceFill([
            'thumbnail_path' => $uploaded['path'],
            'thumbnail_bytes' => $uploaded['bytes'],
        ])->save();

        if ($previous !== null && $previous !== $uploaded['path']) {
            $this->deleteIfUnused($previous);
        }

        return true;
    }

    /**
     * Genera (o rigenera) la versione media di una foto già caricata,
     * leggendo l'originale da R2.
     */
    public function generateMedium(Photo $photo): bool
    {
        $medium = $this->thumbnails->fromDisk($photo->image_path, $this->diskName(), ThumbnailMaker::MEDIUM_SIDE);

        if ($medium === null) {
            return false;
        }

        $previous = $photo->medium_path;
        $uploaded = $this->uploadVariant($photo->family, $photo->image_path, $medium, 'medium');

        $photo->forceFill([
            'medium_path' => $uploaded['path'],
            'medium_bytes' => $uploaded['bytes'],
            'medium_width' => $medium['width'],
            'medium_height' => $medium['height'],
        ])->save();

        if ($previous !== null && $previous !== $uploaded['path']) {
            $this->deleteIfUnused($previous);
        }

        return true;
    }

    /**
     * Ricodifica una versione principale già su R2 (4096 px, JPEG 88) e la
     * sostituisce in tutte le foto che la usano. Con $dryRun non scrive
     * niente: misura soltanto. Null se la ricodifica non farebbe risparmiare.
     *
     * @return array{before: int, after: int, width: int|null, height: int|null}|null
     */
    public function shrinkMainImage(string $imagePath, bool $dryRun = false): ?array
    {
        $source = tempnam(sys_get_temp_dir(), 'photodaily-principale-r2');
        $prepared = null;

        try {
            file_put_contents($source, $this->disk()->get($imagePath));
            $before = (int) filesize($source);
            $prepared = $this->mainImage->prepare(new File($source), forceReencode: true);
            $after = (int) $prepared['file']->getSize();
            $result = ['before' => $before, 'after' => $after, 'width' => $prepared['width'], 'height' => $prepared['height']];

            if ($after >= $before) {
                return null;
            }

            if ($dryRun) {
                return $result;
            }

            $path = $this->upload(Photo::query()->where('image_path', $imagePath)->firstOrFail()->family, $prepared['file'], null);

            try {
                DB::transaction(function () use ($imagePath, $path, $result) {
                    $photos = Photo::query()->where('image_path', $imagePath)->get();

                    foreach ($photos->pluck('family_id')->unique() as $familyId) {
                        Family::query()->lockForUpdate()->findOrFail($familyId);
                    }

                    Photo::query()->where('image_path', $imagePath)->update([
                        'image_path' => $path,
                        'size_bytes' => $result['after'],
                        'width' => $result['width'],
                        'height' => $result['height'],
                    ]);

                    Family::query()->whereIn('id', $photos->pluck('family_id')->unique())->get()->each->refreshStorageUsage();
                });
            } catch (Throwable $e) {
                $this->disk()->delete($path);

                throw $e;
            }

            $this->deleteIfUnused($imagePath);

            return $result;
        } finally {
            @unlink($source);

            if ($prepared !== null && $prepared['temporary'] !== null) {
                @unlink($prepared['temporary']);
            }
        }
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
        foreach (array_filter([$photo->image_path, $photo->thumbnail_path, $photo->medium_path]) as $path) {
            $this->deleteIfUnused($path);
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

    /**
     * URL della versione media; null finché non è stata generata (la timeline
     * usa allora solo la miniatura, mai l'originale da diversi MB).
     */
    public function mediumUrl(Photo $photo): ?string
    {
        return $photo->medium_path === null ? null : $this->signedUrl($photo->medium_path);
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
            ->orWhere('medium_path', $path)
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
     * @param  array{bytes: string, extension: string, width: int, height: int}|null  $variant
     * @param  'thumbs'|'medium'  $folder
     * @return array{path: string, bytes: int}|null
     */
    private function uploadVariant(Family $family, string $imagePath, ?array $variant, string $folder): ?array
    {
        if ($variant === null) {
            return null;
        }

        $path = sprintf(
            'families/%d/photos/%s/%s.%s',
            $family->id,
            $folder,
            pathinfo($imagePath, PATHINFO_FILENAME),
            $variant['extension'],
        );

        $this->disk()->put($path, $variant['bytes']);

        return ['path' => $path, 'bytes' => strlen($variant['bytes'])];
    }
}
