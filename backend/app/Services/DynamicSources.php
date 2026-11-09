<?php

namespace App\Services;

use App\Models\DynamicSource;
use App\Models\SourceSnapshot;
use App\Support\BusinessException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Composants dynamiques (PUB-10) : seul le serveur interroge les sources inscrites en liste blanche ; le résultat est mis
 * en cache puis intégré au manifeste. Garde-fous SSRF (SEC-02) : HTTPS uniquement, aucune redirection, hôtes privés refusés,
 * taille et durée bornées.
 */
class DynamicSources
{
    public const TYPES = ['clock', 'countdown', 'rss', 'json', 'weather'];

    private const MAX_BYTES = 1_048_576;

    public function __construct(private Audit $audit) {}

    /**
     * @return array<string, mixed>
     */
    public function fetch(DynamicSource $source): array
    {
        try {
            $payload = match ($source->type) {
                'clock' => ['kind' => 'clock'],
                'countdown' => ['kind' => 'countdown', 'label' => (string) Arr::get($source->config, 'label', ''), 'target' => (string) Arr::get($source->config, 'target', '')],
                'rss' => ['kind' => 'news', 'items' => $this->rss($this->download($source))],
                'json' => ['kind' => 'news', 'items' => $this->jsonItems($source, $this->download($source))],
                'weather' => $this->weather($source, $this->download($source)),
                default => throw new BusinessException('SOURCE_TYPE', 'Type de source inconnu.', 422),
            };
            $status = 'ok';
        } catch (Throwable $e) {
            $payload = ['error' => mb_substr($e->getMessage(), 0, 200)];
            $status = 'error';
        }
        SourceSnapshot::query()->create(['source_id' => $source->id, 'fetched_at' => now(), 'payload' => $payload, 'status' => $status]);
        $source->forceFill(['last_fetched_at' => now(), 'last_status' => $status])->save();
        SourceSnapshot::query()->where('source_id', $source->id)->where('fetched_at', '<', now()->subDays(2))->delete();

        return ['status' => $status, 'payload' => $payload];
    }

    /**
     * Sources actives dont la fréquence de collecte est échue.
     */
    public function collectDue(): int
    {
        $count = 0;
        foreach (DynamicSource::query()->where('is_active', true)->get() as $source) {
            if (! $source->last_fetched_at || $source->last_fetched_at->addMinutes($source->frequency_minutes)->isPast()) {
                $this->fetch($source);
                $count++;
            }
        }

        return $count;
    }

    /**
     * Dernier relevé valide de chaque source active, pour le manifeste.
     *
     * @return array<string, array<string, mixed>>
     */
    public function forManifest(): array
    {
        $out = [];
        foreach (DynamicSource::query()->where('is_active', true)->orderBy('code')->get() as $source) {
            $snapshot = SourceSnapshot::query()->where('source_id', $source->id)->where('status', 'ok')->orderByDesc('fetched_at')->first();
            if ($snapshot) {
                $out[$source->code] = ['type' => $source->type, 'name' => $source->name, 'fetched_at' => $snapshot->fetched_at->utc()->toIso8601ZuluString()] + $snapshot->payload;
            }
        }

        return $out;
    }

    /**
     * @return list<string> adresses IP vérifiées (toutes publiques)
     */
    public function assertSafeUrl(string $url): array
    {
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new BusinessException('SOURCE_URL_REFUSED', 'Seules les adresses HTTPS publiques sont autorisées.', 422);
        }
        $ips = gethostbynamel($parts['host']) ?: [];
        if ($ips === []) {
            throw new BusinessException('SOURCE_URL_REFUSED', 'Hôte introuvable.', 422);
        }
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new BusinessException('SOURCE_URL_REFUSED', 'Adresse interne ou réservée refusée (protection SSRF).', 422);
            }
        }

        return array_values($ips);
    }

    private function download(DynamicSource $source): string
    {
        $ips = $this->assertSafeUrl((string) $source->url);
        $parts = parse_url((string) $source->url);
        // SEC-02 (SSRF) : la connexion est figée sur l'adresse vérifiée (pas de seconde résolution DNS exploitable par rebinding),
        // et le transfert est interrompu dès qu'il dépasse la taille maximale, sans attendre la fin du téléchargement.
        $options = [
            'curl' => [CURLOPT_RESOLVE => [$parts['host'].':'.($parts['port'] ?? 443).':'.$ips[0]]],
            'progress' => function (int $expected, int $downloaded): void {
                if ($expected > self::MAX_BYTES || $downloaded > self::MAX_BYTES) {
                    throw new \RuntimeException('Réponse trop volumineuse');
                }
            },
        ];
        $response = Http::timeout(10)->connectTimeout(5)->withoutRedirecting()->withOptions($options)->withHeaders(['User-Agent' => 'SAIE-CEEAC/1.0'])->get($source->url);
        if (! $response->successful()) {
            throw new \RuntimeException('Réponse HTTP '.$response->status());
        }
        $body = $response->body();
        if (strlen($body) > self::MAX_BYTES) {
            throw new \RuntimeException('Réponse trop volumineuse');
        }

        return $body;
    }

    /**
     * @return list<array{title: string, date: string|null}>
     */
    private function rss(string $xml): array
    {
        $previous = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_use_internal_errors($previous);
        if (! $doc) {
            throw new \RuntimeException('Flux RSS illisible');
        }
        $items = $doc->channel->item ?? $doc->entry ?? [];
        $out = [];
        foreach ($items as $item) {
            $out[] = ['title' => mb_substr(trim(strip_tags((string) $item->title)), 0, 160), 'date' => ((string) ($item->pubDate ?? $item->updated ?? '')) ?: null];
            if (count($out) >= 10) {
                break;
            }
        }

        return $out;
    }

    /**
     * @return list<array{title: string, date: string|null}>
     */
    private function jsonItems(DynamicSource $source, string $body): array
    {
        $data = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        $items = Arr::get($data, (string) Arr::get($source->config, 'items_path', 'items'), []);

        return collect(is_array($items) ? $items : [])->take(10)->map(fn ($i) => [
            'title' => mb_substr(trim(strip_tags((string) Arr::get($i, (string) Arr::get($source->config, 'title_field', 'title'), ''))), 0, 160),
            'date' => Arr::get($i, (string) Arr::get($source->config, 'date_field', 'date')),
        ])->filter(fn ($i) => $i['title'] !== '')->values()->all();
    }

    /**
     * Météo : réponse JSON d'un service type Open-Meteo, champs configurables.
     *
     * @return array<string, mixed>
     */
    private function weather(DynamicSource $source, string $body): array
    {
        $data = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        $temperature = Arr::get($data, (string) Arr::get($source->config, 'temperature_path', 'current.temperature_2m'));
        if (! is_numeric($temperature)) {
            throw new \RuntimeException('Température absente de la réponse');
        }

        return ['kind' => 'weather', 'place' => (string) Arr::get($source->config, 'place', 'Libreville'), 'temperature' => round((float) $temperature), 'unit' => '°C'];
    }
}
