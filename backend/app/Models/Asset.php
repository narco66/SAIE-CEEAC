<?php

namespace App\Models;

use App\Models\Concerns\GuardsVersion;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Asset extends Model
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
        return [
            'trashed_at' => 'datetime',
            'is_demo' => 'boolean',
        ];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(AssetVersion::class);
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(AssetVersion::class, 'current_version_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Structure::class, 'owner_structure_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ContentCategory::class, 'category_id');
    }
}
