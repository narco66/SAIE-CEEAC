<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Références métier lisibles, uniques et monotones (ex. RES-2026-0184) fondées sur des séquences PostgreSQL.
 */
class Reference
{
    public static function next(string $prefix): string
    {
        $sequence = 'ref_seq_'.strtolower(preg_replace('/[^A-Za-z]/', '', $prefix));
        DB::statement("CREATE SEQUENCE IF NOT EXISTS {$sequence}");
        $value = (int) DB::selectOne("SELECT nextval('{$sequence}') AS v")->v;

        return sprintf('%s-%s-%04d', $prefix, now()->format('Y'), $value);
    }
}
