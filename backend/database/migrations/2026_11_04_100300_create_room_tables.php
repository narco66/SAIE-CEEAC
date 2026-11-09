<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Salles, réservations, occurrences et prestations (SAL-01 à SAL-15).
     * La contrainte EXCLUDE GiST est la garantie finale contre les doubles réservations (SAL-05).
     */
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 40)->unique();
            $table->string('name');
            $table->foreignUuid('floor_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('capacity');
            $table->string('location_text')->nullable();
            $table->jsonb('photos')->nullable();
            $table->foreignUuid('manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('approval_mode', 10)->default('auto');
            $table->unsignedSmallInteger('min_minutes')->default(30);
            $table->unsignedSmallInteger('max_minutes')->default(600);
            $table->unsignedSmallInteger('lead_days')->default(90);
            $table->unsignedSmallInteger('prep_minutes')->default(0);
            $table->unsignedSmallInteger('cleanup_minutes')->default(0);
            $table->time('open_from')->default('07:30');
            $table->time('open_to')->default('19:00');
            $table->unsignedSmallInteger('pending_hold_hours')->default(24);
            $table->boolean('auto_release_enabled')->default(false);
            $table->string('status', 10)->default('active');
            $table->boolean('is_demo')->default(false);
            $table->timestamps();
        });
        DB::statement('ALTER TABLE rooms ADD CONSTRAINT rooms_capacity_check CHECK (capacity > 0)');
        DB::statement("ALTER TABLE rooms ADD CONSTRAINT rooms_mode_check CHECK (approval_mode IN ('auto','manual'))");
        DB::statement("ALTER TABLE rooms ADD CONSTRAINT rooms_status_check CHECK (status IN ('active','inactive'))");
        DB::statement('ALTER TABLE rooms ADD CONSTRAINT rooms_hours_check CHECK (open_to > open_from)');
        DB::statement('ALTER TABLE rooms ADD CONSTRAINT rooms_duration_check CHECK (max_minutes >= min_minutes)');

        Schema::table('screens', function (Blueprint $table) {
            $table->foreign('room_id')->references('id')->on('rooms')->nullOnDelete();
        });

        Schema::create('room_equipment', function (Blueprint $table) {
            $table->foreignUuid('room_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('equipment_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->primary(['room_id', 'equipment_id']);
        });

        Schema::create('room_blocks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('room_id')->constrained()->cascadeOnDelete();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->string('kind', 15)->default('technical');
            $table->string('reason');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['room_id', 'starts_at', 'ends_at']);
        });
        DB::statement('ALTER TABLE room_blocks ADD CONSTRAINT room_blocks_period_check CHECK (ends_at > starts_at)');
        DB::statement("ALTER TABLE room_blocks ADD CONSTRAINT room_blocks_kind_check CHECK (kind IN ('technical','institutional'))");

        Schema::create('reservations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('reference', 30)->unique();
            $table->foreignUuid('organizer_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('structure_id')->nullable()->constrained()->nullOnDelete();
            $table->string('subject');
            $table->text('description')->nullable();
            $table->boolean('confidential')->default(false);
            $table->unsignedInteger('participant_count');
            $table->jsonb('internal_participants')->nullable();
            $table->jsonb('external_participants')->nullable();
            $table->jsonb('recurrence')->nullable();
            $table->boolean('is_priority')->default(false);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_demo')->default(false);
            $table->timestamps();
        });
        DB::statement('ALTER TABLE reservations ADD CONSTRAINT reservations_participants_check CHECK (participant_count > 0)');

        Schema::create('reservation_occurrences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('reservation_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('room_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('sequence')->default(1);
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->timestampTz('blocking_start');
            $table->timestampTz('blocking_end');
            $table->string('status', 12);
            $table->timestampTz('hold_until')->nullable();
            $table->foreignUuid('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('decided_at')->nullable();
            $table->string('decision_reason')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->string('no_show_reason')->nullable();
            $table->foreignUuid('no_show_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('no_show_at')->nullable();
            $table->boolean('capacity_override')->default(false);
            $table->string('capacity_override_reason')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['room_id', 'starts_at', 'ends_at']);
            $table->index(['status', 'starts_at']);
        });
        DB::statement("ALTER TABLE reservation_occurrences ADD CONSTRAINT occurrences_status_check CHECK (status IN ('draft','pending','confirmed','refused','cancelled','in_progress','completed','no_show'))");
        DB::statement('ALTER TABLE reservation_occurrences ADD CONSTRAINT occurrences_period_check CHECK (ends_at > starts_at AND blocking_start <= starts_at AND blocking_end >= ends_at)');
        DB::statement("ALTER TABLE reservation_occurrences ADD CONSTRAINT occurrences_no_overlap EXCLUDE USING gist (room_id WITH =, tstzrange(blocking_start, blocking_end, '[)') WITH &&) WHERE (status IN ('pending','confirmed','in_progress'))");

        Schema::create('agenda_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('reservation_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('title');
            $table->string('time_label', 20)->nullable();
            $table->string('presenter')->nullable();
            $table->timestamps();
        });

        Schema::create('reservation_documents', function (Blueprint $table) {
            $table->foreignUuid('reservation_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('document_id')->constrained()->cascadeOnDelete();
            $table->primary(['reservation_id', 'document_id']);
        });

        Schema::create('mobile_resources', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 40)->unique();
            $table->string('name');
            $table->string('type', 40);
            $table->foreignUuid('manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 10)->default('active');
            $table->timestamps();
        });

        Schema::create('resource_bookings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('resource_id')->constrained('mobile_resources')->restrictOnDelete();
            $table->foreignUuid('occurrence_id')->constrained('reservation_occurrences')->cascadeOnDelete();
            $table->timestampTz('blocking_start');
            $table->timestampTz('blocking_end');
            $table->string('status', 12)->default('active');
            $table->timestamps();
        });
        DB::statement("ALTER TABLE resource_bookings ADD CONSTRAINT resource_bookings_no_overlap EXCLUDE USING gist (resource_id WITH =, tstzrange(blocking_start, blocking_end, '[)') WITH &&) WHERE (status = 'active')");

        Schema::create('service_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('occurrence_id')->constrained('reservation_occurrences')->cascadeOnDelete();
            $table->foreignUuid('service_type_id')->constrained()->restrictOnDelete();
            $table->text('details')->nullable();
            $table->string('status', 15)->default('requested');
            $table->foreignUuid('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('due_at')->nullable();
            $table->text('response_note')->nullable();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'due_at']);
        });
        DB::statement("ALTER TABLE service_requests ADD CONSTRAINT service_requests_status_check CHECK (status IN ('requested','accepted','clarification','ready','impossible','cancelled'))");

        Schema::create('checklist_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('occurrence_id')->constrained('reservation_occurrences')->cascadeOnDelete();
            $table->string('phase', 10);
            $table->string('label');
            $table->timestampTz('done_at')->nullable();
            $table->foreignUuid('done_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['checklist_items', 'service_requests', 'resource_bookings', 'mobile_resources', 'reservation_documents', 'agenda_items', 'reservation_occurrences', 'reservations', 'room_blocks', 'room_equipment'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('screens', fn (Blueprint $table) => $table->dropForeign(['room_id']));
        Schema::dropIfExists('rooms');
    }
};
