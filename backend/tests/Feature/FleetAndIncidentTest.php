<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\UserNotification;
use App\Services\ExportService;
use App\Services\OperationalMetrics;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Parc d'écrans et maintenance (audit 2026-11-06, écarts PAR-01 à PAR-03, INC-01, INC-02, API-08) : fiche matérielle,
 * état opérationnel jamais déduit du seul lecteur, statut « en maintenance » motivé, incidents catégorisés et affectés à un
 * intervenant habilité et prévenu, dates invalides refusées sans erreur serveur.
 */
class FleetAndIncidentTest extends TestCase
{
    public function test_screen_hardware_record_and_operational_states(): void
    {
        $admin = $this->userWith('admin_tech', 'DSI');
        $pending = $this->screen('ECR-NEUF');
        $this->actingAs($admin)->patchJson("/api/v1/screens/{$pending->id}", [
            'expected_version' => $pending->version, 'manufacturer' => 'Samsung', 'model' => 'QM55C', 'diagonal_inches' => 55, 'ip_address' => '10.20.0.15',
            'connection_type' => 'ethernet', 'installed_on' => '2026-10-01', 'warranty_until' => '2029-10-01',
        ])->assertOk()->assertJsonPath('data.hardware.manufacturer', 'Samsung')->assertJsonPath('data.hardware.under_warranty', true);

        $show = fn ($screen) => $this->actingAs($admin)->getJson("/api/v1/screens/{$screen->id}")->assertOk()->json('data.operational_state.code');
        $this->assertSame('pending_configuration', $show($pending), 'sans lecteur appairé');

        $reachable = $this->screen('ECR-OK');
        $this->pairedDevice($reachable);
        $this->assertSame('active', $show($reachable));

        $silent = $this->screen('ECR-MUET');
        [$device] = $this->pairedDevice($silent);
        $device->forceFill(['last_seen_at' => now()->subHour()])->save();
        $this->assertSame('offline', $show($silent));

        $faulty = $this->screen('ECR-ERR');
        [$faultyDevice] = $this->pairedDevice($faulty);
        Incident::query()->create(['reference' => 'INC-T1', 'device_id' => $faultyDevice->id, 'kind' => 'manual', 'severity' => 'minor', 'title' => 'Écran figé', 'detected_at' => now()]);
        $this->assertSame('anomaly', $show($faulty));
    }

    public function test_maintenance_status_requires_a_reason_and_keeps_the_screen_targeted(): void
    {
        $admin = $this->userWith('admin_tech', 'DSI');
        $screen = $this->screen('ECR-MAINT');

        $this->actingAs($admin)->patchJson("/api/v1/screens/{$screen->id}", ['expected_version' => $screen->version, 'status' => 'maintenance'])
            ->assertUnprocessable()->assertJsonValidationErrors('maintenance_reason');
        $this->actingAs($admin)->patchJson("/api/v1/screens/{$screen->id}", ['expected_version' => $screen->version, 'status' => 'maintenance', 'maintenance_reason' => 'Remplacement de la dalle'])
            ->assertOk()->assertJsonPath('data.operational_state.code', 'maintenance');

        $this->actingAs($admin)->patchJson("/api/v1/screens/{$screen->id}", ['expected_version' => $screen->refresh()->version, 'warranty_until' => '2020-01-01', 'installed_on' => '2021-01-01'])
            ->assertUnprocessable()->assertJsonValidationErrors('warranty_until');
    }

    public function test_incident_is_categorised_diagnosed_and_assigned_only_to_an_eligible_notified_agent(): void
    {
        $admin = $this->userWith('admin_tech', 'DSI');
        $technician = $this->userWith('admin_tech', 'DSI');
        $agent = $this->userWith('agent');

        $id = $this->actingAs($admin)->postJson('/api/v1/incidents', ['title' => 'Dalle sans image', 'severity' => 'major', 'category' => 'panel', 'maintenance_type' => 'corrective'])
            ->assertCreated()->json('data.id');
        $this->assertDatabaseHas('incidents', ['id' => $id, 'category' => 'panel']);

        $this->actingAs($admin)->patchJson("/api/v1/incidents/$id", ['action' => 'assign', 'assignee_id' => $agent->id])
            ->assertUnprocessable()->assertJsonPath('code', 'ASSIGNEE_NOT_ELIGIBLE');
        $this->actingAs($admin)->patchJson("/api/v1/incidents/$id", ['action' => 'assign', 'assignee_id' => $technician->id])->assertOk();
        $this->assertSame(1, UserNotification::query()->where('user_id', $technician->id)->where('event', 'incident.assigned')->count());

        $this->actingAs($admin)->patchJson("/api/v1/incidents/$id", ['action' => 'diagnose'])->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->actingAs($admin)->patchJson("/api/v1/incidents/$id", ['action' => 'diagnose', 'note' => 'Câble HDMI défectueux'])->assertOk();
        $this->assertSame('Câble HDMI défectueux', Incident::query()->findOrFail($id)->diagnosis);
    }

