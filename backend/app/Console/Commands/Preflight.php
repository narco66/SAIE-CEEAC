<?php

namespace App\Console\Commands;

use App\Services\ProductionReadiness;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('saie:preflight {--json : Sortie JSON pour la supervision ou la chaîne de déploiement}')]
#[Description('Contrôle la configuration de production avant mise en service (HTTPS, secrets, antivirus, base, démonstration) ; code 1 si un contrôle bloque')]
class Preflight extends Command
{
    public function handle(ProductionReadiness $readiness): int
    {
        $checks = $readiness->checks();
        $blocking = collect($checks)->where('status', 'fail')->count();
        $warnings = collect($checks)->where('status', 'warn')->count();

        if ($this->option('json')) {
            $this->line((string) json_encode(['ready' => $blocking === 0, 'blocking' => $blocking, 'warnings' => $warnings, 'checks' => $checks], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return $blocking === 0 ? self::SUCCESS : self::FAILURE;
        }

        $label = ['ok' => '<fg=green>OK</>', 'warn' => '<fg=yellow>À EXAMINER</>', 'fail' => '<fg=red>BLOQUANT</>'];
        $this->table(['Contrôle', 'État', 'Détail'], array_map(fn (array $c): array => [$c['name'], $label[$c['status']], $c['detail']], $checks));

        if ($blocking > 0) {
            $this->error("$blocking contrôle(s) bloquant(s) : mise en service refusée.");

            return self::FAILURE;
        }
        $this->info($warnings > 0 ? "Prêt pour la mise en service ($warnings point(s) à examiner)." : 'Prêt pour la mise en service.');

        return self::SUCCESS;
    }
}
