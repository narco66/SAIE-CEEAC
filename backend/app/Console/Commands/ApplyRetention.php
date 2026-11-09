<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\Audit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Durées de conservation (GOV-02, ADM-01 ; décision D5 à valider). Par défaut, simulation : compte ce qui serait purgé.
 * Avec --apply : supprime la télémétrie et les notifications lues échues ; le journal d'audit, en ajout seul, n'est
 * jamais supprimé par l'application : ses entrées échues sont archivées (JSON Lines compressé, empreinte SHA-256),
 * leur purge restant une opération du propriétaire de la base (procédure du guide technique).
 */
class ApplyRetention extends Command
{
    protected $signature = 'saie:retention {--apply : Exécuter la purge (sinon simulation)}';

    protected $description = 'Applique les durées de conservation (télémétrie, notifications lues) et archive le journal d’audit échu ; simulation par défaut';

    public function handle(Audit $audit): int
    {
        $apply = (bool) $this->option('apply');
        $telemetryBefore = now()->subDays((int) Setting::effective('retention.telemetry_days'));
        $notificationsBefore = now()->subDays((int) Setting::effective('retention.notifications_days'));
        $auditBefore = now()->subMonths((int) Setting::effective('retention.audit_months'));

        $telemetry = DB::table('device_telemetry')->where('recorded_at', '<', $telemetryBefore);
        $notifications = DB::table('notifications')->whereNotNull('read_at')->where('created_at', '<', $notificationsBefore);
        $auditEntries = DB::table('audit_logs')->where('occurred_at', '<', $auditBefore);
        // §13.3, §17.3 : accusés de diffusion, commandes terminées et historique des états, chacun selon sa durée.
        $receiptsBefore = now()->subDays((int) Setting::effective('retention.receipts_days'));
        $commandsBefore = now()->subDays((int) Setting::effective('retention.commands_days'));
        $statesBefore = now()->subDays((int) Setting::effective('retention.states_days'));
        $receipts = DB::table('device_receipts')->where('occurred_at', '<', $receiptsBefore);
        $commands = DB::table('device_commands')->whereNotIn('status', ['pending', 'sent', 'received'])->where('requested_at', '<', $commandsBefore);
        $states = DB::table('screen_status_history')->whereNotNull('ended_at')->where('ended_at', '<', $statesBefore);
        // §17.6 : journal des échanges des lecteurs.
        $exchanges = DB::table('device_exchanges')->where('occurred_at', '<', now()->subDays((int) Setting::effective('retention.exchanges_days')));
        $report = ['telemetry' => (clone $telemetry)->count(), 'notifications' => (clone $notifications)->count(), 'audit_to_archive' => (clone $auditEntries)->count(),
            'receipts' => (clone $receipts)->count(), 'commands' => (clone $commands)->count(), 'states' => (clone $states)->count(), 'exchanges' => (clone $exchanges)->count()];

        if ($apply) {
            $report['telemetry'] = $telemetry->delete();
            $report['notifications'] = $notifications->delete();
            $report['receipts'] = $receipts->delete();
            $report['commands'] = $commands->delete();
            $report['states'] = $states->delete();
            $report['exchanges'] = $exchanges->delete();
            $report['audit_archive'] = $report['audit_to_archive'] > 0 ? $this->archiveAudit($auditBefore) : null;
            $audit->log('retention.applied', null, after: $report + ['before' => ['telemetry' => $telemetryBefore->toIso8601String(), 'notifications' => $notificationsBefore->toIso8601String(), 'audit' => $auditBefore->toIso8601String()]]);
        }

        $this->line(($apply ? 'Purge appliquée' : 'Simulation (ajouter --apply pour purger)').' : '.collect($report)->map(fn ($v, $k) => "$k=".(is_array($v) ? $v['file'] : (string) $v))->implode(' '));

        return self::SUCCESS;
    }

    /**
     * Archive les entrées du journal antérieures à la date donnée ; l'archive est rangée dans le stockage privé.
     *
     * @return array{file: string, sha256: string, entries: int}
     */
    private function archiveAudit(\DateTimeInterface $before): array
    {
        $lines = '';
        $count = 0;
        DB::table('audit_logs')->where('occurred_at', '<', $before)->orderBy('id')->chunkById(1000, function ($rows) use (&$lines, &$count): void {
            foreach ($rows as $row) {
                $lines .= json_encode($row, JSON_UNESCAPED_UNICODE)."\n";
                $count++;
            }
        });
        $content = (string) gzencode($lines, 9);
        $file = 'archives/audit-avant-'.now()->format('Ymd-His').'.jsonl.gz';
        Storage::disk('local')->put($file, $content);
        Storage::disk('local')->put($file.'.sha256', hash('sha256', $content).'  '.basename($file)."\n");

        return ['file' => $file, 'sha256' => hash('sha256', $content), 'entries' => $count];
    }
}
