<?php

namespace Tests\Feature;

use App\Models\DeliveryAttempt;
use App\Models\Room;
use App\Models\Setting;
use App\Services\Notifier;
use Tests\TestCase;

/**
 * V39 : chaque paramètre proposé produit un effet vérifiable ; ceux sans fonction réelle ne sont plus proposés,
 * et les bornes sont contrôlées par le serveur.
 */
class SettingsEffectTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function manifestFor(array $screenExtra = []): array
    {
        $screen = $this->screen('ECR-PARAM', true, $screenExtra);
        [, $token] = $this->pairedDevice($screen);
        $envelope = $this->getJson('/api/v1/device/manifest', ['Authorization' => "Bearer $token"])->assertOk()->json();

        return json_decode($envelope['payload'], true);
    }

    public function test_only_settings_with_a_real_effect_are_offered(): void
    {
        $admin = $this->userWith('admin_tech');
        $keys = collect($this->actingAs($admin)->getJson('/api/v1/settings')->assertOk()->json('data.settings'))->pluck('key')->sort()->values()->all();

        $this->assertSame([
            'channels.push_enabled', 'channels.smtp_enabled', 'display.default_slide_s', 'display.tagline', 'identity.institution_name',
            'integrations.api_enabled', 'operating.audio_max_volume', 'operating.default_window', 'rooms.no_show_auto', 'rooms.pending_hold_hours',
        ], $keys);
    }

    public function test_institution_name_default_window_and_audio_cap_reach_the_screens(): void
    {
        Setting::put('identity.institution_name', 'Commission de la CEEAC — Siège de Libreville');
        Setting::put('operating.default_window', ['days' => [1, 2, 3, 4, 5, 6], 'on' => '07:30', 'off' => '19:00']);
        Setting::put('operating.audio_max_volume', 25);

        $manifest = $this->manifestFor(['operating_window' => null, 'audio_enabled' => true]);

        $this->assertSame('Commission de la CEEAC — Siège de Libreville', $manifest['institution']['name']);
        $this->assertSame('07:30', $manifest['operating_window']['on']);
        $this->assertSame([1, 2, 3, 4, 5, 6], $manifest['operating_window']['days']);
        $this->assertSame(25, $manifest['audio_policy']['max_volume']);
    }

    public function test_new_rooms_inherit_the_room_defaults(): void
    {
        Setting::put('rooms.pending_hold_hours', 72);
        Setting::put('rooms.no_show_auto', true);
        $admin = $this->userWith('admin_fonc');

        $id = $this->actingAs($admin)->postJson('/api/v1/rooms', ['code' => 'S-PARAM', 'name' => 'Salle paramétrée', 'floor_id' => $this->place()['floor']->id, 'capacity' => 12])
            ->assertCreated()->json('data.id');

        $room = Room::query()->findOrFail($id);
        $this->assertSame(72, $room->pending_hold_hours);
        $this->assertTrue($room->auto_release_enabled);
    }

    public function test_campaign_slide_duration_defaults_to_the_setting(): void
    {
        Setting::put('display.default_slide_s', 20);
        $agent = $this->userWith('agent');

        $this->actingAs($agent)->getJson('/api/v1/referentials')->assertOk()->assertJsonPath('data.defaults.slide_duration_s', 20);
    }

    public function test_email_channel_switch_stops_new_emails_but_keeps_in_app_notifications(): void
    {
        $agent = $this->userWith('agent');
        Setting::put('channels.smtp_enabled', false);

        app(Notifier::class)->notify($agent, 'reservation.confirmed', 'Réservation confirmée');

        $this->assertDatabaseHas('notifications', ['user_id' => $agent->id, 'event' => 'reservation.confirmed']);
        $this->assertSame(0, DeliveryAttempt::query()->where('channel', 'email')->count());
    }

    public function test_server_enforces_setting_bounds_and_window_format(): void
    {
        $admin = $this->userWith('admin_tech');

        $this->actingAs($admin)->patchJson('/api/v1/settings', ['key' => 'operating.audio_max_volume', 'value' => 150, 'reason' => 'Essai de borne'])->assertUnprocessable();
        $this->actingAs($admin)->patchJson('/api/v1/settings', ['key' => 'rooms.pending_hold_hours', 'value' => 500, 'reason' => 'Essai de borne'])->assertUnprocessable();
        $this->actingAs($admin)->patchJson('/api/v1/settings', ['key' => 'operating.default_window', 'value' => ['days' => [9], 'on' => '25:00'], 'reason' => 'Essai de format'])->assertUnprocessable();
        $this->actingAs($admin)->patchJson('/api/v1/settings', ['key' => 'operating.audio_max_volume', 'value' => 30, 'reason' => 'Réglage de l’accueil'])->assertOk();
    }
}
