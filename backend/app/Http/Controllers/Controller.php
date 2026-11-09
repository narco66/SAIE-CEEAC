<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Access;
use App\Services\Audit;
use App\Support\BusinessException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

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

    /**
     * Permissions dont la détention protège un compte contre un administrateur qui ne les a pas : changer son adresse,
     * le désactiver, réinitialiser son second facteur ou lui retirer un rôle vaudrait prise de contrôle ou déni de service (HAB-02).
     *
     * @var list<string>
     */
    protected const PRIVILEGED = ['users.manage', 'roles.manage', 'settings.manage', 'settings.technical', 'audit.view', 'contents.validate', 'reservations.approve',
        'alerts.manage', 'campaigns.manage', 'referentials.manage', 'templates.manage', 'devices.revoke', 'incidents.manage'];

    /**
     * Droits d'administration du système : les comptes qui les détiennent ne peuvent être désactivés, débloqués ou privés de leur
     * second facteur que par un administrateur qui les détient aussi (séparation DSI / administration fonctionnelle / audit).
     * L'assistance ordinaire (validateur ou diffuseur qui a perdu son téléphone) reste possible.
     *
     * @var list<string>
     */
    protected const ADMINISTRATIVE = ['users.manage', 'roles.manage', 'settings.manage', 'settings.technical', 'audit.view'];

    /**
     * HAB-02 : pas d'action d'administration sur un compte qui détient des droits ou pouvoirs que l'acteur n'a pas ; refus journalisé.
     * Par défaut, la liste complète protège contre la prise de contrôle (adresse, rôles) ; `administrativeOnly` ne protège que les
     * droits d'administration (désactivation, déblocage, second facteur, sessions).
     */
    protected function assertMayAdminister(User $target, string $action, bool $administrativeOnly = false): void
    {
        $actor = $this->user();
        $protected = $administrativeOnly ? self::ADMINISTRATIVE : self::PRIVILEGED;
        $beyond = array_values(array_diff(array_intersect($this->access()->permissions($target), $protected), $this->access()->permissions($actor)));
        $powers = $administrativeOnly ? [] : $this->access()->powers($target)->pluck('power')->diff($this->access()->powers($actor)->pluck('power'))->values()->all();
        if ($beyond !== [] || $powers !== []) {
            $this->audit()->log('access.denied', $target, "Tentative de $action d’un compte détenant des droits supérieurs", after: ['permissions' => $beyond, 'powers' => $powers], outcome: 'denied');
            throw new AuthorizationException("Ce compte détient des droits que vous n’avez pas : seul un administrateur disposant de ces droits peut $action.");
        }
    }

    /**
     * HAB-01 : écrans visibles au titre de l'une des permissions (réunion des périmètres). Null : tout le parc.
     *
     * @return list<string>|null
     */
    protected function screenScopeFor(string ...$permissions): ?array
    {
        $ids = [];
        foreach ($permissions as $permission) {
            $scope = $this->access()->screenScope($this->user(), $permission);
            if ($scope === null && $this->access()->can($this->user(), $permission)) {
                return null;
            }
            $ids = array_merge($ids, $scope ?? []);
        }

        return array_values(array_unique($ids));
    }

    /**
     * Objet rattaché à un écran (incident, plan de maintenance) : refusé hors du périmètre de la permission. Un objet sans écran
     * concerne tout le parc : seul un titulaire sans restriction de périmètre y accède.
     */
    protected function assertScreenInScope(?string $screenId, string ...$permissions): void
    {
        $scope = $this->screenScopeFor(...$permissions);
        if ($scope !== null && ($screenId === null || ! in_array($screenId, $scope, true))) {
            $this->deny('Écran hors du site ou de la zone de l’habilitation');
        }
    }

    protected function deny(string $reason = ''): never
    {
        $this->audit()->log('access.denied', null, $reason ?: null, outcome: 'denied');
        throw new AuthorizationException;
    }

    /**
     * Réponse de liste paginée homogène : data et meta (API-02). `$prepare` reçoit les éléments de la page avant leur
     * présentation : il sert à charger en une fois les données de toute la page (et non une requête par élément).
     *
     * @param  callable(mixed): array<string, mixed>|null  $transform
     * @param  callable(Collection<int, mixed>): void|null  $prepare
     */
    protected function paginated(Builder $query, Request $request, ?callable $transform = null, int $default = 20, ?callable $prepare = null): JsonResponse
    {
        $perPage = max(1, min(500, (int) $request->integer('per_page', $default)));
        /** @var LengthAwarePaginator $page */
        $page = $query->paginate($perPage);
        if ($prepare) {
            $prepare(collect($page->items()));
        }
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
    protected function expectVersion(Request $request, Model $model): void
    {
        $current = (int) $model->getAttribute('version');
        $expected = $request->header('If-Match') ?? $request->input('expected_version');
        if ($expected === null || $expected === '') {
            throw new BusinessException('VERSION_REQUIRED', 'Version de l’objet manquante : rechargez la page avant de modifier.', 428, ['current_version' => $current]);
        }
        if ((int) trim((string) $expected, '"W/') !== $current) {
            throw new BusinessException('VERSION_CONFLICT', 'L’objet a été modifié par un autre utilisateur. Rechargez la version courante.', 409, ['current_version' => $current]);
        }
        // Réservation atomique à l'écriture (GuardsVersion) : ferme la fenêtre entre ce contrôle et l'enregistrement.
        if (method_exists($model, 'expectVersionOnSave')) {
            $model->expectVersionOnSave($current);
        }
    }
}
