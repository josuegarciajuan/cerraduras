<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Presence\IotSessionService;
use App\Infrastructure\Gateways\Sensor\SensorIngressInterface;
use App\Infrastructure\Gateways\Sensor\TuyaSensorIngress;
use App\Http\Request;
use App\Http\Response;
use App\Support\Errors\BadRequestException;
use App\Support\Errors\NotFoundException;

/**
 * TuyaWebhookController: receives Tuya IoT message push notifications
 * and normalises them through TuyaSensorIngress into the presence/events
 * pipeline (IotSessionService).
 *
 * POST /api/v1/tuya/webhook  (no auth — Tuya Cloud doesn't use our API keys)
 *
 * See TSK-200, TSK-201, F19.
 */
final class TuyaWebhookController
{
    private IotSessionService    $iotService;
    private SensorIngressInterface $ingress;

    public function __construct(IotSessionService $iotService, SensorIngressInterface $ingress)
    {
        $this->iotService = $iotService;
        $this->ingress    = $ingress;
    }

    /**
     * POST /api/v1/tuya/webhook
     *
     * Delegates normalisation to TuyaSensorIngress::normalize() which
     * handles both legacy (protocol 4) and IoT Core (protocol 1000)
     * Tuya push payloads. The ingress is expected to be a TuyaSensorIngress
     * instance wired by index.php (F19).
     */
    public function ingest(Request $request): Response
    {
        $body = is_array($request->jsonBody) ? $request->jsonBody : [];
        $correlationId = (string) $request->attr('correlation_id', 'tuya-wbhk-' . uniqid());

        error_log('[TuyaWebhook] RAW: ' . json_encode($body, JSON_UNESCAPED_UNICODE));

        // Delegate all normalization to TuyaSensorIngress.
        // It handles: devId lookup, room resolution, DP→sensor mapping,
        // timestamp conversion, and source_event_id generation.
        try {
            $event = $this->ingress->normalize($body);
        } catch (BadRequestException $e) {
            error_log('[TuyaWebhook] ' . $e->getMessage());
            return Response::json(400, [
                'error'    => $e->getMessage(),
                'accepted' => false,
                'details'  => $e->getDetails(),
            ]);
        }

        // Skip events flagged as noop (heartbeats, battery/switches, no room).
        // F50: se propaga `discard_reason` (aditivo) para que el motivo sea
        // visible en la respuesta y en los logs sin cambiar el contrato.
        if (isset($event['meta']['_noop']) && $event['meta']['_noop'] === true) {
            $reason = $event['meta']['discard_reason'] ?? null;
            error_log('[TuyaWebhook] Noop event (' . ($reason ?? 'battery/heartbeat') . ') — accepted');
            $payload = ['accepted' => true, 'note' => 'no_actionable_dps'];
            if ($reason !== null) {
                $payload['discard_reason'] = $reason;
            }
            return Response::json(202, $payload);
        }

        // Process through the domain pipeline
        try {
            $result = $this->iotService->processEvent($event, $correlationId);
        } catch (NotFoundException $e) {
            // F50 (N10): una sala inexistente (o cualquier recurso ausente en el
            // pipeline) no es un fallo del servidor: Tuya ya entregó el push y
            // reintentar no ayuda. Se acepta con 202 y se audita el descarte.
            error_log('[TuyaWebhook] room_not_found: ' . $e->getMessage());
            return Response::json(202, [
                'accepted'       => false,
                'discard_reason' => 'room_not_found',
            ]);
        } catch (\Throwable $e) {
            error_log('[TuyaWebhook] Error processing event: ' . $e->getMessage());
            return Response::json(500, [
                'accepted' => false,
                'error'    => $e->getMessage(),
            ]);
        }

        return Response::json(202, $result);
    }
}
