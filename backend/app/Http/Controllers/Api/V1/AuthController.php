<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Rules\StrongPassphrase;
use App\Services\Access;
use App\Services\Audit;
use App\Support\BusinessException;
use App\Support\Totp;
use Carbon\CarbonInterface;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

/**
 * Connexion SPA par session et cookies sécurisés (API-05), second facteur TOTP (SEC-01), réinitialisation neutre (V01, V02).
 */
class AuthController extends Controller
{
    private const FAILED_LOGIN_MESSAGE = 'Identifiant ou mot de passe incorrect. Après plusieurs échecs, le compte est bloqué quelques minutes.';

    /**
     * Empreinte d'un secret aléatoire, calculée une fois par processus, pour que la vérification d'un compte inconnu coûte autant que celle d'un compte réel.
     */
    /**
     * Verrouillage progressif (ASVS V6.3, décision D6 à valider) : la durée croît avec le nombre d'échecs consécutifs
     * (par défaut 1 min dès 5 échecs, 5 min dès 10, 15 min dès 15). Un tiers qui verrouille un compte le bloque ainsi peu de temps.
     */
    private static function lockoutUntil(int $failed): ?CarbonInterface
    {
        $minutes = null;
        foreach ((array) config('saie.security.lockout_steps') as $threshold => $duration) {
            if ($failed >= (int) $threshold) {
                $minutes = (int) $duration;
            }
        }

        return $minutes ? now()->addMinutes($minutes) : null;
    }

    private static function dummyHash(): string
    {
        static $hash = null;

        return $hash ??= Hash::make(Str::random(40));
    }

    public function login(Request $request, Audit $audit, Access $access): JsonResponse
    {
        $this->assertStatefulOrigin($request);
        $data = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        $user = User::query()->where('email', mb_strtolower($data['email']))->first();

        // ASVS V6 : compte inconnu, mot de passe faux et compte verrouillé reçoivent la même réponse ; un hachage factice égalise le temps de réponse.
        $passwordMatches = Hash::check($data['password'], $user?->password ?? self::dummyHash());
        if ($user?->locked_until?->isFuture()) {
            $audit->log('auth.login_locked', $user, outcome: 'denied', actor: $user);
            throw new BusinessException('INVALID_CREDENTIALS', self::FAILED_LOGIN_MESSAGE, 422);
        }
        if (! $user || ! $passwordMatches || ! $user->isActive()) {
            if ($user) {
                $failed = $user->failed_logins + 1;
                $user->forceFill(['failed_logins' => $failed, 'locked_until' => self::lockoutUntil($failed)])->save();
            }
            $audit->log('auth.login_failed', $user, outcome: 'denied', actor: $user, objectLabel: $user ? null : 'compte inconnu');
            throw new BusinessException('INVALID_CREDENTIALS', self::FAILED_LOGIN_MESSAGE, 422);
        }

        if ($user->mfa_enabled_at) {
            $request->session()->put('mfa.pending', ['user' => $user->id, 'at' => now()->timestamp]);

            return response()->json(['mfa_required' => true]);
        }

        return $this->completeLogin($request, $user, $audit, $access);
    }

