<?php

use Database\Seeders\ReferenceSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Décision D7 (validée le 7 novembre) : permission dédiée aux versions du lecteur, accordée à chaque rôle qui traite
     * aujourd'hui les incidents (aucun droit perdu) ; `exports.create` accordé au technicien de maintenance, qui garde
     * ainsi son rapport d'incidents désormais soumis à cette permission.
     */
    public function up(): void
    {
        (new ReferenceSeeder)->run();
        $releases = DB::table('permissions')->where('code', 'player_releases.manage')->value('id');
        $incidents = DB::table('permissions')->where('code', 'incidents.manage')->value('id');
        foreach (DB::table('permission_role')->where('permission_id', $incidents)->pluck('role_id') as $roleId) {
            DB::table('permission_role')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $releases]);
        }
        $exports = DB::table('permissions')->where('code', 'exports.create')->value('id');
        $technician = DB::table('roles')->where('code', 'technicien')->value('id');
        if ($exports && $technician) {
            DB::table('permission_role')->insertOrIgnore(['role_id' => $technician, 'permission_id' => $exports]);
        }
    }

    /**
     * Retour arrière : la gestion des versions revient à la permission des incidents ; aucune affectation n'est supprimée.
     */
    public function down(): void
    {
        $releases = DB::table('permissions')->where('code', 'player_releases.manage')->value('id');
        DB::table('permission_role')->where('permission_id', $releases)->delete();
        DB::table('permissions')->where('id', $releases)->delete();
    }
};
