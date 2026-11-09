<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Règles d'accès aux médias (DOC-04, DOC-07), partagées par la médiathèque et par AssetPolicy.
 */
class AssetAccess
{
    public function __construct(private Access $access) {}

    /**
     * Médias visibles :
     * - public et interne : tout titulaire de la consultation de la médiathèque ;
     * - restreint : son déposant, ou un gestionnaire de médias dont le périmètre couvre la structure propriétaire ;
     * - fichier d'un document : seulement ses gestionnaires ; les lecteurs passent par le téléchargement documentaire.
     *
     * @param  Builder<Asset>  $query
     * @return Builder<Asset>
     */
    public function scope(Builder $query, User $user): Builder
    {
        if (! $this->access->can($user, 'media.view')) {
            return $query->whereRaw('1 = 0');
        }
        $managed = $this->access->can($user, 'media.upload') ? $this->access->structureScope($user, 'media.upload') : [];
        $documentManaged = $this->access->can($user, 'documents.manage') ? $this->access->structureScope($user, 'documents.manage') : [];
        $none = ['00000000-0000-0000-0000-000000000000'];

        return $query
            ->where(fn ($q) => $q->whereIn('classification', ['public', 'interne'])
                ->orWhere('created_by', $user->id)
                ->when($managed === null, fn ($w) => $w->orWhereRaw('true'), fn ($w) => $w->orWhereIn('owner_structure_id', $managed ?: $none)))
            ->where(fn ($q) => $q->whereNotExists(fn ($d) => $d->select(DB::raw(1))->from('document_versions')->join('asset_versions', 'asset_versions.id', '=', 'document_versions.asset_version_id')->whereColumn('asset_versions.asset_id', 'assets.id'))
                ->orWhere('created_by', $user->id)
                ->when($documentManaged === null, fn ($w) => $w->orWhereRaw('true'), fn ($w) => $w->orWhereExists(fn ($d) => $d->select(DB::raw(1))->from('document_versions')
                    ->join('asset_versions', 'asset_versions.id', '=', 'document_versions.asset_version_id')->join('documents', 'documents.id', '=', 'document_versions.document_id')
                    ->whereColumn('asset_versions.asset_id', 'assets.id')->where(fn ($o) => $o->whereIn('documents.owner_structure_id', $documentManaged ?: $none)->orWhere('documents.author_id', $user->id)))));
    }

    public function canView(Asset $asset, User $user): bool
    {
        return $this->scope(Asset::query()->whereKey($asset->id), $user)->exists();
    }

    public function canEdit(Asset $asset, User $user): bool
    {
        $scope = $this->access->structureScope($user, 'media.upload');

        return $this->access->can($user, 'media.upload') && ($asset->created_by === $user->id || $scope === null || in_array($asset->owner_structure_id, $scope, true));
    }
}
