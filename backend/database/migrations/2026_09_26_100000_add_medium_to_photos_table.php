<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('photos', function (Blueprint $table) {
            // Versione media (lato lungo 1400px) per la timeline, su R2 accanto
            // a originale e miniatura. Nulla finché non viene generata: le foto
            // già caricate si recuperano con `php artisan photos:generate-medium`.
            $table->string('medium_path')->nullable()->after('thumbnail_path');
            $table->unsignedBigInteger('medium_bytes')->default(0)->after('thumbnail_bytes');
            // Dimensioni della versione media, già ruotata secondo l'EXIF: sono
            // quelle da usare per le proporzioni in pagina.
            $table->unsignedInteger('medium_width')->nullable()->after('height');
            $table->unsignedInteger('medium_height')->nullable()->after('medium_width');
        });
    }

    public function down(): void
    {
        Schema::table('photos', function (Blueprint $table) {
            $table->dropColumn(['medium_path', 'medium_bytes', 'medium_width', 'medium_height']);
        });
    }
};
