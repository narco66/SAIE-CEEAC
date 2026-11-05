<?php

namespace Tests\Feature;

use App\Jobs\DeliverPushNotification;
use App\Models\PushSubscription;
use App\Models\Setting;
use App\Models\UserNotification;
use App\Services\Notifier;
use Illuminate\Support\Facades\Queue;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\WebPush;
use Mockery;
use Tests\TestCase;

/**
 * NOT-03 : abonnement par l'utilisateur, seuls les événements éligibles sont poussés, sans titre confidentiel.
 */
class PushNotificationTest extends TestCase
{
    public function test_subscription_and_only_eligible_events_are_pushed(): void
    {
        config(['saie.push.public_key' => 'BPublicKeyTest', 'saie.push.private_key' => 'privateTest']);
        Setting::put('channels.push_enabled', true);
        $agent = $this->userWith('agent');
        $this->actingAs($agent)->postJson('/api/v1/me/push', ['endpoint' => 'https://push.example.org/abc', 'keys' => ['p256dh' => 'BKey', 'auth' => 'auth']])->assertOk()->assertJsonPath('data.subscriptions', 1);

        Queue::fake();
        app(Notifier::class)->notify($agent->refresh(), 'reservation.confirmed', 'Réservation confirmée', 'Réunion confidentielle', '/reservations/x', 'reservation', '00000000-0000-4000-8000-000000000001');
        app(Notifier::class)->notify($agent, 'document.published', 'Nouveau document', null, '/documents/y', 'document', '00000000-0000-4000-8000-000000000002');
        Queue::assertPushed(DeliverPushNotification::class, 1);

        $notification = UserNotification::query()->where('event', 'reservation.confirmed')->firstOrFail();
        $report = Mockery::mock(MessageSentReport::class);
        $report->allows(['isSubscriptionExpired' => false, 'isSuccess' => true]);
        $client = Mockery::mock(WebPush::class);
        $client->shouldReceive('sendOneNotification')->once()->withArgs(fn ($sub, $payload) => str_contains($payload, 'Réunion confidentielle') && ! str_contains($payload, 'Arbitrages'))->andReturn($report);
        (new DeliverPushNotification($notification->id))->handle($client);
        $this->assertDatabaseHas('delivery_attempts', ['notification_id' => $notification->id, 'channel' => 'push', 'status' => 'sent']);

        $this->actingAs($agent)->deleteJson('/api/v1/me/push')->assertOk();
        $this->assertSame(0, PushSubscription::query()->count());
    }

    public function test_push_is_refused_while_the_channel_is_disabled_by_administration(): void
    {
        config(['saie.push.public_key' => 'BPublicKeyTest', 'saie.push.private_key' => 'privateTest']);
        Setting::put('channels.push_enabled', false);
        $agent = $this->userWith('agent');

        $this->actingAs($agent)->getJson('/api/v1/me/push')->assertOk()->assertJsonPath('data.available', false)->assertJsonPath('data.public_key', null);
        $this->actingAs($agent)->postJson('/api/v1/me/push', ['endpoint' => 'https://push.example.org/abc', 'keys' => ['p256dh' => 'BKey', 'auth' => 'auth']])
            ->assertStatus(409)->assertJsonPath('code', 'PUSH_UNAVAILABLE');
        $this->assertSame(0, PushSubscription::query()->count());
    }
}
