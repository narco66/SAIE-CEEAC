<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Structures organiques, utilisateurs et sessions (REF-01, HAB-01).
     */
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
        DB::statement('CREATE EXTENSION IF NOT EXISTS pgcrypto');

        Schema::create('structures', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 40)->unique();
            $table->string('name');
            $table->string('type', 20);
            $table->uuid('parent_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('storage_quota_mb')->nullable();
            $table->timestamps();
        });
        DB::statement("ALTER TABLE structures ADD CONSTRAINT structures_type_check CHECK (type IN ('cabinet','departement','direction','service','autre'))");

        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->foreignUuid('structure_id')->nullable()->constrained('structures')->restrictOnDelete();
            $table->uuid('backup_user_id')->nullable();
            $table->string('title')->nullable();
            $table->string('locale', 5)->default('fr');
            $table->string('timezone', 40)->default('Africa/Libreville');
            $table->string('status', 12)->default('active');
            $table->string('source', 12)->default('local');
            $table->string('external_id')->nullable();
            $table->text('mfa_secret')->nullable();
            $table->timestamp('mfa_enabled_at')->nullable();
            $table->text('mfa_recovery_codes')->nullable();
            $table->boolean('must_change_password')->default(false);
            $table->unsignedSmallInteger('failed_logins')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->jsonb('notification_preferences')->nullable();
            $table->boolean('is_demo')->default(false);
            $table->rememberToken();
            $table->timestamps();
        });
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_status_check CHECK (status IN ('active','suspended','disabled'))");

        Schema::table('structures', fn (Blueprint $table) => $table->foreign('parent_id')->references('id')->on('structures')->restrictOnDelete());
        Schema::table('users', fn (Blueprint $table) => $table->foreign('backup_user_id')->references('id')->on('users')->nullOnDelete());

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignUuid('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
        Schema::dropIfExists('structures');
    }
};
