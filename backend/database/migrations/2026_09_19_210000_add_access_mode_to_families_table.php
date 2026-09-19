<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('families', function (Blueprint $table) {
            $table->enum('access_mode', ['private', 'password', 'public'])->default('private')->after('app_url');
            // Hash della password condivisa, valorizzato solo con access_mode = 'password'.
            // Cambiandolo (o azzerandolo) decadono i token di sola lettura già emessi.
            $table->string('access_password_hash')->nullable()->after('access_mode');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('families', function (Blueprint $table) {
            $table->dropColumn(['access_mode', 'access_password_hash']);
        });
    }
};
