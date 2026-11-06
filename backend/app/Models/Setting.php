<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Paramètres applicatifs administrés dans V39. Les secrets ne sont jamais renvoyés en lecture.
 */
class Setting extends Model
{
    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['value' => 'array', 'is_secret' => 'boolean'];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $all = Cache::remember('saie.settings', 60, fn (): array => static::query()->pluck('value', 'key')->all());

        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    /**
     * Valeurs par défaut des paramètres de V39, utilisées tant qu'aucune valeur n'a été enregistrée.
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'identity.institution_name' => 'Commission de la CEEAC',
            'display.tagline' => 'Communauté Économique des États de l’Afrique Centrale',
            'display.default_slide_s' => 12,
            'operating.default_window' => config('saie.operating_window'),
            'operating.audio_max_volume' => 40,
            'rooms.pending_hold_hours' => 24,
            'rooms.no_show_auto' => false,
            'channels.smtp_enabled' => true,
            'channels.push_enabled' => false,
            'integrations.api_enabled' => false,
        ];
    }

    /**
     * Valeur en vigueur : valeur enregistrée, sinon valeur par défaut.
     */
    public static function effective(string $key): mixed
    {
        return static::get($key, static::defaults()[$key] ?? null);
    }

    public static function put(string $key, mixed $value, ?string $userId = null, bool $secret = false): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value, 'updated_by' => $userId, 'is_secret' => $secret]);
        Cache::forget('saie.settings');
    }
}
