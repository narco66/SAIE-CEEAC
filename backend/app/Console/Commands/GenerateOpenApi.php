<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Support\Str;
use ReflectionMethod;

#[Signature('saie:openapi {--output=../docs/api/openapi.json}')]
#[Description('Génère le contrat OpenAPI 3.1 de /api/v1 à partir des routes et des contrôles de permission (API-06)')]
class GenerateOpenApi extends Command
{
    /** Règles de validation reprises dans les schémas de requête des routes clés. */
    private const SUMMARIES = [
        'AuthController@login' => 'Connexion par identifiant et mot de passe (SEC-01)',
        'MeController@show' => 'Identité, permissions effectives, pouvoirs étendus et périmètres',
        'DeviceApiController@manifest' => 'Manifeste signé minimal autorisé (ETag, If-None-Match)',
        'DeviceApiController@heartbeat' => 'Télémétrie du lecteur et écart d’horloge',
        'DeviceApiController@receipts' => 'Reçus différés dédoublonnés (y compris réception d’alerte)',
        'ScreenController@pair' => 'Appairage par code à usage unique (PLY-02)',
        'ReservationController@store' => 'Création simple ou série, contrôle SQL et idempotence (API-03)',
        'ContentController@approve' => 'Décision par langue (approuver, retourner, réaffecter)',
        'CampaignController@publish' => 'Publication atomique sur versions approuvées',
        'AlertController@store' => 'Alerte sous pouvoir « urgence »',
        'QrController@show' => 'Résolution d’un QR code après contrôle de classification',
    ];

