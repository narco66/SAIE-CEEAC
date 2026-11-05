<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

/**
 * Compte de service de l'API d'intégration (API-07) : portées minimales, jeton révocable, jamais d'accès utilisateur.
 */
class ServiceAccount extends Model
{
    use HasApiTokens, HasUuids;

    public const SCOPES = ['integration:meetings' => 'Réunions du jour autorisées', 'integration:screens' => 'État du parc', 'integration:announcements' => 'Annonces publiques diffusées'];

    /** @var list<string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['scopes' => 'array', 'last_used_at' => 'datetime'];
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
