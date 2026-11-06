<?php

namespace App\Models;

use App\Models\Concerns\GuardsVersion;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ScreenGroup extends Model
{
    use GuardsVersion, HasUuids;

    /**
     * Les attributs sont alimentés exclusivement par des données validées côté contrôleur ou service.
     *
     * @var list<string>
     */
    protected $guarded = ['id'];

    /**
     * Version initiale (API-04), présente dès la réponse de création.
     *
     * @var array<string, int>
     */
    protected $attributes = ['version' => 1];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['operating_window' => 'array'];
    }

    public function screens(): BelongsToMany
    {
        return $this->belongsToMany(Screen::class, 'group_members', 'group_id', 'screen_id');
    }

    public function structure(): BelongsTo
    {
        return $this->belongsTo(Structure::class);
    }
}
