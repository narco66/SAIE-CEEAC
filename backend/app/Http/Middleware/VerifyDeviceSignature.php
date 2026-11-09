<?php

namespace App\Http\Middleware;

use App\Models\Device;
use App\Models\Setting;
use App\Support\BusinessException;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * §18.5 : requêtes signées des lecteurs avec anti-rejeu. Signature HMAC-SHA256 de « méthode, chemin, horodatage, nonce,
 * empreinte du corps » avec une clé dérivée du jeton (son empreinte SHA-256, connue du serveur sans que le jeton soit
 * conservé) ; horodatage à ±5 minutes, nonce à usage unique. Vérifiée et exigée seulement quand la DSI l'active.
 */
class VerifyDeviceSignature
{
    public const UNSIGNED_BODY = 'UNSIGNED-BODY';

    public function handle(Request $request, Closure $next): Response
    {
        $device = $request->user();
        $signature = (string) $request->header('X-SAIE-Signature', '');
        // Désactivé (par défaut) : aucune vérification, les lecteurs signent déjà mais rien n'est exigé ni refusé.
        if (! $device instanceof Device || ! Setting::effective('devices.signed_requests')) {
            return $next($request);
        }
        if ($signature === '') {
            throw new BusinessException('SIGNATURE_REQUIRED', 'Requête du lecteur non signée.', 401);
        }
        $timestamp = (string) $request->header('X-SAIE-Timestamp', '');
        $nonce = (string) $request->header('X-SAIE-Nonce', '');
        $bodyHash = (string) $request->header('X-SAIE-Body-Hash', '');
        if (! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > 300 || ! preg_match('/^[A-Za-z0-9-]{16,64}$/', $nonce)) {
            throw new BusinessException('SIGNATURE_INVALID', 'Horodatage ou nonce de la requête invalide.', 401);
        }
        $json = str_contains((string) $request->header('Content-Type'), 'json') || $request->getContent() === '';
        if ($bodyHash === self::UNSIGNED_BODY ? $json : ! hash_equals(hash('sha256', $request->getContent()), $bodyHash)) {
            throw new BusinessException('SIGNATURE_INVALID', 'Empreinte du corps de la requête invalide.', 401);
        }
        $key = (string) $device->currentAccessToken()?->getAttribute('token');
        $message = implode("\n", [$request->method(), '/'.ltrim($request->path(), '/'), $timestamp, $nonce, $bodyHash]);
        if ($key === '' || ! hash_equals(hash_hmac('sha256', $message, $key), $signature)) {
            throw new BusinessException('SIGNATURE_INVALID', 'Signature de la requête invalide.', 401);
        }
        if (! Cache::add("device-nonce:{$device->id}:$nonce", 1, 600)) {
            throw new BusinessException('REPLAYED_REQUEST', 'Requête déjà reçue (rejeu).', 401);
        }

        return $next($request);
    }
}
