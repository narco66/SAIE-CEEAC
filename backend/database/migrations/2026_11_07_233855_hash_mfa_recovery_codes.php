<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

return new class extends Migration
{
    /**
     * ASVS 5.0 V6.5 : les codes de secours du second facteur étaient chiffrés de façon réversible ; ils sont remplacés par
     * leurs empreintes. Les codes restent valables pour leurs détenteurs (même code, vérifié par Hash::check).
     */
    public function up(): void
    {
        DB::table('users')->whereNotNull('mfa_recovery_codes')->orderBy('id')->each(function (object $user): void {
            try {
                $codes = json_decode(Crypt::decryptString($user->mfa_recovery_codes), true);
            } catch (DecryptException) {
                return; // déjà converti ou illisible : laissé tel quel
            }
            if (! is_array($codes)) {
                return;
            }
            $hashes = array_values(array_map(fn (string $code): string => Hash::make(strtoupper($code)), array_filter($codes, 'is_string')));
            DB::table('users')->where('id', $user->id)->update(['mfa_recovery_codes' => json_encode($hashes)]);
        });
    }

    /**
     * Irréversible : une empreinte ne redonne pas le code. Retour arrière = réinitialiser le second facteur des comptes concernés.
     */
    public function down(): void {}
};
