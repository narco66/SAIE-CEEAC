<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Période d'un écran dans un état opérationnel (§10.1), ouverte au changement d'état et fermée au suivant (RG-014 :
 * la disponibilité se calcule sur ces périodes, issues des remontées réellement reçues).
 */
class ScreenStatusHistory extends Model
{
    protected $table = 'screen_status_history';

    public $timestamps = false;

    /**
     * Les attributs sont alimentés exclusivement par le planificateur de supervision.
     *
     * @var list<string>
     */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'ended_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Screen, $this>
     */
    public function screen(): BelongsTo
    {
        return $this->belongsTo(Screen::class);
    }
}
