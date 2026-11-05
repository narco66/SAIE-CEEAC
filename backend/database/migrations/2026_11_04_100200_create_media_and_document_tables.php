<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Médiathèque, documents, accusés de lecture et liens QR (DOC-01 à DOC-08).
     */
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('reference', 30)->unique();
            $table->string('title');
            $table->string('kind', 10);
            $table->foreignUuid('owner_structure_id')->constrained('structures')->restrictOnDelete();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('classification', 10)->default('interne');
            $table->string('language', 2)->nullable();
            $table->foreignUuid('category_id')->nullable()->constrained('content_categories')->nullOnDelete();
            $table->string('status', 12)->default('quarantine');
            $table->uuid('current_version_id')->nullable();
            $table->timestampTz('trashed_at')->nullable();
            $table->boolean('is_demo')->default(false);
            $table->timestamps();
            $table->index(['owner_structure_id', 'classification']);
            $table->index(['status', 'kind']);
        });
        DB::statement("ALTER TABLE assets ADD CONSTRAINT assets_kind_check CHECK (kind IN ('image','video','pdf','office'))");
        DB::statement("ALTER TABLE assets ADD CONSTRAINT assets_class_check CHECK (classification IN ('public','interne','restreint'))");
        DB::statement("ALTER TABLE assets ADD CONSTRAINT assets_status_check CHECK (status IN ('quarantine','scanning','ready','rejected','trashed'))");

        Schema::create('asset_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('asset_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('storage_key');
            $table->string('original_name');
            $table->string('declared_mime', 120)->nullable();
            $table->string('mime', 120)->nullable();
            $table->string('extension', 10);
            $table->unsignedBigInteger('size');
            $table->char('sha256', 64)->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('duration_s')->nullable();
            $table->unsignedInteger('pages')->nullable();
            $table->text('text_content')->nullable();
            $table->jsonb('subtitles')->nullable();
            $table->string('thumbnail_key')->nullable();
            $table->string('scan_status', 12)->default('quarantine');
            $table->string('scan_engine', 40)->nullable();
            $table->text('scan_detail')->nullable();
            $table->timestampTz('processed_at')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['asset_id', 'version']);
        });
        DB::statement("ALTER TABLE asset_versions ADD CONSTRAINT asset_versions_scan_check CHECK (scan_status IN ('quarantine','scanning','ready','rejected'))");
        DB::statement('ALTER TABLE asset_versions ADD CONSTRAINT asset_versions_size_check CHECK (size > 0)');
        Schema::table('assets', function (Blueprint $table) {
            $table->foreign('current_version_id')->references('id')->on('asset_versions')->nullOnDelete();
        });

        Schema::create('documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('reference', 30)->unique();
            $table->string('title');
            $table->text('summary')->nullable();
            $table->foreignUuid('category_id')->nullable()->constrained('content_categories')->nullOnDelete();
            $table->foreignUuid('owner_structure_id')->constrained('structures')->restrictOnDelete();
            $table->foreignUuid('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('language', 2)->default('fr');
            $table->jsonb('keywords')->nullable();
            $table->string('classification', 10)->default('interne');
            $table->date('valid_from')->nullable();
            $table->date('expires_at')->nullable();
            $table->string('status', 12)->default('draft');
            $table->uuid('current_version_id')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->timestampTz('unpublished_at')->nullable();
            $table->timestampTz('trashed_at')->nullable();
            $table->text('search_text')->nullable();
            $table->boolean('is_demo')->default(false);
            $table->timestamps();
            $table->index(['classification', 'owner_structure_id']);
            $table->index(['status', 'expires_at']);
        });
        DB::statement("ALTER TABLE documents ADD CONSTRAINT documents_class_check CHECK (classification IN ('public','interne','restreint'))");
        DB::statement("ALTER TABLE documents ADD CONSTRAINT documents_status_check CHECK (status IN ('draft','published','unpublished','trashed'))");
        DB::statement("ALTER TABLE documents ADD COLUMN search_vector tsvector GENERATED ALWAYS AS (to_tsvector('french'::regconfig, coalesce(reference,'') || ' ' || coalesce(title,'') || ' ' || coalesce(summary,'') || ' ' || coalesce(search_text,''))) STORED");
        DB::statement('CREATE INDEX documents_search_idx ON documents USING GIN (search_vector)');

        Schema::create('document_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('document_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->foreignUuid('asset_version_id')->constrained()->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['document_id', 'version']);
        });
        Schema::table('documents', function (Blueprint $table) {
            $table->foreign('current_version_id')->references('id')->on('document_versions')->nullOnDelete();
        });

        Schema::create('document_audiences', function (Blueprint $table) {
            $table->foreignUuid('document_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('structure_id')->constrained()->cascadeOnDelete();
            $table->primary(['document_id', 'structure_id']);
        });

        Schema::create('document_reads', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('document_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('document_version_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->timestampTz('first_opened_at');
            $table->unique(['document_version_id', 'user_id']);
        });

        Schema::create('access_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 16)->unique();
            $table->string('target_type', 20);
            $table->uuid('target_id');
            $table->string('classification', 10);
            $table->timestampTz('expires_at')->nullable();
            $table->unsignedBigInteger('hits')->default(0);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['target_type', 'target_id']);
        });
        DB::statement("ALTER TABLE access_links ADD CONSTRAINT access_links_class_check CHECK (classification IN ('public','interne'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('access_links');
        Schema::dropIfExists('document_reads');
        Schema::dropIfExists('document_audiences');
        Schema::table('documents', fn (Blueprint $table) => $table->dropForeign(['current_version_id']));
        Schema::dropIfExists('document_versions');
        Schema::dropIfExists('documents');
        Schema::table('assets', fn (Blueprint $table) => $table->dropForeign(['current_version_id']));
        Schema::dropIfExists('asset_versions');
        Schema::dropIfExists('assets');
    }
};
