<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Presence\IotSessionService;
use App\Infrastructure\Gateways\Sensor\SensorIngressInterface;
use App\Infrastructure\Gateways\Sensor\TuyaSensorIngress;
use App\Http\Request;
use App\Http\Response;
use App\Support\Errors\BadRequestException;

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

        // Skip events flagged as noop (heartbeats, battery-only updates)
        if (isset($event['meta']['_noop']) && $event['meta']['_noop'] === true) {
            error_log('[TuyaWebhook] Noop event (battery/heartbeat) — accepted');
            return Response::json(202, ['accepted' => true, 'note' => 'no_actionable_dps']);
        }

        // Process through the domain pipeline
        try {
            $result = $this->iotService->processEvent($event, $correlationId);
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
