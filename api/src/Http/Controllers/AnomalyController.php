<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Anomalies\AnomalyService;
use App\Http\Request;
use App\Http\Response;

/**
 * AnomalyController: API endpoints for anomaly listing and acknowledgement.
 *
 * GET  /api/v1/anomalies              — list with filters
 * POST /api/v1/anomalies/{id}/acknowledge — mark as reviewed
 *
 * See contracts.md §3 (F35).
 */
final class AnomalyController
{
    private AnomalyService $anomalyService;

    public function __construct(AnomalyService $anomalyService)
    {
        $this->anomalyService = $anomalyService;
    }

    /**
     * GET /api/v1/anomalies
     *
     * Query params:
     *   room_id      (int, optional)
     *   anomaly_type (string, optional) — A1..A8
     *   severity     (string, optional) — LOW|MEDIUM|HIGH|CRITICAL
     *   status       (string, optional) — default: OPEN
     *   limit        (int, default 50)
     *   offset       (int, default 0)
     */
    public function index(Request $request): Response
    {
        $filters = [];
        $params = $request->query;

        if (isset($params['room_id'])) {
            $filters['room_id'] = (int) $params['room_id'];
        }
        if (isset($params['anomaly_type'])) {
            $filters['anomaly_type'] = (string) $params['anomaly_type'];
        }
        if (isset($params['severity'])) {
            $filters['severity'] = (string) $params['severity'];
        }
        if (isset($params['status']) && $params['status'] !== '' && $params['status'] !== 'ALL') {
            $filters['status'] = (string) $params['status'];
        } elseif (!isset($params['status'])) {
            // Backward compatible: no status param defaults to OPEN
            $filters['status'] = 'OPEN';
        }
        // else: status is empty string or 'ALL' → no status filter (show all)

        $limit  = isset($params['limit'])  ? max(1, min(200, (int) $params['limit']))  : 50;
        $offset = isset($params['offset']) ? max(0, (int) $params['offset'])            : 0;

        $anomalies = $this->anomalyService->findAll($filters, $limit, $offset);
        $total     = $this->anomalyService->count($filters);

        $data = array_map(fn ($a) => $a->toArray(), $anomalies);

        return Response::json(200, [
            'data' => $data,
            'meta' => [
                'total'  => $total,
                'limit'  => $limit,
                'offset' => $offset,
            ],
        ]);
    }

    /**
     * POST /api/v1/anomalies/{id}/acknowledge
     */
    public function acknowledge(Request $request): Response
    {
        $id = (int) $request->routeParam('id');

        $body = is_array($request->jsonBody) ? $request->jsonBody : [];
        $actor = (string) ($body['actor'] ?? 'admin');

        try {
            $anomaly = $this->anomalyService->acknowledge($id, $actor);
            return Response::json(200, [
                'ok'      => true,
                'anomaly' => $anomaly->toArray(),
            ]);
        } catch (\RuntimeException $e) {
            return Response::json(409, [
                'error' => [
                    'code'    => 'anomaly_not_open',
                    'message' => $e->getMessage(),
                ],
            ]);
        }
    }

    /**
     * POST /api/v1/anomalies/{id}/dismiss
     *
     * Manual dismissal for anomaly types that cannot auto-resolve (A1, A5, A8).
     */
    public function dismiss(Request $request): Response
    {
        $id = (int) $request->routeParam('id');

        $body  = is_array($request->jsonBody) ? $request->jsonBody : [];
        $actor = (string) ($body['actor'] ?? 'admin');

        try {
            $anomaly = $this->anomalyService->dismiss($id, $actor);
            return Response::json(200, [
                'ok'      => true,
                'anomaly' => $anomaly->toArray(),
            ]);
        } catch (\RuntimeException $e) {
            return Response::json(409, [
                'error' => [
                    'code'    => 'anomaly_dismiss_failed',
                    'message' => $e->getMessage(),
                ],
            ]);
        }
    }
}
