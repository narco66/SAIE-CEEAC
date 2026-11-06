<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Access;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Réserve les routes utilisateur aux comptes humains actifs : un jeton de lecteur n'y donne aucun accès,
 * et une désactivation coupe immédiatement la session (HAB-01).
 */
class EnsureHumanUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User) {
            throw new AuthenticationException;
        }
        if (! $user->isActive()) {
            Auth::guard('web')->logout();
            if ($request->hasSession()) {
                $request->session()->invalidate();
            }
            throw new AuthenticationException;
        }
        if ($user->last_activity_at === null || $user->last_activity_at->lt(now()->subMinute())) {
            $user->forceFill(['last_activity_at' => now()])->saveQuietly();
        }

        $pending = match (true) {
            (bool) $user->must_change_password => ['PASSWORD_CHANGE_REQUIRED', 'Vous devez définir un nouveau mot de passe avant de poursuivre.'],
            app(Access::class)->requiresMfaEnrollment($user) => ['MFA_ENROLLMENT_REQUIRED', 'Votre rôle impose l’activation du second facteur avant de poursuivre.'],
            default => null,
        };
        // Seul le compte personnel reste accessible : changement de mot de passe et activation du second facteur.
        if ($pending && ! $request->is('api/v1/me', 'api/v1/me/*')) {
            return response()->json(['code' => $pending[0], 'message' => $pending[1], 'errors' => (object) []], 403);
        }

        return $next($request);
    }
}
