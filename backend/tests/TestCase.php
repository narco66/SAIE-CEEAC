<?php

namespace Tests;

use App\Models\Building;
use App\Models\ContentCategory;
use App\Models\Device;
use App\Models\DisplayZone;
use App\Models\Floor;
use App\Models\Location;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RoleExtension;
use App\Models\Room;
use App\Models\Screen;
use App\Models\Site;
use App\Models\Structure;
use App\Models\User;
use App\Services\Access;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Contract\OpenApiContract;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = ReferenceSeeder::class;

    /** @var array<string, mixed> */
    protected array $place = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->withHeader('Accept', 'application/json');
    }

    /**
     * Chaque réponse de l'API est confrontée au contrat OpenAPI livré (API-02, API-06).
     *
     * @param  array<string, mixed>  $parameters
     * @param  array<string, mixed>  $cookies
     * @param  array<string, mixed>  $files
     * @param  array<string, mixed>  $server
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null): TestResponse
    {
        $response = parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
        $body = is_string($content) && $content !== '' ? json_decode($content, true) : $parameters;
        $violations = OpenApiContract::check($method, $body, $response);
        if ($violations !== [] && ($report = getenv('SAIE_CONTRACT_REPORT'))) {
            file_put_contents($report, implode("\n", $violations)."\n", FILE_APPEND);
        } elseif ($violations !== []) {
            $this->fail("Réponse hors contrat OpenAPI (docs/api/openapi.json) :\n".implode("\n", $violations));
        }

        return $response;
    }

    protected function structure(string $code = 'COM'): Structure
    {
        return Structure::query()->firstOrCreate(['code' => $code], ['name' => "Structure $code", 'type' => 'service']);
    }

    /**
     * @param  list<string>|null  $languages
     */
    protected function userWith(string $role, ?string $structure = 'COM', ?array $languages = null, ?string $scope = null): User
    {
        $user = User::query()->create(['name' => 'Utilisateur '.Str::random(5), 'email' => Str::lower(Str::random(8)).'@test.local', 'password' => 'Secret-Passw0rd!', 'structure_id' => $structure ? $this->structure($structure)->id : null]);
        $this->grantRole($user, $role, $languages, $scope);

        return $user;
    }

    /**
     * @param  list<string>|null  $languages
     */
    protected function grantRole(User $user, string $role, ?array $languages = null, ?string $scope = null): void
    {
        RoleAssignment::query()->create(['user_id' => $user->id, 'role_id' => Role::query()->where('code', $role)->value('id'), 'languages' => $languages, 'structure_id' => $scope ? $this->structure($scope)->id : null, 'starts_at' => now()->subDay()]);
        app(Access::class)->flush();
    }

    protected function grantPower(User $user, string $power, ?array $scope = null, ?\DateTimeInterface $ends = null): RoleExtension
    {
        $extension = RoleExtension::query()->create(['user_id' => $user->id, 'power' => $power, 'scope' => $scope, 'starts_at' => now()->subDays(2), 'ends_at' => $ends ?? now()->addMonth()]);
        app(Access::class)->flush();

        return $extension;
    }

    protected function category(): ContentCategory
    {
        // Catégorie à un seul niveau de validation : les circuits à plusieurs niveaux sont testés à part (ApprovalCircuitTest).
        return ContentCategory::query()->where('code', 'ANNONCE')->firstOrFail();
    }

    /**
     * @return array{site: Site, building: Building, floor: Floor, zone_public: DisplayZone, zone_internal: DisplayZone, loc_public: Location, loc_internal: Location}
     */
    protected function place(): array
    {
        if ($this->place) {
            return $this->place;
        }
        $site = Site::query()->create(['code' => 'LBV', 'name' => 'Libreville']);
        $building = Building::query()->create(['site_id' => $site->id, 'code' => 'SIEGE', 'name' => 'Siège']);
        $floor = Floor::query()->create(['building_id' => $building->id, 'code' => 'RDC', 'label' => 'Rez-de-chaussée', 'level' => 0]);
        $public = DisplayZone::query()->create(['code' => 'Z-PUB', 'name' => 'Accueil', 'max_classification' => 'public', 'audio_allowed' => true, 'audio_max_volume' => 40]);
        $internal = DisplayZone::query()->create(['code' => 'Z-INT', 'name' => 'Interne', 'max_classification' => 'interne']);

        return $this->place = [
            'site' => $site, 'building' => $building, 'floor' => $floor, 'zone_public' => $public, 'zone_internal' => $internal,
            'loc_public' => Location::query()->create(['floor_id' => $floor->id, 'zone_id' => $public->id, 'code' => 'HALL', 'name' => 'Hall']),
            'loc_internal' => Location::query()->create(['floor_id' => $floor->id, 'zone_id' => $internal->id, 'code' => 'PALIER', 'name' => 'Palier']),
        ];
    }

    protected function screen(string $code, bool $public = true, array $extra = []): Screen
    {
        $place = $this->place();

        return Screen::query()->create(array_merge(['code' => $code, 'name' => $code, 'kind' => 'display', 'location_id' => ($public ? $place['loc_public'] : $place['loc_internal'])->id, 'orientation' => 'landscape', 'resolution' => '1920x1080', 'operating_window' => ['days' => [1, 2, 3, 4, 5, 6, 7], 'on' => '00:00', 'off' => '23:59']], $extra));
    }

    /**
     * @return array{0: Device, 1: string}
     */
    protected function pairedDevice(Screen $screen): array
    {
        $device = Device::query()->create(['code' => 'LCT-'.strtoupper(Str::random(6)), 'screen_id' => $screen->id, 'hardware_id' => 'hw-'.Str::random(6), 'status' => 'active', 'paired_at' => now(), 'last_seen_at' => now()]);

        return [$device, $device->createToken('device', ['device'])->plainTextToken];
    }

    protected function room(array $extra = []): Room
    {
        return Room::query()->create(array_merge(['code' => 'R'.Str::upper(Str::random(4)), 'name' => 'Salle test', 'floor_id' => $this->place()['floor']->id, 'capacity' => 20, 'approval_mode' => 'auto', 'prep_minutes' => 15, 'cleanup_minutes' => 15, 'open_from' => '00:00', 'open_to' => '23:59'], $extra));
    }
}
