<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Database\Seeders\DemoScenariosSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Le jeu de démonstration complet (DemoSeeder puis DemoScenariosSeeder) se construit sur une base vierge, représente
 * chaque état métier et peut être rejoué sans rien modifier.
 */
class DemoScenariosSeederTest extends TestCase
{
    public function test_the_full_demo_dataset_covers_every_business_state_and_is_replayable(): void
    {
        $this->seed([DemoSeeder::class, DemoScenariosSeeder::class]);

        $expected = [
            'users' => ['active', 'disabled'],
            'reservation_occurrences' => ['pending', 'confirmed', 'refused', 'cancelled', 'in_progress', 'completed', 'no_show'],
            'service_requests' => ['requested', 'accepted', 'clarification', 'ready', 'impossible', 'cancelled'],
            'visits' => ['draft', 'planned', 'done', 'cancelled'],
            'campaigns' => ['scheduled', 'active', 'suspended', 'expired', 'archived', 'draft'],
            'alerts' => ['pending', 'active', 'revoked', 'expired'],
            'incidents' => ['open', 'acknowledged', 'resolved', 'closed'],
            'documents' => ['draft', 'published', 'unpublished', 'trashed'],
            'exports' => ['ready', 'failed', 'cancelled', 'expired'],
            'screens' => ['active', 'maintenance', 'inactive'],
            'devices' => ['active', 'revoked'],
            'rooms' => ['active', 'inactive'],
            'templates' => ['active', 'draft', 'archived'],
            'playlists' => ['active', 'archived'],
            'service_accounts' => ['active', 'revoked'],
            'player_releases' => ['active', 'withdrawn'],
        ];
        foreach ($expected as $table => $statuses) {
            $present = DB::table($table)->distinct()->pluck('status')->all();
            $this->assertEmpty(array_diff($statuses, $present), "$table : états manquants ".implode(', ', array_diff($statuses, $present)));
        }
        $this->assertDatabaseHas('campaigns', ['kind' => 'event', 'status' => 'scheduled']);
        $this->assertDatabaseHas('room_blocks', ['kind' => 'institutional']);
        $this->assertDatabaseHas('incidents', ['maintenance_type' => 'preventive']);
        $this->assertDatabaseHas('users', ['email' => 'olga.mintsa@demo.saie-ceeac.local', 'failed_logins' => 5]);
        $this->assertTrue(User::query()->where('email', 'herve.mba@demo.saie-ceeac.local')->where('failed_logins', 20)->where('locked_until', '>', now()->addYear())->exists());
        $this->assertDatabaseMissing('users', ['status' => 'suspended']);
        $this->assertDatabaseHas('users', ['email' => 'kevin.assoumou@demo.saie-ceeac.local', 'must_change_password' => true]);
        $this->assertNotNull(DB::table('users')->where('email', 'paul.nguema@demo.saie-ceeac.local')->value('mfa_enabled_at'));
        foreach (['document_broadcasts', 'document_reads', 'checklist_items', 'resource_bookings', 'maintenance_plans', 'dynamic_sources', 'source_snapshots', 'calendar_subscriptions', 'import_runs', 'device_screenshots', 'playlist_items'] as $table) {
            $this->assertGreaterThan(0, DB::table($table)->count(), "$table est vide");
        }
        $this->assertStringContainsString('Portail intranet', Storage::disk('local')->get('demo-accounts.txt'));

        $this->assertEveryScreenRendersTheDemoData();

        $before = DB::table('audit_logs')->count() + DB::table('reservations')->count();
        $this->seed(DemoScenariosSeeder::class);
        $this->assertSame($before, DB::table('audit_logs')->count() + DB::table('reservations')->count());
    }

    /**
     * Chaque liste et les fiches des nouveaux scénarios s'affichent pour chaque profil, sans erreur serveur.
     */
    private function assertEveryScreenRendersTheDemoData(): void
    {
        $lists = ['alerts', 'assets', 'audit-logs', 'campaigns', 'contents', 'dashboard', 'documents', 'dynamic-sources', 'exports', 'fallbacks', 'imports', 'incidents', 'maintenance-plans',
            'me/calendar-subscriptions', 'mobile-resources', 'notifications', 'player-releases', 'playlists', 'program', 'referentials', 'reservations', 'roles', 'rooms', 'rooms-calendar',
            'screen-groups', 'screens', 'service-accounts', 'service-requests', 'settings', 'supervision', 'tasks', 'templates', 'users', 'visits'];
        $details = [
            'screens/'.DB::table('screens')->where('code', 'ECR-5E-03')->value('id'),
            'screens/'.DB::table('screens')->where('code', 'ECR-4E-03')->value('id'),
            'reservations/'.DB::table('reservations')->where('subject', 'Atelier régional sur la libre circulation')->value('id'),
            'reservations/'.DB::table('reservations')->where('subject', 'Comité des œuvres sociales')->value('id'),
            'documents/'.DB::table('documents')->where('title', 'Annuaire téléphonique interne')->value('id'),
            'campaigns/'.DB::table('campaigns')->where('name', 'Collecte de sang — étages')->value('id'),
        ];
        foreach (['alain.mbeng', 'clarisse.ondo', 'mireille.tchibinda', 'patrick.obiang', 'jean.moussavou', 'sylvain.essono', 'didier.ella', 'sandra.bivigou', 'ruth.mabika'] as $login) {
            $user = User::query()->where('email', "$login@demo.saie-ceeac.local")->firstOrFail();
            foreach (array_merge($lists, $details) as $path) {
                $status = $this->actingAs($user)->getJson("/api/v1/$path")->status();
                $this->assertLessThan(500, $status, "$login → /api/v1/$path : erreur $status");
            }
        }
        preg_match('/jeton (\S+)/u', (string) Storage::disk('local')->get('demo-accounts.txt'), $token);
        Setting::put('integrations.api_enabled', true);
        $this->app['auth']->forgetGuards();
        $this->withToken($token[1])->getJson('/api/v1/integration/meetings')->assertOk();
    }
}