    public function handle(): int
    {
        $paths = [];
        foreach (Router::getRoutes() as $route) {
            /** @var Route $route */
            if (! str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }
            $path = '/'.Str::after($route->uri(), 'api/v1/');
            $action = class_basename($route->getActionName());
            [$controller, $method] = array_pad(explode('@', $action), 2, null);
            $middleware = $route->gatherMiddleware();
            $isDevice = in_array('device', $middleware, true);
            $isAuth = in_array('auth:sanctum', $middleware, true);
            $permissions = $this->permissions($route);
            foreach ($route->methods() as $verb) {
                if (in_array($verb, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }
                $operation = [
                    'operationId' => lcfirst(str_replace('Controller', '', (string) $controller)).ucfirst((string) $method),
                    'tags' => [str_replace('Controller', '', (string) $controller)],
                    'summary' => self::SUMMARIES[$action] ?? Str::headline((string) $method),
                    'x-permissions' => $permissions,
                    'parameters' => collect($route->parameterNames())->map(fn ($p) => ['name' => $p, 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'format' => in_array($p, ['code', 'type', 'session', 'id', 'version'], true) ? null : 'uuid']])->map(fn ($p) => array_filter($p, fn ($v) => $v !== null))->values()->all(),
                    'responses' => $this->responses($verb, $isAuth),
                ];
                if ($isDevice) {
                    $operation['security'] = [['deviceToken' => ['device']]];
                } elseif ($isAuth) {
                    $operation['security'] = [['sessionCookie' => []]];
                } else {
                    $operation['security'] = [];
                }
                if ($verb === 'POST' && $isAuth && ! $isDevice) {
                    $operation['parameters'][] = ['name' => 'Idempotency-Key', 'in' => 'header', 'required' => false, 'schema' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9_-]{8,100}$'], 'description' => 'Rejoue la réponse initiale ; une réutilisation avec un autre corps renvoie 409.'];
                }
                if (in_array($verb, ['PATCH', 'PUT'], true)) {
                    $operation['parameters'][] = ['name' => 'If-Match', 'in' => 'header', 'required' => false, 'schema' => ['type' => 'string'], 'description' => 'Version attendue ; une révision obsolète renvoie 409 VERSION_CONFLICT.'];
                }
                if (in_array($verb, ['POST', 'PATCH', 'PUT'], true)) {
                    $operation['requestBody'] = ['required' => false, 'content' => ['application/json' => ['schema' => ['type' => 'object']], 'multipart/form-data' => ['schema' => ['type' => 'object']]]];
                }
                $operation['parameters'] = array_values($operation['parameters']) ?: null;
                $paths[$path][strtolower($verb)] = array_filter($operation, fn ($v) => $v !== null);
            }
        }
        ksort($paths);
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'API SAIE-CEEAC', 'version' => '1.0.0', 'description' => 'Contrat de l’API /api/v1 (chapitre 17 du cahier des charges). Les permissions requises figurent dans x-permissions ; une route P1 est documentée mais peut être désactivée par configuration. Instants ISO 8601 en UTC ; affichage Africa/Libreville.'],
            'servers' => [['url' => '/api/v1']],
            'components' => [
                'securitySchemes' => [
                    'sessionCookie' => ['type' => 'apiKey', 'in' => 'cookie', 'name' => 'saie-ceeac-session', 'description' => 'Session Sanctum SPA (HttpOnly, SameSite) + en-tête X-XSRF-TOKEN'],
                    'deviceToken' => ['type' => 'http', 'scheme' => 'bearer', 'description' => 'Jeton individuel et révocable du lecteur (portée device)'],
                ],
                'schemas' => [
                    'Error' => ['type' => 'object', 'required' => ['code', 'message'], 'properties' => ['code' => ['type' => 'string', 'examples' => ['BOOKING_CONFLICT']], 'message' => ['type' => 'string'], 'errors' => ['type' => 'object', 'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']]], 'details' => ['type' => 'object'], 'request_id' => ['type' => 'string']]],
                    'PageMeta' => ['type' => 'object', 'properties' => ['current_page' => ['type' => 'integer'], 'per_page' => ['type' => 'integer'], 'total' => ['type' => 'integer'], 'last_page' => ['type' => 'integer']]],
                    'ReservationRequest' => ['type' => 'object', 'required' => ['room_id', 'start_at', 'end_at', 'confidentiality', 'subject', 'participant_count'], 'properties' => [
                        'room_id' => ['type' => 'string', 'format' => 'uuid'], 'start_at' => ['type' => 'string', 'format' => 'date-time'], 'end_at' => ['type' => 'string', 'format' => 'date-time'],
                        'confidentiality' => ['type' => 'boolean'], 'subject' => ['type' => 'string', 'maxLength' => 200], 'participant_count' => ['type' => 'integer', 'minimum' => 1],
                        'recurrence' => ['type' => 'object', 'properties' => ['freq' => ['enum' => ['none', 'daily', 'weekly', 'monthly']], 'interval' => ['type' => 'integer'], 'until' => ['type' => 'string', 'format' => 'date'], 'count' => ['type' => 'integer', 'maximum' => 100], 'byweekday' => ['type' => 'array', 'items' => ['type' => 'integer']]]],
                        'recurrence_mode' => ['enum' => ['all_or_nothing', 'partial']], 'resource_ids' => ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'uuid']],
                    ]],
                    'ManifestEnvelope' => ['type' => 'object', 'required' => ['format', 'version', 'etag', 'payload', 'signature'], 'properties' => ['format' => ['const' => 'saie-manifest-envelope/1'], 'version' => ['type' => 'integer'], 'etag' => ['type' => 'string'], 'payload' => ['type' => 'string', 'description' => 'JSON exact signé (annexe A)'], 'signature' => ['type' => 'object', 'properties' => ['alg' => ['const' => 'Ed25519'], 'key_id' => ['type' => 'string'], 'value' => ['type' => 'string', 'contentEncoding' => 'base64']]]]],
                ],
            ],
            'paths' => $paths,
        ];
        $spec['paths']['/reservations']['post']['requestBody'] = ['required' => true, 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ReservationRequest'], 'example' => ['room_id' => '0b6f0000-0000-4000-8000-000000000001', 'start_at' => '2026-11-16T12:00:00Z', 'end_at' => '2026-11-16T14:00:00Z', 'confidentiality' => false, 'subject' => 'Atelier de coordination régionale', 'participant_count' => 18]]]];
        $spec['paths']['/device/manifest']['get']['responses']['200']['content'] = ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ManifestEnvelope']]];
        $output = base_path((string) $this->option('output'));
        @mkdir(dirname($output), 0775, true);
        file_put_contents($output, json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
        $this->info(count($paths).' chemins écrits dans '.$this->option('output'));

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function permissions(Route $route): array
    {
        $action = $route->getActionName();
        if (! str_contains($action, '@')) {
            return [];
        }
        [$class, $method] = explode('@', $action);
        try {
            $reflection = new ReflectionMethod($class, $method);
        } catch (\ReflectionException) {
            return [];
        }
        $lines = array_slice(file($reflection->getFileName()), $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1);
        preg_match_all("/authorizePermission\(([^)]*)\)/", implode('', $lines), $matches);

        return collect($matches[1])->flatMap(fn ($args) => array_map(fn ($a) => trim($a, " '\""), explode(',', $args)))->filter()->unique()->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function responses(string $verb, bool $auth): array
    {
        $error = ['description' => '', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]];
        $responses = [$verb === 'POST' ? '201' : ($verb === 'DELETE' ? '204' : '200') => ['description' => 'Succès']];
        foreach (['400' => 'Requête invalide', '401' => 'Non authentifié', '403' => 'Interdit', '404' => 'Objet absent ou inaccessible', '409' => 'Conflit métier', '422' => 'Validation', '429' => 'Limitation de débit'] as $code => $label) {
            if (! $auth && in_array($code, ['401', '403'], true)) {
                continue;
            }
            $responses[$code] = ['description' => $label] + $error;
        }

        return $responses;
    }
}
