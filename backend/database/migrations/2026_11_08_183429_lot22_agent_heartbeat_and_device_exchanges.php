<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lot 22, migration additive :
     * - §10.16 : battement propre de l'agent local (distingue un lecteur arrêté d'une perte réseau ou d'alimentation) ;
     * - §17.6 : journal des échanges des lecteurs avec le serveur (route, statut, durée, volume), purgé selon sa durée.
     */
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            $table->timestampTz('agent_seen_at')->nullable();
            $table->jsonb('agent_report')->nullable();
        });
        Schema::create('device_exchanges', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('device_id')->constrained()->cascadeOnDelete();
            $table->string('endpoint', 60);
            $table->string('method', 8);
            $table->unsignedSmallInteger('status');
            $table->unsignedInteger('duration_ms');
            $table->unsignedInteger('bytes_out')->default(0);
            $table->string('ip', 45)->nullable();
            $table->timestampTz('occurred_at');
            $table->index(['device_id', 'occurred_at']);
            $table->index('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_exchanges');
        Schema::table('devices', function (Blueprint $table): void {
            $table->dropColumn(['agent_seen_at', 'agent_report']);
        });
    }
};
