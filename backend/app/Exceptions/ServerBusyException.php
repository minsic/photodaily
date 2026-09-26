<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Troppe foto grezze da ricodificare nello stesso momento: si chiede di
 * riprovare invece di rischiare di finire la memoria del server.
 */
class ServerBusyException extends RuntimeException implements ShouldntReport
{
    public function render(): JsonResponse
    {
        $message = 'Il server sta già elaborando altre foto: riprova tra qualche secondo.';

        return response()->json(['message' => $message, 'errors' => ['image' => [$message]]], 503, ['Retry-After' => '10']);
    }
}
