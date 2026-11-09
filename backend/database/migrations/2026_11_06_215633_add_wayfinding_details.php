<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Orientation des visiteurs et typologie des espaces (PAR-04, ORI-01) : type d'emplacement, plan de niveau (image de la
     * médiathèque), bureau et téléphone d'accueil des structures, borne d'orientation tactile. Ajout pur.
     */
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->string('type', 20)->default('other');
        });
        DB::statement("ALTER TABLE locations ADD CONSTRAINT locations_type_check CHECK (type IN ('reception','office','meeting_room','common_area','corridor','restaurant','technical','other'))");
        Schema::table('floors', function (Blueprint $table) {
            $table->foreignUuid('plan_asset_version_id')->nullable()->constrained('asset_versions')->nullOnDelete();
        });
        Schema::table('structures', function (Blueprint $table) {
            $table->string('office_location', 120)->nullable();
            $table->string('reception_phone', 40)->nullable();
        });
        Schema::table('screens', function (Blueprint $table) {
            $table->boolean('touch_enabled')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('screens', fn (Blueprint $table) => $table->dropColumn('touch_enabled'));
        Schema::table('structures', fn (Blueprint $table) => $table->dropColumn(['office_location', 'reception_phone']));
        Schema::table('floors', fn (Blueprint $table) => $table->dropConstrainedForeignId('plan_asset_version_id'));
        DB::statement('ALTER TABLE locations DROP CONSTRAINT locations_type_check');
        Schema::table('locations', fn (Blueprint $table) => $table->dropColumn('type'));
    }
};
