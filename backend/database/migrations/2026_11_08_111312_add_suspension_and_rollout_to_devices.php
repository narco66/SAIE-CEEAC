<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lecteurs (§9, §10.7, §21.4), migration additive :
     * - suspension réversible d'un lecteur (identité et jeton conservés, programme neutralisé par le serveur) ;
     * - déploiement progressif d'une version du lecteur : part du parc du canal qui la reçoit (100 % = tout le canal).
     */
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            $table->timestampTz('suspended_at')->nullable();
            $table->foreignUuid('suspended_by')->nullable()->constrained('users');
            $table->string('suspend_reason', 300)->nullable();
        });
        Schema::table('player_releases', function (Blueprint $table): void {
            $table->unsignedSmallInteger('rollout_percent')->default(100);
        });
        DB::statement('ALTER TABLE player_releases ADD CONSTRAINT player_releases_rollout_check CHECK (rollout_percent BETWEEN 1 AND 100)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE player_releases DROP CONSTRAINT IF EXISTS player_releases_rollout_check');
        Schema::table('player_releases', function (Blueprint $table): void {
            $table->dropColumn('rollout_percent');
        });
        Schema::table('devices', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('suspended_by');
            $table->dropColumn(['suspended_at', 'suspend_reason']);
        });
    }
};
