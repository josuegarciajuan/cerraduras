<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Qr\QrCredentialRepository;
use App\Domain\Qr\QrIssueService;
use App\Domain\Qr\QrValidateService;
use App\Http\Request;
use App\Http\Response;
use App\Support\Json\JsonBody;

/**
 * QrController:
 *   POST /api/v1/qr             (qr:issue + idempotency required)
 *   POST /api/v1/qr/{jti}/revoke (qr:revoke + idempotency required)
 *   GET  /api/v1/qr             (qr:read — list/search, CRM panel)
 *
 * Body parsing & VB6 refs normalization happens here so the service can
 * focus on domain rules. We accept VB6 refs in either nested form
 * ("vb6_refs": {...}) or flat (top-level codtic etc.); contracts.md §2.4
 * documents the nested form, the flat form is a forgiving alias for VB6
 * client code that prefers a flat payload.
 */
final class QrController
{
    private QrIssueService $issueService;
    private ?QrValidateService $validateService;
    private ?QrCredentialRepository $qrRepo;

    public function __construct(
        QrIssueService $issueService,
        ?QrValidateService $validateService = null,
        ?QrCredentialRepository $qrRepo = null
    ) {
        $this->issueService    = $issueService;
        $this->validateService = $validateService;
        $this->qrRepo          = $qrRepo;
    }

    public function list(Request $request): Response
    {
        $limit  = min((int) ($request->query('limit') ?? '50'), 200);
        $cursor = (int) ($request->query('cursor') ?? '0');

        $filters = [];
        if ($request->query('room_id')) $filters['room_id'] = $request->query('room_id');
        if ($request->query('stay_id')) $filters['stay_id'] = $request->query('stay_id');
        if ($request->query('jti'))     $filters['jti']     = $request->query('jti');
        if ($request->query('status'))  $filters['status']  = $request->query('status');

        $items = $this->qrRepo->findAllFiltered($filters, $limit, $cursor);
        $nextCursor = count($items) >= $limit ? $cursor + $limit : null;

        return Response::json(200, ['items' => $items, 'next_cursor' => $nextCursor]);
    }

    public function validate(Request $request): Response
    {
        if ($this->validateService === null) {
            return Response::json(503, ['error' => ['code' => 'service_unavailable', 'message' => 'QR validate service not configured']]);
        }

        $body      = JsonBody::require($request->jsonBody);
        $qrText    = JsonBody::string($body, 'qr_text');
        $deviceId  = JsonBody::string($body, 'device_id');
        $corrId    = (string) $request->attr('correlation_id', '');

        $result = $this->validateService->validate($qrText, $deviceId, $corrId);
        return Response::json(200, $result);
    }

    public function issue(Request $request): Response
    {
        $body = JsonBody::require($request->jsonBody);
        $roomId = JsonBody::int($body, 'room_id', 1);
        // Service enforces [30,720] but we also pass a permissive guard at
        // the controller layer: any positive integer is accepted; the service
        // will return 422 duration_out_of_range for out-of-range values.
        $duracionMinutos = JsonBody::int($body, 'duracion_minutos', 1);

        // Normalize vb6 refs.
        $rawRefs = [];
        if (isset($body['vb6_refs']) && is_array($body['vb6_refs'])) {
            $rawRefs = $body['vb6_refs'];
        }
        // Allow flat top-level keys to win over nested if both present.
        $flatKeys = ['codalq','codtic','codcli','codart','codlot','codhab','temporada','empresa','departamento'];
        foreach ($flatKeys as $k) {
            if (array_key_exists($k, $body)) {
                $rawRefs[$k] = $body[$k];
            }
        }

        $vb6Refs = $this->sanitizeVb6Refs($rawRefs);

        $result = $this->issueService->issue($roomId, $duracionMinutos, $vb6Refs);
        return Response::json(201, $result);
    }

    public function revoke(Request $request): Response
    {
        $jti = (string) $request->routeParam('jti');
        $reason = null;
        if (is_array($request->jsonBody) && isset($request->jsonBody['reason'])) {
            $reason = (string) $request->jsonBody['reason'];
        }
        $cred = $this->issueService->revoke($jti, $reason);
        return Response::json(200, [
            'jti' => $cred->jti,
            'revoked_at' => $cred->revokedAt,
            'stay_id' => $cred->stayId,
            'room_id' => $cred->roomId,
        ]);
    }

    /**
     * Sanitize VB6 refs to plain scalars accepted by the persistence layer.
     * The service stores them verbatim in stays.vb6_*; we only do shape
     * conversions here (strings/ints), not business validation.
     *
     * @param array<string,mixed> $raw
     * @return array<string,mixed>
     */
    private function sanitizeVb6Refs(array $raw): array
    {
        $out = [];
        $intKeys = ['codalq','codtic','codcli','codart','codlot','empresa','departamento'];
        foreach ($intKeys as $k) {
            if (!array_key_exists($k, $raw) || $raw[$k] === null) continue;
            $v = $raw[$k];
            if (is_int($v)) {
                $out[$k] = $v;
            } elseif (is_string($v) && preg_match('/^-?\d+$/', $v)) {
                $out[$k] = (int) $v;
            }
            // Silent drop for unparseable values: not a hard error here. The
            // sync to VB6 will validate again with the proper error code.
        }
        // codhab: P5 -> accept text or numeric; persist as string.
        if (array_key_exists('codhab', $raw) && $raw['codhab'] !== null) {
            $v = $raw['codhab'];
            if (is_int($v)) {
                $out['codhab'] = (string) $v;
            } elseif (is_string($v) && $v !== '') {
                $out['codhab'] = $v;
            }
        }
        // temporada
        if (array_key_exists('temporada', $raw) && $raw['temporada'] !== null) {
            $v = $raw['temporada'];
            if (is_string($v) && preg_match('/^\d{4}$/', $v)) {
                $out['temporada'] = $v;
            } elseif (is_int($v) && $v >= 1000 && $v <= 9999) {
                $out['temporada'] = (string) $v;
            }
        }
        return $out;
    }
}
