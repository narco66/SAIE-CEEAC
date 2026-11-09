<?php

namespace App\Services;

use App\Models\AssetVersion;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser;

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

        $key = dirname($version->storage_key).'/derived/'.basename($pdf);
        $pages = rescue(fn () => count((new Parser)->parseFile($pdf)->getPages()), null, report: false);

        // PUB-09 : la copie PDF, empreintée comme un original, est le fichier diffusé à l'écran (présentation affichée page par page).
        return ['consultation_pdf' => $key, 'broadcast_pdf' => ['storage_key' => $key, 'sha256' => hash_file('sha256', $pdf), 'size' => filesize($pdf), 'pages' => $pages],
            'note' => 'Copie de consultation : ne vaut pas original signé.'];
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
            $run = Process::timeout(self::TIMEOUT * 5)->run([config('saie.converters.ffmpeg'), '-y', '-protocol_whitelist', 'file', '-i', $source, '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '23', '-c:a', 'aac', '-movflags', '+faststart', Storage::disk('local')->path($target)]);
            $path = Storage::disk('local')->path($target);
            // La dérivée diffusée est empreintée comme un original : le lecteur la vérifie avant de l'afficher.
            $derived['video']['transcoded'] = $run->successful() && is_file($path)
                ? ['storage_key' => $target, 'sha256' => hash_file('sha256', $path), 'size' => filesize($path), 'mime' => 'video/mp4']
                : null;
        }
        // Vidéo illisible par les lecteurs et non transcodée : refusée plutôt que diffusée en écran noir.
        if (! $compatible && empty($derived['video']['transcoded'])) {
            $derived['reject'] = 'Vidéo '.($codecs['video'] ?? 'de codec inconnu').' non lisible par les lecteurs et non transcodée : déposez-la en H.264/AAC (MP4).';
        }

        return $derived;
    }
}
