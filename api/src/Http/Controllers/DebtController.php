<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Debts\DebtRepositoryInterface;
use App\Domain\Debts\DebtsService;
use App\Http\Request;
use App\Http\Response;
use App\Support\Errors\NotFoundException;

/**
 * DebtController: debt read and resync endpoints (TSK-113).
 *
 *   GET  /api/v1/debts             scope: debts:read
 *   GET  /api/v1/debts/{id}        scope: debts:read
 *   POST /api/v1/debts/{id}/resync scope: debts:sync
 */
final class DebtController
{
    private DebtRepositoryInterface $repo;
    private DebtsService            $service;

    public function __construct(DebtRepositoryInterface $repo, DebtsService $service)
    {
        $this->repo    = $repo;
        $this->service = $service;
    }

    public function index(Request $request): Response
    {
        $filters = [
            'status'   => $request->query('status'),
            'stay_id'  => $request->query('stay_id'),
            'from'     => $request->query('from'),
            'to'       => $request->query('to'),
        ];
        $limit  = max(1, min(200, (int) ($request->query('limit') ?? '50')));
        $offset = max(0, (int) ($request->query('offset') ?? '0'));

        $items = $this->repo->listFiltered($filters, $limit, $offset);
        return Response::json(200, [
            'items'       => array_map(static fn($d) => $d->toArray(), $items),
            'next_cursor' => null,
        ]);
    }

    public function show(Request $request): Response
    {
        $id   = (int) $request->routeParam('id');
        $debt = $this->repo->findById($id);
        if ($debt === null) {
            throw new NotFoundException('Debt not found', ['debt_id' => $id]);
        }
        return Response::json(200, $debt->toArray());
    }

    public function resync(Request $request): Response
    {
        $id   = (int) $request->routeParam('id');
        $debt = $this->service->rescheduleDebt($id);
        if ($debt === null) {
            throw new NotFoundException('Debt not found', ['debt_id' => $id]);
        }
        return Response::json(200, $debt->toArray());
    }
}
