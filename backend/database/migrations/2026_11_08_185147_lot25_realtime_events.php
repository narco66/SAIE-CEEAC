<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lot 25 (§10.22, §17.2) : journal court des changements diffusés en temps réel par flux SSE. Chaque ligne ne porte que
     * la nature du changement et l'écran concerné ; le client relit les données par l'API, bornée au périmètre.
     */
    public function up(): void
    {
        Schema::create('realtime_events', function (Blueprint $table): void {
            $table->id();
            $table->string('topic', 30);
            $table->uuid('screen_id')->nullable();
            $table->string('ref_id', 64)->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('realtime_events');
    }
};
