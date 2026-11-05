<?php

namespace App\Console\Commands;

use App\Models\Alert;
use App\Models\Approval;
use App\Models\Content;
use App\Models\Device;
use App\Models\Incident;
use App\Models\ReservationOccurrence;
use App\Models\User;
use App\Services\Audit;
use App\Services\CampaignService;
use App\Services\ContentWorkflow;
use App\Services\DynamicSources;
use App\Services\Notifier;
use App\Services\ReservationService;
use App\Support\Reference;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

#[Signature('saie:tick')]
#[Description('Traitements périodiques SAIE : expirations, statuts, rappels et escalades, supervision des lecteurs, purges')]
class SaieTick extends Command
{
    public function handle(CampaignService $campaigns, ContentWorkflow $workflow, Notifier $notifier, Audit $audit): int
    {
        $report = [];
        $report['campagnes'] = $campaigns->tick();

        $report['alertes_expirées'] = Alert::query()->where('status', 'active')->where('expires_at', '<=', now())->update(['status' => 'expired', 'updated_at' => now()]);

        foreach (Content::query()->whereNotIn('status', ['archived', 'expired'])->where('is_permanent', false)->whereNotNull('expires_at')->where('expires_at', '<=', now())->get() as $content) {
            $workflow->refreshStatus($content);
            $audit->log('content.expired', $content, actor: null);
        }
        foreach (Content::query()->whereNotIn('status', ['archived', 'expired', 'draft'])->where('is_permanent', false)->whereBetween('expires_at', [now(), now()->addDays(2)])->with('author')->get() as $content) {
            $notifier->notify($content->author, 'content.expiring', 'Expiration prochaine', $content->title, "/affichage/contenus/{$content->id}/edition", 'content', $content->id);
        }

        $report['réunions_en_cours'] = ReservationOccurrence::query()->where('status', 'confirmed')->where('starts_at', '<=', now())->where('ends_at', '>', now())->update(['status' => 'in_progress', 'updated_at' => now()]);
        $report['sources'] = app(DynamicSources::class)->collectDue();
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
            $notifier->notify($occurrence->reservation->organizer, 'reservation.expired', 'Demande de réservation expirée', $occurrence->room->name, "/reservations/{$occurrence->reservation_id}", 'reservation', $occurrence->reservation_id, 'hold-'.$occurrence->id);
            $audit->log('reservation.hold_expired', $occurrence->reservation, 'Délai de blocage dépassé', actor: null);
            // Plus aucune occurrence en attente : la validation n'a plus d'objet (sinon rappels et escalades sur une demande morte).
            if (! $occurrence->reservation->occurrences()->where('status', 'pending')->exists()) {
                Approval::query()->where('object_type', 'reservation')->where('object_id', $occurrence->reservation_id)->where('status', 'open')
                    ->update(['status' => 'cancelled', 'reason' => 'Délai de validation dépassé', 'updated_at' => now()]);
            }
        }

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
                    $admins = User::query()->where('status', 'active')->whereHas('roleAssignments', fn ($q) => $q->whereNull('revoked_at')->where('starts_at', '<=', now())
                        ->where(fn ($w) => $w->whereNull('ends_at')->orWhere('ends_at', '>', now()))
                        ->whereHas('role', fn ($r) => $r->where('code', 'admin_fonc')))->get();
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
            $open = Incident::query()->where('device_id', $device->id)->where('kind', 'device_unavailable')->whereIn('status', ['open', 'acknowledged'])->first();
            if ($status === 'unavailable' && ! $open && $device->last_seen_at) {
                Incident::query()->create(['reference' => Reference::next('INC'), 'screen_id' => $device->screen_id, 'device_id' => $device->id, 'kind' => 'device_unavailable', 'severity' => 'major',
                    'title' => 'Lecteur indisponible '.($device->screen?->code ?? $device->code), 'impact' => 'Diffusion non garantie sur cet écran', 'status' => 'open', 'detected_at' => $device->last_seen_at->addSeconds((int) config('saie.device.unavailable_after_s')), 'is_automatic' => true,
                    'events' => [['at' => now()->toAtomString(), 'by' => 'Système', 'action' => 'Détection automatique (aucun contact depuis 5 min)']]]);
                $report['incidents_lecteurs']++;
            } elseif ($status === 'online' && $open) {
                $events = $open->events ?? [];
                $events[] = ['at' => now()->toAtomString(), 'by' => 'Système', 'action' => 'Contact rétabli'];
                $open->forceFill(['status' => 'resolved', 'resolved_at' => now(), 'events' => $events])->save();
            }
        }

        DB::table('device_screenshots')->where('expires_at', '<', now())->whereNotNull('storage_key')->get()->each(function ($shot): void {
            Storage::disk('local')->delete($shot->storage_key);
            DB::table('device_screenshots')->where('id', $shot->id)->update(['storage_key' => null, 'status' => 'expired']);
        });
        DB::table('exports')->where('status', 'ready')->where('expires_at', '<', now())->get()->each(function ($export): void {
            if ($export->file_key) {
                Storage::disk('local')->delete($export->file_key);
            }
            DB::table('exports')->where('id', $export->id)->update(['status' => 'expired', 'file_key' => null]);
        });
        DB::table('pairing_codes')->where('expires_at', '<', now()->subDay())->whereNull('used_at')->delete();
        DB::table('idempotency_keys')->where('created_at', '<', now()->subDays(2))->delete();
        if (config('saie.retention.auto_purge_enabled')) {
            DB::table('device_telemetry')->where('recorded_at', '<', now()->subDays((int) config('saie.retention.telemetry_days')))->delete();
        }

        $this->line(collect($report)->map(fn ($v, $k) => "$k=$v")->implode(' '));

        return self::SUCCESS;
    }

    private function link(Approval $approval): string
    {
        return $approval->object_type === 'content' ? "/affichage/contenus/{$approval->object_id}/validation?lang={$approval->lang}" : "/reservations/{$approval->object_id}";
    }
}
