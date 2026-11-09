<?php

namespace App\Http\Middleware;

use App\Services\OperationalMetrics;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Identifiant de corrélation renvoyé dans chaque réponse et dans les erreurs (API-02).
 */
class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $incoming = (string) $request->headers->get('X-Request-Id', '');
        $id = preg_match('/^[A-Za-z0-9-]{8,40}$/', $incoming) ? $incoming : (string) Str::uuid();
        $request->attributes->set('request_id', $id);

        $started = microtime(true);
        $response = $next($request);
        $response->headers->set('X-Request-Id', $id);
        // ADM-02 : volume, erreurs serveur et durée des appels de l'API, agrégés par minute (jamais par utilisateur).
        if ($request->is('api/*')) {
            rescue(fn () => app(OperationalMetrics::class)->record($response->getStatusCode(), (microtime(true) - $started) * 1000), report: false);
        }

        return $response;
    }
}
