<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

/**
 * Observabilité de l'API et des erreurs (ADM-02) : compteurs de requêtes par minute (volume, erreurs serveur, durée cumulée)
 * conservés deux heures dans le cache, et lecture des dernières erreurs du journal applicatif, sans les données des requêtes SQL.
 */
class OperationalMetrics
{
    private const TTL = 7200;

    /** Magasins où un compteur est atomique et sans coût notable ; ailleurs (cache en base), aucun compteur n'est écrit. */
    private const COUNTING_STORES = ['redis', 'memcached', 'array'];

    /**
     * Les compteurs ne sont tenus que sur un magasin adapté : avec le cache en base de données, chaque appel coûterait
     * plusieurs requêtes et des verrous sur les mêmes lignes à chaque minute.
     */
    public function enabled(): bool
    {
        return in_array(config('cache.stores.'.config('cache.default').'.driver'), self::COUNTING_STORES, true);
    }

    public function record(int $status, float $milliseconds): void
    {
        if (! $this->enabled()) {
            return;
        }
        $bucket = 'apim:'.now()->format('YmdHi');
        foreach (['n' => 1, 'e' => $status >= 500 ? 1 : 0, 'ms' => (int) round($milliseconds)] as $suffix => $value) {
            Cache::add("$bucket:$suffix", 0, self::TTL);
            if ($value > 0) {
                Cache::increment("$bucket:$suffix", $value);
            }
        }
    }

    /**
     * @return array{measured: bool, requests: int, server_errors: int, error_rate: float|null, mean_ms: int|null, window_minutes: int}
     */
    public function lastHour(): array
    {
        if (! $this->enabled()) {
            return ['measured' => false, 'requests' => 0, 'server_errors' => 0, 'error_rate' => null, 'mean_ms' => null, 'window_minutes' => 60];
        }
        $requests = $errors = $ms = 0;
        for ($i = 0; $i < 60; $i++) {
            $bucket = 'apim:'.now()->subMinutes($i)->format('YmdHi');
            $requests += (int) Cache::get("$bucket:n", 0);
            $errors += (int) Cache::get("$bucket:e", 0);
            $ms += (int) Cache::get("$bucket:ms", 0);
        }

        return ['measured' => true, 'requests' => $requests, 'server_errors' => $errors, 'error_rate' => $requests ? round(100 * $errors / $requests, 2) : null,
            'mean_ms' => $requests ? intdiv($ms, $requests) : null, 'window_minutes' => 60];
    }

    /**
     * Dernières erreurs du journal applicatif, des plus récentes aux plus anciennes. Le contexte technique (connexion, requête
     * SQL et valeurs liées, pile d'appels) est retiré : il peut contenir des données personnelles.
     *
     * @return list<array{at: string, level: string, message: string}>
     */
    public function recentErrors(int $limit = 50): array
    {
        $files = collect(File::glob(storage_path('logs/*.log')))->sortByDesc(fn (string $f) => filemtime($f))->take(2);
        $entries = [];
        foreach ($files as $file) {
            $handle = fopen($file, 'rb');
            $size = filesize($file);
            fseek($handle, max(0, $size - 1024 * 1024));
            $tail = (string) stream_get_contents($handle);
            fclose($handle);
            preg_match_all('/^\[(\d{4}-\d\d-\d\d \d\d:\d\d:\d\d)\] \w+\.(ERROR|CRITICAL|ALERT|EMERGENCY): (.*)$/m', $tail, $matches, PREG_SET_ORDER);
            foreach ($matches as [, $at, $level, $message]) {
                $entries[] = ['at' => $at, 'level' => strtolower($level), 'message' => self::sanitize($message)];
            }
        }
        usort($entries, fn ($a, $b) => strcmp($b['at'], $a['at']));

        return array_slice($entries, 0, $limit);
    }

    public static function sanitize(string $message): string
    {
        $message = preg_replace('/\s*\((Connection|SQL):.*$/s', '', $message) ?? '';
        $message = preg_replace('/\s*\{"(userId|exception)".*$/s', '', $message) ?? '';
        $message = preg_replace('/(password|token|secret|bearer)\S*\s*[=:]\s*\S+/i', '$1=[masqué]', $message) ?? '';
        $message = preg_replace('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', '[adresse masquée]', $message) ?? '';

        return mb_substr(trim($message), 0, 300);
    }
}
