<?php

namespace App\Support\OpenApi;

/**
 * Traduit des règles de validation Laravel (notation pointée) en schéma JSON Schema 2020-12 (API-02, API-06).
 *
 * Seules les règles statiquement connues sont traduites ; une règle dynamique (objet Rule, closure, variable)
 * est ignorée, ce qui rend le schéma permissif plutôt que faux.
 */
class RuleSchema
{
    /**
     * @param  array<string, list<string>>  $rules
     * @param  bool  $creating  une règle « obligatoire à la création » s'applique (POST)
     * @return array{schema: array<string, mixed>, multipart: bool}
     */
    public static function fromRules(array $rules, bool $creating = false): array
    {
        $root = ['type' => 'object', 'properties' => []];
        $multipart = false;
        ksort($rules);
        foreach ($rules as $key => $tokens) {
            $segments = explode('.', $key);
            $field = self::field($tokens);
            $multipart = $multipart || ($field['format'] ?? null) === 'binary' || (($field['items']['format'] ?? null) === 'binary');
            $required = in_array('required', $tokens, true) || in_array('accepted', $tokens, true) || $creating && in_array('required-on-create', $tokens, true);
            self::place($root, $segments, $field, $required);
        }

        return ['schema' => self::finish($root), 'multipart' => $multipart];
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  list<string>  $segments
     * @param  array<string, mixed>  $field
     */
    private static function place(array &$node, array $segments, array $field, bool $required): void
    {
        $segment = array_shift($segments);
        if ($segment === '*') {
            $node['type'] = self::withType($node['type'] ?? 'array', 'array');
            $node['items'] ??= [];
            if ($segments === []) {
                $node['items'] = self::merge($node['items'], $field);

                return;
            }
            $node['items']['type'] = $node['items']['type'] ?? 'object';
            $node['items']['properties'] ??= [];
            self::place($node['items'], $segments, $field, $required);

            return;
        }
        if (($node['type'] ?? null) !== null && ! in_array('object', (array) $node['type'], true)) {
            $node['type'] = self::withType($node['type'], 'object');
        }
        $node['properties'] ??= [];
        if ($segments === []) {
            $node['properties'][$segment] = self::merge($node['properties'][$segment] ?? [], $field);
            if ($required) {
                $node['required'][] = $segment;
            }

            return;
        }
        $child = $node['properties'][$segment] ?? [];
        if (! isset($child['type']) || $child['type'] === 'array' && ($segments[0] ?? null) !== '*') {
            $child['type'] = ($segments[0] ?? null) === '*' ? 'array' : 'object';
        }
        self::place($child, $segments, $field, $required);
        $node['properties'][$segment] = $child;
    }

    /**
     * @param  list<string>  $tokens
     * @return array<string, mixed>
     */
    private static function field(array $tokens): array
    {
        $schema = [];
        $type = null;
        $parameters = [];
        foreach ($tokens as $token) {
            [$name, $argument] = array_pad(explode(':', $token, 2), 2, null);
            $parameters[$name] = $argument;
            $type = match ($name) {
                'string', 'uuid', 'email', 'url', 'date', 'date_format', 'regex', 'ip', 'timezone', 'alpha_dash', 'alpha_num', 'json' => $type ?? 'string',
                'integer', 'digits_between' => 'integer',
                'numeric', 'decimal' => 'number',
                'boolean', 'accepted' => 'boolean',
                'array', 'list' => 'array',
                'file', 'image', 'mimes', 'mimetypes' => 'file',
                default => $type,
            };
        }
        if ($type === 'file') {
            $schema = ['type' => 'string', 'format' => 'binary'];
        } elseif ($type !== null) {
            $schema['type'] = $type;
        }
        if (array_key_exists('uuid', $parameters)) {
            $schema['format'] = 'uuid';
        }
        if (array_key_exists('email', $parameters)) {
            $schema['format'] = 'email';
        }
        if (array_key_exists('url', $parameters)) {
            $schema['format'] = 'uri';
        }
        if (array_key_exists('date', $parameters)) {
            $schema['format'] = 'date-time';
        }
        if (isset($parameters['date_format'])) {
            $schema['x-date-format'] = $parameters['date_format'];
            if ($parameters['date_format'] === 'Y-m-d') {
                $schema['format'] = 'date';
            }
        }
        if (isset($parameters['in'])) {
            $values = array_map(fn ($v) => trim($v, '"'), explode(',', $parameters['in']));
            $schema['enum'] = ($schema['type'] ?? null) === 'integer' ? array_map('intval', $values) : $values;
        }
        if (isset($parameters['regex'])) {
            $schema['pattern'] = self::pattern($parameters['regex']);
        }
        if (isset($parameters['size']) && ($schema['type'] ?? null) === 'string' && ! isset($schema['format'])) {
            $schema['minLength'] = $schema['maxLength'] = (int) $parameters['size'];
        }
        if (isset($parameters['digits'])) {
            $schema['type'] = 'string';
            $schema['pattern'] = '^[0-9]{'.(int) $parameters['digits'].'}$';
        }
        $bounds = ['min' => $parameters['min'] ?? null, 'max' => $parameters['max'] ?? null];
        if (isset($parameters['between'])) {
            [$bounds['min'], $bounds['max']] = array_pad(explode(',', $parameters['between']), 2, null);
        }
        foreach ($bounds as $bound => $value) {
            if ($value === null || ! is_numeric($value)) {
                continue;
            }
            $keyword = match ($schema['type'] ?? null) {
                'integer', 'number' => $bound === 'min' ? 'minimum' : 'maximum',
                'array' => $bound === 'min' ? 'minItems' : 'maxItems',
                'string' => ($schema['format'] ?? null) === 'binary' ? ($bound === 'max' ? 'x-max-kilobytes' : null) : ($bound === 'min' ? 'minLength' : 'maxLength'),
                default => null,
            };
            if ($keyword !== null) {
                $schema[$keyword] = str_contains($value, '.') ? (float) $value : (int) $value;
            }
        }
        if (isset($parameters['mimes'])) {
            $schema['x-mimes'] = explode(',', $parameters['mimes']);
        }
        if (array_key_exists('nullable', $parameters) && isset($schema['type'])) {
            $schema['type'] = [$schema['type'], 'null'];
        }

        return $schema;
    }

    private static function pattern(string $regex): string
    {
        $delimiter = $regex[0] ?? '/';
        $end = strrpos($regex, $delimiter);

        return $end > 0 ? substr($regex, 1, $end - 1) : $regex;
    }

    /**
     * @param  string|list<string>  $current
     * @return string|list<string>
     */
    private static function withType(string|array $current, string $wanted): string|array
    {
        $types = (array) $current;
        if (in_array($wanted, $types, true)) {
            return $current;
        }

        return in_array('null', $types, true) ? [$wanted, 'null'] : $wanted;
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private static function merge(array $base, array $extra): array
    {
        if (isset($base['type'], $extra['type']) && in_array((array) $extra['type'], [['array'], ['array', 'null'], ['object'], ['object', 'null']], true)) {
            $nullable = in_array('null', (array) $extra['type'], true);
            $kind = isset($base['properties']) ? 'object' : (isset($base['items']) ? 'array' : ((array) $extra['type'])[0]);
            $extra['type'] = $nullable ? [$kind, 'null'] : $kind;
        }

        return $extra + $base;
    }

    /**
     * Normalise : listes de propriétés requises uniques, objets vides retirés.
     *
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private static function finish(array $node): array
    {
        if (isset($node['required'])) {
            $node['required'] = array_values(array_unique($node['required']));
        }
        if (isset($node['properties'])) {
            if ($node['properties'] === []) {
                unset($node['properties']);
            } else {
                $node['properties'] = array_map(self::finish(...), $node['properties']);
            }
        }
        if (isset($node['items'])) {
            $node['items'] = self::finish($node['items']);
            if ($node['items'] === []) {
                unset($node['items']);
            }
        }
        if (in_array('object', (array) ($node['type'] ?? []), true) && isset($node['properties'])) {
            $node['type'] = in_array('null', (array) $node['type'], true) ? ['object', 'null'] : 'object';
        }

        return $node;
    }
}
