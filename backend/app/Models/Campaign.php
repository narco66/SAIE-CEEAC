<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Campaign extends Model
{
    use HasUuids;

    /**
     * Les attributs sont alimentés exclusivement par des données validées côté contrôleur ou service.
     *
     * @var list<string>
     */
    protected $guarded = ['id'];

    /**
     * Version initiale (API-04) : identique au défaut de la colonne, présente dès la réponse de création.
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
            'days' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'published_at' => 'datetime',
            'suspended_at' => 'datetime',
            'is_demo' => 'boolean',
            'priority' => 'integer',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(CampaignItem::class)->orderBy('position');
    }

    public function languages(): HasMany
    {
        return $this->hasMany(CampaignLanguage::class)->orderBy('rank');
    }

    public function targets(): HasMany
    {
        return $this->hasMany(CampaignTarget::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }
}
