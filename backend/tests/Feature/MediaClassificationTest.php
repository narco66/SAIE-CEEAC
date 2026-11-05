<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\AssetVersion;
use App\Models\Campaign;
use App\Models\Content;
use App\Models\ContentVersion;
use App\Models\User;
use App\Services\ManifestSigner;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * DOC-04 : un média n'est jamais plus classifié que le contenu qu'il illustre, et un écran ne reçoit jamais un média plus
 * classifié que sa zone — même si une donnée antérieure contournait la règle de soumission.
 */
class MediaClassificationTest extends TestCase
{
    private User $author;

    protected function setUp(): void
    {
        parent::setUp();
        $this->author = $this->userWith('contributeur');
    }

    private function media(string $classification): AssetVersion
    {
        $asset = Asset::query()->create(['reference' => 'MED-'.uniqid(), 'title' => "Image $classification", 'kind' => 'image', 'owner_structure_id' => $this->author->structure_id, 'classification' => $classification, 'status' => 'ready']);

        return $asset->versions()->create(['version' => 1, 'storage_key' => 'assets/'.uniqid().'.png', 'original_name' => 'i.png', 'extension' => 'png', 'size' => 10, 'mime' => 'image/png', 'sha256' => str_repeat('e', 64), 'scan_status' => 'ready']);
    }

    private function contentWithMedia(string $classification, AssetVersion $media): Content
    {
        $response = $this->actingAs($this->author)->postJson('/api/v1/contents', [
            'title' => 'Annonce', 'category_id' => $this->category()->id, 'classification' => $classification, 'expires_at' => now()->addDays(5)->toIso8601String(),
            'languages' => ['fr' => ['title' => 'Annonce illustrée', 'body' => 'Texte.']],
        ])->assertCreated();
        $content = Content::query()->findOrFail($response->json('data.id'));
        $content->forceFill(['media' => [['asset_version_id' => $media->id, 'kind' => 'image', 'role' => 'main']]])->save();

        return $content;
    }

    public function test_media_more_classified_than_its_content_is_refused_at_submission(): void
    {
        $public = $this->contentWithMedia('public', $this->media('interne'));
        $this->actingAs($this->author)->postJson("/api/v1/contents/{$public->id}/submit", ['languages' => ['fr']])
            ->assertStatus(422)->assertJsonPath('code', 'CLASSIFICATION_MISMATCH');

        $internal = $this->contentWithMedia('interne', $this->media('restreint'));
        $this->actingAs($this->author)->postJson("/api/v1/contents/{$internal->id}/submit", ['languages' => ['fr']])
            ->assertStatus(422)->assertJsonPath('code', 'CLASSIFICATION_MISMATCH');

        $ok = $this->contentWithMedia('interne', $this->media('public'));
        $this->actingAs($this->author)->postJson("/api/v1/contents/{$ok->id}/submit", ['languages' => ['fr']])->assertOk();
    }

    public function test_public_screen_never_receives_an_internal_media_even_from_legacy_data(): void
    {
        $screen = $this->screen('ECR-PUB-MEDIA');
        [, $token] = $this->pairedDevice($screen);
        $internalMedia = $this->media('interne');
        $validator = $this->userWith('validateur', 'COM', ['fr']);
        $content = Content::query()->create(['reference' => 'CNT-'.uniqid(), 'title' => 'Ancienne annonce', 'category_id' => $this->category()->id, 'owner_structure_id' => $this->author->structure_id, 'author_id' => $this->author->id, 'classification' => 'public', 'expires_at' => now()->addDay(), 'status' => 'approved']);
        $version = ContentVersion::query()->create(['content_id' => $content->id, 'lang' => 'fr', 'revision' => 1, 'payload' => ['title' => 'Ancienne annonce', 'media' => [['asset_version_id' => $internalMedia->id, 'kind' => 'image']]], 'classification' => 'public', 'author_id' => $this->author->id, 'approved_by' => $validator->id, 'approved_at' => now(), 'payload_hash' => str_repeat('f', 64)]);
        $campaign = Campaign::query()->create(['reference' => 'CMP-'.uniqid(), 'name' => 'Accueil', 'status' => 'active', 'priority' => 50, 'starts_at' => now()->subHour(), 'ends_at' => now()->addDay(), 'created_by' => $this->author->id, 'published_at' => now()]);
        $campaign->items()->create(['content_id' => $content->id, 'position' => 1, 'version_map' => ['fr' => $version->id]]);
        DB::table('campaign_languages')->insert(['campaign_id' => $campaign->id, 'lang' => 'fr', 'rank' => 1]);
        $campaign->targets()->create(['target_type' => 'screen', 'target_id' => $screen->id]);

        $envelope = $this->getJson('/api/v1/device/manifest', ['Authorization' => "Bearer $token"])->assertOk()->json();
        $this->assertTrue(app(ManifestSigner::class)->verify($envelope['payload'], $envelope['signature']['value']));
        $manifest = json_decode($envelope['payload'], true);

        $this->assertCount(1, $manifest['schedule']);
        $this->assertSame([], $manifest['schedule'][0]['slide']['media']);
        $this->assertStringNotContainsString($internalMedia->id, $envelope['payload']);
        $this->getJson("/api/v1/device/assets/{$internalMedia->id}", ['Authorization' => "Bearer $token"])->assertForbidden();
    }
}
