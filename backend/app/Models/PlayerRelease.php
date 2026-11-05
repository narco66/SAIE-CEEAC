<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Version publiée du lecteur sur un canal (PLY-12). L'empreinte porte sur le fichier index.html de la version.
 */
class PlayerRelease extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $guarded = ['id'];

    /**
     * Version cible d'un canal : la plus récente encore active.
     */
    public static function targetFor(string $channel): ?self
    {
        return static::query()->where('channel', $channel)->where('status', 'active')->orderByDesc('created_at')->first();
    }
}
