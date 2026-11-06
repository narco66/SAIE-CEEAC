<?php

namespace Tests\Feature;

use App\Models\AssetVersion;
use App\Models\Document;
use Dompdf\Dompdf;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * DOC-04, DOC-07 : la médiathèque ne contourne ni la classification restreinte ni les règles documentaires.
 */
class MediaAccessTest extends TestCase
{
    private function pdf(string $name): UploadedFile
    {
        $pdf = new Dompdf;
        $pdf->loadHtml("<p>$name</p>");
        $pdf->render();

        return UploadedFile::fake()->createWithContent($name, (string) $pdf->output());
    }

    private function fileOf(string $versionId, $user): int
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user)->get("/api/v1/asset-versions/$versionId/file")->getStatusCode();
    }

    public function test_restricted_media_needs_media_management_rights_not_mere_consultation(): void
    {
        $owner = $this->userWith('contributeur', 'DSI', null, 'DSI');
        $versionId = $this->actingAs($owner)->post('/api/v1/assets', ['file' => $this->pdf('secret.pdf'), 'classification' => 'restreint'])->assertCreated()->json('version.id');

        $this->assertSame(200, $this->fileOf($versionId, $owner));
        $this->assertSame(404, $this->fileOf($versionId, $this->userWith('diffuseur')), 'la consultation de la médiathèque ne donne pas accès au restreint');
        $this->assertSame(404, $this->fileOf($versionId, $this->userWith('validateur', 'COM', ['fr'])));
        $this->assertSame(404, $this->fileOf($versionId, $this->userWith('contributeur', 'COM', null, 'COM')), 'hors périmètre de gestion');
        $this->assertSame(200, $this->fileOf($versionId, $this->userWith('contributeur', 'COM')), 'gestionnaire de médias à périmètre global : habilité');
    }

    public function test_document_file_is_only_served_through_document_rules_and_follows_its_classification(): void
    {
        $owner = $this->userWith('contributeur', 'DSI', null, 'DSI');
        $id = $this->actingAs($owner)->post('/api/v1/documents', ['file' => $this->pdf('circulaire.pdf'), 'title' => 'Circulaire', 'classification' => 'interne', 'audience_ids' => [$owner->structure_id]])->assertCreated()->json('data.id');
        $document = Document::query()->findOrFail($id);
        $versionId = $document->currentVersion->asset_version_id;

        $this->assertSame(200, $this->fileOf($versionId, $owner));
        $this->assertSame(404, $this->fileOf($versionId, $this->userWith('diffuseur')), 'pas de contournement de l’audience par la médiathèque');

        $this->actingAs($owner)->patchJson("/api/v1/documents/$id", ['classification' => 'restreint', 'expected_version' => $document->version])->assertOk();
        $this->assertSame('restreint', AssetVersion::query()->findOrFail($versionId)->asset->classification);
    }
}
