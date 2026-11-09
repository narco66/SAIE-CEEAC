<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ajouts P1 : confirmation de présence (SAL-10), comptes de service de l'API d'intégration (API-07).
     * Migration additive uniquement (TECH-04).
     */
    public function up(): void
    {
        Schema::table('reservation_occurrences', function (Blueprint $table) {
            $table->timestampTz('checked_in_at')->nullable()->after('no_show_at');
            $table->foreignUuid('checked_in_by')->nullable()->after('checked_in_at')->constrained('users')->nullOnDelete();
            $table->string('check_in_code', 8)->nullable()->after('checked_in_by');
        });

        Schema::create('service_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('description')->nullable();
            $table->jsonb('scopes');
            $table->string('status', 10)->default('active');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_accounts');
        Schema::table('reservation_occurrences', function (Blueprint $table) {
            $table->dropConstrainedForeignId('checked_in_by');
            $table->dropColumn(['checked_in_at', 'check_in_code']);
        });
    }
};
