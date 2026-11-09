<?php

use Database\Seeders\ReferenceSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Habilitations du module écrans (§14) :
     * - périmètre d'une affectation limitable à un niveau ou à un groupe d'écrans (en plus du site et de la zone) ;
     * - rôles « Responsable du parc » et « Superviseur », créés avec leurs permissions (config/saie.php) ; aucun rôle
     *   existant n'est modifié.
     */
    public function up(): void
    {
        Schema::table('role_assignments', function (Blueprint $table): void {
            $table->foreignUuid('floor_id')->nullable()->constrained('floors');
            $table->foreignUuid('group_id')->nullable()->constrained('screen_groups');
        });
        (new ReferenceSeeder)->run();
    }

    /**
     * Retour arrière des colonnes seulement : les rôles créés restent (des affectations peuvent y faire référence).
     */
    public function down(): void
    {
        Schema::table('role_assignments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('group_id');
            $table->dropConstrainedForeignId('floor_id');
        });
    }
};
