<?php

namespace App\Services;

use App\Jobs\DeliverNotificationEmail;
use App\Jobs\DeliverPushNotification;
use App\Models\DeliveryAttempt;
use App\Models\PushSubscription;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Notifications internes et courriels (NOT-01, NOT-02). Dédoublonnage par événement, destinataire et canal.
 * Une notification n'étend jamais une habilitation : la cible est revérifiée à l'ouverture.
 */
class Notifier
{
    /**
     * @param  iterable<User|null>|User|null  $recipients
     */
    public function notify(iterable|User|null $recipients, string $event, string $title, ?string $body = null, ?string $link = null, ?string $objectType = null, ?string $objectId = null, string $dedup = ''): void
    {
        $recipients = $recipients instanceof User ? [$recipients] : ($recipients ?? []);
        $seen = [];
        foreach ($recipients as $user) {
            if (! $user instanceof User || isset($seen[$user->id]) || ! $user->isActive()) {
                continue;
            }
            $seen[$user->id] = true;
            $key = mb_substr(implode('|', [$event, $user->id, $objectId ?? '-', $dedup]), 0, 160);
            $id = (string) Str::uuid();
            $inserted = DB::table('notifications')->insertOrIgnore([
                'id' => $id, 'user_id' => $user->id, 'event' => $event, 'title' => mb_substr($title, 0, 250), 'body' => $body ? mb_substr($body, 0, 250) : null,
                'link' => $link, 'object_type' => $objectType, 'object_id' => $objectId, 'dedup_key' => $key, 'created_at' => now(),
            ]);
            if ($inserted === 0) {
                continue;
            }
            if ($this->wantsEmail($user, $event)) {
                DeliveryAttempt::query()->create(['notification_id' => $id, 'channel' => 'email', 'status' => 'pending']);
                DB::afterCommit(fn () => DeliverNotificationEmail::dispatch($id));
            }
            if ($this->wantsPush($user, $event)) {
                DeliveryAttempt::query()->create(['notification_id' => $id, 'channel' => 'push', 'status' => 'pending']);
                DB::afterCommit(fn () => DeliverPushNotification::dispatch($id));
            }
        }
    }

    /**
     * Le push exige des clés VAPID configurées et l'activation du canal par l'administration (V39, « channels.push_enabled »).
     */
    public static function pushAvailable(): bool
    {
        return (bool) config('saie.push.public_key') && (bool) Setting::get('channels.push_enabled', false);
    }

    /**
     * Push navigateur (NOT-03) : canal activé par l'utilisateur, événement éligible, push disponible, abonnement existant.
     */
    private function wantsPush(User $user, string $event): bool
    {
        $preferences = $user->notification_preferences ?? [];

        return ($preferences['push'] ?? false) && in_array($event, config('saie.push.events'), true) && self::pushAvailable()
            && PushSubscription::query()->where('user_id', $user->id)->exists();
    }

    private function wantsEmail(User $user, string $event): bool
    {
        $preferences = $user->notification_preferences ?? [];

        return ($preferences['email'] ?? true) && ! in_array($event, $preferences['muted_events'] ?? [], true);
    }
}
