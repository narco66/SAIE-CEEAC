<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Access;
use App\Services\Audit;
use App\Support\BusinessException;
use App\Support\Totp;
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
    public function login(Request $request, Audit $audit, Access $access): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        $user = User::query()->where('email', mb_strtolower($data['email']))->first();

        if ($user?->locked_until?->isFuture()) {
            $audit->log('auth.login_locked', $user, outcome: 'denied', actor: $user);
            throw new BusinessException('ACCOUNT_LOCKED', 'Trop de tentatives. Réessayez dans quelques minutes.', 429);
        }
        if (! $user || ! Hash::check($data['password'], $user->password) || ! $user->isActive()) {
            if ($user) {
                $failed = $user->failed_logins + 1;
                $user->forceFill(['failed_logins' => $failed, 'locked_until' => $failed >= config('saie.security.max_failed_logins') ? now()->addMinutes((int) config('saie.security.lockout_minutes')) : null])->save();
            }
            $audit->log('auth.login_failed', $user, outcome: 'denied', actor: $user, objectLabel: $user ? null : 'compte inconnu');
            throw new BusinessException('INVALID_CREDENTIALS', 'Identifiant ou mot de passe incorrect.', 422);
        }

        if ($user->mfa_enabled_at) {
            $request->session()->put('mfa.pending', ['user' => $user->id, 'remember' => $request->boolean('remember'), 'at' => now()->timestamp]);

            return response()->json(['mfa_required' => true]);
        }

        return $this->completeLogin($request, $user, $audit, $access);
    }

    public function mfa(Request $request, Audit $audit, Access $access): JsonResponse
    {
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
            $codes = $user->mfa_recovery_codes ?? [];
            $index = array_search(strtoupper(trim($data['code'])), $codes, true);
            if ($index !== false) {
                unset($codes[$index]);
                $user->forceFill(['mfa_recovery_codes' => array_values($codes)])->save();
                $valid = true;
            }
        }
        if (! $valid) {
            // Les échecs du second facteur comptent dans le verrouillage du compte, comme ceux du mot de passe.
            $failed = $user->failed_logins + 1;
            $user->forceFill(['failed_logins' => $failed, 'locked_until' => $failed >= config('saie.security.max_failed_logins') ? now()->addMinutes((int) config('saie.security.lockout_minutes')) : null])->save();
            $audit->log('auth.mfa_failed', $user, outcome: 'denied', actor: $user);
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
            'password' => ['required', 'confirmed', PasswordRule::min(12)->mixedCase()->numbers()],
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

    private function completeLogin(Request $request, User $user, Audit $audit, Access $access): JsonResponse
    {
        Auth::guard('web')->login($user, (bool) $request->boolean('remember'));
        $request->session()->regenerate();
        $user->forceFill(['failed_logins' => 0, 'locked_until' => null, 'last_login_at' => now(), 'last_activity_at' => now()])->save();
        $audit->log('auth.login', $user, actor: $user);
        $mustEnroll = $access->requiresMfaEnrollment($user);

        return response()->json(['authenticated' => true, 'mfa_enrollment_required' => $mustEnroll, 'must_change_password' => $user->must_change_password]);
    }
}
