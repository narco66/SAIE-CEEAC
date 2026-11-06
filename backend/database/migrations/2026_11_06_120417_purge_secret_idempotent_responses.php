<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SEC-05 : efface les réponses porteuses de secrets (second facteur, jetons de comptes de service) conservées
 * par l'ancien mécanisme d'idempotence, dont la portée était le chemin en clair tronqué à 60 caractères.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('idempotency_keys')
            ->where(fn ($q) => $q->where('scope', 'like', '%|api/v1/me/mfa%')->orWhere('scope', 'like', '%|api/v1/service-acc%'))
            ->update(['response_body' => null]);
    }

    public function down(): void
    {
        // Données effacées volontairement : aucun retour arrière.
    }
};
