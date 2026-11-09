<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Traitement des incidents (§10.3, §15.2), migration additive : vérification du fonctionnement avant clôture (auteur et
     * date), escalade (niveau atteint et date) et échéance de prise en charge selon la gravité.
     */
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table): void {
            $table->timestampTz('verified_at')->nullable();
            $table->foreignUuid('verified_by')->nullable()->constrained('users');
            $table->unsignedSmallInteger('escalation_level')->default(0);
            $table->timestampTz('escalated_at')->nullable();
            $table->timestampTz('due_at')->nullable();
            $table->index(['status', 'due_at']);
        });
    }

    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table): void {
            $table->dropIndex(['status', 'due_at']);
            $table->dropConstrainedForeignId('verified_by');
            $table->dropColumn(['verified_at', 'escalation_level', 'escalated_at', 'due_at']);
        });
    }
};
