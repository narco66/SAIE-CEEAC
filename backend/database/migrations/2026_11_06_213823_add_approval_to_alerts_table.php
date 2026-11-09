<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Double validation des alertes non urgentes adressées à tout le parc (PUB-05, décision D3 à valider) : statut « en attente »,
     * durée demandée conservée, approbateur distinct de l'auteur. Ajout pur : les alertes existantes sont inchangées.
     */
    public function up(): void
    {
        Schema::table('alerts', function (Blueprint $table) {
            $table->unsignedSmallInteger('requested_duration_min')->nullable();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
        });
        DB::statement('ALTER TABLE alerts DROP CONSTRAINT alerts_status_check');
        DB::statement("ALTER TABLE alerts ADD CONSTRAINT alerts_status_check CHECK (status IN ('pending','active','revoked','expired'))");
    }

    /**
     * Retour arrière refusé tant qu'une alerte est en attente (l'ancienne contrainte ne l'accepterait pas).
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE alerts DROP CONSTRAINT alerts_status_check');
        DB::statement("ALTER TABLE alerts ADD CONSTRAINT alerts_status_check CHECK (status IN ('active','revoked','expired'))");
        Schema::table('alerts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['requested_duration_min', 'approved_at']);
        });
    }
};
