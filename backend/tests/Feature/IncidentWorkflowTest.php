<?php

namespace Tests\Feature;

use App\Models\Incident;
use App\Models\Setting;
use Tests\TestCase;

/**
 * Traitement des incidents (§10.3, §15.2) : échéance selon la gravité, responsable des incidents automatiques, circuit
 * qualification → intervention → résolution → vérification → clôture, réouverture, escalade et statistiques.
 */
class IncidentWorkflowTest extends TestCase
{
    public function test_the_full_workflow_requires_a_verification_before_closing(): void
    {
        $screen = $this->screen('ECR-INC');
        $technician = $this->userWith('technicien', 'DSI');
        $supervisor = $this->userWith('technicien', 'DSI');
        $id = $this->actingAs($technician)->postJson('/api/v1/incidents', ['title' => 'Image figée', 'severity' => 'minor', 'screen_id' => $screen->id])->assertCreated()->json('data.id');
        $this->assertEqualsWithDelta(now()->addDay()->getTimestamp(), Incident::query()->findOrFail($id)->due_at->getTimestamp(), 5, 'échéance mineure : 24 h');

        $act = fn (array $body, $user = null) => $this->actingAs($user ?? $technician)->patchJson("/api/v1/incidents/$id", $body);
        $act(['action' => 'qualify', 'severity' => 'critical', 'category' => 'player', 'note' => 'Plusieurs étages touchés'])->assertOk();
        $this->assertSame('critical', Incident::query()->findOrFail($id)->severity);
        $act(['action' => 'intervene'])->assertUnprocessable()->assertJsonValidationErrors('note');
        $act(['action' => 'intervene', 'note' => 'Remplacement du câble HDMI'])->assertOk();
        $act(['action' => 'resolve', 'note' => 'Image rétablie'])->assertOk();
        $act(['action' => 'close'])->assertStatus(409)->assertJsonPath('code', 'VERIFICATION_REQUIRED');
        $act(['action' => 'reopen', 'note' => 'L’image se fige de nouveau'])->assertOk()->assertJsonPath('data.status', 'open');
        $act(['action' => 'resolve', 'note' => 'Lecteur remplacé'])->assertOk();
        $act(['action' => 'verify', 'note' => 'Diffusion contrôlée sur place pendant 15 min'], $supervisor)->assertOk();
        $act(['action' => 'close'])->assertOk()->assertJsonPath('data.status', 'closed');

        $row = collect($this->actingAs($technician)->getJson('/api/v1/incidents')->json('data'))->firstWhere('id', $id);
        $this->assertSame($supervisor->id, $row['verified_by']['id']);
        $this->assertSame(['Déclaration', 'qualify', 'intervene', 'resolve', 'reopen', 'resolve', 'verify', 'close'], array_column($row['events'], 'action'));
    }

    public function test_automatic_incidents_go_to_the_default_assignee_and_overdue_ones_are_escalated(): void
    {
        $technician = $this->userWith('technicien', 'DSI');
        $manager = $this->userWith('admin_tech', 'DSI');
        $this->actingAs($manager)->patchJson('/api/v1/settings', ['key' => 'incidents.default_assignee_id', 'value' => $technician->id, 'reason' => 'Astreinte de novembre'])->assertOk();
        $screen = $this->screen('ECR-AUTO');
        [$device] = $this->pairedDevice($screen);
        $device->forceFill(['last_seen_at' => now()->subHour()])->save();

        $this->artisan('saie:tick')->assertSuccessful();
        $incident = Incident::query()->where('device_id', $device->id)->where('kind', 'device_unavailable')->firstOrFail();
        $this->assertSame($technician->id, $incident->assignee_id);

        $incident->forceFill(['due_at' => now()->subMinute()])->save();
        $this->artisan('saie:tick')->assertSuccessful();
        $incident->refresh();
        $this->assertSame(1, $incident->escalation_level);
        $this->assertTrue($incident->due_at->isFuture());
        $this->assertDatabaseHas('notifications', ['user_id' => $technician->id, 'event' => 'incident.escalated']);
        $this->assertDatabaseHas('notifications', ['user_id' => $manager->id, 'event' => 'incident.escalated']);
    }

    public function test_statistics_report_volumes_and_mean_delays(): void
    {
        $screen = $this->screen('ECR-STATS');
        Incident::query()->create(['reference' => 'INC-S1', 'screen_id' => $screen->id, 'kind' => 'manual', 'category' => 'panel', 'severity' => 'major', 'status' => 'resolved', 'title' => 'A',
            'detected_at' => now()->subHours(3), 'acknowledged_at' => now()->subHours(2), 'resolved_at' => now()->subHour()]);
        Incident::query()->create(['reference' => 'INC-S2', 'screen_id' => $screen->id, 'kind' => 'manual', 'category' => 'network', 'severity' => 'major', 'status' => 'open', 'title' => 'B', 'detected_at' => now()->subHour()]);
        Setting::put('incidents.delay_major_min', 120);

        $stats = $this->actingAs($this->userWith('technicien', 'DSI'))->getJson('/api/v1/incidents/stats')->assertOk()->json('data');
        $this->assertSame(2, $stats['total']);
        $this->assertSame(1, $stats['open']);
        $this->assertEquals(60, $stats['by_severity']['major']['mean_ack_min']);
        $this->assertEquals(120, $stats['by_severity']['major']['mean_resolve_min']);
        $this->assertSame(['panel' => 1, 'network' => 1], $stats['by_category']);
    }
}
