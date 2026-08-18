<?php
declare(strict_types=1);

namespace Ws\Http\Controllers;

use Ws\Http\Request;
use Ws\Http\Response;
use Ws\Support\Errors\NotFoundException;
use Ws\Vb6Repo\HabitacionesRepo;

/**
 * HabitacionesController (TSK-124): GET /ws-vb6/v1/habitaciones/{codhab}
 *
 * Accepts codhab in numeric or text format (P5 — doble aceptación).
 * Returns the habitacion row or 404 if not found.
 *
 * Scope: vb6-bridge:read (all authenticated callers have this).
 */
final class HabitacionesController
{
    private HabitacionesRepo $repo;

    public function __construct(HabitacionesRepo $repo)
    {
        $this->repo = $repo;
    }

    public function show(Request $request): Response
    {
        $codhab = (string) $request->routeParam('codhab');
        if ($codhab === '') {
            throw new NotFoundException('codhab parameter is required');
        }

        $row = $this->repo->findByCodhab($codhab);
        if ($row === null) {
            throw new NotFoundException(
                'Habitacion not found',
                ['codhab' => $codhab]
            );
        }

        return Response::json(200, $row);
    }
}
