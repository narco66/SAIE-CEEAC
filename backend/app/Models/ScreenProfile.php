<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Profil de configuration réutilisable (§7.3), affecté à un écran ou à un groupe. Ses paramètres sont transmis au lecteur
 * dans le manifeste signé ; le lecteur rend compte de ceux qu'il applique et de ceux qu'il ne prend pas en charge.
 */
class ScreenProfile extends Model
{
    use HasUuids;

    /**
     * Paramètres reconnus : type, bornes et capacité du lecteur nécessaire à leur application.
     *
     * @var array<string, array{label: string, rules: list<string>, capability: string|null}>
     */
    public const SETTINGS = [
        'volume_max' => ['label' => 'Volume maximal (%)', 'rules' => ['integer', 'between:0,100'], 'capability' => null],
        'languages' => ['label' => 'Langues des vues non programmées', 'rules' => ['array', 'min:1', 'max:4'], 'capability' => null],
        'language_rotation_s' => ['label' => 'Rotation des langues (s)', 'rules' => ['integer', 'between:6,60'], 'capability' => null],
        'sync_interval_s' => ['label' => 'Intervalle de synchronisation (s)', 'rules' => ['integer', 'between:15,600'], 'capability' => null],
        'heartbeat_interval_s' => ['label' => 'Intervalle de remontée d’état (s)', 'rules' => ['integer', 'between:15,60'], 'capability' => null],
        'reconnect_max_s' => ['label' => 'Délai maximal entre deux tentatives de reconnexion (s)', 'rules' => ['integer', 'between:10,600'], 'capability' => null],
        'standby_outside_window' => ['label' => 'Mise en veille de la dalle hors plage', 'rules' => ['boolean'], 'capability' => 'panel_power'],
        'fullscreen' => ['label' => 'Plein écran', 'rules' => ['boolean'], 'capability' => null],
        'brightness' => ['label' => 'Luminosité (%)', 'rules' => ['integer', 'between:0,100'], 'capability' => 'panel_picture'],
        'contrast' => ['label' => 'Contraste (%)', 'rules' => ['integer', 'between:0,100'], 'capability' => 'panel_picture'],
        // §7.1-b : rotation logicielle de l'affichage quand l'orientation de l'écran diffère de celle de la sortie vidéo.
        'software_rotation' => ['label' => 'Rotation logicielle selon l’orientation', 'rules' => ['boolean'], 'capability' => null],
        // §7.1-f : mise en veille de la dalle après N minutes sans contenu programmé (repli seul), pendant la plage.
        'idle_standby_min' => ['label' => 'Veille après inactivité (min)', 'rules' => ['integer', 'between:1,240'], 'capability' => 'panel_power'],
        // §7.2-c : serveur de rattachement, choisi parmi les serveurs autorisés par la configuration.
        'server_url' => ['label' => 'Serveur de rattachement', 'rules' => ['string', 'max:200'], 'capability' => null],
    ];

    /**
     * Les attributs sont alimentés exclusivement par des données validées côté contrôleur.
     *
     * @var list<string>
     */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['settings' => 'array', 'is_active' => 'boolean'];
    }
}
