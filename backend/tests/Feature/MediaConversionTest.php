<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Services\MediaConverter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;
use ZipArchive;

/**
 * DOC-01 / SEC-03 : conversions optionnelles, plafonnées, et état explicite quand les binaires sont absents.
 */
class MediaConversionTest extends TestCase
{
    private function docx(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'docx');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
        $zip->addFromString('word/document.xml', '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Note</w:t></w:r></w:p></w:body></w:document>');
        $zip->close();

        return (string) file_get_contents($path);
    }

    public function test_office_file_without_converter_is_ready_with_explicit_note(): void
    {
        config(['saie.converters.soffice' => null]);
        $user = $this->userWith('contributeur');
        $response = $this->actingAs($user)->post('/api/v1/assets', ['file' => UploadedFile::fake()->createWithContent('note.docx', $this->docx()), 'classification' => 'interne'])->assertCreated();
        $asset = Asset::query()->findOrFail($response->json('data.id'));
        $version = $asset->currentVersion;
        $this->assertSame('ready', $version->scan_status);
        $this->assertStringContainsString('LibreOffice non configuré', $version->scan_detail);
        $this->actingAs($user)->get("/api/v1/asset-versions/{$version->id}/consultation")->assertNotFound();
    }

    public function test_office_conversion_uses_isolated_bounded_process_when_configured(): void
    {
        $fake = tempnam(sys_get_temp_dir(), 'soffice');
        config(['saie.converters.soffice' => $fake]);
        Process::fake(function ($process) {
            $args = $process->command;
            $outDir = $args[array_search('--outdir', $args, true) + 1];
            file_put_contents($outDir.'/'.pathinfo(end($args), PATHINFO_FILENAME).'.pdf', '%PDF-1.4 consultation');

            return Process::result('converted');
        });
        $user = $this->userWith('contributeur');
        $response = $this->actingAs($user)->post('/api/v1/assets', ['file' => UploadedFile::fake()->createWithContent('note.docx', $this->docx()), 'classification' => 'interne'])->assertCreated();
        $version = Asset::query()->findOrFail($response->json('data.id'))->currentVersion;
        $this->assertNotEmpty($version->derivatives['consultation_pdf']);
        Process::assertRan(fn ($process) => in_array('--headless', $process->command, true) && $process->timeout === 120);
        $this->actingAs($user)->get("/api/v1/asset-versions/{$version->id}/consultation")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertTrue(app(MediaConverter::class)->available('soffice'));
    }
}
