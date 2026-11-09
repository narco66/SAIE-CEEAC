<?php

namespace App\Policies;

use App\Models\Asset;
use App\Models\User;
use App\Services\AssetAccess;

/**
 * SEC-02 : droits sur un média de la médiathèque et sur ses fichiers (DOC-04, DOC-07).
 */
class AssetPolicy
{
    public function __construct(private AssetAccess $assets) {}

    public function view(User $user, Asset $asset): bool
    {
        return $this->assets->canView($asset, $user);
    }

    public function update(User $user, Asset $asset): bool
    {
        return $this->assets->canEdit($asset, $user);
    }
}
