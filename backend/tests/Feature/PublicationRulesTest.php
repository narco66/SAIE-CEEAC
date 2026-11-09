<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\AssetVersion;
use App\Models\Campaign;
use App\Models\Content;
use App\Models\ContentVersion;
use App\Models\Document;
use App\Models\Setting;
use App\Models\Template;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Règles de publication (audit 2026-11-06, écarts PUB-01 à PUB-03) : début de validité du contenu appliqué au manifeste,
 * avertissement d'orientation à l'aperçu, média encore diffusé protégé contre la corbeille.
 */
class PublicationRulesTest extends TestCase
{
    public function test_content_is_never_scheduled_before_its_validity_start(): void
    {
        [, $token] = $this->pairedDevice($this->screen('ECR-VALID'));
        $content = $this->campaignWith();
        $content->forceFill(['valid_from' => now()->addMinutes(30)])->save();

        $schedule = json_decode($this->getJson('/api/v1/device/manifest', ['Authorization' => "Bearer $token"])->assertOk()->json('payload'), true)['schedule'];
        $this->assertCount(1, $schedule);
        $this->assertEqualsWithDelta(now()->addMinutes(30)->getTimestamp(), strtotime($schedule[0]['from']), 2, 'début de validité retenu');

        $content->forceFill(['valid_from' => now()->addDays(3)])->save();
        $this->app['auth']->forgetGuards();
        $schedule = json_decode($this->getJson('/api/v1/device/manifest', ['Authorization' => "Bearer $token"])->assertOk()->json('payload'), true)['schedule'];
        $this->assertCount(0, $schedule, 'validité commençant après la campagne : rien à diffuser');
    }

    public function test_preview_warns_when_template_orientation_differs_from_the_screen(): void
    {
        $screen = $this->screen('ECR-PAYSAGE');
        $content = $this->campaignWith();
        $portrait = Template::query()->create(['code' => 'TPL-PORTRAIT', 'name' => 'Portrait', 'orientation' => 'portrait', 'layout' => ['zones' => [['id' => 'texte', 'type' => 'text', 'x' => 0, 'y' => 0, 'w' => 100, 'h' => 100]]]]);
        $content->forceFill(['template_id' => $portrait->id])->save();

        $preview = $this->actingAs($this->userWith('diffuseur'))->postJson('/api/v1/campaigns/preview', ['targets' => [['type' => 'screen', 'id' => $screen->id]], 'items' => [['content_id' => $content->id]]])->assertOk();
        $this->assertTrue($preview->json('data.screens.0.orientation_mismatch'));
        $this->assertNotEmpty(array_filter($preview->json('data.warnings'), fn (string $w) => str_contains($w, 'orientation')));
    }

    public function test_media_still_broadcast_or_used_as_subtitles_cannot_be_trashed(): void
    {
        $author = $this->userWith('contributeur');
        $subtitles = $this->asset($author->structure_id, 'Sous-titres');
        $content = $this->campaignWith();
        $content->languages()->update(['subtitles_asset_version_id' => $subtitles->id]);
        $this->actingAs($author)->postJson("/api/v1/assets/{$subtitles->asset_id}/trash")->assertStatus(409)->assertJsonPath('code', 'ASSET_IN_USE');

        // Le contenu courant ne référence plus le visuel, mais la version approuvée encore diffusée, elle, le contient.
        $image = $this->asset($author->structure_id, 'Visuel diffusé');
        $this->campaignWith([['asset_version_id' => $image->id]]);
        $response = $this->actingAs($author)->postJson("/api/v1/assets/{$image->asset_id}/trash")->assertStatus(409);
        $this->assertContains('campaign', array_column($response->json('details.usages') ?? $response->json('usages') ?? [], 'type'));
    }