    public function test_invalid_dates_are_refused_without_server_error_and_calendar_period_is_bounded(): void
    {
        $agent = $this->userWith('agent');
        $auditor = $this->userWith('auditeur');

        $this->actingAs($agent)->getJson('/api/v1/rooms?from=pas-une-date')->assertUnprocessable();
        $this->actingAs($auditor)->getJson('/api/v1/audit-logs?from=pas-une-date')->assertUnprocessable();
        $this->actingAs($agent)->getJson('/api/v1/rooms-calendar?from=2026-01-01&to=2027-01-01')->assertUnprocessable()->assertJsonPath('code', 'PERIOD_TOO_LONG');
        $this->actingAs($agent)->getJson('/api/v1/rooms-calendar?from=2026-11-01&to=2026-12-01')->assertOk();
    }

    public function test_player_anomalies_open_then_resolve_automatic_incidents(): void
    {
        $this->userWith('admin_tech', 'DSI');
        [$device] = $this->pairedDevice($this->screen('ECR-ANOM'));
        $device->forceFill(['storage' => ['usage' => 95, 'quota' => 100], 'clock_skew_ms' => 60000])->save();

        $this->artisan('saie:tick')->assertSuccessful();
        $kinds = Incident::query()->where('device_id', $device->id)->whereIn('status', ['open', 'acknowledged'])->pluck('kind')->sort()->values()->all();
        $this->assertSame(['clock_drift', 'storage_full'], $kinds);
        $this->assertSame(2, UserNotification::query()->where('event', 'incident.opened')->count());

        $this->artisan('saie:tick')->assertSuccessful();
        $this->assertSame(2, Incident::query()->where('device_id', $device->id)->count(), 'aucun doublon');

        $device->forceFill(['storage' => ['usage' => 10, 'quota' => 100], 'clock_skew_ms' => 200, 'last_seen_at' => now()])->save();
        $this->artisan('saie:tick')->assertSuccessful();
        $this->assertSame(0, Incident::query()->where('device_id', $device->id)->whereIn('status', ['open', 'acknowledged'])->count(), 'résolus quand la condition disparaît');
    }

