<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * §10.22 : publication des changements du parc pour le flux temps réel (SSE). Une publication ne doit jamais faire
 * échouer l'opération métier : en cas d'erreur, le client retombe sur son rafraîchissement périodique.
 */
class RealtimeBus
{
    /** @var list<string> */
    public const TOPICS = ['screen', 'device', 'incident', 'command'];

    public static function publish(string $topic, ?string $screenId, ?string $refId = null): void
    {
        if (! config('saie.realtime.enabled')) {
            return;
        }
        try {
            DB::table('realtime_events')->insert(['topic' => $topic, 'screen_id' => $screenId, 'ref_id' => $refId !== null ? mb_substr($refId, 0, 64) : null, 'created_at' => now()]);
        } catch (Throwable) {
            // Le temps réel est un confort : l'écriture métier prime.
        }
    }

    public static function purge(): int
    {
        return DB::table('realtime_events')->where('created_at', '<', now()->subHour())->delete();
    }
}
