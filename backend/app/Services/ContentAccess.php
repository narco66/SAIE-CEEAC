<?php

namespace App\Services;

use App\Models\Content;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Visibilité des contenus (HAB-01, R01, R23) appliquée dans la requête SQL : structures du périmètre de l'utilisateur pour
 * la création, la validation ou la consultation, plus ses propres contenus. Partagée par la liste et la recherche globale.
 */
class ContentAccess
{
    public function __construct(private Access $access) {}

    /**
     * @param  Builder<Content>  $query
     * @return Builder<Content>
     */
    public function scope(Builder $query, User $user): Builder
    {
        $scopes = collect(['contents.create', 'contents.validate', 'contents.view'])->map(fn (string $permission) => $this->access->structureScope($user, $permission));
        if ($scopes->contains(fn ($scope) => $scope === null)) {
            return $query;
        }
        $ids = $scopes->flatten()->filter()->unique()->values()->all();

        return $query->where(fn (Builder $q) => $q->whereIn('owner_structure_id', $ids ?: ['00000000-0000-0000-0000-000000000000'])->orWhere('author_id', $user->id));
    }
}
