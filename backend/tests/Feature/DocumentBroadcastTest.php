<?php

namespace Tests\Feature;

use App\Jobs\DeliverNotificationEmail;
use App\Models\Asset;
use App\Models\Document;
use App\Models\DocumentBroadcast;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * DOC-09 : diffusion par courriel d'un document publié à des structures, par la file NOT-02, avec un lien authentifié
 * et sans pièce jointe ; seuls les agents habilités à lire le document sont destinataires.
 */
class DocumentBroadcastTest extends TestCase
{
    private User $author;

    protected function setUp(): void
    {
        parent::setUp();
        $this->author = $this->userWith('contributeur', 'COM');
        Queue::fake();
    }

    private function document(string $classification, string $status = 'published'): Document
    {
        $asset = Asset::query()->create(['reference' => 'MED-'.uniqid(), 'title' => 'f', 'kind' => 'pdf', 'owner_structure_id' => $this->author->structure_id, 'classification' => $classification, 'status' => 'ready']);
        $version = $asset->versions()->create(['version' => 1, 'storage_key' => 'assets/f.pdf', 'original_name' => 'f.pdf', 'extension' => 'pdf', 'size' => 10, 'mime' => 'application/pdf', 'sha256' => str_repeat('d', 64), 'scan_status' => 'ready']);
        $document = Document::query()->create([
            'reference' => 'DOC-'.uniqid(), 'title' => "Note $classification sur le budget", 'owner_structure_id' => $this->author->structure_id, 'author_id' => $this->author->id,
            'classification' => $classification, 'status' => $status, 'published_at' => $status === 'published' ? now() : null,
        ]);
        $current = $document->versions()->create(['version' => 1, 'asset_version_id' => $version->id]);
        $document->forceFill(['current_version_id' => $current->id])->save();

        return $document;
    }

    public function test_internal_document_is_sent_with_an_authenticated_link_to_active_agents_of_selected_structures(): void
    {
        $document = $this->document('interne');
        $drh = $this->userWith('agent', 'DRH');
        $dsi = $this->userWith('agent', 'DSI');
        $inactive = $this->userWith('agent', 'DRH');
        $inactive->forceFill(['status' => 'disabled'])->save();
        $outside = $this->userWith('agent', 'DAF');

        $response = $this->actingAs($this->author)->postJson("/api/v1/documents/{$document->id}/broadcasts", [
            'structure_ids' => [$drh->structure_id, $dsi->structure_id], 'message' => 'Merci d’en prendre connaissance avant le comité.',
        ])->assertCreated();

        $response->assertJsonPath('data.recipients_count', 2)->assertJsonPath('data.excluded_count', 0);
        $notified = UserNotification::query()->where('event', 'document.broadcast')->pluck('user_id')->all();
        $this->assertEqualsCanonicalizing([$drh->id, $dsi->id], $notified);
        $this->assertNotContains($inactive->id, $notified);
        $this->assertNotContains($outside->id, $notified);

        $notification = UserNotification::query()->where('user_id', $drh->id)->firstOrFail();
        $this->assertSame("/documents/{$document->id}", $notification->link);
        $this->assertStringContainsString('aucune pièce jointe', $notification->body);
        $this->assertStringContainsString($document->title, $notification->title);
        $this->assertDatabaseHas('delivery_attempts', ['notification_id' => $notification->id, 'channel' => 'email', 'status' => 'pending']);
        Queue::assertPushed(DeliverNotificationEmail::class, 2);
        $this->assertDatabaseHas('audit_logs', ['action' => 'document.broadcast', 'object_id' => $document->id]);

        $this->actingAs($this->author)->getJson("/api/v1/documents/{$document->id}/broadcasts")->assertOk()
            ->assertJsonPath('data.0.recipients_count', 2)->assertJsonCount(2, 'data.0.structures');
    }

    public function test_agents_outside_the_document_audience_are_excluded_and_counted(): void
    {
        $document = $this->document('interne');
        $drh = $this->userWith('agent', 'DRH');
        $dsi = $this->userWith('agent', 'DSI');
        $document->audiences()->attach($drh->structure_id);

        $this->actingAs($this->author)->postJson("/api/v1/documents/{$document->id}/broadcasts", ['structure_ids' => [$drh->structure_id, $dsi->structure_id]])
            ->assertCreated()->assertJsonPath('data.recipients_count', 1)->assertJsonPath('data.excluded_count', 1);
        $this->assertSame([$drh->id], UserNotification::query()->where('event', 'document.broadcast')->pluck('user_id')->all());
    }

    public function test_restricted_document_reaches_only_authorized_readers_and_never_reveals_its_title(): void
    {
        $document = $this->document('restreint');
        $colleague = $this->userWith('contributeur', 'COM');
        $agent = $this->userWith('agent', 'COM');

        $this->actingAs($this->author)->postJson("/api/v1/documents/{$document->id}/broadcasts", ['structure_ids' => [$this->author->structure_id]])->assertCreated();

        $notified = UserNotification::query()->where('event', 'document.broadcast')->get();
        $this->assertNotContains($agent->id, $notified->pluck('user_id')->all());
        $this->assertContains($colleague->id, $notified->pluck('user_id')->all());
        foreach ($notified as $notification) {
            $this->assertStringNotContainsString('budget', $notification->title.' '.$notification->body);
        }
    }

    public function test_unpublished_document_cannot_be_broadcast(): void
    {
        $document = $this->document('interne', 'draft');
        $drh = $this->userWith('agent', 'DRH');

        $this->actingAs($this->author)->postJson("/api/v1/documents/{$document->id}/broadcasts", ['structure_ids' => [$drh->structure_id]])
            ->assertStatus(422)->assertJsonPath('code', 'DOCUMENT_NOT_PUBLISHED');
        $this->assertSame(0, DocumentBroadcast::query()->count());
    }

    public function test_reader_without_document_management_right_cannot_broadcast(): void
    {
        $document = $this->document('public');
        $agent = $this->userWith('agent', 'COM');

        $this->actingAs($agent)->postJson("/api/v1/documents/{$document->id}/broadcasts", ['structure_ids' => [$agent->structure_id]])->assertForbidden();
        $this->assertSame(0, UserNotification::query()->where('event', 'document.broadcast')->count());
    }
}
