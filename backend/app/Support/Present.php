<?php

namespace App\Support;

use App\Models\Alert;
use App\Models\Asset;
use App\Models\AssetVersion;
use App\Models\Campaign;
use App\Models\Content;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\Document;
use App\Models\Incident;
use App\Models\Reservation;
use App\Models\ReservationOccurrence;
use App\Models\Room;
use App\Models\Screen;
use App\Models\User;
use App\Services\RemoteCommands;
use DateTimeInterface;

/**
 * Formats de sortie de l'API. Le masquage des réunions confidentielles est centralisé ici (SAL-09, R11).
 */
class Present
{
    public static function date(?DateTimeInterface $date): ?string
    {
        return $date?->format(DateTimeInterface::ATOM);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function userRef(?User $user): ?array
    {
        return $user ? ['id' => $user->id, 'name' => $user->name, 'initials' => $user->initials()] : null;
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @param  list<string>|null  $devicesWithOpenIncident  Lecteurs ayant un incident ouvert, préchargés pour une liste
     */
    public static function screen(Screen $screen, ?array $devicesWithOpenIncident = null): array
    {
        $device = $screen->relationLoaded('activeDevice') ? $screen->activeDevice : null;

        return [
            'id' => $screen->id, 'version' => $screen->version, 'code' => $screen->code, 'inventory_code' => $screen->inventory_code, 'name' => $screen->name, 'kind' => $screen->kind,
            'orientation' => $screen->orientation, 'resolution' => $screen->resolution, 'status' => $screen->status,
            // Plage réellement appliquée par le lecteur : écran, sinon groupe, sinon paramètre général.
            'operating_window' => $screen->effectiveOperatingWindow(), 'window_source' => $screen->operating_window ? 'screen' : 'inherited', 'window_override_reason' => $screen->window_override_reason,
            'audio_enabled' => $screen->audio_enabled, 'touch_enabled' => (bool) $screen->touch_enabled, 'control_channel' => $screen->control_channel,
            'panel_state' => $screen->control_channel === 'none' || ! $screen->panel_state ? 'not_measured' : $screen->panel_state,
            'panel_state_at' => self::date($screen->panel_state_at),
            'location' => $screen->relationLoaded('location') && $screen->location ? [
                'id' => $screen->location->id, 'name' => $screen->location->name,
                'floor' => $screen->location->floor ? ['id' => $screen->location->floor->id, 'label' => $screen->location->floor->label, 'level' => $screen->location->floor->level] : null,
                'building' => $screen->location->floor?->relationLoaded('building') && $screen->location->floor->building ? ['id' => $screen->location->floor->building->id, 'name' => $screen->location->floor->building->name] : null,
                'type' => $screen->location->type, 'manager_id' => $screen->location->manager_id,
                'zone' => $screen->location->zone ? ['id' => $screen->location->zone->id, 'code' => $screen->location->zone->code, 'name' => $screen->location->zone->name, 'max_classification' => $screen->location->zone->max_classification] : null,
            ] : null,
            'room' => $screen->room_id ? ['id' => $screen->room_id, 'name' => $screen->room?->name] : null,
            'groups' => $screen->relationLoaded('groups') ? $screen->groups->map(fn ($g) => ['id' => $g->id, 'name' => $g->name])->values() : [],
            'device' => $device ? self::device($device) : null,
            'operational_state' => $screen->relationLoaded('activeDevice') ? self::operationalState($screen, $device, $devicesWithOpenIncident) : null,
            'maintenance_reason' => $screen->maintenance_reason, 'notes' => $screen->notes, 'location_detail' => $screen->location_detail,
            'plan' => $screen->plan_x !== null ? ['x' => (float) $screen->plan_x, 'y' => (float) $screen->plan_y] : null,
            'archived_at' => self::date($screen->archived_at), 'archive_reason' => $screen->archive_reason,
            'hardware' => [
                'manufacturer' => $screen->manufacturer, 'model' => $screen->model, 'serial_number' => $screen->serial_number, 'diagonal_inches' => $screen->diagonal_inches,
                'ip_address' => $screen->ip_address, 'connection_type' => $screen->connection_type,
                'installed_on' => $screen->installed_on?->format('Y-m-d'), 'warranty_until' => $screen->warranty_until?->format('Y-m-d'),
                'acquired_on' => $screen->acquired_on?->format('Y-m-d'), 'supplier' => $screen->supplier, 'order_reference' => $screen->order_reference,
                'technology' => $screen->technology, 'power_watts' => $screen->power_watts, 'mac_address' => $screen->mac_address, 'os_name' => $screen->os_name, 'firmware_version' => $screen->firmware_version,
                'under_warranty' => $screen->warranty_until ? $screen->warranty_until->endOfDay()->isFuture() : null,
            ],
            'is_demo' => $screen->is_demo,
            'category' => $screen->relationLoaded('category') && $screen->category ? ['id' => $screen->category->id, 'name' => $screen->category->name] : null,
            'interfaces' => $screen->interfaces ?? [], 'operating_mode' => $screen->operating_mode, 'criticality' => $screen->criticality ?? 'normal',
            'commissioning' => $screen->commissioned_at ? [
                'at' => self::date($screen->commissioned_at), 'by' => $screen->relationLoaded('commissioner') ? self::userRef($screen->commissioner) : null, 'report' => $screen->commissioning_report,
            ] : null,
        ];
    }

    /**
     * État opérationnel d'un écran, du plus grave au plus favorable. « Lecteur joignable » n'atteste jamais que la dalle est
     * allumée ni que le contenu est vu : ces niveaux de preuve relèvent du suivi de diffusion (annexe C).
     *
     * @return array{code: string, label: string}
     */
    /**
     * @param  list<string>|null  $devicesWithOpenIncident  Lecteurs et écrans ayant un incident ouvert, préchargés pour une liste (évite une requête par écran)
     */
    public static function operationalState(Screen $screen, ?Device $device, ?array $devicesWithOpenIncident = null): array
    {
        $network = $device?->computedNetworkStatus();
        $playback = (array) ($device?->current_playback ?? []);
        $recent = isset($playback['at']) && strtotime((string) $playback['at']) >= now()->subMinutes(15)->getTimestamp();
        [$code, $label] = match (true) {
            $screen->status === 'archived' => ['archived', 'Archivé'],
            $screen->status === 'inactive' => ['disabled', 'Désactivé'],
            $screen->status === 'maintenance' => ['maintenance', 'En maintenance'],
            $device === null => ['pending_configuration', 'En attente de configuration (aucun lecteur appairé)'],
            $device->suspended_at !== null => ['suspended', 'Lecteur suspendu'],
            // §10.16 : application de lecture muette mais agent local joignable : le poste et le réseau fonctionnent.
            in_array($network, ['suspect', 'unavailable', 'unknown'], true) && $device->agent_seen_at?->gt(now()->subSeconds(Device::thresholds()['unavailable'])) => ['player_stopped', 'Lecteur arrêté (poste joignable)'],
            in_array($network, ['suspect', 'unavailable', 'unknown'], true) => ['offline', 'Hors ligne'],
            $device->recentErrors() !== [] || ($devicesWithOpenIncident !== null
                ? in_array($device->id, $devicesWithOpenIncident, true) || in_array($screen->id, $devicesWithOpenIncident, true)
                : Incident::query()->where(fn ($q) => $q->where('device_id', $device->id)->orWhere('screen_id', $screen->id))->whereIn('status', ['open', 'acknowledged'])->exists()) => ['anomaly', 'En anomalie'],
            (bool) $device->playback_paused => ['paused', 'Diffusion arrêtée à distance'],
            // Veille : dalle mesurée éteinte par son canal de pilotage, ou lecteur hors de sa plage de fonctionnement.
            ($screen->control_channel !== 'none' && in_array($screen->panel_state, ['standby', 'off'], true)) || ($playback['mode'] ?? null) === 'off' => ['standby', 'En veille'],
            // En diffusion : le lecteur joignable déclare une diapositive ou une alerte affichée récemment (lecture déclarée).
            (isset($playback['entry_id']) || isset($playback['alert'])) && $recent => ['broadcasting', 'En diffusion (lecture déclarée)'],
            ($playback['mode'] ?? null) === 'fallback' => ['fallback', 'Contenu de repli (aucun programme)'],
            default => ['active', 'Lecteur joignable'],
        };

        return ['code' => $code, 'label' => $label];
    }

    /**
     * @return array<string, mixed>
     */
    public static function device(Device $device): array
    {
        return [
            'id' => $device->id, 'code' => $device->code, 'status' => $device->status, 'hardware_id' => $device->hardware_id,
            'network_status' => $device->computedNetworkStatus(), 'last_seen_at' => self::date($device->last_seen_at), 'last_sync_at' => self::date($device->last_sync_at),
            'pending_command' => $device->pending_command, 'command_requested_at' => self::date($device->command_requested_at), 'command_delivered_at' => self::date($device->command_delivered_at),
            'app_version' => $device->app_version, 'release_channel' => $device->release_channel, 'platform' => $device->platform, 'storage' => $device->storage, 'clock_skew_ms' => $device->clock_skew_ms,
            'active_manifest_version' => $device->active_manifest_version, 'current_playback' => $device->current_playback, 'last_errors' => $device->last_errors,
            'cache_inventory' => $device->cache_inventory, 'paired_at' => self::date($device->paired_at), 'revoked_at' => self::date($device->revoked_at), 'revoke_reason' => $device->revoke_reason,
            'capabilities' => $device->capabilities, 'capabilities_reported_at' => self::date($device->capabilities_reported_at), 'playback_paused' => (bool) $device->playback_paused,
            'hardware_info' => $device->hardware_info,
            'reported_resolution' => $device->reported_resolution, 'name' => $device->name, 'hardware_type' => $device->hardware_type, 'serial_number' => $device->serial_number,
            'rotate_requested_at' => self::date($device->rotate_requested_at), 'pinned_release_id' => $device->pinned_release_id,
            'agent_seen_at' => self::date($device->agent_seen_at), 'observed_ip' => $device->observed_ip, 'offline_since' => self::date($device->offline_since), 'events_dropped' => (int) $device->events_dropped,
            'suspended_at' => self::date($device->suspended_at), 'suspend_reason' => $device->suspend_reason,
        ];
    }

    /**
     * Commande à distance (§11.2) : identifiant de transaction, état, auteur, horodatages et compte rendu du lecteur.
     *
     * @return array<string, mixed>
     */
    public static function deviceCommand(DeviceCommand $c): array
    {
        return [
            'id' => $c->id, 'device_id' => $c->device_id, 'screen_id' => $c->screen_id, 'command' => $c->command, 'label' => RemoteCommands::CATALOGUE[$c->command]['label'] ?? $c->command,
            'status' => $c->status, 'reason' => $c->reason, 'requested_by' => self::userRef($c->requester), 'requested_at' => self::date($c->requested_at),
            'sent_at' => self::date($c->sent_at), 'received_at' => self::date($c->received_at), 'completed_at' => self::date($c->completed_at), 'expires_at' => self::date($c->expires_at),
            'result_message' => $c->result_message, 'result_data' => $c->result_data, 'screenshot_id' => $c->params['screenshot_id'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function assetVersion(?AssetVersion $v): ?array
    {
        return $v ? [
            'id' => $v->id, 'version' => $v->version, 'original_name' => $v->original_name, 'mime' => $v->mime, 'extension' => $v->extension, 'size' => (int) $v->size,
            'sha256' => $v->sha256, 'width' => $v->width, 'height' => $v->height, 'duration_s' => $v->duration_s, 'pages' => $v->pages,
            'scan_status' => $v->scan_status, 'scan_engine' => $v->scan_engine, 'scan_detail' => $v->scan_detail, 'has_thumbnail' => (bool) $v->thumbnail_key,
            'has_consultation_pdf' => ! empty($v->derivatives['consultation_pdf']), 'video' => $v->derivatives['video'] ?? null,
            'broadcastable_as_pdf' => ! empty($v->derivatives['broadcast_pdf']['sha256']), 'broadcast_pages' => $v->derivatives['broadcast_pdf']['pages'] ?? null,
            'created_at' => self::date($v->created_at), 'processed_at' => self::date($v->processed_at),
        ] : null;
    }

    /**
     * @return array<string, mixed>
     */
    public static function asset(Asset $asset, ?int $usages = null): array
    {
        return [
            'id' => $asset->id, 'version' => $asset->version, 'reference' => $asset->reference, 'title' => $asset->title, 'kind' => $asset->kind, 'classification' => $asset->classification,
            'language' => $asset->language, 'status' => $asset->status, 'owner' => $asset->owner ? ['id' => $asset->owner->id, 'name' => $asset->owner->name] : null,
            'category' => $asset->category?->name, 'category_id' => $asset->category_id, 'creator' => self::userRef($asset->creator), 'current_version' => self::assetVersion($asset->currentVersion),
            'usages' => $usages, 'created_at' => self::date($asset->created_at), 'updated_at' => self::date($asset->updated_at), 'is_demo' => $asset->is_demo,
            'tags' => $asset->tags ?? [], 'usage_rights' => $asset->usage_rights, 'rights_expire_on' => $asset->rights_expire_on?->format('Y-m-d'),
            'rights_expired' => $asset->rightsExpired(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function document(Document $d): array
    {
        return [
            'id' => $d->id, 'version' => $d->version, 'reference' => $d->reference, 'title' => $d->title, 'summary' => $d->summary, 'classification' => $d->classification, 'status' => $d->status,
            'language' => $d->language, 'keywords' => $d->keywords ?? [], 'category' => $d->category ? ['id' => $d->category->id, 'name' => $d->category->name] : null,
            'owner' => $d->owner ? ['id' => $d->owner->id, 'name' => $d->owner->name] : null, 'author' => self::userRef($d->author),
            'valid_from' => $d->valid_from?->toDateString(), 'expires_at' => $d->expires_at?->toDateString(),
            'current_version' => $d->currentVersion ? ['id' => $d->currentVersion->id, 'version' => $d->currentVersion->version, 'file' => self::assetVersion($d->currentVersion->assetVersion)] : null,
            'diffusion_allowed' => $d->classification !== 'restreint', 'published_at' => self::date($d->published_at), 'updated_at' => self::date($d->updated_at), 'is_demo' => $d->is_demo,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function content(Content $c): array
    {
        return [
            'id' => $c->id, 'reference' => $c->reference, 'title' => $c->title, 'kind' => $c->kind, 'status' => $c->status, 'classification' => $c->classification,
            'priority' => $c->priority, 'version' => $c->version, 'category' => $c->category ? ['id' => $c->category->id, 'name' => $c->category->name] : null,
            'owner' => $c->owner ? ['id' => $c->owner->id, 'name' => $c->owner->name] : null, 'author' => self::userRef($c->author),
            'template' => $c->template ? ['id' => $c->template->id, 'name' => $c->template->name, 'orientation' => $c->template->orientation] : null,
            'media' => $c->media ?? [], 'qr' => $c->qr, 'proposed_targets' => $c->proposed_targets, 'valid_from' => self::date($c->valid_from), 'expires_at' => self::date($c->expires_at),
            'is_permanent' => $c->is_permanent, 'delegation' => $c->delegation, 'occurrence_id' => $c->occurrence_id,
            'languages' => $c->relationLoaded('languages') ? $c->languages->sortBy(fn ($l) => array_search($l->lang, ['fr', 'en', 'pt', 'es'], true))->map(fn ($l) => [
                'id' => $l->id, 'lang' => $l->lang, 'revision' => $l->revision, 'title' => $l->title, 'body' => $l->body, 'extra' => $l->extra, 'status' => $l->status,
                'overflow' => $l->overflow, 'subtitles_asset_version_id' => $l->subtitles_asset_version_id, 'submitted_at' => self::date($l->submitted_at), 'last_return_reason' => $l->last_return_reason,
            ])->values() : [],
            'updated_at' => self::date($c->updated_at), 'created_at' => self::date($c->created_at), 'is_demo' => $c->is_demo,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function campaign(Campaign $c): array
    {
        return [
            'id' => $c->id, 'reference' => $c->reference, 'name' => $c->name, 'kind' => $c->kind ?? 'standard', 'status' => $c->status, 'priority' => $c->priority, 'version' => $c->version,
            'starts_at' => self::date($c->starts_at), 'ends_at' => self::date($c->ends_at), 'days' => $c->days ?? [], 'time_from' => $c->time_from ? substr($c->time_from, 0, 5) : null,
            'time_to' => $c->time_to ? substr($c->time_to, 0, 5) : null, 'slide_duration_s' => $c->slide_duration_s, 'suspend_reason' => $c->suspend_reason,
            'creator' => self::userRef($c->creator), 'published_at' => self::date($c->published_at),
            'languages' => $c->relationLoaded('languages') ? $c->languages->map(fn ($l) => ['lang' => $l->lang, 'rank' => $l->rank])->values() : [],
            'items' => $c->relationLoaded('items') ? $c->items->map(fn ($i) => [
                'id' => $i->id, 'position' => $i->position, 'duration_s' => $i->duration_s, 'version_map' => $i->version_map,
                'content' => $i->content ? ['id' => $i->content->id, 'reference' => $i->content->reference, 'title' => $i->content->title, 'classification' => $i->content->classification, 'status' => $i->content->status] : null,
            ])->values() : [],
            'targets' => $c->relationLoaded('targets') ? $c->targets->map(fn ($t) => ['type' => $t->target_type, 'id' => $t->target_id])->values() : [],
            'is_demo' => $c->is_demo,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function alert(Alert $a): array
    {
        return [
            'id' => $a->id, 'reference' => $a->reference, 'title' => $a->title, 'message' => $a->message, 'translations' => $a->translations, 'severity' => $a->severity,
            'status' => match (true) {
                $a->status === 'active' && $a->expires_at->isPast() => 'expired',
                $a->status === 'active' && $a->starts_at->isFuture() => 'scheduled',
                default => $a->status,
            }, 'starts_at' => self::date($a->starts_at), 'expires_at' => self::date($a->expires_at),
            'author' => self::userRef($a->author), 'justification' => $a->justification, 'revoked_at' => self::date($a->revoked_at), 'revoker' => self::userRef($a->revoker),
            'revoke_reason' => $a->revoke_reason, 'approved_at' => self::date($a->approved_at), 'requested_duration_min' => $a->requested_duration_min, 'extensions' => $a->extensions ?? [], 'target_count' => $a->target_count, 'offline_at_publish' => $a->offline_at_publish,
            'targets' => $a->relationLoaded('targets') ? $a->targets->map(fn ($t) => ['type' => $t->target_type, 'id' => $t->target_id])->values() : [],
            'receipts' => $a->relationLoaded('receipts') ? ['received' => $a->receipts->whereNotNull('received_at')->count(), 'activated' => $a->receipts->whereNotNull('activated_at')->count()] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function room(Room $r): array
    {
        return [
            'id' => $r->id, 'version' => $r->version, 'code' => $r->code, 'name' => $r->name, 'capacity' => $r->capacity, 'location_text' => $r->location_text, 'location_id' => $r->location_id, 'photos' => $r->photos ?? [],
            'floor' => $r->floor ? ['id' => $r->floor->id, 'label' => $r->floor->label, 'level' => $r->floor->level] : null,
            'manager' => self::userRef($r->manager), 'approval_mode' => $r->approval_mode, 'min_minutes' => $r->min_minutes, 'max_minutes' => $r->max_minutes,
            'lead_days' => $r->lead_days, 'prep_minutes' => $r->prep_minutes, 'cleanup_minutes' => $r->cleanup_minutes,
            'open_from' => substr((string) $r->open_from, 0, 5), 'open_to' => substr((string) $r->open_to, 0, 5), 'pending_hold_hours' => $r->pending_hold_hours,
            'status' => $r->status, 'auto_release_enabled' => $r->auto_release_enabled,
            'equipment' => $r->relationLoaded('equipment') ? $r->equipment->map(fn ($e) => ['id' => $e->id, 'code' => $e->code, 'name' => $e->name, 'quantity' => $e->pivot->quantity])->values() : [],
            'is_demo' => $r->is_demo,
        ];
    }

    /**
     * Une réunion confidentielle n'expose ni objet, ni organisateur, ni participants à qui n'en est pas l'organisateur ou le gestionnaire.
     *
     * @return array<string, mixed>
     */
    public static function reservation(Reservation $r, bool $full, bool $isParticipant = false): array
    {
        // SAL-02, SAL-09 : pour un tiers, l'objet et les participants d'une demande encore en attente sont masqués comme ceux d'une
        // réunion confidentielle (seuls le créneau et la salle sont communiqués avant validation).
        $pendingOnly = $r->relationLoaded('occurrences') && $r->occurrences->isNotEmpty() && $r->occurrences->every(fn ($o) => $o->status === 'pending');
        $masked = ($r->confidential || $pendingOnly) && ! $full;
        // Décision D7 : la liste des participants d'une réunion standard est réservée à la vue complète et aux participants nommés.
        $participantsVisible = $full || ($isParticipant && ! $masked);

        return [
            'id' => $r->id, 'reference' => $r->reference, 'confidential' => $r->confidential, 'version' => $r->version,
            'subject' => $masked ? 'Réservée' : $r->subject, 'description' => $masked ? null : $r->description,
            'organizer' => $masked ? null : self::userRef($r->organizer), 'structure' => $masked ? null : $r->structure?->name,
            'participant_count' => $r->participant_count, 'internal_participants' => $participantsVisible ? $r->internal_participants : null,
            'external_participants' => $participantsVisible ? $r->external_participants : null, 'recurrence' => $r->recurrence, 'is_priority' => $r->is_priority,
            'created_at' => self::date($r->created_at), 'is_demo' => $r->is_demo,
            'occurrences' => $r->relationLoaded('occurrences') ? $r->occurrences->sortBy('starts_at')->map(fn ($o) => self::occurrence($o))->values() : [],
            'agenda' => $masked || ! $r->relationLoaded('agendaItems') ? [] : $r->agendaItems->sortBy('position')->map(fn ($a) => ['id' => $a->id, 'position' => $a->position, 'title' => $a->title, 'time_label' => $a->time_label, 'presenter' => $a->presenter])->values(),
            'can_manage' => $full,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function occurrence(ReservationOccurrence $o): array
    {
        return [
            'id' => $o->id, 'sequence' => $o->sequence, 'status' => $o->status, 'version' => $o->version,
            'starts_at' => self::date($o->starts_at), 'ends_at' => self::date($o->ends_at), 'blocking_start' => self::date($o->blocking_start), 'blocking_end' => self::date($o->blocking_end),
            'hold_until' => self::date($o->hold_until), 'decision_reason' => $o->decision_reason, 'cancel_reason' => $o->cancel_reason,
            'no_show_reason' => $o->no_show_reason, 'no_show_at' => self::date($o->no_show_at), 'checked_in_at' => self::date($o->checked_in_at),
            'room' => $o->relationLoaded('room') && $o->room ? ['id' => $o->room->id, 'code' => $o->room->code, 'name' => $o->room->name, 'floor' => $o->room->floor?->label, 'capacity' => $o->room->capacity] : ['id' => $o->room_id],
            'services' => $o->relationLoaded('serviceRequests') ? $o->serviceRequests->map(fn ($s) => ['id' => $s->id, 'type' => $s->serviceType?->name, 'label' => $s->label(), 'headcount' => $s->headcount, 'serve_time' => $s->serve_time, 'status' => $s->status, 'details' => $s->details])->values() : [],
        ];
    }
}
