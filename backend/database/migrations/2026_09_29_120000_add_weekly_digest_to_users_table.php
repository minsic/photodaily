<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Riepilogo della settimana via email, la domenica sera: attivo finché
            // la persona non lo spegne dal profilo.
            $table->boolean('weekly_digest_enabled')->default(true)->after('reminder_last_sent_on');
            // La domenica (nel fuso della famiglia) dell'ultimo riepilogo: uno a settimana.
            $table->date('weekly_digest_last_sent_on')->nullable()->after('weekly_digest_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['weekly_digest_enabled', 'weekly_digest_last_sent_on']);
        });
    }
};
