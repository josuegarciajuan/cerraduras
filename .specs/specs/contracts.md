# Contracts — Cerraduras Hotel

---

# Fase 1: Robustez firmware ESP32 QR Reader

## Endpoints usados por el ESP32

Estos contratos ya existen en la API. Se documentan aquí solo para referencia.

### 1. Validación QR
```
POST /api/v1/qr/validate
Header:  X-API-Key: <API_KEY>
Header:  Content-Type: application/json
Body:    {"qr_text":"<token>","device_id":"<chipId>"}
Response 200: {"allow":true,"stay_id":<int>,"room_id":<int>,"action":"open"}
Response 403: {"error":{"code":"qr_invalid_signature|qr_expired|...","message":"..."}}
```

### 2. Health check
```
GET /api/v1/health
Response 200: {"status":"ok"}
```

#### 2.1 Deep health check
```
GET /api/v1/health/deep
Response 200: {
  "status": "healthy|degraded",
  "time": "<iso8601-utc-ms>",
  "checks": {
    "database":      {"status":"ok|error", ...},
    "outbox_failed": {"status":"ok|warning","count":<int>,"note":"<string|null>"},
    "outbox_dead":   {"status":"ok|warning","count":<int>,"note":"<string|null>"},
    "workers":       {"<nombre>":"running|stopped"},
    "ws_vb6":        "reachable|unreachable",
    "battery":       {"status":"ok|warning|error", ...},
    "circuit_breaker": { ... }
  }
}
```

- `outbox_failed`: items `FAILED` con más de 1 h sin resolverse (reintentables). Si `count > 0`,
  `status='warning'` y el chequeo **degrada** el servicio (`overall.status='degraded'`).
- `outbox_dead` (N6 / RF-60): items en la cola muerta (`status='DEAD'`, venenos 4xx que nunca se
  aceptarán). `status='ok'` si `count == 0`, `'warning'` si `count > 0`. Es **informativo**: no
  altera `allOk` y por tanto **no degrada** `overall.status`. El `note` indica
  `"<N> mensajes en cola muerta; reintentar desde el panel"`.
- El endpoint siempre responde HTTP 200; la salud se expresa en el campo `status`.

### 2.2 Reintento manual de un item de outbox
```
POST /admin/outbox/{id}/retry
Response 200: {"retried":true}
Response 404: {"error":{"code":"not_found","message":"Outbox item not found"}}
```

- Acepta cualquier item (`PENDING`, `FAILED` o `DEAD`): reinicia `status='PENDING'`, `attempts=0`,
  `last_error=NULL`, `next_attempt_at=now`. Es la vía de recuperación de la cola muerta.

### 3. Device heartbeat
```
POST /dashboard-api/device-heartbeat
Header:  Content-Type: application/json
Body:    {"external_id":"<chipId>","sub_kinds":["RPI","SCANNER","LOCK"]}
Response 200: {"ok":true,"has_pending_commands":false}
```

### 4. Blink light (solo en WiFi nueva)
```
POST /dashboard-api/blink-light
Header:  Content-Type: application/json
Body:    {"external_id":"<chipId>"}
Response 200: {"ok":true}
```

### 5. Identify button
```
POST /api/v1/devices/identify
Header:  X-API-Key: <API_KEY>
Header:  Content-Type: application/json
Body:    {"external_id":"<chipId>","state":true|false}
Response 200: {"ok":true}
```

### 6. Pending commands (F33)
```
GET /dashboard-api/pending-command?external_id=<chipId>
Response 200: {"id":<int>,"command":"check",...}
```

### 7. Command result (F33)
```
POST /dashboard-api/command-result
Header:  Content-Type: application/json
Body:    {"command_id":<int>,"external_id":"<chipId>","results":{"RPI":true,"SCANNER":true|false,"LOCK":true|false}}
Response 200: {"ok":true}
```

## Contratos internos del firmware

### Variables de configuración (sin cambios)
- `API_BASE_URL` = `"http://92.113.151.136:8080"`
- `API_KEY` = `"<API_KEY>"`
- `RELAY_PIN` = 16, `OPEN_DURATION_MS` = 3000
- `LED_PIN` = 2, `LED_ON_MS` = 4500
- `IDENTIFY_PIN` = 4
- Heartbeat cada 30000ms

### Variables globales nuevas (no expuestas externamente)
| Variable | Tipo | Default |
|----------|------|---------|
| `relayOffAt` | `unsigned long` | `0` |
| `relayPulsing` | `bool` | `false` |
| `ledOffAt` | `unsigned long` | `0` |
| `wifiDownSince` | `unsigned long` | `0` |

### Watchdog
- Timeout: 60 segundos
- Librería: `esp_task_wdt.h` (incluida en ESP32 Arduino core)
- Configuración inicial en `setup()`. La firma de `esp_task_wdt_init()` cambió entre cores:
  - Core ≥3 (IDF 5.x): `esp_task_wdt_config_t{timeout_ms=60000, idle_core_mask=0, trigger_panic=true}` → `esp_task_wdt_init(&cfg)` (timeout 60s, reinicio automático al expirar).
  - Core 2.x legado (IDF 4.x): `esp_task_wdt_init(60, true)`.
  - El sketch usa un `#if ESP_ARDUINO_VERSION_MAJOR >= 3` para cubrir ambos.
- `esp_task_wdt_reset()` — llamado al inicio de cada `loop()`

---

# Fase 35: Detección de anomalías en el flujo de sensores

## 1. Tipos de anomalía (enum)

| Código | Nombre | Severidad | Disparador |
|--------|--------|-----------|------------|
| `A1` | `presence_without_door_open` | `HIGH` | PRESENCE=PRESENT sin PROXIMITY=OPEN previo |
| `A2` | `presence_without_stay` | `HIGH` | PRESENCE=PRESENT en room sin stay activo |
| `A3` | `door_open_without_qr` | `CRITICAL` | PROXIMITY=OPEN sin QR validado ni stay |
| `A4` | `presence_without_door_activity` | `HIGH` | PRESENCE prolongada sin eventos de puerta |
| `A5` | `exited_without_door_open` | `MEDIUM` | stay → EXITED sin PROXIMITY=OPEN de salida |
| `A6` | `presence_after_exit` | `HIGH` | PRESENCE tras stay EXITED |
| `A7` | `door_open_without_presence` | `MEDIUM` | door OPEN + absence sostenida >120s |
| `A8` | `sensor_flapping` | `LOW` | ≥5 transiciones del mismo sensor en 30s |

## 2. Estados de anomalía

| Estado | Significado | Transiciones válidas |
|--------|-------------|---------------------|
| `OPEN` | Detectada, no revisada | → `ACKNOWLEDGED`, → `DISMISSED` (auto) |
| `ACKNOWLEDGED` | Revisada por un operador | → `DISMISSED` (auto) |
| `DISMISSED` | Cerrada (condición resuelta o reconocida y resuelta) | terminal |

## 3. API endpoints

### 3.1 Listar anomalías

```
GET /api/v1/anomalies
Header: X-API-Key: <ADMIN-CLI>
Query params:
  room_id     (int, optional)    — filtrar por habitación
  anomaly_type (string, optional) — filtrar por tipo (A1..A8)
  severity    (string, optional) — LOW, MEDIUM, HIGH, CRITICAL
  status      (string, optional) — OPEN, ACKNOWLEDGED, DISMISSED (default: OPEN)
  limit       (int, default 50)
  offset      (int, default 0)

Response 200:
{
    "data": [
        {
            "id": 42,
            "room_id": 3,
            "room_code": "101",
            "stay_id": 15,
            "anomaly_type": "A1",
            "severity": "HIGH",
            "status": "OPEN",
            "context_data": {
                "door_state": "CLOSED",
                "presence_state": "PRESENT",
                "stay_status": "OCCUPIED",
                "first_entry_at": "2026-07-25T10:00:00.000Z",
                "last_open_at": null
            },
            "detected_at": "2026-07-25T10:30:00.000Z",
            "acknowledged_at": null,
            "acknowledged_by": null,
            "dismissed_at": null,
            "dismissed_by": null
        }
    ],
    "meta": {
        "total": 1,
        "limit": 50,
        "offset": 0
    }
}
```

### 3.2 Reconocer anomalía

```
POST /api/v1/anomalies/{id}/acknowledge
Header: X-API-Key: <ADMIN-CLI>
Body: (opcional) {"actor": "operador@hotel.com"}

Response 200:
{
    "ok": true,
    "anomaly": { ... }  // anomalía con status=ACKNOWLEDGED
}

Response 404:
{"error":{"code":"anomaly_not_found","message":"Anomaly not found"}}

Response 409:
{"error":{"code":"anomaly_not_open","message":"Anomaly is not in OPEN status"}}
```

### 3.3 Anomalías en RoomLive

El endpoint existente `GET /api/v1/rooms/{id}/live` incluye anomalías activas. Ver §6 del design.

### 3.4 Dismiss automático

No tiene endpoint público. El worker `anomaly-scanner.php` evalúa periódicamente y transiciona `OPEN → DISMISSED` cuando la condición deja de cumplirse.

## 4. Contratos internos

### 4.1 `AnomalyDetector` (interfaz PHP)

```php
interface AnomalyDetector {
    /**
     * @return AnomalyResult|null — null si no se detecta anomalía
     */
    public function detect(
        IotSession $session,
        PresenceEvent $trigger,
        ?Stay $stay
    ): ?AnomalyResult;
}
```

### 4.2 `AnomalyResult` (DTO)

```php
class AnomalyResult {
    public function __construct(
        public readonly string $type,        // 'A1'..'A8'
        public readonly string $severity,    // 'LOW','MEDIUM','HIGH','CRITICAL'
        public readonly array  $contextData, // snapshot del estado IoT
    ) {}
}
```

### 4.3 `AnomalyService` (interfaz)

```php
interface AnomalyServiceInterface {
    /** Evalúa y persiste anomalías disparadas por un evento de sensor */
    public function detectAndPersist(
        IotSession $session,
        PresenceEvent $trigger,
        ?Stay $stay
    ): array; // retorna Anomaly[] creadas

    /** Marca anomalía como reconocida por un operador */
    public function acknowledge(int $anomalyId, string $actor): void;

    /** Cierra automáticamente anomalías cuya condición ya no se cumple */
    public function autoResolve(int $roomId): void;

    /** Lista anomalías con filtros */
    public function findAll(array $filters): array;
}
```

### 4.4 Umbrales configurables

| Parámetro | Default | Descripción |
|-----------|---------|-------------|
| `A3_QR_WINDOW_SECONDS` | 30 | Ventana hacia atrás para buscar QR validado |
| `A4_ABSENCE_OF_DOOR_HOURS` | 2 | Horas sin evento PROXIMITY para A4 |
| `A5_EXIT_DOOR_GAP_HOURS` | 2 | Antigüedad máxima de last_open_at para considerar salida normal |
| `A7_MAX_OPEN_ABSENT_SECONDS` | 120 | Tiempo máximo de puerta abierta sin presencia |
| `A8_FLAPPING_THRESHOLD` | 5 | Número de transiciones para considerar flapping |
| `A8_FLAPPING_WINDOW_SECONDS` | 30 | Ventana de tiempo para contar transiciones |

---

# Fase 36: Monitoreo de batería del sensor de puerta MC400D

## 1. Nuevo endpoint: Refresco de batería bajo demanda

### 1.1 POST /dashboard-api/battery-refresh

```
POST /dashboard-api/battery-refresh
Content-Type: application/json
Body:    {"device_id": <int>}

Response 200:
{
  "ok": true,
  "device_id": 5,
  "kind": "PROXIMITY",
  "battery_pct": 87,
  "state": "normal"
}

Response 400: {"error": "device_id required"}
Response 404: {"error": "Device not found"}
Response 502: {"ok":false, "error": "Tuya API error: ..."}
```

**Estados de batería (`state`)**:

| Valor | Condición |
|-------|-----------|
| `"normal"` | `battery_pct > 20` |
| `"low"` | `10 < battery_pct <= 20` |
| `"critical"` | `battery_pct <= 10` |
| `"unknown"` | `battery_pct IS NULL` |

**Comportamiento**:
1. Busca el dispositivo por `device_id`
2. Llama a Tuya API: `GET /v1.0/iot-03/devices/{external_id}/status`
3. Extrae DP `battery_percentage` de la respuesta
4. Persiste en `devices.battery_pct`
5. Retorna el valor y estado clasificado

## 2. Endpoints modificados

### 2.1 GET /dashboard-api/device-status?room_id=N

**Nuevo campo en cada dispositivo**:
```json
{
  "devices": [
    {
      "id": 3,
      "kind": "PROXIMITY",
      "external_id": "bf4c7e7d2cef28cea2nkwk",
      "label": "Sensor Puerta",
      "last_seen_at": "2026-07-28 12:00:00.000",
      "online": true,
      "battery_pct": 87,
      "meta": {...}
    }
  ]
}
```

### 2.2 GET /dashboard-api/pack-detail?pack_id=N

**Mismo cambio**: campo `battery_pct` añadido a cada dispositivo.

### 2.3 GET /api/v1/rooms/{id}/live

**Nuevo campo en la respuesta**:
```json
{
  "room_id": 1,
  ...
  "battery": {
    "device_id": 3,
    "kind": "PROXIMITY",
    "label": "Sensor Puerta",
    "pct": 87,
    "state": "normal"
  }
}
```

El campo `battery` solo se incluye si la habitación tiene un dispositivo `PROXIMITY` en su pack asignado. Si no hay dispositivo o no tiene pack, el campo es `null`.

---

# Fase 37: Vista de anomalías con filtro de estado

## 1. API — endpoint modificado

