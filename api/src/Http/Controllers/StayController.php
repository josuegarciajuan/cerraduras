<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Debts\OutboxVb6RepositoryInterface;
use App\Domain\Devices\SwitchService;
use App\Domain\Stays\StayService;
use App\Domain\Stays\StayStateMachine;
use App\Http\Request;
use App\Http\Response;
use App\Support\Clock;
use App\Support\Errors\BadRequestException;
use App\Support\Json\JsonBody;

/**
 * StayController: /api/v1/stays endpoints.
 *
 * Scopes (contracts.md §2.7 + §5):
 *   GET list / show:   stays:read
 *   PATCH vb6-refs:    stays:write
 *   POST close:        stays:write (+ Idempotency-Key required)
 *
 * Creation is not exposed here: stays are created as a side effect of
 * POST /qr (QrIssueService, TSK-061). Admins who need to cancel a stay
 * without a proper close flow can do so via POST /stays/{id}/close with
 * reason=canceled — we model the "admin abort" as a close() for now; the
 * cancel() transition is reserved for RESERVED stays and will be wired in
 * TSK-063 (revoke) once QR revocation lands.
 */
final class StayController
{
    private StayService $stays;
    private StayStateMachine $machine;
    private ?OutboxVb6RepositoryInterface $outbox;
    private ?SwitchService $switchService;

    public function __construct(
        StayService $stays,
        StayStateMachine $machine,
        ?OutboxVb6RepositoryInterface $outbox = null,
        ?SwitchService $switchService = null
    ) {
        $this->stays  = $stays;
        $this->machine = $machine;
        $this->outbox  = $outbox;
        $this->switchService = $switchService;
    }

    public function index(Request $request): Response
    {
        $filters = [
            'room_id' => $request->query('room_id'),
            'status' => $request->query('status'),
            'vb6_codtic' => $request->query('vb6_codtic'),
            'vb6_codalq' => $request->query('vb6_codalq'),
            'from' => $request->query('from'),
            'to' => $request->query('to'),
        ];
        $limit = (int) ($request->query('limit') ?? '50');
        $limit = max(1, min(200, $limit));
        $offset = max(0, (int) ($request->query('offset') ?? '0'));

        $list = $this->stays->listFiltered($filters, $limit, $offset);
        return Response::json(200, [
            'items' => array_map(static function ($s) { return $s->toArray(); }, $list),
            'next_cursor' => null,
        ]);
    }

    public function show(Request $request): Response
    {
        $id = (int) $request->routeParam('id');
        return Response::json(200, $this->stays->getOrFail($id)->toArray());
    }

    public function patchVb6Refs(Request $request): Response
    {
        $id = (int) $request->routeParam('id');
        $body = JsonBody::require($request->jsonBody);
        // Accept either a top-level object OR a nested { "vb6_refs": {...} }
        // to be forgiving with VB6 client code.
        $refs = $body;
        if (isset($body['vb6_refs']) && is_array($body['vb6_refs'])) {
            $refs = $body['vb6_refs'];
        }
        $allowed = ['codalq','codtic','codcli','codart','codlot','codhab','temporada','empresa','departamento'];
        $clean = [];
        foreach ($allowed as $k) {
            if (array_key_exists($k, $refs)) {
                $clean[$k] = $refs[$k];
            }
        }
        if (empty($clean)) {
            throw new BadRequestException('At least one VB6 ref field is required');
        }
        $stay = $this->stays->patchVb6Refs($id, $clean);
        return Response::json(200, $stay->toArray());
    }

    public function close(Request $request): Response
    {
        $id = (int) $request->routeParam('id');
        // Body is optional; reason/notes are informational for audit/VB6 log.
        // Accept both absent body and `{}`.
        $stay = $this->stays->getOrFail($id);
        $stay = $this->machine->close($stay);

        // TSK-153: enqueue stay.closed event in outbox for WS-VB6 delivery
        if ($this->outbox !== null) {
            $occurredAt = Clock::nowUtc()->format(DATE_ATOM);
            $this->outbox->enqueue(
                'stay.closed',
                [
                    'topic'       => 'stay.closed',
                    'stay_id'     => $stay->id,
                    'room_id'     => $stay->roomId,
                    'occurred_at' => $occurredAt,
                ],
                'stay-close-' . $stay->id . '-' . $occurredAt
            );
        }

        // RF-16.3: turn off room light (best-effort)
        if ($this->switchService !== null) {
            try {
                $this->switchService->turnOff($stay->roomId);
            } catch (\Throwable $e) {
                error_log('[StayController] Switch turnOff failed (best-effort): ' . $e->getMessage());
            }
        }

        return Response::json(200, $stay->toArray());
    }
}
