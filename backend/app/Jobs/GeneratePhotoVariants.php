<?php

namespace App\Jobs;

use App\Models\Photo;
use App\Services\PhotoStorage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Versione media e miniatura di una foto appena caricata. Gira sul worker
 * della coda: l'upload risponde subito e una raffica di foto (recupero dei
 * giorni mancanti) non tiene occupati i processi web. Nel frattempo l'API
 * mostra l'originale al posto della miniatura.
 */
class GeneratePhotoVariants implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    /** Una foto cancellata prima del job non ha niente da generare. */
    public bool $deleteWhenMissingModels = true;

    public function __construct(public Photo $photo)
    {
        // Solo dopo il commit: il worker deve trovare la foto nel database.
        $this->afterCommit();
    }

    public function handle(PhotoStorage $storage): void
    {
        if (! $storage->generateVariants($this->photo)) {
            // Un file che GD non sa leggere non migliora riprovando.
            Log::warning("Versioni ridotte non generate per la foto {$this->photo->id}: immagine non elaborabile.");
        }
    }
}
