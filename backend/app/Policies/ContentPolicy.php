<?php

namespace App\Policies;

use App\Models\Content;
use App\Models\User;
use App\Services\Access;
use App\Services\ContentAccess;

/**
 * SEC-02 : droits sur un contenu (périmètre de structure, auteur, statut).
 */
class ContentPolicy
{
    public function __construct(private Access $access, private ContentAccess $contents) {}

    public function view(User $user, Content $content): bool
    {
        return $this->access->can($user, 'contents.view') && $this->contents->scope(Content::query()->whereKey($content->id), $user)->exists();
    }

    public function update(User $user, Content $content): bool
    {
        if (in_array($content->status, ['archived', 'expired'], true)) {
            return false;
        }
        if ($content->author_id === $user->id) {
            return true;
        }
        $scope = $this->access->structureScope($user, 'contents.create');

        return $this->access->can($user, 'contents.create') && ($scope === null || in_array($content->owner_structure_id, $scope, true));
    }
}
