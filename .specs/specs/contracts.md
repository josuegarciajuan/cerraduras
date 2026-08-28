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
- `esp_task_wdt_init(60, true)` — timeout 60s, reinicio automático al expirar
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
HTTPS obligatorio; CRM o scope factory:claim
Body: { "chip_id": "a1b2c3d4e5f6", "factory_key": "<pegada desde portal>",
        "label": "opcional", "pack_id": 12 }
```

La respuesta nunca contiene `factory_key`. Una clave inválida produce error
genérico; registro inexistente `404`, binding incompatible `409` y datos
inválidos `422`.

## Binding

Un `api_client` bound solo puede operar sobre su único RPI. La incompatibilidad
devuelve `403` con código `device_mismatch`. Clientes legacy sin binding siguen
autorizándose por scope hasta su migración.

El registro de auditoría del claim incluye `chip_id`, `status_before`,
`status_after`, `actor` y `created_at`.
