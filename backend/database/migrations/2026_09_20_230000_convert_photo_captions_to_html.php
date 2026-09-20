<?php

use App\Support\CaptionHtml;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Le didascalie diventano HTML minimale (l'editor della form). Le vecchie
 * sono testo semplice: vanno rivestite di paragrafi, o il render con v-html
 * le mostrerebbe tutte attaccate su una riga sola.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('photos')
            ->whereNotNull('didascalia')
            ->where('didascalia', '<>', '')
            ->orderBy('id')
            ->chunkById(200, function ($photos) {
                foreach ($photos as $photo) {
                    // Se una riga è già in HTML (import fatto dopo il
                    // passaggio al nuovo formato) riconvertirla la
                    // mostrerebbe con i tag in chiaro.
                    if (str_starts_with(ltrim($photo->didascalia), '<p>')) {
                        continue;
                    }

                    DB::table('photos')
                        ->where('id', $photo->id)
                        ->update(['didascalia' => CaptionHtml::fromPlainText($photo->didascalia)]);
                }
            });
    }

    public function down(): void
    {
        DB::table('photos')
            ->whereNotNull('didascalia')
            ->where('didascalia', '<>', '')
            ->orderBy('id')
            ->chunkById(200, function ($photos) {
                foreach ($photos as $photo) {
                    DB::table('photos')
                        ->where('id', $photo->id)
                        ->update(['didascalia' => CaptionHtml::toPlainText($photo->didascalia)]);
                }
            });
    }
};
