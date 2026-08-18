<?php
declare(strict_types=1);

namespace Ws\Http\Controllers;

use Ws\Debts\DebtsSyncService;
use Ws\Http\Request;
use Ws\Http\Response;
use Ws\Support\Errors\BadRequestException;
use Ws\Support\Json\JsonBody;

/**
 * DebtsController (TSK-134): POST /ws-vb6/v1/debts
 *
 * Accepts a debt.created event from the API outbox worker and inserts it
 * into bs2026.deudas via DebtsSyncService.
 *
 * Required body fields:
 *   - codtic (int)
 *   - codcli (int)
 *   - exceso_minutos (int)
 *   - fecha (string, YYYY-MM-DD HH:MM:SS or ISO8601)
 *   - temporada (string, optional — S22 fallback applies)
 *
 * Returns 201 on new insertion, 200 on idempotent duplicate.
 *
 * Scope: all authenticated callers (WS-VB6-CALLER).
 *
 * See contracts.md §3.2, design.md §19.1.
 */
final class DebtsController
{
    private DebtsSyncService $service;

    public function __construct(DebtsSyncService $service)
    {
        $this->service = $service;
    }

    public function create(Request $request): Response
    {
        $body = JsonBody::require($request->jsonBody);

        $result = $this->service->process($body);

        $status = $result['inserted'] ? 201 : 200;

        return Response::json($status, [
            'inserted'   => $result['inserted'],
            'codtic'     => $result['codtic'],
            'temporada'  => $result['temporada'],
            'importe'    => $result['importe'],
            'detalle'    => $result['detalle'],
        ]);
    }
}
