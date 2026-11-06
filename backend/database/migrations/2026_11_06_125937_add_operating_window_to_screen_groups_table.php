<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PLY-08 : plage de fonctionnement portée par un groupe d'écrans (écran > groupe > paramètre général).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('screen_groups', function (Blueprint $table) {
            $table->jsonb('operating_window')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('screen_groups', fn (Blueprint $table) => $table->dropColumn('operating_window'));
    }
};
