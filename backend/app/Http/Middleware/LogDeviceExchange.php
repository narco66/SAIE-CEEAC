<?php

namespace App\Http\Middleware;

use App\Models\Device;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * §17.6 : chaque échange d'un lecteur avec le serveur (programme, battement, accusés, médias, commandes) est journalisé :
 * route, statut, durée et volume. Le contenu n'est pas conservé ; la durée de conservation est réglable.
 */
class LogDeviceExchange
{
    public function handle(Request $request, Closure $next): Response
    {
        $started = microtime(true);
        $response = $next($request);
        $device = $request->user();
        if ($device instanceof Device) {
            $content = $response->getContent();
            DB::table('device_exchanges')->insert([
                'device_id' => $device->id, 'endpoint' => mb_substr((string) ($request->route()?->uri() ?? $request->path()), 0, 60), 'method' => $request->method(),
                'status' => $response->getStatusCode(), 'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'bytes_out' => is_string($content) ? strlen($content) : (int) $response->headers->get('Content-Length', '0'), 'ip' => $request->ip(), 'occurred_at' => now(),
            ]);
        }

        return $response;
    }
}
