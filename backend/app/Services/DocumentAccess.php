<?php

namespace App\Services;

use App\Models\Document;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Règles de visibilité documentaire (DOC-04, DATA-01, R01, R23) appliquées dans la requête SQL elle-même.
 */
class DocumentAccess
{
    public function __construct(private Access $access) {}

    /**
     * @param  Builder<Document>  $query
     * @return Builder<Document>
     */
    public function scope(Builder $query, User $user): Builder
    {
        if (! $this->access->can($user, 'documents.view')) {
            return $query->whereRaw('1 = 0');
        }
        $managed = $this->access->structureScope($user, 'documents.manage');
        $structureId = $user->structure_id;

        return $query->where(function (Builder $q) use ($managed, $user, $structureId): void {
            $q->where(function (Builder $readable) use ($structureId): void {
                $readable->where('status', 'published')->where(function (Builder $c) use ($structureId): void {
                    $c->where('classification', 'public')
                        ->orWhere(fn (Builder $i) => $i->where('classification', 'interne')->where(fn (Builder $a) => $a->whereDoesntHave('audiences')
                            ->orWhereHas('audiences', fn (Builder $s) => $s->where('structures.id', $structureId))));
                });
            })->orWhere('author_id', $user->id);
            if ($managed === null) {
                $q->orWhereRaw('true');
            } elseif ($managed !== []) {
                $q->orWhereIn('owner_structure_id', $managed);
            }
        })->where('status', '!=', 'trashed');
    }

    public function canView(Document $document, User $user): bool
    {
        return $this->scope(Document::query()->whereKey($document->id), $user)->exists();
    }

    public function canManage(Document $document, User $user): bool
    {
        $scope = $this->access->structureScope($user, 'documents.manage');

        return $this->access->can($user, 'documents.manage') && ($scope === null || in_array($document->owner_structure_id, $scope, true) || $document->author_id === $user->id);
    }
}
