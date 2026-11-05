<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Content extends Model
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
        return [
            'media' => 'array',
            'qr' => 'array',
            'proposed_targets' => 'array',
            'delegation' => 'array',
            'valid_from' => 'datetime',
            'expires_at' => 'datetime',
            'archived_at' => 'datetime',
            'is_permanent' => 'boolean',
            'is_demo' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ContentCategory::class, 'category_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Structure::class, 'owner_structure_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }

    public function languages(): HasMany
    {
        return $this->hasMany(ContentLanguage::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ContentVersion::class);
    }

    public function occurrence(): BelongsTo
    {
        return $this->belongsTo(ReservationOccurrence::class, 'occurrence_id');
    }
}
