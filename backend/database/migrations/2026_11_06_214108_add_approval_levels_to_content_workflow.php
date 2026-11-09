<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Circuit de validation à plusieurs niveaux par catégorie (PUB-04, décision D2 à valider) : nombre de niveaux de la
     * catégorie (1 à 3) et niveau de chaque tâche de validation. Valeur proposée : deux niveaux pour les communiqués et
     * les messages de la Présidence ; un niveau ailleurs (comportement antérieur). Réglable ensuite dans les référentiels.
     */
    public function up(): void
    {
        Schema::table('content_categories', function (Blueprint $table) {
            $table->unsignedSmallInteger('approval_levels')->default(1);
        });
        Schema::table('approvals', function (Blueprint $table) {
            $table->unsignedSmallInteger('level')->default(1);
        });
        DB::statement('ALTER TABLE content_categories ADD CONSTRAINT content_categories_levels_check CHECK (approval_levels BETWEEN 1 AND 3)');
        DB::table('content_categories')->whereIn('code', ['COMMUNIQUE', 'DIRECTION'])->update(['approval_levels' => 2]);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE content_categories DROP CONSTRAINT content_categories_levels_check');
        Schema::table('approvals', fn (Blueprint $table) => $table->dropColumn('level'));
        Schema::table('content_categories', fn (Blueprint $table) => $table->dropColumn('approval_levels'));
    }
};
