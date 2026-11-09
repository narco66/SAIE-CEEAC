<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Étiquettes, mention de droits (auteur, licence, crédit) et fin des droits d'usage d'un média (PUB-08). Ajout pur.
     */
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->jsonb('tags')->nullable();
            $table->string('usage_rights', 300)->nullable();
            $table->date('rights_expire_on')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('assets', fn (Blueprint $table) => $table->dropColumn(['tags', 'usage_rights', 'rights_expire_on']));
    }
};
