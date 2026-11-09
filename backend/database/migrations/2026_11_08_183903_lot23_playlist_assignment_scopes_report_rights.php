<?php

use Database\Seeders\ReferenceSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lot 23, migration additive :
     * - §12-a : programme permanent issu d'une liste de lecture affectée directement (lien campagne → liste) ;
     * - §14-8 : habilitation limitée à un bâtiment ou à un écran ;
     * - §13.3-k : permission dédiée aux rapports du parc, accordée aux rôles qui consultent déjà les rapports (aucun droit
     *   perdu), puis paramétrable rôle par rôle ; §14-1 : rôle « Administrateur SAIE » créé par le référentiel.
     */
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->foreignUuid('playlist_id')->nullable()->constrained('playlists')->nullOnDelete();
        });
        Schema::table('role_assignments', function (Blueprint $table): void {
            $table->foreignUuid('building_id')->nullable()->constrained('buildings')->nullOnDelete();
            $table->foreignUuid('screen_id')->nullable()->constrained('screens')->nullOnDelete();
        });
        (new ReferenceSeeder)->run();
        $fleet = DB::table('permissions')->where('code', 'reports.fleet')->value('id');
        $source = DB::table('permissions')->where('code', 'reports.view')->value('id');
        foreach (DB::table('permission_role')->where('permission_id', $source)->distinct()->pluck('role_id') as $roleId) {
            DB::table('permission_role')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $fleet]);
        }
    }

    public function down(): void
    {
        $fleet = DB::table('permissions')->where('code', 'reports.fleet')->value('id');
        DB::table('permission_role')->where('permission_id', $fleet)->delete();
        DB::table('permissions')->where('id', $fleet)->delete();
        Schema::table('role_assignments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('screen_id');
            $table->dropConstrainedForeignId('building_id');
        });
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('playlist_id');
        });
    }
};
