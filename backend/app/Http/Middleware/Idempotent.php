<?php

namespace App\Http\Middleware;

use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * En-tête Idempotency-Key lié à l'utilisateur et à l'empreinte du corps (API-03) : un rejeu renvoie la réponse initiale,
 * une réutilisation avec un autre corps renvoie 409.
 */
class Idempotent
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = (string) $request->headers->get('Idempotency-Key', '');
        if ($key === '' || ! $request->isMethod('POST')) {
            return $next($request);
        }
        if (! preg_match('/^[A-Za-z0-9_-]{8,100}$/', $key)) {
            return $this->error('IDEMPOTENCY_KEY_INVALID', 'Clé d’idempotence invalide.', 400, $request);
        }

        $actor = $request->user();
        $scope = mb_substr(($actor ? class_basename($actor).':'.$actor->getKey() : 'anon').'|'.$request->path(), 0, 60);
        $hash = hash('sha256', $request->getContent().'|'.json_encode($request->except([])));

        $inserted = IdempotencyKey::query()->insertOrIgnore([
            'id' => (string) Str::uuid(), 'scope' => $scope, 'key' => $key, 'request_hash' => $hash, 'created_at' => now(),
        ]);
        $record = IdempotencyKey::query()->where('scope', $scope)->where('key', $key)->first();
        if ($inserted === 0) {
            $existing = $record;
            if (! $existing || $existing->request_hash !== $hash) {
                return $this->error('IDEMPOTENCY_MISMATCH', 'Cette clé d’idempotence a déjà été utilisée avec une autre requête.', 409, $request);
            }
            if ($existing->response_status === null) {
                return $this->error('IDEMPOTENCY_IN_PROGRESS', 'La requête initiale est encore en cours de traitement.', 409, $request);
            }

            return response()->json($existing->response_body, $existing->response_status)->header('Idempotent-Replayed', 'true');
        }

        $response = $next($request);

        if ($response instanceof JsonResponse && $response->getStatusCode() < 500) {
            $record->forceFill(['response_status' => $response->getStatusCode(), 'response_body' => $response->getData(true)])->save();
        } else {
            $record->delete();
        }

        return $response;
    }

    private function error(string $code, string $message, int $status, Request $request): JsonResponse
    {
        return response()->json(['code' => $code, 'message' => $message, 'errors' => (object) [], 'request_id' => $request->attributes->get('request_id')], $status);
    }
}
