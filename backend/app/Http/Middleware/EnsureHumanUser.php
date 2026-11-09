<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Access;
use App\Support\BusinessException;
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
        // Durée maximale de session, indépendante de l'activité (ASVS 5.0 V7.3) : reconnexion, donc second facteur, imposée.
        if ($request->hasSession()) {
            $authenticatedAt = $request->session()->get('auth.at');
            if ($authenticatedAt === null) {
                $request->session()->put('auth.at', now()->timestamp);
            } elseif (now()->timestamp - (int) $authenticatedAt > 3600 * (int) config('saie.security.session_absolute_hours', 12)) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                throw new AuthenticationException('Session expirée : reconnectez-vous.');
            }
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
            throw new BusinessException($pending[0], $pending[1], 403);
        }

        return $next($request);
    }
}