    public function test_document_trash_requires_unpublishing_and_no_qr_reference(): void
    {
        $author = $this->userWith('contributeur');
        $file = $this->asset($author->structure_id, 'Fichier');
        $document = Document::query()->create(['reference' => 'DOC-CORB', 'title' => 'Note à retirer', 'owner_structure_id' => $author->structure_id, 'author_id' => $author->id, 'classification' => 'interne', 'status' => 'published', 'published_at' => now()]);
        $document->forceFill(['current_version_id' => $document->versions()->create(['version' => 1, 'asset_version_id' => $file->id])->id])->save();

        $this->actingAs($author)->postJson("/api/v1/documents/{$document->id}/trash", ['reason' => 'Document caduc'])->assertStatus(409)->assertJsonPath('code', 'DOCUMENT_PUBLISHED');
        $document->forceFill(['status' => 'unpublished'])->save();
        $content = $this->campaignWith();
        $content->forceFill(['qr' => ['enabled' => true, 'document_id' => $document->id]])->save();
        $this->actingAs($author)->postJson("/api/v1/documents/{$document->id}/trash", ['reason' => 'Document caduc'])->assertStatus(409)->assertJsonPath('code', 'DOCUMENT_IN_USE');

        $content->forceFill(['qr' => null])->save();
        $this->actingAs($author)->postJson("/api/v1/documents/{$document->id}/trash", ['reason' => 'Document caduc'])->assertNoContent();
        $this->assertSame('trashed', $document->refresh()->status);
        $this->actingAs($author)->getJson('/api/v1/documents')->assertOk()->assertJsonMissing(['id' => $document->id]);
    }

    public function test_media_detail_lists_visible_duplicates_by_fingerprint(): void
    {
        $author = $this->userWith('contributeur');
        $first = $this->asset($author->structure_id, 'Logo 2026');
        $second = $this->asset($author->structure_id, 'Logo 2026 (copie)');
        $first->asset->forceFill(['current_version_id' => $first->id])->save();
        $second->asset->forceFill(['current_version_id' => $second->id])->save();

        $this->actingAs($author)->getJson("/api/v1/assets/{$first->asset_id}")->assertOk()->assertJsonPath('data.duplicates.0.id', $second->asset_id);
    }

    public function test_media_tags_and_expired_usage_rights_are_enforced(): void
    {
        [, $token] = $this->pairedDevice($this->screen('ECR-DROITS'));
        $author = $this->userWith('contributeur');
        $image = $this->asset($author->structure_id, 'Photo du sommet');
        $image->asset->forceFill(['current_version_id' => $image->id, 'created_by' => $author->id])->save();

        $this->actingAs($author)->patchJson("/api/v1/assets/{$image->asset_id}", ['expected_version' => $image->asset->version, 'tags' => [' Sommet ', 'sommet', 'Presse'], 'usage_rights' => '© CEEAC — photo officielle', 'rights_expire_on' => now()->addMonth()->toDateString()])
            ->assertOk()->assertJsonPath('data.tags', ['sommet', 'presse'])->assertJsonPath('data.rights_expired', false);
        $this->actingAs($author)->getJson('/api/v1/assets?tag=Sommet')->assertOk()->assertJsonPath('data.0.id', $image->asset_id);

        $this->campaignWith([['asset_version_id' => $image->id]]);
        $image->asset->forceFill(['rights_expire_on' => now()->subDays(2)->toDateString()])->save();
        $this->app['auth']->forgetGuards();
        $schedule = json_decode($this->getJson('/api/v1/device/manifest', ['Authorization' => "Bearer $token"])->assertOk()->json('payload'), true)['schedule'];
        $this->assertCount(0, $schedule, 'droits échus : entrée retirée, jamais amputée de son visuel');

        $draft = $this->actingAs($author)->postJson('/api/v1/contents', [
            'title' => 'Annonce', 'category_id' => $this->category()->id, 'classification' => 'public', 'expires_at' => now()->addDays(5)->toIso8601String(),
            'languages' => ['fr' => ['title' => 'Annonce', 'body' => 'Texte.']],
        ])->assertCreated()->json('data.id');
        Content::query()->findOrFail($draft)->forceFill(['media' => [['asset_version_id' => $image->id, 'kind' => 'image', 'role' => 'main']]])->save();
        $this->actingAs($author)->postJson("/api/v1/contents/$draft/submit", ['languages' => ['fr']])->assertUnprocessable()->assertJsonPath('code', 'MEDIA_RIGHTS_EXPIRED');
    }

