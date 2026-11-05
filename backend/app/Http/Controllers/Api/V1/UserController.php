<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\BusinessException;
use App\Support\Present;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'], 'email' => ['required', 'email', 'max:160', 'unique:users,email'], 'structure_id' => ['nullable', 'uuid', 'exists:structures,id'],
            'title' => ['nullable', 'string', 'max:120'], 'backup_user_id' => ['nullable', 'uuid', 'exists:users,id'], 'locale' => ['sometimes', 'in:fr,en,pt,es'],
        ]);
        $user = User::query()->create($data + ['email' => mb_strtolower($data['email']), 'password' => Str::password(32), 'must_change_password' => true, 'status' => 'active', 'source' => 'local']);
        Password::sendResetLink(['email' => $user->email]);
        $this->audit()->log('user.created', $user, after: collect($data)->except('email')->all());

        return response()->json(['data' => $this->present($user->load(['structure'])), 'message' => 'Compte créé : un lien de définition du mot de passe a été envoyé par le canal institutionnel.'], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $this->authorizePermission('users.manage');
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'], 'email' => ['sometimes', 'email', 'max:160', Rule::unique('users', 'email')->ignore($user->id)],
            'structure_id' => ['nullable', 'uuid', 'exists:structures,id'], 'title' => ['nullable', 'string', 'max:120'],
            'backup_user_id' => ['nullable', 'uuid', 'exists:users,id', Rule::notIn([$user->id])],
        ]);
        if ($user->source !== 'local' && (isset($data['name']) || isset($data['email']))) {
            throw new BusinessException('IDENTITY_MANAGED_BY_DIRECTORY', 'Identité gérée par l’annuaire : modification impossible ici.', 422);
        }
        $before = $user->only(array_keys($data));
        $user->update($data);
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
        $user->forceFill(['status' => 'disabled', 'remember_token' => null])->save();
        $sessions = DB::table('sessions')->where('user_id', $user->id)->delete();
        $this->access()->flush($user);
        $this->audit()->log('user.deactivated', $user, $data['reason'], after: ['sessions_closed' => $sessions]);

        return $this->show($user);
    }

    public function reactivate(User $user): JsonResponse
    {
        $this->authorizePermission('users.manage');
        $user->forceFill(['status' => 'active', 'failed_logins' => 0, 'locked_until' => null])->save();
        $this->audit()->log('user.reactivated', $user);

        return $this->show($user);
    }

    public function revokeSessions(User $user): JsonResponse
    {
        $this->authorizePermission('users.manage');
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
            'id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'initials' => $u->initials(), 'title' => $u->title, 'status' => $u->status, 'source' => $u->source,
            'structure' => $u->structure ? ['id' => $u->structure->id, 'name' => $u->structure->name] : null, 'backup' => Present::userRef($u->backup),
            'roles' => $u->relationLoaded('roleAssignments') ? $u->roleAssignments->map(fn ($a) => [
                'id' => $a->id, 'code' => $a->role->code, 'name' => $a->role->name, 'structure' => $a->structure?->name, 'structure_id' => $a->structure_id, 'zone_id' => $a->zone_id, 'languages' => $a->languages,
                'starts_at' => Present::date($a->starts_at), 'ends_at' => Present::date($a->ends_at), 'revoked_at' => Present::date($a->revoked_at), 'active' => $active($a), 'reason' => $a->reason,
            ])->values() : [],
            'powers' => $u->relationLoaded('roleExtensions') ? $u->roleExtensions->map(fn ($e) => [
                'id' => $e->id, 'power' => $e->power, 'scope' => $e->scope, 'starts_at' => Present::date($e->starts_at), 'ends_at' => Present::date($e->ends_at), 'revoked_at' => Present::date($e->revoked_at), 'active' => $active($e),
            ])->values() : [],
            'mfa_enabled' => (bool) $u->mfa_enabled_at, 'last_login_at' => Present::date($u->last_login_at), 'last_activity_at' => Present::date($u->last_activity_at), 'is_demo' => $u->is_demo,
        ];
    }
}
