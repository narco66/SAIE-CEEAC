<?php

namespace Tests\Feature;

use App\Models\DeviceCommand;
use App\Models\Incident;
use App\Models\ScreenStatusHistory;
use App\Models\Setting;
use App\Services\ExportService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Rapports d'exploitation du parc (§13.3) : écrans hors ligne, commandes, fin de garantie, disponibilité, maintenance ;
 * accessibles à l'auditeur (§14) ; durées de conservation paramétrables.
 */
class FleetReportsTest extends TestCase
{
    public function test_each_fleet_report_is_built_from_real_data(): void
    {
        $offline = $this->screen('ECR-HL', true, ['warranty_until' => now()->subDay()->toDateString(), 'supplier' => 'Fournisseur SA']);
        [$device] = $this->pairedDevice($offline);
        $device->forceFill(['last_seen_at' => now()->subHours(2)])->save();
        ScreenStatusHistory::query()->create(['screen_id' => $offline->id, 'device_id' => $device->id, 'state' => 'offline', 'started_at' => now()->subHours(2)]);
        $this->screen('ECR-SANS', true, ['warranty_until' => now()->addYears(3)->toDateString()]);
        $admin = $this->userWith('admin_tech', 'DSI');
        DeviceCommand::query()->create(['device_id' => $device->id, 'screen_id' => $offline->id, 'command' => 'sync', 'status' => 'executed', 'requested_by' => $admin->id,
            'requested_at' => now()->subHour(), 'completed_at' => now()->subHour()->addSeconds(40), 'expires_at' => now(), 'result_message' => 'Synchronisation effectuée']);
        Incident::query()->create(['reference' => 'INC-M1', 'screen_id' => $offline->id, 'kind' => 'manual', 'category' => 'panel', 'severity' => 'minor', 'status' => 'closed', 'title' => 'Nettoyage',
            'maintenance_type' => 'preventive', 'detected_at' => now()->subDay(), 'resolution' => 'Dalle nettoyée', 'closed_at' => now()]);
        $auditor = $this->userWith('auditeur', 'DSI');
        $exports = app(ExportService::class);

        $report = $exports->build('offline_screens', [], $auditor);
        $this->assertSame('ECR-HL', $report['sections'][0]['table']['rows'][0][0]);
        $this->assertSame('ECR-SANS', $report['sections'][1]['table']['rows'][0][0], 'écran en service sans lecteur');

        $commands = $exports->build('remote_commands', [], $auditor)['sections'][0]['table']['rows'];
        $this->assertSame(['Synchroniser', 'Exécutée', '40 s'], [$commands[0][3], $commands[0][6], $commands[0][8]]);

        $warranty = $exports->build('warranty_expiry', ['months' => 6], $auditor)['sections'][0]['table']['rows'];
        $this->assertSame([['ECR-HL', 'Expirée']], array_map(fn ($r) => [$r[0], $r[8]], $warranty));

        $availability = $exports->build('availability_report', [], $auditor);
        $this->assertSame(['ECR-HL', '1'], [$availability['sections'][1]['table']['rows'][0][0], $availability['sections'][1]['table']['rows'][0][5]]);

        $maintenance = $exports->build('maintenance_report', [], $auditor)['sections'][1]['table']['rows'];
        $this->assertSame(['INC-M1', 'Préventive', 'Dalle nettoyée'], [$maintenance[0][0], $maintenance[0][2], $maintenance[0][5]]);
    }

    public function test_the_auditor_can_request_fleet_reports_and_inventory(): void
    {
        Queue::fake();
        $auditor = $this->userWith('auditeur', 'DSI');
        foreach (['offline_screens', 'warranty_expiry', 'availability_report', 'maintenance_report', 'remote_commands', 'equipment_inventory', 'incident_report'] as $type) {
            $this->actingAs($auditor)->postJson('/api/v1/exports', ['type' => $type, 'format' => 'xlsx', 'params' => []])->assertStatus(202);
            DB::table('exports')->update(['status' => 'ready']); // limite de 5 exports en attente par personne
        }
        $this->actingAs($this->userWith('agent'))->postJson('/api/v1/exports', ['type' => 'offline_screens', 'format' => 'pdf', 'params' => []])->assertForbidden();
    }

    public function test_retention_purges_old_receipts_commands_and_states_only_when_applied(): void
    {
        $screen = $this->screen('ECR-RET');
        [$device] = $this->pairedDevice($screen);
        DB::table('device_receipts')->insert(['id' => (string) Str::uuid(), 'event_id' => (string) Str::uuid(), 'device_id' => $device->id, 'kind' => 'played', 'occurred_at' => now()->subDays(500), 'received_at' => now()->subDays(500)]);
        DeviceCommand::query()->create(['device_id' => $device->id, 'screen_id' => $screen->id, 'command' => 'logs', 'status' => 'executed', 'requested_at' => now()->subDays(400), 'expires_at' => now()->subDays(400)]);
        DeviceCommand::query()->create(['device_id' => $device->id, 'screen_id' => $screen->id, 'command' => 'sync', 'status' => 'pending', 'requested_at' => now()->subDays(400), 'expires_at' => now()->addMinute()]);
        ScreenStatusHistory::query()->create(['screen_id' => $screen->id, 'state' => 'active', 'started_at' => now()->subDays(800), 'ended_at' => now()->subDays(790)]);
        Setting::put('retention.commands_days', 365);

        $this->artisan('saie:retention')->assertSuccessful();
        $this->assertSame(1, DB::table('device_receipts')->count(), 'simulation : rien n’est supprimé');

        $this->artisan('saie:retention --apply')->assertSuccessful();
        $this->assertSame(0, DB::table('device_receipts')->count());
        $this->assertSame(['pending'], DeviceCommand::query()->pluck('status')->all(), 'une commande en cours est conservée');
        $this->assertSame(0, ScreenStatusHistory::query()->count());
    }
}
