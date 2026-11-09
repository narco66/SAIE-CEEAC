<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Services\RemoteCommands;
use App\Support\Present;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Commandes à distance d'un lecteur (§11) : disponibilité selon les capacités déclarées, envoi, suivi et annulation.
 * Toute commande est historisée avec son auteur, son identifiant de transaction et son compte rendu (RG-007).
 */
class DeviceCommandController extends Controller
{
    public function __construct(private RemoteCommands $commands) {}

    public function index(Request $request, Device $device): JsonResponse
    {
        $this->authorizePermission('devices.command', 'supervision.view', 'screens.manage');
        $this->assertScreenInScope($device->screen_id, 'devices.command', 'supervision.view', 'screens.manage');
        $query = DeviceCommand::query()->where('device_id', $device->id)->with('requester')->orderByDesc('requested_at')->orderByDesc('id');
        $response = $this->paginated($query, $request, fn (DeviceCommand $c) => Present::deviceCommand($c));
        $payload = $response->getData(true);
        $payload['meta']['availability'] = $this->commands->availability($device);
        $payload['meta']['capabilities'] = $device->capabilities;
        $payload['meta']['capabilities_reported_at'] = Present::date($device->capabilities_reported_at);
        $payload['meta']['playback_paused'] = (bool) $device->playback_paused;
        $payload['meta']['can_command'] = $this->can('devices.command');

        return response()->json($payload);
    }

    public function store(Request $request, Device $device): JsonResponse
    {
        $this->authorizePermission('devices.command');
        $this->assertScreenInScope($device->screen_id, 'devices.command');
        $data = $request->validate([
            'command' => ['required', Rule::in(array_keys(RemoteCommands::CATALOGUE))],
            // Arrêt de diffusion et redémarrage de l'appareil interrompent l'affichage : motif exigé.
            'reason' => ['nullable', 'string', 'min:5', 'max:300', Rule::requiredIf(in_array($request->input('command'), ['stop_playback', 'reboot_device', 'standby'], true))],
        ]);
        $command = $this->commands->request($device, $data['command'], $this->user(), $data['reason'] ?? null);

        return response()->json(['data' => Present::deviceCommand($command->load('requester')), 'message' => 'Commande transmise au prochain battement du lecteur (environ '.(int) config('saie.device.heartbeat_s').' secondes). Elle ne sera « exécutée » qu’après confirmation du lecteur.'], 201);
    }

    public function cancel(DeviceCommand $command): JsonResponse
    {
        $this->authorizePermission('devices.command');
        $this->assertScreenInScope($command->device?->screen_id, 'devices.command');

        return response()->json(['data' => Present::deviceCommand($this->commands->cancel($command, $this->user())->load('requester'))]);
    }
}
