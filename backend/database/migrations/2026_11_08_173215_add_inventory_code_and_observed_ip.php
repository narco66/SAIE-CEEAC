<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Inventaire et supervision (§5.1, §5.2, §17.10), migration additive :
     * - code d'inventaire patrimonial distinct du code technique de l'écran (unique, facultatif) ;
     * - unicité du numéro de série quand il est renseigné (insensible à la casse) ;
     * - adresse IP observée par le serveur au battement, perte de connexion et événements perdus déclarés par le lecteur.
     */
    public function up(): void
    {
        Schema::table('screens', function (Blueprint $table): void {
            $table->string('inventory_code', 40)->nullable()->unique();
        });
        DB::statement('CREATE UNIQUE INDEX screens_serial_number_unique ON screens (lower(serial_number)) WHERE serial_number IS NOT NULL');
        Schema::table('devices', function (Blueprint $table): void {
            $table->string('observed_ip', 45)->nullable();
            $table->timestampTz('offline_since')->nullable();
            $table->unsignedInteger('events_dropped')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            $table->dropColumn(['observed_ip', 'offline_since', 'events_dropped']);
        });
        DB::statement('DROP INDEX IF EXISTS screens_serial_number_unique');
        Schema::table('screens', function (Blueprint $table): void {
            $table->dropUnique(['inventory_code']);
            $table->dropColumn('inventory_code');
        });
    }
};
