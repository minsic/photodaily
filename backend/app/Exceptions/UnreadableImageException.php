<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Il file ha l'aspetto di una foto ma non si lascia decodificare (troncato,
 * danneggiato): per chi carica è un errore di validazione, non del server.
 */
class UnreadableImageException extends RuntimeException implements ShouldntReport
{
    public function render(): JsonResponse
    {
        $message = 'Non riusciamo a leggere questa foto: forse è danneggiata. Prova a salvarla come JPG e a ricaricarla.';

        return response()->json(['message' => $message, 'errors' => ['image' => [$message]]], 422);
    }
}
