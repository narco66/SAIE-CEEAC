<?php

namespace App\Services;

use App\Models\Incident;
use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Règles de traitement des incidents (§10.3, §15.2) : échéance de prise en charge selon la gravité, responsable par défaut
 * des incidents détectés automatiquement, escalade d'un incident non pris en charge à l'échéance, statistiques.
 */
class IncidentPolicy
{
    /** Délai de prise en charge par défaut (minutes), réglable par gravité dans les paramètres. */
    public const DEFAULT_DELAYS = ['critical' => 30, 'major' => 120, 'medium' => 480, 'minor' => 1440];

    public function __construct(private Notifier $notifier, private Audit $audit) {}

    public function dueAt(string $severity, ?CarbonImmutable $from = null): CarbonImmutable
    {
        $minutes = (int) (Setting::effective("incidents.delay_{$severity}_min") ?? self::DEFAULT_DELAYS[$severity] ?? 1440);

        return ($from ?? CarbonImmutable::now())->addMinutes($minutes);
    }

    /**
     * Responsable par défaut d'un incident automatique : l'agent désigné dans les paramètres, s'il est actif et habilité.
     */
    public function defaultAssignee(): ?User
    {
        $id = Setting::effective('incidents.default_assignee_id');
        $user = $id ? User::query()->find($id) : null;
        $access = app(Access::class);

        return $user && $user->isActive() && ($access->can($user, 'incidents.manage') || $access->can($user, 'supervision.view')) ? $user : null;
    }

    /**
     * Escalade : un incident ouvert non acquitté à son échéance est signalé aux gestionnaires des incidents et à son
     * responsable ; une nouvelle échéance est fixée et le niveau d'escalade augmente (une notification par niveau).
     */
    public function escalateOverdue(): int
    {
        $overdue = Incident::query()->with('assignee')->where('status', 'open')->whereNotNull('due_at')->where('due_at', '<=', now())->get();
        foreach ($overdue as $incident) {
            DB::transaction(function () use ($incident): void {
                $level = $incident->escalation_level + 1;
                $events = $incident->events ?? [];
                $events[] = ['at' => now()->toAtomString(), 'by' => 'Système', 'action' => 'escalate', 'note' => "Escalade de niveau $level : incident non pris en charge à l’échéance"];
                $incident->forceFill(['escalation_level' => $level, 'escalated_at' => now(), 'due_at' => $this->dueAt($incident->severity), 'events' => $events])->save();
                $recipients = $this->managers()->when($incident->assignee, fn ($c) => $c->push($incident->assignee))->unique('id');
                $this->notifier->notify($recipients, 'incident.escalated', "Escalade (niveau $level) : {$incident->reference}", $incident->title, '/supervision', 'incident', $incident->id, $incident->id.':esc'.$level);
                $this->audit->log('incident.escalated', $incident, "Niveau $level", after: ['escalation_level' => $level], actor: null);
            });
        }

        return $overdue->count();
    }

    /**
     * Statistiques sur une période (§10.3, §15.2) : volumes par gravité et par catégorie, délais moyens de prise en charge
     * et de rétablissement mesurés sur les incidents concernés, part des incidents escaladés.
     *
     * @param  list<string>|null  $scope
     * @return array<string, mixed>
     */
    public function stats(CarbonImmutable $from, ?array $scope): array
    {
        $items = Incident::query()->where('detected_at', '>=', $from)->when($scope !== null, fn ($q) => $q->whereIn('screen_id', $scope))
            ->get(['severity', 'category', 'status', 'detected_at', 'acknowledged_at', 'resolved_at', 'escalation_level', 'is_automatic']);
        $mean = fn (Collection $c, string $end) => ($m = $c->filter(fn ($i) => $i->{$end})->map(fn ($i) => $i->detected_at->diffInMinutes($i->{$end}, true)))->isEmpty() ? null : round($m->avg(), 1);

        return [
            'from' => $from->toIso8601String(), 'total' => $items->count(), 'open' => $items->whereIn('status', ['open', 'acknowledged'])->count(),
            'automatic' => $items->where('is_automatic', true)->count(), 'escalated' => $items->where('escalation_level', '>', 0)->count(),
            'by_severity' => collect(['critical', 'major', 'medium', 'minor'])->mapWithKeys(fn ($s) => [$s => [
                'count' => $items->where('severity', $s)->count(),
                'mean_ack_min' => $mean($items->where('severity', $s), 'acknowledged_at'),
                'mean_resolve_min' => $mean($items->where('severity', $s), 'resolved_at'),
            ]])->all(),
            'by_category' => $items->countBy('category')->all(),
        ];
    }

    /**
     * @return Collection<int, User>
     */
    private function managers(): Collection
    {
        $access = app(Access::class);

        return User::query()->where('status', 'active')->get()->filter(fn (User $u) => $access->can($u, 'incidents.manage'))->values();
    }
}