    public function test_document_four_eyes_rule_requires_another_manager_to_publish(): void
    {
        $author = $this->userWith('contributeur');
        $colleague = $this->userWith('contributeur');
        $file = $this->asset($author->structure_id, 'Note');
        $makeDocument = function (string $reference) use ($author, $file): Document {
            $document = Document::query()->create(['reference' => $reference, 'title' => 'Note de service', 'owner_structure_id' => $author->structure_id, 'author_id' => $author->id, 'classification' => 'interne', 'status' => 'draft']);
            $document->forceFill(['current_version_id' => $document->versions()->create(['version' => 1, 'asset_version_id' => $file->id])->id])->save();

            return $document;
        };

        // Activée par défaut depuis le 8 novembre (PUB-06) ; désactivable par le réglage.
        $this->assertTrue((bool) Setting::effective('documents.four_eyes'));
        Setting::put('documents.four_eyes', false);
        $free = $makeDocument('DOC-LIBRE');
        $this->actingAs($author)->postJson("/api/v1/documents/{$free->id}/publish")->assertOk();

        Setting::put('documents.four_eyes', true);
        $controlled = $makeDocument('DOC-4Y');
        $this->actingAs($author)->getJson("/api/v1/documents/{$controlled->id}")->assertOk()->assertJsonPath('data.can_publish', false);
        $this->actingAs($colleague)->getJson("/api/v1/documents/{$controlled->id}")->assertOk()->assertJsonPath('data.can_publish', true);
        $this->actingAs($author)->postJson("/api/v1/documents/{$controlled->id}/publish")->assertForbidden()->assertJsonPath('code', 'FOUR_EYES_REQUIRED');
        $this->actingAs($author)->postJson("/api/v1/documents/{$controlled->id}/request-publication")->assertOk();
        $this->assertDatabaseHas('notifications', ['user_id' => $colleague->id, 'event' => 'document.publication_requested']);
        $this->actingAs($colleague)->postJson("/api/v1/documents/{$controlled->id}/publish")->assertOk();
        $this->assertSame('published', $controlled->refresh()->status);
    }

    public function test_reusable_playlist_keeps_order_and_campaign_archiving_is_controlled(): void
    {
        $diffuser = $this->userWith('diffuseur');
        $first = $this->campaignWith();
        $second = $this->campaignWith();
        $this->actingAs($this->userWith('agent'))->postJson('/api/v1/playlists', ['name' => 'Accueil', 'items' => [['content_id' => $first->id]]])->assertForbidden();

        $id = $this->actingAs($diffuser)->postJson('/api/v1/playlists', ['name' => 'Accueil du matin', 'items' => [['content_id' => $second->id, 'duration_s' => 20], ['content_id' => $first->id]]])
            ->assertCreated()->json('data.id');
        $items = $this->actingAs($diffuser)->getJson("/api/v1/playlists/$id")->assertOk()->json('data.items');
        $this->assertSame([$second->id, $first->id], array_column($items, 'content_id'), 'ordre conservé');
        $this->assertSame(['fr'], $items[0]['approved']);

        $first->forceFill(['status' => 'archived'])->save();
        $this->actingAs($diffuser)->patchJson("/api/v1/playlists/$id", ['items' => [['content_id' => $first->id]]])->assertUnprocessable()->assertJsonPath('code', 'CONTENT_ARCHIVED');
        $this->actingAs($diffuser)->patchJson("/api/v1/playlists/$id", ['status' => 'archived'])->assertOk()->assertJsonPath('data.status', 'archived');

        $campaign = Campaign::query()->whereHas('items', fn ($q) => $q->where('content_id', $second->id))->firstOrFail();
        $this->actingAs($diffuser)->postJson("/api/v1/campaigns/{$campaign->id}/archive")->assertStatus(409);
        $campaign->forceFill(['status' => 'suspended'])->save();
        $this->actingAs($diffuser)->postJson("/api/v1/campaigns/{$campaign->id}/archive", ['reason' => 'Fin de l’opération'])->assertOk()->assertJsonPath('data.status', 'archived');
        $this->assertNotContains($campaign->id, array_column($this->actingAs($diffuser)->getJson('/api/v1/campaigns')->json('data'), 'id'), 'archivée : absente de la liste courante');
    }

