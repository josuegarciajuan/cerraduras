<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Support\Config;
use App\Support\Qr\QrTokenizer;
use App\Domain\Devices\SwitchService;
use App\Domain\Rooms\RoomCycleResetter;
use App\Domain\Rooms\RoomCycleResetterInterface;
use App\Domain\Rooms\RoomResetService;

/**
 * QrTestController: endpoints del dashboard para QR de pruebas (RF-20).
 *
 * POST /dashboard-api/qr-test/create  — Crea un QR real de pruebas
 * POST /dashboard-api/qr-test/reset   — Método A: revoca + limpia + crea nuevo QR
 * POST /dashboard-api/rooms/reset     — Método B: hard reset, sin crear nuevo QR
 *
 * F59 (RF-66): toda creación de QR deja la habitación sin el ciclo anterior
 * (cooldown de anti-reentrada + estado IoT) mediante `RoomCycleResetter`.
 */
final class QrTestController
{
    private \PDO $pdo;
    private ?QrTokenizer $qrTokenizer;
    private ?SwitchService $switchService;
    private RoomCycleResetterInterface $cycleResetter;
    private ?RoomResetService $resetService;

    public function __construct(
        \PDO $pdo,
        ?QrTokenizer $qrTokenizer = null,
        ?SwitchService $switchService = null,
        ?RoomCycleResetterInterface $cycleResetter = null,
        ?RoomResetService $resetService = null
    ) {
        $this->pdo = $pdo;
        $this->qrTokenizer = $qrTokenizer;
        $this->switchService = $switchService;
        $this->cycleResetter = $cycleResetter ?? new RoomCycleResetter($pdo);
        $this->resetService = $resetService;
    }

    private function resetService(): RoomResetService
    {
        if ($this->resetService === null) {
            $this->resetService = new RoomResetService(
                $this->pdo,
                $this->cycleResetter,
                $this->switchService
            );
        }
        return $this->resetService;
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

        // F59 (RF-66): misma creación que el reset (incluye limpieza del ciclo
        // anterior: cooldown + estado IoT) para no duplicar la lógica.
        return $this->doCreate($roomId, $duracionMinutos);
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

        // F126/RF-364: reset compartido (QR, estancias, deudas, IoT y luz).
        $this->resetService()->reset($roomId);

        // Create new QR (reuse create logic).
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

        $result = $this->resetService()->reset($roomId);
        return Response::json(200, [
            'ok'      => true,
            'room_id' => $roomId,
            'status'  => 'FREE',
            'summary' => $result['summary'],
            'message' => 'Habitación reseteada. Panel en blanco.',
        ]);
    }

    /**
     * Internal method: create a QR without the route-level checks.
     *
     * F59 (RF-66): limpia el ciclo anterior (cooldown de anti-reentrada + estado
     * IoT) antes de crear la estancia, para que un QR nuevo no herede
     * `ANTI_REENTRADA` ni el rechazo `room_cooldown`.
     */
    private function doCreate(int $roomId, int $duracionMinutos): Response
    {
        if ($duracionMinutos < 30 || $duracionMinutos > 720) {
            $duracionMinutos = 60;
        }

        // F59 (RF-66): ciclo limpio antes de la nueva reserva.
        $this->cycleResetter->reset($roomId);

        // Create stay
        $this->pdo->prepare(
            "INSERT INTO stays (room_id, status, duracion_minutos, reserved_at, created_at, updated_at)
             VALUES (:rid, 'RESERVED', :dur, UTC_TIMESTAMP(3), UTC_TIMESTAMP(3), UTC_TIMESTAMP(3))"
        )->execute([':rid' => $roomId, ':dur' => $duracionMinutos]);
        $stayId = (int) $this->pdo->lastInsertId();

        // Update room status
        $this->pdo->prepare("UPDATE rooms SET status = 'RESERVED' WHERE id = :rid")
            ->execute([':rid' => $roomId]);

        // Generate QR token. exp must cover the arrival window + the stay
        // duration because the signed token cannot be re-signed (Fase 51).
        $jti = bin2hex(random_bytes(16));
        $iat = time();
        $arrivalMinutes = Config::getInt('QR_ARRIVAL_WINDOW_MINUTES', 15) ?? 15;
        $exp = $iat + (($arrivalMinutes + $duracionMinutos) * 60);

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
