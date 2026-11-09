<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lot 20, migration additive (§5, §6, §15.1) :
     * - catégories d'écran paramétrables et classement de l'écran (interfaces physiques, mode de fonctionnement, criticité) ;
     * - niveau « Institution » au-dessus des sites, salle rattachable à un emplacement ;
     * - mise en service explicite (contrôles enregistrés, auteur, date).
     */
    public function up(): void
    {
        Schema::create('screen_categories', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 40)->unique();
            $table->string('name', 120);
            $table->string('description', 300)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });
        Schema::create('institutions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 40)->unique();
            $table->string('name', 160);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });
        Schema::table('sites', function (Blueprint $table): void {
            $table->foreignUuid('institution_id')->nullable()->constrained('institutions')->restrictOnDelete();
        });
        Schema::table('rooms', function (Blueprint $table): void {
            $table->foreignUuid('location_id')->nullable()->constrained('locations')->nullOnDelete();
        });
        Schema::table('screens', function (Blueprint $table): void {
            $table->foreignUuid('category_id')->nullable()->constrained('screen_categories')->restrictOnDelete();
            $table->jsonb('interfaces')->nullable();
            $table->string('operating_mode', 20)->nullable();
            $table->string('criticality', 10)->default('normal');
            $table->timestampTz('commissioned_at')->nullable();
            $table->foreignUuid('commissioned_by')->nullable()->constrained('users');
            $table->jsonb('commissioning_report')->nullable();
        });
        DB::statement("ALTER TABLE screens ADD CONSTRAINT screens_operating_mode_check CHECK (operating_mode IS NULL OR operating_mode IN ('permanent','window','on_demand'))");
        DB::statement("ALTER TABLE screens ADD CONSTRAINT screens_criticality_check CHECK (criticality IN ('low','normal','high','critical'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE screens DROP CONSTRAINT IF EXISTS screens_criticality_check');
        DB::statement('ALTER TABLE screens DROP CONSTRAINT IF EXISTS screens_operating_mode_check');
        Schema::table('screens', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('commissioned_by');
            $table->dropConstrainedForeignId('category_id');
            $table->dropColumn(['interfaces', 'operating_mode', 'criticality', 'commissioned_at', 'commissioning_report']);
        });
        Schema::table('rooms', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('location_id');
        });
        Schema::table('sites', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('institution_id');
        });
        Schema::dropIfExists('institutions');
        Schema::dropIfExists('screen_categories');
    }
};
