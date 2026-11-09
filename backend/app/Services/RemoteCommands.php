<?php

namespace App\Services;

use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\DeviceScreenshot;
use App\Models\User;
use App\Support\BusinessException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Commandes à distance (§11, RG-006, RG-007).
 *
 * Cycle : en attente → envoyée (remise au battement suivant) → reçue (accusé du lecteur) → exécutée ou échouée (compte rendu
 * du lecteur). Sans confirmation avant l'échéance, la commande expire ; tant qu'elle n'est pas reçue, elle peut être annulée.
 * Une commande n'est proposée que si le lecteur a déclaré la capacité correspondante.
 */
class RemoteCommands
{
    /**
     * Catalogue : libellé, capacité exigée du lecteur, durée de validité (minutes), motif de l'indisponibilité matérielle.
     *
     * @var array<string, array{label: string, capability: string, ttl: int, unsupported: string}>
     */
    public const CATALOGUE = [
        'sync' => ['label' => 'Synchroniser', 'capability' => 'sync', 'ttl' => 15, 'unsupported' => 'Version du lecteur sans synchronisation forcée.'],
        'refresh' => ['label' => 'Actualiser les contenus', 'capability' => 'refresh', 'ttl' => 15, 'unsupported' => 'Version du lecteur sans rechargement des contenus.'],
        'restart_player' => ['label' => 'Redémarrer le lecteur', 'capability' => 'restart_player', 'ttl' => 15, 'unsupported' => 'Version du lecteur sans relance à distance.'],
        'reboot_device' => ['label' => 'Redémarrer l’appareil', 'capability' => 'reboot_device', 'ttl' => 15, 'unsupported' => 'L’agent local n’a pas de commande de redémarrage configurée (SAIE_REBOOT_CMD) ou n’est pas installé.'],
        'stop_playback' => ['label' => 'Arrêter la diffusion', 'capability' => 'stop_playback', 'ttl' => 15, 'unsupported' => 'Version du lecteur sans arrêt de diffusion.'],
        'resume_playback' => ['label' => 'Reprendre la diffusion', 'capability' => 'resume_playback', 'ttl' => 15, 'unsupported' => 'Version du lecteur sans reprise de diffusion.'],
        'standby' => ['label' => 'Mettre en veille', 'capability' => 'panel_power', 'ttl' => 15, 'unsupported' => 'Veille matérielle indisponible : aucun canal de pilotage de la dalle (HDMI-CEC, RS-232, API) n’est joignable par l’agent local.'],
        'wake' => ['label' => 'Sortir de veille', 'capability' => 'panel_power', 'ttl' => 15, 'unsupported' => 'Réveil matériel indisponible : aucun canal de pilotage de la dalle n’est joignable par l’agent local.'],
        'screenshot' => ['label' => 'Capturer l’écran', 'capability' => 'screenshot', 'ttl' => 10, 'unsupported' => 'Version du lecteur sans capture.'],
        'logs' => ['label' => 'Consulter les journaux', 'capability' => 'logs', 'ttl' => 15, 'unsupported' => 'Version du lecteur sans remontée de journaux.'],
    ];

    /** Transitions permises à l'accusé du lecteur, depuis chaque état. */
    private const ACK = [
        'received' => ['pending', 'sent'],
        'executed' => ['pending', 'sent', 'received'],
        'failed' => ['pending', 'sent', 'received'],
    ];

    public function __construct(private Audit $audit) {}

