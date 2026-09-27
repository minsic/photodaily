<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Con il diario sospeso le sessioni già aperte non servono più a niente:
 * il frontend riceve 403 e torna al login, che spiega il perché.
 */
class EnsureFamilyActive
{
    public const MESSAGE = 'Questo diario è sospeso. Per informazioni scrivi a chi gestisce PhotoDaily.';

    public function handle(Request $request, Closure $next): Response
    {
        abort_if($request->user()?->family?->isSuspended(), 403, self::MESSAGE);

        return $next($request);
    }
}
