<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SEC-04 : le compte applicatif « saie_app » (infra/postgres/10-roles.sh) ne peut que lire et ajouter au journal d'audit.
 * Sans ce rôle (poste de développement), la migration n'a pas d'effet.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::scalar("select count(*) from pg_roles where rolname = 'saie_app'") > 0) {
            DB::statement('GRANT SELECT, INSERT ON audit_logs TO saie_app');
            DB::statement('REVOKE UPDATE, DELETE, TRUNCATE, TRIGGER, REFERENCES ON audit_logs FROM saie_app');
        }
    }

    public function down(): void
    {
        if (DB::scalar("select count(*) from pg_roles where rolname = 'saie_app'") > 0) {
            DB::statement('GRANT UPDATE, DELETE ON audit_logs TO saie_app');
        }
    }
};
