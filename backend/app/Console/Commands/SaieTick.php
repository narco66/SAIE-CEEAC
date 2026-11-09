<?php

namespace App\Console\Commands;

use App\Models\Alert;
use App\Models\Approval;
use App\Models\Content;
use App\Models\Device;
use App\Models\Incident;
use App\Models\MaintenancePlan;
use App\Models\Manifest;
use App\Models\PlayerRelease;
use App\Models\ReservationOccurrence;
use App\Models\Screen;
use App\Models\ScreenStatusHistory;
use App\Models\Setting;
use App\Models\User;
use App\Models\Visit;
use App\Services\Audit;
use App\Services\CampaignService;
use App\Services\ContentWorkflow;
use App\Services\DynamicGroups;
use App\Services\DynamicSources;
use App\Services\IncidentPolicy;
use App\Services\Notifier;
use App\Services\RealtimeBus;
use App\Services\ReleasePromotion;
use App\Services\RemoteCommands;
use App\Services\ReservationService;
use App\Services\ScheduleResolver;
use App\Support\Present;
use App\Support\Reference;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

#[Signature('saie:tick')]
#[Description('Traitements périodiques SAIE : expirations, statuts, rappels et escalades, supervision des lecteurs, purges')]
class SaieTick extends Command
{
    public function handle(CampaignService $campaigns, ContentWorkflow $workflow, Notifier $notifier, Audit $audit): int
    {
        $report = [];
        $report['campagnes'] = $campaigns->tick();

        // §11.2 : commande sans compte rendu du lecteur à son échéance → « expirée », jamais présumée exécutée.
        $report['commandes_expirées'] = $this->guard('commandes à distance', fn () => app(RemoteCommands::class)->expireOverdue(), 0);
        $report['alertes_expirées'] = Alert::query()->where('status', 'active')->where('expires_at', '<=', now())->update(['status' => 'expired', 'updated_at' => now()]);

        foreach (Content::query()->whereNotIn('status', ['archived', 'expired'])->where('is_permanent', false)->whereNotNull('expires_at')->where('expires_at', '<=', now())->get() as $content) {
            $workflow->refreshStatus($content);
            $audit->log('content.expired', $content, actor: null);
        }
        foreach (Content::query()->whereNotIn('status', ['archived', 'expired', 'draft'])->where('is_permanent', false)->whereBetween('expires_at', [now(), now()->addDays(2)])->with('author')->get() as $content) {
            $notifier->notify($content->author, 'content.expiring', 'Expiration prochaine', $workflow->label($content), "/affichage/contenus/{$content->id}/edition", 'content', $content->id);
        }

        $report['réunions_en_cours'] = ReservationOccurrence::query()->where('status', 'confirmed')->where('starts_at', '<=', now())->where('ends_at', '>', now())->update(['status' => 'in_progress', 'updated_at' => now()]);
        // Collecte réseau isolée : une source lente ou en erreur n'empêche pas les traitements suivants (EXP-01).
        $report['sources'] = $this->guard('sources dynamiques', fn () => app(DynamicSources::class)->collectDue(), 0);
        $report['rappels_réunion'] = 0;
        foreach (ReservationOccurrence::query()->with(['reservation.organizer', 'room'])->where('status', 'confirmed')->whereBetween('starts_at', [now()->addMinutes(14), now()->addMinutes(16)])->get() as $occurrence) {
            $subject = $occurrence->reservation->confidential ? 'Réunion confidentielle' : $occurrence->reservation->subject;
            $notifier->notify($occurrence->reservation->organizer, 'reservation.reminder', 'Votre réunion commence dans 15 minutes', $subject.' · '.$occurrence->room->name, "/reservations/{$occurrence->reservation_id}", 'reservation', $occurrence->reservation_id, 'reminder15-'.$occurrence->id);
            $report['rappels_réunion']++;
        }
        $report['salles_libérées'] = app(ReservationService::class)->autoRelease();
        $report['réunions_terminées'] = ReservationOccurrence::query()->whereIn('status', ['confirmed', 'in_progress'])->where('ends_at', '<=', now())->update(['status' => 'completed', 'updated_at' => now()]);
        foreach (ReservationOccurrence::query()->with('reservation.organizer', 'room')->where('status', 'pending')->where('hold_until', '<=', now())->get() as $occurrence) {
            $occurrence->forceFill(['status' => 'refused', 'decision_reason' => 'Délai de validation dépassé : créneau libéré', 'version' => $occurrence->version + 1])->save();
            app(ReservationService::class)->release($occurrence);
            $notifier->notify($occurrence->reservation->organizer, 'reservation.expired', 'Demande de réservation expirée', $occurrence->room->name, "/reservations/{$occurrence->reservation_id}", 'reservation', $occurrence->reservation_id, 'hold-'.$occurrence->id);
            $audit->log('reservation.hold_expired', $occurrence->reservation, 'Délai de blocage dépassé', actor: null);
            // Plus aucune occurrence en attente : la validation n'a plus d'objet (sinon rappels et escalades sur une demande morte).
            if (! $occurrence->reservation->occurrences()->where('status', 'pending')->exists()) {
                Approval::query()->where('object_type', 'reservation')->where('object_id', $occurrence->reservation_id)->where('status', 'open')
                    ->update(['status' => 'cancelled', 'reason' => 'Délai de validation dépassé', 'updated_at' => now()]);
            }
        }

        $report['validations_affectées'] = $workflow->assignPending();
        $report['visites_mises_à_jour'] = Visit::syncStatuses();
        $report['rappels'] = 0;
        $report['escalades'] = 0;
        foreach (Approval::query()->with(['assignee', 'backup'])->where('status', 'open')->get() as $approval) {
            $half = $approval->created_at->addSeconds((int) ($approval->created_at->diffInSeconds($approval->due_at, true) / 2));
            if (! $approval->reminded_at && now()->gte($half) && now()->lt($approval->due_at)) {
                $notifier->notify($approval->assignee, 'approval.reminder', 'Rappel : validation en attente', $approval->title, $this->link($approval), $approval->object_type, $approval->object_id, 'reminder-'.$approval->id);
                $approval->forceFill(['reminded_at' => now()])->save();
                $report['rappels']++;
            }
            if (! $approval->escalated_at && now()->gte($approval->due_at)) {
                $approval->forceFill(['escalated_at' => now()])->save();
                $notifier->notify([$approval->assignee, $approval->backup], 'approval.escalated', 'Validation escaladée au suppléant', $approval->title, $this->link($approval), $approval->object_type, $approval->object_id, 'escalation-'.$approval->id);
                if (! $approval->backup) {
                    // Seules les affectations en cours (non révoquées, commencées, non échues) désignent un administrateur fonctionnel.
                    $admins = $workflow->functionalAdmins();
                    $notifier->notify($admins, 'approval.no_backup', 'Tâche escaladée sans suppléant', $approval->title, '/mes-taches', $approval->object_type, $approval->object_id, 'nobackup-'.$approval->id);
                }
                $audit->log('approval.escalated', $approval->object_type, $approval->backup ? 'Escalade au suppléant' : 'Aucun suppléant désigné', actor: null, objectLabel: $approval->title);
                $report['escalades']++;
            }
        }

        $report['incidents_lecteurs'] = 0;
        foreach (Device::query()->with('screen')->where('status', 'active')->get() as $device) {
            $status = $device->computedNetworkStatus();
            if ($device->network_status !== $status) {
                $device->forceFill(['network_status' => $status])->save();
            }
            $open = Incident::query()->where('device_id', $device->id)->whereIn('kind', ['device_unavailable', 'player_stopped'])->whereIn('status', ['open', 'acknowledged'])->first();
            // §10.16 : agent local joignable = application de lecture arrêtée (et non perte de réseau ou d'alimentation).
            $agentAlive = $device->agent_seen_at?->gt(now()->subSeconds(Device::thresholds()['unavailable'])) ?? false;
            if ($status === 'unavailable' && ! $open && $device->last_seen_at) {
                $incident = Incident::query()->create(['reference' => Reference::next('INC'), 'screen_id' => $device->screen_id, 'device_id' => $device->id, 'kind' => $agentAlive ? 'player_stopped' : 'device_unavailable', 'severity' => 'major',
                    'category' => $agentAlive ? 'player' : 'network',
                    'title' => ($agentAlive ? 'Application de lecture arrêtée ' : 'Lecteur indisponible ').($device->screen?->code ?? $device->code),
                    'impact' => $agentAlive ? 'Le poste répond (agent local) mais l’application de lecture ne contacte plus le serveur : relancer le lecteur' : 'Diffusion non garantie sur cet écran', 'status' => 'open', 'detected_at' => $device->last_seen_at->addSeconds(Device::thresholds()['unavailable']), 'is_automatic' => true,
                    'events' => [['at' => now()->toAtomString(), 'by' => 'Système', 'action' => 'Détection automatique (aucun contact depuis '.round(Device::thresholds()['unavailable'] / 60, 1).' min)']]]);
                // NOT-01 : l'incident est notifié aux gestionnaires des incidents, sans attendre l'ouverture de la supervision.
                $notifier->notify($this->incidentManagers(), 'incident.opened', 'Incident : '.$incident->title, $incident->impact, '/supervision', 'incident', $incident->id);
                $report['incidents_lecteurs']++;
            } elseif ($status === 'online' && $open) {
                $events = $open->events ?? [];
                $events[] = ['at' => now()->toAtomString(), 'by' => 'Système', 'action' => 'Contact rétabli'];
                // RG-012 : même une résolution automatique porte son résultat.
                $open->forceFill(['status' => 'resolved', 'resolved_at' => now(), 'events' => $events, 'resolution' => 'Contact rétabli : le lecteur a de nouveau transmis son battement.'])->save();
            }
        }

        $report['anomalies'] = $this->guard('anomalies', fn (): int => $this->detectAnomalies($notifier), 0);
        // §10.3 : incident non pris en charge à son échéance → escalade notifiée.
        $report['incidents_escaladés'] = $this->guard('escalade des incidents', fn (): int => app(IncidentPolicy::class)->escalateOverdue(), 0);
        // §8.3 : membres des groupes dynamiques recalculés (état de connexion, nouveaux écrans, déplacements).
        $report['groupes_dynamiques'] = $this->guard('groupes dynamiques', fn (): int => app(DynamicGroups::class)->syncAll(), 0);
        $report['états_écrans'] = $this->guard('historique des états', fn (): int => $this->recordScreenStates(), 0);
        $report['maintenance'] = $this->guard('maintenance', fn (): int => $this->openDueMaintenance($notifier), 0);

        $this->guard('purges', function (): void {
            DB::table('device_screenshots')->where('expires_at', '<', now())->whereNotNull('storage_key')->get()->each(function ($shot): void {
                Storage::disk('local')->delete($shot->storage_key);
                DB::table('device_screenshots')->where('id', $shot->id)->update(['storage_key' => null, 'status' => 'expired']);
            });
            // Fichier d'un export échu supprimé quel que soit son statut (déjà marqué « expiré » par une consultation, ou échoué puis relancé).
            DB::table('exports')->where('expires_at', '<', now())->whereNotNull('file_key')->get()->each(function ($export): void {
                Storage::disk('local')->delete($export->file_key);
                DB::table('exports')->where('id', $export->id)->update(['status' => 'expired', 'file_key' => null]);
            });
            DB::table('pairing_codes')->where('expires_at', '<', now()->subDay())->whereNull('used_at')->delete();
            // Jeton de lecteur jamais retiré : inutile après 10 minutes, il n'est pas conservé (ASVS 5.0 V9).
            DB::table('pairing_codes')->whereNotNull('issued_token')->where('updated_at', '<', now()->subMinutes(10))->update(['issued_token' => null]);
            DB::table('password_reset_tokens')->where('created_at', '<', now()->subHours(2))->delete();
            DB::table('idempotency_keys')->where('created_at', '<', now()->subDays(2))->delete();
            // Seuls les cinq derniers manifestes de chaque lecteur sont conservés (preuve de diffusion récente, volume borné).
            DB::statement('delete from manifests m using (select id, row_number() over (partition by device_id order by version desc) as rank from manifests) r where m.id = r.id and r.rank > 5');
            // La purge de conservation (télémétrie, notifications, archivage du journal) relève de saie:retention, planifiée chaque nuit.
        });

        $this->line(collect($report)->map(fn ($v, $k) => "$k=$v")->implode(' '));

        return self::SUCCESS;
    }

