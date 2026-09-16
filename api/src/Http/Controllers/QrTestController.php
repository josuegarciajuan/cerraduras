<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Support\Config;
use App\Support\Qr\QrTokenizer;
use App\Domain\Devices\SwitchService;

/**
 * QrTestController: endpoints del dashboard para QR de pruebas (RF-20).
 *
 * POST /dashboard-api/qr-test/create  — Crea un QR real de pruebas
 * POST /dashboard-api/qr-test/reset   — Método A: revoca + limpia + crea nuevo QR
 * POST /dashboard-api/rooms/reset     — Método B: hard reset, sin crear nuevo QR
 */
final class QrTestController
{
    private \PDO $pdo;
    private ?QrTokenizer $qrTokenizer;
    private ?SwitchService $switchService;

    public function __construct(\PDO $pdo, ?QrTokenizer $qrTokenizer = null, ?SwitchService $switchService = null)
    {
        $this->pdo = $pdo;
        $this->qrTokenizer = $qrTokenizer;
        $this->switchService = $switchService;
    }

    /**
     * POST /dashboard-api/qr-test/create
     * Body: {room_id: int, duracion_minutos?: int}
     *
     * Crea un QR real firmado para pruebas. La habitación pasa a RESERVED.
     */
    public function create(Request $request): Response
    {
        $body = $request->jsonBody ?? [];
        $roomId = (int) ($body['room_id'] ?? 0);
        $duracionMinutos = (int) ($body['duracion_minutos'] ?? 60);

        if ($roomId <= 0) {
            return Response::json(400, ['error' => 'room_id required']);
        }
        if ($duracionMinutos < 30 || $duracionMinutos > 720) {
            return Response::json(422, ['error' => 'duration_out_of_range', 'message' => 'Duración entre 30 y 720 minutos']);
        }

        if ($this->qrTokenizer === null) {
            return Response::json(500, ['error' => 'QR tokenizer not configured']);
        }

        // Verify room exists
        $room = $this->pdo->prepare("SELECT id, code, status FROM rooms WHERE id = :rid LIMIT 1");
        $room->execute([':rid' => $roomId]);
        $roomRow = $room->fetch(\PDO::FETCH_ASSOC);
        if (!$roomRow) {
            return Response::json(404, ['error' => 'room_not_found']);
        }

        // Check for active stays
        $activeStay = $this->pdo->prepare(
            "SELECT id FROM stays WHERE room_id = :rid AND status IN ('RESERVED','OCCUPIED','OVERSTAY') LIMIT 1"
        );
        $activeStay->execute([':rid' => $roomId]);
        if ($activeStay->fetchColumn()) {
            return Response::json(409, ['error' => 'room_busy', 'message' => 'La habitación ya tiene una estancia activa. Usa reset primero.']);
        }

        // Create stay
        $this->pdo->prepare(
            "INSERT INTO stays (room_id, status, duracion_minutos, reserved_at, created_at, updated_at)
             VALUES (:rid, 'RESERVED', :dur, UTC_TIMESTAMP(3), UTC_TIMESTAMP(3), UTC_TIMESTAMP(3))"
        )->execute([':rid' => $roomId, ':dur' => $duracionMinutos]);
        $stayId = (int) $this->pdo->lastInsertId();

        // Update room status
        $this->pdo->prepare("UPDATE rooms SET status = 'RESERVED' WHERE id = :rid")
            ->execute([':rid' => $roomId]);

        // Generate QR token
        $jti = bin2hex(random_bytes(16));
        $iat = time();
        $exp = $iat + ($duracionMinutos * 60);

        try {
            $qrText = $this->qrTokenizer->issue($roomId, $stayId, $jti, $iat, $exp);
        } catch (\Throwable $e) {
            // Cleanup on failure
            $this->pdo->prepare("DELETE FROM stays WHERE id = :sid")->execute([':sid' => $stayId]);
            $this->pdo->prepare("UPDATE rooms SET status = 'FREE' WHERE id = :rid")->execute([':rid' => $roomId]);
            return Response::json(500, ['error' => 'qr_generation_failed', 'message' => $e->getMessage()]);
        }

        // Store in qr_credentials
        $tokenHash = hash('sha256', $qrText);
        $iatUtc = gmdate('Y-m-d H:i:s.v', $iat);
        $expUtc = gmdate('Y-m-d H:i:s.v', $exp);
        $this->pdo->prepare(
            'INSERT INTO qr_credentials (stay_id, room_id, jti, token_hash, issued_at, expires_at, created_at, updated_at)
             VALUES (:sid, :rid, :jti, :hash, :iat_utc, :exp_utc, UTC_TIMESTAMP(3), UTC_TIMESTAMP(3))'
        )->execute([
            ':sid'     => $stayId,
            ':rid'     => $roomId,
            ':jti'     => $jti,
            ':hash'    => $tokenHash,
            ':iat_utc' => $iatUtc,
            ':exp_utc' => $expUtc,
        ]);

        return Response::json(201, [
            'stay_id'    => $stayId,
            'jti'        => $jti,
            'qr_text'    => $qrText,
            'room_id'    => $roomId,
            'expires_at' => gmdate('Y-m-d\TH:i:s.v\Z', $exp),
            'duracion_minutos' => $duracionMinutos,
        ]);
    }

