<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Canaux de version du lecteur (PLY-12) — migration additive.
     */
    public function up(): void
    {
        Schema::create('player_releases', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('version', 40);
            $table->string('channel', 10);
            $table->string('base_url');
            $table->char('sha256', 64);
            $table->text('notes')->nullable();
            $table->string('status', 10)->default('active');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['version', 'channel']);
        });
        DB::statement("ALTER TABLE player_releases ADD CONSTRAINT player_releases_channel_check CHECK (channel IN ('pilot','stable'))");
        Schema::table('devices', function (Blueprint $table) {
            $table->string('release_channel', 10)->default('stable')->after('app_version');
        });
    }

    public function down(): void
    {
        Schema::table('devices', fn (Blueprint $table) => $table->dropColumn('release_channel'));
        Schema::dropIfExists('player_releases');
    }
};
