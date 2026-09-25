<?php

namespace App\Http\Controllers\Api;

use App\Enums\AccessMode;
use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveHostFamily;
use App\Models\Family;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SiteController extends Controller
{
    /**
     * Di quale diario è l'host da cui viene servito il frontend.
     *
     * family è null sull'host principale; un host che non è né quello
     * principale né di una famiglia risponde 404. Il frontend lo chiama
     * all'avvio per sapere se mostrare il login o il diario in sola lettura.
     */
    public function show(Request $request): JsonResponse
    {
        $family = ResolveHostFamily::from($request);

        abort_if($family === null && Family::normalizeHost($request->getHost()) !== Family::mainHost(), 404);

        return response()->json([
            'data' => [
                'family' => $family === null ? null : [
                    'name' => $family->name,
                    'slug' => $family->slug,
                    'access_mode' => $family->access_mode,
                    // Chiunque può chiedere /api/site conoscendo l'indirizzo: nome e
                    // data di nascita di un bambino si mostrano solo se il diario è
                    // pubblico. Con la password arrivano dopo lo sblocco
                    // (/api/public/{slug}/profilo), per un diario privato solo ai membri.
                    'protagonist' => $family->access_mode === AccessMode::Public ? $family->protagonist() : null,
                ],
            ],
        ]);
    }

    /**
     * Risposta per l'on-demand TLS di Caddy: 200 se per quel dominio
     * si può chiedere un certificato, 404 altrimenti. Senza questo controllo
     * chiunque potrebbe far generare certificati per domini a caso.
     */
    public function tlsAsk(Request $request): JsonResponse
    {
        $domain = Family::normalizeHost((string) $request->query('domain'));

        abort_unless($domain !== '' && ($domain === Family::mainHost() || Family::forHost($domain) !== null), 404);

        return response()->json(['data' => ['domain' => $domain]]);
    }
}
