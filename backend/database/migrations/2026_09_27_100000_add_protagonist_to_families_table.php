<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('families', function (Blueprint $table) {
            // Di chi è il diario (es. "Gio"): serve a scrivere l'età sotto ogni
            // foto. Entrambi facoltativi, li imposta un admin della famiglia.
            $table->string('protagonist_name', 60)->nullable()->after('name');
            $table->date('protagonist_birthdate')->nullable()->after('protagonist_name');
        });
    }

    public function down(): void
    {
        Schema::table('families', function (Blueprint $table) {
            $table->dropColumn(['protagonist_name', 'protagonist_birthdate']);
        });
    }
};
