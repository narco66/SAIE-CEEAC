<?php

namespace App\Services;

use App\Models\AssetVersion;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * Conversions isolées (DOC-01, SEC-03) : copie PDF de consultation des fichiers bureautiques (LibreOffice sans interface)
 * et contrôle/transcodage H.264-AAC des vidéos (ffprobe/ffmpeg). Chaque appel est plafonné à 120 s ; en production, le
 * worker « media » s'exécute dans un conteneur sans accès réseau. Sans binaire configuré, aucune conversion n'est tentée.
 */
class MediaConverter
{
    private const TIMEOUT = 120;

    public function available(string $tool): bool
    {
        $path = config("saie.converters.$tool");

        return is_string($path) && $path !== '' && is_file($path);
    }

    /**
     * @return array<string, mixed>
     */
    public function officeToPdf(AssetVersion $version): array
    {
        if (! $this->available('soffice')) {
            return ['consultation_pdf' => null, 'note' => 'Copie PDF de consultation non produite : LibreOffice non configuré (SAIE_SOFFICE_PATH).'];
        }
        $source = Storage::disk('local')->path($version->storage_key);
        $outDir = Storage::disk('local')->path(dirname($version->storage_key).'/derived');
        @mkdir($outDir, 0750, true);
        $profile = 'file:///'.str_replace('\\', '/', sys_get_temp_dir()).'/saie-lo-'.$version->id;
        $result = Process::timeout(self::TIMEOUT)->run([config('saie.converters.soffice'), '--headless', '--norestore', '-env:UserInstallation='.$profile, '--convert-to', 'pdf', '--outdir', $outDir, $source]);
        $pdf = $outDir.'/'.pathinfo($source, PATHINFO_FILENAME).'.pdf';
        if (! $result->successful() || ! is_file($pdf)) {
            return ['consultation_pdf' => null, 'note' => 'Conversion bureautique en échec contrôlé : '.mb_substr($result->errorOutput(), 0, 200)];
        }

        return ['consultation_pdf' => dirname($version->storage_key).'/derived/'.basename($pdf), 'note' => 'Copie de consultation : ne vaut pas original signé.'];
    }

    /**
     * Lit codecs et durée ; transcode en H.264/AAC si nécessaire et si ffmpeg est disponible.
     *
     * @return array<string, mixed>
     */
    public function inspectVideo(AssetVersion $version): array
    {
        if (! $this->available('ffprobe')) {
            return ['video' => null, 'note' => 'Codec non vérifié : ffprobe non configuré (SAIE_FFPROBE_PATH). Déposer des vidéos H.264/AAC.'];
        }
        $source = Storage::disk('local')->path($version->storage_key);
        $probe = Process::timeout(30)->run([config('saie.converters.ffprobe'), '-v', 'error', '-show_entries', 'stream=codec_type,codec_name:format=duration', '-of', 'json', $source]);
        $info = json_decode($probe->output(), true) ?: [];
        $codecs = collect($info['streams'] ?? [])->mapWithKeys(fn ($s) => [$s['codec_type'] => $s['codec_name']])->all();
        $duration = isset($info['format']['duration']) ? (int) round((float) $info['format']['duration']) : null;
        $compatible = ($codecs['video'] ?? null) === 'h264' && in_array($codecs['audio'] ?? 'aac', ['aac', null], true);
        $derived = ['video' => ['codecs' => $codecs, 'duration_s' => $duration, 'compatible' => $compatible]];
        if (! $compatible && $this->available('ffmpeg')) {
            $target = dirname($version->storage_key).'/derived/h264.mp4';
            @mkdir(dirname(Storage::disk('local')->path($target)), 0750, true);
            $run = Process::timeout(self::TIMEOUT * 5)->run([config('saie.converters.ffmpeg'), '-y', '-i', $source, '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '23', '-c:a', 'aac', '-movflags', '+faststart', Storage::disk('local')->path($target)]);
            $derived['video']['transcoded'] = $run->successful() ? $target : null;
        }

        return $derived;
    }
}
