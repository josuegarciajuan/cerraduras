<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Devices\DeviceRepositoryInterface;
use App\Domain\Devices\SwitchService;
use App\Domain\Rooms\RoomControlStatusService;
use App\Domain\Rooms\RoomRemoteUnlockService;
use App\Domain\Rooms\RoomResetService;
use App\Http\Request;
use App\Http\Response;

/**
 * RoomControlController — F124.
 *
 * Canonical room-centric API plus the LAN dashboard facade. Both call the
 * same services; the facade only fixes `override_cooldown=false`.
 */
final class RoomControlController
{
    private RoomControlStatusService $status;
    private SwitchService $switches;
    private RoomRemoteUnlockService $unlocks;
    private RoomResetService $resets;
    private DeviceRepositoryInterface $devices;

    public function __construct(
        RoomControlStatusService $status,
        SwitchService $switches,
        RoomRemoteUnlockService $unlocks,
        RoomResetService $resets,
        DeviceRepositoryInterface $devices
    ) {
        $this->status = $status;
        $this->switches = $switches;
        $this->unlocks = $unlocks;
        $this->resets = $resets;
        $this->devices = $devices;
    }

    public function controls(Request $request): Response
    {
        return Response::json(200, [
            'items' => $this->status->controls(),
            'server_ts' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
    }

    public function status(Request $request): Response
    {
        $roomId = (int) $request->routeParam('id');
        return Response::json(200, $this->status->status($roomId));
    }

    public function light(Request $request): Response
    {
        $roomId = (int) $request->routeParam('id');
        return Response::json(200, $this->status->lightState($roomId));
    }

    public function lightOn(Request $request): Response
    {
        return $this->applyLight($request, 'ON');
    }

    public function lightOff(Request $request): Response
    {
        return $this->applyLight($request, 'OFF');
    }

    public function unlock(Request $request): Response
    {
        $roomId = (int) $request->routeParam('id');
        $body = is_array($request->jsonBody) ? $request->jsonBody : [];
        $reason = isset($body['reason']) ? (string) $body['reason'] : 'manual_override';
        $override = isset($body['override_cooldown']) && (bool) $body['override_cooldown'];
        if ($override) {
            $client = $request->attr('api_client');
            if ($client === null || !$client->hasScope('locks:override')) {
                return Response::json(403, [
                    'error' => [
                        'code' => 'auth_insufficient_scope',
                        'message' => 'locks:override scope required to bypass cooldown',
                    ],
                ]);
            }
        }
        $result = $this->unlocks->open(
            $roomId,
            $reason,
            $override,
            (string) $request->attr('correlation_id', ''),
            $this->actor($request)
        );
        return Response::json(200, $result);
    }

    public function reset(Request $request): Response
    {
        $roomId = (int) $request->routeParam('id');
        $body = is_array($request->jsonBody) ? $request->jsonBody : [];
        if (($body['confirm'] ?? false) !== true) {
            return Response::json(422, [
                'error' => ['code' => 'confirm_required', 'message' => 'confirm=true is required'],
            ]);
        }
        $result = $this->resets->reset($roomId);
        return Response::json(200, [
            'ok' => true,
            'room_id' => $roomId,
            'status' => 'FREE',
            'summary' => $result['summary'],
        ]);
    }

    // -------------------------------------------------------------------------
    // Dashboard facade (LAN, no CRM session)
    // -------------------------------------------------------------------------

    public function dashboardControls(Request $request): Response
    {
        return $this->controls($request);
    }

    public function dashboardStatus(Request $request): Response
    {
        return $this->status($request);
    }

    public function dashboardLight(Request $request): Response
    {
        $body = is_array($request->jsonBody) ? $request->jsonBody : [];
        $state = strtoupper(trim((string) ($body['state'] ?? '')));
        if (!in_array($state, ['ON', 'OFF'], true)) {
            return Response::json(400, [
                'error' => ['code' => 'invalid_state', 'message' => 'state must be ON or OFF'],
            ]);
        }
        return $this->applyLight($request, $state);
    }

    public function dashboardUnlock(Request $request): Response
    {
        $roomId = (int) $request->routeParam('id');
        $body = is_array($request->jsonBody) ? $request->jsonBody : [];
        $reason = isset($body['reason']) ? (string) $body['reason'] : 'dashboard_manual';
        $result = $this->unlocks->open(
            $roomId,
            $reason,
            false,
            (string) $request->attr('correlation_id', ''),
            'dashboard'
        );
        return Response::json(200, $result);
    }

    public function dashboardReset(Request $request): Response
    {
        $body = is_array($request->jsonBody) ? $request->jsonBody : [];
        if (($body['confirm'] ?? false) !== true) {
            return Response::json(422, [
                'error' => ['code' => 'confirm_required', 'message' => 'confirm=true is required'],
            ]);
        }
        $roomId = (int) $request->routeParam('id');
        $result = $this->resets->reset($roomId);
        return Response::json(200, [
            'ok' => true,
            'room_id' => $roomId,
            'status' => 'FREE',
            'summary' => $result['summary'],
        ]);
    }

    // -------------------------------------------------------------------------

    private function applyLight(Request $request, string $state): Response
    {
        $roomId = (int) $request->routeParam('id');
        $device = $this->switches->getSwitchForRoom($roomId);
        if ($device === null) {
            return Response::json(404, [
                'error' => ['code' => 'switch_not_found', 'message' => 'No SWITCH device for this room'],
            ]);
        }

        $result = $state === 'ON'
            ? $this->switches->turnOn($roomId)
            : $this->switches->turnOff($roomId);

        if (($result['ok'] ?? false) !== true) {
            $provider = (string) ($result['provider'] ?? 'UNKNOWN');
            $error = (string) ($result['error'] ?? 'switch_error');
            $status = $provider === 'PAUSED' ? 409 : 502;
            return Response::json($status, [
                'ok' => false,
                'room_id' => $roomId,
                'action' => strtolower($state),
                'requested_state' => $state,
                'provider' => $provider,
                'error' => $error,
            ]);
        }

        $fresh = $this->devices->findById($device->id);
        $meta = $fresh !== null && is_array($fresh->meta) ? $fresh->meta : [];
        $confirmedState = strtoupper((string) ($meta['switch_state'] ?? 'UNKNOWN'));
        $confirmed = $confirmedState === $state;
        $commandId = bin2hex(random_bytes(8));
        $meta['last_command_id'] = $commandId;
        $meta['last_command_source'] = 'room_control';
        $this->devices->update($device->id, [
            'meta_json' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        return Response::json($confirmed ? 200 : 202, [
            'ok' => true,
            'room_id' => $roomId,
            'action' => strtolower($state),
            'requested_state' => $state,
            'provider' => $result['provider'] ?? null,
            'confirmed' => $confirmed,
            'state' => $confirmedState,
            'state_at' => $meta['switch_state_at'] ?? null,
            'command_id' => $commandId,
            'pending_confirmation' => !$confirmed,
        ]);
    }

    private function actor(Request $request): string
    {
        $user = $request->attr('crm_user');
        if ($user !== null && method_exists($user, 'toArray')) {
            $data = $user->toArray();
            return (string) ($data['username'] ?? $data['display_name'] ?? 'panel');
        }
        return 'panel';
    }
}
