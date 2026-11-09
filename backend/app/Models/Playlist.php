<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Playlist extends Model
{
    use HasUuids;

    /**
     * Les attributs sont alimentés exclusivement par des données validées côté contrôleur.
     *
     * @var list<string>
     */
    protected $guarded = ['id'];

    /** @var array<string, string> */
    protected $attributes = ['status' => 'active'];

    /**
     * Contenus ordonnés de la liste, avec la durée propre à chaque élément.
     *
     * @return BelongsToMany<Content, $this>
     */
    public function contents(): BelongsToMany
    {
        return $this->belongsToMany(Content::class, 'playlist_items')->using(PlaylistItem::class)->withPivot(['id', 'position', 'duration_s'])->orderByPivot('position');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
