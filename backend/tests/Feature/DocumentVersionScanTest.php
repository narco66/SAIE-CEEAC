<?php

namespace Tests\Feature;

use App\Jobs\ProcessAssetVersion;
use App\Models\Document;
use App\Models\Setting;
use Dompdf\Dompdf;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * DOC-03 : la nouvelle version d'un document publié ne le rend pas indisponible pendant son analyse ;
 * elle devient courante une fois déclarée saine.
 */
class DocumentVersionScanTest extends TestCase
{
    private function pdf(string $name): UploadedFile
    {
        $pdf = new Dompdf;
        $pdf->loadHtml("<p>$name</p>");
        $pdf->render();

        return UploadedFile::fake()->createWithContent($name, (string) $pdf->output());
    }

    public function test_published_document_keeps_serving_previous_version_while_new_one_is_scanned(): void
    {
        Setting::put('documents.four_eyes', false); // objet du test : l'analyse des versions, pas la double validation
        $owner = $this->userWith('contributeur', 'DSI', null, 'DSI');
        $id = $this->actingAs($owner)->post('/api/v1/documents', ['file' => $this->pdf('note-v1.pdf'), 'title' => 'Note de service', 'classification' => 'interne'])->assertCreated()->json('data.id');
        $this->actingAs($owner)->postJson("/api/v1/documents/$id/publish")->assertOk();
        $v1 = Document::query()->findOrFail($id)->current_version_id;

        Queue::fake();
        $this->actingAs($owner)->post("/api/v1/documents/$id/versions", ['file' => $this->pdf('note-v2.pdf'), 'notes' => 'Mise à jour'])->assertOk();

        $document = Document::query()->findOrFail($id);
        $this->assertSame($v1, $document->current_version_id, 'la version saine reste servie pendant l’analyse');
        $this->actingAs($this->userWith('agent', 'DSI'))->get("/api/v1/documents/$id/versions/1/download")->assertOk();

        $pending = $document->versions()->where('version', 2)->firstOrFail();
        Queue::assertPushed(ProcessAssetVersion::class);
        app()->call([new ProcessAssetVersion($pending->asset_version_id), 'handle']);

        $this->assertSame($pending->id, $document->refresh()->current_version_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'document.version_activated', 'object_id' => $id]);
    }
}
