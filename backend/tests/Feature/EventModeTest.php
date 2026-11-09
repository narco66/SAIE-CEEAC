<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Content;
use App\Models\ContentVersion;
use App\Models\User;
use App\Services\ManifestSigner;
use App\Services\ScheduleResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PUB-12 : mode événement. Une campagne « event » remplace le programme ordinaire des écrans ciblés pendant sa période,
 * sa durée est bornée à 31 jours et le programme ordinaire reprend seul à son expiration.
 */
class EventModeTest extends TestCase
{
    private User $author;

    protected function setUp(): void
    {
        parent::setUp();
        $this->author = $this->userWith('contributeur');
    }

    private function approvedContent(string $title): Content
    {
        $validator = $this->userWith('validateur', 'COM', ['fr']);
        $content = Content::query()->create(['reference' => 'CNT-'.uniqid(), 'title' => $title, 'category_id' => $this->category()->id, 'owner_structure_id' => $this->author->structure_id, 'author_id' => $this->author->id, 'classification' => 'public', 'expires_at' => now()->addDays(10), 'status' => 'approved']);
        ContentVersion::query()->create(['content_id' => $content->id, 'lang' => 'fr', 'revision' => 1, 'payload' => ['title' => $title, 'media' => []], 'classification' => 'public', 'author_id' => $this->author->id, 'approved_by' => $validator->id, 'approved_at' => now(), 'payload_hash' => str_repeat('b', 64)]);

        return $content;
    }

    private function activeCampaign(string $kind, int $priority, Content $content): Campaign
    {
        $campaign = Campaign::query()->create(['reference' => 'CMP-'.uniqid(), 'name' => $kind, 'kind' => $kind, 'status' => 'active', 'priority' => $priority, 'starts_at' => now()->subHour(), 'ends_at' => now()->addDays(2), 'created_by' => $this->author->id, 'published_at' => now()]);
        $version = ContentVersion::query()->where('content_id', $content->id)->firstOrFail();
        $campaign->items()->create(['content_id' => $content->id, 'position' => 1, 'version_map' => ['fr' => $version->id]]);
        DB::table('campaign_languages')->insert(['campaign_id' => $campaign->id, 'lang' => 'fr', 'rank' => 1]);
        $campaign->targets()->create(['target_type' => 'floor', 'target_id' => $this->place()['floor']->id]);

        return $campaign;
    }

    public function test_event_longer_than_31_days_is_refused_by_the_api(): void
    {
        $diffuser = $this->userWith('diffuseur');
        $content = $this->approvedContent('Sommet');
        $start = CarbonImmutable::now()->addDay();

        $this->actingAs($diffuser)->postJson('/api/v1/campaigns', [
            'name' => 'Sommet des chefs d’État', 'kind' => 'event', 'priority' => 50, 'starts_at' => $start->toIso8601String(), 'ends_at' => $start->addDays(40)->toIso8601String(),
            'items' => [['content_id' => $content->id]], 'languages' => ['fr'], 'targets' => [['type' => 'floor', 'id' => $this->place()['floor']->id]],
        ])->assertStatus(422)->assertJsonPath('code', 'EVENT_TOO_LONG');

        $this->actingAs($diffuser)->postJson('/api/v1/campaigns', [
            'name' => 'Sommet des chefs d’État', 'kind' => 'event', 'priority' => 50, 'starts_at' => $start->toIso8601String(), 'ends_at' => $start->addDays(3)->toIso8601String(),
            'items' => [['content_id' => $content->id]], 'languages' => ['fr'], 'targets' => [['type' => 'floor', 'id' => $this->place()['floor']->id]],
        ])->assertCreated()->assertJsonPath('data.kind', 'event');
    }

    public function test_database_refuses_an_event_longer_than_31_days(): void
    {
        $this->expectException(QueryException::class);
        Campaign::query()->create(['reference' => 'CMP-LONG', 'name' => 'Trop long', 'kind' => 'event', 'status' => 'draft', 'priority' => 50, 'starts_at' => now(), 'ends_at' => now()->addDays(45), 'created_by' => $this->author->id]);
    }

    public function test_manifest_flags_event_entries_and_screen_shows_only_the_event(): void
    {
        $screen = $this->screen('ECR-EVT');
        [, $token] = $this->pairedDevice($screen);
        $this->activeCampaign('standard', 90, $this->approvedContent('Programme ordinaire'));
        $this->activeCampaign('event', 20, $this->approvedContent('Conseil des ministres'));

        $envelope = $this->getJson('/api/v1/device/manifest', ['Authorization' => "Bearer $token"])->assertOk()->json();
        $this->assertTrue(app(ManifestSigner::class)->verify($envelope['payload'], $envelope['signature']['value']));
        $manifest = json_decode($envelope['payload'], true);
        $this->assertEqualsCanonicalizing([false, true], array_column($manifest['schedule'], 'event'));

        $resolved = (new ScheduleResolver)->resolve($manifest + ['operating_window' => null], CarbonImmutable::now());
        $this->assertSame('program', $resolved['mode']);
        $this->assertSame(['Conseil des ministres'], array_column(array_column($resolved['playlist'], 'slide'), 'title'));
    }
}
