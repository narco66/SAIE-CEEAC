<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Référentiels (REF-01) et habilitations (HAB-01 à HAB-03).
     */
    public function up(): void
    {
        Schema::create('sites', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 40)->unique();
            $table->string('name');
            $table->string('timezone', 40)->default('Africa/Libreville');
            $table->string('address')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('buildings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('site_id')->constrained()->restrictOnDelete();
            $table->string('code', 40);
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['site_id', 'code']);
        });

        Schema::create('floors', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('building_id')->constrained()->restrictOnDelete();
            $table->string('code', 40);
            $table->string('label');
            $table->smallInteger('level');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['building_id', 'code']);
            $table->unique(['building_id', 'level']);
        });

        Schema::create('display_zones', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 40)->unique();
            $table->string('name');
            $table->string('max_classification', 10)->default('public');
            $table->boolean('audio_allowed')->default(false);
            $table->unsignedSmallInteger('audio_max_volume')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        DB::statement("ALTER TABLE display_zones ADD CONSTRAINT display_zones_class_check CHECK (max_classification IN ('public','interne'))");
        DB::statement('ALTER TABLE display_zones ADD CONSTRAINT display_zones_volume_check CHECK (audio_max_volume BETWEEN 0 AND 100)');

        Schema::create('locations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('floor_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('zone_id')->constrained('display_zones')->restrictOnDelete();
            $table->string('code', 40);
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['floor_id', 'code']);
        });

        Schema::table('structures', function (Blueprint $table) {
            $table->foreignUuid('floor_id')->nullable()->after('parent_id')->constrained('floors')->nullOnDelete();
            $table->boolean('show_in_orientation')->default(true);
        });

        Schema::create('languages', function (Blueprint $table) {
            $table->string('code', 2)->primary();
            $table->string('name');
            $table->boolean('is_active')->default(false);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('content_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 40)->unique();
            $table->string('name');
            $table->unsignedSmallInteger('default_duration_s')->default(12);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('service_types', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 40)->unique();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('holidays', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->date('date')->unique();
            $table->string('label');
            $table->timestamps();
        });

        Schema::create('equipment', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 40)->unique();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->string('key', 80)->primary();
            $table->jsonb('value')->nullable();
            $table->boolean('is_secret')->default(false);
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 40)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_system')->default(true);
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 60)->unique();
            $table->string('name');
            $table->string('group', 40);
            $table->timestamps();
        });

        Schema::create('permission_role', function (Blueprint $table) {
            $table->foreignUuid('role_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('permission_id')->constrained()->cascadeOnDelete();
            $table->primary(['role_id', 'permission_id']);
        });

        Schema::create('role_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('role_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('structure_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('site_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('zone_id')->nullable()->constrained('display_zones')->restrictOnDelete();
            $table->jsonb('languages')->nullable();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignUuid('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'starts_at', 'ends_at']);
        });
        DB::statement('ALTER TABLE role_assignments ADD CONSTRAINT role_assignments_period_check CHECK (ends_at IS NULL OR ends_at > starts_at)');

        Schema::create('role_extensions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('power', 20);
            $table->jsonb('scope')->nullable();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignUuid('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'power']);
        });
        DB::statement("ALTER TABLE role_extensions ADD CONSTRAINT role_extensions_power_check CHECK (power IN ('urgence','modeles','prestations'))");
        DB::statement('ALTER TABLE role_extensions ADD CONSTRAINT role_extensions_period_check CHECK (ends_at > starts_at)');
    }

    public function down(): void
    {
        foreach (['role_extensions', 'role_assignments', 'permission_role', 'permissions', 'roles', 'settings', 'equipment', 'holidays', 'service_types', 'content_categories', 'languages'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('structures', function (Blueprint $table) {
            $table->dropConstrainedForeignId('floor_id');
            $table->dropColumn('show_in_orientation');
        });
        foreach (['locations', 'display_zones', 'floors', 'buildings', 'sites'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
