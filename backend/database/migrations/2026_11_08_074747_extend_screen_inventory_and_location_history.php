<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Inventaire et localisation (§5, §6, RG-011), migration additive :
     * - fiche administrative et technique complétée (acquisition, fournisseur, commande, technologie, consommation, MAC,
     *   système, firmware, emplacement précis, position sur le plan d'étage) ;
     * - documents rattachés à un écran (factures, notices, procès-verbaux) ;
     * - responsable et photographie d'un emplacement ;
     * - historique des emplacements de chaque écran, initialisé avec l'emplacement actuel des écrans existants.
     */
    public function up(): void
    {
        Schema::table('screens', function (Blueprint $table): void {
            $table->date('acquired_on')->nullable();
            $table->string('supplier', 120)->nullable();
            $table->string('order_reference', 80)->nullable();
            $table->string('technology', 10)->nullable();
            $table->unsignedSmallInteger('power_watts')->nullable();
            $table->string('mac_address', 17)->nullable();
            $table->string('os_name', 60)->nullable();
            $table->string('firmware_version', 60)->nullable();
            $table->string('location_detail', 200)->nullable();
            $table->decimal('plan_x', 5, 2)->nullable();
            $table->decimal('plan_y', 5, 2)->nullable();
        });
        DB::statement("ALTER TABLE screens ADD CONSTRAINT screens_technology_check CHECK (technology IS NULL OR technology IN ('led','lcd','oled','other'))");
        DB::statement('ALTER TABLE screens ADD CONSTRAINT screens_plan_position_check CHECK ((plan_x IS NULL AND plan_y IS NULL) OR (plan_x BETWEEN 0 AND 100 AND plan_y BETWEEN 0 AND 100))');
        DB::statement("ALTER TABLE screens ADD CONSTRAINT screens_mac_check CHECK (mac_address IS NULL OR mac_address ~ '^([0-9A-F]{2}:){5}[0-9A-F]{2}$')");

        Schema::create('screen_documents', function (Blueprint $table): void {
            $table->foreignUuid('screen_id')->constrained('screens');
            $table->foreignUuid('document_id')->constrained('documents');
            $table->foreignUuid('linked_by')->nullable()->constrained('users');
            $table->timestampTz('linked_at');
            $table->primary(['screen_id', 'document_id']);
        });

        Schema::table('locations', function (Blueprint $table): void {
            $table->foreignUuid('manager_id')->nullable()->constrained('users');
            $table->foreignUuid('photo_asset_version_id')->nullable()->constrained('asset_versions');
        });

        Schema::create('screen_location_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('screen_id')->constrained('screens');
            $table->foreignUuid('location_id')->constrained('locations');
            $table->foreignUuid('room_id')->nullable()->constrained('rooms');
            $table->string('location_detail', 200)->nullable();
            $table->timestampTz('from_at');
            $table->timestampTz('to_at')->nullable();
            $table->foreignUuid('moved_by')->nullable()->constrained('users');
            $table->string('reason', 300)->nullable();
            $table->index(['screen_id', 'from_at']);
        });
        // RG-011 / §6 : un seul emplacement actif par écran.
        DB::statement('CREATE UNIQUE INDEX screen_location_history_one_current ON screen_location_history (screen_id) WHERE to_at IS NULL');
        // Point de départ de l'historique : emplacement actuel de chaque écran, daté de sa création.
        DB::statement("INSERT INTO screen_location_history (screen_id, location_id, room_id, from_at, reason)
            SELECT id, location_id, room_id, COALESCE(created_at, now()), 'Emplacement à la mise en place de l’historique' FROM screens");
    }

    public function down(): void
    {
        Schema::dropIfExists('screen_location_history');
        Schema::table('locations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('manager_id');
            $table->dropConstrainedForeignId('photo_asset_version_id');
        });
        Schema::dropIfExists('screen_documents');
        DB::statement('ALTER TABLE screens DROP CONSTRAINT IF EXISTS screens_technology_check');
        DB::statement('ALTER TABLE screens DROP CONSTRAINT IF EXISTS screens_plan_position_check');
        DB::statement('ALTER TABLE screens DROP CONSTRAINT IF EXISTS screens_mac_check');
        Schema::table('screens', function (Blueprint $table): void {
            $table->dropColumn(['acquired_on', 'supplier', 'order_reference', 'technology', 'power_watts', 'mac_address', 'os_name', 'firmware_version', 'location_detail', 'plan_x', 'plan_y']);
        });
    }
};
