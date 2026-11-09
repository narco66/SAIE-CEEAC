<?php

use Database\Seeders\ReferenceSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Commandes à distance (§11, RG-006, RG-007) : file de commandes par lecteur, avec identifiant de transaction, cycle
     * d'états confirmé par le lecteur et compte rendu. Capacités déclarées par le lecteur à chaque battement.
     * Permission dédiée `devices.command`, accordée aux rôles qui gèrent aujourd'hui les écrans ou les incidents (aucun droit perdu).
     */
    public function up(): void
    {
        Schema::create('device_commands', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('device_id')->constrained('devices');
            $table->foreignUuid('screen_id')->nullable()->constrained('screens');
            $table->string('command', 30);
            $table->jsonb('params')->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('reason', 300)->nullable();
            $table->foreignUuid('requested_by')->nullable()->constrained('users');
            $table->timestampTz('requested_at');
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('received_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('expires_at');
            $table->foreignUuid('cancelled_by')->nullable()->constrained('users');
            $table->string('result_message', 500)->nullable();
            $table->jsonb('result_data')->nullable();
            $table->timestamps();
            $table->index(['device_id', 'status']);
            $table->index(['screen_id', 'requested_at']);
        });
        DB::statement("ALTER TABLE device_commands ADD CONSTRAINT device_commands_status_check CHECK (status IN ('pending','sent','received','executed','failed','expired','cancelled'))");
        DB::statement("ALTER TABLE device_commands ADD CONSTRAINT device_commands_command_check CHECK (command IN ('sync','refresh','restart_player','reboot_device','stop_playback','resume_playback','standby','wake','screenshot','logs'))");

        Schema::table('devices', function (Blueprint $table): void {
            $table->jsonb('capabilities')->nullable();
            $table->timestampTz('capabilities_reported_at')->nullable();
            $table->boolean('playback_paused')->default(false);
        });

        (new ReferenceSeeder)->run();
        $command = DB::table('permissions')->where('code', 'devices.command')->value('id');
        $sources = DB::table('permissions')->whereIn('code', ['screens.manage', 'incidents.manage'])->pluck('id');
        foreach (DB::table('permission_role')->whereIn('permission_id', $sources)->distinct()->pluck('role_id') as $roleId) {
            DB::table('permission_role')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $command]);
        }
    }

    public function down(): void
    {
        $command = DB::table('permissions')->where('code', 'devices.command')->value('id');
        DB::table('permission_role')->where('permission_id', $command)->delete();
        DB::table('permissions')->where('id', $command)->delete();
        Schema::table('devices', function (Blueprint $table): void {
            $table->dropColumn(['capabilities', 'capabilities_reported_at', 'playback_paused']);
        });
        Schema::dropIfExists('device_commands');
    }
};
