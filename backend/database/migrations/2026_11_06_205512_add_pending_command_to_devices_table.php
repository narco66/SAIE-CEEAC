<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Commande à distance en attente pour un lecteur (relance de l'application), remise au heartbeat suivant. Ajout pur.
     */
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->string('pending_command', 20)->nullable();
            $table->timestampTz('command_requested_at')->nullable();
            $table->foreignUuid('command_requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('command_delivered_at')->nullable();
        });
        DB::statement("ALTER TABLE devices ADD CONSTRAINT devices_pending_command_check CHECK (pending_command IS NULL OR pending_command IN ('reload'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE devices DROP CONSTRAINT devices_pending_command_check');
        Schema::table('devices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('command_requested_by');
            $table->dropColumn(['pending_command', 'command_requested_at', 'command_delivered_at']);
        });
    }
};
