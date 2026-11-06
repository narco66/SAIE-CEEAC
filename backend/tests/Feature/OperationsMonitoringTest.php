<?php

namespace Tests\Feature;

use App\Jobs\GenerateExport;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * EXP-01, NOT-01, CONT-01 : la supervision mesure les files réelles et la fraîcheur des sauvegardes ;
 * un lecteur indisponible ouvre un incident notifié.
 */
class OperationsMonitoringTest extends TestCase
{
    /**
     * @return array<string, array<string, mixed>>
     */
    private function checks(): array
    {
        return collect($this->actingAs($this->userWith('admin_tech'))->getJson('/api/v1/supervision')->assertOk()->json('data.checks'))->keyBy('name')->all();
    }

    public function test_queue_check_reads_the_active_queue_driver(): void
    {
        config(['queue.default' => 'database']);
        Queue::connection('database')->pushOn('exports', new GenerateExport('00000000-0000-0000-0000-000000000000'));

        $this->assertStringContainsString('1 en attente', $this->checks()['Files de traitement']['detail']);
        $this->assertStringContainsString('pilote database', $this->checks()['Files de traitement']['detail']);
    }

    public function test_backup_freshness_is_measured_from_the_backup_service_status(): void
    {
        $file = storage_path('app/backup-status.json');
        @unlink($file);
        $this->assertStringStartsWith('Non mesuré', $this->checks()['Sauvegardes']['detail']);

        file_put_contents($file, json_encode(['last_success_at' => now()->subHours(3)->toIso8601ZuluString(), 'last_wal_at' => now()->subMinutes(20)->toIso8601ZuluString()]));
        try {
            $check = $this->checks()['Sauvegardes'];
            $this->assertTrue($check['ok']);
            $this->assertStringContainsString('il y a 3 h', $check['detail']);
        } finally {
            @unlink($file);
        }
    }

    public function test_unavailable_player_opens_an_incident_notified_to_incident_managers(): void
    {
        $manager = $this->userWith('admin_tech');
        [$device] = $this->pairedDevice($this->screen('ECR-PANNE'));
        $device->forceFill(['last_seen_at' => now()->subMinutes(10)])->save();

        $this->artisan('saie:tick')->assertSuccessful();

        $this->assertDatabaseHas('incidents', ['device_id' => $device->id, 'kind' => 'device_unavailable', 'status' => 'open']);
        $this->assertDatabaseHas('notifications', ['user_id' => $manager->id, 'event' => 'incident.opened']);
    }
}
