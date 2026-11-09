<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Content;
use App\Services\VirusScanner;
use RuntimeException;
use Tests\TestCase;

/**
 * Sûreté de la publication (audit 2026-11-06, écarts MED-01, MOD-01, SEC-AV1) : un fichier bureautique n'est jamais soumis
 * à la diffusion, une zone de modèle tient dans le cadre, et l'antivirus de démonstration ne laisse rien passer en production.
 */
class PublicationSafetyTest extends TestCase
{
    public function test_office_file_cannot_illustrate_a_content_submitted_for_display(): void
    {
        $author = $this->userWith('contributeur');
        $asset = Asset::query()->create(['reference' => 'MED-DOCX', 'title' => 'Note de service', 'kind' => 'office', 'owner_structure_id' => $author->structure_id, 'classification' => 'public', 'status' => 'ready']);
        $version = $asset->versions()->create(['version' => 1, 'storage_key' => 'assets/n.docx', 'original_name' => 'n.docx', 'extension' => 'docx', 'size' => 10, 'mime' => 'application/zip', 'sha256' => str_repeat('d', 64), 'scan_status' => 'ready']);
        $id = $this->actingAs($author)->postJson('/api/v1/contents', [
            'title' => 'Annonce', 'category_id' => $this->category()->id, 'classification' => 'public', 'expires_at' => now()->addDays(5)->toIso8601String(),
            'languages' => ['fr' => ['title' => 'Annonce', 'body' => 'Texte.']],
        ])->assertCreated()->json('data.id');
        Content::query()->findOrFail($id)->forceFill(['media' => [['asset_version_id' => $version->id, 'kind' => 'office', 'role' => 'main']]])->save();

        $this->actingAs($author)->postJson("/api/v1/contents/$id/submit", ['languages' => ['fr']])
            ->assertUnprocessable()->assertJsonPath('code', 'MEDIA_NOT_DISPLAYABLE');
    }

    public function test_template_zone_must_fit_inside_the_frame(): void
    {
        $admin = $this->userWith('admin_fonc');
        $this->grantPower($admin, 'modeles');
        $zone = fn (int $x, int $w) => ['id' => 'titre', 'type' => 'text', 'x' => $x, 'y' => 0, 'w' => $w, 'h' => 20];

        $this->actingAs($admin)->postJson('/api/v1/templates', ['name' => 'Débordant', 'orientation' => 'landscape', 'layout' => ['zones' => [$zone(60, 50)]]])
            ->assertUnprocessable()->assertJsonValidationErrors('layout.zones');
        $this->actingAs($admin)->postJson('/api/v1/templates', ['name' => 'Ajusté', 'orientation' => 'landscape', 'layout' => ['zones' => [$zone(50, 50)]]])->assertCreated();
    }

    public function test_demonstration_antivirus_refuses_every_file_in_production(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'av');
        file_put_contents($path, 'contenu ordinaire');
        config(['saie.antivirus.driver' => 'signature']);
        $this->assertTrue(app(VirusScanner::class)->scan($path)['clean'], 'hors production : analyse de démonstration');

        $this->app['env'] = 'production';
        try {
            $this->expectException(RuntimeException::class);
            app(VirusScanner::class)->scan($path);
        } finally {
            $this->app['env'] = 'testing';
            @unlink($path);
        }
    }

    public function test_converted_presentation_is_broadcast_through_its_fingerprinted_pdf_copy(): void
    {
        $author = $this->userWith('contributeur');
        $asset = Asset::query()->create(['reference' => 'MED-PPTX', 'title' => 'Présentation du budget', 'kind' => 'office', 'owner_structure_id' => $author->structure_id, 'classification' => 'public', 'status' => 'ready']);
        $version = $asset->versions()->create(['version' => 1, 'storage_key' => 'assets/b.pptx', 'original_name' => 'b.pptx', 'extension' => 'pptx', 'size' => 10, 'mime' => 'application/zip', 'sha256' => str_repeat('a', 64), 'scan_status' => 'ready',
            'derivatives' => ['consultation_pdf' => 'assets/derived/b.pdf', 'broadcast_pdf' => ['storage_key' => 'assets/derived/b.pdf', 'sha256' => str_repeat('b', 64), 'size' => 2048, 'pages' => 6]]]);

        $this->assertSame('pdf', $version->broadcastKind());
        $this->assertSame(['storage_key' => 'assets/derived/b.pdf', 'sha256' => str_repeat('b', 64), 'size' => 2048, 'mime' => 'application/pdf'], $version->broadcastFile());

        $id = $this->actingAs($author)->postJson('/api/v1/contents', [
            'title' => 'Budget', 'category_id' => $this->category()->id, 'classification' => 'public', 'expires_at' => now()->addDays(5)->toIso8601String(),
            'languages' => ['fr' => ['title' => 'Budget 2027', 'body' => 'Présentation.']],
        ])->assertCreated()->json('data.id');
        Content::query()->findOrFail($id)->forceFill(['media' => [['asset_version_id' => $version->id, 'kind' => 'office', 'role' => 'main', 'pages' => [1, 2, 3], 'page_duration_s' => 10]]])->save();
        $this->actingAs($author)->postJson("/api/v1/contents/$id/submit", ['languages' => ['fr']])->assertOk();
        $this->actingAs($author)->getJson("/api/v1/assets/{$asset->id}")->assertOk()->assertJsonPath('data.versions.0.broadcastable_as_pdf', true)->assertJsonPath('data.versions.0.broadcast_pages', 6);
    }
}
