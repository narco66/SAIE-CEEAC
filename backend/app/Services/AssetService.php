<?php

namespace App\Services;

use App\Jobs\ProcessAssetVersion;
use App\Models\Asset;
use App\Models\AssetVersion;
use App\Models\User;
use App\Support\BusinessException;
use App\Support\Reference;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Dépôt en quarantaine dans le stockage privé. Aucun fichier n'est publiable avant l'état « prêt » (DOC-02).
 */
class AssetService
{
    public function __construct(private Audit $audit) {}

    /**
     * @param  array<string, mixed>  $meta
     */
    public function store(UploadedFile $file, array $meta, User $user, ?Asset $asset = null): AssetVersion
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $rule = config("saie.uploads.allowed.$extension");
        if (! $rule || $rule['kind'] === 'subtitle' && ($meta['purpose'] ?? null) !== 'subtitle') {
            throw new BusinessException('FILE_TYPE_REFUSED', 'Type de fichier non autorisé.', 422);
        }
        $kind = $rule['kind'] === 'subtitle' ? 'office' : $rule['kind'];
        $limitMb = $kind === 'video' ? config('saie.uploads.max_video_mb') : config('saie.uploads.max_document_mb');
        if ($file->getSize() > $limitMb * 1024 * 1024) {
            throw new BusinessException('FILE_TOO_LARGE', "Fichier trop volumineux (limite : $limitMb Mo).", 422);
        }
        if ($asset && $asset->kind !== $kind && $rule['kind'] !== 'subtitle') {
            throw new BusinessException('FILE_KIND_MISMATCH', 'Une nouvelle version doit conserver le même type de média.', 422);
        }
        $structureId = $asset?->owner_structure_id ?? ($meta['owner_structure_id'] ?? $user->structure_id);
        $this->assertQuota($structureId, (int) $file->getSize());

        return DB::transaction(function () use ($file, $meta, $user, $asset, $kind, $extension, $structureId): AssetVersion {
            $asset ??= Asset::query()->create([
                'reference' => Reference::next('MED'),
                'title' => $meta['title'] ?? pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
                'kind' => $kind, 'owner_structure_id' => $structureId, 'created_by' => $user->id,
                'classification' => $meta['classification'] ?? 'interne', 'language' => $meta['language'] ?? null,
                'category_id' => $meta['category_id'] ?? null, 'status' => 'quarantine',
            ]);
            $number = (int) $asset->versions()->max('version') + 1;
            $key = "assets/{$asset->id}/v{$number}/".Str::uuid().'.'.$extension;
            Storage::disk('local')->putFileAs(dirname($key), $file, basename($key));
            $version = $asset->versions()->create([
                'version' => $number, 'storage_key' => $key, 'original_name' => mb_substr($file->getClientOriginalName(), 0, 250),
                'declared_mime' => $file->getClientMimeType(), 'extension' => $extension, 'size' => $file->getSize(),
                'scan_status' => 'quarantine', 'created_by' => $user->id,
            ]);
            if (($meta['purpose'] ?? null) !== 'subtitle') {
                $asset->forceFill(['current_version_id' => $version->id, 'status' => 'quarantine'])->save();
            }
            $this->audit->log('asset.uploaded', $asset, version: $number, after: ['name' => $version->original_name, 'size' => $version->size]);
            DB::afterCommit(fn () => ProcessAssetVersion::dispatch($version->id));

            return $version;
        });
    }

    private function assertQuota(?string $structureId, int $size): void
    {
        $quota = $structureId ? DB::table('structures')->where('id', $structureId)->value('storage_quota_mb') : null;
        if (! $quota) {
            return;
        }
        $used = (int) AssetVersion::query()->whereHas('asset', fn ($q) => $q->where('owner_structure_id', $structureId))->sum('size');
        if ($used + $size > $quota * 1024 * 1024) {
            throw new BusinessException('QUOTA_EXCEEDED', 'Le quota de stockage de la structure est atteint.', 422);
        }
    }
}
