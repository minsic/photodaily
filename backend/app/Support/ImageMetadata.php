<?php

namespace App\Support;

/**
 * Toglie dalle immagini i dati di posizione prima di salvarle, senza
 * ricomprimerle: si lavora sui byte del file, i pixel restano identici.
 *
 * - JPEG: la sezione GPS dell'EXIF viene azzerata (orientamento e data di
 *   scatto restano), i blocchi XMP vengono tolti.
 * - PNG: via i blocchi eXIf, tEXt, zTXt e iTXt (EXIF, XMP, testi liberi).
 * - WebP: via i blocchi EXIF e XMP, con i flag di VP8X aggiornati.
 *
 * Restituisce null se il file non si lascia leggere come ci si aspetta:
 * in quel caso chi chiama deve ricodificare l'immagine (niente metadati).
 */
final class ImageMetadata
{
    private const GPS_IFD_TAG = 0x8825;

    /** Byte per elemento dei tipi TIFF (BYTE, ASCII, SHORT, LONG, RATIONAL, SBYTE, UNDEFINED, SSHORT, SLONG, SRATIONAL, FLOAT, DOUBLE). */
    private const TYPE_SIZES = [1 => 1, 2 => 1, 3 => 2, 4 => 4, 5 => 8, 6 => 1, 7 => 1, 8 => 2, 9 => 4, 10 => 8, 11 => 4, 12 => 8];

    public static function withoutLocation(string $bytes): ?string
    {
        return match (true) {
            str_starts_with($bytes, "\xFF\xD8") => self::jpeg($bytes),
            str_starts_with($bytes, "\x89PNG\r\n\x1A\n") => self::png($bytes),
            str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP' => self::webp($bytes),
            default => null,
        };
    }

    private static function jpeg(string $bytes): ?string
    {
        $out = "\xFF\xD8";
        $pos = 2;
        $length = strlen($bytes);

        while ($pos + 4 <= $length) {
            if ($bytes[$pos] !== "\xFF") {
                return null;
            }

            $marker = ord($bytes[$pos + 1]);

            // Da SOS in poi ci sono i dati dell'immagine: si copiano così come sono.
            if ($marker === 0xDA || $marker === 0xD9) {
                return $out.substr($bytes, $pos);
            }

            $size = unpack('n', substr($bytes, $pos + 2, 2))[1];
            $segment = substr($bytes, $pos, 2 + $size);

            if (strlen($segment) !== 2 + $size) {
                return null;
            }

            if ($marker === 0xE1) {
                $payload = substr($segment, 4);

                if (str_starts_with($payload, "Exif\0\0")) {
                    $tiff = self::blankGps(substr($payload, 6));

                    if ($tiff === null) {
                        return null;
                    }

                    $segment = substr($segment, 0, 4)."Exif\0\0".$tiff;
                } elseif (str_starts_with($payload, 'http://ns.adobe.com/')) {
                    // XMP (anche esteso): può contenere le coordinate.
                    $pos += 2 + $size;

                    continue;
                }
            }

            $out .= $segment;
            $pos += 2 + $size;
        }

        return null;
    }

    /**
     * Azzera la sezione GPS di un blocco TIFF/EXIF lasciandone intatta la
     * lunghezza: gli offset di tutto il resto restano validi.
     */
    private static function blankGps(string $tiff): ?string
    {
        $order = substr($tiff, 0, 2);

        if ($order !== 'II' && $order !== 'MM') {
            return null;
        }

        $u16 = fn (int $at) => $at + 2 <= strlen($tiff) ? unpack($order === 'II' ? 'v' : 'n', substr($tiff, $at, 2))[1] : null;
        $u32 = fn (int $at) => $at + 4 <= strlen($tiff) ? unpack($order === 'II' ? 'V' : 'N', substr($tiff, $at, 4))[1] : null;

        $ifd0 = $u32(4);
        $entries = $ifd0 === null ? null : $u16($ifd0);

        if ($entries === null) {
            return null;
        }

        for ($i = 0; $i < $entries; $i++) {
            $entry = $ifd0 + 2 + 12 * $i;

            if ($u16($entry) !== self::GPS_IFD_TAG) {
                continue;
            }

            $gps = $u32($entry + 8);
            $gpsEntries = $gps === null ? null : $u16($gps);

            if ($gpsEntries === null || $gps + 2 + 12 * $gpsEntries + 4 > strlen($tiff)) {
                return null;
            }

            // Prima i valori lunghi, che stanno fuori dalla tabella...
            for ($j = 0; $j < $gpsEntries; $j++) {
                $gpsEntry = $gps + 2 + 12 * $j;
                $bytes = (self::TYPE_SIZES[$u16($gpsEntry + 2)] ?? 1) * (int) $u32($gpsEntry + 4);

                if ($bytes > 4) {
                    $offset = (int) $u32($gpsEntry + 8);

                    if ($offset + $bytes > strlen($tiff)) {
                        return null;
                    }

                    $tiff = substr_replace($tiff, str_repeat("\0", $bytes), $offset, $bytes);
                }
            }

            // ...poi la tabella: con zero voci resta una sezione GPS vuota e valida.
            $tableBytes = 2 + 12 * $gpsEntries + 4;
            $tiff = substr_replace($tiff, str_repeat("\0", $tableBytes), $gps, $tableBytes);
        }

        return $tiff;
    }

    private static function png(string $bytes): ?string
    {
        $out = substr($bytes, 0, 8);
        $pos = 8;
        $length = strlen($bytes);

        while ($pos + 12 <= $length) {
            $size = unpack('N', substr($bytes, $pos, 4))[1];
            $type = substr($bytes, $pos + 4, 4);
            $chunk = substr($bytes, $pos, 12 + $size);

            if (strlen($chunk) !== 12 + $size) {
                return null;
            }

            if (! in_array($type, ['eXIf', 'tEXt', 'zTXt', 'iTXt'], true)) {
                $out .= $chunk;
            }

            $pos += 12 + $size;

            if ($type === 'IEND') {
                return $out;
            }
        }

        return null;
    }

    private static function webp(string $bytes): ?string
    {
        $chunks = '';
        $pos = 12;
        $length = strlen($bytes);

        while ($pos + 8 <= $length) {
            $type = substr($bytes, $pos, 4);
            $size = unpack('V', substr($bytes, $pos + 4, 4))[1];
            $padded = $size + ($size % 2);
            $chunk = substr($bytes, $pos, 8 + $padded);

            if (strlen($chunk) < 8 + $size) {
                return null;
            }

            if ($type === 'VP8X') {
                // Flag di VP8X: 0x08 = c'è l'EXIF, 0x04 = c'è l'XMP.
                $chunk[8] = chr(ord($chunk[8]) & ~0x0C);
            }

            if ($type !== 'EXIF' && $type !== 'XMP ') {
                $chunks .= $chunk;
            }

            $pos += 8 + $padded;
        }

        return 'RIFF'.pack('V', strlen($chunks) + 4).'WEBP'.$chunks;
    }
}
