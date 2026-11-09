<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Complète la longueur (Password::defaults) sans règle de composition (ASVS 5.0 V6.2) : refuse les suites trop
 * répétitives et un mot de passe qui contient l'identifiant du compte.
 */
class StrongPassphrase implements ValidationRule
{
    public function __construct(private ?string $email = null) {}

    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }
        if (count(array_unique(mb_str_split(mb_strtolower($value)))) < 6) {
            $fail('Le mot de passe doit comporter au moins 6 caractères différents.');

            return;
        }
        $login = mb_strtolower((string) strstr((string) $this->email, '@', true));
        if (mb_strlen($login) >= 4 && str_contains(mb_strtolower($value), $login)) {
            $fail('Le mot de passe ne doit pas contenir votre identifiant.');
        }
    }
}
