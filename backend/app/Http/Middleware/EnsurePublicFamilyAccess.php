<?php

namespace App\Http\Middleware;

use App\Enums\AccessMode;
use App\Models\Family;
use App\Services\FamilyReadTokens;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controlla l'accesso in sola lettura alle rotte pubbliche di una famiglia.
 *
 * Famiglia inesistente e famiglia in modalità "private" rispondono entrambe
 * 404 identico: chi indovina uno slug non scopre né che esiste né come è
 * configurata. La famiglia risolta viene passata al controller.
 */
class EnsurePublicFamilyAccess
{
    public const FAMILY = 'public_family';

    public function __construct(private readonly FamilyReadTokens $tokens) {}

    public function handle(Request $request, Closure $next): Response
    {
        $family = Family::query()->where('slug', $request->route('family_slug'))->first();

        abort_if($family === null || $family->access_mode === AccessMode::Private, 404);

        if ($family->access_mode === AccessMode::Password
            && ! $this->tokens->isValidFor($this->tokenFrom($request), $family)) {
            abort(401, 'Serve la password della famiglia per vedere queste foto.');
        }

        $request->attributes->set(self::FAMILY, $family);

        return $next($request);
    }

    private function tokenFrom(Request $request): ?string
    {
        return $request->bearerToken() ?: $request->query('access_token');
    }
}
