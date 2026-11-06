<?php

namespace Tests\Feature;

use App\Support\OpenApi\RuleSchema;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route as Router;
use Tests\Contract\OpenApiContract;
use Tests\TestCase;

/**
 * API-02, API-06 : contrat OpenAPI 3.1 livré, complet et vérifié.
 *
 * Toutes les réponses de la suite sont en outre validées contre le contrat (Tests\TestCase::call).
 */
class OpenApiContractTest extends TestCase
{
    /** Routes du tableau du chapitre 17 (annulation et non-présentation portent sur une occurrence). */
    private const CHAPTER_17 = [
        'get /me', 'get /screens', 'post /screens', 'get /rooms', 'post /rooms', 'get /screen-groups', 'post /screen-groups',
        'post /devices/pair', 'post /devices/{device}/revoke', 'post /devices/{device}/screenshot', 'post /assets', 'get /assets/{asset}/status',
        'get /templates', 'post /templates', 'get /contents', 'post /contents', 'post /contents/{content}/languages', 'post /contents/{content}/submit',
        'post /contents/{content}/approve', 'post /campaigns', 'post /campaigns/{campaign}/publish', 'post /campaigns/{campaign}/suspend',
        'post /alerts', 'post /alerts/{alert}/extend', 'post /alerts/{alert}/revoke', 'get /documents', 'get /documents/{document}',
        'get /documents/{document}/versions/{version}/download', 'get /q/{code}', 'get /rooms/{room}/availability', 'post /reservations',
        'post /reservations/{reservation}/approve', 'post /occurrences/{occurrence}/cancel', 'post /occurrences/{occurrence}/no-show',
        'get /device/manifest', 'post /device/heartbeat', 'post /device/receipts', 'post /imports/simulate', 'post /imports/commit',
        'get /exports', 'post /exports', 'get /notifications', 'post /notifications/{notification}/read', 'get /audit-logs',
    ];

    public function test_committed_contract_matches_the_routes_and_validation_rules(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'openapi');
        Artisan::call('saie:openapi', ['--output' => $file]);

