<?php

namespace Tests\Unit;

use App\Services\ScheduleResolver;
use App\Support\Totp;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Règles de calendrier et de rotation (QUAL-01, PUB-05, PLY-08) — miroir de player/tests/schedule.test.ts.
 */
class ScheduleResolverTest extends TestCase
{
    private function entry(array $over): array
    {
        return array_merge(['entry_id' => 'e', 'campaign_id' => 'c', 'priority' => 50, 'position' => 1, 'rotation_rank' => 1, 'auth_class' => 'public', 'from' => '2026-11-02T07:00:00Z', 'to' => '2026-11-30T17:00:00Z', 'days' => [], 'time_from' => null, 'time_to' => null], $over);
    }

    private function manifest(array $schedule = [], array $alerts = [], array $extra = []): array
    {
        return array_merge(['timezone' => 'Africa/Libreville', 'operating_window' => ['days' => [1, 2, 3, 4, 5], 'on' => '08:00', 'off' => '18:00'], 'non_working_days' => [], 'authorization' => [], 'schedule' => $schedule, 'alerts' => $alerts], $extra);
    }

    public function test_deterministic_order_and_rotation_at_equal_priority(): void
    {
        $result = (new ScheduleResolver)->resolve($this->manifest([
            $this->entry(['entry_id' => 'low', 'priority' => 40]),
            $this->entry(['entry_id' => 'b-en', 'campaign_id' => 'b', 'priority' => 60, 'rotation_rank' => 2]),
            $this->entry(['entry_id' => 'b-fr', 'campaign_id' => 'b', 'priority' => 60]),
            $this->entry(['entry_id' => 'a-fr', 'campaign_id' => 'a', 'priority' => 60, 'from' => '2026-11-03T07:00:00Z']),
        ]), CarbonImmutable::parse('2026-11-04T09:00:00Z'));
        $this->assertSame('program', $result['mode']);
        $this->assertSame(['b-fr', 'b-en', 'a-fr'], array_column($result['playlist'], 'entry_id'));
    }

    public function test_event_mode_replaces_ordinary_program_whatever_its_priority_then_hands_back_automatically(): void
    {
        $manifest = $this->manifest([
            $this->entry(['entry_id' => 'ordinary-high', 'campaign_id' => 'ordinary', 'priority' => 90]),
            $this->entry(['entry_id' => 'summit-fr', 'campaign_id' => 'summit', 'priority' => 20, 'event' => true, 'from' => '2026-11-04T07:00:00Z', 'to' => '2026-11-06T17:00:00Z']),
            $this->entry(['entry_id' => 'summit-en', 'campaign_id' => 'summit', 'priority' => 20, 'event' => true, 'rotation_rank' => 2, 'from' => '2026-11-04T07:00:00Z', 'to' => '2026-11-06T17:00:00Z']),
        ]);
        $resolver = new ScheduleResolver;

        $during = $resolver->resolve($manifest, CarbonImmutable::parse('2026-11-05T09:00:00Z'));
        $this->assertSame(['summit-fr', 'summit-en'], array_column($during['playlist'], 'entry_id'));
        $this->assertStringStartsWith('Mode événement', $during['reason']);

        $after = $resolver->resolve($manifest, CarbonImmutable::parse('2026-11-09T09:00:00Z'));
        $this->assertSame(['ordinary-high'], array_column($after['playlist'], 'entry_id'));
    }

    public function test_alert_still_preempts_event_mode(): void
    {
        $manifest = $this->manifest(
            [$this->entry(['entry_id' => 'summit', 'event' => true])],
            [['id' => 'a', 'severity' => 'critical', 'starts_at' => '2026-11-04T08:55:00Z', 'expires_at' => '2026-11-04T09:25:00Z']],
        );
        $this->assertSame('alert', (new ScheduleResolver)->resolve($manifest, CarbonImmutable::parse('2026-11-04T09:00:00Z'))['mode']);
    }

    public function test_alert_preempts_and_expires(): void
    {
        $manifest = $this->manifest([$this->entry([])], [['id' => 'a', 'severity' => 'critical', 'starts_at' => '2026-11-04T08:55:00Z', 'expires_at' => '2026-11-04T09:25:00Z']]);
        $this->assertSame('alert', (new ScheduleResolver)->resolve($manifest, CarbonImmutable::parse('2026-11-04T09:00:00Z'))['mode']);
        $this->assertSame('program', (new ScheduleResolver)->resolve($manifest, CarbonImmutable::parse('2026-11-04T09:30:00Z'))['mode']);
    }

    public function test_campaign_from_10_00_to_10_30_starts_and_ends_at_the_minute(): void
    {
        $manifest = $this->manifest([$this->entry(['from' => '2026-11-04T09:00:00Z', 'to' => '2026-11-04T09:30:00Z'])]);
        $resolver = new ScheduleResolver;
        $this->assertSame('fallback', $resolver->resolve($manifest, CarbonImmutable::parse('2026-11-04T08:59:59Z'))['mode']);
        $this->assertSame('program', $resolver->resolve($manifest, CarbonImmutable::parse('2026-11-04T09:00:00Z'))['mode']);
        $this->assertSame('program', $resolver->resolve($manifest, CarbonImmutable::parse('2026-11-04T09:29:59Z'))['mode']);
        $this->assertSame('fallback', $resolver->resolve($manifest, CarbonImmutable::parse('2026-11-04T09:30:00Z'))['mode']);
    }

    public function test_operating_window_weekend_holiday_and_evening(): void
    {
        $resolver = new ScheduleResolver;
        $this->assertSame('off', $resolver->resolve($this->manifest(), CarbonImmutable::parse('2026-11-04T17:30:00Z'))['mode']);
        $this->assertSame('off', $resolver->resolve($this->manifest(), CarbonImmutable::parse('2026-11-07T09:00:00Z'))['mode']);
        $this->assertSame('off', $resolver->resolve($this->manifest([], [], ['non_working_days' => ['2026-11-04']]), CarbonImmutable::parse('2026-11-04T09:00:00Z'))['mode']);
    }

    public function test_totp_rfc6238_vector(): void
    {
        // Secret RFC 6238 « 12345678901234567890 » en base32, instant 59 s → 287082 (6 derniers chiffres de 94287082).
        $this->assertSame('287082', Totp::code('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 59));
    }
}
