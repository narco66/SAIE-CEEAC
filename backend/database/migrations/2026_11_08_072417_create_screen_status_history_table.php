<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Supervision (§10, RG-014) :
     * - historique des états opérationnels de chaque écran (une ligne par période, fermée au changement d'état) ;
     * - informations matérielles et logicielles déclarées par le lecteur à chaque battement (écran, cœurs, mémoire, langue) ;
     * - index de lecture des erreurs déclarées sur une heure.
     */
    public function up(): void
    {
        Schema::create('screen_status_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('screen_id')->constrained('screens');
            $table->foreignUuid('device_id')->nullable()->constrained('devices');
            $table->string('state', 30);
            $table->string('network_status', 20)->nullable();
            $table->string('panel_state', 20)->nullable();
            $table->timestampTz('started_at');
            $table->timestampTz('ended_at')->nullable();
            $table->index(['screen_id', 'started_at']);
        });
        // Une seule période ouverte par écran.
        DB::statement('CREATE UNIQUE INDEX screen_status_history_one_open ON screen_status_history (screen_id) WHERE ended_at IS NULL');

        Schema::table('devices', function (Blueprint $table): void {
            $table->jsonb('hardware_info')->nullable();
        });
        Schema::table('device_receipts', function (Blueprint $table): void {
            $table->index(['device_id', 'kind', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::table('device_receipts', function (Blueprint $table): void {
            $table->dropIndex(['device_id', 'kind', 'occurred_at']);
        });
        Schema::table('devices', function (Blueprint $table): void {
            $table->dropColumn('hardware_info');
        });
        Schema::dropIfExists('screen_status_history');
    }
};
