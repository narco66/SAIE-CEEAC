<?php

namespace Tests\Feature;

use App\Models\Incident;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Services\Access;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Lot 25 (§10.22, §17.2) : flux temps réel SSE sans dépendance — annonces des changements significatifs, filtrage selon
 * le périmètre, reprise au dernier identifiant, accès réservé.
 */
class RealtimeStreamTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['saie.realtime.stream_seconds' => 0, 'saie.realtime.poll_ms' => 200]);
    }

    public function test_significant_changes_are_announced_once_and_heartbeats_are_not(): void
    {
        [$device, $token] = $this->pairedDevice($this->screen('ECR-RT'));
        $beat = fn () => $this->postJson('/api/v1/device/heartbeat', ['device_time' => now()->toIso8601String(), 'app_version' => '1.0.0'], ['Authorization' => "Bearer $token"])->assertOk();
        $beat();
        $after = DB::table('realtime_events')->count();
        $beat();
        $this->assertSame($after, DB::table('realtime_events')->count(), 'un battement sans changement n’est pas annoncé');

        Incident::query()->create(['reference' => 'INC-RT1', 'screen_id' => $device->screen_id, 'device_id' => $device->id, 'kind' => 'manual', 'category' => 'panel', 'severity' => 'minor', 'status' => 'open', 'title' => 'Rayure', 'detected_at' => now()]);
        $this->assertDatabaseHas('realtime_events', ['topic' => 'incident', 'screen_id' => $device->screen_id]);
    }

    public function test_the_stream_is_filtered_by_scope_and_resumes_after_the_last_id(): void
    {
        $inside = $this->screen('ECR-RT-HALL');
        $outside = $this->screen('ECR-RT-PALIER', false);
        DB::table('realtime_events')->insert([
            ['topic' => 'screen', 'screen_id' => $inside->id, 'ref_id' => null, 'created_at' => now()],
            ['topic' => 'screen', 'screen_id' => $outside->id, 'ref_id' => null, 'created_at' => now()],
        ]);
        $first = (int) DB::table('realtime_events')->min('id');

        $admin = $this->userWith('admin_tech', 'DSI');
        $all = $this->actingAs($admin)->get('/api/v1/realtime/stream?since=0', ['Accept' => 'text/event-stream'])->assertOk()->streamedContent();
        $this->assertStringContainsString($inside->id, $all);
        $this->assertStringContainsString($outside->id, $all);
        $resumed = $this->actingAs($admin)->get('/api/v1/realtime/stream', ['Accept' => 'text/event-stream', 'Last-Event-ID' => (string) $first])->streamedContent();
        $this->assertStringNotContainsString($inside->id, $resumed, 'reprise après le dernier identifiant reçu');

        $zone = User::query()->create(['name' => 'Superviseur de zone', 'email' => uniqid('z').'@test.local', 'password' => 'Secret-Passw0rd!', 'structure_id' => $this->structure('DSI')->id]);
        RoleAssignment::query()->create(['user_id' => $zone->id, 'role_id' => Role::query()->where('code', 'superviseur')->value('id'), 'zone_id' => $this->place()['zone_public']->id, 'starts_at' => now()->subDay()]);
        app(Access::class)->flush();
        $scoped = $this->actingAs($zone)->get('/api/v1/realtime/stream?since=0', ['Accept' => 'text/event-stream'])->assertOk()->streamedContent();
        $this->assertStringContainsString($inside->id, $scoped);
        $this->assertStringNotContainsString($outside->id, $scoped, 'changement hors périmètre non annoncé');

        $this->actingAs($this->userWith('agent'))->get('/api/v1/realtime/stream', ['Accept' => 'text/event-stream'])->assertForbidden();
    }
}
