<?php

namespace App\Support;

use App\Models\Holiday;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Jours ouvrés évalués dans le fuseau institutionnel, hors week-ends et jours non ouvrés du référentiel (HAB-04, PLY-08).
 */
class BusinessCalendar
{
    /** @var array<string, bool>|null */
    private static ?array $holidays = null;

    public static function flush(): void
    {
        self::$holidays = null;
    }

    public static function isBusinessDay(CarbonInterface $date): bool
    {
        $local = CarbonImmutable::instance($date)->setTimezone(config('saie.timezone'));
        self::$holidays ??= Holiday::query()->pluck('date')->mapWithKeys(fn ($d) => [$d->format('Y-m-d') => true])->all();

        return ! $local->isWeekend() && ! isset(self::$holidays[$local->format('Y-m-d')]);
    }

    public static function addBusinessDays(CarbonInterface $from, int $days): CarbonImmutable
    {
        $date = CarbonImmutable::instance($from);
        $added = 0;
        while ($added < $days) {
            $date = $date->addDay();
            if (self::isBusinessDay($date)) {
                $added++;
            }
        }

        return $date;
    }
}
