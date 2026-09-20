<?php

namespace App\Rules;

use App\Support\CaptionHtml;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Il limite vale sul testo scritto, non sul markup: l'utente conta i
 * caratteri che vede, non i tag che l'editor gli mette intorno.
 */
class CaptionLength implements ValidationRule
{
    public const MAX = 5000;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        if (mb_strlen(CaptionHtml::toPlainText($value)) > self::MAX) {
            $fail('La didascalia non può superare i :max caratteri.')->translate(['max' => self::MAX]);
        }
    }
}