    /**
     * POST /dashboard-api/qr-test/reset
     * Body: {room_id: int, duracion_minutos?: int}
     *
     * Método A: revoca el QR actual, cierra la estancia activa, limpia efectos secundarios,
     * y crea automáticamente un nuevo QR virgen.
     */
    public function reset(Request $request): Response
    {
        $body = $request->jsonBody ?? [];
        $roomId = (int) ($body['room_id'] ?? 0);
        $duracionMinutos = (int) ($body['duracion_minutos'] ?? 60);

        if ($roomId <= 0) {
            return Response::json(400, ['error' => 'room_id required']);
        }

        if ($this->qrTokenizer === null) {
            return Response::json(500, ['error' => 'QR tokenizer not configured']);
        }

        // Verify room exists
        $room = $this->pdo->prepare("SELECT id FROM rooms WHERE id = :rid LIMIT 1");
        $room->execute([':rid' => $roomId]);
        if (!$room->fetchColumn()) {
            return Response::json(404, ['error' => 'room_not_found']);
        }

        // ── Step 1: Revoke all active QRs for this room ──
        $this->pdo->prepare(
            "UPDATE qr_credentials qc
             JOIN stays s ON s.id = qc.stay_id
             SET qc.revoked_at = UTC_TIMESTAMP(3), qc.updated_at = UTC_TIMESTAMP(3)
             WHERE s.room_id = :rid AND qc.revoked_at IS NULL AND qc.consumed_at IS NULL"
        )->execute([':rid' => $roomId]);

        // ── Step 2: Close all active stays ──
        $this->pdo->prepare(
            "UPDATE stays SET status = 'CLOSED', closed_at = UTC_TIMESTAMP(3)
             WHERE room_id = :rid AND status IN ('RESERVED','OCCUPIED','EXITED','OVERSTAY')"
        )->execute([':rid' => $roomId]);

        // ── Step 3: Clean room state ──
        $this->pdo->prepare("UPDATE rooms SET status = 'FREE', cooldown_until = NULL WHERE id = :rid")
            ->execute([':rid' => $roomId]);

        // ── Step 4: Clean IoT session ──
        $this->pdo->prepare(
            "INSERT INTO iot_sessions (room_id, door_state, presence_state, updated_at)
             VALUES (:rid, 'UNKNOWN', 'UNKNOWN', UTC_TIMESTAMP(3))
             ON DUPLICATE KEY UPDATE door_state = 'UNKNOWN', presence_state = 'UNKNOWN',
                     last_open_at = NULL, last_close_at = NULL, last_absent_since = NULL,
                     exit_evaluated_at = NULL, last_door_event_at = NULL, last_presence_event_at = NULL,
                     last_door_value = NULL, last_presence_value = NULL, updated_at = UTC_TIMESTAMP(3)"
        )->execute([':rid' => $roomId]);

        // ── Step 5: Clean debts ──
        try { $this->pdo->prepare("DELETE FROM debts WHERE room_id = :rid")->execute([':rid' => $roomId]); } catch (\Throwable $e) { error_log('[QrTestController] cleanup debts: ' . $e->getMessage()); }
        try { $this->pdo->prepare("DELETE FROM overstay_debts WHERE room_id = :rid")->execute([':rid' => $roomId]); } catch (\Throwable $e) { error_log('[QrTestController] cleanup overstay_debts: ' . $e->getMessage()); }

        // ── Step 6: Clean outbox ──
        try { $this->pdo->prepare("DELETE FROM outbox WHERE room_id = :rid")->execute([':rid' => $roomId]); } catch (\Throwable $e) { error_log('[QrTestController] cleanup outbox: ' . $e->getMessage()); }

        // ── Step 6b: Turn off the physical switch (RF-16.3: light off on stay close) ──
        if ($this->switchService !== null) {
            try {
                $this->switchService->turnOff($roomId);
            } catch (\Throwable) { /* best-effort */ }
        }

        // ── Step 7: Create new QR (reuse create logic) ──
        return $this->doCreate($roomId, $duracionMinutos);
    }

