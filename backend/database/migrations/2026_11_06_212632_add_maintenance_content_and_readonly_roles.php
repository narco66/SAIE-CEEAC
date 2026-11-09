<?php

use Database\Seeders\ReferenceSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Rôles « technicien de maintenance », « gestionnaire des contenus » et « consultation » (ROL-01, décision D4 à valider).
     * Le référentiel rejouable crée seulement ce qui manque : les rôles et permissions existants ne sont pas modifiés.
     */
    public function up(): void
    {
        (new ReferenceSeeder)->run();
    }

    /**
     * Retour arrière : retire les trois rôles s'ils ne sont attribués à personne (jamais d'affectation supprimée).
     */
    public function down(): void
    {
        $codes = ['technicien', 'gestionnaire_contenus', 'consultation'];
        $ids = DB::table('roles')->whereIn('code', $codes)->pluck('id');
        if (DB::table('role_assignments')->whereIn('role_id', $ids)->exists()) {
            throw new RuntimeException('Des affectations utilisent encore ces rôles : révoquez-les avant le retour arrière.');
        }
        DB::table('permission_role')->whereIn('role_id', $ids)->delete();
        DB::table('roles')->whereIn('id', $ids)->delete();
    }
};
