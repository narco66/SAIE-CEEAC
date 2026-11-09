<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * §10.22, §17.2 : flux temps réel des changements du parc (Server-Sent Events, sans dépendance). Le flux est borné dans
 * le temps (le navigateur se reconnecte seul et reprend au dernier identifiant reçu) et filtré selon le périmètre de
 * l'utilisateur ; il n'annonce que la nature du changement et l'écran, les données étant relues par l'API.
 */
class RealtimeController extends Controller
{
    public function stream(Request $request): StreamedResponse
    {
        $this->authorizePermission('supervision.view', 'screens.view');
        $scope = $this->screenScopeFor('supervision.view', 'screens.view');
        // Reprise au dernier identifiant reçu (reconnexion du navigateur), ou depuis `since` ; sinon, seulement la suite.
        $lastId = $request->hasHeader('Last-Event-ID') ? (int) $request->header('Last-Event-ID')
            : ($request->query->has('since') ? (int) $request->query('since') : (int) DB::table('realtime_events')->max('id'));
        $seconds = max(0, (int) config('saie.realtime.stream_seconds'));
        $pollMs = max(200, (int) config('saie.realtime.poll_ms'));
        // Session relâchée avant le flux : les autres requêtes de l’utilisateur ne l’attendent pas.
        if ($request->hasSession()) {
            $request->session()->save();
        }

        return response()->stream(function () use ($scope, $lastId, $seconds, $pollMs): void {
            $deadline = microtime(true) + $seconds;
            $cursor = $lastId;
            echo "retry: 2000\n\n";
            do {
                $events = DB::table('realtime_events')->where('id', '>', $cursor)->orderBy('id')->limit(200)->get();
                foreach ($events as $event) {
                    $cursor = (int) $event->id;
                    // Changement hors du périmètre : non annoncé (seul le curseur avance).
                    if ($scope !== null && $event->screen_id !== null && ! in_array($event->screen_id, $scope, true)) {
                        continue;
                    }
                    echo "id: {$event->id}\nevent: change\ndata: ".json_encode(['topic' => $event->topic, 'screen_id' => $event->screen_id, 'ref_id' => $event->ref_id])."\n\n";
                }
                echo ": keep-alive\n\n";
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
                if (connection_aborted() || microtime(true) >= $deadline) {
                    break;
                }
                usleep($pollMs * 1000);
            } while (true);
        }, 200, ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache, no-transform', 'X-Accel-Buffering' => 'no', 'Connection' => 'keep-alive']);
    }
}