    /**
     * POST /dashboard-api/rooms/reset
     * Body: {room_id: int}
     *
     * Método B: hard reset — revoca QR, cierra estancias, limpia todo.
     * NO crea un nuevo QR. La habitación vuelve a FREE.
     */
    public function roomsReset(Request $request): Response
    {
        $body = $request->jsonBody ?? [];
        $roomId = (int) ($body['room_id'] ?? 0);

        if ($roomId <= 0) {
            return Response::json(400, ['error' => 'room_id required']);
        }

        // Verify room exists
        $room = $this->pdo->prepare("SELECT id FROM rooms WHERE id = :rid LIMIT 1");
        $room->execute([':rid' => $roomId]);
        if (!$room->fetchColumn()) {
            return Response::json(404, ['error' => 'room_not_found']);
        }

        // ── Revoke all active QRs ──
        $this->pdo->prepare(
            "UPDATE qr_credentials qc
             JOIN stays s ON s.id = qc.stay_id
             SET qc.revoked_at = UTC_TIMESTAMP(3), qc.updated_at = UTC_TIMESTAMP(3)
             WHERE s.room_id = :rid AND qc.revoked_at IS NULL AND qc.consumed_at IS NULL"
        )->execute([':rid' => $roomId]);

        // ── Close all active stays ──
        $this->pdo->prepare(
            "UPDATE stays SET status = 'CLOSED', closed_at = UTC_TIMESTAMP(3)
             WHERE room_id = :rid AND status IN ('RESERVED','OCCUPIED','EXITED','OVERSTAY')"
        )->execute([':rid' => $roomId]);

        // ── Clean room state ──
        $this->pdo->prepare("UPDATE rooms SET status = 'FREE', cooldown_until = NULL WHERE id = :rid")
            ->execute([':rid' => $roomId]);

        // ── Clean IoT session ──
        $this->pdo->prepare(
            "INSERT INTO iot_sessions (room_id, door_state, presence_state, updated_at)
             VALUES (:rid, 'UNKNOWN', 'UNKNOWN', UTC_TIMESTAMP(3))
             ON DUPLICATE KEY UPDATE door_state = 'UNKNOWN', presence_state = 'UNKNOWN',
                     last_open_at = NULL, last_close_at = NULL, last_absent_since = NULL,
                     exit_evaluated_at = NULL, last_door_event_at = NULL, last_presence_event_at = NULL,
                     last_door_value = NULL, last_presence_value = NULL, updated_at = UTC_TIMESTAMP(3)"
        )->execute([':rid' => $roomId]);

        // ── Clean debts ──
        try { $this->pdo->prepare("DELETE FROM debts WHERE room_id = :rid")->execute([':rid' => $roomId]); } catch (\Throwable $e) { error_log('[QrTestController] cleanup debts: ' . $e->getMessage()); }
        try { $this->pdo->prepare("DELETE FROM overstay_debts WHERE room_id = :rid")->execute([':rid' => $roomId]); } catch (\Throwable $e) { error_log('[QrTestController] cleanup overstay_debts: ' . $e->getMessage()); }

        // ── Clean outbox ──
        try { $this->pdo->prepare("DELETE FROM outbox WHERE room_id = :rid")->execute([':rid' => $roomId]); } catch (\Throwable $e) { error_log('[QrTestController] cleanup outbox: ' . $e->getMessage()); }

        // ── Turn off the physical switch (RF-16.3: light off on stay close) ──
        if ($this->switchService !== null) {
            try {
                $this->switchService->turnOff($roomId);
            } catch (\Throwable) { /* best-effort */ }
        }

        return Response::json(200, [
            'ok'      => true,
            'room_id' => $roomId,
            'status'  => 'FREE',
            'message' => 'Habitación reseteada. Panel en blanco.',
        ]);
    }

