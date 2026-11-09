<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Plans de maintenance préventive (INC-03) : opération périodique sur un écran ou sur tout le parc ; l'échéance ouvre
     * un incident « préventif » rattaché au plan, dont la résolution recale l'échéance suivante. Ajout pur.
     */
    public function up(): void
    {
        Schema::create('maintenance_plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title', 200);
            $table->text('instructions')->nullable();
            $table->foreignUuid('screen_id')->nullable()->constrained()->nullOnDelete();
            $table->string('category', 20)->default('panel');
            $table->unsignedSmallInteger('frequency_days');
            $table->date('next_due_on');
            $table->date('last_done_on')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->index(['is_active', 'next_due_on']);
        });
        DB::statement("ALTER TABLE maintenance_plans ADD CONSTRAINT maintenance_plans_category_check CHECK (category IN ('player','panel','network','power','content','software','other'))");
        DB::statement('ALTER TABLE maintenance_plans ADD CONSTRAINT maintenance_plans_frequency_check CHECK (frequency_days BETWEEN 1 AND 730)');
        Schema::table('incidents', function (Blueprint $table) {
            $table->foreignUuid('maintenance_plan_id')->nullable()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('incidents', fn (Blueprint $table) => $table->dropConstrainedForeignId('maintenance_plan_id'));
        Schema::dropIfExists('maintenance_plans');
    }
};
