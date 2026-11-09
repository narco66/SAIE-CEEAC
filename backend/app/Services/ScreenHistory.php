<?php

namespace App\Services;

use App\Models\Screen;
use App\Support\ActionLabel;
use App\Support\Present;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Historique unifié d'un écran (§13.1, §13.2) : opérations sur la fiche, déplacements, lecteurs successifs, groupes,
 * configuration, programmes effectivement transmis, commandes, incidents et changements d'état. Chaque événement est
 * daté, catégorisé et attribué (utilisateur ou système).
 */
class ScreenHistory
{
    public const CATEGORIES = ['record', 'location', 'device', 'group', 'configuration', 'assignment', 'command', 'incident', 'state', 'error'];

    /**
     * @return list<array{at: string, category: string, label: string, detail: string|null, actor: string|null, outcome: string|null}>
     */
    public function for(Screen $screen, ?string $category = null, int $limit = 300): array
    {
        $deviceIds = DB::table('devices')->where('screen_id', $screen->id)->pluck('id')->all();
        $sources = [
            'record' => fn () => $this->audit(DB::table('audit_logs')->where('object_type', 'Screen')->where('object_id', $screen->id)->whereNotIn('action', ['screen.moved', 'screen.window_changed'])
                ->whereRaw("not coalesce((after ?? 'profile_id') and (after->>'profile_id') is distinct from (before->>'profile_id'), false)"), 'record'),
            'location' => fn () => DB::table('screen_location_history as h')->leftJoin('locations as l', 'l.id', '=', 'h.location_id')->leftJoin('floors as f', 'f.id', '=', 'l.floor_id')
                ->leftJoin('rooms as r', 'r.id', '=', 'h.room_id')->leftJoin('users as u', 'u.id', '=', 'h.moved_by')->where('h.screen_id', $screen->id)
                ->get(['h.from_at', 'f.label as floor', 'l.name as location', 'r.name as room', 'h.location_detail', 'h.reason', 'u.name as actor'])
                ->map(fn ($h) => $this->item($h->from_at, 'location', 'Emplacement : '.trim(($h->floor ? $h->floor.' · ' : '').$h->location.($h->room ? ' · salle '.$h->room : '')), trim(($h->location_detail ?? '').' '.($h->reason ? '— '.$h->reason : '')) ?: null, $h->actor ?? 'Système')),
            'device' => fn () => $this->audit(DB::table('audit_logs')->where('object_type', 'Device')->whereIn('object_id', $deviceIds)->where('action', 'not like', 'device.command%'), 'device'),
            'group' => fn () => $this->audit(DB::table('audit_logs')->where('object_type', 'ScreenGroup')
                ->where(fn ($q) => $q->whereRaw("after->'screen_ids' ?? ?", [$screen->id])->orWhereRaw("before->'screen_ids' ?? ?", [$screen->id])), 'group'),
            'configuration' => fn () => $this->audit(DB::table('audit_logs')->where('object_type', 'Screen')->where('object_id', $screen->id)->where(fn ($q) => $q->whereRaw("(after ?? 'profile_id') and (after->>'profile_id') is distinct from (before->>'profile_id')")->orWhere('action', 'screen.window_changed')), 'configuration'),
            'assignment' => fn () => DB::table('campaign_transmissions as t')->join('campaigns as c', 'c.id', '=', 't.campaign_id')->whereIn('t.device_id', $deviceIds)
                ->get(['t.first_transmitted_at', 'c.reference', 'c.name', 't.first_manifest_version'])
                ->map(fn ($t) => $this->item($t->first_transmitted_at, 'assignment', "Campagne transmise : {$t->name}", "{$t->reference} · programme v{$t->first_manifest_version}", 'Système')),
            'command' => fn () => DB::table('device_commands as c')->leftJoin('users as u', 'u.id', '=', 'c.requested_by')->where('c.screen_id', $screen->id)
                ->get(['c.requested_at', 'c.command', 'c.status', 'c.result_message', 'c.reason', 'u.name as actor'])
                ->map(fn ($c) => $this->item($c->requested_at, 'command', (RemoteCommands::CATALOGUE[$c->command]['label'] ?? $c->command).' — '.$c->status, trim(($c->reason ? $c->reason.' · ' : '').($c->result_message ?? '')) ?: null, $c->actor ?? 'Système', $c->status)),
            'incident' => fn () => DB::table('incidents')->where('screen_id', $screen->id)->get(['detected_at', 'closed_at', 'reference', 'title', 'severity', 'resolution', 'is_automatic'])
                ->flatMap(fn ($i) => array_filter([
                    $this->item($i->detected_at, 'incident', "Incident {$i->reference} ({$i->severity}) : {$i->title}", null, $i->is_automatic ? 'Système' : null),
                    $i->closed_at ? $this->item($i->closed_at, 'incident', "Incident {$i->reference} clos", $i->resolution, null) : null,
                ])),
            // §13.2-e : historique exhaustif des erreurs déclarées par les lecteurs de l'écran (code et message).
            'error' => fn () => DB::table('device_receipts')->whereIn('device_id', $deviceIds)->where('kind', 'error')->orderByDesc('occurred_at')->limit(200)->get(['occurred_at', 'payload'])
                ->map(fn ($r) => $this->item($r->occurred_at, 'error', 'Erreur '.(json_decode((string) $r->payload, true)['code'] ?? 'PLAYER'), json_decode((string) $r->payload, true)['message'] ?? null, 'Lecteur')),
            'state' => fn () => DB::table('screen_status_history')->where('screen_id', $screen->id)->get(['started_at', 'state', 'network_status'])
                ->map(fn ($s) => $this->item($s->started_at, 'state', 'État : '.$s->state, $s->network_status ? 'réseau '.$s->network_status : null, 'Supervision')),
        ];
        $items = collect($sources)->only($category ? [$category] : self::CATEGORIES)->flatMap(fn (callable $source) => $source());

        return $items->sortByDesc('at')->take($limit)->values()->all();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function audit($query, string $category): Collection
    {
        return $query->orderByDesc('occurred_at')->limit(300)->get(['occurred_at', 'action', 'actor_label', 'reason', 'outcome'])
            ->map(fn ($a) => $this->item($a->occurred_at, $category, ActionLabel::for($a->action), $a->reason, $a->actor_label ?? 'Système', $a->outcome));
    }

    /**
     * @return array{at: string, category: string, label: string, detail: string|null, actor: string|null, outcome: string|null}
     */
    private function item(mixed $at, string $category, string $label, ?string $detail, ?string $actor, ?string $outcome = null): array
    {
        return ['at' => Present::date(CarbonImmutable::parse($at)), 'category' => $category, 'label' => $label, 'detail' => $detail, 'actor' => $actor, 'outcome' => $outcome];
    }
}
