<?php

namespace App\Services;

use App\Exceptions\UnreadableImageException;
use App\Support\ImageMetadata;
use Illuminate\Support\Facades\Image;
use Imagick;
use Symfony\Component\HttpFoundation\File\File;
use Throwable;

/**
 * Prepara la versione principale di una foto, l'unica che va su R2 insieme
 * a medium e miniatura: mai l'originale grezzo.
 *
 * - lato lungo oltre 4096 px, EXIF con rotazione o HEIC: ricodifica a 4096
 *   px in JPEG 88, con la rotazione applicata ai pixel;
 * - altrimenti i byte restano quelli (niente seconda compressione della foto
 *   già ridotta dal browser) e si tolgono tutti i metadati.
 */
class MainImage
{
    public const JPEG_QUALITY = 88;

    /** Formati accettati, riconosciuti dai primi byte del file. */
    public const FORMATS = ['jpeg', 'png', 'webp', 'heic'];

    /**
     * @return 'jpeg'|'png'|'webp'|'heic'|null
     */
    public static function detectFormat(string $head): ?string
    {
        return match (true) {
            str_starts_with($head, "\xFF\xD8\xFF") => 'jpeg',
            str_starts_with($head, "\x89PNG\r\n\x1A\n") => 'png',
            str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP' => 'webp',
            // Contenitore ISO BMFF: "ftyp" e un marchio HEIF (heic, heix, mif1...).
            substr($head, 4, 4) === 'ftyp' && in_array(substr($head, 8, 4), ['heic', 'heix', 'heim', 'heis', 'hevc', 'hevx', 'mif1', 'msf1'], true) => 'heic',
            default => null,
        };
    }

    /** Lato lungo massimo (4096; lo stesso limite del browser, utils/shrinkImage.ts). */
    public static function maxSide(): int
    {
        return config('photodaily.main_max_side');
    }

    /**
     * Imagick installato e capace di leggere l'HEIC (serve libheif con il
     * decoder HEVC). GD non lo legge mai.
     */
    public static function canReadHeic(): bool
    {
        static $supported = null;

        return $supported ??= class_exists(Imagick::class) && Imagick::queryFormats('HEIC') !== [];
    }

    /**
     * @return array{file: File, temporary: string|null, width: int|null, height: int|null}
     */
    public function prepare(File $file, bool $forceReencode = false): array
    {
        $path = $file->getPathname();
        $bytes = (string) file_get_contents($path);
        $format = self::detectFormat(substr($bytes, 0, 16));

        if ($format === 'heic') {
            return $this->store($this->reencode($path, imagick: true));
        }

        $size = @getimagesize($path);
        [$width, $height] = [$size[0] ?? 0, $size[1] ?? 0];

        if ($forceReencode || max($width, $height) > self::maxSide() || $this->orientation($path, $format) > 1) {
            return $this->store($this->reencode($path));
        }

        $clean = ImageMetadata::stripped($bytes);

        // File che non si lascia leggere come ci si aspetta: la ricodifica
        // toglie comunque tutto.
        if ($clean === null) {
            return $this->store($this->reencode($path));
        }

        if ($clean === $bytes) {
            return ['file' => $file, 'temporary' => null, 'width' => $width ?: null, 'height' => $height ?: null];
        }

        return $this->store($clean);
    }

    /**
     * Ricodifica a 4096 px JPEG 88 con la rotazione EXIF applicata; il file
     * che ne esce non ha metadati.
     */
    public function reencode(string $path, bool $imagick = false): string
    {
        // Una foto da 48 MP decodificata da GD occupa circa 200 MB.
        $limit = ini_get('memory_limit');
        ini_set('memory_limit', config('photodaily.thumbnail_memory_limit'));

        try {
            $image = Image::fromPath($path);

            if ($imagick) {
                $image = $image->usingImagick();
            }

            return $image->orient()
                ->scale(self::maxSide(), self::maxSide())
                ->optimize('jpg', self::JPEG_QUALITY)
                ->toBytes();
        } catch (Throwable $e) {
            throw new UnreadableImageException($e->getMessage(), previous: $e);
        } finally {
            gc_collect_cycles();
            @ini_set('memory_limit', $limit);
        }
    }

    private function orientation(string $path, ?string $format): int
    {
        if ($format !== 'jpeg' || ! function_exists('exif_read_data')) {
            return 1;
        }

        $exif = @exif_read_data($path, 'IFD0');

        return (int) ($exif['Orientation'] ?? 1);
    }

    /**
     * @return array{file: File, temporary: string, width: int|null, height: int|null}
     */
    private function store(string $bytes): array
    {
        $temporary = tempnam(sys_get_temp_dir(), 'photodaily-principale');
        file_put_contents($temporary, $bytes);
        $size = @getimagesize($temporary);

        return ['file' => new File($temporary), 'temporary' => $temporary, 'width' => $size[0] ?? null, 'height' => $size[1] ?? null];
    }
}
