<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            // null = nessun limite
            $table->unsignedInteger('max_photos')->nullable();
            $table->unsignedInteger('max_storage_mb')->nullable();
            $table->unsignedInteger('price_monthly_cents')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Il piano di default deve esistere in ogni ambiente (anche nei test):
        // ogni famiglia creata senza plan_id viene assegnata a questo piano.
        DB::table('plans')->insert([
            'name' => 'Beta',
            'slug' => 'beta',
            'max_photos' => null,
            'max_storage_mb' => null,
            'price_monthly_cents' => 0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
