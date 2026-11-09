<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Catégorie et nature (corrective, préventive) des incidents et interventions, diagnostic consigné.
     * Ajout pur : les incidents existants reçoivent la catégorie « lecteur » s'ils sont automatiques, « autre » sinon.
     */
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->string('category', 20)->default('other');
            $table->string('maintenance_type', 12)->default('corrective');
            $table->text('diagnosis')->nullable();
        });
        DB::table('incidents')->where('kind', 'device_unavailable')->update(['category' => 'player']);
        DB::statement("ALTER TABLE incidents ADD CONSTRAINT incidents_category_check CHECK (category IN ('player','panel','network','power','content','software','other'))");
        DB::statement("ALTER TABLE incidents ADD CONSTRAINT incidents_maintenance_type_check CHECK (maintenance_type IN ('corrective','preventive'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE incidents DROP CONSTRAINT incidents_category_check');
        DB::statement('ALTER TABLE incidents DROP CONSTRAINT incidents_maintenance_type_check');
        Schema::table('incidents', function (Blueprint $table) {
            $table->dropColumn(['category', 'maintenance_type', 'diagnosis']);
        });
    }
};
