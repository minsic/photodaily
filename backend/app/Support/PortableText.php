<?php

namespace App\Support;

/**
 * Conversione di Portable Text (blocchi Sanity) in testo semplice.
 */
final class PortableText
{
    /**
     * Concatena i children[].text di ogni blocco; i blocchi diventano righe separate.
     */
    public static function toPlainText(mixed $value): ?string
    {
        if (is_string($value)) {
            return self::nullIfBlank($value);
        }

        if (! is_array($value)) {
            return null;
        }

        // Un singolo blocco invece di un array di blocchi.
        if (isset($value['_type'])) {
            $value = [$value];
        }

        $lines = [];

        foreach ($value as $block) {
            if (! is_array($block) || ($block['_type'] ?? 'block') !== 'block') {
                continue;
            }

            $text = '';

            foreach ($block['children'] ?? [] as $child) {
                if (is_array($child) && is_string($child['text'] ?? null)) {
                    $text .= $child['text'];
                }
            }

            if (trim($text) !== '') {
                $lines[] = $text;
            }
        }

        return self::nullIfBlank(implode("\n", $lines));
    }

    private static function nullIfBlank(string $text): ?string
    {
        $text = trim($text);

        return $text === '' ? null : $text;
    }
}