    public function mfa(Request $request, Audit $audit, Access $access): JsonResponse
    {
        $this->assertStatefulOrigin($request);
        $data = $request->validate(['code' => ['required', 'string', 'max:20']]);
        $pending = $request->session()->get('mfa.pending');
        if (! $pending || now()->timestamp - $pending['at'] > 300) {
            throw new BusinessException('MFA_EXPIRED', 'Étape expirée : reconnectez-vous.', 401);
        }
        $user = User::query()->findOrFail($pending['user']);
        if ($user->locked_until?->isFuture()) {
            $audit->log('auth.login_locked', $user, outcome: 'denied', actor: $user);
            throw new BusinessException('ACCOUNT_LOCKED', 'Trop de tentatives. Réessayez dans quelques minutes.', 429);
        }
        // Un code n'est accepté qu'une fois : son pas de temps doit être postérieur au dernier accepté.
        $step = Totp::step((string) $user->mfa_secret, $data['code']);
        $valid = $step !== null && ($user->mfa_last_step === null || $step > $user->mfa_last_step);
        if ($valid) {
            $user->forceFill(['mfa_last_step' => $step])->save();
        }
        if (! $valid) {
            // Codes de secours conservés sous forme d'empreintes ; un code utilisé est retiré et l'usage est journalisé.
            $codes = $user->mfa_recovery_codes ?? [];
            $candidate = strtoupper(trim($data['code']));
            foreach ($codes as $index => $hash) {
                if (is_string($hash) && Hash::check($candidate, $hash)) {
                    unset($codes[$index]);
                    $user->forceFill(['mfa_recovery_codes' => array_values($codes)])->save();
                    $audit->log('auth.mfa_recovery_used', $user, actor: $user, after: ['remaining' => count($codes)]);
                    $valid = true;
                    break;
                }
            }
        }
        if (! $valid) {
            // Les échecs du second facteur comptent dans le verrouillage du compte, comme ceux du mot de passe.
            // Au-delà d'un seuil, le mot de passe est réputé connu d'un tiers : blocage jusqu'au déblocage par un administrateur.
            $failed = $user->failed_logins + 1;
            $final = $failed >= (int) config('saie.security.mfa_admin_unlock_after', 20);
            $user->forceFill(['failed_logins' => $failed, 'locked_until' => $final ? now()->addYears(10) : self::lockoutUntil($failed)])->save();
            $audit->log($final ? 'auth.mfa_locked' : 'auth.mfa_failed', $user, outcome: 'denied', actor: $user);
            throw new BusinessException('MFA_INVALID', 'Code de vérification incorrect.', 422);
        }
        $request->session()->forget('mfa.pending');

        return $this->completeLogin($request, $user, $audit, $access);
    }

    public function logout(Request $request, Audit $audit): JsonResponse
    {
        if ($user = Auth::guard('web')->user()) {
            $audit->log('auth.logout', $user, actor: $user);
        }
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['ok' => true]);
    }

    public function forgot(Request $request, Audit $audit): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);
        Password::sendResetLink(['email' => mb_strtolower($data['email'])]);
        $audit->log('auth.reset_requested', null, actor: null, objectLabel: 'demande de réinitialisation');

        return response()->json(['message' => 'Si un compte correspond à cette adresse, un lien de réinitialisation vient d’être envoyé.']);
    }

    public function reset(Request $request, Audit $audit): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'], 'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::defaults(), new StrongPassphrase($request->string('email')->toString())],
        ]);
        $status = Password::reset($data, function (User $user, string $password) use ($audit): void {
            // Une réinitialisation ferme toutes les sessions et invalide le cookie « se souvenir de moi » (SEC-01).
            $user->forceFill(['password' => $password, 'must_change_password' => false, 'failed_logins' => 0, 'locked_until' => null, 'remember_token' => Str::random(60)])->save();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            event(new PasswordReset($user));
            $audit->log('auth.password_reset', $user, actor: $user);
        });
        if ($status !== Password::PASSWORD_RESET) {
            throw new BusinessException('RESET_LINK_INVALID', 'Ce lien de réinitialisation est invalide ou expiré.', 422);
        }

        return response()->json(['message' => 'Mot de passe modifié. Vous pouvez vous connecter.']);
    }

    /**
     * API-05 : la connexion par session n'est ouverte qu'aux origines déclarées (SANCTUM_STATEFUL_DOMAINS). Ailleurs, refus
     * explicite avant toute vérification d'identifiants, au lieu d'une erreur serveur.
     */
    private function assertStatefulOrigin(Request $request): void
    {
        if (! $request->hasSession()) {
            throw new BusinessException('STATEFUL_ORIGIN_REQUIRED', 'Connexion refusée : cette adresse n’est pas autorisée à ouvrir une session sur le SAIE-CEEAC.', 400);
        }
    }

    private function completeLogin(Request $request, User $user, Audit $audit, Access $access): JsonResponse
    {
        // Pas de cookie « se souvenir de moi » : il survivrait à la durée maximale de session (ASVS 5.0 V7.3).
        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put('auth.at', now()->timestamp);
        $user->forceFill(['failed_logins' => 0, 'locked_until' => null, 'last_login_at' => now(), 'last_activity_at' => now()])->save();
        $audit->log('auth.login', $user, actor: $user);
        $mustEnroll = $access->requiresMfaEnrollment($user);

        return response()->json(['authenticated' => true, 'mfa_enrollment_required' => $mustEnroll, 'must_change_password' => $user->must_change_password]);
    }
}
