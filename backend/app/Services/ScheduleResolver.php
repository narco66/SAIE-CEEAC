<?php

namespace App\Services;

use Carbon\CarbonImmutable;

/**
 * Résolution déterministe du programme à un instant donné (PUB-05) — même algorithme que le lecteur (player/src/schedule.ts) :
 * 1. alerte active autorisée ; 2. priorité décroissante ; 3. date de début ; 4. identifiant ; rotation ordonnée à priorité égale.
 */
class ScheduleResolver
{
    /**
     * @param  array<string, mixed>  $manifest  Contenu du manifeste (champ « payload » décodé)
     * @return array{mode: string, alerts: list<array<string, mixed>>, playlist: list<array<string, mixed>>, reason: string}
     */
    public function resolve(array $manifest, CarbonImmutable $at): array
    {
        $tz = $manifest['timezone'] ?? config('saie.timezone');
        $local = $at->setTimezone($tz);

        if (! $this->withinOperatingWindow($manifest['operating_window'] ?? null, $manifest['non_working_days'] ?? [], $local)) {
            return ['mode' => 'off', 'alerts' => [], 'playlist' => [], 'reason' => 'Hors plage de fonctionnement'];
        }

        $alerts = array_values(array_filter($manifest['alerts'] ?? [], fn (array $a): bool => CarbonImmutable::parse($a['starts_at']) <= $at && CarbonImmutable::parse($a['expires_at']) > $at));
        if ($alerts !== []) {
            $rank = ['critical' => 0, 'warning' => 1, 'exercise' => 2, 'info' => 3];
            usort($alerts, fn ($a, $b) => [$rank[$a['severity']] ?? 9, $a['starts_at'], $a['id']] <=> [$rank[$b['severity']] ?? 9, $b['starts_at'], $b['id']]);

            return ['mode' => 'alert', 'alerts' => $alerts, 'playlist' => [], 'reason' => 'Alerte active prioritaire'];
        }

        $authorization = $manifest['authorization'] ?? [];
        $eligible = array_values(array_filter($manifest['schedule'] ?? [], function (array $entry) use ($at, $local, $authorization): bool {
            $until = $authorization[$entry['auth_class'] ?? 'public'] ?? null;
            if ($until !== null && CarbonImmutable::parse($until) <= $at) {
                return false;
            }
            if (CarbonImmutable::parse($entry['from']) > $at || CarbonImmutable::parse($entry['to']) <= $at) {
                return false;
            }
            if (! empty($entry['days']) && ! in_array((int) $local->isoWeekday(), array_map('intval', $entry['days']), true)) {
                return false;
            }
            $time = $local->format('H:i');
            if (! empty($entry['time_from']) && $time < substr($entry['time_from'], 0, 5)) {
                return false;
            }

            return empty($entry['time_to']) || $time < substr($entry['time_to'], 0, 5);
        }));

        if ($eligible === []) {
            return ['mode' => 'fallback', 'alerts' => [], 'playlist' => [], 'reason' => 'Aucun programme valide : contenu de repli'];
        }

        usort($eligible, fn ($a, $b) => [-$a['priority'], $a['from'], $a['campaign_id'], $a['position'], $a['rotation_rank']]
            <=> [-$b['priority'], $b['from'], $b['campaign_id'], $b['position'], $b['rotation_rank']]);
        $top = $eligible[0]['priority'];
        $playlist = array_values(array_filter($eligible, fn (array $e): bool => $e['priority'] === $top));

        return ['mode' => 'program', 'alerts' => [], 'playlist' => $playlist, 'reason' => 'Priorité '.$top.' · rotation de '.count($playlist).' diapositive(s)'];
    }

    /**
     * @param  array<string, mixed>|null  $window
     * @param  list<string>  $nonWorkingDays
     */
    public function withinOperatingWindow(?array $window, array $nonWorkingDays, CarbonImmutable $local): bool
    {
        if (! $window) {
            return true;
        }
        if (in_array($local->format('Y-m-d'), $nonWorkingDays, true) && empty($window['ignore_holidays'])) {
            return false;
        }
        if (! in_array((int) $local->isoWeekday(), array_map('intval', $window['days'] ?? [1, 2, 3, 4, 5]), true)) {
            return false;
        }
        $time = $local->format('H:i');

        return $time >= $window['on'] && $time < $window['off'];
    }
}
