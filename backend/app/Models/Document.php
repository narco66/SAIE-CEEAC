<?php

namespace App\Models;

use App\Services\Audit;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Document extends Model
{
    use HasUuids;

    /**
     * Les attributs sont alimentés exclusivement par des données validées côté contrôleur ou service.
     *
     * @var list<string>
     */
    protected $guarded = ['id'];

    /** @var list<string> */
    protected $hidden = ['search_text', 'search_vector'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'keywords' => 'array',
            'valid_from' => 'date',
            'expires_at' => 'date',
            'published_at' => 'datetime',
            'unpublished_at' => 'datetime',
            'trashed_at' => 'datetime',
            'is_demo' => 'boolean',
        ];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class);
    }

    /**
     * Rend courante une version déclarée saine si elle est plus récente que la version servie (DOC-03) :
     * un document publié reste téléchargeable dans sa version précédente pendant l'analyse de la nouvelle.
     */
    public static function activateReadyVersion(AssetVersion $file): void
    {
        foreach (DocumentVersion::query()->with('document.currentVersion')->where('asset_version_id', $file->id)->get() as $candidate) {
            $document = $candidate->document;
            if ($document->current_version_id === $candidate->id || ($document->currentVersion && $document->currentVersion->version > $candidate->version)) {
                continue;
            }
            $document->forceFill(['current_version_id' => $candidate->id] + ($document->status === 'published' ? ['search_text' => $file->text_content] : []))->save();
            app(Audit::class)->log('document.version_activated', $document, 'Nouvelle version déclarée saine', version: $candidate->version, actor: null);
        }
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'current_version_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Structure::class, 'owner_structure_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ContentCategory::class, 'category_id');
    }

    public function audiences(): BelongsToMany
    {
        return $this->belongsToMany(Structure::class, 'document_audiences', 'document_id', 'structure_id');
    }

    public function reads(): HasMany
    {
        return $this->hasMany(DocumentRead::class);
    }
}
