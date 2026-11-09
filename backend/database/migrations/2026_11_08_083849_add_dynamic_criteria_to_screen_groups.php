<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Groupes dynamiques (§8.3) : un groupe est soit statique (membres choisis), soit dynamique (membres recalculés à partir
     * de critères : bâtiment, niveau, zone, salle, type, statut, état de connexion, campagne). Les membres calculés restent
     * dans `group_members`, que le ciblage des campagnes et le manifeste lisent déjà.
     */
    public function up(): void
    {
        Schema::table('screen_groups', function (Blueprint $table): void {
            $table->string('type', 10)->default('static');
            $table->jsonb('criteria')->nullable();
            $table->timestampTz('members_computed_at')->nullable();
        });
        DB::statement("ALTER TABLE screen_groups ADD CONSTRAINT screen_groups_type_check CHECK (type IN ('static','dynamic'))");
        DB::statement("ALTER TABLE screen_groups ADD CONSTRAINT screen_groups_criteria_check CHECK ((type = 'static' AND criteria IS NULL) OR (type = 'dynamic' AND criteria IS NOT NULL))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE screen_groups DROP CONSTRAINT IF EXISTS screen_groups_criteria_check');
        DB::statement('ALTER TABLE screen_groups DROP CONSTRAINT IF EXISTS screen_groups_type_check');
        Schema::table('screen_groups', function (Blueprint $table): void {
            $table->dropColumn(['type', 'criteria', 'members_computed_at']);
        });
    }
};
