<?php

namespace Tests\Feature;

use App\Models\Screen;
use App\Services\ProductionReadiness;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Contrôles de mise en service (saie:preflight) : une configuration de production complète passe ; chaque écart bloquant
 * (débogage, HTTP, démonstration, antivirus factice, clé de signature absente) refuse la mise en service.
 */
class PreflightTest extends TestCase
{
    private ?Process $daemon = null;

    private string $keyPath;

    protected function setUp(): void
    {
        parent::setUp();
        $port = random_int(34000, 34999);
        $this->daemon = new Process([PHP_BINARY, base_path('tests/Fixtures/fake-clamd.php'), (string) $port, '5']);
        $this->daemon->start();
        usleep(400_000);
        $this->keyPath = tempnam(sys_get_temp_dir(), 'mk');
        file_put_contents($this->keyPath, 'clé de test');
        chmod($this->keyPath, 0600);
        config([
            'app.env' => 'production', 'app.debug' => false, 'app.url' => 'https://saie.ceeac-eccas.org', 'app.frontend_url' => 'https://saie.ceeac-eccas.org',
            'session.secure' => true, 'saie.demo_mode' => false, 'saie.security.mfa_enforced' => true,
            'saie.antivirus.driver' => 'clamd', 'saie.antivirus.clamd_host' => '127.0.0.1', 'saie.antivirus.clamd_port' => $port,
            'saie.device.signing_key_path' => $this->keyPath, 'saie.device.signing_key_id' => 'mk-2026-11',
            'mail.default' => 'smtp', 'queue.default' => 'redis', 'logging.channels.single.level' => 'warning', 'logging.channels.daily.level' => 'warning',
        ]);
        // Compte applicatif à moindre privilège, comme en production (infra/postgres/10-roles.sh) : le compte de test local
        // est propriétaire des tables ; le contrôle réel est vérifié séparément.
        $this->app->bind(ProductionReadiness::class, fn () => new class extends ProductionReadiness
        {
            protected function privilegeFacts(): array
            {
                return ['role' => 'saie_app', 'super' => false, 'owner' => false, 'tamper' => false];
            }
        });
    }

    public function test_application_connected_as_table_owner_blocks_the_go_live(): void
    {
        $check = collect((new ProductionReadiness)->checks())->firstWhere('name', 'Compte PostgreSQL à moindre privilège');

        $this->assertSame('fail', $check['status']);
        $this->assertStringContainsString('propriétaire des tables', $check['detail']);
    }

    protected function tearDown(): void
    {
        $this->daemon?->stop(0);
        @unlink($this->keyPath);
        parent::tearDown();
    }

    /**
     * @return array<string, string>
     */
    private function statuses(): array
    {
        return collect(app(ProductionReadiness::class)->checks())->pluck('status', 'name')->all();
    }

    public function test_complete_production_configuration_is_ready(): void
    {
        $statuses = $this->statuses();

        $this->assertNotContains('fail', $statuses, json_encode($statuses, JSON_UNESCAPED_UNICODE));
        $this->assertSame('ok', $statuses['Antivirus de production (clamd)']);
        $this->artisan('saie:preflight')->assertSuccessful();
    }

    public function test_each_unsafe_setting_blocks_the_go_live(): void
    {
        config(['app.debug' => true, 'app.url' => 'http://saie.ceeac-eccas.org', 'saie.antivirus.driver' => 'signature', 'saie.device.signing_key_path' => '/nulle-part/cle.pem']);
        $statuses = $this->statuses();

        $this->assertSame('fail', $statuses['Mode débogage désactivé']);
        $this->assertSame('fail', $statuses['Adresses en HTTPS']);
        $this->assertSame('fail', $statuses['Antivirus de production (clamd)']);
        $this->assertSame('fail', $statuses['Clé de signature des manifestes']);
        $this->artisan('saie:preflight')->assertFailed();
    }

    public function test_template_values_left_in_the_configuration_block_the_go_live(): void
    {
        config(['app.url' => 'https://À_FOURNIR', 'mail.mailers.smtp.host' => 'À_FOURNIR']);
        $check = collect(app(ProductionReadiness::class)->checks())->firstWhere('name', 'Aucune valeur de modèle restante');

        $this->assertSame('fail', $check['status']);
        $this->assertStringContainsString('APP_URL', $check['detail']);
        $this->assertStringContainsString('MAIL_HOST', $check['detail']);
    }

    public function test_demonstration_records_left_in_the_database_block_the_go_live(): void
    {
        $this->screen('ECR-DEMO', true, ['is_demo' => true]);

        $this->assertSame('fail', $this->statuses()['Aucune donnée de démonstration']);
        $this->assertSame(1, Screen::query()->where('is_demo', true)->count());
    }

    public function test_json_output_reports_readiness_for_deployment_pipelines(): void
    {
        $this->artisan('saie:preflight', ['--json' => true])->expectsOutputToContain('"ready": true')->assertSuccessful();
    }
}
