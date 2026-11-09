<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Profils de configuration (§7.3) : paramètres d'affichage et de communication réutilisables, affectés à un écran ou à
     * un groupe ; le lecteur rend compte de la configuration appliquée et des paramètres qu'il ne prend pas en charge.
     */
    public function up(): void
    {
        Schema::create('screen_profiles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 40)->unique();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->jsonb('settings');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->foreignUuid('created_by')->nullable()->constrained('users');
            $table->timestamps();
        });
        Schema::table('screens', function (Blueprint $table): void {
            $table->foreignUuid('profile_id')->nullable()->constrained('screen_profiles');
        });
        Schema::table('screen_groups', function (Blueprint $table): void {
            $table->foreignUuid('profile_id')->nullable()->constrained('screen_profiles');
        });
        Schema::table('devices', function (Blueprint $table): void {
            $table->string('config_hash', 64)->nullable();
            $table->jsonb('config_report')->nullable();
            $table->timestampTz('config_reported_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            $table->dropColumn(['config_hash', 'config_report', 'config_reported_at']);
        });
        Schema::table('screen_groups', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('profile_id');
        });
        Schema::table('screens', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('profile_id');
        });
        Schema::dropIfExists('screen_profiles');
    }
};
