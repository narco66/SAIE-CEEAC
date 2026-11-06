<?php

namespace App\Jobs;

use App\Models\AssetVersion;
use App\Models\Document;
use App\Services\Audit;
use App\Services\MediaConverter;
use App\Services\VirusScanner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser;
use Throwable;
use ZipArchive;

/**
 * Traitement du fichier en quarantaine : signature MIME réelle, SHA-256, antivirus, contrôle des archives,
 * métadonnées et miniature (DOC-02, SEC-03). Plafonné à 120 s par fichier.
 */
class ProcessAssetVersion implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $tries = 2;

    public function __construct(public string $versionId)
    {
        $this->onQueue('media');
    }

    public function handle(VirusScanner $scanner, Audit $audit): void
    {
        $version = AssetVersion::query()->with('asset')->find($this->versionId);
        if (! $version || in_array($version->scan_status, ['ready', 'rejected'], true)) {
            return;
        }
        $version->forceFill(['scan_status' => 'scanning'])->save();
        $this->syncAsset($version, 'scanning');
        $path = Storage::disk('local')->path($version->storage_key);

        try {
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream';
            $allowed = config("saie.uploads.allowed.{$version->extension}.mimes", []);
            if (! in_array($mime, $allowed, true)) {
                $this->reject($version, "Signature MIME réelle ($mime) incompatible avec l’extension .{$version->extension}.", $audit);

                return;
            }
            $hash = hash_file('sha256', $path);
            $scan = $scanner->scan($path);
            $version->forceFill(['mime' => $mime, 'sha256' => $hash, 'scan_engine' => $scan['engine']])->save();
            if (! $scan['clean']) {
                $this->reject($version, $scan['detail'], $audit);

                return;
            }
            $details = match ($version->asset->kind) {
                'image' => $this->image($version, $path),
                'pdf' => $this->pdf($path),
                'office' => $this->office($version, $path),
                default => [],
            };
            if (isset($details['reject'])) {
                $this->reject($version, $details['reject'], $audit);

                return;
            }
            $converter = app(MediaConverter::class);
            $derivatives = match (true) {
                $version->asset->kind === 'office' && $version->extension !== 'vtt' => $converter->officeToPdf($version),
                $version->asset->kind === 'video' => $converter->inspectVideo($version),
                default => null,
            };
            if (($derivatives['video']['duration_s'] ?? null) !== null) {
                $details['duration_s'] = $derivatives['video']['duration_s'];
            }
            $version->forceFill($details + ['derivatives' => $derivatives, 'scan_status' => 'ready', 'scan_detail' => trim($scan['detail'].' '.($derivatives['note'] ?? '')), 'processed_at' => now()])->save();
            $this->syncAsset($version, 'ready');
            Document::activateReadyVersion($version);
            $audit->log('asset.ready', $version->asset, version: $version->version, actor: null);
        } catch (Throwable $e) {
            $this->reject($version, 'Échec contrôlé du traitement : '.mb_substr($e->getMessage(), 0, 200), $audit);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function image(AssetVersion $version, string $path): array
    {
        $info = @getimagesize($path);
        if (! $info) {
            return ['reject' => 'Image illisible.'];
        }
        [$width, $height] = $info;
        $thumbnail = null;
        if (function_exists('imagecreatefromstring') && $width * $height < 40_000_000) {
            $source = @imagecreatefromstring((string) file_get_contents($path));
            if ($source) {
                $targetWidth = min(480, $width);
                $scaled = imagescale($source, $targetWidth, (int) round($height * $targetWidth / $width));
                $thumbnail = dirname($version->storage_key).'/thumb.jpg';
                ob_start();
                imagejpeg($scaled, null, 78);
                Storage::disk('local')->put($thumbnail, (string) ob_get_clean());
            }
        }

        return ['width' => $width, 'height' => $height, 'thumbnail_key' => $thumbnail];
    }

    /**
     * @return array<string, mixed>
     */
    private function pdf(string $path): array
    {
        $parser = new Parser;
        $document = $parser->parseFile($path);
        $text = mb_substr(trim(preg_replace('/\s+/u', ' ', $document->getText())), 0, 200_000);

        return ['pages' => count($document->getPages()), 'text_content' => $text !== '' ? $text : null];
    }

    /**
     * Contrôle des archives bureautiques : nombre d'entrées, taux de compression, absence d'exécutable (SEC-03).
     *
     * @return array<string, mixed>
     */
    private function office(AssetVersion $version, string $path): array
    {
        if ($version->extension === 'vtt') {
            return str_starts_with(ltrim((string) file_get_contents($path, length: 64), "\xEF\xBB\xBF"), 'WEBVTT') ? [] : ['reject' => 'Fichier de sous-titres WebVTT invalide.'];
        }
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            return ['reject' => 'Archive bureautique illisible.'];
        }
        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $total += $stat['size'];
            if (preg_match('/\.(exe|dll|bat|cmd|ps1|vbs|js|bin)$/i', $stat['name'])) {
                $zip->close();

                return ['reject' => 'Contenu exécutable détecté dans l’archive.'];
            }
        }
        $zip->close();
        if ($zip->numFiles > 5000 || $total > 500 * 1024 * 1024 || ($version->size > 0 && $total / $version->size > 200)) {
            return ['reject' => 'Archive anormale (bombe de décompression suspectée).'];
        }

        return [];
    }

    private function reject(AssetVersion $version, string $detail, Audit $audit): void
    {
        $version->forceFill(['scan_status' => 'rejected', 'scan_detail' => $detail, 'processed_at' => now()])->save();
        $this->syncAsset($version, 'rejected');
        $audit->log('asset.rejected', $version->asset, $detail, version: $version->version, outcome: 'failure');
    }

    private function syncAsset(AssetVersion $version, string $status): void
    {
        if ($version->asset->current_version_id === $version->id) {
            $version->asset->forceFill(['status' => $status])->save();
        }
    }
}
