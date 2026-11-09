<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Preuve durable de transmission (PUB-07, annexe C « Transmis ») : première version de manifeste qui a porté une campagne
     * vers un lecteur. Le suivi de diffusion ne dépend plus des manifestes, purgés au-delà des cinq derniers par lecteur.
     * Ajout pur : reprise depuis les manifestes encore conservés, aucune donnée existante modifiée.
     */
    public function up(): void
    {
        Schema::create('campaign_transmissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('device_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('campaign_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('first_manifest_version');
            $table->timestampTz('first_transmitted_at');
            $table->unique(['device_id', 'campaign_id']);
            $table->index('campaign_id');
        });

        DB::statement(<<<'SQL'
            insert into campaign_transmissions (id, device_id, campaign_id, first_manifest_version, first_transmitted_at)
            select gen_random_uuid(), m.device_id, (e->>'campaign_id')::uuid, min(m.version), min(m.generated_at)
            from manifests m cross join lateral jsonb_array_elements(coalesce(m.payload->'schedule', '[]'::jsonb)) e
            where e->>'campaign_id' is not null and exists (select 1 from campaigns c where c.id = (e->>'campaign_id')::uuid)
            group by m.device_id, (e->>'campaign_id')::uuid
            on conflict do nothing
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_transmissions');
    }
};
