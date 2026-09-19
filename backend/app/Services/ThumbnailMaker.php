<?php

namespace App\Services;

use Closure;
use Illuminate\Support\Facades\Image;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Genera le miniature usando la libreria immagini di Laravel (driver GD).
 * WebP quando è supportato, altrimenti JPEG.
 */
class ThumbnailMaker
{
    /** Lato lungo della miniatura, in pixel. */
    public const MAX_SIDE = 400;

    public const QUALITY = 80;

    /**
     * @return array{bytes: string, extension: string, width: int, height: int}|null
     */
    public function fromPath(string $path): ?array
    {
        return $this->encode(fn () => Image::fromPath($path));
    }

    /**
     * @return array{bytes: string, extension: string, width: int, height: int}|null
     */
    public function fromDisk(string $path, string $disk): ?array
    {
        return $this->encode(fn () => Image::fromStorage($path, $disk));
    }

    /**
     * @param  Closure(): \Illuminate\Image\Image  $source
     * @return array{bytes: string, extension: string, width: int, height: int}|null
     */
    private function encode(Closure $source): ?array
    {
        // GD decodifica l'immagine in una bitmap non compressa: una foto da
        // 4624x3468 occupa circa 64 MB, ben oltre il limite abituale di PHP.
        $limit = ini_get('memory_limit');
        ini_set('memory_limit', config('photodaily.thumbnail_memory_limit'));

        try {
            return $this->run($source);
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
    private function run(Closure $source): ?array
    {
        foreach (['webp', 'jpg'] as $format) {
            try {
                $image = $source()
                    // orient() applica la rotazione EXIF: le foto da telefono
                    // altrimenti escono coricate.
                    ->orient()
                    ->scale(self::MAX_SIDE, self::MAX_SIDE)
                    ->optimize($format, self::QUALITY);

                return [
                    'bytes' => $image->toBytes(),
                    'extension' => $image->extension(),
                    'width' => $image->width(),
                    'height' => $image->height(),
                ];
            } catch (Throwable $e) {
                Log::warning("Miniatura {$format} non generata: {$e->getMessage()}");
            }
        }

        return null;
    }
}
