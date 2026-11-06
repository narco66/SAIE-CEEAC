<?php

namespace Tests\Feature;

use App\Models\DeliveryAttempt;
use App\Models\Reservation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Sonde d'audit (hors dépôt) : panne SMTP après confirmation d'une réservation, file asynchrone comme en production. */
class AuditProbeSmtpTest extends TestCase
{
    public function test_smtp_outage_does_not_affect_confirmed_reservation(): void
    {
        config(['queue.default' => 'database', 'mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1, 'mail.mailers.smtp.timeout' => 2]);
        $agent = $this->userWith('agent', 'DRH');
        $room = $this->room();
        $day = CarbonImmutable::now('Africa/Libreville')->addDays(3)->setTime(10, 0);
        $r = $this->actingAs($agent)->postJson('/api/v1/reservations', ['room_id' => $room->id, 'start_at' => $day->toIso8601String(), 'end_at' => $day->addHour()->toIso8601String(), 'confidentiality' => false, 'subject' => 'Sonde SMTP', 'participant_count' => 3]);
        fwrite(STDERR, "creation_http={$r->status()}\n");
        fwrite(STDERR, 'jobs_en_file='.DB::table('jobs')->count()."\n");
        $code = $this->artisan('queue:work', ['--once' => true, '--queue' => 'default,notifications,mail', '--stop-when-empty' => true])->run();
        fwrite(STDERR, "worker_code=$code\n");
        foreach (DeliveryAttempt::query()->get() as $a) {
            fwrite(STDERR, "tentative canal={$a->channel} statut={$a->status} erreur=".mb_substr((string) $a->last_error, 0, 120)."\n");
        }
        fwrite(STDERR, 'jobs_restants='.DB::table('jobs')->count().' failed_jobs='.DB::table('failed_jobs')->count()."\n");
        $this->assertSame(201, $r->status());
        $this->assertSame('confirmed', Reservation::query()->first()->occurrences()->first()->status);
    }
}
