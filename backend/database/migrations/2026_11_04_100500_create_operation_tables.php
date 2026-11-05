<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Notifications, journal d'audit en ajout seul, outbox, exports, idempotence, imports (NOT-01, SEC-04, API-03).
     */
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('event', 40);
            $table->string('title');
            $table->string('body')->nullable();
            $table->string('link')->nullable();
            $table->string('object_type', 20)->nullable();
            $table->uuid('object_id')->nullable();
            $table->string('dedup_key', 160)->unique();
            $table->timestampTz('read_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['user_id', 'read_at', 'created_at']);
        });

        Schema::create('delivery_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('notification_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 10);
            $table->string('status', 10)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestamps();
            $table->unique(['notification_id', 'channel']);
        });

        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->text('endpoint');
            $table->jsonb('keys')->nullable();
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->timestampTz('occurred_at')->useCurrent();
            $table->foreignUuid('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_type', 10)->default('user');
            $table->string('actor_label')->nullable();
            $table->string('actor_role', 60)->nullable();
            $table->string('power', 20)->nullable();
            $table->string('action', 60);
            $table->string('object_type', 30)->nullable();
            $table->string('object_id', 60)->nullable();
            $table->string('object_label')->nullable();
            $table->string('lang', 2)->nullable();
            $table->string('version', 20)->nullable();
            $table->text('reason')->nullable();
            $table->string('outcome', 10)->default('success');
            $table->string('ip', 45)->nullable();
            $table->string('request_id', 40)->nullable();
            $table->jsonb('before')->nullable();
            $table->jsonb('after')->nullable();
            $table->index(['object_type', 'object_id', 'occurred_at']);
            $table->index(['actor_id', 'occurred_at']);
            $table->index('occurred_at');
        });
        DB::statement('CREATE TRIGGER audit_logs_append_only BEFORE UPDATE OR DELETE ON audit_logs FOR EACH ROW EXECUTE FUNCTION saie_forbid_change()');

        Schema::create('outbox_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type', 60);
            $table->jsonb('payload');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('dispatched_at')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->index(['dispatched_at', 'created_at']);
        });

        Schema::create('exports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type', 30);
            $table->string('format', 5);
            $table->jsonb('params')->nullable();
            $table->string('status', 12)->default('pending');
            $table->unsignedSmallInteger('progress')->default(0);
            $table->string('file_key')->nullable();
            $table->string('file_name')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->text('error')->nullable();
            $table->string('classification', 10)->default('interne');
            $table->foreignUuid('requested_by')->constrained('users')->cascadeOnDelete();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestamps();
            $table->index(['requested_by', 'created_at']);
        });
        DB::statement("ALTER TABLE exports ADD CONSTRAINT exports_status_check CHECK (status IN ('pending','processing','ready','failed','expired','cancelled'))");

        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('scope', 60);
            $table->string('key', 100);
            $table->char('request_hash', 64);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->jsonb('response_body')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['scope', 'key']);
        });

        Schema::create('import_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type', 30);
            $table->string('file_name');
            $table->string('match_key', 30);
            $table->string('status', 12);
            $table->jsonb('summary')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['import_runs', 'idempotency_keys', 'exports', 'outbox_events', 'audit_logs', 'push_subscriptions', 'delivery_attempts', 'notifications'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
