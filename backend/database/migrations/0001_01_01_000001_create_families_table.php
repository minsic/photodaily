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
        Schema::create('families', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            // Ricalcolato dalla somma di photos.size_bytes ad ogni upload/cancellazione.
            $table->decimal('storage_used_mb', 12, 2)->default(0);
            // URL del frontend della famiglia (es. https://giopellino.it), usato nei link di invito.
            $table->string('app_url')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('families');
    }
};
