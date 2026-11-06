<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
use App\Models\RoleExtension;
use App\Services\Notifier;
use App\Support\BusinessException;
use App\Support\Present;
use App\Support\Totp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

/**
 * GET /me : identité, permissions effectives, pouvoirs étendus et périmètres (contrat chapitre 17) ; V03 Mon compte.
 */
class MeController extends Controller
{
    public function show(): JsonResponse
    {
        $user = $this->user()->load('structure', 'backup');
        $access = $this->access();

        return response()->json(['data' => [
            'id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'initials' => $user->initials(), 'title' => $user->title,
            'structure' => $user->structure ? ['id' => $user->structure->id, 'name' => $user->structure->name] : null,
            'backup' => Present::userRef($user->backup), 'locale' => $user->locale, 'timezone' => $user->timezone, 'source' => $user->source,
            'roles' => $access->assignments($user)->map(fn ($a) => [
                'id' => $a->id, 'code' => $a->role->code, 'name' => $a->role->name, 'structure_id' => $a->structure_id, 'zone_id' => $a->zone_id,
                'languages' => $a->languages, 'starts_at' => Present::date($a->starts_at), 'ends_at' => Present::date($a->ends_at),
            ])->values(),
            'role_label' => $access->primaryRoleLabel($user),
            'permissions' => $access->permissions($user),
            'powers' => $access->powers($user)->map(fn (RoleExtension $e) => ['power' => $e->power, 'scope' => $e->scope, 'ends_at' => Present::date($e->ends_at), 'active' => $access->hasPower($user, $e->power)])->values(),
            'validation_languages' => $access->validationLanguages($user),
            'mfa_enabled' => (bool) $user->mfa_enabled_at,
            'mfa_enrollment_required' => $access->requiresMfaEnrollment($user),
            'must_change_password' => $user->must_change_password,
            'notification_preferences' => $user->notification_preferences ?? ['email' => true, 'push' => false, 'muted_events' => []],
            'last_login_at' => Present::date($user->last_login_at),
            'server_time' => now()->toIso8601String(), 'timezone_institution' => config('saie.timezone'),
            'demo_mode' => (bool) config('saie.demo_mode'),
        ]]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'locale' => ['sometimes', 'in:fr,en,pt,es'],
            'notification_preferences' => ['sometimes', 'array'],
            'notification_preferences.email' => ['boolean'],
            'notification_preferences.push' => ['boolean'],
            'notification_preferences.muted_events' => ['array'],
            'notification_preferences.muted_events.*' => ['string', 'max:40'],
        ]);
        $this->user()->forceFill($data)->save();
        $this->audit()->log('account.preferences_updated', $this->user());

        return $this->show();
    }

    public function password(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', 'different:current_password', PasswordRule::min(12)->mixedCase()->numbers()],
        ]);
        $user = $this->user();
        if (! Hash::check($data['current_password'], $user->password)) {
            throw new BusinessException('INVALID_PASSWORD', 'Le mot de passe actuel est incorrect.', 422);
        }
        $user->forceFill(['password' => $data['password'], 'must_change_password' => false])->save();
        DB::table('sessions')->where('user_id', $user->id)->where('id', '!=', $request->session()->getId())->delete();
        $this->audit()->log('account.password_changed', $user);

        return response()->json(['message' => 'Mot de passe modifié. Vos autres sessions ont été fermées.']);
    }

    public function sessions(Request $request): JsonResponse
    {
        $rows = DB::table('sessions')->where('user_id', $this->user()->id)->orderByDesc('last_activity')->get();

        return response()->json(['data' => $rows->map(fn ($s) => [
            'id' => hash('sha256', $s->id), 'current' => $s->id === $request->session()->getId(), 'ip_address' => $s->ip_address,
            'user_agent' => Str::limit((string) $s->user_agent, 120), 'last_activity' => date(DATE_ATOM, $s->last_activity),
        ])->values()]);
    }

    public function revokeSession(string $session): JsonResponse
    {
        $match = DB::table('sessions')->where('user_id', $this->user()->id)->get()->first(fn ($s) => hash('sha256', $s->id) === $session);
        if (! $match) {
            throw new BusinessException('NOT_FOUND', 'Session introuvable.', 404);
        }
        DB::table('sessions')->where('id', $match->id)->delete();
        $this->audit()->log('account.session_revoked', $this->user());

        return response()->json(['ok' => true]);
    }

    /**
     * Abonnement aux notifications push (NOT-03) : clé publique VAPID et état d'abonnement.
     */
    public function push(): JsonResponse
    {
        return response()->json(['data' => [
            'available' => Notifier::pushAvailable(), 'public_key' => Notifier::pushAvailable() ? config('saie.push.public_key') : null,
            'subscriptions' => PushSubscription::query()->where('user_id', $this->user()->id)->count(), 'events' => config('saie.push.events'),
        ]]);
    }

    public function subscribePush(Request $request): JsonResponse
    {
        $data = $request->validate(['endpoint' => ['required', 'url:https', 'max:1000'], 'keys' => ['required', 'array'], 'keys.p256dh' => ['required', 'string', 'max:200'], 'keys.auth' => ['required', 'string', 'max:100']]);
        if (! Notifier::pushAvailable()) {
            throw new BusinessException('PUSH_UNAVAILABLE', 'Les notifications push ne sont pas activées sur ce serveur.', 409);
        }
        PushSubscription::query()->updateOrCreate(['user_id' => $this->user()->id, 'endpoint' => $data['endpoint']], ['keys' => $data['keys']]);
        $preferences = $this->user()->notification_preferences ?? [];
        $this->user()->forceFill(['notification_preferences' => array_merge($preferences, ['push' => true])])->save();
        $this->audit()->log('account.push_subscribed', $this->user());

        return $this->push();
    }

    public function unsubscribePush(): JsonResponse
    {
        PushSubscription::query()->where('user_id', $this->user()->id)->delete();
        $preferences = $this->user()->notification_preferences ?? [];
        $this->user()->forceFill(['notification_preferences' => array_merge($preferences, ['push' => false])])->save();
        $this->audit()->log('account.push_unsubscribed', $this->user());

        return $this->push();
    }

    public function mfaSetup(Request $request): JsonResponse
    {
        $user = $this->user();
        if ($user->mfa_enabled_at) {
            throw new BusinessException('MFA_ALREADY_ENABLED', 'Le second facteur est déjà activé.', 409);
        }
        $secret = Totp::generateSecret();
        $request->session()->put('mfa.setup', $secret);

        return response()->json(['secret' => $secret, 'otpauth_uri' => Totp::uri($secret, $user->email)]);
    }

    public function mfaConfirm(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:10']]);
        $secret = $request->session()->get('mfa.setup');
        if (! $secret || ! Totp::verify($secret, $data['code'])) {
            throw new BusinessException('MFA_INVALID', 'Code incorrect : vérifiez l’heure de votre appareil.', 422);
        }
        $codes = collect(range(1, 8))->map(fn () => strtoupper(Str::random(4).'-'.Str::random(4)))->all();
        $this->user()->forceFill(['mfa_secret' => $secret, 'mfa_enabled_at' => now(), 'mfa_recovery_codes' => $codes])->save();
        $request->session()->forget('mfa.setup');
        $this->audit()->log('account.mfa_enabled', $this->user());

        return response()->json(['recovery_codes' => $codes]);
    }
}
