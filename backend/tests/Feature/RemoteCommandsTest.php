<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Services\Access;
use Tests\TestCase;

/**
 * Commandes à distance (§11, RG-006, RG-007) : capacités déclarées, cycle d'états confirmé par le lecteur, expiration,
 * annulation, périmètre et habilitation.
 */
class RemoteCommandsTest extends TestCase
{
    private const ALL = ['sync', 'refresh', 'restart_player', 'stop_playback', 'resume_playback', 'screenshot', 'logs'];

    /**
     * @return array{0: Device, 1: string}
     */
    private function reportingDevice(array $capabilities = self::ALL, bool $public = true): array
    {
        [$device, $token] = $this->pairedDevice($this->screen('ECR-'.strtoupper(substr(uniqid(), -5)), $public));
        $this->postJson('/api/v1/device/heartbeat', ['device_time' => now()->toIso8601String(), 'capabilities' => $capabilities, 'playback_paused' => false], ['Authorization' => "Bearer $token"])->assertOk();
        $this->app['auth']->forgetGuards();

        return [$device->refresh(), $token];
    }

    private function heartbeat(string $token): array
    {
        $commands = $this->postJson('/api/v1/device/heartbeat', ['device_time' => now()->toIso8601String()], ['Authorization' => "Bearer $token"])->assertOk()->json('commands');
        $this->app['auth']->forgetGuards();

        return $commands;
    }

