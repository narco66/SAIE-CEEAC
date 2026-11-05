<?php

namespace App\Jobs;

use App\Models\DeliveryAttempt;
use App\Models\PushSubscription;
use App\Models\UserNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Throwable;

/**
 * Envoi Web Push (NOT-03) vers chaque abonnement de l'utilisateur ; un abonnement expiré est supprimé.
 */
class DeliverPushNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public string $notificationId)
    {
        $this->onQueue('notifications');
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 300];
    }

    public function handle(?WebPush $client = null): void
    {
        $notification = UserNotification::query()->find($this->notificationId);
        $attempt = DeliveryAttempt::query()->where('notification_id', $this->notificationId)->where('channel', 'push')->first();
        if (! $notification || ! $attempt || $attempt->status === 'sent') {
            return;
        }
        $attempt->increment('attempts');
        $client ??= new WebPush(['VAPID' => ['subject' => config('saie.push.subject'), 'publicKey' => config('saie.push.public_key'), 'privateKey' => config('saie.push.private_key')]]);
        $payload = json_encode(['title' => $notification->title, 'body' => $notification->body, 'link' => $notification->link, 'id' => $notification->id], JSON_UNESCAPED_UNICODE);
        $sent = 0;
        foreach (PushSubscription::query()->where('user_id', $notification->user_id)->get() as $subscription) {
            $report = $client->sendOneNotification(Subscription::create(['endpoint' => $subscription->endpoint, 'keys' => $subscription->keys ?? []]), $payload, ['TTL' => 3600]);
            if ($report->isSubscriptionExpired()) {
                $subscription->delete();
            } elseif ($report->isSuccess()) {
                $sent++;
            }
        }
        $attempt->forceFill(['status' => $sent > 0 ? 'sent' : 'failed', 'sent_at' => $sent > 0 ? now() : null, 'last_error' => $sent > 0 ? null : 'Aucun abonnement joignable'])->save();
    }

    public function failed(Throwable $exception): void
    {
        DeliveryAttempt::query()->where('notification_id', $this->notificationId)->where('channel', 'push')->update(['status' => 'failed', 'last_error' => mb_substr($exception->getMessage(), 0, 500), 'updated_at' => now()]);
    }
}
