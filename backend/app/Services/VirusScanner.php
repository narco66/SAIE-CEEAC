<?php

namespace App\Services;

use RuntimeException;

/**
 * Analyse antivirus (DOC-02). Pilote « clamd » en production (protocole INSTREAM) ; pilote « signature » pour le
 * développement : il détecte uniquement la signature de test EICAR et l'indique explicitement dans le résultat.
 */
class VirusScanner
{
    private const EICAR = 'X5O!P%@AP[4\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';

    /**
     * @return array{clean: bool, engine: string, detail: string}
     */
    public function scan(string $path): array
    {
        // Échec sûr : en production, le pilote de démonstration ne laisse passer aucun fichier (le dépôt reste en quarantaine).
        if (config('saie.antivirus.driver') !== 'clamd' && app()->isProduction()) {
            throw new RuntimeException('Antivirus de production non configuré (SAIE_AV_DRIVER=clamd requis) : fichier conservé en quarantaine.');
        }

        return match (config('saie.antivirus.driver')) {
            'clamd' => $this->clamd($path),
            default => $this->signature($path),
        };
    }

    /**
     * @return array{clean: bool, engine: string, detail: string}
     */
    private function signature(string $path): array
    {
        $handle = fopen($path, 'rb');
        $infected = false;
        $previous = '';
        while (! feof($handle)) {
            $chunk = $previous.fread($handle, 1 << 20);
            if (str_contains($chunk, self::EICAR)) {
                $infected = true;
                break;
            }
            $previous = substr($chunk, -strlen(self::EICAR));
        }
        fclose($handle);

        return $infected
            ? ['clean' => false, 'engine' => 'signature-test', 'detail' => 'Signature EICAR détectée (fichier de test antivirus).']
            : ['clean' => true, 'engine' => 'signature-test', 'detail' => 'Analyse limitée à la signature de test : configurer clamd en production.'];
    }

    /**
     * @return array{clean: bool, engine: string, detail: string}
     */
    private function clamd(string $path): array
    {
        $socket = @fsockopen((string) config('saie.antivirus.clamd_host'), (int) config('saie.antivirus.clamd_port'), $errno, $error, 5);
        if (! $socket) {
            throw new RuntimeException("Antivirus clamd injoignable : $error");
        }
        stream_set_timeout($socket, 120);
        fwrite($socket, "zINSTREAM\0");
        $handle = fopen($path, 'rb');
        while (! feof($handle)) {
            $data = fread($handle, 8192);
            fwrite($socket, pack('N', strlen($data)).$data);
        }
        fclose($handle);
        fwrite($socket, pack('N', 0));
        $response = trim((string) fgets($socket));
        fclose($socket);

        return ['clean' => str_ends_with($response, 'OK'), 'engine' => 'clamd', 'detail' => $response];
    }
}