    /**
     * Exécute une étape en isolant ses erreurs : elles sont journalisées et les étapes suivantes s'exécutent quand même.
     *
     * @template T
     *
     * @param  callable(): T  $step
     * @param  T  $fallback
     * @return T
     */
    private function guard(string $label, callable $step, mixed $fallback = null): mixed
    {
        try {
            return $step();
        } catch (Throwable $e) {
            report($e);
            $this->error("Étape « $label » en échec : ".mb_substr($e->getMessage(), 0, 200));

            return $fallback;
        }
    }

    /**
     * Utilisateurs actifs dont un rôle en vigueur donne la gestion des incidents.
     *
     * @return Collection<int, User>
     */
    /**
     * Anomalies des lecteurs joignables (supervision M16) : stockage presque plein, horloge dérivée, programme récent non
     * appliqué. Un incident automatique par lecteur et par type ; résolu dès que la condition disparaît.
     */
    /**
     * §10.1, RG-014 : historique des états opérationnels. Une période est ouverte à chaque changement d'état et la précédente
     * fermée ; l'archivage clôt la dernière période de l'écran.
     */
    private function recordScreenStates(): int
    {
        $changes = 0;
        $open = Incident::query()->whereIn('status', ['open', 'acknowledged'])->get(['device_id', 'screen_id']);
        $withIncident = $open->pluck('device_id')->merge($open->pluck('screen_id'))->filter()->unique()->values()->all();
        $current = ScreenStatusHistory::query()->whereNull('ended_at')->get()->keyBy('screen_id');
        foreach (Screen::query()->with('activeDevice')->get() as $screen) {
            $state = Present::operationalState($screen, $screen->activeDevice, $withIncident)['code'];
            $period = $current->get($screen->id);
            if (($period && $period->state === $state) || (! $period && $state === 'archived')) {
                continue;
            }
            DB::transaction(function () use ($period, $screen, $state): void {
                $period?->forceFill(['ended_at' => now()])->save();
                if ($state !== 'archived') {
                    ScreenStatusHistory::query()->create([
                        'screen_id' => $screen->id, 'device_id' => $screen->activeDevice?->id, 'state' => $state, 'network_status' => $screen->activeDevice?->computedNetworkStatus(),
                        'panel_state' => $screen->control_channel === 'none' ? null : $screen->panel_state, 'started_at' => now(),
                    ]);
                }
            });
            $changes++;
        }

        return $changes;
    }