    public function test_remote_reload_is_queued_redelivered_until_acknowledged_and_traced(): void
    {
        $admin = $this->userWith('admin_tech', 'DSI');
        [$device, $token] = $this->pairedDevice($this->screen('ECR-RELANCE'));
        $device->forceFill(['capabilities' => ['sync', 'restart_player']])->save();

        $this->actingAs($admin)->postJson("/api/v1/devices/{$device->id}/reload", [])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $id = $this->actingAs($admin)->postJson("/api/v1/devices/{$device->id}/reload", ['reason' => 'Affichage figé'])->assertOk()->assertJsonPath('data.command.status', 'pending')->json('data.command.id');
        $this->app['auth']->forgetGuards();

        $heartbeat = fn () => $this->postJson('/api/v1/device/heartbeat', ['device_time' => now()->toIso8601String()], ['Authorization' => "Bearer $token"])->assertOk();
        $this->assertSame('restart_player', $heartbeat()->json('commands.0.command'));
        $this->assertSame($id, $heartbeat()->json('commands.0.id'), 'renvoyée tant que le lecteur n’a pas accusé réception');
        $this->postJson("/api/v1/device/commands/$id/ack", ['status' => 'executed', 'message' => 'Application relancée'], ['Authorization' => "Bearer $token"])->assertOk()->assertJsonPath('data.status', 'executed');
        $this->assertSame([], $heartbeat()->json('commands'), 'plus remise une fois exécutée');
        $this->assertDatabaseHas('audit_logs', ['action' => 'device.command_requested', 'reason' => 'Affichage figé']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'device.command_executed']);
    }

    public function test_unauthorized_terminal_calls_are_traced_and_reported_in_supervision(): void
    {
        $this->getJson('/api/v1/device/manifest', ['Authorization' => 'Bearer 999|jeton-forge'])->assertUnauthorized();
        $this->getJson('/api/v1/device/manifest', ['Authorization' => 'Bearer 999|jeton-forge'])->assertUnauthorized();
        $this->assertSame(1, AuditLog::query()->where('action', 'device.unauthorized')->count(), 'une trace par adresse et par période');

        $check = collect($this->actingAs($this->userWith('admin_tech', 'DSI'))->getJson('/api/v1/supervision')->assertOk()->json('data.checks'))->firstWhere('name', 'Terminaux non autorisés');
        $this->assertTrue($check['warn']);
    }

    public function test_telemetry_is_sampled_once_per_five_minute_slot(): void
    {
        [$device, $token] = $this->pairedDevice($this->screen('ECR-TELEM'));
        $beat = fn () => $this->postJson('/api/v1/device/heartbeat', ['device_time' => now()->toIso8601String()], ['Authorization' => "Bearer $token"])->assertOk();

        $beat();
        $this->travel(30)->seconds();
        $beat();
        $this->assertSame(1, DB::table('device_telemetry')->where('device_id', $device->id)->count());
        // Avec un heartbeat toutes les 30 s, le relevé suivant tombe à 300 s : un créneau de 5 min = un relevé (disponibilité non plafonnée).
        $this->travel(270)->seconds();
        $beat();
        $this->assertSame(2, DB::table('device_telemetry')->where('device_id', $device->id)->count());
    }

    public function test_supervision_never_shows_unmeasured_checks_as_ok_and_dashboard_reports_the_fleet(): void
    {
        $admin = $this->userWith('admin_tech', 'DSI');
        $this->pairedDevice($this->screen('ECR-SUP'));
        $this->screen('ECR-SANS-LECTEUR');
        DB::table('dynamic_sources')->insert(['id' => (string) Str::uuid(), 'code' => 'METEO', 'name' => 'Météo', 'type' => 'weather', 'is_active' => true, 'frequency_minutes' => 30, 'last_fetched_at' => now()->subHours(3), 'last_status' => 'ok', 'created_at' => now(), 'updated_at' => now()]);

        $checks = collect($this->actingAs($admin)->getJson('/api/v1/supervision')->assertOk()->json('data.checks'))->keyBy('name');
        $this->assertFalse($checks['Dalles']['ok'], 'aucun canal de pilotage : non mesuré, jamais OK');
        $this->assertFalse($checks['Collecte des sources dynamiques']['ok'], 'relevé plus vieux que deux fréquences');

        $dashboard = $this->actingAs($admin)->getJson('/api/v1/dashboard')->assertOk();
        $this->assertSame(2, $dashboard->json('data.fleet.total'));
        $this->assertSame(1, $dashboard->json('data.fleet.by_state.active'));
        $this->assertSame(1, $dashboard->json('data.fleet.by_state.pending_configuration'));
        $this->assertSame('Siège', $dashboard->json('data.buildings.0.name'));
        $this->assertArrayHasKey('on', $dashboard->json('data.default_window'));
    }

    public function test_retention_simulates_by_default_then_purges_and_archives_the_audit_log_without_deleting_it(): void
    {
        [$device] = $this->pairedDevice($this->screen('ECR-RETENTION'));
        $user = $this->userWith('agent');
        DB::table('device_telemetry')->insert([
            ['device_id' => $device->id, 'recorded_at' => now()->subDays(200), 'payload' => '{}'],
            ['device_id' => $device->id, 'recorded_at' => now()->subDay(), 'payload' => '{}'],
        ]);
        DB::table('notifications')->insert(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'event' => 'x', 'title' => 'Ancienne', 'dedup_key' => 'old-1', 'read_at' => now()->subDays(300), 'created_at' => now()->subDays(300)]);
        DB::table('audit_logs')->insert(['occurred_at' => now()->subYears(6), 'action' => 'test.ancien', 'actor_type' => 'system']);

        $this->artisan('saie:retention')->assertSuccessful();
        $this->assertSame(2, DB::table('device_telemetry')->where('device_id', $device->id)->count(), 'simulation : rien supprimé');

        $this->artisan('saie:retention --apply')->assertSuccessful();
        $this->assertSame(1, DB::table('device_telemetry')->where('device_id', $device->id)->count(), 'seule la télémétrie échue est purgée');
        $this->assertSame(0, DB::table('notifications')->where('dedup_key', 'old-1')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'test.ancien')->count(), 'journal en ajout seul : archivé, jamais supprimé');
        $archives = collect(Storage::disk('local')->files('archives'));
        $archive = $archives->first(fn ($f) => str_ends_with($f, '.jsonl.gz'));
        $this->assertNotNull($archive);
        $this->assertStringContainsString('test.ancien', gzdecode(Storage::disk('local')->get($archive)));
        $this->assertTrue($archives->contains($archive.'.sha256'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'retention.applied']);
    }

    public function test_error_log_and_api_metrics_are_exposed_to_technical_staff_without_sensitive_data(): void
    {
        $this->assertSame('Échec de connexion password=[masqué] pour [adresse masquée]',
            OperationalMetrics::sanitize('Échec de connexion password=Secret123 pour agent@ceeac.org (Connection: pgsql, SQL: select * from users where email = agent@ceeac.org)'));

        $technical = $this->userWith('admin_tech', 'DSI');
        config(['cache.default' => 'database']);
        $this->assertFalse(app(OperationalMetrics::class)->lastHour()['measured'], 'cache en base : aucun compteur, « non mesuré »');
        config(['cache.default' => 'array']);
        $this->actingAs($technical)->getJson('/api/v1/dashboard')->assertOk();
        $data = $this->actingAs($technical)->getJson('/api/v1/supervision/errors')->assertOk()->json('data');
        $this->assertGreaterThanOrEqual(1, $data['api']['requests'], 'requêtes comptées par le middleware');
        $this->assertIsArray($data['errors']);

        $this->actingAs($this->userWith('technicien', 'DSI'))->getJson('/api/v1/supervision/errors')->assertForbidden();
    }

    public function test_preventive_maintenance_plan_opens_one_intervention_and_reschedules_on_resolution(): void
    {
        $technician = $this->userWith('technicien', 'DSI');
        $screen = $this->screen('ECR-PREV');
        $this->actingAs($this->userWith('agent'))->postJson('/api/v1/maintenance-plans', ['title' => 'Nettoyage des dalles', 'frequency_days' => 30, 'next_due_on' => now()->toDateString()])->assertForbidden();
        $planId = $this->actingAs($technician)->postJson('/api/v1/maintenance-plans', [
            'title' => 'Nettoyage et contrôle des dalles', 'screen_id' => $screen->id, 'category' => 'panel', 'frequency_days' => 30, 'next_due_on' => now()->subDay()->toDateString(),
        ])->assertCreated()->assertJsonPath('data.overdue', true)->json('data.id');

        $this->artisan('saie:tick')->assertSuccessful();
        $this->artisan('saie:tick')->assertSuccessful();
        $incidents = Incident::query()->where('maintenance_plan_id', $planId)->get();
        $this->assertCount(1, $incidents, 'une seule intervention ouverte par échéance');
        $this->assertSame('preventive', $incidents[0]->maintenance_type);

        $this->actingAs($technician)->patchJson("/api/v1/incidents/{$incidents[0]->id}", ['action' => 'resolve', 'note' => 'Dalles nettoyées'])->assertOk();
        $plan = $this->actingAs($technician)->getJson('/api/v1/maintenance-plans')->assertOk()->json('data.0');
        $this->assertSame(now('Africa/Libreville')->toDateString(), $plan['last_done_on']);
        $this->assertSame(now('Africa/Libreville')->addDays(30)->toDateString(), $plan['next_due_on']);
    }

    public function test_incident_report_shows_french_status_and_severity(): void
    {
        $screen = $this->screen('ECR-PDF');
        [$device] = $this->pairedDevice($screen);
        Incident::query()->create(['reference' => 'INC-LBL', 'device_id' => $device->id, 'kind' => 'manual', 'severity' => 'major', 'title' => 'Dalle noire', 'detected_at' => now()->subHour()]);

        $model = app(ExportService::class)->build('incident_report', [], $this->userWith('admin_tech', 'DSI'));
        $table = $model['sections'][0]['table'];
        $row = array_combine($table['headers'], collect($table['rows'])->firstWhere(0, 'INC-LBL'));

        $this->assertSame('Majeur', $row['Gravité']);
        $this->assertSame('Ouvert', $row['Statut']);
    }
}
