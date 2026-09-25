<?php

namespace App\Http\Middleware;

use App\Models\Family;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Riconosce la famiglia dall'host della richiesta: <slug>.photodaily.app
 * oppure il dominio proprio della famiglia. Il frontend chiama l'API sullo
 * stesso host da cui è servito, quindi l'host dice di quale diario si tratta.
 *
 * Non blocca nulla: sull'host principale, o su un host sconosciuto, la
 * famiglia è semplicemente null. Chi ne ha bisogno la legge con from().
 */
class ResolveHostFamily
{
    public const FAMILY = 'host_family';

    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set(self::FAMILY, Family::forHost($request->getHost()));

        return $next($request);
    }

    public static function from(Request $request): ?Family
    {
        return $request->attributes->get(self::FAMILY);
    }
}
