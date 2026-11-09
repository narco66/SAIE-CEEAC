<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * §17.6 : échange d'un lecteur avec le serveur (route, statut, durée, volume), écrit par le middleware LogDeviceExchange.
 */
class DeviceExchange extends Model
{
    public $timestamps = false;

    /**
     * Les attributs sont alimentés exclusivement par le middleware de journalisation.
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
            'occurred_at' => 'datetime',
        ];
    }
}
