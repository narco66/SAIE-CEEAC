<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Modèles, contenus multilingues, validations, campagnes, alertes, manifestes (PUB-01 à PUB-12, PLY-03).
     */
    public function up(): void
    {
        Schema::create('templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 40)->unique();
            $table->string('name');
            $table->string('orientation', 10);
            $table->string('purpose', 20)->default('content');
            $table->jsonb('layout');
            $table->unsignedInteger('version')->default(1);
            $table->string('status', 10)->default('active');
            $table->boolean('is_institutional')->default(false);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        DB::statement("ALTER TABLE templates ADD CONSTRAINT templates_orientation_check CHECK (orientation IN ('landscape','portrait'))");
        DB::statement("ALTER TABLE templates ADD CONSTRAINT templates_status_check CHECK (status IN ('draft','active','archived'))");

        Schema::create('contents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('reference', 30)->unique();
            $table->string('title');
            $table->string('kind', 12)->default('standard');
            $table->foreignUuid('category_id')->constrained('content_categories')->restrictOnDelete();
            $table->foreignUuid('owner_structure_id')->constrained('structures')->restrictOnDelete();
            $table->foreignUuid('author_id')->constrained('users')->restrictOnDelete();
            $table->string('classification', 10)->default('interne');
            $table->unsignedSmallInteger('priority')->default(50);
            $table->foreignUuid('template_id')->nullable()->constrained()->nullOnDelete();
            $table->jsonb('media')->nullable();
            $table->jsonb('qr')->nullable();
            $table->jsonb('proposed_targets')->nullable();
            $table->timestampTz('valid_from')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->boolean('is_permanent')->default(false);
            $table->string('status', 12)->default('draft');
            $table->foreignUuid('occurrence_id')->nullable()->constrained('reservation_occurrences')->nullOnDelete();
            $table->jsonb('delegation')->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_demo')->default(false);
            $table->timestamps();
            $table->index(['status', 'expires_at']);
            $table->index(['owner_structure_id', 'classification']);
        });
        DB::statement("ALTER TABLE contents ADD CONSTRAINT contents_class_check CHECK (classification IN ('public','interne','restreint'))");
        DB::statement("ALTER TABLE contents ADD CONSTRAINT contents_status_check CHECK (status IN ('draft','submitted','approved','scheduled','broadcasting','expired','archived','suspended'))");
        DB::statement("ALTER TABLE contents ADD CONSTRAINT contents_kind_check CHECK (kind IN ('standard','welcome','fallback'))");
        DB::statement('ALTER TABLE contents ADD CONSTRAINT contents_expiry_check CHECK (is_permanent OR expires_at IS NOT NULL OR status = \'draft\')');

        Schema::create('content_languages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('content_id')->constrained()->cascadeOnDelete();
            $table->string('lang', 2);
            $table->unsignedInteger('revision')->default(1);
            $table->string('title');
            $table->text('body')->nullable();
            $table->jsonb('extra')->nullable();
            $table->foreignUuid('subtitles_asset_version_id')->nullable()->constrained('asset_versions')->nullOnDelete();
            $table->string('status', 12)->default('draft');
            $table->boolean('overflow')->default(false);
            $table->timestampTz('submitted_at')->nullable();
            $table->foreignUuid('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('last_return_reason')->nullable();
            $table->timestamps();
            $table->unique(['content_id', 'lang']);
        });
        DB::statement("ALTER TABLE content_languages ADD CONSTRAINT content_languages_status_check CHECK (status IN ('draft','submitted','approved','returned'))");

        Schema::create('content_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('content_id')->constrained()->restrictOnDelete();
            $table->string('lang', 2);
            $table->unsignedInteger('revision');
            $table->jsonb('payload');
            $table->string('classification', 10);
            $table->foreignUuid('template_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('author_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('approved_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('approved_at');
            $table->char('payload_hash', 64);
            $table->timestamps();
            $table->unique(['content_id', 'lang', 'revision']);
        });
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION saie_forbid_change() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'Table % en ajout seul : modification interdite', TG_TABLE_NAME USING ERRCODE = '42501';
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::statement('CREATE TRIGGER content_versions_immutable BEFORE UPDATE ON content_versions FOR EACH ROW EXECUTE FUNCTION saie_forbid_change()');

        Schema::create('approvals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('object_type', 20);
            $table->uuid('object_id');
            $table->string('lang', 2)->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->string('title');
            $table->foreignUuid('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('backup_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('due_at');
            $table->timestampTz('reminded_at')->nullable();
            $table->timestampTz('escalated_at')->nullable();
            $table->string('status', 10)->default('open');
            $table->string('decision', 12)->nullable();
            $table->text('reason')->nullable();
            $table->foreignUuid('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('decided_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'due_at']);
            $table->index(['object_type', 'object_id']);
        });
        DB::statement("ALTER TABLE approvals ADD CONSTRAINT approvals_status_check CHECK (status IN ('open','decided','cancelled'))");

        Schema::create('workflow_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('object_type', 20);
            $table->uuid('object_id');
            $table->string('action', 30);
            $table->string('from_status', 15)->nullable();
            $table->string('to_status', 15)->nullable();
            $table->string('lang', 2)->nullable();
            $table->unsignedInteger('revision')->nullable();
            $table->foreignUuid('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_role', 40)->nullable();
            $table->text('reason')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['object_type', 'object_id', 'created_at']);
        });

        Schema::create('campaigns', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('reference', 30)->unique();
            $table->string('name');
            $table->string('status', 12)->default('draft');
            $table->unsignedSmallInteger('priority')->default(50);
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->jsonb('days')->nullable();
            $table->time('time_from')->nullable();
            $table->time('time_to')->nullable();
            $table->unsignedSmallInteger('slide_duration_s')->default(12);
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('published_at')->nullable();
            $table->foreignUuid('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('suspended_at')->nullable();
            $table->text('suspend_reason')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_demo')->default(false);
            $table->timestamps();
            $table->index(['status', 'ends_at']);
        });
        DB::statement("ALTER TABLE campaigns ADD CONSTRAINT campaigns_status_check CHECK (status IN ('draft','scheduled','active','suspended','expired','archived'))");
        DB::statement('ALTER TABLE campaigns ADD CONSTRAINT campaigns_period_check CHECK (ends_at > starts_at)');
        DB::statement('ALTER TABLE campaigns ADD CONSTRAINT campaigns_duration_check CHECK (slide_duration_s >= 5)');

        Schema::create('campaign_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('content_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('position');
            $table->unsignedSmallInteger('duration_s')->nullable();
            $table->jsonb('version_map')->nullable();
            $table->timestamps();
        });

        Schema::create('campaign_languages', function (Blueprint $table) {
            $table->foreignUuid('campaign_id')->constrained()->cascadeOnDelete();
            $table->string('lang', 2);
            $table->unsignedSmallInteger('rank');
            $table->primary(['campaign_id', 'lang']);
        });

        Schema::create('campaign_targets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('campaign_id')->constrained()->cascadeOnDelete();
            $table->string('target_type', 10);
            $table->uuid('target_id');
            $table->index(['target_type', 'target_id']);
        });
        DB::statement("ALTER TABLE campaign_targets ADD CONSTRAINT campaign_targets_type_check CHECK (target_type IN ('screen','group','zone','floor'))");

        Schema::create('alerts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('reference', 30)->unique();
            $table->string('title');
            $table->text('message');
            $table->jsonb('translations')->nullable();
            $table->string('severity', 10);
            $table->timestampTz('starts_at');
            $table->timestampTz('expires_at');
            $table->foreignUuid('author_id')->constrained('users')->restrictOnDelete();
            $table->text('justification');
            $table->string('status', 10)->default('active');
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignUuid('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('revoke_reason')->nullable();
            $table->jsonb('extensions')->nullable();
            $table->unsignedSmallInteger('target_count')->default(0);
            $table->unsignedSmallInteger('offline_at_publish')->default(0);
            $table->timestamps();
            $table->index(['status', 'starts_at', 'expires_at']);
        });
        DB::statement("ALTER TABLE alerts ADD CONSTRAINT alerts_severity_check CHECK (severity IN ('critical','warning','info','exercise'))");
        DB::statement("ALTER TABLE alerts ADD CONSTRAINT alerts_status_check CHECK (status IN ('active','revoked','expired'))");
        DB::statement('ALTER TABLE alerts ADD CONSTRAINT alerts_period_check CHECK (expires_at > starts_at)');

        Schema::create('alert_targets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('alert_id')->constrained()->cascadeOnDelete();
            $table->string('target_type', 10);
            $table->uuid('target_id')->nullable();
        });

        Schema::create('alert_receipts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('alert_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('device_id')->constrained()->cascadeOnDelete();
            $table->timestampTz('received_at')->nullable();
            $table->timestampTz('activated_at')->nullable();
            $table->unique(['alert_id', 'device_id']);
        });

        Schema::create('fallback_contents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('zone_id')->constrained('display_zones')->cascadeOnDelete();
            $table->foreignUuid('content_version_id')->constrained()->restrictOnDelete();
            $table->timestampTz('valid_until');
            $table->boolean('is_active')->default(true);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('dynamic_sources', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 40)->unique();
            $table->string('name');
            $table->string('type', 12);
            $table->string('url')->nullable();
            $table->jsonb('config')->nullable();
            $table->boolean('is_active')->default(false);
            $table->unsignedSmallInteger('frequency_minutes')->default(30);
            $table->timestampTz('last_fetched_at')->nullable();
            $table->string('last_status', 20)->nullable();
            $table->timestamps();
        });

        Schema::create('source_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('source_id')->constrained('dynamic_sources')->cascadeOnDelete();
            $table->timestampTz('fetched_at');
            $table->jsonb('payload')->nullable();
            $table->string('status', 20);
        });

        Schema::create('manifests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('device_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->char('etag', 64);
            $table->jsonb('payload');
            $table->timestampTz('valid_until');
            $table->timestampTz('generated_at');
            $table->unique(['device_id', 'version']);
        });

        Schema::create('device_receipts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('event_id')->unique();
            $table->foreignUuid('device_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);
            $table->unsignedInteger('manifest_version')->nullable();
            $table->string('ref_type', 20)->nullable();
            $table->string('ref_id', 60)->nullable();
            $table->string('lang', 2)->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampTz('received_at');
            $table->jsonb('payload')->nullable();
            $table->index(['device_id', 'occurred_at']);
            $table->index(['ref_type', 'ref_id']);
        });

        Schema::create('device_screenshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('device_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('requested_at');
            $table->timestampTz('captured_at')->nullable();
            $table->string('storage_key')->nullable();
            $table->string('status', 10)->default('requested');
            $table->timestampTz('expires_at');
        });

        Schema::create('device_telemetry', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('device_id')->constrained()->cascadeOnDelete();
            $table->timestampTz('recorded_at');
            $table->jsonb('payload');
            $table->index(['device_id', 'recorded_at']);
        });

        Schema::create('incidents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('reference', 30)->unique();
            $table->foreignUuid('screen_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('device_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 30);
            $table->string('severity', 10);
            $table->string('status', 12)->default('open');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('impact')->nullable();
            $table->timestampTz('detected_at');
            $table->timestampTz('acknowledged_at')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->foreignUuid('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->jsonb('events')->nullable();
            $table->boolean('is_automatic')->default(false);
            $table->timestamps();
            $table->index(['status', 'severity']);
        });
        DB::statement("ALTER TABLE incidents ADD CONSTRAINT incidents_status_check CHECK (status IN ('open','acknowledged','resolved','closed'))");
        DB::statement("ALTER TABLE incidents ADD CONSTRAINT incidents_severity_check CHECK (severity IN ('critical','major','minor'))");

        Schema::create('visits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('reference', 30)->unique();
            $table->string('name');
            $table->string('institution')->nullable();
            $table->unsignedSmallInteger('visitors_count')->default(1);
            $table->foreignUuid('occurrence_id')->nullable()->constrained('reservation_occurrences')->nullOnDelete();
            $table->foreignUuid('content_id')->nullable()->constrained()->nullOnDelete();
            $table->jsonb('languages')->nullable();
            $table->jsonb('flags')->nullable();
            $table->string('status', 15)->default('draft');
            $table->text('notes')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_demo')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['visits', 'incidents', 'device_telemetry', 'device_screenshots', 'device_receipts', 'manifests', 'source_snapshots', 'dynamic_sources', 'fallback_contents', 'alert_receipts', 'alert_targets', 'alerts', 'campaign_targets', 'campaign_languages', 'campaign_items', 'campaigns', 'workflow_events', 'approvals', 'content_versions', 'content_languages', 'contents', 'templates'] as $table) {
            Schema::dropIfExists($table);
        }
        DB::statement('DROP FUNCTION IF EXISTS saie_forbid_change() CASCADE');
    }
};
