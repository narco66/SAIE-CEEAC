<?php

namespace App\Services;

use OpenSSLAsymmetricKey;
use RuntimeException;

/**
 * Signature Ed25519 des manifestes (PLY-03). La clé privée reste sur le serveur ; les lecteurs reçoivent la clé publique à l'appairage.
 */
class ManifestSigner
{
    private ?OpenSSLAsymmetricKey $key = null;

    public function sign(string $payload): string
    {
        if (! openssl_sign($payload, $signature, $this->privateKey(), 0)) {
            throw new RuntimeException('Signature du manifeste impossible.');
        }

        return base64_encode($signature);
    }

    public function verify(string $payload, string $signature): bool
    {
        return openssl_verify($payload, base64_decode($signature), $this->publicKeyPem(), 0) === 1;
    }

    /**
     * Clés de confiance annoncées aux lecteurs : la clé courante et, pendant une rotation, la clé suivante.
     *
     * @return list<array{key_id: string, public_key: string}>
     */
    public function trustedKeys(): array
    {
        $keys = [['key_id' => $this->keyId(), 'public_key' => $this->publicKeyRaw()]];
        $nextId = config('saie.device.next_signing_key_id');
        $nextKey = config('saie.device.next_signing_key_public');
        if (filled($nextId) && filled($nextKey) && $nextId !== $this->keyId() && strlen((string) base64_decode((string) $nextKey, true)) === 32) {
            $keys[] = ['key_id' => (string) $nextId, 'public_key' => (string) $nextKey];
        }

        return $keys;
    }

    public function keyId(): string
    {
        return (string) config('saie.device.signing_key_id');
    }

    /**
     * Clé publique brute (32 octets) encodée en base64, utilisée par le lecteur (tweetnacl).
     */
    public function publicKeyRaw(): string
    {
        $pem = $this->publicKeyPem();
        $der = base64_decode(preg_replace('/-----[^-]+-----|\s/', '', $pem));

        return base64_encode(substr($der, -32));
    }

    public function publicKeyPem(): string
    {
        return openssl_pkey_get_details($this->privateKey())['key'];
    }

    private function privateKey(): OpenSSLAsymmetricKey
    {
        if ($this->key) {
            return $this->key;
        }
        $path = (string) config('saie.device.signing_key_path');
        if (! is_file($path)) {
            if (app()->isProduction()) {
                throw new RuntimeException("Clé de signature absente : $path (voir docs/deploiement.md).");
            }
            @mkdir(dirname($path), 0700, true);
            $generated = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_ED25519]);
            openssl_pkey_export($generated, $pem);
            file_put_contents($path, $pem);
            @chmod($path, 0600);
        }
        $key = openssl_pkey_get_private((string) file_get_contents($path));
        if (! $key) {
            throw new RuntimeException('Clé de signature illisible.');
        }

        return $this->key = $key;
    }
}
