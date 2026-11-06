<?php

namespace Tests\Contract;

use Illuminate\Testing\TestResponse;

/**
 * Test de contrat (API-02, API-06) : chaque réponse de /api/v1 produite par la suite de tests doit
 * correspondre à une opération et à un code de réponse déclarés dans docs/api/openapi.json, et son corps
 * JSON doit respecter le schéma déclaré.
 *
 * Validateur JSON Schema volontairement minimal (sous-ensemble utilisé par le contrat) : type, required,
 * properties, items, enum, const, $ref, oneOf, anyOf, format uuid et date-time.
 * Avec SAIE_CONTRACT_RECORD=1, un exemple réel anonymisé par opération est enregistré dans docs/api/examples.json.
 */
class OpenApiContract
{
    /** @var array<string, mixed>|null */
    private static ?array $spec = null;

    /** @var array<string, array{status: int, request?: mixed, response?: mixed}> */
    private static array $examples = [];

    private static bool $shutdownRegistered = false;

    /**
     * @return list<string> violations du contrat
     */
    public static function check(string $method, mixed $requestBody, TestResponse $response): array
    {
        $route = $response->baseRequest?->route();
        if ($route === null || ! str_starts_with($route->uri(), 'api/v1/')) {
            return [];
        }
        $path = '/'.substr($route->uri(), strlen('api/v1/'));
        $verb = strtolower($method);
        $status = $response->getStatusCode();
        $where = strtoupper($verb)." $path → $status";
        $operation = self::spec()['paths'][$path][$verb] ?? null;
        if ($operation === null) {
            return ["$where : opération absente du contrat"];
        }
        $declared = $operation['responses'][(string) $status] ?? null;
        if ($declared === null) {
            return ["$where : code de réponse non déclaré (".implode(', ', array_keys($operation['responses'])).')'];
        }
        $type = (string) $response->headers->get('Content-Type');
        if (! str_contains($type, 'json')) {
            return [];
        }
        $schema = $declared['content']['application/json']['schema'] ?? null;
        if ($schema === null) {
            return $response->getContent() === '' ? [] : ["$where : corps JSON non déclaré"];
        }
        $body = json_decode((string) $response->getContent(), true);
        $errors = [];
        self::validate($body, $schema, '$', $errors);
        if ($errors === [] && getenv('SAIE_CONTRACT_RECORD')) {
            self::record("$verb $path", $status, $requestBody, $body);
        }

        return array_map(fn ($e) => "$where : $e", array_slice($errors, 0, 5));
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  list<string>  $errors
     */
    public static function validate(mixed $value, array $schema, string $at, array &$errors): void
    {
        if (isset($schema['$ref'])) {
            $name = substr($schema['$ref'], strlen('#/components/schemas/'));
            self::validate($value, self::spec()['components']['schemas'][$name] ?? [], $at, $errors);

            return;
        }
        foreach (['oneOf', 'anyOf'] as $keyword) {
            if (isset($schema[$keyword])) {
                $matches = 0;
                foreach ($schema[$keyword] as $candidate) {
                    $candidateErrors = [];
                    self::validate($value, $candidate, $at, $candidateErrors);
                    $matches += $candidateErrors === [] ? 1 : 0;
                }
                if ($matches === 0 || $keyword === 'oneOf' && $matches > 1) {
                    $errors[] = "$at : $matches variante(s) de $keyword satisfaite(s)";
                }

                return;
            }
        }
        if (array_key_exists('const', $schema) && $value !== $schema['const']) {
            $errors[] = "$at : valeur ".json_encode($value).' au lieu de '.json_encode($schema['const']);
        }
        if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            $errors[] = "$at : valeur ".json_encode($value, JSON_UNESCAPED_UNICODE).' hors énumération';
        }
        if (isset($schema['type'])) {
            $types = (array) $schema['type'];
            if (! collect($types)->contains(fn ($t) => self::isType($value, $t))) {
                $errors[] = "$at : type ".get_debug_type($value).' au lieu de '.implode('|', $types);

                return;
            }
        }
        if (is_string($value) && isset($schema['format'])) {
            $valid = match ($schema['format']) {
                'uuid' => (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value),
                'date-time' => (bool) preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:\d{2})$/', $value),
                default => true,
            };
            if (! $valid) {
                $errors[] = "$at : « $value » n'est pas au format {$schema['format']}";
            }
        }
        if (is_array($value) && ! array_is_list($value) || is_array($value) && $value === [] && isset($schema['properties'])) {
            foreach ($schema['required'] ?? [] as $property) {
                if (! array_key_exists($property, $value)) {
                    $errors[] = "$at : propriété requise « $property » absente";
                }
            }
            foreach ($schema['properties'] ?? [] as $property => $propertySchema) {
                if (array_key_exists($property, $value)) {
                    self::validate($value[$property], $propertySchema, "$at.$property", $errors);
                }
            }
        } elseif (is_array($value) && isset($schema['items'])) {
            foreach (array_slice($value, 0, 20) as $index => $item) {
                self::validate($item, $schema['items'], "{$at}[$index]", $errors);
            }
        }
    }

    private static function isType(mixed $value, string $type): bool
    {
        return match ($type) {
            'null' => $value === null,
            'string' => is_string($value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            // Un objet JSON vide est décodé en tableau vide : il satisfait object comme array.
            'array' => is_array($value) && array_is_list($value),
            'object' => is_array($value) && (! array_is_list($value) || $value === []),
            default => true,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public static function spec(): array
    {
        return self::$spec ??= json_decode((string) file_get_contents(dirname(__DIR__, 3).'/docs/api/openapi.json'), true);
    }

    private static function record(string $key, int $status, mixed $request, mixed $response): void
    {
        $existing = self::$examples[$key] ?? null;
        if ($existing !== null && ($existing['status'] < 300 || $status >= 300)) {
            return;
        }
        self::$examples[$key] = array_filter(['status' => $status, 'request' => self::anonymize($request), 'response' => self::anonymize($response)], fn ($v) => $v !== null && $v !== []);
        if (! self::$shutdownRegistered) {
            self::$shutdownRegistered = true;
            register_shutdown_function(function (): void {
                $file = dirname(__DIR__, 3).'/docs/api/examples.json';
                ksort(self::$examples);
                file_put_contents($file, json_encode(self::$examples, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR)."\n");
            });
        }
    }

    /**
     * Exemples publiables : secrets masqués, listes réduites à deux éléments, longues chaînes tronquées.
     */
    private static function anonymize(mixed $value, string $key = ''): mixed
    {
        if (preg_match('/token|secret|password|signature|otp|totp|recovery|private|payload|etag|p256dh|auth_key|endpoint/i', $key)) {
            if (is_string($value) || is_int($value)) {
                return '…';
            }
        }
        if (is_array($value)) {
            $value = array_is_list($value) ? array_slice($value, 0, 2) : $value;
            $result = [];
            foreach ($value as $k => $v) {
                $result[$k] = self::anonymize($v, is_string($k) ? $k : $key);
            }

            return $result;
        }
        if ($value instanceof \SplFileInfo) {
            return '(fichier binaire)';
        }
        if (is_object($value)) {
            return null;
        }
        if (is_string($value) && mb_strlen($value) > 160) {
            return mb_substr($value, 0, 157).'…';
        }

        return $value;
    }
}
