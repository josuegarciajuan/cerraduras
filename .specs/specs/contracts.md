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
