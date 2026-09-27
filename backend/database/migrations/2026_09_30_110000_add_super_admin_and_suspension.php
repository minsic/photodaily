<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Chi gestisce il servizio: vede tutti i diari da photodaily.app/admin.
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_super_admin')->default(false)->after('role');
        });

        // Diario sospeso: nessuno entra, niente diario pubblico né promemoria.
        Schema::table('families', function (Blueprint $table) {
            $table->timestamp('suspended_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_super_admin');
        });

        Schema::table('families', function (Blueprint $table) {
            $table->dropColumn('suspended_at');
        });
    }
};
