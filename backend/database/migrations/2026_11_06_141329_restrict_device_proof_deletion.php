<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * DATA-02 : les preuves de diffusion d'un lecteur (manifestes, accusés, captures, télémétrie, réceptions d'alertes) ne
 * disparaissent pas avec lui. Un lecteur se révoque ; sa suppression est refusée tant que ses preuves existent.
 */
return new class extends Migration
{
    /** @var list<string> */
    private array $tables = ['alert_receipts', 'manifests', 'device_receipts', 'device_screenshots', 'device_telemetry'];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            DB::statement("ALTER TABLE $table DROP CONSTRAINT {$table}_device_id_foreign");
            DB::statement("ALTER TABLE $table ADD CONSTRAINT {$table}_device_id_foreign FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE RESTRICT");
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            DB::statement("ALTER TABLE $table DROP CONSTRAINT {$table}_device_id_foreign");
            DB::statement("ALTER TABLE $table ADD CONSTRAINT {$table}_device_id_foreign FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE");
        }
    }
};
