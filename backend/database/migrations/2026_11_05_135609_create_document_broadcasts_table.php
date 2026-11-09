<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Diffusions par courriel d'un document publié (DOC-09) : structures visées, destinataires retenus et écartés.
     */
    public function up(): void
    {
        Schema::create('document_broadcasts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('document_id')->constrained()->cascadeOnDelete();
            $table->jsonb('structure_ids');
            $table->text('message')->nullable();
            $table->unsignedInteger('recipients_count');
            $table->unsignedInteger('excluded_count');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at');
            $table->index(['document_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_broadcasts');
    }
};
