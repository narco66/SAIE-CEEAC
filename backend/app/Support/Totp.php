<?php

namespace App\Support;

/**
 * TOTP RFC 6238 (SHA-1, 30 s, 6 chiffres) pour le second facteur des comptes à privilèges (SEC-01).
 */
class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(int $bytes = 20): string
    {
        $data = random_bytes($bytes);
        $bits = '';
        foreach (str_split($data) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }
        $secret = '';
        foreach (str_split($bits, 5) as $chunk) {
            $secret .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $secret;
    }

    public static function code(string $secret, ?int $timestamp = null): string
    {
        $counter = intdiv($timestamp ?? time(), 30);
        $hash = hash_hmac('sha1', pack('N*', 0, $counter), self::decode($secret), true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);

        return str_pad((string) ($value % 1_000_000), 6, '0', STR_PAD_LEFT);
    }

    public static function verify(string $secret, string $code, int $window = 1): bool
    {
        return self::step($secret, $code, $window) !== null;
    }

    /**
     * Pas de temps (compteur de 30 s) auquel correspond le code, ou null ; sert à refuser le rejeu d'un code déjà accepté.
     */
    public static function step(string $secret, string $code, int $window = 1): ?int
    {
        $code = preg_replace('/\D/', '', $code);
        for ($i = -$window; $i <= $window; $i++) {
            $timestamp = time() + $i * 30;
            if (hash_equals(self::code($secret, $timestamp), $code)) {
                return intdiv($timestamp, 30);
            }
        }

        return null;
    }

    public static function uri(string $secret, string $account): string
    {
        return 'otpauth://totp/'.rawurlencode('SAIE-CEEAC:'.$account).'?secret='.$secret.'&issuer=SAIE-CEEAC&digits=6&period=30';
    }

    private static function decode(string $secret): string
    {
        $bits = '';
        foreach (str_split(strtoupper($secret)) as $char) {
            $position = strpos(self::ALPHABET, $char);
            if ($position !== false) {
                $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
            }
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }

        return $out;
    }
}
