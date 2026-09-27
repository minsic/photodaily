<?php

namespace App\Http\Middleware;

use App\Models\Family;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Il pannello del servizio: solo per i super-admin e solo sull'indirizzo
 * principale. A tutti gli altri risponde 404, come se non esistesse.
 */
class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(
            $request->user()?->is_super_admin === true
                && Family::normalizeHost($request->getHost()) === Family::mainHost(),
            404,
        );

        return $next($request);
    }
}
