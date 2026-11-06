<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ServiceAccount;
use App\Support\Present;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Administration des comptes de service (API-07) : le jeton n'est affiché qu'une fois, à la création ou au renouvellement.
 */
class ServiceAccountController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorizePermission('settings.technical');

        return response()->json(['data' => ServiceAccount::query()->orderBy('name')->get()->map(fn (ServiceAccount $a) => $this->present($a)), 'scopes' => ServiceAccount::SCOPES]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission('settings.technical');
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'description' => ['nullable', 'string', 'max:250'], 'scopes' => ['required', 'array', 'min:1'], 'scopes.*' => ['in:'.implode(',', array_keys(ServiceAccount::SCOPES))], 'reason' => ['required', 'string', 'min:5', 'max:300']]);
        $account = ServiceAccount::query()->create(['name' => $data['name'], 'description' => $data['description'] ?? null, 'scopes' => $data['scopes'], 'created_by' => $this->user()->id]);
        // SEC-05 : jeton d'intégration à durée de vie bornée (un an), renouvelé par rotation.
        $token = $account->createToken('integration', $data['scopes'], now()->addYear())->plainTextToken;
        $this->audit()->log('service_account.created', $account, $data['reason'], after: ['scopes' => $data['scopes']]);

        return response()->json(['data' => $this->present($account), 'token' => $token, 'notice' => 'Copiez ce jeton maintenant : il ne sera plus jamais affiché.'], 201);
    }

    public function rotate(Request $request, ServiceAccount $account): JsonResponse
    {
        $this->authorizePermission('settings.technical');
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:300']]);
        $account->tokens()->delete();
        $token = $account->createToken('integration', $account->scopes, now()->addYear())->plainTextToken;
        $this->audit()->log('service_account.rotated', $account, $data['reason']);

        return response()->json(['data' => $this->present($account), 'token' => $token]);
    }

    public function revoke(Request $request, ServiceAccount $account): JsonResponse
    {
        $this->authorizePermission('settings.technical');
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:300']]);
        $account->tokens()->delete();
        $account->forceFill(['status' => 'revoked'])->save();
        $this->audit()->log('service_account.revoked', $account, $data['reason']);

        return response()->json(['data' => $this->present($account)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ServiceAccount $a): array
    {
        return ['id' => $a->id, 'name' => $a->name, 'description' => $a->description, 'scopes' => $a->scopes, 'status' => $a->status, 'last_used_at' => Present::date($a->last_used_at), 'created_at' => Present::date($a->created_at)];
    }
}
