<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeviceTelemetry extends Model
{
    protected $table = 'device_telemetry';

    public $timestamps = false;

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
            'payload' => 'array',
            'recorded_at' => 'datetime',
        ];
    }
}
