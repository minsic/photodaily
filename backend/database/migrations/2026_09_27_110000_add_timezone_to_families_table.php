<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('families', function (Blueprint $table) {
            // Fuso orario con cui si decide che giorno è "oggi" per la famiglia:
            // calendario, date delle foto, orario dei promemoria.
            $table->string('timezone', 64)->default('Europe/Rome')->after('protagonist_birthdate');
        });
    }

    public function down(): void
    {
        Schema::table('families', function (Blueprint $table) {
            $table->dropColumn('timezone');
        });
    }
};
