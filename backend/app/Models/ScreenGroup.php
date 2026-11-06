<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ScreenGroup extends Model
{
    use HasUuids;

    /**
     * Les attributs sont alimentés exclusivement par des données validées côté contrôleur ou service.
     *
     * @var list<string>
     */
    protected $guarded = ['id'];

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
