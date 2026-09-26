<?php

namespace App\Services;

use App\Exceptions\UnreadableImageException;
use App\Support\ImageMetadata;
use GdImage;
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
        $limit = ini_get('memory_limit');
        ini_set('memory_limit', config('photodaily.thumbnail_memory_limit'));

        try {
            if ($imagick) {
                return Image::fromPath($path)->usingImagick()
                    ->orient()
                    ->scale(self::maxSide(), self::maxSide())
                    ->optimize('jpg', self::JPEG_QUALITY)
                    ->toBytes();
            }

            return $this->reencodeWithGd($path);
        } catch (UnreadableImageException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new UnreadableImageException($e->getMessage(), previous: $e);
        } finally {
            gc_collect_cycles();
            @ini_set('memory_limit', $limit);
        }
    }

    /**
     * GD a mano, non la libreria immagini: quella copia la bitmap a ogni
     * passaggio, e una foto da 48 MP (circa 200 MB decodificata) ruotata e
     * poi ridotta supera i 512 MB. Qui prima si riduce e si libera
     * l'originale, poi si ruota la versione piccola: il picco resta sui 250 MB.
     */
    private function reencodeWithGd(string $path): string
    {
        $source = match (self::detectFormat((string) file_get_contents($path, length: 16))) {
            'jpeg' => @imagecreatefromjpeg($path),
            'png' => @imagecreatefrompng($path),
            'webp' => @imagecreatefromwebp($path),
            default => false,
        };

        if ($source === false) {
            throw new UnreadableImageException('GD non riesce a decodificare il file.');
        }

        $orientation = $this->orientation($path, 'jpeg');
        [$width, $height] = [imagesx($source), imagesy($source)];
        $scale = min(1, self::maxSide() / max($width, $height));

        if ($scale < 1) {
            $resized = imagecreatetruecolor(max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)));
            imagecopyresampled($resized, $source, 0, 0, 0, 0, imagesx($resized), imagesy($resized), $width, $height);
            // Da PHP 8 la bitmap si libera quando nessuno la referenzia più.
            $source = $resized;
            unset($resized);
        }

        $image = $this->applyOrientation($source, $orientation);
        unset($source);

        ob_start();
        imagejpeg($image, null, self::JPEG_QUALITY);

        return (string) ob_get_clean();
    }

    /**
     * Rotazioni e specchiature dell'EXIF (1-8). imagerotate gira in senso
     * antiorario: -90 è un quarto di giro in senso orario.
     */
    private function applyOrientation(GdImage $image, int $orientation): GdImage
    {
        $rotate = fn (GdImage $image, int $angle): GdImage => imagerotate($image, $angle, 0);

        return match ($orientation) {
            2 => tap($image, fn () => imageflip($image, IMG_FLIP_HORIZONTAL)),
            3 => $rotate($image, 180),
            4 => tap($image, fn () => imageflip($image, IMG_FLIP_VERTICAL)),
            5 => tap($rotate($image, -90), fn (GdImage $rotated) => imageflip($rotated, IMG_FLIP_HORIZONTAL)),
            6 => $rotate($image, -90),
            7 => tap($rotate($image, -90), fn (GdImage $rotated) => imageflip($rotated, IMG_FLIP_VERTICAL)),
            8 => $rotate($image, 90),
            default => $image,
        };
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
