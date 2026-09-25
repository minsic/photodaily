<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sull'indirizzo di una famiglia si entra solo con un account di quella
 * famiglia. I dati restano comunque filtrati sulla famiglia dell'utente:
 * questo impedisce solo di vedere il diario di A sotto il dominio di B.
 * Risponde 404 come per le risorse di altre famiglie, mai 403.
 */
class EnsureHostFamilyMember
{
    public function handle(Request $request, Closure $next): Response
    {
        $family = ResolveHostFamily::from($request);

        if ($family !== null && $request->user()?->family_id !== $family->id) {
            abort(404, 'Not Found');
        }

        return $next($request);
    }
}
