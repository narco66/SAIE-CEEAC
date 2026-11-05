<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Diffusion par courriel d'un document publié (DOC-09). Les courriels partent par la file des notifications (NOT-02),
 * avec un lien authentifié et sans pièce jointe.
 */
class DocumentBroadcast extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

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
            'structure_ids' => 'array',
            'recipients_count' => 'integer',
            'excluded_count' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
