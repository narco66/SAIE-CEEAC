<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lot 21, migration additive (§9.2, §9.6, §9.8) : identité propre du lecteur (nom, type matériel, n° de série),
     * renouvellement forcé de ses identifiants et version épinglée pour une mise à jour ciblée.
     */
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            $table->string('name', 120)->nullable();
            $table->string('hardware_type', 60)->nullable();
            $table->string('serial_number', 80)->nullable();
            $table->timestampTz('rotate_requested_at')->nullable();
            $table->foreignUuid('pinned_release_id')->nullable()->constrained('player_releases')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('pinned_release_id');
            $table->dropColumn(['name', 'hardware_type', 'serial_number', 'rotate_requested_at']);
        });
    }
};
