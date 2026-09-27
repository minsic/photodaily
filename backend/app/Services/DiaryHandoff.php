<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Passaggio da photodaily.app al sottodominio del proprio diario senza
 * rimettere la password: il token Sanctum vive nel localStorage di ogni
 * origine, quindi si porta con sé solo un codice monouso di pochi minuti,
 * nel frammento dell'indirizzo (non arriva al server né nei log di Caddy).
 */
class DiaryHandoff
{
    private const MINUTES = 5;

    /**
     * @param  bool  $welcome  diario appena creato: all'arrivo si propone la prima foto
     * @param  int|null  $fromToken  sessione di photodaily.app da cui si parte, da chiudere insieme
     */
    public function url(User $user, bool $welcome = false, ?int $fromToken = null): string
    {
        $code = Str::random(48);

        Cache::put(
            $this->key($code),
            ['user' => $user->id, 'welcome' => $welcome, 'from_token' => $fromToken],
            now()->addMinutes(self::MINUTES),
        );

        return $user->family->url().'/entra#'.$code;
    }

    /**
     * Una volta sola: dopo la lettura il codice non vale più.
     *
     * @return array{user: int, welcome: bool, from_token: int|null}|null
     */
    public function redeem(string $code): ?array
    {
        $data = Cache::pull($this->key($code));

        return is_array($data) ? $data : null;
    }

    private function key(string $code): string
    {
        return 'photodaily:ingresso:'.hash('sha256', $code);
    }
}
