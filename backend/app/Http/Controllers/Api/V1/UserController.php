<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Approval;
use App\Models\User;
use App\Support\BusinessException;
use App\Support\Present;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Utilisateurs et affectations (V34). La désactivation coupe les sessions et empêche tout nouveau traitement (HAB-01).
 */
class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission('users.manage', 'roles.manage');
        $query = User::query()->with(['structure', 'backup', 'roleAssignments.role', 'roleExtensions'])
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('structure_id'), fn ($q, $s) => $q->where('structure_id', $s))
            ->when($request->query('role'), fn ($q, $r) => $q->whereHas('roleAssignments.role', fn ($w) => $w->where('code', $r)))
            ->when($request->query('q'), fn ($q, $t) => $q->where(fn ($w) => $w->where('name', 'ilike', "%$t%")->orWhere('email', 'ilike', "%$t%")->orWhereHas('structure', fn ($s) => $s->where('name', 'ilike', "%$t%"))));
        $this->applySort($query, $request, ['name', 'last_activity_at', 'created_at'], 'name', 'asc');

        return $this->paginated($query, $request, fn (User $u) => $this->present($u));
    }

    public function show(User $user): JsonResponse
    {
        $this->authorizePermission('users.manage', 'roles.manage');

        return response()->json(['data' => $this->present($user->load(['structure', 'backup', 'roleAssignments.role', 'roleAssignments.structure', 'roleExtensions']))]);
    }

    /**
     * Le mot de passe initial n'est jamais affiché : un lien de définition est envoyé à l'adresse professionnelle (LIV-03).
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission('users.manage');
        $this->normalizeEmail($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'], 'email' => ['required', 'email', 'max:160', 'unique:users,email'], 'structure_id' => ['nullable', 'uuid', Rule::exists('structures', 'id')->where('is_active', true)],
            'title' => ['nullable', 'string', 'max:120'], 'backup_user_id' => ['nullable', 'uuid', Rule::exists('users', 'id')->where('status', 'active')], 'locale' => ['sometimes', 'in:fr,en,pt,es'],
        ], ['structure_id.exists' => 'Cette structure est désactivée ou inconnue.', 'backup_user_id.exists' => 'Le suppléant doit être un compte actif.']);
        $user = User::query()->create($data + ['email' => mb_strtolower($data['email']), 'password' => Str::password(32), 'must_change_password' => true, 'status' => 'active', 'source' => 'local']);
        Password::sendResetLink(['email' => $user->email]);
        $this->audit()->log('user.created', $user, after: collect($data)->except('email')->all());

        return response()->json(['data' => $this->present($user->load(['structure'])), 'message' => 'Compte créé : un lien de définition du mot de passe a été envoyé par le canal institutionnel.'], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $this->authorizePermission('users.manage');
        $this->normalizeEmail($request);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'], 'email' => ['sometimes', 'email', 'max:160', Rule::unique('users', 'email')->ignore($user->id)],
            'structure_id' => ['nullable', 'uuid', $request->input('structure_id') === $user->structure_id ? 'exists:structures,id' : Rule::exists('structures', 'id')->where('is_active', true)], 'title' => ['nullable', 'string', 'max:120'],
            'backup_user_id' => ['nullable', 'uuid', $request->input('backup_user_id') === $user->backup_user_id ? 'exists:users,id' : Rule::exists('users', 'id')->where('status', 'active'), Rule::notIn([$user->id])],
        ], ['structure_id.exists' => 'Cette structure est désactivée ou inconnue.', 'backup_user_id.exists' => 'Le suppléant doit être un compte actif.']);
        if ($user->source !== 'local' && (isset($data['name']) || isset($data['email']))) {
            throw new BusinessException('IDENTITY_MANAGED_BY_DIRECTORY', 'Identité gérée par l’annuaire : modification impossible ici.', 422);
        }
        $emailChanged = isset($data['email']) && mb_strtolower($data['email']) !== $user->email;
        if ($emailChanged) {
            $this->assertMayAdminister($user, 'modifier l’adresse de connexion');
            $data['email'] = mb_strtolower($data['email']);
        }
        $this->expectVersion($request, $user);
        $previousEmail = $user->email;
        $before = $user->only(array_keys($data));
        $user->fill($data)->forceFill(['version' => $user->version + 1])->save();
        if ($emailChanged) {
            // L'ancienne adresse est prévenue et les sessions fermées : un changement d'adresse ne permet pas une prise de compte silencieuse.
            $user->forceFill(['remember_token' => null])->save();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            $this->warnAccountHolder($previousEmail, 'L’adresse de connexion de votre compte SAIE-CEEAC a été remplacée par un administrateur.');
        }
        $this->audit()->log('user.updated', $user, before: $before, after: $data);

        return $this->show($user);
    }

    public function deactivate(Request $request, User $user): JsonResponse
    {
        $this->authorizePermission('users.manage');
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:300']]);
        if ($user->id === $this->user()->id) {
            throw new BusinessException('SELF_DEACTIVATION', 'Vous ne pouvez pas désactiver votre propre compte.', 422);
        }
        $this->assertMayAdminister($user, 'désactiver ce compte', administrativeOnly: true);
        $user->forceFill(['status' => 'disabled', 'remember_token' => null])->save();
        $sessions = DB::table('sessions')->where('user_id', $user->id)->delete();
        $this->access()->flush($user);
        // HAB-01 : les validations ouvertes du compte désactivé repassent « à affecter » (réaffectation automatique par saie:tick).
        $released = Approval::query()->where('status', 'open')->where('assignee_id', $user->id)->update(['assignee_id' => null, 'updated_at' => now()]);
        Approval::query()->where('status', 'open')->where('backup_id', $user->id)->update(['backup_id' => null, 'updated_at' => now()]);
        $this->audit()->log('user.deactivated', $user, $data['reason'], after: ['sessions_closed' => $sessions, 'approvals_released' => $released]);

        return $this->show($user);
    }

    public function reactivate(Request $request, User $user): JsonResponse
    {
        $this->authorizePermission('users.manage');
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:300']]);
        $this->refuseOwnAccount($user);
        $this->assertMayAdminister($user, 'réactiver ce compte', administrativeOnly: true);
        $user->forceFill(['status' => 'active', 'failed_logins' => 0, 'locked_until' => null])->save();
        $this->audit()->log('user.reactivated', $user, $data['reason']);

        return $this->show($user);
    }

    /**
     * Déblocage d'un compte verrouillé après des échecs de connexion (SEC-01), sur motif, jamais pour soi-même.
     */
    public function unlock(Request $request, User $user): JsonResponse
    {
        $this->authorizePermission('users.manage');
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:300']]);
        $this->refuseOwnAccount($user);
        $this->assertMayAdminister($user, 'débloquer ce compte', administrativeOnly: true);
        $user->forceFill(['failed_logins' => 0, 'locked_until' => null])->save();
        $this->audit()->log('user.unlocked', $user, $data['reason']);

        return $this->show($user);
    }

    /**
     * Réinitialisation du second facteur d'un agent qui a perdu son application (SEC-01) : secret et codes de secours effacés,
     * sessions fermées ; un nouvel enrôlement est exigé à la prochaine connexion si son rôle l'impose.
     */
    public function resetMfa(Request $request, User $user): JsonResponse
    {
        $this->authorizePermission('users.manage');
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:300']]);
        $this->refuseOwnAccount($user);
        $user->forceFill(['mfa_secret' => null, 'mfa_enabled_at' => null, 'mfa_last_step' => null, 'mfa_recovery_codes' => null, 'remember_token' => null])->save();
        $sessions = DB::table('sessions')->where('user_id', $user->id)->delete();
        $this->audit()->log('user.mfa_reset', $user, $data['reason'], after: ['sessions_closed' => $sessions]);
        $this->warnAccountHolder($user->email, 'Le second facteur de votre compte SAIE-CEEAC a été réinitialisé par un administrateur.');

        return $this->show($user);
    }

    /**
     * Les adresses sont enregistrées en minuscules : l'unicité se contrôle sur la même forme (sinon doublon en 409 générique).
     */
    private function normalizeEmail(Request $request): void
    {
        if (is_string($request->input('email'))) {
            $request->merge(['email' => mb_strtolower(trim($request->input('email')))]);
        }
    }

    private function warnAccountHolder(string $email, string $what): void
    {
        Mail::raw("$what\n\nSi vous n’êtes pas à l’origine de cette demande, prévenez immédiatement la DSI.", fn (Message $m) => $m->to($email)->subject('[SAIE-CEEAC] Sécurité de votre compte'));
    }

    private function refuseOwnAccount(User $user): void
    {
        if ($user->id === $this->user()->id) {
            $this->audit()->log('access.denied', $user, 'Tentative de déblocage ou de réinitialisation de son propre compte', outcome: 'denied');
            throw new AuthorizationException('Cette opération sur votre propre compte doit être faite par un autre administrateur.');
        }
    }

    public function revokeSessions(User $user): JsonResponse
    {
        $this->authorizePermission('users.manage');
        $this->assertMayAdminister($user, 'fermer ses sessions', administrativeOnly: true);
        $count = DB::table('sessions')->where('user_id', $user->id)->delete();
        $user->forceFill(['remember_token' => null])->save();
        $this->audit()->log('user.sessions_revoked', $user, after: ['count' => $count]);

        return response()->json(['revoked' => $count]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(User $u): array
    {
        $active = fn ($item) => ! $item->revoked_at && $item->starts_at <= now() && (! $item->ends_at || $item->ends_at > now());

        return [
            'id' => $u->id, 'version' => $u->version, 'name' => $u->name, 'email' => $u->email, 'initials' => $u->initials(), 'title' => $u->title, 'status' => $u->status, 'source' => $u->source,
            'structure' => $u->structure ? ['id' => $u->structure->id, 'name' => $u->structure->name] : null, 'backup' => Present::userRef($u->backup),
            'roles' => $u->relationLoaded('roleAssignments') ? $u->roleAssignments->map(fn ($a) => [
                'id' => $a->id, 'code' => $a->role->code, 'name' => $a->role->name, 'structure' => $a->structure?->name, 'structure_id' => $a->structure_id, 'site_id' => $a->site_id, 'zone_id' => $a->zone_id, 'floor_id' => $a->floor_id, 'group_id' => $a->group_id, 'building_id' => $a->building_id, 'screen_id' => $a->screen_id, 'languages' => $a->languages,
                'starts_at' => Present::date($a->starts_at), 'ends_at' => Present::date($a->ends_at), 'revoked_at' => Present::date($a->revoked_at), 'active' => $active($a), 'reason' => $a->reason,
            ])->values() : [],
            'powers' => $u->relationLoaded('roleExtensions') ? $u->roleExtensions->map(fn ($e) => [
                'id' => $e->id, 'power' => $e->power, 'scope' => $e->scope, 'starts_at' => Present::date($e->starts_at), 'ends_at' => Present::date($e->ends_at), 'revoked_at' => Present::date($e->revoked_at), 'active' => $active($e),
            ])->values() : [],
            'mfa_enabled' => (bool) $u->mfa_enabled_at, 'locked' => (bool) $u->locked_until?->isFuture(), 'locked_until' => Present::date($u->locked_until), 'last_login_at' => Present::date($u->last_login_at), 'last_activity_at' => Present::date($u->last_activity_at), 'is_demo' => $u->is_demo,
        ];
    }
}
