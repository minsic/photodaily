<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Promemoria serale (Web Push): preferenza di ogni utente, spento finché
            // non lo attiva lui. L'orario è nel fuso della famiglia.
            $table->boolean('reminder_enabled')->default(false)->after('role');
            $table->time('reminder_time')->default('20:30:00')->after('reminder_enabled');
            // Giorno (nel fuso della famiglia) dell'ultimo promemoria mandato: uno al giorno.
            $table->date('reminder_last_sent_on')->nullable()->after('reminder_time');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['reminder_enabled', 'reminder_time', 'reminder_last_sent_on']);
        });
    }
};
