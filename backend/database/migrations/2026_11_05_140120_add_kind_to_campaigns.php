<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mode événement (PUB-12) : une campagne « event » remplace le programme ordinaire des écrans ciblés pendant sa période,
     * date de fin obligatoire et durée bornée ; le programme ordinaire reprend seul à son expiration.
     */
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->string('kind', 10)->default('standard')->after('name');
        });
        DB::statement("ALTER TABLE campaigns ADD CONSTRAINT campaigns_kind_check CHECK (kind IN ('standard','event'))");
        DB::statement("ALTER TABLE campaigns ADD CONSTRAINT campaigns_event_duration_check CHECK (kind <> 'event' OR ends_at <= starts_at + interval '31 days')");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE campaigns DROP CONSTRAINT IF EXISTS campaigns_event_duration_check');
        DB::statement('ALTER TABLE campaigns DROP CONSTRAINT IF EXISTS campaigns_kind_check');
        Schema::table('campaigns', fn (Blueprint $table) => $table->dropColumn('kind'));
    }
};
