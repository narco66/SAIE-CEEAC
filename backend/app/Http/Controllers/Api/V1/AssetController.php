<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\AssetVersion;
use App\Models\Content;
use App\Services\AssetAccess;
use App\Services\AssetService;
use App\Support\BusinessException;
use App\Support\Present;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Médiathèque (V11, V12) : quarantaine, analyse, versions et suivi des utilisations.
 */
class AssetController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission('media.view');
        $query = $this->visible(Asset::query())->with(['currentVersion', 'owner', 'creator', 'category'])
            ->where('status', '!=', 'trashed')
            ->when($request->query('kind'), fn ($q, $k) => $q->where('kind', $k))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('classification'), fn ($q, $c) => $q->where('classification', $c))
            ->when($request->query('q'), fn ($q, $t) => $q->where(fn ($w) => $w->where('title', 'ilike', "%$t%")->orWhere('reference', 'ilike', "%$t%")->orWhereHas('currentVersion', fn ($v) => $v->where('original_name', 'ilike', "%$t%"))));
        if ($request->boolean('ready_only')) {
            $query->where('status', 'ready');
        }
        $this->applySort($query, $request, ['created_at', 'title', 'updated_at'], 'created_at');

        return $this->paginated($query, $request, fn (Asset $a) => Present::asset($a, $this->usageCount($a)), 24);
    }

    public function show(Asset $asset): JsonResponse
    {
        $this->assertVisible($asset);
        $asset->load(['currentVersion', 'versions.asset', 'owner', 'creator', 'category']);
        $data = Present::asset($asset, $this->usageCount($asset));
        $data['versions'] = $asset->versions->sortByDesc('version')->map(fn ($v) => Present::assetVersion($v))->values();
        $data['usages_detail'] = $this->usages($asset);
        $data['can_edit'] = $this->canEdit($asset);

        return response()->json(['data' => $data]);
    }

    public function store(Request $request, AssetService $service): JsonResponse
    {
        $this->authorizePermission('media.upload');
        $max = (int) config('saie.uploads.max_video_mb') * 1024;
        $data = $request->validate([
            'file' => ['required', 'file', "max:$max"], 'title' => ['nullable', 'string', 'max:200'], 'classification' => ['required', 'in:public,interne,restreint'],
            'language' => ['nullable', 'in:fr,en,pt,es'], 'category_id' => ['nullable', 'uuid', 'exists:content_categories,id'], 'purpose' => ['nullable', 'in:media,subtitle'],
        ]);
        $version = $service->store($request->file('file'), $data, $this->user());

        return response()->json(['data' => Present::asset($version->asset->load(['currentVersion', 'owner', 'creator']), 0), 'version' => Present::assetVersion($version)], 201);
    }

    public function update(Request $request, Asset $asset): JsonResponse
    {
        $this->assertEditable($asset);
        $data = $request->validate(['title' => ['sometimes', 'string', 'max:200'], 'classification' => ['sometimes', 'in:public,interne,restreint'], 'language' => ['nullable', 'in:fr,en,pt,es'], 'category_id' => ['nullable', 'uuid', 'exists:content_categories,id']]);
        if (isset($data['classification']) && $data['classification'] !== $asset->classification && $this->usageCount($asset) > 0) {
            throw new BusinessException('ASSET_IN_USE', 'Ce média est utilisé : sa classification ne peut changer qu’après révision des contenus concernés.', 409, ['usages' => $this->usages($asset)]);
        }
        $asset->update($data);
        $this->audit()->log('asset.updated', $asset, after: $data);

        return $this->show($asset);
    }

    public function status(Asset $asset): JsonResponse
    {
        $this->assertVisible($asset);

        return response()->json(['data' => ['status' => $asset->status, 'version' => Present::assetVersion($asset->currentVersion)]]);
    }

    public function addVersion(Request $request, Asset $asset, AssetService $service): JsonResponse
    {
        $this->assertEditable($asset);
        $request->validate(['file' => ['required', 'file']]);
        $service->store($request->file('file'), [], $this->user(), $asset);

        return $this->show($asset->refresh());
    }

    /**
     * Suppression logique protégée : un média utilisé par un contenu ou une campagne ne peut pas être mis à la corbeille.
     */
    public function trash(Asset $asset): JsonResponse
    {
        $this->assertEditable($asset);
        $usages = $this->usages($asset);
        if ($usages !== []) {
            throw new BusinessException('ASSET_IN_USE', 'Ce média est utilisé et ne peut pas être supprimé.', 409, ['usages' => $usages]);
        }
        $asset->forceFill(['status' => 'trashed', 'trashed_at' => now()])->save();
        $this->audit()->log('asset.trashed', $asset);

        return response()->json(null, 204);
    }

    public function file(Request $request, AssetVersion $assetVersion): StreamedResponse
    {
        $this->assertVisible($assetVersion->asset);
        if ($assetVersion->scan_status !== 'ready') {
            throw new BusinessException('FILE_NOT_READY', 'Aperçu indisponible tant que l’analyse n’est pas saine.', 409);
        }
        $inline = in_array($assetVersion->asset->kind, ['image', 'video', 'pdf'], true) || $assetVersion->extension === 'vtt';

        return Storage::disk('local')->response($assetVersion->storage_key, $assetVersion->original_name, [
            'Content-Type' => $assetVersion->mime, 'Cache-Control' => 'private, max-age=300', 'X-Frame-Options' => 'SAMEORIGIN',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self' data:; media-src 'self'; frame-ancestors 'self'",
        ], $inline && ! $request->boolean('download') ? 'inline' : 'attachment');
    }

    /**
     * Copie PDF de consultation d'un fichier bureautique (DOC-01) ; l'original reste seul à faire foi.
     */
    public function consultation(AssetVersion $assetVersion): StreamedResponse
    {
        $this->assertVisible($assetVersion->asset);
        $key = $assetVersion->derivatives['consultation_pdf'] ?? null;
        abort_unless($assetVersion->scan_status === 'ready' && $key, 404);

        return Storage::disk('local')->response($key, pathinfo($assetVersion->original_name, PATHINFO_FILENAME).' (consultation).pdf', ['Content-Type' => 'application/pdf', 'Cache-Control' => 'private, max-age=300', 'X-Frame-Options' => 'SAMEORIGIN']);
    }

    public function thumbnail(AssetVersion $assetVersion): StreamedResponse
    {
        $this->assertVisible($assetVersion->asset);
        abort_unless($assetVersion->scan_status === 'ready' && $assetVersion->thumbnail_key, 404);

        return Storage::disk('local')->response($assetVersion->thumbnail_key, 'thumb.jpg', ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, max-age=3600']);
    }

    /**
     * @param  Builder<Asset>  $query
     * @return Builder<Asset>
     */
    /**
     * Médias visibles (DOC-04, DOC-07) :
     * - public et interne : tout titulaire de la consultation de la médiathèque ;
     * - restreint : son déposant, ou un gestionnaire de médias dont le périmètre couvre la structure propriétaire
     *   (la simple consultation ne suffit pas) ;
     * - fichier d'un document : seulement ses gestionnaires ; les lecteurs passent par le téléchargement documentaire,
     *   qui applique audience, statut, version courante et accusé de lecture.
     */
    private function visible(Builder $query): Builder
    {
        return app(AssetAccess::class)->scope($query, $this->user());
    }

    private function assertVisible(Asset $asset): void
    {
        if (Gate::forUser($this->user())->denies('view', $asset)) {
            abort(404);
        }
    }

    private function canEdit(Asset $asset): bool
    {
        return Gate::forUser($this->user())->allows('update', $asset);
    }

    private function assertEditable(Asset $asset): void
    {
        if (! $this->canEdit($asset)) {
            $this->deny('Média hors périmètre');
        }
    }

    private function usageCount(Asset $asset): int
    {
        return count($this->usages($asset));
    }

    /**
     * @return list<array<string, string>>
     */
    private function usages(Asset $asset): array
    {
        $versionIds = $asset->versions()->pluck('id')->all();
        if ($versionIds === []) {
            return [];
        }
        $contents = Content::query()->where('status', '!=', 'archived')->where(function ($q) use ($versionIds): void {
            foreach ($versionIds as $id) {
                $q->orWhereRaw('media @> ?::jsonb', [json_encode([['asset_version_id' => $id]])]);
            }
        })->get(['id', 'reference', 'title']);
        $documents = DB::table('document_versions')->join('documents', 'documents.id', '=', 'document_versions.document_id')
            ->whereIn('asset_version_id', $versionIds)->where('documents.status', '!=', 'trashed')->distinct()->get(['documents.id', 'documents.reference', 'documents.title']);

        return $contents->toBase()->map(fn ($c) => ['type' => 'content', 'id' => $c->id, 'reference' => $c->reference, 'title' => $c->title])
            ->merge($documents->map(fn ($d) => ['type' => 'document', 'id' => $d->id, 'reference' => $d->reference, 'title' => $d->title]))->values()->all();
    }
}
