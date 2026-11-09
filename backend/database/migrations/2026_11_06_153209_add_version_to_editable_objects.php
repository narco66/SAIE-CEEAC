<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * API-04 : version de concurrence optimiste sur les objets modifiables par plusieurs gestionnaires
 * (salles, écrans, groupes d'écrans, documents, médias, comptes). Ajout seul : les lignes existantes partent de 1.
 */
return new class extends Migration
{
    /** @var list<string> */
    private array $tables = ['rooms', 'screens', 'screen_groups', 'documents', 'assets', 'users'];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->unsignedInteger('version')->default(1);
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropColumn('version');
            });
        }
    }
};
