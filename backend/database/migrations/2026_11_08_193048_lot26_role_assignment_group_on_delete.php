<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Lot 26 (§14-8) : la suppression d'un groupe d'écrans échouait (clé étrangère sans règle) dès qu'une habilitation,
     * même révoquée, le référençait. Les habilitations actives bloquent toujours la suppression (contrôle applicatif) ;
     * les habilitations révoquées perdent seulement la référence au groupe.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE role_assignments DROP CONSTRAINT role_assignments_group_id_foreign');
        DB::statement('ALTER TABLE role_assignments ADD CONSTRAINT role_assignments_group_id_foreign FOREIGN KEY (group_id) REFERENCES screen_groups(id) ON DELETE SET NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE role_assignments DROP CONSTRAINT role_assignments_group_id_foreign');
        DB::statement('ALTER TABLE role_assignments ADD CONSTRAINT role_assignments_group_id_foreign FOREIGN KEY (group_id) REFERENCES screen_groups(id)');
    }
};
