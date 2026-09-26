<?php

namespace App\Exceptions;

use App\Models\Plan;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class PlanLimitExceededException extends RuntimeException implements ShouldntReport
{
    public function __construct(
        string $message,
        public readonly string $limit,
        public readonly Plan $plan,
    ) {
        parent::__construct($message);
    }

    public static function photos(Plan $plan): self
    {
        return new self(
            sprintf(
                'Hai raggiunto il limite di %d foto previsto dal piano "%s". Elimina qualche foto o passa a un piano superiore per caricarne altre.',
                $plan->max_photos,
                $plan->name,
            ),
            'max_photos',
            $plan,
        );
    }

    public static function storage(Plan $plan, int $usedBytes, int $incomingBytes): self
    {
        return new self(
            sprintf(
                'Spazio esaurito: il piano "%s" include %d MB e ne stai già usando %s. Questo caricamento (%s) supererebbe il limite. Elimina qualche foto o passa a un piano superiore.',
                $plan->name,
                $plan->max_storage_mb,
                self::megabytes($usedBytes),
                self::megabytes($incomingBytes),
            ),
            'max_storage_mb',
            $plan,
        );
    }

    /** Spazio già tutto occupato, senza un caricamento preciso in mente. */
    public static function full(Plan $plan): self
    {
        return new self(
            sprintf(
                'Lo spazio del piano "%s" (%d MB) è tutto occupato. Elimina qualche foto o passa a un piano superiore per caricarne altre.',
                $plan->name,
                $plan->max_storage_mb,
            ),
            'max_storage_mb',
            $plan,
        );
    }

    /**
     * 422 come gli altri errori del caricamento: il frontend mostra il
     * messaggio sotto la foto, senza trattarlo come un permesso negato.
     */
    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'errors' => ['image' => [$this->getMessage()]],
            'code' => 'plan_limit_exceeded',
            'limit' => $this->limit,
            'plan' => $this->plan->slug,
        ], 422);
    }

    private static function megabytes(int $bytes): string
    {
        return number_format($bytes / 1024 / 1024, 1, ',', '.').' MB';
    }
}
