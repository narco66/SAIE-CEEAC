<?php

namespace App\Support;

use RuntimeException;

/**
 * Conflit ou règle métier violée : rendu en erreur JSON homogène (API-01, API-02) sans modifier aucune donnée.
 */
class BusinessException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 409,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }
}
