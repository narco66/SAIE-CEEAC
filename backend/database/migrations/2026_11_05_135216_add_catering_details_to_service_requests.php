<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pause-café et restauration (SAL-15) : nombre de couverts et heure de service pour les types qui l'exigent.
     * L'heure est locale (fuseau institutionnel) pour s'appliquer à chaque occurrence d'une série.
     */
    public function up(): void
    {
        Schema::table('service_types', function (Blueprint $table) {
            $table->boolean('requires_headcount')->default(false)->after('is_active');
        });
        Schema::table('service_requests', function (Blueprint $table) {
            $table->unsignedInteger('headcount')->nullable()->after('details');
            $table->string('serve_time', 5)->nullable()->after('headcount');
        });
        DB::statement('ALTER TABLE service_requests ADD CONSTRAINT service_requests_headcount_positive CHECK (headcount IS NULL OR headcount BETWEEN 1 AND 5000)');
        DB::statement("ALTER TABLE service_requests ADD CONSTRAINT service_requests_serve_time_format CHECK (serve_time IS NULL OR serve_time ~ '^([01][0-9]|2[0-3]):[0-5][0-9]$')");
        DB::table('service_types')->where('code', 'RESTAURATION')->update(['requires_headcount' => true, 'is_active' => true, 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE service_requests DROP CONSTRAINT IF EXISTS service_requests_serve_time_format');
        DB::statement('ALTER TABLE service_requests DROP CONSTRAINT IF EXISTS service_requests_headcount_positive');
        Schema::table('service_requests', fn (Blueprint $table) => $table->dropColumn(['headcount', 'serve_time']));
        Schema::table('service_types', fn (Blueprint $table) => $table->dropColumn('requires_headcount'));
    }
};
