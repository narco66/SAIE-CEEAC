<?php

namespace App\Console\Commands;

use App\Services\Referentials;
use App\Support\OpenApi\ControllerInspector;
use App\Support\OpenApi\ResourceSchemas;
use App\Support\OpenApi\RuleSchema;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Support\Str;
use ReflectionMethod;
use Stringable;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

#[Signature('saie:openapi {--output=../docs/api/openapi.json} {--examples=../docs/api/examples.json}')]
#[Description('Génère le contrat OpenAPI 3.1 de /api/v1 à partir des routes, des règles de validation et des contrôles de permission (API-02, API-06)')]
class GenerateOpenApi extends Command
{
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

    private const ERROR_LABELS = [
        '400' => 'Requête invalide', '401' => 'Non authentifié', '403' => 'Interdit', '404' => 'Objet absent ou inaccessible',
        '409' => 'Conflit métier', '412' => 'Précondition échouée', '422' => 'Validation', '429' => 'Limitation de débit',
    ];

    public function handle(): int
    {
        $inspector = new ControllerInspector;
        $examples = $this->examples();
        $powers = collect(config('saie.powers'))->flatMap(fn ($p, $code) => collect($p['permissions'])->mapWithKeys(fn ($permission) => [$permission => $code]))->all();
        $paths = [];
        foreach (Router::getRoutes() as $route) {
            /** @var Route $route */
            if (! str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }
            $path = '/'.Str::after($route->uri(), 'api/v1/');
            $action = class_basename($route->getActionName());
            [$controller, $method] = array_pad(explode('@', $action), 2, null);
            $info = str_contains($route->getActionName(), '@')
                ? $inspector->inspect(...explode('@', $route->getActionName()))
                : ['rules' => [], 'success' => 200, 'shape' => 'object', 'errors' => []];
            $middleware = $route->gatherMiddleware();
            $isDevice = in_array('device', $middleware, true);
            $isAuth = in_array('auth:sanctum', $middleware, true);
            $isIntegration = str_starts_with($path, '/integration/');
            $permissions = $this->permissions($route);
            foreach ($route->methods() as $verb) {
                if (in_array($verb, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }
                $operationId = lcfirst(str_replace('Controller', '', (string) $controller)).ucfirst((string) $method);
                $operation = [
                    'operationId' => $operationId,
                    'tags' => [str_replace('Controller', '', (string) $controller)],
                    'summary' => self::SUMMARIES[$action] ?? Str::headline((string) $method),
                    'x-permissions' => $permissions,
                    'x-power' => array_values(array_unique(array_filter(array_map(fn ($p) => $powers[$p] ?? null, $permissions)))) ?: null,
                    'x-error-codes' => $info['errors'] ?: null,
                    'parameters' => collect($route->parameterNames())->map(fn ($p) => array_filter(['name' => $p, 'in' => 'path', 'required' => true, 'schema' => array_filter(['type' => 'string', 'format' => in_array($p, ['code', 'type', 'session', 'id', 'version'], true) ? null : 'uuid'])]))->values()->all(),
                    'responses' => $this->responses($verb, $info, $operationId),
                ];
                $operation['security'] = match (true) {
                    $isDevice => [['deviceToken' => ['device']]],
                    $isIntegration => [['serviceToken' => []]],
                    $isAuth => [['sessionCookie' => []]],
                    default => [],
                };
                if ($verb === 'POST' && $isAuth && ! $isDevice && ! $isIntegration) {
                    $operation['parameters'][] = ['name' => 'Idempotency-Key', 'in' => 'header', 'required' => false, 'schema' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9_-]{8,100}$'], 'description' => 'Rejoue la réponse initiale ; une réutilisation avec un autre corps renvoie 409.'];
                }
                if (in_array($verb, ['PATCH', 'PUT'], true)) {
                    $operation['parameters'][] = ['name' => 'If-Match', 'in' => 'header', 'required' => false, 'schema' => ['type' => 'string'], 'description' => 'Version attendue (ou champ expected_version) ; une révision obsolète renvoie 409 VERSION_CONFLICT.'];
                }
                $this->describeInput($operation, $verb, $info['rules']);
                if (in_array($operationId, ['referentialStore', 'referentialUpdate'], true)) {
                    $this->describeReferential($operation, $verb === 'POST');
                }
                $example = $examples[strtolower($verb).' '.$path] ?? null;
                if ($example !== null) {
                    $this->attachExample($operation, $example);
                }
                $operation['parameters'] = array_values($operation['parameters']) ?: null;
                $paths[$path][strtolower($verb)] = array_filter($operation, fn ($v) => $v !== null);
            }
        }
        ksort($paths);
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'API SAIE-CEEAC', 'version' => '1.1.0', 'description' => 'Contrat de l’API /api/v1 (chapitre 17 du cahier des charges). Corps de requête issus des règles de validation du serveur ; permissions dans x-permissions, pouvoir étendu (HAB-03) dans x-power, codes d’erreur métier propres à l’action dans x-error-codes. Une route P1 est documentée mais peut être désactivée par configuration. Instants ISO 8601 en UTC ; affichage Africa/Libreville. Contrat vérifié en CI : chaque réponse JSON produite par la suite de tests est validée contre ce document.'],
            'servers' => [['url' => '/api/v1']],
            'components' => [
                'securitySchemes' => [
                    'sessionCookie' => ['type' => 'apiKey', 'in' => 'cookie', 'name' => 'saie-ceeac-session', 'description' => 'Session Sanctum SPA (HttpOnly, SameSite) + en-tête X-XSRF-TOKEN'],
                    'deviceToken' => ['type' => 'http', 'scheme' => 'bearer', 'description' => 'Jeton individuel et révocable du lecteur (portée device)'],
                    'serviceToken' => ['type' => 'http', 'scheme' => 'bearer', 'description' => 'Jeton d’un compte de service d’intégration (API-07), portées minimales'],
                ],
                'schemas' => ResourceSchemas::components() + [
                    'Error' => ['type' => 'object', 'required' => ['code', 'message', 'errors', 'request_id'], 'properties' => ['code' => ['type' => 'string', 'examples' => ['BOOKING_CONFLICT']], 'message' => ['type' => 'string'], 'errors' => ['type' => 'object', 'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']]], 'details' => ['type' => 'object'], 'request_id' => ['type' => ['string', 'null']]]],
                    'PageMeta' => ['type' => 'object', 'required' => ['current_page', 'per_page', 'total', 'last_page'], 'properties' => ['current_page' => ['type' => 'integer'], 'per_page' => ['type' => 'integer'], 'total' => ['type' => 'integer'], 'last_page' => ['type' => 'integer']]],
                    'ManifestEnvelope' => ['type' => 'object', 'required' => ['format', 'version', 'etag', 'payload', 'signature'], 'properties' => ['format' => ['const' => 'saie-manifest-envelope/1'], 'version' => ['type' => 'integer'], 'etag' => ['type' => 'string'], 'payload' => ['type' => 'string', 'description' => 'JSON exact signé (annexe A)'], 'signature' => ['type' => 'object', 'required' => ['alg', 'key_id', 'value'], 'properties' => ['alg' => ['const' => 'Ed25519'], 'key_id' => ['type' => 'string'], 'value' => ['type' => 'string', 'contentEncoding' => 'base64']]]]],
                ],
            ],
            'paths' => $paths,
        ];
        ksort($spec['components']['schemas']);
        $output = $this->path((string) $this->option('output'));
        @mkdir(dirname($output), 0775, true);
        file_put_contents($output, json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
        $operations = collect($paths)->flatten(1)->count();
        $this->info(count($paths)." chemins, $operations opérations écrites dans ".$this->option('output'));

        return self::SUCCESS;
    }

    /**
     * Corps de requête (POST, PATCH, PUT) ou paramètres de requête (GET, DELETE) tirés des règles de validation.
     *
     * @param  array<string, mixed>  $operation
     * @param  array<string, list<string>>  $rules
     */
    private function describeInput(array &$operation, string $verb, array $rules): void
    {
        if ($rules === []) {
            return;
        }
        ['schema' => $schema, 'multipart' => $multipart] = RuleSchema::fromRules($rules, $verb === 'POST');
        if (in_array($verb, ['POST', 'PATCH', 'PUT'], true)) {
            $operation['requestBody'] = ['required' => isset($schema['required']), 'content' => [$multipart ? 'multipart/form-data' : 'application/json' => ['schema' => $schema]]];

            return;
        }
        foreach ($schema['properties'] ?? [] as $name => $property) {
            $operation['parameters'][] = array_filter(['name' => $name, 'in' => 'query', 'required' => in_array($name, $schema['required'] ?? [], true) ?: null, 'schema' => $property]);
        }
    }

    /**
     * Référentiels (REF-01) : un corps par type, évalué depuis les règles réelles du service Referentials.
     *
     * @param  array<string, mixed>  $operation
     */
    private function describeReferential(array &$operation, bool $creating): void
    {
        $variants = [];
        foreach (Referentials::definitions() as $type => $definition) {
            $rules = ($definition['rules'])($creating ? null : new $definition['model']);
            $tokens = collect($rules)->map(fn ($rule) => collect(is_string($rule) ? explode('|', $rule) : $rule)
                ->filter(fn ($token) => is_string($token) || $token instanceof Stringable)->map(fn ($token) => (string) $token)->values()->all())->all();
            $variants[] = ['title' => $type] + RuleSchema::fromRules($tokens)['schema'];
        }
        $operation['requestBody'] = ['required' => true, 'content' => ['application/json' => ['schema' => ['oneOf' => $variants], 'description' => 'Le schéma applicable est celui du type de référentiel indiqué dans le chemin (title).']]];
        foreach ($operation['parameters'] as &$parameter) {
            if ($parameter['name'] === 'type') {
                $parameter['schema']['enum'] = array_keys(Referentials::definitions());
            }
        }
    }

    /**
     * @param  array<string, mixed>  $operation
     * @param  array{status: int, request?: mixed, response?: mixed}  $example
     */
    private function attachExample(array &$operation, array $example): void
    {
        if (isset($example['request'], $operation['requestBody'])) {
            $type = array_key_first($operation['requestBody']['content']);
            $operation['requestBody']['content'][$type]['example'] = $example['request'];
        }
        $status = (string) $example['status'];
        if (isset($example['response'], $operation['responses'][$status]['content']['application/json'])) {
            $operation['responses'][$status]['content']['application/json']['example'] = $example['response'];
        }
    }

    /**
     * Exemples réels anonymisés, enregistrés par la suite de tests (SAIE_CONTRACT_RECORD=1).
     *
     * @return array<string, array{status: int, request?: mixed, response?: mixed}>
     */
    private function examples(): array
    {
        $file = $this->path((string) $this->option('examples'));

        return is_file($file) ? (array) json_decode((string) file_get_contents($file), true) : [];
    }

    private function path(string $option): string
    {
        return preg_match('#^([A-Za-z]:)?[/\\\\]#', $option) ? $option : base_path($option);
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
     * @param  array{success: int, statuses?: list<int>, shape: string}  $info
     * @return array<string, mixed>
     */
    private function responses(string $verb, array $info, string $operationId): array
    {
        $error = ['content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]];
        $resource = ResourceSchemas::forOperation($operationId);
        $success = ['description' => 'Succès'];
        $success += match (ResourceSchemas::isUnwrapped($operationId) ? 'unwrapped' : $info['shape']) {
            'unwrapped' => ['content' => ['application/json' => ['schema' => $resource]]],
            'empty' => [],
            'binary' => ['content' => ['application/octet-stream' => ['schema' => ['type' => 'string', 'format' => 'binary']]]],
            'page' => ['content' => ['application/json' => ['schema' => ['type' => 'object', 'required' => ['data', 'meta'], 'properties' => ['data' => ['type' => 'array', 'items' => $resource ?? ['type' => 'object']], 'meta' => ['$ref' => '#/components/schemas/PageMeta']]]]]],
            'data' => ['content' => ['application/json' => ['schema' => ['type' => 'object', 'required' => ['data'], 'properties' => ['data' => $resource === null ? ['type' => ['object', 'array', 'null']] : (ResourceSchemas::isList($operationId) ? ['type' => 'array', 'items' => $resource] : $resource)]]]]],
            default => ['content' => ['application/json' => ['schema' => $resource ?? ['type' => 'object']]]],
        };
        $responses = [(string) $info['success'] => $success];
        foreach (self::ERROR_LABELS as $code => $label) {
            // Clés numériques : PHP les convertit en entiers.
            if ((int) $code === 412 && ! in_array($verb, ['PATCH', 'PUT'], true)) {
                continue;
            }
            $responses[(string) $code] = ['description' => $label] + $error;
        }
        // Codes propres à l'action, relevés dans son code (création, 304 sur ETag, 410 code expiré, 428 version manquante…).
        foreach ($info['statuses'] ?? [] as $status) {
            $responses[(string) $status] ??= match (true) {
                $status === 304 => ['description' => 'Non modifié (If-None-Match)'],
                $status < 300 => $success,
                default => ['description' => SymfonyResponse::$statusTexts[$status] ?? 'Erreur'] + $error,
            };
        }
        ksort($responses);

        return $responses;
    }
}
