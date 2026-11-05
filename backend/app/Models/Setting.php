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

    public static function put(string $key, mixed $value, ?string $userId = null, bool $secret = false): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value, 'updated_by' => $userId, 'is_secret' => $secret]);
        Cache::forget('saie.settings');
    }
}