### 1.1 GET /api/v1/anomalies — nuevo valor de status

El query param `status` ahora acepta un valor adicional:

| Valor | Comportamiento |
|-------|---------------|
| `OPEN` | Solo anomalías pendientes (default) |
| `ACKNOWLEDGED` | Solo anomalías reconocidas |
| `DISMISSED` | Solo anomalías descartadas |
| `ALL` | Todas las anomalías, sin filtro de estado |
| (no enviado) | Igual que `OPEN` (retrocompatibilidad) |

El resto de parámetros (`room_id`, `anomaly_type`, `severity`, `limit`, `offset`) no cambian.

## 2. Repositorio — nuevo método

### 2.1 `AnomalyRepositoryInterface::findResolvableForRoom()`

```php
/**
 * Find all resolvable (OPEN + ACKNOWLEDGED) anomalies for a room.
 * Used by anomaly-scanner worker to auto-dismiss anomalies whose
 * triggering condition has cleared.
 *
 * @return list<Anomaly>
 */
public function findResolvableForRoom(int $roomId): array;
```

## 3. Frontend — contrato visual

### 3.1 Badges de estado

| Estado | Clase CSS | Color |
|--------|-----------|-------|
| OPEN / Pendiente | `status-open` | Rojo (#ff5252) |
| ACKNOWLEDGED / Reconocida | `status-acked` | Naranja (#ff9100) |
| DISMISSED / Descartada | `status-dismissed` | Verde (#69f0ae) |

### 3.2 Columna de acción

- **OPEN**: botón "✓ Reconocer" que llama a `POST /api/v1/anomalies/{id}/acknowledge`
- **ACKNOWLEDGED**: texto "por {acknowledged_by} · {acknowledged_at}"
- **DISMISSED**: texto "por {dismissed_by} · {dismissed_at}"


---
# Fase 38: Workers — Trabajadores del hotel con QR maestro

## 1. Workers CRUD

### 1.1 Listar trabajadores
```
GET /api/v1/workers?role_id=1&active=true
Header: X-API-Key: <ADMIN-CLI>
Response 200:
{
  "data": [
    {
      "id": 1,
      "name": "María García",
      "role": {"id": 1, "name": "Limpieza"},
      "active": true,
      "current_room": null,
      "last_access_at": "2026-08-05 10:30:00.000",
      "sessions_today": 5,
      "created_at": "2026-01-15 08:00:00.000"
    }
  ],
  "meta": {"total": 1}
}
```

### 1.2 Crear trabajador
```
POST /api/v1/workers
Header: X-API-Key: <ADMIN-CLI>
Body:
{
  "name": "María García",
  "role_id": 1,
  "notes": "Turno mañana"
}
Response 201:
{
  "data": {
    "id": 1,
    "name": "María García",
    "role": {"id": 1, "name": "Limpieza"},
    "active": true,
    "qr_token": "eyJhbGciOi...worker_jwt...",   <-- solo aquí
    "notes": "Turno mañana",
    "created_at": "2026-08-05 10:00:00.000"
  }
}
Response 422 (validación): {"error": {"code": "validation_error", "message": "..."}}
```

### 1.3 Ver trabajador
```
GET /api/v1/workers/1
Header: X-API-Key: <ADMIN-CLI>
Response 200:
{
  "data": {
    "id": 1,
    "name": "María García",
    "role": {"id": 1, "name": "Limpieza"},
    "active": true,
    "current_room": {"id": 3, "code": "H-103"},
    "notes": "Turno mañana",
    "created_at": "...",
    "updated_at": "..."
  }
}
```

### 1.4 Editar trabajador
```
PATCH /api/v1/workers/1
Header: X-API-Key: <ADMIN-CLI>
Body: {"name": "María G.", "role_id": 2, "notes": "..."}
Response 200: {"data": {...}}
```

### 1.5 Desactivar trabajador
```
DELETE /api/v1/workers/1
Header: X-API-Key: <ADMIN-CLI>
Response 200: {"data": {"id": 1, "active": false, "qr_revoked": true}}
Response 404: {"error": {"code": "not_found", "message": "Worker not found"}}
```

### 1.6 Regenerar QR
```
POST /api/v1/workers/1/qr
Header: X-API-Key: <ADMIN-CLI>
Response 200:
{
  "data": {
    "id": 1,
    "qr_token": "eyJhbGciOi...new_worker_jwt...",
    "message": "QR regenerado. El token anterior ha sido revocado."
  }
}
```

### 1.7 Sesiones del trabajador
```
GET /api/v1/workers/1/sessions?from=2026-08-01&to=2026-08-05&room_id=3&limit=50
Header: X-API-Key: <ADMIN-CLI>
Response 200:
{
  "data": [
    {
      "id": 42,
      "room": {"id": 3, "code": "H-103"},
      "entered_at": "2026-08-05 09:15:00.000",
      "exited_at": "2026-08-05 10:05:00.000",
      "duration_minutes": 50,
      "exit_kind": "DOOR_EVENT"
    }
  ],
  "meta": {"total": 1}
}
```

### 1.8 Workers dentro ahora
```
GET /api/v1/workers/inside
Header: X-API-Key: <ADMIN-CLI>
Response 200:
{
  "data": [
    {
      "worker": {"id": 1, "name": "María García", "role": "Limpieza"},
      "room": {"id": 3, "code": "H-103"},
      "entered_at": "2026-08-05 11:30:00.000",
      "duration_minutes": 15
    }
  ]
}
```

## 2. Validación QR worker

### 2.1 Validar QR y abrir puerta
```
POST /api/v1/workers/qr/validate
Header: X-API-Key: <SCANNER-KEY>
Body:
{
  "token": "eyJhbGciOi...worker_jwt...",
  "room_id": 3,
  "device_id": "ESP32-SCAN-A001"
}
Response 200:
{
  "ok": true,
  "worker": {"id": 1, "name": "María García"},
  "worker_session_id": 42,
  "action": "open"
}
Response 403 (qr_revoked):
{
  "error": {"code": "qr_revoked", "message": "Worker QR no longer valid"}
}
Response 403 (access_denied):
{
  "error": {"code": "access_denied", "message": "Worker role cannot access this room type"}
}
Response 409 (already_inside):
{
  "error": {"code": "already_inside", "message": "Worker already has an active session in this room"}
}
```

## 3. Worker Roles CRUD

### 3.1 Listar roles
```
GET /api/v1/worker-roles
Header: X-API-Key: <ADMIN-CLI>
Response 200:
{
  "data": [
    {
      "id": 1,
      "name": "Limpieza",
      "description": "Personal de limpieza de habitaciones",
      "room_types": [{"id": 1, "code": "ESTANDAR"}, {"id": 2, "code": "SUITE"}],
      "workers_count": 3,
      "created_at": "..."
    }
  ]
}
```

### 3.2 Crear rol
```
POST /api/v1/worker-roles
Header: X-API-Key: <ADMIN-CLI>
Body:
{
  "name": "Limpieza",
  "description": "Personal de limpieza",
  "room_type_ids": [1, 2]
}
Response 201: {"data": {"id": 1, ...}}
Response 422: {"error": {"code": "validation_error", "message": "room_type_ids must be an array"}}
```

### 3.3 Ver rol
```
GET /api/v1/worker-roles/1
Header: X-API-Key: <ADMIN-CLI>
Response 200: {"data": {...}}
```

### 3.4 Editar rol
```
PATCH /api/v1/worker-roles/1
Header: X-API-Key: <ADMIN-CLI>
Body: {"name": "Limpieza General", "room_type_ids": [1, 2, 3]}
Response 200: {"data": {...}}
```

### 3.5 Eliminar rol
```
DELETE /api/v1/worker-roles/1
Header: X-API-Key: <ADMIN-CLI>
Response 200: {"data": {"id": 1, "deleted": true}}
Response 409: {"error": {"code": "role_in_use", "message": "Cannot delete: N workers assigned"}}
```

## 4. Ocupación en tiempo real

### 4.1 Ocupantes de habitación
```
GET /api/v1/rooms/3/occupants
Header: X-API-Key: <ADMIN-CLI>
Response 200:
{
  "data": {
    "room": {"id": 3, "code": "H-103"},
    "occupants": [
      {"type": "guest", "id": 101, "name": "Juan Pérez", "entered_at": "...", "duration_minutes": 45},
      {"type": "worker", "id": 42, "name": "María García", "role": "Limpieza", "entered_at": "...", "duration_minutes": 15}
    ]
  }
}
```

## 5. Access events — nuevo kind

### 5.1 WORKER_EXIT
```
{
  "kind": "WORKER_EXIT",
  "room_id": 3,
  "worker_session_id": 42,
  "result": "OK",
  "provider": "TUYA",
  "meta_json": {"exit_kind": "EXIT_RULE", "worker_id": 1}
}
```

## 6. Frontend — contratos visuales

### 6.1 Worker badge en dashboard

Estado | Clase CSS | Color
--- | --- | ---
Activo | `worker-active` | Verde (#69f0ae)
Inactivo | `worker-inactive` | Gris (#9e9e9e)
Dentro de habitación | `worker-inside` | Azul (#448aff)
Alerta activa | `worker-alert` | Rojo (#ff5252)

### 6.2 Componentes panel

- **WorkerRow**: nombre, rol badge, status badge, última acción, ubicación actual, acciones (editar, desactivar, regenerar QR)
- **WorkerDetail**: tabs: Historial | Métricas | Alertas
- **RoleForm**: nombre, descripción, checklist de room_types con toggle
- **WorkerQRModal**: modal con QR token (solo en creación/regeneración), botón copiar
- **WorkerAlertsBadge**: badge numérico en navbar con dropdown de alertas activas

---

# Fase 39: Identificación de fábrica integrada en ESP32 productivo

## 1. Registro de fábrica

### 1.1 Anunciar ESP32

```text
POST /api/v1/factory-devices/announce
Header: X-API-Key: <FACTORY_DEVICE_KEY>
Header: Content-Type: application/json
Body: { "chip_id": "a1b2c3d4e5f6" }
```

El `chip_id` es exactamente la representación lowercase, sin separadores, de los
6 bytes de `ESP.getEfuseMac()` (12 caracteres hexadecimales). La operación es
idempotente por `chip_id`.

Primera aceptación:

```json
{
  "data": {
    "id": 12,
    "chip_id": "a1b2c3d4e5f6",
    "status": "PENDING",
    "first_announced_at": "2026-08-26 10:00:00.000",
    "last_announced_at": "2026-08-26 10:00:00.000",
    "claimed_at": null,
    "claimed_by": null,
    "device_id": null
  },
  "created": true
}
```

La primera aceptación devuelve `201`. Un reintento o anuncio repetido devuelve
`200`, `created: false` y el registro existente. Si ya está `CLAIMED`, conserva
`status`, `claimed_at` y `claimed_by`; nunca se rebaja ni se duplica.

Errores: `400` para `chip_id` ausente o inválido y `401/403` para autenticación
o autorización inválida.

### 1.2 Listar pendientes para el panel

```text
GET /api/v1/factory-devices?status=PENDING
Header: X-API-Key: <ADMIN-CLI>

Response 200:
{
  "data": [
    {
      "id": 12,
      "chip_id": "a1b2c3d4e5f6",
      "status": "PENDING",
      "first_announced_at": "2026-08-26 10:00:00.000",
      "last_announced_at": "2026-08-26 10:00:00.000",
      "device_id": null
    }
  ]
}
```

El filtro acepta `PENDING`, `CLAIMED` o `ALL`; el panel usa `PENDING` para la
cola de equipos por reclamar.

### 1.3 Reclamar equipo

```text
POST /api/v1/factory-devices/12/claim
Header: X-API-Key: <ADMIN-CLI>
Header: Content-Type: application/json
Body: {}

Response 200:
{
  "data": {
    "id": 12,
    "chip_id": "a1b2c3d4e5f6",
    "status": "CLAIMED",
    "claimed_at": "2026-08-26 10:05:00.000",
    "claimed_by": "<operator-id>",
    "device_id": 34
  }
}
```

El actor se obtiene de la identidad autenticada del panel. Repetir el claim
sobre un registro `CLAIMED` devuelve `200` con el estado persistente y no cambia
el actor ni la fecha. El primer claim crea o vincula, en la misma transacción,
un `devices.kind=RPI` con `external_id=chip_id`, `pack_id=null` y `room_id=null`,
y guarda su id en `factory_devices.device_id`. Un identificador inexistente
devuelve `404`.

## 2. Contrato firmware integrado

- El único sketch F39 es `docs/esp32-qr-reader/scanner-relay-prod.ino`; no hay
  contrato para `factory-identification.ino` ni otro firmware aislado.
- `chip_id` son los 6 bytes de `ESP.getEfuseMac()`, serializados como 12
  caracteres hexadecimales lowercase sin separadores.
- WiFiManager/NVS conservan su contrato productivo actual. Al detectar WiFi,
  el scheduler integrado anuncia automáticamente sin requerir GPIO4, QR,
  habitación o acción humana.
- Mientras `status=PENDING`, programa reintentos periódicos acotados. Ante
  timeout/error, programa el siguiente intento y continúa el loop; no bloquea
  QR, USB, relé, GPIO4, watchdog, heartbeat ni command queue.
- Ante `status=CLAIMED`, deshabilita anuncios solo en RAM hasta el final de ese
  arranque. No persiste `factory_claimed` ni otro estado autoritativo en NVS.
  En el siguiente boot, reflasheo o NVS wipe vuelve a anunciar para consultar
  el backend, que decide el estado.
- F39 no altera el comportamiento de QR, relé, GPIO4, heartbeat, locks,
  sensores, sesiones, habitaciones o estancias.

## 3. Estados persistentes

| Estado | Entrada | Anuncio posterior | Acción válida |
|--------|---------|-------------------|---------------|
| `PENDING` | primer anuncio | permanece `PENDING`; firmware reintenta durante el boot | claim manual |
| `CLAIMED` | claim manual | permanece `CLAIMED`; firmware detiene anuncio solo durante ese boot | claim idempotente, sin cambio |

---

# Fase 40: Credenciales individuales ESP32

## Correcciones de provisioning y claim

El claim es indivisible: crea o vincula exactamente un `RPI` por `external_id=chip_id` y exactamente un `api_client` por `device_id`; ningún cliente puede quedar huérfano. Después de eliminar `factory_devices`, repetir el claim devuelve `200` con `CLAIMED` reconstruido desde RPI/auditoría, sin cambiar actor/fecha ni crear recursos.

Cada build nuevo compara `build_marker = ESP.getSketchMD5() + "-" + __DATE__ + "-" + __TIME__`. Si cambia, elimina solo `ssid`, `pass`, `last_ssid` y `build_marker` de `Preferences("cerraduras")`, guarda el nuevo marcador y reinicia. Nunca usa `Preferences::clear()` ni modifica `Preferences("device-cred")`.

## Anuncio

```text
POST /api/v1/factory-devices/announce
HTTPS obligatorio
Content-Type: application/json
Body: { "chip_id": "a1b2c3d4e5f6", "factory_key": "<clave generada por placa>" }
```

Responde solo metadatos (`chip_id`, `status`, fechas, `device_id`); nunca la
clave ni su hash. `201` es alta, `200` reintento, `400` formato inválido y
`401/403` credencial o transporte inválido.

## Claim

```text
POST /api/v1/factory-devices/{id}/claim
CRM/sesión administrativa o cliente con scope `factory:claim`
Body: { "label": "opcional", "pack_id": 12 }
```

El servidor obtiene el hash de enrollment del registro anunciado. La respuesta
solo contiene metadatos y nunca contiene `factory_key` ni su hash. El claim es
idempotente: registro inexistente `404`, registro sin hash `409
enrollment_required`, binding incompatible `409 device_client_conflict` y
datos inválidos `422`.

## 3. Claim consumido y anuncio posterior

Tras un claim exitoso, la fila `factory_devices` se elimina en la misma
transacción, después de crear/vincular el RPI, vincular su cliente API y escribir
la auditoría. `audit_log`, el RPI y el cliente API permanecen. Un anuncio
posterior responde `200`, `created: false` y `data.status: "CLAIMED"` con
`device_id`; una credencial distinta responde `403` sin recrear `PENDING`.

## Binding

Un `api_client` bound solo puede operar sobre su único RPI. La incompatibilidad
devuelve `403` con código `device_mismatch`. Clientes legacy sin binding siguen
autorizándose por scope hasta su migración.

El registro de auditoría del claim incluye `chip_id`, `status_before`,
`status_after`, `actor` y `created_at`.

## CR — Calibración de sensor de presencia (RF-41)

### `GET /dashboard-api/presence-calibrate/status?room_id=N` (LAN dashboard, sin auth)
- `400` si falta `room_id`. `404` si la habitación no tiene sensor de presencia
  (cuerpo `{error}`). `502` si la API Tuya falla o hay backoff de cuota
  (cuerpo incluye `error` y `device`).
- Respuesta `200`:
  ```json
  {
    "room_id": 1,
    "device": { "id": 5, "external_id": "bf98d27d…", "label": "...", "online": true },
    "far_detection": 300,          // cm
    "sensitivity": 9,              // 0-9
    "presence_state": "presence",  // 'presence' | 'none' | null
    "target_dis_closest": 120,
    "mode": "ON",                  // 'ON' | 'OFF' (OFF ⇔ far_detection <= 1)
    "effective_presence": "PRESENT", // 'PRESENT' | 'ABSENT' (radio<=1 ⇒ 'ABSENT')
    "saved": { "far_detection": 300, "sensitivity": 9, "calibrated_at": "…Z", "source": "dashboard-calib" }
  }
  ```

### `POST /dashboard-api/presence-calibrate/set`
- Body: `{ "room_id": 1, "far_detection": 250, "sensitivity": 9 }` (uno o ambos campos).
- `400` si falta `room_id`, ambos campos vacíos o fuera de rango
  (`far_detection` 0–1000, `sensitivity` 0–9). `404` sin sensor. `502` Tuya falla.
- `200`: `{ "ok": true, "saved": {…snapshot persistido…}, "elapsed_ms": … }`.
- Efecto: escribe los DP `far_detection`/`sensitivity` en el dispositivo Tuya y
  persiste el snapshot en `devices.meta_json.calibration` de la habitación.

---

# Fase 41 — Contratos: Robustez del pipeline de sensores y coreografía

## 0. Alcance y trazabilidad

Este apartado formaliza **solo los cambios de contrato observables externamente**
(esquema de BD, endpoints HTTP/SSE y semántica de deduplicación) introducidos por la Fase 41.
La refactorización de atomicidad (lock de fila, `SELECT … FOR UPDATE`) es **interna** y no
cambia la forma externa de ningún endpoint.

| RF | Contrato afectado | Sección |
|----|-------------------|---------|
| RF-43 | (interno) punto único de escritura; sin cambio de forma HTTP | §7 |
| RF-44 | Migración `0108`; semántica `applied`/`discard_reason` | §1, §2 |
| RF-46 / RF-47 | `GET /api/v1/rooms/{id}/live` (campos nuevos y semántica de `exit_deadline`) | §3 |
| RF-49 | `GET /dashboard-api/event-stream` (evento `ping`) | §4 |
| RF-48 | `GET /dashboard-api/system-status` (esquema nuevo) | §5 |
| RF-47 | `POST /dashboard-api/rooms/reset` (limpieza de marcas) | §6 |

---

## 1. Migración `0108_sensor_event_ordering.sql`

Archivo nuevo: `api/migrations/0108_sensor_event_ordering.sql` (siguiente libre tras `0107`).
Aditiva y nullable; segura de aplicar en caliente sobre producción.

### 1.1 DDL exacto

```sql
-- 0108_sensor_event_ordering.sql — Orden temporal, idempotencia y consolidación de entrada
-- Trazabilidad: RF-43, RF-44, RF-46, RF-47.

ALTER TABLE iot_sessions
  ADD COLUMN last_door_event_at     DATETIME(3) NULL DEFAULT NULL AFTER last_absent_since,
  ADD COLUMN last_presence_event_at DATETIME(3) NULL DEFAULT NULL AFTER last_door_event_at,
  ADD COLUMN last_door_value        VARCHAR(8)  NULL DEFAULT NULL AFTER last_presence_event_at,
  ADD COLUMN last_presence_value    VARCHAR(8)  NULL DEFAULT NULL AFTER last_door_value;

ALTER TABLE presence_events
  ADD COLUMN event_fingerprint CHAR(40)    NULL DEFAULT NULL AFTER source_event_id,
  ADD COLUMN applied           TINYINT(1)  NULL DEFAULT NULL AFTER event_fingerprint,
  ADD COLUMN discard_reason    VARCHAR(16) NULL DEFAULT NULL AFTER applied,
  ADD UNIQUE KEY uq_presence_fingerprint (event_fingerprint),
  ADD KEY idx_presence_room_occurred (room_id, occurred_at);

ALTER TABLE stays
  ADD COLUMN entry_confirmed_at DATETIME(3) NULL DEFAULT NULL AFTER first_entry_at;
```

### 1.2 Semántica de las columnas nuevas

**`iot_sessions`** (marcas de orden temporal por sensor):

| Columna | Tipo | Null | Semántica |
|---------|------|------|-----------|
| `last_door_event_at` | `DATETIME(3)` | sí | `occurred_at` del último evento de puerta **aplicado**. |
| `last_presence_event_at` | `DATETIME(3)` | sí | `occurred_at` del último evento de presencia **aplicado**. |
| `last_door_value` | `VARCHAR(8)` | sí | Último valor de puerta aplicado: `OPEN` \| `CLOSED`. |
| `last_presence_value` | `VARCHAR(8)` | sí | Último valor de presencia aplicado: `PRESENT` \| `ABSENT`. |

**`presence_events`** (auditoría de aplicación y deduplicación):

| Columna | Tipo | Null | Semántica |
|---------|------|------|-----------|
| `event_fingerprint` | `CHAR(40)` | sí | Identidad lógica del hecho (SHA-1 hexadecimal de 40 chars). `UNIQUE`. |
| `applied` | `TINYINT(1)` | sí | `1` = aplicado al estado; `0` = descartado; `NULL` = fila legacy. |
| `discard_reason` | `VARCHAR(16)` | sí | `duplicate` \| `stale` \| `noop` \| `no_context` (solo si `applied = 0`). |

**`stays`**:

| Columna | Tipo | Null | Semántica |
|---------|------|------|-----------|
| `entry_confirmed_at` | `DATETIME(3)` | sí | Instante en que la entrada del huésped quedó confirmada (puerta cerrada con presencia vista durante la apertura). `NULL` = aún no confirmado. Es la autoridad de "huésped dentro" para la coreografía y la precondición de salida. |

> `first_entry_at` se conserva tal cual (informativo). `entry_confirmed_at` lo complementa y
> **no lo reemplaza** en el modelo de datos.

### 1.3 Compatibilidad y rollback

- **Aditiva**: ninguna columna existente se modifica ni se elimina; los endpoints actuales
  que no leen los campos nuevos siguen funcionando.
- **Nullable**: las filas existentes quedan con `NULL`. Los consumidores deben tratar `NULL`
  como "desconocido/legacy" (p. ej. no usar `applied` para decidir).
- **`UNIQUE` sobre columna nullable**: MySQL/MariaDB permiten múltiples `NULL`, por lo que las
  filas legacy de `presence_events` no colisionan entre sí.
- **Rollback**: `DROP COLUMN` de las 8 columnas nuevas + `DROP INDEX uq_presence_fingerprint`
  y `DROP INDEX idx_presence_room_occurred`. Es reversible a nivel de esquema, pero
  **destruye** la información de orden/consolidación acumulada; hacerlo solo con backup previo
  y tras confirmar que no hay procesos dependientes.

---

## 2. Semántica de deduplicación y orden de eventos

### 2.1 Identidad lógica (fingerprint)

```
event_fingerprint = SHA1( room_id | sensor | value | floor(occurred_at, segundo) )
```

- Salida: 40 caracteres hexadecimales (`CHAR(40)`).
- Incluye `value`, de modo que `OPEN` y `CLOSED` en el mismo segundo son hechos distintos.
- Trunca `occurred_at` a segundo (precisión real de la fuente Tuya/poller).
- `source_event_id` (id de transporte) **se conserva** y su `UNIQUE` sigue vigente; el
  fingerprint es una segunda capa que cubre reenvíos con distinto `t`.

### 2.2 Valores de `applied` y `discard_reason`

| `applied` | `discard_reason` | Significado |
|-----------|------------------|-------------|
| `1` | `NULL` | El evento se aplicó al estado IoT. |
| `0` | `duplicate` | Ya existía un evento con el mismo `event_fingerprint` (o `source_event_id`). No altera el estado. |
| `0` | `stale` | `occurred_at` es anterior al último evento aplicado del mismo sensor. No revierte transiciones más nuevas. |
| `0` | `noop` | El valor ya estaba vigente; no representa una transición. |
| `0` | `no_context` | F48/RF-57: PRESENT de presencia no creíble por contexto (p. ej. `move` fuera de la ventana de entrada, o presencia en habitación FREE sin estancia). No altera el estado; evita presencia fantasma del pasillo. |
| `NULL` | `NULL` | Fila previa a la migración `0108` (legacy); no participa en la decisión. |

- Todo evento recibido se persiste íntegro (auditoría bruta), **aunque no se aplique**
  (RF-44.3). Un descarte nunca borra evidencia.
- El evento queda auditable con `applied`/`discard_reason` rellenados en la misma transacción
  que aplica el estado.

### 2.3 Regla de decisión (contrato de dominio)

| Caso | `discard_reason` |
|------|------------------|
| Fingerprint ya existente | `duplicate` |
| `occurred_at < last_<sensor>_event_at` | `stale` |
| `occurred_at == last_<sensor>_event_at` y `value == last_<sensor>_value` | `duplicate` |
| `value == last_<sensor>_value` (sin cambio) | `noop` |
| F48: `sensor=PRESENCE`, `value=PRESENT` y `tuya_raw_val=move` fuera de la ventana de entrada | `no_context` |
| F48: `sensor=PRESENCE`, `value=PRESENT` sin estancia activa ni ventana de entrada (habitación FREE) | `no_context` |
| En cualquier otro caso | se **aplica** (`applied = 1`) |

> F48 (RF-57): `no_context` **solo** aplica a transiciones a `PRESENT`. `ABSENT` (`none`)
> siempre se aplica para poder limpiar el estado.

---

## 3. `GET /api/v1/rooms/{id}/live`

Endpoint y forma general **sin cambios**. Se añaden campos aditivos y se precisa la semántica
de `exit_deadline`. El mismo payload es el que viaja en el evento SSE `state` (§4).

### 3.1 Campos nuevos en `iot_session`

| Campo | Tipo | Null | Descripción |
|-------|------|------|-------------|
| `last_door_event_at` | string ISO-8601 UTC | sí | `occurred_at` del último evento de puerta aplicado. |
| `last_presence_event_at` | string ISO-8601 UTC | sí | `occurred_at` del último evento de presencia aplicado. |
| `last_door_value` | string | sí | `OPEN` \| `CLOSED` aplicado. |
| `last_presence_value` | string | sí | `PRESENT` \| `ABSENT` aplicado. |

### 3.2 Campo nuevo en `active_stay`

| Campo | Tipo | Null | Descripción |
|-------|------|------|-------------|
| `entry_confirmed_at` | string ISO-8601 UTC | sí | Confirmación de entrada (huésped dentro). Autoridad de `hasBeenInside` para la coreografía y precondición de la regla de salida. |

Cuando no hay estancia activa, `active_stay` sigue siendo `null` y el campo no aparece.

### 3.3 Campos con cambio de semántica (retrocompatible)

| Campo | Contrato anterior | Contrato Fase 41 / 42 |
|-------|-------------------|------------------|
| `exit_deadline` | Se emitía con `presence_state=ABSENT` + `door_state=CLOSED` + ancla en `last_close_at`/`last_open_at` dentro de la ventana. | Se mantiene el campo y su tipo (ISO-8601 UTC o `null`). Se emite **solo** cuando `presence_state=ABSENT`, existe un **ciclo de puerta acreditado** (`last_open_at` y `last_close_at` presentes, `last_close_at >= last_open_at`), la puerta está `CLOSED` **y `active_stay.entry_confirmed_at` no es nulo** (F42 / RF-46.4). Se calcula como `last_absent_since + exit_guard_seconds` (Bug 1). Se limpia (`null`) si la puerta se reabre, reaparece presencia o la entrada aún no se ha consolidado. El poller lo usa como señal de ventana de verificación. |
| `gap_seconds` | Override de habitación > tipo de habitación > 15 s. | **Legacy (Bug 1)**: se mantiene el campo y su resolución (override de sala ?? `room_type.exit_presence_gap_seconds` ?? 15) por compatibilidad, pero **ya no** gobierna la regla de salida ni la ventana de entrada. |
| `exit_guard_seconds` | — | **Nuevo, aditivo (Bug 1)**: guarda de ausencia sostenida de la SALIDA, en segundos. Resolución `room.presence_check_seconds` (>0) > `EXIT_ABSENCE_GUARD_SECONDS` (>0) > 3. `exit_deadline = last_absent_since + exit_guard_seconds`. |
| `first_entry_at` | Se usaba como indicador de "dentro". | Se mantiene informativo. La autoridad de "dentro" pasa a `active_stay.entry_confirmed_at`. |
| `iot_session.last_open_at` / `last_close_at` | Anclas de la regla de salida. | Sin cambios de formato; siguen siendo las anclas del ciclo de puerta. No se expone ningún campo compuesto `door_cycle`: los consumidores lo derivan de estos dos. |

**Nota (retrocompatibilidad)**: los campos nuevos son aditivos; ningún campo existente cambia
de nombre ni de tipo. Un consumidor que ignore `entry_confirmed_at` y
`last_*_event_at`/`last_*_value` sigue funcionando con la semántica anterior.

**Nota F42 (RF-46.4) / Bug 1**: la ventana de verificación de entrada es **client-side** y se
deriva de `iot_session.last_close_at` + `entry_window_seconds` (`room_types.presence_entry_window_seconds`,
default 90 s) mientras `active_stay.entry_confirmed_at` es nulo. El backend consolida
`entry_confirmed_at` al recibir `PRESENCE=PRESENT` con `door_state=CLOSED` dentro de
`entry_window_seconds` del cierre; con la puerta abierta no consolida. La guarda de SALIDA es
independiente (`exit_guard_seconds`, default 3 s) y `gap_seconds` queda como campo legacy.

### 3.4 Ejemplo de respuesta (fragmento)

```json
{
  "room_id": 12,
  "code": "PROTO2",
  "status": "OCCUPIED",
  "presence_check_seconds": null,
  "cooldown_until": null,
  "iot_session": {
    "door_state": "CLOSED",
    "presence_state": "PRESENT",
    "last_open_at": "2026-09-16T10:20:01.000Z",
    "last_close_at": "2026-09-16T10:20:05.000Z",
    "last_absent_since": null,
    "last_door_event_at": "2026-09-16T10:20:05.000Z",
    "last_presence_event_at": "2026-09-16T10:20:06.000Z",
    "last_door_value": "CLOSED",
    "last_presence_value": "PRESENT"
  },
  "active_stay": {
    "id": 305,
    "status": "OCCUPIED",
    "duracion_minutos": 60,
    "first_entry_at": "2026-09-16T10:19:55.000Z",
    "entry_confirmed_at": "2026-09-16T10:20:05.000Z",
    "exited_at": null
  },
  "exit_deadline": null,
  "gap_seconds": 15,
  "exit_guard_seconds": 3,
  "qr_status": { "...": "sin cambios" },
  "recent_events": [],
  "recent_presence": [],
  "anomalies": []
}
```

> **F48/RF-57.2.2**: `recent_presence` (tanto en `/live` como en el evento SSE `state`)
> expone **solo hechos aplicados** (`presence_events.applied = 1`). Los descartados
> (`duplicate`/`stale`/`noop`/`no_context`) no deben alimentar la coreografía del panel.

`exit_deadline` con conteo activo (fragmento):

```json
{
  "iot_session": {
    "door_state": "CLOSED",
    "presence_state": "ABSENT",
    "last_open_at": "2026-09-16T11:00:00.000Z",
    "last_close_at": "2026-09-16T11:00:03.000Z",
    "last_absent_since": "2026-09-16T11:00:04.000Z"
  },
  "active_stay": { "status": "OCCUPIED", "entry_confirmed_at": "2026-09-16T10:20:05.000Z" },
  "exit_deadline": "2026-09-16T11:00:07.000Z",
  "gap_seconds": 15,
  "exit_guard_seconds": 3
}
```

### 3.5 Campos nuevos en `switch_state` (aditivo)

Campos **opcionales** que solo aparecen cuando la habitación tiene un dispositivo
`SWITCH` registrado (si no hay `switch_state`, no aplican). Son aditivos y nullable: un
consumidor que los ignore sigue funcionando. Reflejan el estado real reportado por push y
el último fallo clasificado de Tuya.

| Campo | Tipo | Null | Descripción |
|-------|------|------|-------------|
| `state` | string | no | **F50**: estado REAL del relé según el push de Tuya: `ON` \| `OFF` \| `UNKNOWN`. `UNKNOWN` mientras no haya llegado ningún push con DP `switch`/`switch_1`. No procede de sondeo REST (sin cuota). |
| `state_at` | string ISO-8601 UTC (`Y-m-d\TH:i:s\Z`) | sí | **F50**: `occurred_at` derivado del sello `t` del DP de switch. `null` si no hay estado reportado. |
| `last_error` | string | sí | Mensaje del último fallo de Tuya (incluye `code` y `msg`). `null` si el último comando fue aceptado. |
| `last_error_at` | string ISO-8601 UTC (`Y-m-d\TH:i:s.v\Z`) | sí | Marca UTC del último fallo. `null` si no hay error. |
| `last_error_category` | string | sí | Taxonomía del fallo: `quota` \| `offline` \| `dp_invalid` \| `unknown`. `null` si no hay error. |

> En caso de comando exitoso se limpian `last_error` y `last_error_category`; `last_command`
> y `commanded_at` conservan su contrato. El evento SSE `state` transporta el mismo payload.
> `state`/`state_at` (F50) los escribe el push del `TuyaSensorIngress` en `devices.meta_json`
> (`switch_state`/`switch_state_at`), en paralelo a `last_command`/`commanded_at` (comando).
> Un comando puede ir por delante del push; el panel debe poder mostrar ambos.

### 3.6 `POST /api/v1/tuya/webhook` — descartes no-op (F50, aditivo)

El webhook sigue devolviendo **202** para eventos `_noop` (heartbeats, batería, switch) y
ahora también para `NotFoundException` (p. ej. sala inexistente), en vez de 500: Tuya ya
entregó el push y un 500 solo provoca reenvíos/ruido. Forma de la respuesta (aditiva):

| Caso | HTTP | Cuerpo |
|------|------|--------|
| Evento `_noop` sin motivo (batería/heartbeat) | 202 | `{"accepted":true,"note":"no_actionable_dps"}` |
| Push de `SWITCH` con DP de switch | 202 | `{"accepted":true,"note":"no_actionable_dps","discard_reason":"switch_state"}` |
| Device sin sala resuelta (`device → pack → room` sin room) | 202 | `{"accepted":true,"note":"no_actionable_dps","discard_reason":"room_not_found"}` |
| `NotFoundException` en `processEvent` (sala ausente) | 202 | `{"accepted":false,"discard_reason":"room_not_found"}` |
| Error inesperado | 500 | `{"accepted":false,"error":"…"}` |

> `discard_reason` en esta respuesta es **aditivo** y no forma parte del enum de
> `presence_events.discard_reason` (§2.2): los eventos `room_not_found`/`switch_state` no
> llegan a `presence_events` (se cortan antes del pipeline de dominio). El 500 se reserva
> para errores inesperados.

---

## 4. `GET /dashboard-api/event-stream?room_id=N`

Sin cambios en cabeceras, autenticación ni ciclo de vida. Se añade un evento de latido.

### 4.1 Tabla de eventos SSE

| `event` | `data` | Cuándo se emite |
|---------|--------|-----------------|
| `connected` | `{ "room_id": N, "ts": "ISO-8601Z" }` | Al establecer el stream. |
| `state` | Mismo payload que `GET /api/v1/rooms/{id}/live` (§3). | Cuando cambia el fingerprint del estado (Tier 1) o en el primer ciclo. |
| `ping` | `{ "room_id": N, "ts": "ISO-8601Z" }` | Cada ~5 s mientras el stream está vivo. |
| `close` | `{ "reason": "max_lifetime" \| "too_many_connections", ... }` | Al cerrar el stream por límite de vida o de conexiones. |

El comentario `keepalive` (`: keepalive <ISO>`, cada 15 s) se mantiene **además** para evitar
timeouts de proxies; no es visible para `EventSource` y no sustituye a `ping`.

### 4.2 Contrato del evento `ping`

```text
event: ping
data: {"room_id":12,"ts":"2026-09-16T11:00:00Z"}
```

- Frecuencia objetivo: **~5 s** (mientras el stream está abierto).
- El panel lo usa como señal de vida: si no recibe `state` ni `ping` durante > 5 s (pestaña
  visible), dispara una resincronización puntual de `/live`; si el silencio supera ~15 s,
  fuerza la reconexión del stream (RF-49.1).
- `ping` no modifica ningún estado de dominio ni de UI.

### 4.3 Compatibilidad

- El objeto `state` **no se reestructura**: conserva sus claves actuales. Los campos aditivos
  de §3 viajan dentro de `iot_session` y `active_stay`.
- Un cliente que ignore el evento `ping` sigue funcionando (los eventos desconocidos se
  descartan en `EventSource` sin listener).

---

## 5. `GET /dashboard-api/system-status`

Esquema ampliado. Retrocompatible en campos: `label`, `online` y `pid` se conservan.

### 5.1 Esquema

Respuesta `200`, objeto `{clave: worker}` con las claves exactas:

| Clave | Worker supervisado | `expected` |
|-------|--------------------|-----------|
| `exit-scan` | `bin/exit-scan.php` | 1 |
| `overstay-scan` | `bin/overstay-scan.php` | 1 |
| `outbox-worker` | `bin/outbox-worker.php` | 1 |
| `anomaly-scanner` | `bin/anomaly-scanner.php` | 1 |
| `presence-poller-manager` | `bin/presence-poller-manager.sh` | 1 |
| `pulsar-consumer` | `tuya-pulsar-consumer` | 1 |

Cada entrada:

| Campo | Tipo | Descripción |
|-------|------|-------------|
| `label` | string | Nombre legible (sin cambios). |
| `online` | bool | `instances >= 1`. Deriva de `instances`. |
| `pid` | int \| null | PID representativo (el primero), o `null` si no hay. Retrocompatible. |
| `expected` | int | Instancias esperadas (siempre `1`). |
| `instances` | int | Nº real de procesos vivos con ese patrón. |
| `pids` | int[] | Lista de PIDs; `[]` si no hay procesos. |
| `healthy` | bool | `instances === expected`. |
| `degraded` | bool | `instances > expected` (duplicado/huérfano). |

### 5.2 Ejemplo de respuesta

```json
{
  "exit-scan": {
    "label": "Regla de Salida (Exit)",
    "online": true,
    "pid": 12345,
    "expected": 1,
    "instances": 1,
    "pids": [12345],
    "healthy": true,
    "degraded": false
  },
  "overstay-scan": {
    "label": "Overstay Scanner",
    "online": true,
    "pid": 12346,
    "expected": 1,
    "instances": 1,
    "pids": [12346],
    "healthy": true,
    "degraded": false
  },
  "outbox-worker": {
    "label": "Outbox Worker",
    "online": true,
    "pid": 12347,
    "expected": 1,
    "instances": 1,
    "pids": [12347],
    "healthy": true,
    "degraded": false
  },
  "anomaly-scanner": {
    "label": "Anomaly Scanner",
    "online": true,
    "pid": 12348,
    "expected": 1,
    "instances": 1,
    "pids": [12348],
    "healthy": true,
    "degraded": false
  },
  "presence-poller-manager": {
    "label": "Gestor Poller Presencia",
    "online": true,
    "pid": 12349,
    "expected": 1,
    "instances": 1,
    "pids": [12349],
    "healthy": true,
    "degraded": false
  },
  "pulsar-consumer": {
    "label": "Eventos Tuya (Pulsar)",
    "online": false,
    "pid": null,
    "expected": 1,
    "instances": 0,
    "pids": [],
    "healthy": false,
    "degraded": false
  }
}
```

### 5.3 Reglas de estado

| Situación | `online` | `healthy` | `degraded` |
|-----------|----------|-----------|------------|
| 1 proceso vivo | `true` | `true` | `false` |
| 0 procesos | `false` | `false` | `false` |
| >1 proceso (duplicado/huérfano) | `true` | `false` | `true` |

- No hay endpoint de escritura: `system-status` es de solo lectura.
- Los procesos que no coinciden con ningún patrón no aparecen.

---

## 6. `POST /dashboard-api/rooms/reset`

### 6.1 Request / Response

```text
POST /dashboard-api/rooms/reset
Content-Type: application/json
Body: { "room_id": 12 }

Response 200 (forma sin cambios):
{
  "ok": true,
  "room_id": 12,
  "status": "FREE",
  "message": "Habitación reseteada. Panel en blanco."
}

Response 400: {"error":"room_id required"}
Response 404: {"error":"room_not_found"}
```

### 6.2 Estado que limpia (ampliado)

Además de lo que ya limpia hoy (QRs activos, estancias activas, `rooms.status/cooldown`,
`last_open_at`, `last_absent_since`, `exit_evaluated_at`, deudas, outbox y luz), debe limpiar
**todas** las marcas temporales y el estado de deduplicación de la habitación:

| Tabla | Campos que se reinician |
|-------|-------------------------|
| `iot_sessions` | `door_state='CLOSED'`, `presence_state='ABSENT'`, `last_open_at=NULL`, **`last_close_at=NULL`**, `last_absent_since=NULL`, `exit_evaluated_at=NULL`, `last_door_event_at=NULL`, `last_presence_event_at=NULL`, `last_door_value=NULL`, `last_presence_value=NULL`. |
| `stays` | Las estancias activas pasan a `CLOSED`; `entry_confirmed_at` de esas estancias deja de usarse (la siguiente estancia nace con `NULL`). |
| `presence_events` | No se borra la auditoría; el reset no reescribe `applied`/`discard_reason` (la auditoría es inmutable). La deduplicación por fingerprint se reinicia de facto al no haber estado previo que descartar. |

**Nota (RC-6)**: hoy `last_close_at` no se limpiaba, de modo que tras un reset el ciclo de
puerta anterior seguía "acreditado". Con este contrato, un reset deja la habitación lista para
una secuencia de apertura/cierre nueva (RF-47.5).

- La forma de la respuesta no cambia; solo cambia el conjunto de campos internos limpiados.
- La operación sigue siendo idempotente: repetir el reset no falla.

---

## 7. Contratos internos (sin cambio observable)

- **`IotSessionService::processEvent(array $event, string $correlationId): array`**: se
  conserva como punto de entrada público, con la **misma firma y el mismo retorno**
  (`{accepted, derived_state}`). La atomicidad (lock de fila), el orden temporal y la
  idempotencia son cambios **internos**. El webhook y los tests existentes no requieren
  cambios de llamada.
- **`IotSessionRepositoryInterface::upsert()`**: deja de ser el camino de escritura de estado
  usado por el servicio, pero su contrato no se elimina; el servicio pasa a usar operaciones
  internas de lock/update. No es observable externamente.
- **Poller de presencia**: `ENTRY_WINDOW_MS` (90 s) y el watchdog de captura máxima (120 s)
  son estado **interno** del proceso Node; no se exponen en `/live` ni en la API.
- **`pendingExitVerification(live, now)`** (RF-51.1.6): función **interna** del poller, sin
  representación HTTP. Determina si, tras un ciclo de salida acreditado, el muestreo debe
  continuar pese a `presence_state=PRESENT`. No altera la forma de `/live` ni del webhook;
  solo decide si el proceso gasta cuota Tuya.
- **`DOOR_CYCLE_MAX_S`**: constante de dominio con valor por defecto **300 s**; no es
  configuración externa ni campo de API.
- **Anomalías A1–A8**: siguen siendo informativas; su contrato (F35) no cambia.

---

## 8. Nota de no regresión

Los siguientes contratos **no cambian** con la Fase 41:

- **QR**: `POST /api/v1/qr/validate`, formato de token, códigos de error y `qr_status` en
  `/live`.
- **Workers (F38)**: CRUD de trabajadores y roles, `POST /api/v1/workers/qr/validate`,
  `GET /api/v1/rooms/{id}/occupants`, `GET /api/v1/workers/inside`, `access_events` kind
  `WORKER_EXIT`. El cierre de `worker_session` por evento de puerta se mantiene.
- **Anomalías (F35)**: enum A1–A8, severidades, estados `OPEN`/`ACKNOWLEDGED`/`DISMISSED`,
  endpoints `/api/v1/anomalies*` y su presencia en `/live` (`anomalies`). No bloquean el flujo.
- **Dispositivos (RF-42)**: `GET /dashboard-api/device-status`, `pack-detail`,
  `ping-all-devices` y la semántica `online`/`unknown` de dispositivos Tuya cloud.
- **Calibración (RF-41)**: `presence-calibrate/status` y `/set`.
- **Fábrica (F39/F40)**: `factory-devices/announce`, `claim` y binding.
- **Firmware ESP32**: ningún endpoint del sketch (`heartbeat`, `identify`, `commands`,
  `command-result`, blink) cambia.
- El evento SSE `connected`/`close` y el comentario `keepalive` conservan su contrato.

---

## 9. Discrepancias documentadas con `design.md`

Se adoptan las resoluciones del usuario. Las siguientes diferencias con lo redactado en
`design.md` §7/§8 quedan documentadas (no bloquean):

1. **Claves de `system-status`**: `design.md` §7.2 proponía `tuya-presence-poller` y
   `tuya-pulsar-consumer`. La resolución fija `presence-poller-manager` y `pulsar-consumer`
   (además de añadir `outbox-worker` y `anomaly-scanner`). Este contrato usa las claves de la
   resolución. Si se necesita compatibilidad estricta de claves con consumidores antiguos,
   puede añadirse un alias temporal, pero no forma parte de este contrato.
2. **`healthy`**: `design.md` §7.2 definía `healthy = online ∧ instances == 1`. La resolución
   define `healthy = instances === expected` (con `expected = 1`). Se adopta la resolución;
   en la práctica coinciden salvo por la interpretación de `online`.
3. **`exit_deadline`**: `design.md` §8.1 decía que se calcula "sin exigir estado actual
   `CLOSED`" para la **regla interna** de salida. La resolución exige `door_state=CLOSED` para
   **emitir el campo** `exit_deadline` en `/live`. Se documenta la distinción: la regla de
   confirmación no depende del estado actual de puerta (usa ciclo acreditado), mientras que el
   campo de UI sí requiere `CLOSED`; si la puerta queda inconsistente, el backend puede
   confirmar igualmente la salida vía `exit-scan`.
4. **Forma del evento SSE `state`**: la resolución indica que "el objeto `state` no cambia de
   forma". Se interpreta como que **no se reestructura** (conserva sus claves); los campos
   aditivos de §3 viajan anidadados en `iot_session`/`active_stay`, sin romper consumidores.
5. **`ENTRY_WINDOW_MS` y `DOOR_CYCLE_MAX_S`**: coherentes con `design.md` §4 y §3;
   respectivamente interno del poller (90 s) y constante de dominio (300 s).

---

## Anexo F44 — Tiempo real de sensores y presencia bajo demanda

### 1. `/api/v1/rooms/{id}/live` — campos aditivos (RF-51.3)

Se añaden dos campos, sin romper consumidores existentes:

```json
{
  "entry_window_seconds": 90,
  "exit_check_seconds": 25,
  "exit_guard_seconds": 3
}
```

- `entry_window_seconds`: `room_types.presence_entry_window_seconds` (default 90). Ventana de
  muestreo tras la apertura de puerta hasta detectar presencia.
- `exit_check_seconds`: `gap_seconds + 10`. Ventana de muestreo tras el cierre para decidir
  la salida real.
- `exit_guard_seconds` (Bug 1, aditivo): guarda de ausencia sostenida de la SALIDA
  (`rooms.presence_check_seconds` > `EXIT_ABSENCE_GUARD_SECONDS` > 3). `exit_deadline =
  last_absent_since + exit_guard_seconds`. `gap_seconds` queda como campo legacy.

### 2. Consumer Pulsar (RF-50)

- **No contrato HTTP**: consume el WS de Tuya y hace `POST /api/v1/tuya/webhook`.
- **Dueño único**: `cerraduras-pulsar-consumer.service`. `start-all.sh` solo reinicia el
  servicio; `stop-all.sh` solo lo detiene vía `systemctl stop`.
- **Lista de devices rastreados (push)**: derivada de la BD
  (`TRACKED_KINDS = PROXIMITY, PRESENCE, SWITCH`), reconciliada cada 60 s. No hay
  `SENSOR_DEVICE_IDS` hardcodeado. El SWITCH se rastrea para conocer su estado real por push
  (sin cuota IoT Core).
- **Resync**: en cada `open`, `GET /v1.0/iot-03/devices/{id}/status` **solo** por device de
  `RESYNC_KINDS = PROXIMITY, PRESENCE` (el SWITCH nunca se sondea → sin cuota),
  rate-limited a 20 s, reenviado al webhook como `{devId, status:[{code,value,t}]}`.
- **Watchdog de silencio**: backstop de 15 min (`CONSUMER_SILENCE_MS`, default `900000`); el
  ping proactivo de 30 s detecta sockets muertos y el silencio en reposo (packs solo-puerta)
  es normal, por lo que un umbral corto causaba churn de reconexión.
- **Reintento ante fallo sin `close`** (p. ej. DNS `getaddrinfo EAI_AGAIN`): `scheduleReconnect()`
  se comparte entre `close` y `error` con guarda anti-dobles-timers, manteniendo backoff
  exponencial base 1 s.

### 3. Presencia (RF-52)

- Se elimina del pipeline de producción la semántica `far_detection ≤ 1 → ABSENT`.
- `far_detection` es config (cm) del dispositivo; solo aparece en `/dashboard-api/presence-calibrate/*`
  y en la calibración (`/simula` conserva su semántica propia de simulación).
- Presencia efectiva = `presence_state ∈ {presence, move}` → `PRESENT`; `none` → `ABSENT`.
- **`target_dis_closest` (RF-52.4.3)**: el 24G V3 lo declara pero reporta siempre `0`; no forma
  parte de ninguna decisión de presencia. El modo **prueba de paseo** (RF-52.4) reutiliza los
  endpoints `presence-calibrate/status|set` sin cambios de contrato (mismos campos, `persist:false`
  para aplicar en vivo).

### 4. `system-status` (RF-50.2.4)

`pulsar-consumer` mantiene el esquema `{expected, instances, pids, healthy, degraded}` con
`expected = 1`; `instances` debe ser 1 y `healthy = (instances === expected)`.

---

## Anexo F47 — Latencia, robustez de recepción y arranque consistente

### 1. Sonda CLI `bin/presence-latency-probe.js` (RF-53)

CLI de diagnóstico, sin contrato HTTP. Salida por stdout, una línea por observación:

```
[2026-09-17T11:17:12.917Z] PRESENCE PRESENT
  physical ≈ 2026-09-17T11:16:53.000Z (DELANTE_SENSOR)
  device_t = 2026-09-17T11:17:12.000Z   received_at = 2026-09-17T11:17:12.917Z
  deltas: physical→device=19.0s  device→db=0.9s  db→now=0.0s
```

Ejemplo de marcas por stdin / fichero `api/run/latency-marker`:

```
DELANTE_SENSOR 2026-09-17T11:16:53.000Z
```

Formato de marca: `<ETIQUETA> [ISO-8601]`; sin ISO se usa el instante de lectura.

**Garantía**: la sonda NO realiza peticiones a Tuya. Solo `EventSource`/HTTP local al SSE y
`mysql` en lectura.

### 2. Estado del consumer — `api/run/pulsar-consumer-status.json` (RF-54.4.2)

Fichero de runtime (no versionado). Esquema:

```json
{
  "connected": true,
  "last_msg_at": "2026-09-17T11:21:45.123Z",
  "last_pong_at": "2026-09-17T11:21:45.100Z",
  "known_devices": 2,
  "resyncs": 1,
  "updated_at": "2026-09-17T11:21:45.123Z"
}
```

- `connected`: estado del WS (`ws.readyState === OPEN`).
- `last_msg_at`: instante UTC del último mensaje recibido (cualquier DP).
- `last_pong_at` (**F50, aditivo**): instante UTC del último `pong` del servidor; `null` si
  nunca hubo. Es solo observabilidad: **no** fuerza reconexión (no está probado que Tuya
  responda siempre con pong).
- `known_devices`: tamaño de la lista reconciliada (incluye SWITCH desde F50).
- `resyncs`: contador de resyncs ejecutados desde el arranque.
- `updated_at`: instante de la última escritura del fichero.

Consumo: `system-status` expone estos datos en la entrada `pulsar-consumer` como
campos **ADITIVOS**, sin alterar el contrato F41:
`ws_connected` (bool), `ws_silent` (bool) y `status_reason`
(`ws_disconnected` | `ws_silent` | `null`). `healthy`/`degraded` siguen siendo
`(instances === expected)` / `(instances > expected)` (contracts.md §5).

### 3. `system-status` (RF-55, opcional)

Si se añade el pool del API, la entrada mantiene el esquema F41:

```json
{
  "api-server": {
    "label": "API server (pool)",
    "online": true,
    "expected": 16,
    "instances": 16,
    "healthy": true,
    "degraded": false
  }
}
```

### 4. Firmware ESP32 (RF-56)

- Nuevo log serie: `[QR] Encolado→POST: %lu ms` (tiempo desde el callback USB al inicio del
  POST) además del existente `[QR] Validación HTTP %d (%lu ms)`.
- Sin cambios de contrato HTTP ni de TLS. Prioridad del QR = reordenación del `loop()`.

---

# Fase 51: Ciclo de vida del QR de huésped (Bug 4)

## 1. `POST /api/v1/qr/validate` — `qr_expired` con ventana

El código de error `qr_expired` se mantiene **estable** (sin cambios de estructura). Se añade,
de forma **aditiva**, el campo `window` en el detalle de la respuesta de error para distinguir la
ventana agotada:

```json
403 {"error":{"code":"qr_expired","message":"...","details":{"jti":"<uuid>","window":"arrival"}}}
403 {"error":{"code":"qr_expired","message":"...","details":{"jti":"<uuid>","window":"usage"}}}
```

- `window: "arrival"` → nunca se escaneó y se agotó `QR_ARRIVAL_WINDOW_MINUTES`.
- `window: "usage"`   → ya usado y se agotó `first_used_at + stays.duracion_minutos`.
- El `exp` sellado puede producir `qr_expired` **sin** `window` (comportamiento previo); los
  clientes deben tratar `window` como opcional.

`qr_already_used` deja de emitirse en el flujo de huésped (la reentrada dentro de la ventana de
uso es válida). No hay cambios en la respuesta 200 de éxito.

## 2. `qr_status` (en `/live` y SSE) — campos aditivos

`qr_status` conserva todos sus campos y añade cuatro. `consumed`, `revoked`, `expired`,
`scannable`, `jti`, `stay_id`, `expires_at` y `qr_text` mantienen su semántica.

```json
{
  "qr_status": {
    "scannable": true,
    "consumed": false,
    "revoked": false,
    "expired": false,
    "jti": "<uuid>",
    "stay_id": 123,
    "expires_at": "2026-09-22 10:45:00.000",
    "qr_text": "<token HMAC reconstruido de issued_at/expires_at>",
    "first_used_at": null,
    "valid_until": null,
    "arrival_deadline": "2026-09-22 10:15:00.000",
    "in_use": false
  }
}
```

| Campo | Tipo | Semántica |
|-------|------|-----------|
| `first_used_at` | `string\|null` | Instante del primer escaneo (UTC `DATETIME(3)`); `null` si no usado. |
| `valid_until` | `string\|null` | `first_used_at + duracion_minutos`; `null` si no usado. |
| `arrival_deadline` | `string` | `issued_at + QR_ARRIVAL_WINDOW_MINUTES` (UTC `DATETIME(3)`). |
| `in_use` | `bool` | `true` si el QR ya tuvo su primer uso. |

`expired` pasa a calcularse así:

- Sin uso → `now > issued_at + QR_ARRIVAL_WINDOW_MINUTES`.
- Con uso → `now > valid_until` (o `first_used_at + duracion_minutos` si `valid_until` es `null`).

Cuando no hay credencial, los cuatro campos aditivos se devuelven a `null`/`false` junto al resto
del objeto por defecto.

## 3. Emisión — sello del token

Sin cambios de esquema de respuesta en `POST /api/v1/qr`: `issued_at` y `expires_at` siguen
presentes. Semánticamente, `expires_at` ahora es
`issued_at + (QR_ARRIVAL_WINDOW_MINUTES + duracion_minutos)`. `room_type.qr_usage_window_minutes`
queda deprecado para el QR de huésped.

## 4. Configuración

| Variable | Default | Descripción |
|----------|---------|-------------|
| `QR_ARRIVAL_WINDOW_MINUTES` | `15` | Ventana de llegada global (minutos); común a todas las habitaciones. |

---

# Fase 54: Batería de aceptación E2E (BLOCK 42)

## 0. Alcance

**No hay cambios de contrato de API.** Esta fase es exclusivamente un test de aceptación en
`api/bin/run-tests.sh` (BLOCK 42) sobre PROTO2 (`rooms.id=12`). Se documentan aquí las llamadas
que el test encadena y los campos de `/live` que usa como aserciones, para que una futura
modificación de contrato sepa qué rompe la aceptación.

## 1. Secuencia de llamadas

| # | Método y ruta | Auth | Códigos | Efecto/observación |
|---|---------------|------|---------|--------------------|
| S0 | `POST /dashboard-api/rooms/reset` | pública | 200 / 400 / 404 | Reset determinista previo (`{"room_id":12}`) |
| S1 | `POST /api/v1/qr` | `VB6-MAIN` + `Idempotency-Key` | 201 / 409 / 422 | Crea stay `RESERVED`; devuelve `stay_id`, `jti`, `qr_text`, `issued_at`, `expires_at` |
| S2 | `GET /api/v1/rooms/12/live` | pública | 200 / 404 | `active_stay.status`, `qr_status` |
| S3 | `POST /api/v1/qr/validate` | `RPI-DEV` | 200 / 403 | `RESERVED→OCCUPIED`, `first_used_at`, lock (SIMULATED) |
| S4 | `GET /api/v1/rooms/12/live` | pública | 200 | `active_stay.status=OCCUPIED`, `qr_status.in_use=true` |
| S5 | `POST /sim/rooms/12/presence` + `POST /sim/rooms/12/door` ×2 | `SIM-CLIENT` | 202 / 403 | `PRESENT` + `OPEN` + `CLOSED` |
| S6 | `GET /api/v1/rooms/12/live` | pública | 200 | `entry_confirmed_at` no nulo, `exit_deadline` nulo |
| S7 | `POST /sim/rooms/12/door` ×2 + `POST /sim/rooms/12/presence` | `SIM-CLIENT` | 202 | `OPEN`+`CLOSED` (post-entrada) + `ABSENT` |
| S8 | `GET /api/v1/rooms/12/live` | pública | 200 | `exit_deadline` no nulo |
| S9 | worker `bin/exit-scan.php` (no HTTP) | — | — | `OCCUPIED→EXITED`, sala `FREE`, `AUTO_LOCK` |
| S10 | `POST /api/v1/stays/{id}/close` | `ADMIN-CLI` + `Idempotency-Key` | 200 / 409 | `EXITED→CLOSED`; encola `stay.closed` en `outbox_vb6` |
| S11 | `GET /api/v1/stays/{id}/overstay` | `ADMIN-CLI` | 200 / 404 / 409 | Lectura de overstay (opcional) |

Notas de contrato relevantes:
- **No existe `POST /api/v1/stays`**: la estancia la crea `POST /api/v1/qr` (`QrIssueService`).
- `/sim/rooms/{id}/door|presence` exigen `SIMULATED_MODE=true` **o** `rooms.simulated_override=1`.
- `POST /api/v1/qr` puede responder `422 slot_not_rentable` fuera de franja rentable o
  `409 room_busy` si la sala tiene estancia activa (el test normaliza antes).
- `POST /api/v1/qr/validate` exige que `device_id` sea un `RPI` del pack de la sala
  (`403 device_mismatch`).
- `POST /stays/{id}/close` encola `stay.closed` (efecto de contrato conocido).

## 2. Campos de `GET /api/v1/rooms/12/live` usados como aserciones

```json
{
  "room_id": 12,
  "status": "FREE",
  "iot_session": { "door_state": "CLOSED", "presence_state": "ABSENT", "last_absent_since": "..." },
  "active_stay": {
    "id": 123, "status": "OCCUPIED", "duracion_minutos": 60,
    "first_entry_at": "...", "entry_confirmed_at": "..." 
  },
  "exit_deadline": "2026-09-28 10:00:09.000",
  "gap_seconds": 15,
  "exit_guard_seconds": 5,
  "entry_window_seconds": 90,
  "exit_check_seconds": 40,
  "qr_status": {
    "scannable": false, "consumed": true, "in_use": true,
    "first_used_at": "...", "valid_until": "...", "arrival_deadline": "..."
  }
}
```

Aserciones por paso:
- **S2**: `active_stay.status == "RESERVED"`, `qr_status.scannable == true`, `in_use == false`.
- **S4**: `active_stay.status == "OCCUPIED"`, `qr_status.in_use == true`.
- **S6**: `active_stay.entry_confirmed_at` no nulo, `iot_session.presence_state == "PRESENT"`,
  `door_state == "CLOSED"`, `exit_deadline` nulo.
- **S8**: `iot_session.presence_state == "ABSENT"`, `door_state == "CLOSED"`, `exit_deadline` no nulo.

> Formato de fechas de `/live`: `Y-m-d H:i:s.v` (espacio, sin `T`/`Z`), según la serialización
> vigente del proyecto.

## 3. Idempotencia

- Claves por ejecución: `Idempotency-Key: e2e-f54-qr-<ts>` (S1) y `e2e-f54-close-<ts>` (S10).
- `/sim/*` no requieren `Idempotency-Key`; `rooms/reset` es seguro de repetir.

## 4. No regresión de contrato

Al no añadir rutas, campos ni códigos, ningún consumidor existente se ve afectado. La fase solo
verifica contratos ya vigentes.

---

# Fase 55: Panel de aceptación manual `/pruebas`

## 1. Rutas nuevas (aditivas, sin auth — LAN/MVP)

| Método | Ruta | Cuerpo / Query | Respuestas |
|--------|------|----------------|------------|
| GET | `/pruebas` | — | `200 text/html` (panel) |
| POST | `/dashboard-api/acceptance/save` | `{run_id, operator, room_id, commit, started_at, updated_at, revision, config, tests}` | `200 {ok,run_id,saved_at}` · `400` (`run_id` inválido / `tests` ausente) · `500` (escritura) |
| GET | `/dashboard-api/acceptance/list` | — | `200 [ {run_id,operator,room_id,commit,started_at,updated_at,pass,fail,na,pending,total,green} ]` |
| GET | `/dashboard-api/acceptance/get` | `?id=<run_id>` | `200 <corrida>` · `400` (id inválido) · `404` |
| DELETE | `/dashboard-api/acceptance/delete` | `?id=<run_id>` | `200 {ok,deleted}` · `400` · `404` |

- `run_id` debe cumplir `^[A-Za-z0-9_-]{1,64}$` (si no, `400`) → sin path traversal.
- Persistencia: `api/run/acceptance/<run_id>.json`; el `summary` lo recalcula el servidor
  (`green` = `pending=0 && fail=0 && (naCountsAsGreen || na=0)`).

## 2. Catálogo (`public/assets/acceptance-tests.json`)

```json
{
  "version": 1,
  "room_id": 12,
  "blocks": [ { "id": "0", "name": "Precondiciones", "order": 0 } ],
  "tests":  [ { "id": "P1", "block": "0", "type": "shell", "title": "…",
                "action": "…", "expected": "…", "evidence": "…" } ]
}
```

`type ∈ {shell, nav, fisico, mixto}`. 53 pruebas, `id` único.

## 3. Snapshot de evidencia

`📸 Capturar estado` hace `GET /api/v1/rooms/<room_id>/live` y `GET /api/v1/health/deep` (mismo
origen) y adjunta a la prueba `{captured_at, room_id, live, health, errors[]}`. Son contratos ya
vigentes; no se modifican.

## 4. No regresión

Rutas aditivas; ningún contrato existente cambia. `api/run/` es gitignored (no se versionan datos).

---

# Fase 56: La puerta del croquis sigue al sensor físico (RF-63)

## 1. Sin cambios de contrato

F56 es **solo panel** (regla visual pura + render). No añade ni modifica rutas, campos,
códigos de estado ni formatos de `/live`, SSE, outbox o `/sim/*`.

Los campos existentes mantienen su semántica:

- `iot_session.door_state`: `OPEN` **solo** con `PROXIMITY OPEN` aplicado; el comando del relé
  no lo altera (ya era así en backend desde F28).
- `recent_events`: sigue incluyendo `OPEN OK` (comando); el panel deja de usarlo para pintar
  la puerta y lo conserva para el toast de acceso.
- `recent_presence` (solo `applied = 1`): fuente del pulso visual anti-colapso de la puerta.

## 2. No regresión

Ningún consumidor externo se ve afectado; la verificación es la regresión completa del runner
(`BLOCK 33` incluye los unit tests JS de la regla pura).

---

# Fase 57: Verificación de salida visible y coherente (RF-64)

## 1. Sin cambios de contrato

F57 es **solo panel** (ventana de verificación pura + render del timer). No añade ni modifica
rutas, campos, códigos de estado ni formatos de `/live`, SSE, outbox o `/sim/*`.

Campos existentes, sin cambios de semántica:

- `exit_deadline`: sigue emitiéndose solo con `presence_state = ABSENT`, ciclo de puerta
  acreditado, puerta `CLOSED` y `entry_confirmed_at`; el panel ahora lo usa como deadline
  **preferente** dentro de la ventana de salida.
- `exit_guard_seconds` / `entry_window_seconds` / `gap_seconds`: sin cambios. `gap_seconds`
  sigue siendo legacy y ya no se usa como total del arco en el panel.
- `iot_session.last_close_at`: ancla de la ventana local de 20 s
  (`DEFAULT_EXIT_VERIFY_SECONDS`); no cambia su formato.

## 2. No regresión

Ningún consumidor externo se ve afectado. El conteo de entrada (RF-46.4) conserva su anclaje y
ventana; la verificación es la regresión completa del runner (`BLOCK 33` incluye los unit tests
JS de la coreografía).

---

# Fase 58: Orden por milisegundos de los eventos de sensor (RF-65)

## 1. Formato de `occurred_at` (precisión)

- Sin cambios de forma: sigue siendo ISO-8601 UTC (`Y-m-d\TH:i:s.v\Z` para eventos reales de
  Tuya; un sello en segundos se acepta como fallback).
- **Cambio de precisión**: los eventos reales de Tuya conservan ahora los **milisegundos** del
  DP `status[].t` (antes se truncaban a segundos). Afecta a:
  - `presence_events.occurred_at` (la columna ya era `DATETIME(3)`),
  - `iot_session.last_door_event_at` / `last_presence_event_at`,
  - `recent_presence[].occurred_at` en `/live` y SSE (misma forma, con `.sss`).
- Los consumidores existentes parsean la fracción (el panel usa `Choreography.parseTime`);
  `strtotime()` de PHP la ignora donde solo se comparan segundos (regla de salida).

## 2. Idempotencia

El `event_fingerprint` lógico **no cambia** (`sha1(room|sensor|value|segundo)`): los reenvíos
del mismo hecho en el mismo segundo siguen colapsando. El orden entre eventos del mismo segundo
se decide por `occurred_at` en milisegundos (`stale` si es anterior al último aplicado).

## 3. No regresión

Rutas, códigos, campos y eventos `SIMULATED` intactos; sin migración (columnas `DATETIME(3)` ya
existentes). La verificación es la regresión completa del runner con el caso nuevo de llegada
invertida del mismo segundo.

---

# Fase 59: Un QR nuevo limpia el ciclo anterior (RF-66)

## 1. Semántica de creación/emisión (sin cambios de forma)

Toda creación de QR deja la habitación sin el ciclo anterior:

- `POST /dashboard-api/qr-test/create` → `201` (mismos campos), previa limpieza:
  `rooms.cooldown_until = NULL` + `iot_sessions` a `UNKNOWN`/marcas `NULL`.
- `POST /api/v1/qr` (emisión real) → igual semántica, conservando `room_busy` (409) y el resto
  de validaciones. Mismos campos de respuesta.
- `POST /dashboard-api/qr-test/reset` y `POST /dashboard-api/rooms/reset` reutilizan la misma
  limpieza (antes duplicada); sus pasos adicionales (revocar QR, cerrar estancias, deudas, luz)
  no cambian.

La limpieza **no** modifica `rooms.status`, estancias, credenciales ni deudas; no hay columnas ni
migraciones nuevas.

## 2. No regresión

Rutas, códigos y campos intactos; la coreografía (`ANTI_REENTRADA`), F56/F57/F58, la regla de
salida, la luz y las anomalías no se tocan. Verificación: regresión completa del runner con el
caso de cooldown + IoT sucios antes de crear/emitir.

---

# Fase 60: Tipo AlmacenBebidas, cámara IP y pack (RF-67/68)

## 1. Tipo de habitación (extiende `/api/v1/room-types`)

Los objetos `room_type` añaden dos campos (mismos endpoints y códigos):

```json
{
  "id": 7, "code": "ALMACEN_BEBIDAS", "name": "Almacén de bebidas",
  "grace_minutes": 5, "exit_presence_gap_seconds": 15, "reentry_cooldown_seconds": 20,
  "qr_usage_window_minutes": 30, "presence_entry_window_seconds": 90, "exit_check_seconds": 40,
  "warehouse_confirm_seconds": 40, "warehouse_exterior_margin_seconds": 5
}
```
- `POST`/`PATCH` aceptan ambos campos; validación pura devuelve `400 validation_error` con
  `field` si X ∉ [10,600] o M ∉ [0,60]. Ausentes en un tipo no-almacén (defaults del esquema).

## 2. Dispositivo `CAMERA` (extiende `/api/v1/devices*`)

Representación:
```json
{
  "id": 91, "pack_id": 5, "kind": "CAMERA", "subtype": "EXTERIOR",
  "external_id": "CAM-ALM-EXT", "label": "Cámara pasillo",
  "meta": { "rtsp_url": "rtsp://user:pw@host:554/...", "enabled": true,
            "record_enabled": true, "resolution": "1920x1080", "note": "" },
  "online": null, "battery_pct": null
}
```
- `POST /api/v1/devices/register` acepta `subtype` (obligatorio si `kind=CAMERA`); si falta o es
  inválido → `422 validation_error` (`subtype_required` / `subtype_invalid`).
- `PATCH /api/v1/devices/{id}` admite `subtype`, `label`, `meta_json`.
- `Device::allKinds()` incluye `CAMERA`; el resto de endpoints de dispositivos no cambian.
- **Privacidad**: `meta.rtsp_url` puede contener credenciales; las respuestas de listado del panel
  público la **enmascaran** (`rtsp://***`), y solo se devuelve completa en `/almacen-api/cameras`
  del módulo almacén (MVP LAN) o nunca en logs.

## 3. Pack

Sin cambios de contrato: el pack de almacén es un `device_packs` con devices `pack_id`
(`kind=CAMERA` incluido).

---

# Fase 61: Gestión de cámaras y directo con go2rtc (RF-68/72)

## 1. `/almacen-api/cameras`

- `GET /almacen-api/cameras?room_id=N` →
  ```json
  { "cameras": [ { "id": 91, "position": "EXTERIOR", "label": "Cámara pasillo",
      "enabled": true, "record_enabled": true, "online": true, "recording": false,
      "stream": "almacen_1_EXTERIOR", "live_url": "http://92.113.151.136:1984/stream.html?src=almacen_1_EXTERIOR" } ] }
  ```
  `online` es `true|false|null` (null = sin verificar); `recording` refleja
  `camera_recordings.status='RECORDING'`.
- `POST /almacen-api/cameras`
  Body `{ "room_id": 1, "pack_id": 5, "position": "INTERIOR", "external_id": "CAM-ALM-INT",
  "label": "…", "rtsp_url": "rtsp://…", "enabled": true, "record_enabled": true }` → `201`
  `{ "camera": {…} }`. `position` ∈ `EXTERIOR|INTERIOR`; `rtsp_url` obligatoria
  (`422 validation_error`).
- `PATCH /almacen-api/cameras/{id}` → `{ "camera": {…} }`. Tras crear/editar/borrar se invoca
  `go2rtc-sync`; si go2rtc falla → `502 upstream_error` pero la cámara queda persistida.
- `DELETE /almacen-api/cameras/{id}` → `200 { "ok": true }` (desactiva `enabled=0` y borra stream).
- `POST /almacen-api/cameras/sync` → `200 { "ok": true, "streams": N }`.

## 2. Directo (go2rtc)

- Nombre canónico de stream `almacen_<room_id>_<position>`; `live_url` apunta a
  `<GO2RTC_BASE>/stream.html?src=<stream>` (WebRTC/MSE). `GO2RTC_BASE` configurable en `.env`
  (`GO2RTC_BASE_URL`, por defecto `http://<host>:1984`).
- Si `GO2RTC_BASE_URL` no está configurada o go2rtc no responde → `live_url: null` y el panel
  muestra "directo no disponible"; nunca rompe la página.

---

# Fase 62: Permisos rol + excepción por empleado (RF-69)

## 1. `/almacen-api/access?room_type_id=N`

```json
{
  "room_type": { "id": 7, "code": "ALMACEN_BEBIDAS" },
  "roles": [ { "id": 1, "name": "Limpieza", "allowed": true } ],
  "workers": [ { "id": 3, "name": "Ana", "role_id": 1, "role_allowed": true,
                 "override": null, "effective": true } ]
}
```
`override` ∈ `"ALLOW" | "DENY" | null`; `effective` es el resultado de la política.

## 2. Modificaciones (públicas LAN)

- `PUT /almacen-api/access/role/{role_id}` body `{ "room_type_id": 7, "allow": true }`
  → `200 { "role": {…} }`. Reutiliza `assignRoomTypes`/`getRoomTypesForRole`.
- `PUT /almacen-api/access/worker/{worker_id}` body
  `{ "room_type_id": 7, "effect": "ALLOW" | "DENY" | null }` → `200 { "worker": {…} }`.
  `null` borra la excepción (vuelve al rol).
- Errores: `404 not_found` (worker/rol/tipo inexistente), `422 validation_error` (`effect` o
  `allow` inválidos).

## 3. Validación de QR de empleado (sin cambios de forma)

`POST /api/v1/workers/qr/validate` mantiene su request/response. Cambia **solo** la decisión
interna de permisos (excepción sobre rol). Rechazo por permisos: `403` con
`{"error":"access_denied","message":"…"}` y `access_event` `DENIED` con motivo
`access_denied`; **sin** abrir puerta ni crear grabaciones.

---

# Fase 63: Visitas y motor de grabación (RF-70/71)

## 1. `/almacen-api/visits`

`GET /almacen-api/visits?room_id=1&worker_id=&from=2026-09-30T00:00:00Z&to=…&outcome=&limit=50`
→
```json
{
  "visits": [
    { "id": 12, "room_id": 1, "worker": { "id": 3, "name": "Ana" },
      "entry_trigger": "QR", "outcome": "ENTERED",
      "qr_at": "2026-09-30T10:00:00.123Z", "entered_at": "2026-09-30T10:00:40.000Z",
      "exited_at": "2026-09-30T10:04:10.000Z",
      "recordings": [
        { "id": 40, "position": "EXTERIOR", "episode": "ENTRY", "status": "SAVED",
          "started_at": "…", "stopped_at": "…", "duration_s": 42, "size_bytes": 1234567,
          "video_url": "/almacen-api/recordings/40/video",
          "poster_url": "/almacen-api/recordings/40/poster" },
        { "id": 41, "position": "INTERIOR", "episode": "ENTRY", "status": "DISCARDED", "…": "…" }
      ] }
  ]
}
```
- `outcome` ∈ `ENTERED|NO_SHOW|ANONYMOUS|DENIED`; `entry_trigger` ∈ `QR|DOOR|PRESENCE`.
- Los registros `DISCARDED` no tienen `video_url` (o devuelve `410 gone`).
- `GET /almacen-api/visits/{id}` → `{ "visit": {…} }`; `404 not_found` si no existe.

## 2. Máquina de estados (contrato interno)

Las transiciones de §27.2 del diseño no son un contrato HTTP externo; se exponen como
`warehouse_state.state` ∈ `IDLE|QR_PENDING|RECORDING_INSIDE|EXTERIOR_ONLY|EXIT_PENDING` dentro de
`/almacen-api/state`. Los tests unitarios cubren la tabla completa.

## 3. Motivos de resultado

- `NO_SHOW`: disparó pero no hubo presencia en X. En trigger `QR`, INT descartada y EXT guardada;
  en trigger `DOOR`, ambas descartadas.
- `DENIED`: permisos denegados; sin vídeo.
- `ANONYMOUS`: presencia sin QR identificado.

---

# Fase 64: Recorder y retención (RF-73)

## 1. Servido de grabaciones

- `GET /almacen-api/recordings/{id}/video` → `200 video/mp4` con `Accept-Ranges: bytes`,
  `ETag`, soporte `Range` (`206` con `Content-Range`, `416` fuera de rango, `304` si
  `If-None-Match`). `404 not_found` si no existe o `410 gone` si `status=DISCARDED`.
- `GET /almacen-api/recordings/{id}/poster` → `200 image/jpeg`; `404` si no hay miniatura.
- Ruta resuelta **siempre** validada bajo `data/cameras/` (anti path-traversal) → si no, `403`.

## 2. Retención (configuración)

- `system_settings` clave `warehouse.retention_days` (servicio `api`, categoría `warehouse`).
  Valor `1` en pruebas; `0`/negativo = sin borrado automático. Expuesto (solo lectura) en
  `/almacen-api/state.retention` = `{ "days": 1, "auto": true, "disk_used_pct": 41 }`.

## 3. Recorder

- No expone HTTP; su contrato es la fila `camera_recordings` (`status` y transiciones
  `PENDING→RECORDING→SAVED|DISCARDED|FAILED`) y `pid`/`stop_requested`. El daemon es el único que
  escribe `file_path`/`size_bytes`/`poster_path`.

---

# Fase 65: Panel `/almacen` (RF-74/75)

## 1. Página

- `GET /almacen` → `200 text/html`, página única pública LAN (sin login). Si falta el HTML →
  `404` (patrón `/dashboard`).

## 2. Estado en vivo

- `GET /almacen-api/state?room_id=1` →
  ```json
  {
    "room": { "id": 1, "code": "ALMACEN" },
    "warehouse": { "state": "RECORDING_INSIDE", "occupied": true,
      "current_visit": { "id": 12, "worker": {"id":3,"name":"Ana"},
                         "entered_at": "…", "seconds_inside": 120 },
      "presence": "PRESENT", "door": "CLOSED" },
    "cameras": [ { "position": "EXTERIOR", "recording": true, "online": true, "live_url": "…" } ],
    "recordings_active": [ { "position": "INTERIOR", "episode": "ENTRY", "started_at": "…" } ],
    "retention": { "days": 1, "auto": true, "disk_used_pct": 41 },
    "server_ts": "2026-09-30T10:02:00.000Z"
  }
  ```
- `GET /almacen-api/event-stream?room_id=1` → SSE con eventos `connected`, `state` (misma forma
  que arriba), `ping` cada 5 s y `close` (`max_lifetime`); `retry: 3000`. Bypass de middleware
  (patrón F46). La página reconecta y hace fallback a `state` cada 2 s.

## 3. Controles

- `POST /almacen-api/door/open` body `{ "room_id": 1 }` → `200 { "ok": true, "mode": "manual" }`;
  registra `access_event` (`OPEN`, `reason='manual_almacen'`). Rechaza `409 room_busy` si ya hay
  apertura en curso (misma guarda de LockService).
- `RF-75` (métricas, alertas, export CSV, snapshots, salud, abrir/forzar, multi-almacén) se
  especifican como **deseables**; sus endpoints concretos se congelarán en la fase en que se
  implementen (no bloquean F60–F66).

## 4. Forma de error común

`{ "error": "<snake_case>", "message": "<texto>", "field": "<opcional>" }` con `400/403/404/409/410/422/502`.

---

# Fase 66: No regresión (RF-76)

- Todas las rutas, códigos y campos de `/api/v1/*` y `/dashboard-api/*` existentes se mantienen.
- Sin excepción de permiso, la validación del QR de empleado resuelve **igual** que antes.
- El motor de grabación no actúa en habitaciones que no sean `ALMACEN_BEBIDAS`: huésped, QR de
  huésped, `stays`, deudas, anomalías y coreografía del panel no se ven afectados.
- Verificación: `bash bin/run-tests.sh` con **0 failures** y **BLOCK 44** nuevo.

---

# Fase 67: Croquis en vivo del almacén (RF-77)

## 1. `GET /almacen-api/state?room_id=`

**Aditivo** sobre la forma de Fase 65. Se añade el bloque `live`; ningún campo existente cambia.

```json
{
  "room": { "id": 12, "code": "PROTO2", "room_type_id": 7 },
  "warehouse": { "state": "IDLE", "occupied": false, "current_visit": null },
  "live": {
    "door_state": "OPEN",
    "presence_state": "ABSENT",
    "switch_state": "UNKNOWN",
    "last_open_at": "2026-10-01 05:17:34.773",
    "last_close_at": "2026-09-30 17:00:20.900",
    "last_absent_since": "2026-09-30 15:01:32.968"
  },
  "cameras": [ { "id": 324, "position": "EXTERIOR", "recording": false,
                 "enabled": true, "live_url": "…" } ],
  "recordings_active": [],
  "retention": { "days": 1, "auto": true, "disk_used_pct": 41 },
  "server_ts": "2026-10-01T08:00:00Z"
}
```

- `door_state ∈ {OPEN, CLOSED, UNKNOWN}`; `presence_state ∈ {PRESENT, ABSENT, UNKNOWN}`;
  `switch_state ∈ {ON, OFF, UNKNOWN}`.
- Sin fila en `iot_sessions` o sin `SWITCH`: valores `UNKNOWN` y timestamps `null`.
- Si no hay sala `ALMACEN_BEBIDAS`, se mantiene la forma actual (`room: null`,
  `warehouse: null`) **más** `"live": null`.

## 2. `GET /almacen-api/event-stream?room_id=`

- Misma forma de eventos (`connected`, `state`, `ping`, `close`); el evento `state` incluye el
  bloque `live`. El **fingerprint** que decide emitir `state` pasa a ser
  `md5(json_encode([warehouse, cameras, recordings_active, live]))`.

## 3. No regresión

- Rutas, códigos y campos existentes intactos. El bloque `live` es **aditivo**.
- Verificación: `bash bin/run-tests.sh` con **0 failures** y **BLOCK 45** nuevo.

---

# Fase 68: Reproducción de visitas (RF-78)

## 1. `GET /almacen-api/visits/{id}`

**Aditivo** sobre la forma de Fase 63/65. Cada elemento de `recordings` añade `requested_at`;
ningún campo existente cambia.

```json
{
  "visit": {
    "id": 42,
    "room_id": 12,
    "worker": { "id": 3, "name": "Ana" },
    "entry_trigger": "QR",
    "outcome": "ENTERED",
    "qr_at": "2026-10-01 09:13:48.120",
    "entered_at": "2026-10-01 09:13:52.400",
    "exited_at": "2026-10-01 09:18:20.900",
    "created_at": "2026-10-01 09:13:48.120",
    "recordings": [
      {
        "id": 501,
        "position": "EXTERIOR",
        "episode": "ENTRY",
        "trigger": "QR",
        "status": "SAVED",
        "started_at": "2026-10-01 09:13:49.000",
        "stopped_at": "2026-10-01 09:18:26.000",
        "duration_s": 277,
        "requested_at": "2026-10-01 09:13:48.300",
        "video_url": "/almacen-api/recordings/501/video",
        "poster_url": "/almacen-api/recordings/501/poster"
      }
    ]
  }
}
```

- `requested_at` (DATETIME(3)) es el instante en que el motor solicitó la grabación (más próximo
  al evento que `started_at`); se usa como origen de sincronización.
- Si un `recording` no tiene `requested_at`, el campo es `null` y el cliente cae a `started_at`.

## 2. No regresión

- Rutas, códigos y campos existentes intactos. `requested_at` es **aditivo**.
- Verificación: `bash bin/run-tests.sh` con **0 failures** y **BLOCK 46** nuevo.

---

# Fase 69: Vista en directo y encendido de cámaras (RF-79)

## 1. Endpoints reutilizados (sin rutas nuevas)

- `PATCH /almacen-api/cameras/{id}` body `{ "enabled": true }` → `200 { "camera": { "enabled": true, "live_url": "…" } }`.
- `POST /almacen-api/cameras/sync` → `200 { "ok": true, "streams": N }`.
- `GET /almacen-api/state` sigue exponiendo `cameras[].{id, position, enabled, live_url}` (F66/F67).

## 2. Contrato de comportamiento

- "Ver en directo" **no** modifica `record_enabled`.
- Tras encender y sincronizar, `GET /almacen-api/state` devuelve `enabled=true` y `live_url` no
  nulo para las cámaras encendidas.

## 3. No regresión

- Rutas, códigos y campos existentes intactos. Verificación: `bash bin/run-tests.sh` con
  **0 failures** y **BLOCK 47** nuevo.

---

# Fase 70: Directo de cámaras por MJPEG (RF-80)

## 1. `GET /almacen-api/state` y `GET /almacen-api/cameras`

**Aditivo**: cada cámara incluye `mjpeg_url`.

```json
{
  "id": 324,
  "position": "EXTERIOR",
  "enabled": true,
  "live_url": "http://92.113.151.136:1984/stream.html?src=almacen_12_EXTERIOR",
  "mjpeg_url": "https://cerraduras.josue.ink/almacen-live?id=324"
}
```

- `mjpeg_url` es `null` si `CAMERAS_LIVE_BASE_URL` no está definida (compatibilidad).
- `live_url` (go2rtc) se mantiene como fallback.

## 2. Servicio interno MJPEG (no es parte de la API pública)

- `GET /almacen-live?id=<deviceId>` (vía Apache → `127.0.0.1:8086/live`) →
  `200 multipart/x-mixed-replace; boundary=frame`.
- `GET /status` (solo local) → `{ "ok": true, "cameras": N, "streams": M }`.
- Cámara inexistente/no habilitada → `404`; sin URL RTSP → `404`.

## 3. No regresión

- Rutas, códigos y campos de `/almacen-api/*` intactos; `mjpeg_url` es **aditivo**.
- Verificación: `bash bin/run-tests.sh` con **0 failures** y **BLOCK 48** nuevo.

---

# Fase 71: Presencia real del almacén y frescura de señal (RF-81 / RF-82)

## 1. `GET /almacen-api/state` — bloque `live` (aditivo)

```json
{
  "live": {
    "door_state": "CLOSED",
    "presence_state": "PRESENT",
    "switch_state": "ON",
    "last_open_at": "2026-10-02 09:10:37.900",
    "last_close_at": "2026-10-02 09:11:21.092",
    "last_absent_since": null,
    "door_age_seconds": 321,
    "presence_age_seconds": 3
  }
}
```

- `door_age_seconds` / `presence_age_seconds`: entero ≥ 0 = segundos desde el último evento
  **recibido** de ese sensor (`presence_events.received_at`); `null` si nunca hubo evento.
- No se elimina ni renombra ningún campo previo. `server_ts` sigue siendo el ancla temporal.

## 2. Comportamiento de dominio

- Para salas `ALMACEN_BEBIDAS`, un `PRESENT` de `PRESENCE` (TUYA) se aplica a
  `iot_sessions.presence_state` sin exigir estancia ni ventana de entrada.
- `GET /almacen-api/state` refleja `warehouse.occupied=true` y `live.presence_state="PRESENT"`
  mientras el sensor detecta presencia.
- `POST /api/v1/presence/events` (sensor=PRESENCE, value=PRESENT) sobre una sala de almacén
  devuelve `accepted=true` y `derived_state.presence_state="PRESENT"` (antes `ABSENT` con
  `discard_reason=no_context`).

## 3. No regresión

- Rutas, códigos y campos existentes intactos; `door_age_seconds`/`presence_age_seconds` son
  **aditivos**. El comportamiento F48 de habitaciones de huésped no cambia.
- Verificación: `bash bin/run-tests.sh` con **0 failures** y **BLOCK 49** nuevo.

---

# Fase 72: Estado persistente de la puerta y resync periódico (RF-83 / RF-84)

## 1. Contrato de comportamiento del croquis (puerta)

- `door_state = OPEN` (o pulso F56 vigente) → chip **`PUERTA ABIERTA`**, independientemente de
  `door_age_seconds`.
- `door_state = CLOSED` → chip **`PUERTA CERRADA`**, independientemente de `door_age_seconds`.
- `door_state` ausente/`UNKNOWN` → chip **`PUERTA SIN DATOS`**.
- `door_age_seconds` / `presence_age_seconds` permanecen en `live` como **diagnóstico** y no
  alteran ningún chip.

## 2. Contrato interno del resync periódico (no es API pública)

- Variable de entorno `CONSUMER_DOOR_RESYNC_MS` (por defecto `600000`).
- Solo se sondea `kind = PROXIMITY` por REST (`/v1.0/iot-03/devices/{id}/status`).
- Con `WS` no conectado, o presupuesto `tuya-quota.json` agotado, el ciclo se omite sin error.
- El estado sondeado se reenvía a `POST /api/v1/tuya/webhook` (mismo camino que el push), por lo
  que `presence_events.received_at` se refresca y una transición perdida se aplica.

## 3. No regresión

- Sin rutas nuevas ni campos eliminados; `door_age_seconds`/`presence_age_seconds` se mantienen.
- Verificación: `bash bin/run-tests.sh` con **0 failures** y **BLOCK 50** nuevo.

---

# Fase 73: Tope de grabación en pruebas y purga del almacén (RF-85 / RF-86)

## 1. Contrato interno del recorder (no es API pública)

- Variable de entorno `WAREHOUSE_MAX_RECORDING_SECONDS` (entero ≥ 0; por defecto `0` = sin
  límite).
- Al alcanzar el tope, la fila `camera_recordings` pasa de `RECORDING` a `SAVED` con
  `duration_s` ≤ tope + 1 tick y `file_path` final (`.mp4`), conservando el póster.
- No se crean nuevos clips hasta que el motor emita `A_START_*`.

## 2. Herramienta de mantenimiento (no es API pública)

- `php bin/warehouse-purge.php --room=N` (o `--all`) → resumen
  `recordings=<n> visits=<n> state=<n> files=<n>`.
- Solo elimina ficheros cuya ruta real quede dentro de `data/cameras/`.

## 3. No regresión

- Rutas, códigos y campos de `/almacen-api/*` intactos.
- Verificación: `bash bin/run-tests.sh` con **0 failures** y **BLOCK 51** nuevo.
---

# Fase 74: Sin polling periódico que consuma cuota Tuya (RF-87)

## 1. Contrato de comportamiento

- **No existe** ningún sondeo REST periódico a Tuya. El consumer no define
  `CONSUMER_DOOR_RESYNC_MS` ni un temporizador que llame a `/devices/{id}/status`.
- El estado de puerta/presencia entra **solo por push** y persiste como último estado conocido
  (RF-83); no se refresca por tiempo.
- Se conserva **únicamente** el resync puntual al (re)conectar el WS (event-driven, pre-F72).

## 2. API pública

- `GET /almacen-api/state` y el SSE no cambian: siguen exponiendo `live` con
  `door_state`, `presence_state`, `switch_state`, `last_*` y `*_age_seconds` (diagnóstico).

## 3. No regresión

- Sin rutas nuevas ni campos eliminados; se elimina únicamente el resync periódico interno.
- Verificación: `bash bin/run-tests.sh` con **0 failures** y **BLOCK 50** actualizado (la sección
  de polling periódico de Fase 72 §2 queda **derogada**).

---

# Fase 75: Refresco de sensores del almacén bajo demanda (RF-88/89/90)

## 1. `POST /almacen-api/sensors/refresh` (nueva, aditiva)

Request opcional:
```json
{ "room_id": 12 }
```
Respuestas:
```json
// lectura realizada
{ "ok": true, "probed": true, "reason": "ok",
  "state": { "room": {...}, "live": {...}, "warehouse": {...} } }

// cooldown vigente (sin gastar crédito)
{ "ok": true, "probed": false, "reason": "throttled", "state": { ... } }

// cuota/backoff de Tuya o error de red
{ "ok": false, "probed": false, "reason": "quota|unavailable", "error": "..." }
```
- La ruta es **pública LAN** (igual que el resto de `/almacen-api/*`), sin autenticación.
- **Nunca** se invoca por temporizador. Cooldown por defecto 1800 s
  (`ALMACEN_SENSOR_REFRESH_COOLDOWN_SECONDS`), persistido en `devices.meta_json.status_probed_at`.

## 2. Semántica de presencia del almacén (RF-89)

- En `ALMACEN_BEBIDAS`, `PRESENT` solo se aplica con `tuya_raw_val="presence"`; `move` → `no_context`.
- `ABSENT` siempre se aplica. Contrato de `GET /almacen-api/state` sin cambios (mismos campos).

## 3. No regresión

- `GET /almacen-api/state`, SSE y `/api/v1/*` intactos; la nueva ruta es aditiva.
- `run-tests.sh` no borra el estado de la sala de almacén (RF-90).
- Verificación: `bash bin/run-tests.sh` con **0 failures** y **BLOCK 52** nuevo.
