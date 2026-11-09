<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Élément d'une liste de lecture réutilisable (PUB-07).
 */
class PlaylistItem extends Pivot
{
    use HasUuids;

    protected $table = 'playlist_items';

    public $incrementing = false;

    public $timestamps = false;
}
