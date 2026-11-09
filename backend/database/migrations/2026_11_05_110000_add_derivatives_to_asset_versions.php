<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dérivés produits par le worker de conversion (DOC-01) : copie PDF de consultation, vidéo transcodée, codecs.
     */
    public function up(): void
    {
        Schema::table('asset_versions', function (Blueprint $table) {
            $table->jsonb('derivatives')->nullable()->after('thumbnail_key');
        });
    }

    public function down(): void
    {
        Schema::table('asset_versions', fn (Blueprint $table) => $table->dropColumn('derivatives'));
    }
};
