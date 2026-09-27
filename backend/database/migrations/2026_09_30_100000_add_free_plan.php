<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Il piano di chi si registra da solo: abbastanza per provare il diario
     * per qualche mese. Come "beta", deve esistere in ogni ambiente.
     */
    public function up(): void
    {
        if (DB::table('plans')->where('slug', 'free')->exists()) {
            return;
        }

        DB::table('plans')->insert([
            'name' => 'Gratuito',
            'slug' => 'free',
            'max_photos' => 100,
            'max_storage_mb' => 500,
            'price_monthly_cents' => 0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Solo se nessuna famiglia lo usa: altrimenti la chiave esterna lo impedirebbe.
        DB::table('plans')->where('slug', 'free')
            ->whereNotExists(fn ($query) => $query->from('families')->whereColumn('families.plan_id', 'plans.id'))
            ->delete();
    }
};