    public function test_list_endpoints_expose_preloaded_versions_and_usage_counts(): void
    {
        $author = $this->userWith('contributeur');
        $image = $this->asset($author->structure_id, 'Visuel utilisé');
        $content = $this->campaignWith([['asset_version_id' => $image->id]]);
        $content->forceFill(['media' => [['asset_version_id' => $image->id, 'kind' => 'image', 'role' => 'main']]])->save();
        $diffuser = $this->userWith('diffuseur');

        // Régression constatée en recette : la liste préchargée affichait « — » au lieu des versions approuvées.
        $row = collect($this->actingAs($diffuser)->getJson('/api/v1/contents?approved_only=1')->assertOk()->json('data'))->firstWhere('id', $content->id);
        $this->assertSame(['fr'], array_column($row['approved_versions'], 'lang'));

        // … et 0 utilisation pour tout média.
        $asset = collect($this->actingAs($author)->getJson('/api/v1/assets')->assertOk()->json('data'))->firstWhere('id', $image->asset_id);
        $this->assertSame(2, $asset['usages'], 'contenu courant + campagne qui diffuse la version approuvée');
    }

    private function asset(string $structureId, string $title): AssetVersion
    {
        $asset = Asset::query()->create(['reference' => 'MED-'.uniqid(), 'title' => $title, 'kind' => 'image', 'owner_structure_id' => $structureId, 'classification' => 'public', 'status' => 'ready']);

        return $asset->versions()->create(['version' => 1, 'storage_key' => 'assets/'.uniqid().'.png', 'original_name' => 'i.png', 'extension' => 'png', 'size' => 10, 'mime' => 'image/png', 'sha256' => str_repeat('f', 64), 'scan_status' => 'ready']);
    }

    /**
     * @param  list<array<string, string>>  $media  Médias de la version approuvée (instantané immuable)
     */
    private function campaignWith(array $media = []): Content
    {
        $author = $this->userWith('contributeur');
        $validator = $this->userWith('validateur', 'COM', ['fr']);
        $content = Content::query()->create(['reference' => 'CNT-'.uniqid(), 'title' => 'Annonce', 'category_id' => $this->category()->id, 'owner_structure_id' => $author->structure_id, 'author_id' => $author->id, 'classification' => 'public', 'expires_at' => now()->addDays(2), 'status' => 'approved']);
        $content->languages()->create(['lang' => 'fr', 'title' => 'Annonce', 'status' => 'approved']);
        $version = ContentVersion::query()->create(['content_id' => $content->id, 'lang' => 'fr', 'revision' => 1, 'payload' => ['title' => 'Annonce', 'media' => $media], 'classification' => 'public', 'author_id' => $author->id, 'approved_by' => $validator->id, 'approved_at' => now(), 'payload_hash' => str_repeat('c', 64)]);
        $campaign = Campaign::query()->create(['reference' => 'CMP-'.uniqid(), 'name' => 'Règles', 'status' => 'active', 'priority' => 50, 'starts_at' => now()->subHour(), 'ends_at' => now()->addDay(), 'created_by' => $author->id, 'published_at' => now()]);
        $campaign->items()->create(['content_id' => $content->id, 'position' => 1, 'version_map' => ['fr' => $version->id]]);
        DB::table('campaign_languages')->insert(['campaign_id' => $campaign->id, 'lang' => 'fr', 'rank' => 1]);
        $campaign->targets()->create(['target_type' => 'floor', 'target_id' => $this->place()['floor']->id]);

        return $content;
    }
}
