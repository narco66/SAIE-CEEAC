<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CampaignLanguage extends Model
{
    public $timestamps = false;

    protected $primaryKey = null;

    public $incrementing = false;

    /**
     * Les attributs sont alimentés exclusivement par des données validées côté contrôleur ou service.
     *
     * @var list<string>
     */
    protected $guarded = ['id'];
}