    private function detectAnomalies(Notifier $notifier): int
    {
        // §10.22 : le journal du flux temps réel ne garde qu'une heure (les clients reprennent au dernier identifiant reçu).
        RealtimeBus::purge();
        // §21.3 : promotion automatique des versions pilotes saines, si elle est activée.
        if (Setting::effective('releases.auto_promote')) {
            app(ReleasePromotion::class)->promoteEligible();
        }
        $opened = 0;
        foreach (Device::query()->with('screen')->where('status', 'active')->get() as $device) {
            $online = $device->computedNetworkStatus() === 'online';
            $quota = (float) ($device->storage['quota'] ?? 0);
            $hw = (array) ($device->hardware_info ?? []);
            // §10.2 : le disque réel (agent local) prime sur le quota du navigateur quand il est mesuré.
            $diskRatio = isset($hw['disk_total_gb'], $hw['disk_free_gb']) && (float) $hw['disk_total_gb'] > 0 ? 1 - (float) $hw['disk_free_gb'] / (float) $hw['disk_total_gb'] : null;
            $latest = Manifest::query()->where('device_id', $device->id)->orderByDesc('version')->first(['version', 'generated_at']);
            $playback = (array) ($device->current_playback ?? []);
            $playbackAt = isset($playback['at']) ? Carbon::parse($playback['at']) : null;
            $release = PlayerRelease::targetForDevice($device);
            // §21.2 : pertes de connexion répétées sur 24 h (incidents « lecteur indisponible » ouverts par la surveillance).
            $losses = Incident::query()->where('device_id', $device->id)->where('kind', 'device_unavailable')->where('detected_at', '>=', now()->subDay())->count();
            // §10.19 : programme émis vide (aucune campagne ne cible l'écran sur la période couverte), hors suspension.
            $screen = $device->screen;
            $panelOff = $online && $screen !== null && $screen->control_channel !== 'none' && in_array($screen->panel_state, ['off', 'standby'], true)
                && $screen->panel_state_at?->lt(now()->subMinutes(10)) && ! $device->playback_paused && ($playback['mode'] ?? null) !== 'off'
                && ! (($playback['mode'] ?? null) === 'fallback' && isset($screen->effectiveProfile()?->settings['idle_standby_min']))
                && app(ScheduleResolver::class)->withinOperatingWindow($screen->effectiveOperatingWindow(), [], CarbonImmutable::now($screen->effectiveTimezone()));
            $emptyProgramme = $latest !== null && $device->suspended_at === null && $device->screen?->kind === 'display' && $device->screen->status === 'active'
                && (int) DB::table('manifests')->where('device_id', $device->id)->where('version', $latest->version)->value(DB::raw("coalesce(jsonb_array_length(payload->'schedule'), 0)")) === 0;
            $errors = DB::table('device_receipts')->where('device_id', $device->id)->where('kind', 'error')->where('occurred_at', '>', now()->subHour())->count();
            $rules = [
                'storage_full' => [$online && ($diskRatio ?? ($quota > 0 ? (float) ($device->storage['usage'] ?? 0) / $quota : 0)) > (int) Setting::effective('anomalies.storage_ratio_pct') / 100,
                    'Stockage du lecteur presque plein', 'Les nouveaux médias risquent de ne plus pouvoir être téléchargés', 'player'],
                'clock_drift' => [$online && $device->clock_skew_ms !== null && abs((int) $device->clock_skew_ms) > 1000 * (int) Setting::effective('anomalies.clock_skew_s'),
                    'Horloge du lecteur déréglée', 'Les créneaux de diffusion peuvent être décalés', 'player'],
                'sync_stalled' => [$online && $latest && $latest->generated_at->lt(now()->subMinutes((int) Setting::effective('anomalies.sync_stalled_minutes'))) && (int) $device->active_manifest_version < $latest->version,
                    'Programme récent non appliqué', 'Le lecteur diffuse une version antérieure de son programme', 'software'],
                // §10.3 : lecteur joignable dont la lecture n'avance plus (application figée), hors arrêt commandé et hors plage.
                // Seule une diapositive programmée est horodatée à chaque passage : repli, alerte et suspension en sont exclus.
                'player_stalled' => [$online && ! $device->playback_paused && $device->suspended_at === null && $device->screen?->kind === 'display' && isset($playback['entry_id'])
                    && $playbackAt !== null && $playbackAt->lt(now()->subMinutes((int) Setting::effective('anomalies.stalled_minutes'))),
                    'Lecture arrêtée sur', 'Le lecteur répond mais ne déclare plus de lecture : application probablement figée', 'player'],
                'overheat' => [$online && isset($hw['temperature_c']) && (float) $hw['temperature_c'] >= (int) Setting::effective('anomalies.temperature_c'),
                    'Température élevée du lecteur', 'Température mesurée : '.($hw['temperature_c'] ?? '—').' °C', 'player'],
                'unstable_connection' => [$online && $losses >= (int) Setting::effective('anomalies.unstable_losses'),
                    'Connexion instable sur', $losses.' perte(s) de connexion en 24 h : vérifier le réseau ou l’alimentation', 'network'],
                'no_programme' => [$online && $emptyProgramme,
                    'Aucun programme pour', 'Aucune campagne ne cible cet écran sur la période : seul le contenu de repli est affiché', 'content'],
                // §10.18 : dalle mesurée éteinte pendant la plage de fonctionnement, sans arrêt commandé.
                'panel_off' => [$panelOff, 'Dalle éteinte pendant la plage sur', 'Le canal de pilotage mesure la dalle éteinte depuis plus de 10 minutes alors que l’écran devrait diffuser', 'panel'],
                // §10.19 : contenu de repli affiché durablement alors qu'un programme existe.
                // Lecteur suspendu ou écran hors service : le repli est l'affichage attendu, pas une anomalie.
                'fallback_prolonged' => [$online && ! $emptyProgramme && $device->suspended_at === null && $device->screen?->status === 'active' && ($playback['mode'] ?? null) === 'fallback' && $playbackAt !== null
                    && $playbackAt->lt(now()->subMinutes((int) Setting::effective('anomalies.fallback_minutes'))),
                    'Contenu de repli prolongé sur', 'Le programme existe mais le lecteur n’affiche que le contenu de repli (médias absents ou hors créneau)', 'content'],
                'playback_errors' => [$errors >= (int) Setting::effective('anomalies.errors_per_hour'),
                    'Échecs de lecture répétés sur', $errors.' erreur(s) de lecture déclarée(s) en une heure', 'content'],
                // Version obsolète : la version cible du canal est publiée depuis plus du délai réglé sans être appliquée.
                'outdated_version' => [$online && $release !== null && $device->app_version !== null && $device->app_version !== $release->version
                    && $release->created_at->lt(now()->subHours((int) Setting::effective('anomalies.outdated_hours'))),
                    'Version du lecteur obsolète sur', 'Version '.$device->app_version.' au lieu de '.($release?->version ?? '—').' (canal '.($device->release_channel ?? 'stable').')', 'software'],
            ];
            foreach ($rules as $kind => [$detected, $title, $impact, $category]) {
                $open = Incident::query()->where('device_id', $device->id)->where('kind', $kind)->whereIn('status', ['open', 'acknowledged'])->first();
                if ($detected && ! $open) {
                    $incident = Incident::query()->create(['reference' => Reference::next('INC'), 'screen_id' => $device->screen_id, 'device_id' => $device->id, 'kind' => $kind, 'category' => $category,
                        'severity' => $kind === 'player_stalled' ? 'major' : (in_array($kind, ['playback_errors', 'unstable_connection', 'panel_off'], true) ? 'medium' : 'minor'),
                        'title' => $title.' '.($device->screen?->code ?? $device->code), 'impact' => $impact, 'status' => 'open', 'detected_at' => now(), 'is_automatic' => true,
                        'events' => [['at' => now()->toAtomString(), 'by' => 'Système', 'action' => 'Détection automatique']]]);
                    $notifier->notify($this->incidentManagers(), 'incident.opened', 'Incident : '.$incident->title, $impact, '/supervision', 'incident', $incident->id);
                    $opened++;
                } elseif (! $detected && $open && $online) {
                    $events = $open->events ?? [];
                    $events[] = ['at' => now()->toAtomString(), 'by' => 'Système', 'action' => 'Condition disparue'];
                    $open->forceFill(['status' => 'resolved', 'resolved_at' => now(), 'events' => $events, 'resolution' => 'Condition disparue lors de la surveillance automatique.'])->save();
                }
            }
        }

        return $opened;
    }

