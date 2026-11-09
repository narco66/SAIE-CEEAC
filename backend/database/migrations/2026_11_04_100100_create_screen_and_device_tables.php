<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Écrans, lecteurs, appairage et groupes (REF-02, PLY-02, PLY-06).
     */
    public function up(): void
    {
        Schema::create('screens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 40)->unique();
            $table->string('name');
            $table->string('kind', 20)->default('display');
            $table->foreignUuid('location_id')->constrained()->restrictOnDelete();
            $table->uuid('room_id')->nullable();
            $table->string('orientation', 10)->default('landscape');
            $table->string('resolution', 12)->default('1920x1080');
            $table->jsonb('operating_window')->nullable();
            $table->string('window_override_reason')->nullable();
            $table->boolean('audio_enabled')->default(false);
            $table->string('control_channel', 10)->default('none');
            $table->string('panel_state', 12)->nullable();
            $table->timestampTz('panel_state_at')->nullable();
            $table->string('status', 10)->default('active');
            $table->text('notes')->nullable();
            $table->boolean('is_demo')->default(false);
            $table->timestamps();
            $table->index(['location_id', 'status']);
        });
        DB::statement("ALTER TABLE screens ADD CONSTRAINT screens_kind_check CHECK (kind IN ('display','room_panel','orientation'))");
        DB::statement("ALTER TABLE screens ADD CONSTRAINT screens_orientation_check CHECK (orientation IN ('landscape','portrait'))");
        DB::statement("ALTER TABLE screens ADD CONSTRAINT screens_channel_check CHECK (control_channel IN ('none','cec','rs232','api'))");
        DB::statement("ALTER TABLE screens ADD CONSTRAINT screens_status_check CHECK (status IN ('active','inactive'))");

        Schema::create('devices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 40)->unique();
            $table->foreignUuid('screen_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('hardware_id', 120);
            $table->string('status', 10)->default('active');
            $table->string('app_version', 40)->nullable();
            $table->string('platform', 120)->nullable();
            $table->string('reported_resolution', 20)->nullable();
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampTz('last_sync_at')->nullable();
            $table->string('network_status', 12)->default('unknown');
            $table->jsonb('storage')->nullable();
            $table->integer('clock_skew_ms')->nullable();
            $table->unsignedInteger('active_manifest_version')->nullable();
            $table->jsonb('current_playback')->nullable();
            $table->jsonb('last_errors')->nullable();
            $table->jsonb('cache_inventory')->nullable();
            $table->timestampTz('paired_at')->nullable();
            $table->foreignUuid('paired_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignUuid('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('revoke_reason')->nullable();
            $table->boolean('is_demo')->default(false);
            $table->timestamps();
            $table->index(['status', 'last_seen_at']);
        });
        DB::statement("ALTER TABLE devices ADD CONSTRAINT devices_status_check CHECK (status IN ('active','revoked'))");
        DB::statement("ALTER TABLE devices ADD CONSTRAINT devices_network_check CHECK (network_status IN ('unknown','online','suspect','unavailable'))");
        DB::statement("CREATE UNIQUE INDEX devices_one_active_per_screen ON devices (screen_id) WHERE status = 'active'");

        Schema::create('pairing_codes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 12)->unique();
            $table->string('poll_secret_hash', 64);
            $table->string('hardware_id', 120);
            $table->jsonb('device_info')->nullable();
            $table->timestampTz('expires_at');
            $table->timestampTz('used_at')->nullable();
            $table->foreignUuid('device_id')->nullable()->constrained()->nullOnDelete();
            $table->text('issued_token')->nullable();
            $table->timestampTz('token_collected_at')->nullable();
            $table->string('created_ip', 45)->nullable();
            $table->timestamps();
        });

        Schema::create('screen_groups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 40)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignUuid('structure_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('group_members', function (Blueprint $table) {
            $table->foreignUuid('group_id')->constrained('screen_groups')->cascadeOnDelete();
            $table->foreignUuid('screen_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['group_id', 'screen_id']);
        });
    }

    public function down(): void
    {
        foreach (['group_members', 'screen_groups', 'pairing_codes', 'devices', 'screens'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
