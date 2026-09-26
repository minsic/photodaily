<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ruolo con cui entra chi accetta l'invito: i membri li invita l'admin dalle
 * impostazioni, il primo admin di una famiglia nuova lo crea family:create.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invites', function (Blueprint $table) {
            $table->string('role', 16)->default('member')->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('invites', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