        $this->assertJsonStringEqualsJsonFile($file, json_encode(OpenApiContract::spec()), 'docs/api/openapi.json est obsolète : php artisan saie:openapi');
        @unlink($file);
    }

    public function test_every_api_route_is_documented(): void
    {
        $spec = OpenApiContract::spec();
        $missing = collect(Router::getRoutes()->getRoutes())
            ->filter(fn (Route $route) => str_starts_with($route->uri(), 'api/v1/'))
            ->flatMap(fn (Route $route) => collect($route->methods())->reject(fn ($m) => in_array($m, ['HEAD', 'OPTIONS'], true))
                ->map(fn ($m) => strtolower($m).' /'.substr($route->uri(), strlen('api/v1/'))))
            ->reject(function (string $operation) use ($spec): bool {
                [$verb, $path] = explode(' ', $operation, 2);

                return isset($spec['paths'][$path][$verb]);
            });

        $this->assertSame([], $missing->values()->all());
    }

    public function test_chapter_17_routes_declare_permissions_errors_and_typed_payloads(): void
    {
        $spec = OpenApiContract::spec();
        foreach (self::CHAPTER_17 as $operation) {
            [$verb, $path] = explode(' ', $operation, 2);
            $definition = $spec['paths'][$path][$verb] ?? null;
            $this->assertNotNull($definition, "$operation absente du contrat");
            $this->assertArrayHasKey('x-permissions', $definition, $operation);
            $this->assertArrayHasKey('security', $definition, $operation);
            $this->assertArrayHasKey('422', $definition['responses'], $operation);
            $success = collect($definition['responses'])->filter(fn ($r, $code) => $code < 300)->first();
            $this->assertNotNull($success, "$operation sans réponse de succès");
            $schema = json_encode($success['content'] ?? [], JSON_UNESCAPED_SLASHES);
            if (! str_contains($schema, 'octet-stream') && $operation !== 'post /devices/{device}/screenshot' && ! str_starts_with($operation, 'post /notifications')) {
                $this->assertStringContainsString('#/components/schemas/', $schema, "$operation sans schéma de ressource");
            }
        }
        $this->assertSame(['urgence'], $spec['paths']['/alerts']['post']['x-power']);
    }

    public function test_write_operations_with_server_validation_publish_their_request_schema(): void
    {
        $reservation = OpenApiContract::spec()['paths']['/reservations']['post']['requestBody']['content']['application/json']['schema'];

        $this->assertEqualsCanonicalizing(['room_id', 'start_at', 'end_at', 'confidentiality', 'subject', 'participant_count'], $reservation['required']);
        $this->assertSame(['none', 'daily', 'weekly', 'monthly'], $reservation['properties']['recurrence']['properties']['freq']['enum']);
        $this->assertSame(['type' => 'string', 'format' => 'binary'], array_intersect_key(OpenApiContract::spec()['paths']['/assets']['post']['requestBody']['content']['multipart/form-data']['schema']['properties']['file'], ['type' => 1, 'format' => 1]));

        $room = OpenApiContract::spec()['paths'];
        $this->assertContains('capacity', $room['/rooms']['post']['requestBody']['content']['application/json']['schema']['required']);
        $this->assertArrayNotHasKey('required', $room['/rooms/{room}']['patch']['requestBody']['content']['application/json']['schema'], 'Une modification partielle n’exige aucun champ');
    }

    public function test_rule_translation_covers_types_bounds_lists_and_nullability(): void
    {
        $schema = RuleSchema::fromRules([
            'name' => ['required', 'string', 'max:120'], 'count' => ['nullable', 'integer', 'between:1,10'], 'mode' => ['in:a,b'],
            'items' => ['array', 'max:3'], 'items.*.id' => ['required', 'uuid'], 'when' => ['date'],
        ])['schema'];

        $this->assertSame(['name'], $schema['required']);
        $this->assertSame(['type' => 'string', 'maxLength' => 120], $schema['properties']['name']);
        $this->assertSame(['type' => ['integer', 'null'], 'minimum' => 1, 'maximum' => 10], $schema['properties']['count']);
        $this->assertSame(['a', 'b'], $schema['properties']['mode']['enum']);
        $this->assertSame(3, $schema['properties']['items']['maxItems']);
        $this->assertSame(['id'], $schema['properties']['items']['items']['required']);
        $this->assertSame('date-time', $schema['properties']['when']['format']);
    }

    public function test_contract_check_rejects_responses_outside_the_schema(): void
    {
        $errors = [];
        OpenApiContract::validate(['data' => ['id' => 'pas-un-uuid', 'code' => 12]], ['type' => 'object', 'required' => ['data'], 'properties' => ['data' => ['$ref' => '#/components/schemas/Room']]], '$', $errors);

        $this->assertContains("$.data.id : « pas-un-uuid » n'est pas au format uuid", $errors);
        $this->assertContains('$.data.code : type int au lieu de string', $errors);
        $this->assertContains('$.data : propriété requise « capacity » absente', $errors);
    }

    public function test_recorded_examples_conform_to_the_contract(): void
    {
        $examples = json_decode((string) file_get_contents(dirname(base_path()).'/docs/api/examples.json'), true);
        $spec = OpenApiContract::spec();
        $this->assertGreaterThan(90, count($examples));
        foreach ($examples as $operation => $example) {
            [$verb, $path] = explode(' ', $operation, 2);
            $schema = $spec['paths'][$path][$verb]['responses'][(string) $example['status']]['content']['application/json']['schema'] ?? null;
            if ($schema === null || ! isset($example['response'])) {
                continue;
            }
            $errors = [];
            OpenApiContract::validate($example['response'], $schema, $operation, $errors);
            $this->assertSame([], $errors);
        }
    }

    /**
     * API-04, SAL-03 : la réponse de création reflète les valeurs par défaut réellement enregistrées.
     */
    public function test_creation_responses_reflect_database_defaults(): void
    {
        $this->actingAs($this->userWith('admin_fonc'))->postJson('/api/v1/rooms', ['code' => 'S-DEF', 'name' => 'Salle', 'floor_id' => $this->place()['floor']->id, 'capacity' => 12])
            ->assertCreated()->assertJsonPath('data.approval_mode', 'auto')->assertJsonPath('data.min_minutes', 30);

        $this->actingAs($this->userWith('contributeur'))->postJson('/api/v1/contents', [
            'title' => 'Communiqué', 'category_id' => $this->category()->id, 'classification' => 'interne', 'expires_at' => now()->addDays(5)->toIso8601String(),
            'languages' => ['fr' => ['title' => 'Réunion', 'body' => 'Lundi.']],
        ])->assertCreated()->assertJsonPath('data.priority', 50)->assertJsonPath('data.is_permanent', false)->assertJsonPath('data.version', 1);
    }
}
