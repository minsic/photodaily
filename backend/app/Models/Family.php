<?php

namespace App\Models;

use App\Enums\AccessMode;
use App\Exceptions\PlanLimitExceededException;
use Database\Factories\FamilyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;

#[Fillable(['name', 'slug', 'custom_domain', 'plan_id'])]
#[Hidden(['access_password_hash'])]
class Family extends Model
{
    /** @use HasFactory<FamilyFactory> */
    use HasFactory;

    /**
     * Slug che non possono diventare sottodomini di una famiglia,
     * perché servono (o serviranno) al servizio stesso.
     */
    public const RESERVED_SLUGS = [
        'admin', 'api', 'app', 'assets', 'blog', 'cdn', 'dev', 'docs', 'help', 'mail',
        'photodaily', 'static', 'staging', 'status', 'support', 'test', 'www',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'access_mode' => AccessMode::Private->value,
    ];

    protected static function booted(): void
    {
        static::creating(function (Family $family) {
            $family->plan_id ??= Plan::default()->id;
        });
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * @return HasMany<Photo, $this>
     */
    public function photos(): HasMany
    {
        return $this->hasMany(Photo::class);
    }

    /**
     * @return HasMany<Invite, $this>
     */
    public function invites(): HasMany
    {
        return $this->hasMany(Invite::class);
    }

    /**
     * Blocca il salvataggio se aggiungere $photos foto per $bytes byte
     * supererebbe i limiti del piano della famiglia.
     *
     * @throws PlanLimitExceededException
     */
    public function ensureCanStore(int $bytes, int $photos = 1): void
    {
        $plan = $this->plan;

        if ($photos > 0 && $plan->max_photos !== null && $this->photos()->count() + $photos > $plan->max_photos) {
            throw PlanLimitExceededException::photos($plan);
        }

        if ($bytes > 0 && $plan->max_storage_mb !== null) {
            $used = $this->storageUsedBytes();

            if ($used + $bytes > $plan->maxStorageBytes()) {
                throw PlanLimitExceededException::storage($plan, $used, $bytes);
            }
        }
    }

    public function storageUsedBytes(): int
    {
        return (int) $this->photos()
            ->selectRaw('COALESCE(SUM(size_bytes), 0) + COALESCE(SUM(thumbnail_bytes), 0) + COALESCE(SUM(medium_bytes), 0) as bytes')
            ->value('bytes');
    }

    /**
     * Ricalcola storage_used_mb dalla dimensione reale dei file salvati.
     */
    public function refreshStorageUsage(): void
    {
        $this->forceFill([
            'storage_used_mb' => round($this->storageUsedBytes() / 1024 / 1024, 2),
        ])->save();
    }

    /**
     * Cambia la modalità di accesso in sola lettura. Uscire dalla modalità
     * "password" azzera l'hash, e questo invalida i token già emessi.
     */
    public function changeAccessMode(AccessMode $mode, ?string $password = null): void
    {
        if ($mode === AccessMode::Password && blank($password)) {
            throw new InvalidArgumentException('La modalità "password" richiede una password condivisa.');
        }

        $this->forceFill([
            'access_mode' => $mode,
            'access_password_hash' => $mode === AccessMode::Password ? Hash::make($password) : null,
        ])->save();
    }

    /**
     * Host principale del servizio (es. photodaily.app), ricavato da FRONTEND_URL.
     * Le famiglie ne sono sottodomini: <slug>.photodaily.app.
     */
    public static function mainHost(): string
    {
        return strtolower((string) parse_url(config('photodaily.frontend_url'), PHP_URL_HOST));
    }

    /**
     * Famiglia servita dall'host indicato: il suo sottodominio o il suo
     * dominio proprio, con o senza "www.". Null per l'host principale
     * e per qualsiasi host che non corrisponde a una famiglia.
     */
    public static function forHost(string $host): ?self
    {
        $host = static::normalizeHost($host);
        $suffix = '.'.static::mainHost();

        if (str_ends_with($host, $suffix)) {
            $slug = substr($host, 0, -strlen($suffix));

            return str_contains($slug, '.') ? null : static::query()->where('slug', $slug)->first();
        }

        return $host === '' ? null : static::query()->where('custom_domain', $host)->first();
    }

    /**
     * Minuscolo, senza porta, punto finale e "www." iniziale.
     */
    public static function normalizeHost(string $host): string
    {
        $host = strtolower(rtrim(preg_replace('/:\d+$/', '', trim($host)), '.'));

        return preg_replace('/^www\./', '', $host);
    }

    /**
     * Indirizzo del diario: il dominio proprio se c'è, altrimenti il sottodominio.
     * Schema e porta sono quelli di FRONTEND_URL (in sviluppo http e :5173).
     */
    public function url(): string
    {
        $frontend = parse_url(config('photodaily.frontend_url'));
        $host = $this->custom_domain ?: $this->slug.'.'.static::mainHost();
        $port = isset($frontend['port']) ? ':'.$frontend['port'] : '';

        return ($frontend['scheme'] ?? 'https').'://'.$host.$port;
    }

    public function inviteUrl(string $token): string
    {
        return $this->url().str_replace('{token}', $token, config('photodaily.invite_path'));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'storage_used_mb' => 'float',
            'access_mode' => AccessMode::class,
        ];
    }
}
