<?php

namespace App\Support;

use HTMLPurifier;
use HTMLPurifier_Config;

/**
 * La didascalia è HTML minimale: grassetto, corsivo, link e a capo.
 *
 * Tutto passa di qui prima del database, perché il diario pubblico serve le
 * didascalie a visitatori non autenticati: il filtro del frontend non conta,
 * chiunque può parlare direttamente con l'API.
 */
final class CaptionHtml
{
    /** I soli tag che sopravvivono alla sanificazione. */
    private const ALLOWED = 'p,br,strong,em,a[href|title|target|rel]';

    private static ?HTMLPurifier $purifier = null;

    /**
     * Ripulisce l'HTML dell'editor: restituisce null se non resta nulla di
     * visibile (l'editor manda `<p></p>` quando il campo è vuoto).
     */
    public static function sanitize(?string $html): ?string
    {
        if ($html === null || trim($html) === '') {
            return null;
        }

        // Chi chiama l'API a mano può mandare testo semplice: va portato in
        // HTML qui, o gli a capo sparirebbero al primo render.
        if (strip_tags($html) === $html) {
            return self::fromPlainText($html);
        }

        $clean = self::purifier()->purify($html);

        return self::toPlainText($clean) === '' ? null : $clean;
    }

    /**
     * Porta il testo semplice nel formato HTML: righe vuote separano i
     * paragrafi, i singoli a capo restano a capo.
     */
    public static function fromPlainText(?string $text): ?string
    {
        if ($text === null || trim($text) === '') {
            return null;
        }

        $paragraphs = preg_split('/\R{2,}/u', trim($text)) ?: [];

        $html = '';

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);

            if ($paragraph === '') {
                continue;
            }

            // str_replace e non nl2br: quest'ultimo lascerebbe anche l'a capo
            // originale, che toPlainText conterebbe una seconda volta.
            $html .= '<p>'.str_replace(["\r\n", "\n", "\r"], '<br>', e($paragraph)).'</p>';
        }

        return $html === '' ? null : $html;
    }

    /**
     * Versione senza tag, per l'attributo alt delle immagini e per misurare
     * la lunghezza reale di quello che ha scritto l'utente.
     */
    public static function toPlainText(?string $html): string
    {
        if ($html === null) {
            return '';
        }

        $text = preg_replace('/<\/p\s*>|<br\s*\/?>/i', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }

    private static function purifier(): HTMLPurifier
    {
        if (self::$purifier instanceof HTMLPurifier) {
            return self::$purifier;
        }

        $cache = storage_path('framework/cache/htmlpurifier');

        if (! is_dir($cache)) {
            mkdir($cache, 0755, true);
        }

        $config = HTMLPurifier_Config::createDefault();
        $config->set('HTML.Allowed', self::ALLOWED);
        $config->set('HTML.TargetBlank', true);
        $config->set('HTML.Nofollow', true);
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);
        $config->set('AutoFormat.RemoveEmpty', true);
        $config->set('Cache.SerializerPath', $cache);

        return self::$purifier = new HTMLPurifier($config);
    }
}
