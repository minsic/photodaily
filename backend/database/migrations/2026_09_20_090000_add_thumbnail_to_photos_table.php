<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('photos', function (Blueprint $table) {
            // Miniatura su R2 accanto all'originale. Nulla finché non viene
            // generata: le foto già importate si recuperano con
            // `php artisan photos:generate-thumbnails`.
            $table->string('thumbnail_path')->nullable()->after('image_path');
            $table->unsignedBigInteger('thumbnail_bytes')->default(0)->after('size_bytes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('photos', function (Blueprint $table) {
            $table->dropColumn(['thumbnail_path', 'thumbnail_bytes']);
        });
    }
};
