<?php

namespace App\Jobs;

use App\Models\DeliveryAttempt;
use App\Models\UserNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Envoi SMTP avec reprises exponentielles et file des échecs (NOT-02). L'échec n'annule jamais l'opération métier.
 */
class DeliverNotificationEmail implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public string $notificationId)
    {
        $this->onQueue('notifications');
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    public function handle(): void
    {
        $notification = UserNotification::query()->with('user')->find($this->notificationId);
        $attempt = DeliveryAttempt::query()->where('notification_id', $this->notificationId)->where('channel', 'email')->first();
        if (! $notification || ! $attempt || $attempt->status === 'sent') {
            return;
        }
        $attempt->increment('attempts');
        $link = $notification->link ? rtrim((string) config('app.frontend_url'), '/').$notification->link : null;

        Mail::raw(
            $notification->title."\n\n".($notification->body ?? '')."\n\n".($link ? "Ouvrir : $link\n\n" : '')
            .'Ce message est généré par le SAIE-CEEAC. L’accès au lien reste soumis à vos droits.',
            function (Message $message) use ($notification): void {
                $message->to($notification->user->email, $notification->user->name)->subject('[SAIE-CEEAC] '.$notification->title);
            }
        );
        $attempt->forceFill(['status' => 'sent', 'sent_at' => now(), 'last_error' => null])->save();
    }

    public function failed(Throwable $exception): void
    {
        DeliveryAttempt::query()->where('notification_id', $this->notificationId)->where('channel', 'email')
            ->update(['status' => 'failed', 'last_error' => mb_substr($exception->getMessage(), 0, 500), 'updated_at' => now()]);
    }
}