    public function test_a_command_goes_through_sent_received_and_executed_with_a_report(): void
    {
        $technician = $this->userWith('technicien', 'DSI');
        [$device, $token] = $this->reportingDevice();

        $id = $this->actingAs($technician)->postJson("/api/v1/devices/{$device->id}/commands", ['command' => 'logs'])->assertCreated()->assertJsonPath('data.status', 'pending')->json('data.id');
        $this->app['auth']->forgetGuards();
        $this->assertSame([['id' => $id, 'command' => 'logs']], array_map(fn ($c) => ['id' => $c['id'], 'command' => $c['command']], $this->heartbeat($token)));
        $this->assertSame('sent', DeviceCommand::query()->findOrFail($id)->status);

        $ack = fn (array $body) => $this->postJson("/api/v1/device/commands/$id/ack", $body, ['Authorization' => "Bearer $token"])->assertOk();
        $ack(['status' => 'received'])->assertJsonPath('data.status', 'received');
        $ack(['status' => 'executed', 'message' => 'Journaux transmis', 'data' => ['errors' => ['2026-11-08T07:00:00.000Z Vidéo illisible'], 'app_version' => '1.2.0']])->assertJsonPath('data.status', 'executed');
        $ack(['status' => 'received'])->assertJsonPath('data.status', 'executed');
        $this->app['auth']->forgetGuards();

        $this->actingAs($technician)->getJson("/api/v1/devices/{$device->id}/commands")->assertOk()
            ->assertJsonPath('data.0.status', 'executed')->assertJsonPath('data.0.result_message', 'Journaux transmis')
            ->assertJsonPath('data.0.result_data.errors.0', '2026-11-08T07:00:00.000Z Vidéo illisible')->assertJsonPath('data.0.result_data.app_version', '1.2.0')->assertJsonPath('data.0.requested_by.id', $technician->id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'device.command_executed', 'object_id' => $device->id]);
    }

    public function test_an_unsupported_command_is_refused_with_its_reason(): void
    {
        $technician = $this->userWith('technicien', 'DSI');
        [$device] = $this->reportingDevice(['sync']);

        $availability = collect($this->actingAs($technician)->getJson("/api/v1/devices/{$device->id}/commands")->assertOk()->json('meta.availability'))->keyBy('command');
        $this->assertTrue($availability['sync']['available']);
        $this->assertFalse($availability['standby']['available']);
        $this->assertStringContainsString('pilotage de la dalle', $availability['standby']['reason']);

        $this->actingAs($technician)->postJson("/api/v1/devices/{$device->id}/commands", ['command' => 'standby', 'reason' => 'Fermeture du bâtiment'])
            ->assertStatus(409)->assertJsonPath('code', 'COMMAND_UNAVAILABLE');
        $this->assertDatabaseHas('audit_logs', ['action' => 'device.command_refused', 'outcome' => 'denied']);
    }

    public function test_a_player_that_never_declared_capabilities_receives_no_command(): void
    {
        $technician = $this->userWith('technicien', 'DSI');
        [$device] = $this->pairedDevice($this->screen('ECR-ANCIEN'));

        $this->actingAs($technician)->postJson("/api/v1/devices/{$device->id}/commands", ['command' => 'sync'])->assertStatus(409)->assertJsonPath('code', 'COMMAND_UNAVAILABLE');
    }

    public function test_stopping_playback_requires_a_reason_and_is_reflected_once_confirmed(): void
    {
        $technician = $this->userWith('technicien', 'DSI');
        [$device, $token] = $this->reportingDevice();

        $this->actingAs($technician)->postJson("/api/v1/devices/{$device->id}/commands", ['command' => 'stop_playback'])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $id = $this->actingAs($technician)->postJson("/api/v1/devices/{$device->id}/commands", ['command' => 'stop_playback', 'reason' => 'Tournage dans le hall'])->assertCreated()->json('data.id');
        $this->assertFalse($device->refresh()->playback_paused, 'jamais présumée exécutée');
        $this->actingAs($technician)->postJson("/api/v1/devices/{$device->id}/commands", ['command' => 'stop_playback', 'reason' => 'Tournage dans le hall'])->assertStatus(409);
        $this->app['auth']->forgetGuards();

        $this->postJson("/api/v1/device/commands/$id/ack", ['status' => 'executed'], ['Authorization' => "Bearer $token"])->assertOk();
        $this->assertTrue($device->refresh()->playback_paused);
    }

    public function test_an_unconfirmed_command_expires_and_a_pending_one_can_be_cancelled(): void
    {
        $technician = $this->userWith('technicien', 'DSI');
        [$device, $token] = $this->reportingDevice();
        $sync = $this->actingAs($technician)->postJson("/api/v1/devices/{$device->id}/commands", ['command' => 'sync'])->assertCreated()->json('data.id');
        $refresh = $this->actingAs($technician)->postJson("/api/v1/devices/{$device->id}/commands", ['command' => 'refresh'])->assertCreated()->json('data.id');

        $this->actingAs($technician)->postJson("/api/v1/device-commands/$refresh/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        DeviceCommand::query()->whereKey($sync)->update(['expires_at' => now()->subMinute()]);
        $this->artisan('saie:tick')->assertSuccessful();

        $this->assertSame('expired', DeviceCommand::query()->findOrFail($sync)->status);
        $this->app['auth']->forgetGuards();
        $this->assertSame([], $this->heartbeat($token), 'ni la commande expirée ni l’annulée ne sont remises');
        $this->postJson("/api/v1/device/commands/$sync/ack", ['status' => 'executed'], ['Authorization' => "Bearer $token"])->assertOk()->assertJsonPath('data.status', 'expired');
    }

    public function test_commands_are_restricted_by_permission_scope_and_device(): void
    {
        [$outside] = $this->reportingDevice(self::ALL, false);
        [$other, $otherToken] = $this->reportingDevice();
        $zoneTechnician = User::query()->create(['name' => 'Technicien hall', 'email' => 'tech.hall@test.local', 'password' => 'Secret-Passw0rd!']);
        RoleAssignment::query()->create(['user_id' => $zoneTechnician->id, 'role_id' => Role::query()->where('code', 'technicien')->value('id'), 'zone_id' => $this->place()['zone_public']->id, 'starts_at' => now()->subDay()]);
        app(Access::class)->flush();

        $this->actingAs($this->userWith('diffuseur'))->postJson("/api/v1/devices/{$outside->id}/commands", ['command' => 'sync'])->assertForbidden();
        $this->actingAs($zoneTechnician)->postJson("/api/v1/devices/{$outside->id}/commands", ['command' => 'sync'])->assertForbidden();

        $id = $this->actingAs($this->userWith('technicien', 'DSI'))->postJson("/api/v1/devices/{$outside->id}/commands", ['command' => 'sync'])->assertCreated()->json('data.id');
        $this->app['auth']->forgetGuards();
        $this->postJson("/api/v1/device/commands/$id/ack", ['status' => 'executed'], ['Authorization' => "Bearer $otherToken"])->assertNotFound();
        $this->assertSame('pending', DeviceCommand::query()->findOrFail($id)->status);
    }

    public function test_a_screenshot_command_links_the_capture_request(): void
    {
        $technician = $this->userWith('technicien', 'DSI');
        [$device, $token] = $this->reportingDevice();

        $command = $this->actingAs($technician)->postJson("/api/v1/devices/{$device->id}/commands", ['command' => 'screenshot'])->assertCreated()->json('data');
        $this->assertNotNull($command['screenshot_id']);
        $this->app['auth']->forgetGuards();
        $response = $this->postJson('/api/v1/device/heartbeat', ['device_time' => now()->toIso8601String()], ['Authorization' => "Bearer $token"])->assertOk();
        $this->assertSame([], $response->json('screenshot_requests'), 'la capture passe par sa commande, pas par l’ancien canal');
        $this->assertSame($command['screenshot_id'], $response->json('commands.0.params.screenshot_id'));
    }
}
