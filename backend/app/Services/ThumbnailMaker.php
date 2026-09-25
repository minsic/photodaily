<?php

namespace App\Services;

use Closure;
use Illuminate\Support\Facades\Image;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Genera le versioni ridotte delle foto usando la libreria immagini di Laravel
 * (driver GD): la miniatura e la versione media per la timeline.
 * WebP quando è supportato, altrimenti JPEG. Le foto non vengono mai ingrandite.
 */
class ThumbnailMaker
{
    /** Lato lungo della miniatura, in pixel. */
    public const MAX_SIDE = 400;

    /** Lato lungo della versione media: la colonna della timeline è larga al massimo 700px, 1400 basta per gli schermi retina. */
    public const MEDIUM_SIDE = 1400;

    public const QUALITY = 80;

    /**
     * Versione media e miniatura insieme. L'originale si decodifica una volta
     * sola: la miniatura si ricava dalla versione media, già orientata.
     *
     * @return array{medium: array{bytes: string, extension: string, width: int, height: int}|null, thumbnail: array{bytes: string, extension: string, width: int, height: int}|null}
     */
    public function variantsFromPath(string $path): array
    {
        $medium = $this->fromPath($path, self::MEDIUM_SIDE);

        return [
            'medium' => $medium,
            'thumbnail' => $medium === null
                ? null
                : $this->encode(fn () => Image::fromBytes($medium['bytes']), self::MAX_SIDE),
        ];
    }

    /**
     * @return array{bytes: string, extension: string, width: int, height: int}|null
     */
    public function fromPath(string $path, int $maxSide = self::MAX_SIDE): ?array
    {
        return $this->encode(fn () => Image::fromPath($path), $maxSide);
    }

    /**
     * @return array{bytes: string, extension: string, width: int, height: int}|null
     */
    public function fromDisk(string $path, string $disk, int $maxSide = self::MAX_SIDE): ?array
    {
        return $this->encode(fn () => Image::fromStorage($path, $disk), $maxSide);
    }

    /**
     * @param  Closure(): \Illuminate\Image\Image  $source
     * @return array{bytes: string, extension: string, width: int, height: int}|null
     */
    private function encode(Closure $source, int $maxSide): ?array
    {
        // GD decodifica l'immagine in una bitmap non compressa: una foto da
        // 4624x3468 occupa circa 64 MB, ben oltre il limite abituale di PHP.
        $limit = ini_get('memory_limit');
        ini_set('memory_limit', config('photodaily.thumbnail_memory_limit'));

        try {
            return $this->run($source, $maxSide);
        } finally {
            // Prima si liberano le bitmap: restano nei cicli di riferimenti
            // finché il GC non passa, e senza questa raccolta elaborando molte
            // foto di fila la memoria cresce fino a esaurire il limite.
            gc_collect_cycles();

            // Se la memoria ancora occupata supera il limite originale il
            // ripristino non è possibile: resta quello alto fino alla fine del
            // processo, che è la richiesta o il comando in corso.
            @ini_set('memory_limit', $limit);
        }
    }

    /**
     * @param  Closure(): \Illuminate\Image\Image  $source
     * @return array{bytes: string, extension: string, width: int, height: int}|null
     */
    private function run(Closure $source, int $maxSide): ?array
    {
        foreach (['webp', 'jpg'] as $format) {
            try {
                $image = $source()
                    // orient() applica la rotazione EXIF: le foto da telefono
                    // altrimenti escono coricate.
                    ->orient()
                    ->scale($maxSide, $maxSide)
                    ->optimize($format, self::QUALITY);

                return [
                    'bytes' => $image->toBytes(),
                    'extension' => $image->extension(),
                    'width' => $image->width(),
                    'height' => $image->height(),
                ];
            } catch (Throwable $e) {
                Log::warning("Versione ridotta {$format} ({$maxSide}px) non generata: {$e->getMessage()}");
            }
        }

        return null;
    }
}
