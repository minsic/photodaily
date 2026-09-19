<?php

namespace App\Services;

use App\Enums\AccessMode;
use App\Models\Family;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;
use JsonException;

/**
 * Token di sola lettura per le famiglie in modalità "password".
 *
 * Il token è una stringa cifrata con la APP_KEY (AES-256 + HMAC): non serve
 * una tabella di revoca perché contiene un'impronta dell'hash della password
 * corrente. Cambiare la password, o uscire dalla modalità "password" (che
 * azzera l'hash), cambia l'impronta e invalida tutti i token già emessi.
 */
class FamilyReadTokens
{
    private const TYPE = 'family-read';

    public function __construct(private readonly Encrypter $encrypter) {}

    /**
     * @return array{0: string, 1: CarbonInterface}
     */
    public function issue(Family $family): array
    {
        $expiresAt = now()->addDays(config('photodaily.public_token_ttl_days'));

        $token = $this->encrypter->encryptString(json_encode([
            'typ' => self::TYPE,
            'fid' => $family->id,
            'ver' => $this->fingerprint($family),
            'exp' => $expiresAt->getTimestamp(),
        ], JSON_THROW_ON_ERROR));

        return [$token, $expiresAt];
    }

    public function isValidFor(?string $token, Family $family): bool
    {
        if ($token === null || $token === '' || $family->access_mode !== AccessMode::Password || $family->access_password_hash === null) {
            return false;
        }

        try {
            $payload = json_decode($this->encrypter->decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            return false;
        }

        return is_array($payload)
            && ($payload['typ'] ?? null) === self::TYPE
            && ($payload['fid'] ?? null) === $family->id
            && is_int($payload['exp'] ?? null)
            && $payload['exp'] > now()->getTimestamp()
            && is_string($payload['ver'] ?? null)
            && hash_equals($this->fingerprint($family), $payload['ver']);
    }

    private function fingerprint(Family $family): string
    {
        return hash_hmac('sha256', self::TYPE.':'.$family->id.':'.$family->access_password_hash, config('app.key'));
    }
}
