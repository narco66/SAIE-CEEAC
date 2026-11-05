<?php

namespace App\Console\Commands;

use App\Models\Device;
use App\Services\ManifestBuilder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

#[Signature('saie:demo-fleet {--once : un seul cycle} {--offline=ECR-2E-02 : écrans laissés hors ligne} {--suspect=ECR-5E-01 : écrans laissés suspects}')]
#[Description('Simulateur de parc de DÉMONSTRATION : maintient le contact des lecteurs fictifs (is_demo) via le vrai compilateur de manifeste. Interdit en production.')]
class DemoFleet extends Command
{
    public function handle(ManifestBuilder $builder): int
    {
        if (app()->isProduction()) {
            $this->error('Simulateur interdit en production.');

            return self::FAILURE;
        }
        $offline = array_filter(explode(',', (string) $this->option('offline')));
        $suspect = array_filter(explode(',', (string) $this->option('suspect')));
        do {
            $devices = Device::query()->with('screen')->where('is_demo', true)->where('status', 'active')->get();
            foreach ($devices as $device) {
                $code = $device->screen?->code;
                if (in_array($code, $offline, true)) {
                    continue;
                }
                $manifest = $builder->current($device);
                $lastSeen = in_array($code, $suspect, true) ? now()->subSeconds(150) : now();
                $device->forceFill(['last_seen_at' => $lastSeen, 'last_sync_at' => $lastSeen, 'network_status' => 'online', 'active_manifest_version' => $manifest->version, 'clock_skew_ms' => random_int(-900, 900)])->save();
                $rows = [];
                if (! DB::table('device_receipts')->where('device_id', $device->id)->where('kind', 'activated')->where('manifest_version', $manifest->version)->exists()) {
                    foreach (['manifest_received', 'content_loaded', 'activated'] as $kind) {
                        $rows[] = $this->receipt($device->id, $kind, $manifest->version);
                    }
                }
                foreach (collect($manifest->payload['schedule'] ?? [])->unique('campaign_id') as $entry) {
                    if (! DB::table('device_receipts')->where('device_id', $device->id)->where('kind', 'played')->where('ref_id', $entry['campaign_id'])->where('occurred_at', '>', now()->subMinutes(10))->exists()) {
                        $rows[] = $this->receipt($device->id, 'played', $manifest->version, 'campaign', $entry['campaign_id'], $entry['lang']);
                    }
                }
                foreach ($manifest->payload['alerts'] ?? [] as $alert) {
                    DB::table('alert_receipts')->updateOrInsert(['alert_id' => $alert['id'], 'device_id' => $device->id], ['id' => (string) Str::uuid(), 'received_at' => now(), 'activated_at' => now()->addSeconds(2)]);
                }
                DB::table('device_receipts')->insertOrIgnore($rows);
            }
            $this->line(now()->format('H:i:s').' · '.$devices->count().' lecteur(s) de démonstration simulés');
            if (! $this->option('once')) {
                sleep(30);
            }
        } while (! $this->option('once'));

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function receipt(string $deviceId, string $kind, int $version, ?string $refType = null, ?string $refId = null, ?string $lang = null): array
    {
        return ['id' => (string) Str::uuid(), 'event_id' => (string) Str::uuid(), 'device_id' => $deviceId, 'kind' => $kind, 'manifest_version' => $version, 'ref_type' => $refType, 'ref_id' => $refId, 'lang' => $lang, 'occurred_at' => now(), 'received_at' => now(), 'payload' => json_encode(['simulated' => true])];
    }
}
