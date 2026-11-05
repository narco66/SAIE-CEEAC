<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetVersion extends Model
{
    use HasUuids;

    /**
     * Les attributs sont alimentés exclusivement par des données validées côté contrôleur ou service.
     *
     * @var list<string>
     */
    protected $guarded = ['id'];

    /** @var list<string> */
    protected $hidden = ['text_content'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'subtitles' => 'array',
            'derivatives' => 'array',
            'processed_at' => 'datetime',
            'size' => 'integer',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
