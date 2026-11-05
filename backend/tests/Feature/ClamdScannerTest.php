<?php

namespace Tests\Feature;

use App\Services\VirusScanner;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * DOC-02 : pilote antivirus de production (clamd, protocole INSTREAM) éprouvé contre un démon de test.
 */
class ClamdScannerTest extends TestCase
{
    public function test_clamd_driver_streams_file_and_interprets_verdict(): void
    {
        $port = random_int(33000, 33999);
        $daemon = new Process([PHP_BINARY, base_path('tests/Fixtures/fake-clamd.php'), (string) $port, '2']);
        $daemon->start();
        usleep(400_000);
        config(['saie.antivirus.driver' => 'clamd', 'saie.antivirus.clamd_host' => '127.0.0.1', 'saie.antivirus.clamd_port' => $port]);

        $clean = tempnam(sys_get_temp_dir(), 'av');
        file_put_contents($clean, str_repeat('Document institutionnel sain. ', 2000));
        $infected = tempnam(sys_get_temp_dir(), 'av');
        file_put_contents($infected, str_repeat('x', 20000).'X5O!P%@AP[4\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*');

        $scanner = app(VirusScanner::class);
        $ok = $scanner->scan($clean);
        $bad = $scanner->scan($infected);
        $daemon->wait();

        $this->assertTrue($ok['clean']);
        $this->assertSame('clamd', $ok['engine']);
        $this->assertFalse($bad['clean']);
        $this->assertStringContainsString('FOUND', $bad['detail']);
    }

    public function test_unreachable_clamd_fails_closed(): void
    {
        config(['saie.antivirus.driver' => 'clamd', 'saie.antivirus.clamd_host' => '127.0.0.1', 'saie.antivirus.clamd_port' => 1]);
        $file = tempnam(sys_get_temp_dir(), 'av');
        file_put_contents($file, 'contenu');
        $this->expectException(\RuntimeException::class);
        app(VirusScanner::class)->scan($file);
    }
}