    /**
     * Commandes proposables pour un lecteur, chacune avec sa disponibilité et, sinon, la raison (RG-006).
     *
     * @return list<array{command: string, label: string, available: bool, reason: string|null}>
     */
    public function availability(Device $device): array
    {
        $declared = $device->capabilities;
        $open = $device->commands()->whereIn('status', DeviceCommand::OPEN)->where('expires_at', '>', now())->pluck('command')->all();

        return collect(self::CATALOGUE)->map(function (array $spec, string $command) use ($device, $declared, $open): array {
            $reason = match (true) {
                $device->status !== 'active' => 'Lecteur révoqué.',
                $declared === null => 'Le lecteur n’a pas encore déclaré ses capacités (version antérieure ou aucun battement reçu depuis sa mise à jour).',
                ! in_array($spec['capability'], $declared, true) => $spec['unsupported'],
                $command === 'stop_playback' && $device->playback_paused => 'La diffusion est déjà arrêtée.',
                $command === 'resume_playback' && ! $device->playback_paused => 'La diffusion n’est pas arrêtée.',
                in_array($command, $open, true) => 'Une commande identique est déjà en cours.',
                default => null,
            };

            return ['command' => $command, 'label' => $spec['label'], 'available' => $reason === null, 'reason' => $reason];
        })->values()->all();
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function request(Device $device, string $command, User $user, ?string $reason = null, array $params = []): DeviceCommand
    {
        $state = collect($this->availability($device))->firstWhere('command', $command);
        if ($state === null) {
            throw new BusinessException('COMMAND_UNKNOWN', 'Commande inconnue.', 422);
        }
        if (! $state['available']) {
            $this->audit->log('device.command_refused', $device, $state['reason'], after: ['command' => $command], outcome: 'denied', actor: $user);
            throw new BusinessException('COMMAND_UNAVAILABLE', $state['reason'], 409, ['command' => $command]);
        }

        return DB::transaction(function () use ($device, $command, $user, $reason, $params): DeviceCommand {
            if ($command === 'screenshot') {
                $shot = DeviceScreenshot::query()->create(['device_id' => $device->id, 'requested_by' => $user->id, 'requested_at' => now(), 'status' => 'requested', 'expires_at' => now()->addMinutes(self::CATALOGUE['screenshot']['ttl'])]);
                $params['screenshot_id'] = $shot->id;
            }
            $entry = DeviceCommand::query()->create([
                'device_id' => $device->id, 'screen_id' => $device->screen_id, 'command' => $command, 'params' => $params ?: null, 'status' => 'pending', 'reason' => $reason,
                'requested_by' => $user->id, 'requested_at' => now(), 'expires_at' => now()->addMinutes(self::CATALOGUE[$command]['ttl']),
            ]);
            $this->audit->log('device.command_requested', $device, $reason, after: ['command' => $command, 'transaction' => $entry->id], actor: $user);

            return $entry;
        });
    }

    /**
     * Commandes à remettre au lecteur lors de son battement. Une commande envoyée mais non acquittée est renvoyée (perte de
     * réponse réseau) jusqu'à son échéance ; le lecteur dédoublonne par identifiant.
     *
     * @return list<array{id: string, command: string, params: array<string, mixed>|null, expires_at: string}>
     */
    public function deliver(Device $device): array
    {
        $commands = $device->commands()->whereIn('status', ['pending', 'sent'])->where('expires_at', '>', now())->orderBy('requested_at')->get();
        DeviceCommand::query()->whereIn('id', $commands->where('status', 'pending')->pluck('id'))->update(['status' => 'sent', 'sent_at' => now(), 'updated_at' => now()]);

        return $commands->map(fn (DeviceCommand $c) => ['id' => $c->id, 'command' => $c->command, 'params' => $c->params, 'expires_at' => $c->expires_at->toIso8601String()])->values()->all();
    }

    /**
     * Accusé du lecteur. Idempotent : un accusé répété ou en retard sur un état plus avancé est ignoré sans erreur.
     *
     * @param  array<string, mixed>|null  $data
     */
    public function acknowledge(Device $device, DeviceCommand $command, string $status, ?string $message, ?array $data): DeviceCommand
    {
        if ($command->device_id !== $device->id) {
            throw new BusinessException('COMMAND_NOT_FOUND', 'Commande inconnue pour ce lecteur.', 404);
        }

        return DB::transaction(function () use ($device, $command, $status, $message, $data): DeviceCommand {
            $command = DeviceCommand::query()->lockForUpdate()->findOrFail($command->id);
            if (! in_array($command->status, self::ACK[$status], true)) {
                return $command;
            }
            $changes = ['status' => $status, 'received_at' => $command->received_at ?? now(), 'sent_at' => $command->sent_at ?? now()];
            if ($status !== 'received') {
                $changes += ['completed_at' => now(), 'result_message' => $message !== null ? mb_substr($message, 0, 500) : null, 'result_data' => $data];
            }
            $command->forceFill($changes)->save();
            if ($status === 'executed' && in_array($command->command, ['stop_playback', 'resume_playback'], true)) {
                $device->forceFill(['playback_paused' => $command->command === 'stop_playback'])->save();
            }
            if ($status !== 'received') {
                $this->audit->log("device.command_$status", $device, $message, after: ['command' => $command->command, 'transaction' => $command->id], outcome: $status === 'executed' ? 'success' : 'failure', objectLabel: $device->code);
            }

            return $command;
        });
    }

    public function cancel(DeviceCommand $command, User $user): DeviceCommand
    {
        if (! in_array($command->status, ['pending', 'sent'], true)) {
            throw new BusinessException('COMMAND_NOT_CANCELLABLE', 'Seule une commande que le lecteur n’a pas encore reçue peut être annulée.', 409);
        }
        $command->forceFill(['status' => 'cancelled', 'completed_at' => now(), 'cancelled_by' => $user->id])->save();
        $this->audit->log('device.command_cancelled', $command->device, after: ['command' => $command->command, 'transaction' => $command->id], actor: $user);

        return $command;
    }

    /**
     * Échéance dépassée sans compte rendu : la commande est « expirée », jamais présumée exécutée.
     */
    public function expireOverdue(): int
    {
        /** @var Collection<int, DeviceCommand> $overdue */
        $overdue = DeviceCommand::query()->with('device')->whereIn('status', DeviceCommand::OPEN)->where('expires_at', '<=', now())->get();
        foreach ($overdue as $command) {
            $command->forceFill(['status' => 'expired', 'completed_at' => now(), 'result_message' => $command->status === 'received' ? 'Reçue sans compte rendu d’exécution avant l’échéance.' : 'Non reçue par le lecteur avant l’échéance.'])->save();
            $this->audit->log('device.command_expired', $command->device, after: ['command' => $command->command, 'transaction' => $command->id], outcome: 'failure', objectLabel: $command->device?->code);
        }

        return $overdue->count();
    }
}
