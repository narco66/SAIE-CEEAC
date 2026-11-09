<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Module « Gestion des écrans », lot 1 (règles métier), migration additive :
     * - gravité « moyen » des incidents (§10.3 : critique, majeur, moyen, mineur) ;
     * - résultat de traitement d'un incident, exigé à sa résolution (RG-012) ;
     * - état « archivé » d'un écran, avec date et motif (§5.5, RG-013).
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE incidents DROP CONSTRAINT incidents_severity_check');
        DB::statement("ALTER TABLE incidents ADD CONSTRAINT incidents_severity_check CHECK (severity IN ('critical','major','medium','minor'))");
        Schema::table('incidents', function (Blueprint $table): void {
            $table->text('resolution')->nullable()->after('diagnosis');
        });

        DB::statement('ALTER TABLE screens DROP CONSTRAINT screens_status_check');
        DB::statement("ALTER TABLE screens ADD CONSTRAINT screens_status_check CHECK (status IN ('active','maintenance','inactive','archived'))");
        Schema::table('screens', function (Blueprint $table): void {
            $table->timestampTz('archived_at')->nullable();
            $table->string('archive_reason', 300)->nullable();
        });
    }

    /**
     * Retour arrière possible tant qu'aucun incident « moyen » ni écran archivé n'existe (sinon la contrainte d'origine échoue,
     * ce qui protège les données).
     */
    public function down(): void
    {
        Schema::table('screens', function (Blueprint $table): void {
            $table->dropColumn(['archived_at', 'archive_reason']);
        });
        DB::statement('ALTER TABLE screens DROP CONSTRAINT screens_status_check');
        DB::statement("ALTER TABLE screens ADD CONSTRAINT screens_status_check CHECK (status IN ('active','maintenance','inactive'))");
        Schema::table('incidents', function (Blueprint $table): void {
            $table->dropColumn('resolution');
        });
        DB::statement('ALTER TABLE incidents DROP CONSTRAINT incidents_severity_check');
        DB::statement("ALTER TABLE incidents ADD CONSTRAINT incidents_severity_check CHECK (severity IN ('critical','major','minor'))");
    }
};
