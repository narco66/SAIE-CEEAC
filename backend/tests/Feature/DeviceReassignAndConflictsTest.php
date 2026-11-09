<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Content;
use App\Models\DeviceCommand;
use App\Services\CampaignService;
use Tests\TestCase;

/**
 * Évolutions issues du contrôle final : réaffectation d'un lecteur sans révocation (§9.2) et conflits de programmation
 * calculés sur les jours et les créneaux (§12, critère de recette n° 6).
 */
class DeviceReassignAndConflictsTest extends TestCase
{
    public function test_a_player_moves_to_another_screen_without_being_revoked(): void
    {
        $admin = $this->userWith('admin_tech', 'DSI');
        $from = $this->screen('ECR-AVANT');
        $to = $this->screen('ECR-APRES');
        [$device, $token] = $this->pairedDevice($from);
        $pending = DeviceCommand::query()->create(['device_id' => $device->id, 'screen_id' => $from->id, 'command' => 'sync', 'status' => 'pending', 'requested_at' => now(), 'expires_at' => now()->addMinutes(10)]);

        $this->actingAs($admin)->postJson("/api/v1/devices/{$device->id}/reassign", ['screen_id' => $to->id])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->actingAs($admin)->postJson("/api/v1/devices/{$device->id}/reassign", ['screen_id' => $to->id, 'reason' => 'Boîtier déplacé au hall'])->assertOk();

        $device->refresh();
        $this->assertSame([$to->id, 'active'], [$device->screen_id, $device->status], 'même lecteur, même jeton, nouvel écran');
        $this->assertSame('cancelled', $pending->fresh()->status);
        $this->actingAs($admin)->getJson("/api/v1/screens/{$from->id}")->assertJsonPath('data.operational_state.code', 'pending_configuration');
        $this->app['auth']->forgetGuards();
        $manifest = json_decode($this->getJson('/api/v1/device/manifest', ['Authorization' => "Bearer $token"])->assertOk()->json('payload'), true);
        $this->assertSame('ECR-APRES', $manifest['screen']['code']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'device.reassigned', 'object_id' => $device->id, 'reason' => 'Boîtier déplacé au hall']);
    }

    public function test_reassignment_respects_the_target_screen(): void
    {
        $admin = $this->userWith('admin_tech', 'DSI');
        [$device] = $this->pairedDevice($this->screen('ECR-A'));
        $occupied = $this->screen('ECR-OCCUPE');
        [$other] = $this->pairedDevice($occupied);
        $archived = $this->screen('ECR-ARCH', true, ['status' => 'archived']);

        $this->actingAs($admin)->postJson("/api/v1/devices/{$device->id}/reassign", ['screen_id' => $archived->id, 'reason' => 'Essai'])->assertStatus(409)->assertJsonPath('code', 'SCREEN_NOT_IN_SERVICE');
        $this->actingAs($admin)->postJson("/api/v1/devices/{$device->id}/reassign", ['screen_id' => $occupied->id, 'reason' => 'Remplacement'])->assertStatus(409)->assertJsonPath('code', 'SCREEN_ALREADY_PAIRED');
        $this->actingAs($admin)->postJson("/api/v1/devices/{$device->id}/reassign", ['screen_id' => $occupied->id, 'reason' => 'Remplacement', 'replace' => true])->assertOk();
        $this->assertSame('revoked', $other->fresh()->status);
        $this->actingAs($this->userWith('diffuseur'))->postJson("/api/v1/devices/{$device->id}/reassign", ['screen_id' => $archived->id, 'reason' => 'Essai'])->assertForbidden();
    }

    public function test_conflicts_require_shared_days_and_overlapping_time_slots(): void
    {
        $this->assertFalse(CampaignService::slotsOverlap(null, '08:00', '12:00', null, '12:00', '18:00'), 'matin et après-midi');
        $this->assertFalse(CampaignService::slotsOverlap([1, 2], null, null, [6, 7], null, null), 'semaine et week-end');
        $this->assertTrue(CampaignService::slotsOverlap([1, 2, 3], '08:00', '12:00', [3], '11:00', '14:00'));
        $this->assertTrue(CampaignService::slotsOverlap(null, null, null, [5], '10:00', '11:00'), 'toute la semaine, toute la journée');

        $screen = $this->screen('ECR-CONFLIT');
        $author = $this->userWith('contributeur');
        $morning = Campaign::query()->create(['reference' => 'CMP-MATIN', 'name' => 'Matin', 'status' => 'active', 'priority' => 50, 'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(5),
            'time_from' => '08:00', 'time_to' => '12:00', 'created_by' => $author->id, 'published_at' => now()]);
        $morning->targets()->create(['target_type' => 'screen', 'target_id' => $screen->id]);
        $content = Content::query()->create(['reference' => 'CNT-C', 'title' => 'Avis', 'category_id' => $this->category()->id, 'owner_structure_id' => $author->structure_id, 'author_id' => $author->id, 'classification' => 'public', 'expires_at' => now()->addDays(10), 'status' => 'approved']);
        $diffuser = $this->userWith('diffuseur');
        $draft = fn (string $from, string $to) => $this->actingAs($diffuser)->postJson('/api/v1/campaigns/preview', [
            'targets' => [['type' => 'screen', 'id' => $screen->id]], 'items' => [['content_id' => $content->id]], 'priority' => 50,
            'starts_at' => now()->toIso8601String(), 'ends_at' => now()->addDays(2)->toIso8601String(), 'time_from' => $from, 'time_to' => $to,
        ])->assertOk()->json('data.screens.0.conflicts');

        $this->assertSame([], $draft('14:00', '18:00'), 'l’après-midi ne recouvre pas la campagne du matin');
        $this->assertSame('CMP-MATIN', $draft('11:00', '15:00')[0]['reference']);
    }
}
