<?php

namespace Tests\Feature;

use App\Models\ReservationOccurrence;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;
use Tests\TestCase;

/** Sonde d'audit (hors dépôt) : deux insertions concurrentes sur deux connexions PostgreSQL réelles ; une seule doit réussir. */
class AuditProbeConcurrencyTest extends TestCase
{
    /** Pas de transaction englobante : les données doivent être visibles des autres connexions. */
    public function refreshDatabase(): void {}

    public function test_two_concurrent_bookings_only_one_succeeds(): void
    {
        $agent = $this->userWith('agent', 'DRH');
        $room = $this->room();
        $day = CarbonImmutable::now('Africa/Libreville')->addDays(5)->setTime(9, 0);
        $base = $this->actingAs($agent)->postJson('/api/v1/reservations', ['room_id' => $room->id, 'start_at' => $day->toIso8601String(), 'end_at' => $day->addHour()->toIso8601String(), 'confidentiality' => false, 'subject' => 'Sonde concurrence', 'participant_count' => 3])->assertCreated();
        $occ = ReservationOccurrence::query()->findOrFail($base->json('data.occurrences.0.id'));
        $cfg = config('database.connections.pgsql');
        $pdo = fn () => new PDO("pgsql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['database']}", $cfg['username'], $cfg['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $a = $pdo();
        $b = $pdo();
        $cols = collect(DB::select("select column_name from information_schema.columns where table_name = 'reservation_occurrences' and column_name not in ('id','starts_at','ends_at','blocking_start','blocking_end','check_in_code','sequence')"))->pluck('column_name')->implode(', ');
        $slot = fn (PDO $c, string $id) => $c->exec("insert into reservation_occurrences (id, starts_at, ends_at, blocking_start, blocking_end, sequence, $cols) select '$id', starts_at + interval '1 day', ends_at + interval '1 day', blocking_start + interval '1 day', blocking_end + interval '1 day', 99, $cols from reservation_occurrences where id = '{$occ->id}'");
        $a->beginTransaction();
        $b->beginTransaction();
        $b->exec("set local lock_timeout = '2s'");
        $slot($a, (string) \Illuminate\Support\Str::uuid());
        $blocked = 'non';
        try {
            $slot($b, (string) \Illuminate\Support\Str::uuid());
        } catch (PDOException $e) {
            $blocked = 'oui ('.$e->getCode().')';
        }
        $b->rollBack();
        $a->commit();
        $b->beginTransaction();
        $second = 'acceptée';
        try {
            $slot($b, (string) \Illuminate\Support\Str::uuid());
            $b->commit();
        } catch (PDOException $e) {
            $second = 'refusée SQLSTATE '.$e->getCode().' '.mb_substr($e->getMessage(), 0, 90);
            $b->rollBack();
        }
        fwrite(STDERR, "B bloquée pendant la transaction de A : $blocked\nB après validation de A : $second\n");
        $count = DB::table('reservation_occurrences')->where('room_id', $room->id)->count();
        fwrite(STDERR, "occurrences dans la salle : $count (attendu 2 : base + A)\n");
        DB::table('reservation_occurrences')->where('room_id', $room->id)->delete();
        DB::table('reservations')->where('id', $occ->reservation_id)->delete();
        $this->assertSame(2, $count);
        $this->assertStringStartsWith('refusée SQLSTATE 23P01', $second);
    }
}
