<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * La sessione aperta su un diario col codice monouso partito da
     * photodaily.app ricorda quella d'origine: uscendo da una si chiude
     * anche l'altra, altrimenti photodaily.app rimanderebbe dentro.
     */
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->unsignedBigInteger('linked_token_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropIndex(['linked_token_id']);
            $table->dropColumn('linked_token_id');
        });
    }
};
