<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Access;
use App\Services\Audit;
use App\Support\BusinessException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

abstract class Controller
{
    protected function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    protected function access(): Access
    {
        return app(Access::class);
    }

    protected function audit(): Audit
    {
        return app(Audit::class);
    }

    protected function can(string $permission): bool
    {
        return $this->access()->can($this->user(), $permission);
    }

    /**
     * Refus par défaut et journalisation des refus (HAB-01, SEC-04).
     */
    protected function authorizePermission(string ...$permissions): void
    {
        foreach ($permissions as $permission) {
            if ($this->can($permission)) {
                return;
            }
        }
        $this->audit()->log('access.denied', null, implode('|', $permissions), outcome: 'denied');
        throw new AuthorizationException;
    }

    protected function deny(string $reason = ''): never
    {
        $this->audit()->log('access.denied', null, $reason ?: null, outcome: 'denied');
        throw new AuthorizationException;
    }

    /**
     * Réponse de liste paginée homogène : data et meta (API-02).
     *
     * @param  callable(mixed): array<string, mixed>|null  $transform
     */
    protected function paginated(Builder $query, Request $request, ?callable $transform = null, int $default = 20): JsonResponse
    {
        $perPage = max(1, min(500, (int) $request->integer('per_page', $default)));
        /** @var LengthAwarePaginator $page */
        $page = $query->paginate($perPage);
        $items = collect($page->items())->map(fn ($item) => $transform ? $transform($item) : $item)->values();

        return response()->json([
            'data' => $items,
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    /**
     * Tri limité à une liste blanche de colonnes, avec départage stable par UUID (DATA-01).
     *
     * @param  list<string>  $allowed
     */
    protected function applySort(Builder $query, Request $request, array $allowed, string $default, string $defaultDirection = 'desc'): Builder
    {
        $sort = (string) $request->query('sort', $default);
        $direction = $sort[0] === '-' ? 'desc' : ($request->query('sort') ? 'asc' : $defaultDirection);
        $column = ltrim($sort, '-');
        if (! in_array($column, $allowed, true)) {
            $column = $default;
            $direction = $defaultDirection;
        }

        return $query->orderBy($query->getModel()->getTable().'.'.$column, $direction)->orderBy($query->getModel()->getTable().'.'.$query->getModel()->getKeyName());
    }

    /**
     * API-04 : concurrence optimiste obligatoire — la version lue (If-Match ou expected_version) doit accompagner la
     * modification ; sans elle, la requête est refusée plutôt que d'écraser la modification d'un autre utilisateur.
     */
    protected function expectVersion(Request $request, int $current): void
    {
        $expected = $request->header('If-Match') ?? $request->input('expected_version');
        if ($expected === null || $expected === '') {
            throw new BusinessException('VERSION_REQUIRED', 'Version de l’objet manquante : rechargez la page avant de modifier.', 428, ['current_version' => $current]);
        }
        if ((int) trim((string) $expected, '"W/') !== $current) {
            throw new BusinessException('VERSION_CONFLICT', 'L’objet a été modifié par un autre utilisateur. Rechargez la version courante.', 409, ['current_version' => $current]);
        }
    }
}