    /**
     * Internal method: create a QR without the route-level checks.
     */
    private function doCreate(int $roomId, int $duracionMinutos): Response
    {
        if ($duracionMinutos < 30 || $duracionMinutos > 720) {
            $duracionMinutos = 60;
        }

        // Create stay
        $this->pdo->prepare(
            "INSERT INTO stays (room_id, status, duracion_minutos, reserved_at, created_at, updated_at)
             VALUES (:rid, 'RESERVED', :dur, UTC_TIMESTAMP(3), UTC_TIMESTAMP(3), UTC_TIMESTAMP(3))"
        )->execute([':rid' => $roomId, ':dur' => $duracionMinutos]);
        $stayId = (int) $this->pdo->lastInsertId();

        // Update room status
        $this->pdo->prepare("UPDATE rooms SET status = 'RESERVED' WHERE id = :rid")
            ->execute([':rid' => $roomId]);

        // Generate QR token
        $jti = bin2hex(random_bytes(16));
        $iat = time();
        $exp = $iat + ($duracionMinutos * 60);

        try {
            $qrText = $this->qrTokenizer->issue($roomId, $stayId, $jti, $iat, $exp);
        } catch (\Throwable $e) {
            $this->pdo->prepare("DELETE FROM stays WHERE id = :sid")->execute([':sid' => $stayId]);
            $this->pdo->prepare("UPDATE rooms SET status = 'FREE' WHERE id = :rid")->execute([':rid' => $roomId]);
            return Response::json(500, ['error' => 'qr_generation_failed', 'message' => $e->getMessage()]);
        }

        // Store in qr_credentials
        $tokenHash = hash('sha256', $qrText);
        $iatUtc = gmdate('Y-m-d H:i:s.v', $iat);
        $expUtc = gmdate('Y-m-d H:i:s.v', $exp);
        $this->pdo->prepare(
            'INSERT INTO qr_credentials (stay_id, room_id, jti, token_hash, issued_at, expires_at, created_at, updated_at)
             VALUES (:sid, :rid, :jti, :hash, :iat_utc, :exp_utc, UTC_TIMESTAMP(3), UTC_TIMESTAMP(3))'
        )->execute([
            ':sid'     => $stayId,
            ':rid'     => $roomId,
            ':jti'     => $jti,
            ':hash'    => $tokenHash,
            ':iat_utc' => $iatUtc,
            ':exp_utc' => $expUtc,
        ]);

        return Response::json(201, [
            'stay_id'    => $stayId,
            'jti'        => $jti,
            'qr_text'    => $qrText,
            'room_id'    => $roomId,
            'expires_at' => gmdate('Y-m-d\TH:i:s.v\Z', $exp),
            'duracion_minutos' => $duracionMinutos,
        ]);
    }
}
