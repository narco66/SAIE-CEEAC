<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\AssetVersion;
use App\Services\MediaConverter;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * DOC-01 : une vidéo illisible par les lecteurs est diffusée dans sa dérivée H.264/AAC, ou refusée si elle ne peut pas être transcodée.
 */
class VideoDerivativeTest extends TestCase
{
    private function hevcVideo(): AssetVersion
    {
        $owner = $this->userWith('contributeur');
        $asset = Asset::query()->create(['reference' => 'MED-HEVC', 'title' => 'Clip', 'kind' => 'video', 'owner_structure_id' => $owner->structure_id, 'classification' => 'public', 'status' => 'ready']);
        Storage::disk('local')->put('assets/hevc/clip.mp4', 'original-hevc');

        return $asset->versions()->create([
            'version' => 1, 'storage_key' => 'assets/hevc/clip.mp4', 'original_name' => 'clip.mp4', 'extension' => 'mp4', 'size' => 13,
            'mime' => 'video/mp4', 'sha256' => hash('sha256', 'original-hevc'), 'scan_status' => 'ready',
        ]);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function tools(): array
    {
        $ffprobe = tempnam(sys_get_temp_dir(), 'ffprobe');
        $ffmpeg = tempnam(sys_get_temp_dir(), 'ffmpeg');
        config(['saie.converters.ffprobe' => $ffprobe, 'saie.converters.ffmpeg' => $ffmpeg]);

        return [$ffprobe, $ffmpeg];
    }

    public function test_transcoded_derivative_is_fingerprinted_and_served_to_players(): void
    {
        [$ffprobe] = $this->tools();
        Process::fake(function ($process) use ($ffprobe) {
            if ($process->command[0] === $ffprobe) {
                return Process::result(json_encode(['streams' => [['codec_type' => 'video', 'codec_name' => 'hevc'], ['codec_type' => 'audio', 'codec_name' => 'aac']], 'format' => ['duration' => '12.4']]));
            }
            file_put_contents(end($process->command), 'derivee-h264');

            return Process::result('');
        });
        $version = $this->hevcVideo();

        $derived = app(MediaConverter::class)->inspectVideo($version);
        $version->forceFill(['derivatives' => $derived])->save();

        $this->assertArrayNotHasKey('reject', $derived);
        $this->assertSame(hash('sha256', 'derivee-h264'), $version->broadcastFile()['sha256']);
        $this->assertSame('assets/hevc/derived/h264.mp4', $version->broadcastFile()['storage_key']);
    }

    public function test_unplayable_video_without_transcoder_is_rejected(): void
    {
        [$ffprobe] = $this->tools();
        config(['saie.converters.ffmpeg' => null]);
        Process::fake(fn () => Process::result(json_encode(['streams' => [['codec_type' => 'video', 'codec_name' => 'hevc']], 'format' => ['duration' => '5']])));

        $derived = app(MediaConverter::class)->inspectVideo($this->hevcVideo());

        $this->assertStringContainsString('hevc', $derived['reject']);
        $this->assertNotEmpty($ffprobe);
    }
}
