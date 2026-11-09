<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\ContentVersion;
use App\Models\ReservationOccurrence;
use App\Models\Screen;
use App\Models\ServiceAccount;
use App\Models\Setting;
use App\Support\BusinessException;
use App\Support\Present;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API d'intégration en lecture (API-07, ACC-03) pour l'agenda, l'intranet et un futur système d'accueil.
 * Aucune donnée confidentielle ; le SAIE n'accède jamais aux bases des systèmes tiers et réciproquement.
 */
class IntegrationController extends Controller
{
    public function meetings(Request $request): JsonResponse
    {
        $this->account($request, 'integration:meetings');
        $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
        $tz = config('saie.timezone');
        $day = $request->query('date') ? CarbonImmutable::parse((string) $request->query('date'), $tz) : CarbonImmutable::now($tz);
        $items = ReservationOccurrence::query()->with(['reservation', 'room.floor'])->whereIn('status', ['confirmed', 'in_progress', 'completed'])
            ->whereBetween('starts_at', [$day->startOfDay()->utc(), $day->endOfDay()->utc()])->orderBy('starts_at')->get();

        return response()->json(['data' => $items->map(fn ($o) => [
            'room' => $o->room->name, 'floor' => $o->room->floor->label, 'starts_at' => Present::date($o->starts_at), 'ends_at' => Present::date($o->ends_at),
            'label' => $o->reservation->confidential ? 'Réservée' : $o->reservation->subject, 'confidential' => $o->reservation->confidential,
        ])->values(), 'meta' => ['date' => $day->toDateString(), 'timezone' => $tz]]);
    }

    public function screens(Request $request): JsonResponse
    {
        $this->account($request, 'integration:screens');

        return response()->json(['data' => Screen::query()->with(['location.floor', 'activeDevice'])->where('status', 'active')->orderBy('code')->get()->map(fn (Screen $s) => [
            'code' => $s->code, 'name' => $s->name, 'kind' => $s->kind, 'floor' => $s->location->floor->label,
            'network_status' => $s->activeDevice?->computedNetworkStatus() ?? 'unpaired', 'last_seen_at' => Present::date($s->activeDevice?->last_seen_at),
        ])]);
    }

    /**
     * Annonces publiques en cours de diffusion, versions approuvées uniquement.
     */
    public function announcements(Request $request): JsonResponse
    {
        $this->account($request, 'integration:announcements');
        $versionIds = Campaign::query()->with('items')->where('status', 'active')->get()->flatMap(fn ($c) => $c->items->flatMap(fn ($i) => array_values($i->version_map ?? [])))->unique();
        $versions = ContentVersion::query()->whereIn('id', $versionIds)->where('classification', 'public')->get();

        return response()->json(['data' => $versions->map(fn ($v) => ['content_id' => $v->content_id, 'lang' => $v->lang, 'title' => $v->payload['title'] ?? '', 'body' => $v->payload['body'] ?? null, 'approved_at' => Present::date($v->approved_at)])->values()]);
    }

    private function account(Request $request, string $scope): ServiceAccount
    {
        if (! Setting::get('integrations.api_enabled', false)) {
            throw new BusinessException('INTEGRATION_DISABLED', 'L’API d’intégration est désactivée (paramètre « integrations.api_enabled »).', 403);
        }
        $account = $request->user();
        if (! $account instanceof ServiceAccount || ! $account->isActive() || ! $account->tokenCan($scope)) {
            throw new AuthorizationException;
        }
        if (! $account->last_used_at || $account->last_used_at->lt(now()->subMinutes(5))) {
            $account->forceFill(['last_used_at' => now()])->save();
        }
        $this->audit()->log('integration.read', $scope, actor: null, objectLabel: $account->name);

        return $account;
    }
}
