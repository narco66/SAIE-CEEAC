<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fiche matérielle de l'écran (inventaire du parc) et statut administratif « en maintenance ».
     * Ajout pur : colonnes facultatives, contrainte de statut élargie, aucune valeur existante modifiée.
     */
    public function up(): void
    {
        Schema::table('screens', function (Blueprint $table) {
            $table->string('manufacturer', 80)->nullable();
            $table->string('model', 80)->nullable();
            $table->string('serial_number', 80)->nullable();
            $table->unsignedSmallInteger('diagonal_inches')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('connection_type', 12)->nullable();
            $table->date('installed_on')->nullable();
            $table->date('warranty_until')->nullable();
            $table->string('maintenance_reason', 300)->nullable();
            $table->string('status', 12)->default('active')->change();
        });
        DB::statement("ALTER TABLE screens ADD CONSTRAINT screens_connection_check CHECK (connection_type IS NULL OR connection_type IN ('ethernet','wifi','cellular','other'))");
        DB::statement('ALTER TABLE screens DROP CONSTRAINT screens_status_check');
        DB::statement("ALTER TABLE screens ADD CONSTRAINT screens_status_check CHECK (status IN ('active','maintenance','inactive'))");
    }

    /**
     * Retour arrière : refusé si un écran est encore en maintenance (la contrainte d'origine ne l'accepterait pas).
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE screens DROP CONSTRAINT screens_status_check');
        DB::statement("ALTER TABLE screens ADD CONSTRAINT screens_status_check CHECK (status IN ('active','inactive'))");
        DB::statement('ALTER TABLE screens DROP CONSTRAINT screens_connection_check');
        DB::statement('ALTER TABLE screens ALTER COLUMN status TYPE varchar(10)');
        Schema::table('screens', function (Blueprint $table) {
            $table->dropColumn(['manufacturer', 'model', 'serial_number', 'diagonal_inches', 'ip_address', 'connection_type', 'installed_on', 'warranty_until', 'maintenance_reason']);
        });
    }
};