    /**
     * INC-03 : chaque plan préventif arrivé à échéance ouvre une intervention (une seule à la fois par plan), notifiée.
     */
    private function openDueMaintenance(Notifier $notifier): int
    {
        $opened = 0;
        $today = now(config('saie.timezone'))->toDateString();
        foreach (MaintenancePlan::query()->with('screen')->where('is_active', true)->where('next_due_on', '<=', $today)->get() as $plan) {
            if (Incident::query()->where('maintenance_plan_id', $plan->id)->whereIn('status', ['open', 'acknowledged'])->exists()) {
                continue;
            }
            $incident = Incident::query()->create(['reference' => Reference::next('INC'), 'screen_id' => $plan->screen_id, 'device_id' => $plan->screen?->activeDevice?->id, 'kind' => 'preventive',
                'category' => $plan->category, 'maintenance_type' => 'preventive', 'severity' => 'minor', 'title' => 'Maintenance préventive : '.$plan->title, 'description' => $plan->instructions,
                'impact' => 'Intervention planifiée', 'status' => 'open', 'detected_at' => now(), 'is_automatic' => true, 'maintenance_plan_id' => $plan->id,
                'events' => [['at' => now()->toAtomString(), 'by' => 'Système', 'action' => 'Échéance du plan de maintenance']]]);
            $notifier->notify($this->incidentManagers(), 'incident.opened', 'Maintenance préventive à réaliser', $plan->title, '/supervision', 'incident', $incident->id);
            $opened++;
        }

        return $opened;
    }

    private function incidentManagers(): Collection
    {
        $roleIds = DB::table('permission_role')->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')->where('permissions.code', 'incidents.manage')->pluck('role_id');

        return User::query()->where('status', 'active')->whereHas('roleAssignments', fn ($q) => $q->whereIn('role_id', $roleIds)->whereNull('revoked_at')
            ->where('starts_at', '<=', now())->where(fn ($w) => $w->whereNull('ends_at')->orWhere('ends_at', '>', now())))->get();
    }

    private function link(Approval $approval): string
    {
        return $approval->object_type === 'content' ? "/affichage/contenus/{$approval->object_id}/validation?lang={$approval->lang}" : "/reservations/{$approval->object_id}";
    }
}
