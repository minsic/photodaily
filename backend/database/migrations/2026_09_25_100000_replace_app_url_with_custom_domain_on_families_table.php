<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * L'indirizzo di una famiglia non è più un URL libero (app_url) ma si ricava:
 * sottodominio <slug>.<dominio principale> sempre, più un dominio proprio
 * facoltativo (custom_domain). Gli app_url che puntano a un dominio vero
 * diventano custom_domain; quelli di sviluppo (localhost) si perdono.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('families', function (Blueprint $table) {
            // Solo host, minuscolo e senza "www." (es. giopellino.it).
            $table->string('custom_domain')->nullable()->unique()->after('slug');
        });

        DB::table('families')->whereNotNull('app_url')->orderBy('id')->each(function (object $family) {
            $host = strtolower((string) parse_url($family->app_url, PHP_URL_HOST));
            $host = preg_replace('/^www\./', '', $host);

            if (str_contains($host, '.') && ! filter_var($host, FILTER_VALIDATE_IP)) {
                DB::table('families')->where('id', $family->id)->update(['custom_domain' => $host]);
            }
        });

        Schema::table('families', function (Blueprint $table) {
            $table->dropColumn('app_url');
        });
    }

    public function down(): void
    {
        Schema::table('families', function (Blueprint $table) {
            $table->string('app_url')->nullable()->after('storage_used_mb');
        });

        DB::table('families')->whereNotNull('custom_domain')->orderBy('id')->each(function (object $family) {
            DB::table('families')->where('id', $family->id)->update(['app_url' => 'https://'.$family->custom_domain]);
        });

        Schema::table('families', function (Blueprint $table) {
            $table->dropUnique(['custom_domain']);
            $table->dropColumn('custom_domain');
        });
    }
};
