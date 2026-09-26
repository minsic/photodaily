<?php

namespace App\Rules;

use App\Services\MainImage;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * Il formato si riconosce dai primi byte, non dall'estensione né dal MIME
 * dichiarato: così l'HEIC si distingue da un file qualsiasi anche dove
 * finfo non lo conosce, e si può spiegare cosa fare.
 */
class PhotoFile implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            return;
        }

        $format = MainImage::detectFormat((string) file_get_contents($value->getPathname(), length: 16));

        if ($format === null) {
            $fail('Formato non supportato: carica una foto JPG, PNG, WebP o HEIC.');

            return;
        }

        if ($format === 'heic' && ! MainImage::canReadHeic()) {
            $fail("Le foto HEIC non si possono ancora caricare. Dall'iPhone caricala da Safari o dall'app installata, che la convertono da sole in JPG; dal computer salvala come JPG e riprova.");
        }
    }
}
