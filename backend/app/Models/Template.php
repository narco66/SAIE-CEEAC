<?php

namespace App\Models;

use App\Models\Concerns\GuardsVersion;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Template extends Model
{
    use GuardsVersion, HasUuids;

    /**
     * Les attributs sont alimentés exclusivement par des données validées côté contrôleur ou service.
     *
     * @var list<string>
     */
    protected $guarded = ['id'];

    /**
     * Version initiale (API-04) et indicateur « institutionnel » : identiques aux défauts des colonnes, présents dès la
     * réponse de création (contrat OpenAPI : booléen, jamais null).
     *
     * @var array<string, int|bool>
     */
    protected $attributes = ['version' => 1, 'is_institutional' => false];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'layout' => 'array',
            'is_institutional' => 'boolean',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
