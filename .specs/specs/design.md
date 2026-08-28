# Design — Cerraduras Hotel

---

# Fase 1: Robustez firmware ESP32 QR Reader

## Arquitectura general

El firmware actual (`scanner-relay-prod.ino`) es un sketch Arduino monociclo (`setup()` + `loop()`). La Fase 1 NO introduce FreeRTOS ni tareas separadas. Simplemente reemplaza patrones bloqueantes por patrones no bloqueantes y añade protecciones.

## 1. Watchdog Timer

**Ubicación**: `setup()`, **línea ~274** (después de `Serial.begin` y antes del resto de setup).

```cpp
#include "esp_task_wdt.h"

void setup() {
  Serial.begin(115200);
  esp_task_wdt_init(60, true);  // 60s timeout, panic on timeout
  esp_task_wdt_add(NULL);       // vigila la tarea actual (loop)
  ...
}
```

**En `loop()`**, al inicio:
```cpp
void loop() {
  esp_task_wdt_reset();  // <-- primera línea
  ...
}
```

Esto garantiza que si cualquier rama del código bloquea el loop por >60s, el ESP32 se reinicia solo.

## 2. Eliminar delays bloqueantes

### 2.1 `delay(1)` → `yield()`

**Línea 639**:
```cpp
// Antes:
delay(1);

// Ahora:
yield();  // cede control al RTOS, suficiente para WiFi y USB
```

### 2.2 Relé no bloqueante (RF-2.3)

**Estado actual** (L137-146):
```cpp
void relayPulse() {
  relayOn();
  delay(OPEN_DURATION_MS);   // 3 segundos BLOQUEANTES
  relayOff();
}
```

**Nuevo diseño** — máquina de estados con timer:

Variables globales nuevas:
```cpp
unsigned long relayOffAt = 0;       // millis() cuando toca apagar el relé
bool          relayPulsing = false; // ¿estamos en pulso de apertura?
```

La función `relayPulse()` cambia a:
```cpp
void relayPulse() {
  relayOn();
  relayOffAt = millis() + OPEN_DURATION_MS;
  relayPulsing = true;
}
```

Y en `loop()`, después del bloque de heartbeat, se añade:
```cpp
// ── Relé no bloqueante ──
if (relayPulsing && millis() > relayOffAt) {
  relayOff();
  relayPulsing = false;
}
```

### 2.3 LED no bloqueante (RF-2.4)

**Estado actual** (L96-105):
```cpp
void wifiConnectedFeedback() {
  digitalWrite(LED_PIN, HIGH);
  relayClick();
  delay(LED_ON_MS);  // 4.5 segundos BLOQUEANTES
  digitalWrite(LED_PIN, LOW);
}
```

**Nuevo diseño**:

Variable global nueva:
```cpp
unsigned long ledOffAt = 0;
```

`wifiConnectedFeedback()` cambia a:
```cpp
void wifiConnectedFeedback() {
  digitalWrite(LED_PIN, HIGH);
  relayClick();  // 150ms de clic — esto SÍ es aceptable como delay corto
  ledOffAt = millis() + LED_ON_MS - RELAY_CLICK_MS; // compensar el delay del clic
}
```

Y en `loop()`:
```cpp
// ── LED no bloqueante ──
if (ledOffAt && millis() > ledOffAt) {
  digitalWrite(LED_PIN, LOW);
  ledOffAt = 0;
}
```

### 2.4 QR pendiente sin WiFi no bloqueante (RF-2.2)

**Estado actual** (L462-464):
```cpp
if (!ensureWiFi()) {
  Serial.println("[QR] Pendiente pero sin WiFi — esperando reconexion...");
  delay(1000); return;
}
```

**Nuevo diseño**: simplemente reencolar el QR y reintentar en la siguiente iteración del loop:

```cpp
if (!ensureWiFi()) {
  static unsigned long lastWifiWarn = 0;
  if (millis() - lastWifiWarn > 5000) {
    Serial.println("[QR] Pendiente pero sin WiFi — esperando reconexion...");
    lastWifiWarn = millis();
  }
  hasPending = true;  // reencolar para siguiente iteración
  return;
}
```

Esto evita spamear el Serial cada 1ms y permite que el loop siga procesando heartbeat, identify, etc.

## 3. Prevenir fragmentación de heap (RF-3)

**Principio**: llamar a `.reserve(N)` ANTES de concatenar con `+`. Esto pre-asigna un buffer contiguo y evita realocaciones.

### 3.1 POST QR validate (L479-483)

```cpp
String body;
body.reserve(600);  // qr_text puede tener ~400 chars + device_id ~15 + JSON ~60
body = "{\"qr_text\":\"" + qrEscaped + "\",\"device_id\":\"" + id + "\"}";
```

### 3.2 sendIdentify (L250-251)

```cpp
String body;
body.reserve(200);
body = "{\"external_id\":\"" + id + "\",\"state\":" + (state ? "true" : "false") + "}";
```

### 3.3 Heartbeat batch (L534)

```cpp
String hbBody;
hbBody.reserve(250);
hbBody = "{\"external_id\":\"" + chipId() + "\",\"sub_kinds\":[\"RPI\",\"SCANNER\",\"LOCK\"]}";
```

### 3.4 Command result (L588-594)

```cpp
String resultBody;
resultBody.reserve(350);
resultBody = "{\"command_id\":" + String(cmdId) +
             ",\"external_id\":\"" + chipId() + "\"" +
             ",\"results\":{" +
             "\"RPI\":true," +
             "\"SCANNER\":" + String(scannerOk ? "true" : "false") + "," +
             "\"LOCK\":" + String(lockOk ? "true" : "false") +
             "}}";
```

## 4. Auto-reinicio si WiFi caído > 2 min (RF-4)

Variables globales nuevas:
```cpp
unsigned long wifiDownSince = 0;  // 0 = WiFi OK, >0 = millis() del momento de caída
```

En `loop()`, después del bloque de heartbeat (que ya llama a `ensureWiFi()`), añadir:

```cpp
// ── WiFi watchdog ──
if (WiFi.status() != WL_CONNECTED) {
  if (wifiDownSince == 0) {
    wifiDownSince = millis();
    Serial.println("[WIFI] Conexión perdida — iniciando contador de 120s para reinicio");
  } else if (millis() - wifiDownSince > 120000) {
    Serial.println("[WIFI] 120s sin conexión — reiniciando ESP32...");
    delay(500);
    ESP.restart();
  }
} else {
  wifiDownSince = 0;  // WiFi OK → resetear contador
}
```

## 5. Diagnóstico de heap (RF-6)

En el bloque de heartbeat (línea ~521), añadir después de `lastBeat = now`:

```cpp
unsigned long heap = ESP.getFreeHeap();
Serial.printf("[HB] Free heap: %lu bytes\n", heap);
if (heap < 20480) {
  Serial.printf("[HB] ⚠️ ADVERTENCIA: heap bajo (%lu bytes) — posible fuga de memoria\n", heap);
}
```

## 6. Resumen de variables globales nuevas

| Variable | Tipo | Inicial | Propósito |
|----------|------|---------|-----------|
| `relayOffAt` | `unsigned long` | `0` | Timestamp para apagar relé |
| `relayPulsing` | `bool` | `false` | ¿Relé en pulso activo? |
| `ledOffAt` | `unsigned long` | `0` | Timestamp para apagar LED |
| `wifiDownSince` | `unsigned long` | `0` | Timestamp de caída WiFi |

## 7. Cambios en `setup()` — resumen

```cpp
void setup() {
  Serial.begin(115200);
  esp_task_wdt_init(60, true);
  esp_task_wdt_add(NULL);
  delay(3000);  // esperar a Serial Monitor en debug — aceptable en setup()

  relayOff();
  // ... resto del setup() sin cambios ...
}
```

## 8. Cambios en `loop()` — estructura final

```cpp
void loop() {
  esp_task_wdt_reset();  // SIEMPRE primera línea

  // ── Procesar QR pendiente ──
  if (hasPending) { ... }

  // ── Heartbeat + F33 + heap log (cada 30s) ──
  unsigned long now = millis();
  if (now - lastBeat > 30000) { ... }

  // ── Relé no bloqueante ──
  if (relayPulsing && millis() > relayOffAt) { relayOff(); relayPulsing = false; }

  // ── LED no bloqueante ──
  if (ledOffAt && millis() > ledOffAt) { digitalWrite(LED_PIN, LOW); ledOffAt = 0; }

  // ── Identify button ──
  // ... sin cambios ...

  // ── WiFi watchdog ──
  if (WiFi.status() != WL_CONNECTED) {
    if (wifiDownSince == 0) wifiDownSince = millis();
    else if (millis() - wifiDownSince > 120000) { delay(500); ESP.restart(); }
  } else { wifiDownSince = 0; }

  yield();  // ceder control al RTOS
}
```

## 9. Lo que NO cambia

- API endpoints, API key, URL base
- Lógica de validación QR (POST, relay, heartbeat)
- GPIOs (relay 16, LED 2, identify 4)
- WiFi provisioning (NVS + WiFiManager)
- USB Host callbacks (onKeyboard, onDeviceConnected)
- Formato de QR (token JWT-like, 2 dots, >=100 chars)
- Heartbeat cada 30s + F33 command polling

---

# Fase 35: Detección de anomalías en el flujo de sensores

## Arquitectura general

El sistema de anomalías se integra como una **capa de observabilidad** sobre el pipeline existente de procesamiento de eventos de sensores. No modifica el comportamiento del negocio (QR, locks, exit rule), solo emite señales cuando detecta patrones inconsistentes.

### Principio de diseño

- **Non-blocking**: la detección de anomalías nunca debe impedir ni retrasar el procesamiento normal de eventos.
- **Idempotente**: una misma condición anómala no debe generar múltiples registros duplicados.
- **Auto-resolutivo**: cuando la condición anómala desaparece, la anomalía se cierra automáticamente (`OPEN → DISMISSED`).
- **Best-effort**: si falla la persistencia de una anomalía, se loguea y se continúa.

## 1. Modelo de datos

### 1.1 Tabla `anomalies`

```sql
CREATE TABLE anomalies (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    room_id         INT NOT NULL,
    stay_id         INT NULL,
    anomaly_type    VARCHAR(50) NOT NULL,        -- 'A1', 'A2', ... 'A8'
    severity        ENUM('LOW','MEDIUM','HIGH','CRITICAL') NOT NULL,
    status          ENUM('OPEN','ACKNOWLEDGED','DISMISSED') NOT NULL DEFAULT 'OPEN',
    context_data    JSON NOT NULL,               -- snapshot del estado IoT al detectar
    detected_at     DATETIME(3) NOT NULL,
    acknowledged_at DATETIME(3) NULL,
    acknowledged_by VARCHAR(100) NULL,
    dismissed_at    DATETIME(3) NULL,
    dismissed_by    VARCHAR(100) NULL,
    created_at      DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at      DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    
    INDEX idx_room_status (room_id, status),
    INDEX idx_anomaly_type (anomaly_type),
    INDEX idx_severity (severity),
    INDEX idx_detected (detected_at),
    FOREIGN KEY (room_id) REFERENCES rooms(id),
    FOREIGN KEY (stay_id) REFERENCES stays(id)
);
```

### 1.2 `context_data` — estructura JSON

Campo libre que captura el estado relevante en el momento de la detección:

```json
{
    "door_state": "CLOSED",
    "presence_state": "PRESENT",
    "stay_status": "OCCUPIED",
    "room_status": "OCCUPIED",
    "last_open_at": "2026-07-25T10:30:00.000Z",
    "last_close_at": "2026-07-25T10:30:05.000Z",
    "last_absent_since": null,
    "trigger_event": {
        "sensor": "PRESENCE",
        "value": "PRESENT",
        "source_event_id": "tuya-ev-12345"
    },
    "relevant_window": {
        "door_events_last_24h": 3,
        "last_door_open_seconds_ago": 86400,
        "presence_duration_seconds": 25200
    }
}
```

## 2. Detectores de anomalías

Cada tipo de anomalía tiene su propio detector. Se organizan en un registry para evaluación encadenada.

### 2.1 `AnomalyDetector` (interfaz)

```php
interface AnomalyDetector {
    public function detect(IotSession $session, PresenceEvent $trigger, ?Stay $stay): ?AnomalyResult;
}

class AnomalyResult {
    public string $type;       // 'A1', 'A2', ...
    public string $severity;
    public array  $contextData;
}
```

### 2.2 `AnomalyPipeline`

```php
class AnomalyPipeline {
    /** @var AnomalyDetector[] */
    private array $detectors;
    
    public function evaluate(IotSession $session, PresenceEvent $trigger, ?Stay $stay): array {
        $results = [];
        foreach ($this->detectors as $detector) {
            try {
                $result = $detector->detect($session, $trigger, $stay);
                if ($result !== null) {
                    $results[] = $result;
                }
            } catch (\Throwable $e) {
                // Non-blocking: log and continue
                error_log("[ANOMALY] Detector {$detector::class} failed: {$e->getMessage()}");
            }
        }
        return $results;
    }
}
```

### 2.3 Punto de inserción en el pipeline existente

En `IotSessionService::processEvent()`, después de actualizar `door_state`/`presence_state` y **antes** de evaluar la regla de salida:

```
processEvent($event):
    1. Validar room
    2. Persistir presence_event (idempotente)
    3. Cargar/crear iot_session
    4. Actualizar door_state / presence_state / timestamps
    5. Persistir iot_session
    ─── NUEVO ───
    6. AnomalyPipeline::evaluate(iot_session, trigger_event, active_stay)
        → persistir anomalías detectadas
    ─── EXISTENTE ───
    7. ExitRuleEvaluator::evaluate()
    8. Si exit → ExitActionService::execute()
```

## 3. Lógica de detección por tipo

### A1 — Presencia sin apertura de puerta

**Disparador**: evento `PRESENCE=PRESENT`.

**Condición**: `door_state` nunca fue `OPEN` desde `first_entry_at` del stay activo (o desde que la room quedó FREE si no hay stay).

**Implementación**:
```php
class PresenceWithoutDoorOpen implements AnomalyDetector {
    public function detect(IotSession $session, PresenceEvent $trigger, ?Stay $stay): ?AnomalyResult {
        if ($trigger->sensor !== 'PRESENCE' || $trigger->value !== 'PRESENT') return null;
        if (!$stay || $stay->status !== 'OCCUPIED') return null;
        
        // Buscar evento PROXIMITY=OPEN desde first_entry_at
        $hasOpen = PresenceEventRepository::hasProximityOpenSince(
            $session->roomId, 
            $stay->firstEntryAt
        );
        
        if ($hasOpen) return null;
        
        return new AnomalyResult(
            type: 'A1',
            severity: 'HIGH',
            contextData: [
                'door_state' => $session->doorState,
                'presence_state' => $session->presenceState,
                'stay_status' => $stay->status,
                'first_entry_at' => $stay->firstEntryAt,
                'last_open_at' => $session->lastOpenAt,
            ]
        );
    }
}
```

### A2 — Presencia en habitación sin stay

**Disparador**: evento `PRESENCE=PRESENT`.

**Condición**: room no tiene stay en `OCCUPIED` ni `EXITED`.

### A3 — Puerta abierta sin QR ni stay

**Disparador**: evento `PROXIMITY=OPEN`.

**Condición**: no hay stay `OCCUPIED` Y no hay `access_event` de tipo `QR_VALIDATE` en los últimos 30s para esa room.

### A4 — Presencia sin actividad de puerta

**Disparador**: evaluación periódica (`bin/anomaly-scanner.php`).

**Condición**: `presence_state = PRESENT` de forma ininterrumpida durante más de `duracion_minutos + 7200s` (2h de margen), sin ningún evento `PROXIMITY` en ese intervalo.

### A5 — EXITED sin apertura de salida

**Disparador**: evento de transición `stay.status → EXITED`.

**Condición**: `last_open_at` es null o es anterior a `first_entry_at` o tiene más de 2h de antigüedad respecto a `exit_detected_at`.

**Punto de inserción**: en `ExitActionService::execute()`, después de ejecutar los side-effects.

### A6 — Presencia post-EXITED

**Disparador**: evento `PRESENCE=PRESENT`.

**Condición**: el stay más reciente de la room está en `EXITED` y `exit_detected_at < now`.

### A7 — Puerta abierta sin presencia

**Disparador**: evaluación periódica.

**Condición**: `door_state = OPEN` Y `presence_state = ABSENT` Y `(now - last_open_at) > 120s`.

### A8 — Flapping de sensor

**Disparador**: cada evento de sensor.

**Condición**: contar transiciones del mismo sensor en los últimos `flapping_window_seconds` (30s). Si ≥ `flapping_threshold` (5) → anomalía.

## 4. Evaluación periódica (`anomaly-scanner.php`)

Nuevo worker similar a `exit-scan.php` y `overstay-scan.php`. Se ejecuta cada 15 segundos y evalúa anomalías que dependen de ventanas temporales (A4, A7, y auto-dismiss de anomalías resueltas).

```
anomaly-scanner.php (cada 15s):
    1. Para cada room con iot_session activo:
        a. Evaluar A4 (presencia sin actividad puerta)
        b. Evaluar A7 (puerta abierta sin presencia)
    2. Para cada anomalía OPEN:
        a. Si la condición ya no se cumple → DISMISSED
```

## 5. Servicio `AnomalyService`

```php
class AnomalyService {
    public function detectAndPersist(IotSession $session, PresenceEvent $trigger, ?Stay $stay): array;
    public function acknowledge(int $anomalyId, string $actor): void;
    public function autoDismiss(int $roomId, string $anomalyType): void;
    public function findByRoom(int $roomId, ?string $status): array;
    public function findAll(?string $severity, ?string $status, ?int $roomId): array;
}
```

### Deduplicación

Antes de insertar una nueva anomalía, verificar si ya existe una con `OPEN` para la misma `(room_id, anomaly_type)`:

```sql
SELECT id FROM anomalies 
WHERE room_id = ? AND anomaly_type = ? AND status = 'OPEN' 
LIMIT 1;
```

Si existe → no insertar (la anomalía ya está reportada).

## 6. Integración con RoomLiveController

El endpoint `GET /api/v1/rooms/{id}/live` ya devuelve datos agregados de la room. Se añade un campo `anomalies`:

```json
{
    "room": { ... },
    "stay": { ... },
    "iot": { ... },
    "anomalies": [
        {
            "id": 42,
            "anomaly_type": "A1",
            "severity": "HIGH",
            "status": "OPEN",
            "detected_at": "2026-07-25T10:30:00.000Z",
            "context_data": { ... }
        }
    ]
}
```

Solo se incluyen anomalías con `status = 'OPEN'`.

## 7. Dashboard

### Indicador por room

Cada tarjeta de room en el panel muestra un badge con el conteo de anomalías activas. Color según la severidad más alta:
- `CRITICAL`: badge rojo con icono ⚠
- `HIGH`: badge naranja
- `MEDIUM`: badge amarillo
- `LOW`: badge gris

### Vista de anomalías

Se añade una sección en el panel (`/panel/anomalies` opcional o integrada en el dashboard principal) con:
- Filtros: room, tipo, severidad, estado
- Tabla/listado con: room, tipo, severidad, detectada hace, estado
- Acción "Reconocer" (ACKNOWLEDGE) por fila

## 8. Archivos nuevos y modificados

| Archivo | Acción |
|---------|--------|
| `api/src/Domain/Anomalies/Anomaly.php` | Nuevo — modelo |
| `api/src/Domain/Anomalies/AnomalyDetector.php` | Nuevo — interfaz |
| `api/src/Domain/Anomalies/AnomalyPipeline.php` | Nuevo — orquestador |
| `api/src/Domain/Anomalies/AnomalyService.php` | Nuevo — servicio |
| `api/src/Domain/Anomalies/Detectors/A1_PresenceWithoutDoorOpen.php` | Nuevo |
| `api/src/Domain/Anomalies/Detectors/A2_PresenceWithoutStay.php` | Nuevo |
| `api/src/Domain/Anomalies/Detectors/A3_DoorOpenWithoutQr.php` | Nuevo |
| `api/src/Domain/Anomalies/Detectors/A4_PresenceWithoutDoorActivity.php` | Nuevo |
| `api/src/Domain/Anomalies/Detectors/A5_ExitedWithoutDoorOpen.php` | Nuevo |
| `api/src/Domain/Anomalies/Detectors/A6_PresenceAfterExit.php` | Nuevo |
| `api/src/Domain/Anomalies/Detectors/A7_DoorOpenWithoutPresence.php` | Nuevo |
| `api/src/Domain/Anomalies/Detectors/A8_SensorFlapping.php` | Nuevo |
| `api/src/Domain/Anomalies/AnomalyRepositoryInterface.php` | Nuevo — interfaz BD |
| `api/src/Infrastructure/Persistence/AnomalyRepository.php` | Nuevo — implementación |
| `api/src/Http/Controllers/AnomalyController.php` | Nuevo |
| `api/public/index.php` | Modificado — rutas de anomalías |
| `api/bin/anomaly-scanner.php` | Nuevo — worker periódico |
| `api/src/Domain/Presence/IotSessionService.php` | Modificado — insertar pipeline |
| `api/src/Domain/Presence/ExitActionService.php` | Modificado — detectar A5 |
| `api/src/Http/Controllers/RoomLiveController.php` | Modificado — incluir anomalías |
| `api/public/panel/` | Modificado — dashboard |
| `api/bin/migrate.php` | Añadir migración `anomalies` |

## 9. Lo que NO cambia

- La máquina de estados de Stay (ninguna transición nueva)
- Las reglas de salida (exit rule)
- El procesamiento de QR (emisión/validación)
- Los comandos de lock
- El worker de overstay
- El outbox / WS-VB6
- La simulación (`/sim/*`)

---

# Fase 37: Vista de anomalías con filtro de estado

## Arquitectura general

F37 extiende F35 sin modificar su núcleo. Los cambios son:

1. **Backend**: nuevo método `findResolvableForRoom()` en el repositorio para incluir ACKNOWLEDGED en la auto-resolución. El controller acepta `status=ALL` para omitir el filtro de estado.
2. **Frontend**: nuevo dropdown de estado en la barra de filtros, renderizado condicional por estado, badges visuales.

## 1. Auto-resolución de ACKNOWLEDGED

### Problema

`autoResolveForRoom()` en `AnomalyService` usaba `findOpenForRoom()` que solo devuelve `status='OPEN'`. Las anomalías ACKNOWLEDGED nunca se evaluaban y quedaban en ese estado para siempre.

### Solución

Nuevo método `findResolvableForRoom(int $roomId): array` en el repositorio:

```sql
SELECT ... FROM anomalies
WHERE room_id = :rid AND status IN ('OPEN', 'ACKNOWLEDGED')
ORDER BY detected_at DESC
```

`autoResolveForRoom()` ahora usa `findResolvableForRoom()` en lugar de `findOpenForRoom()`. El método `autoDismiss()` en `Anomaly` ya acepta ambos estados (su guarda es solo contra DISMISSED), así que no requiere cambios.

## 2. Controller — soporte para status=ALL

```php
// AnomalyController::index()
if (isset($params['status']) && $params['status'] !== '' && $params['status'] !== 'ALL') {
    $filters['status'] = (string) $params['status'];
} elseif (!isset($params['status'])) {
    $filters['status'] = 'OPEN'; // backward compat
}
// else: status is empty or 'ALL' → no status filter added → shows all
```

## 3. Frontend — filtro de estado

### 3.1 Dropdown

Se añade un cuarto `<select>` al `filterRow`:

```html
<select onchange="...">
  <option value="OPEN">📌 Pendientes</option>
  <option value="ACKNOWLEDGED">👁️ Reconocidas</option>
  <option value="DISMISSED">✅ Histórico</option>
  <option value="ALL">📋 Todas</option>
</select>
```

Los `onchange` de los otros dropdowns se actualizan para preservar el valor de `status` al cambiar otros filtros.

### 3.2 `loadAnomalies()`

```javascript
let st = this.anomalyFilters?.status || 'OPEN';
let params = new URLSearchParams({limit:'100'});
if (st && st !== 'ALL') params.set('status', st);
```

### 3.3 Renderizado condicional

- **Columna Estado**: badge con clase CSS `status-open` (rojo), `status-acked` (naranja), `status-dismissed` (verde).
- **Columna Acción**: si `OPEN` → botón "✓ Reconocer"; si `ACKNOWLEDGED`/`DISMISSED` → texto "por X · fecha".
- **Encabezado**: refleja el filtro activo ("Pendientes", "Reconocidas", "Histórico", "Todas").
- **Vacío contextual**: "Sin anomalías pendientes" vs "Sin anomalías reconocidas" vs "Sin anomalías".

## 4. Archivos modificados

| Archivo | Cambio |
|---------|--------|
| `api/src/Domain/Anomalies/AnomalyRepositoryInterface.php` | Nuevo método `findResolvableForRoom()` |
| `api/src/Domain/Anomalies/AnomalyRepository.php` | Implementación `findResolvableForRoom()` |
| `api/src/Domain/Anomalies/AnomalyService.php` | `autoResolveForRoom()` usa `findResolvableForRoom()` |
| `api/src/Http/Controllers/AnomalyController.php` | Soporte `status=ALL` / vacío |
| `api/public/panel/index.html` | Dropdown estado, badges, renderizado condicional, CSS |

## 5. Lo que NO cambia

- La máquina de estados de Anomaly (OPEN → ACKNOWLEDGED → DISMISSED)
- Los detectores A1–A8
- El pipeline de detección
- `checkAnomalyAlerts()` y `loadAnomalySummary()` — siguen contando solo OPEN
- Ningún endpoint nuevo

---

# Fase 36: Monitoreo de batería del sensor de puerta MC400D

## 1. Estrategia de obtención de datos

### 1.1 Push (pasivo) — vía webhook Tuya

El `TuyaSensorIngress` ya recibe los DPs del MC400D en cada push. Actualmente `battery_percentage` está en la lista `INFO_DPS` y se descarta. La F36 modifica el comportamiento: cuando se detecta `battery_percentage`, en lugar de solo saltarlo, se persiste en `devices.battery_pct`.

**Lugar de la modificación**: `TuyaSensorIngress::normalize()`, después de identificar el device via `$this->deviceRepo->findByExternalId($devId)` y antes del mapeo DP→evento canónico. Se itera sobre todos los DPs del push y, si alguno coincide con `INFO_DPS`, se persiste su valor.

**Ventaja**: Cero llamadas adicionales a Tuya API. La batería se actualiza cada vez que el sensor emite un evento (apertura/cierre de puerta).

### 1.2 Pull (activo) — bajo demanda desde el panel

Nuevo endpoint `POST /dashboard-api/battery-refresh` que:
1. Recibe `{device_id: N}`
2. Busca el dispositivo en BD
3. Llama a Tuya API: `GET /v1.0/iot-03/devices/{external_id}/status`
4. Extrae `battery_percentage` de los DPs en la respuesta
5. Persiste en `devices.battery_pct`
6. Retorna `{ok, device_id, kind, battery_pct, state}`

Usa la misma función `tuyaPresenceApi()` existente en `index.php` para firmar y llamar a la API de Tuya.

## 2. Modelo de datos

### 2.1 Nueva columna en `devices`

```sql
ALTER TABLE devices ADD COLUMN IF NOT EXISTS battery_pct TINYINT UNSIGNED NULL;
```

Se elige columna dedicada (no `meta_json`) porque:
- Es un valor escalar semánticamente significativo
- Consultable directamente en SQL
- Indexable si fuera necesario en el futuro
- Más simple que parsear JSON para cada lectura

### 2.2 Device.php

Nueva propiedad `?int $batteryPct` y campo en `toArray()`.

### 2.3 DeviceRepository

- `hydrate()`: lee `battery_pct` de la fila SQL
- `updateBattery(int $deviceId, ?int $pct)`: `UPDATE devices SET battery_pct = ? WHERE id = ?`

## 3. Umbrales de batería

| Estado | Condición | Icono |
|--------|-----------|-------|
| `critical` | `pct <= 10` | 🔴 |
| `low` | `10 < pct <= 20` | 🟡 |
| `normal` | `pct > 20` | 🟢 |
| `unknown` | `pct IS NULL` | ⚪ |

Estos umbrales son consistentes con el comportamiento típico de dispositivos Tuya con pilas AAA/LR03.

## 4. Endpoints modificados / nuevos

### 4.1 Nuevo: `POST /dashboard-api/battery-refresh`

- **Auth**: Sin auth (LAN/MVP, igual que el resto de dashboard-api)
- **Body**: `{"device_id": N}`
- **Llamada Tuya**: `GET /v1.0/iot-03/devices/{external_id}/status`
- **Persistencia**: `UPDATE devices SET battery_pct = ? WHERE id = ?`
- **Respuesta 200**:
  ```json
  {"ok":true,"device_id":5,"kind":"PROXIMITY","battery_pct":87,"state":"normal"}
  ```
- **Errores**: 400 (device_id faltante), 404 (dispositivo no encontrado), 502 (error Tuya API)

### 4.2 Modificado: `GET /dashboard-api/device-status?room_id=N`

Añadir `battery_pct` al SELECT y al array de respuesta de cada dispositivo.

### 4.3 Modificado: `GET /dashboard-api/pack-detail?pack_id=N`

Igual que device-status: añadir `battery_pct` a la respuesta.

### 4.4 Modificado: `GET /api/v1/rooms/{id}/live`

Nuevo campo `battery` en la respuesta:
```json
"battery": {"device_id":3,"kind":"PROXIMITY","label":"Sensor Puerta","pct":87,"state":"normal"}
```
Solo se incluye si la habitación tiene un dispositivo PROXIMITY en su pack.

## 5. Panel (UI)

### 5.1 CRM Panel (`panel/index.html`)

En `openRoomDetail()`, para cada dispositivo PROXIMITY:
- Mostrar `🔋 87%` con color según umbral
- Botón `🔄` que llama a `POST /dashboard-api/battery-refresh`

### 5.2 Dashboard (`dashboard.html`)

En el panel de dispositivos, junto a "Sensor Puerta":
- Mostrar indicador de batería con porcentaje y color
- Botón de refresh

## 6. Archivos afectados

| Archivo | Cambio |
|---------|--------|
| `api/migrations/0043_battery_pct.sql` | Nuevo — columna `battery_pct` |
| `api/src/Domain/Devices/Device.php` | Propiedad `batteryPct` |
| `api/src/Domain/Devices/DeviceRepository.php` | `hydrate()` + `updateBattery()` |
| `api/src/Infrastructure/Gateways/Sensor/TuyaSensorIngress.php` | Persistir battery en push |
| `api/public/index.php` | Nuevo endpoint `battery-refresh` + modificar device-status/pack-detail |
| `api/src/Http/Controllers/RoomLiveController.php` | Campo `battery` en live |
| `api/public/panel/index.html` | Indicador + botón en room detail |
| `api/public/dashboard.html` | Indicador de batería |
| `api/tests/Unit/ChipBatteryTest.php` | Nuevo — unit test |
| `api/bin/run-tests.sh` | BLOCK 26 — HTTP tests |

## 7. Lo que NO cambia

- La máquina de estados de Stay/Room/IoT Session
- Los comandos de lock/switch
- El pipeline de presencia
- La detección de anomalías existente
- El worker de overstay
- La simulación (`/sim/*`)


---
# Fase 38: Workers — Trabajadores del hotel con QR maestro

## 1. Modelo de datos

### Tablas nuevas

```sql
CREATE TABLE worker_roles (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(50) NOT NULL,
    description VARCHAR(255) NULL,
    created_at  DATETIME(3) NOT NULL DEFAULT (UTC_TIMESTAMP(3)),
    updated_at  DATETIME(3) NOT NULL DEFAULT (UTC_TIMESTAMP(3))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE worker_role_room_types (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    role_id      INT NOT NULL,
    room_type_id INT NOT NULL,
    created_at   DATETIME(3) NOT NULL DEFAULT (UTC_TIMESTAMP(3)),
    UNIQUE KEY uq_role_roomtype (role_id, room_type_id),
    FOREIGN KEY (role_id)      REFERENCES worker_roles(id),
    FOREIGN KEY (room_type_id) REFERENCES room_types(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE workers (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(100) NOT NULL,
    role_id       INT NOT NULL,
    qr_token_hash VARCHAR(64) NOT NULL,
    qr_jti        VARCHAR(36) NOT NULL UNIQUE,
    active        BOOLEAN NOT NULL DEFAULT TRUE,
    notes         TEXT NULL,
    created_at    DATETIME(3) NOT NULL DEFAULT (UTC_TIMESTAMP(3)),
    updated_at    DATETIME(3) NOT NULL DEFAULT (UTC_TIMESTAMP(3)),
    FOREIGN KEY (role_id) REFERENCES worker_roles(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE worker_sessions (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    worker_id      INT NOT NULL,
    room_id        INT NOT NULL,
    entered_at     DATETIME(3) NOT NULL,
    exited_at      DATETIME(3) NULL,
    exit_kind      VARCHAR(16) NULL COMMENT 'QR_SCAN|EXIT_RULE|DOOR_EVENT|AUTO',
    correlation_id VARCHAR(64) NULL,
    created_at     DATETIME(3) NOT NULL DEFAULT (UTC_TIMESTAMP(3)),
    updated_at     DATETIME(3) NOT NULL DEFAULT (UTC_TIMESTAMP(3)),
    FOREIGN KEY (worker_id) REFERENCES workers(id),
    FOREIGN KEY (room_id)   REFERENCES rooms(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### Campo añadido a tabla existente

```sql
ALTER TABLE access_events ADD COLUMN worker_session_id INT NULL AFTER stay_id;
ALTER TABLE access_events ADD FOREIGN KEY (worker_session_id) REFERENCES worker_sessions(id);
```

- **worker_session_id**: opcional, para trazar eventos de acceso a sesiones de worker. Los eventos de guest usan `stay_id` (ya existente), los de worker usan `worker_session_id`.

### Ocupación en tiempo real (sin tabla extra)

Consulta UNION sobre `stays` activos + `worker_sessions` abiertas:

```sql
SELECT 'guest' AS occupant_type, s.id AS occupant_id FROM stays s
  WHERE s.room_id = ? AND s.status IN ('OCCUPIED','OVERSTAY')
UNION ALL
SELECT 'worker' AS occupant_type, ws.id AS occupant_id FROM worker_sessions ws
  WHERE ws.room_id = ? AND ws.exited_at IS NULL
```

## 2. Formato del token QR de trabajador

### Payload JWT

```json
{
  "sub": "worker",
  "wid": 5,
  "jti": "550e8400-e29b-41d4-a716-446655440000",
  "iat": 1690900000,
  "exp": 1893456000
}
```

### QrTokenizer (extensión)

El `QrTokenizer` existente debe soportar un discriminador `sub`:
- `sub: "guest"` → comportamiento actual (validación contra `qr_credentials`)
- `sub: "worker"` → validación contra `workers.qr_jti` + `workers.qr_token_hash`

La firma HMAC usa el mismo `QR_SIGNING_SECRET` que los guest QR.

### Generación del token (POST /workers o POST /workers/{id}/qr)

```php
$jti = uuid_v4();
$payload = [
    'sub' => 'worker',
    'wid' => $worker->id,
    'jti' => $jti,
    'iat' => time(),
    'exp' => time() + (10 * 365 * 86400), // ~10 años
];
$token = QrTokenizer::sign($payload);
// Guardar hash en workers.qr_token_hash y workers.qr_jti
```

### Validación (POST /workers/qr/validate)

```php
$claims = QrTokenizer::verify($token);  // verifica firma + expiración
if ($claims['sub'] !== 'worker') { /* rechazar */ }
$worker = $this->workerRepo->findByJti($claims['jti']);
if (!$worker || !$worker->active) { /* qr_revoked */ }
// Verificar acceso del rol al room_type
if (!$this->workerService->canAccessRoomType($worker->role, $roomTypeId)) {
    /* access_denied */
}
// Sin restricciones de stay ni cooldown → abrir puerta
```

## 3. Flujo de validación QR (guest vs worker)

El router de `POST /qr/validate` discrimina por `sub`:

```
POST /api/v1/qr/validate (guest)
POST /api/v1/workers/qr/validate (worker)
```

Ambos endpoints comparten infraestructura (QrTokenizer, LockGateway, AccessEvent) pero divergen en:
- Guest: validación contra `qr_credentials` + stay state machine + time slots + cooldown
- Worker: validación contra `workers` + `worker_role_room_types` + sin restricciones

## 4. Modificación de la regla de salida

### Escenario A — Salida total (exit rule dispara)

Condiciones: door CLOSED + absence sostenida >= `exit_presence_gap_seconds`.

**Nuevo flujo** (en `IotSessionService::processEvent()`, bloque de exit rule):

```
1. Cargar worker_sessions activas (exited_at IS NULL) para este room
2. Para cada worker_session activa:
   a) worker_session.exited_at = now
   b) worker_session.exit_kind = 'EXIT_RULE'
   c) access_event: KIND=WORKER_EXIT, worker_session_id=ws.id
3. Si hay stay activo OCCUPIED → ejecutar ExitActionService (comportamiento actual)
4. Si NO hay stay activo pero sí workers → solo ejecutar side-effects de lock/luz
```

### Escenario B — Solo el worker sale (door close + presence still present)

**Ubicación**: `IotSessionService::processEvent()`, dentro del case `PROXIMITY CLOSED`, justo después de persistir door_state.

```php
// Dentro de processEvent(), case PROXIMITY CLOSED:
if ($value === PresenceEvent::VALUE_CLOSED) {
    // ... actualizar door_state (existente) ...

    // NUEVO: Worker exit por door event
    if ($session->presenceState === IotSession::PRESENCE_PRESENT) {
        $activeWorkerSessions = $this->workerSessionRepo->findActiveForRoom($roomId);
        if (!empty($activeWorkerSessions)) {
            // Si presencia sigue detectada, guest se queda → worker salió
            // Cerrar solo la sesión más reciente (el que acaba de salir)
            $latest = $activeWorkerSessions[count($activeWorkerSessions)-1];
            $this->workerSessionRepo->close($latest->id, 'DOOR_EVENT');
            // access_event WORKER_EXIT
            $this->accessEvents->insert(
                $roomId, null, AccessEvent::KIND_WORKER_EXIT,
                AccessEvent::RESULT_OK, null, $provider,
                $correlationId, ['worker_session_id' => $latest->id]
            );
        }
    }
}
```

## 5. Endpoints API (rutas nuevas en index.php)

| Método | Ruta | Controlador |
|--------|------|-------------|
| GET | `/api/v1/workers` | `WorkerController::list` |
| POST | `/api/v1/workers` | `WorkerController::create` |
| GET | `/api/v1/workers/{id}` | `WorkerController::show` |
| PATCH | `/api/v1/workers/{id}` | `WorkerController::update` |
| DELETE | `/api/v1/workers/{id}` | `WorkerController::deactivate` |
| POST | `/api/v1/workers/{id}/qr` | `WorkerController::regenerateQr` |
| GET | `/api/v1/workers/{id}/sessions` | `WorkerController::sessions` |
| POST | `/api/v1/workers/qr/validate` | `WorkerQrController::validate` |
| GET | `/api/v1/worker-roles` | `WorkerRoleController::list` |
| POST | `/api/v1/worker-roles` | `WorkerRoleController::create` |
| GET | `/api/v1/worker-roles/{id}` | `WorkerRoleController::show` |
| PATCH | `/api/v1/worker-roles/{id}` | `WorkerRoleController::update` |
| DELETE | `/api/v1/worker-roles/{id}` | `WorkerRoleController::delete` |
| GET | `/api/v1/rooms/{id}/occupants` | `RoomLiveController::occupants` |
| GET | `/api/v1/workers/inside` | `WorkerController::inside` |

## 6. Panel CRM (panel/index.html)

### Vistas nuevas

1. **Workers (listado)**: Tabla con nombre, rol, activo/inactivo, último acceso, ubicación actual, acciones.
2. **Worker (crear/editar)**: Formulario con nombre, rol (dropdown), notas. Al crear, mostrar QR token en modal (una sola vez). Botón "Regenerar QR" con confirmación.
3. **Worker (detalle/panel)**: Historial de sesiones (habitación, entrada/salida, duración), habitaciones visitadas hoy, ubicación actual, último acceso, tiempo medio por tipo de habitación.
4. **Worker Roles**: Listado + formulario CRUD con checkboxes de room_types asignados.
5. **Room detail (extensión)**: Nueva pestaña/sección "Servicio" con trabajadores que han entrado hoy, tiempo acumulado, último servicio.
6. **Dashboard (extensión)**: Sección "Workers activos" mostrando quién está dentro de qué habitación ahora.

### Alertas

7. **Worker Alertas**: Panel o badge visible con alertas activas:
   - ⏱ Worker X lleva > 120 min en Hab Y
   - 🌙 Worker X entró a Hab Y a las 03:15

## 7. Archivos nuevos y modificados

### Nuevos

| Archivo | Descripción |
|---------|-------------|
| `api/migrations/0044_workers.sql` | Tablas workers, worker_roles, worker_role_room_types, worker_sessions; ALTER access_events |
| `api/src/Domain/Workers/Worker.php` | Entidad Worker |
| `api/src/Domain/Workers/WorkerRepositoryInterface.php` | Interfaz repositorio |
| `api/src/Domain/Workers/WorkerRepository.php` | Implementación PDO |
| `api/src/Domain/Workers/WorkerRole.php` | Entidad rol |
| `api/src/Domain/Workers/WorkerRoleRepositoryInterface.php` | Interfaz |
| `api/src/Domain/Workers/WorkerRoleRepository.php` | Implementación |
| `api/src/Domain/Workers/WorkerSession.php` | Entidad sesión |
| `api/src/Domain/Workers/WorkerSessionRepositoryInterface.php` | Interfaz |
| `api/src/Domain/Workers/WorkerSessionRepository.php` | Implementación |
| `api/src/Domain/Workers/WorkerService.php` | Lógica de negocio (CRUD, QR gen, validación acceso, métricas) |
| `api/src/Domain/Workers/WorkerQrService.php` | Validación QR worker + open door |
| `api/src/Http/Controllers/WorkerController.php` | Endpoints CRUD |
| `api/src/Http/Controllers/WorkerQrController.php` | Endpoint validate |
| `api/src/Http/Controllers/WorkerRoleController.php` | Endpoints CRUD roles |
| `api/tests/Unit/WorkerTest.php` | Tests unitarios |
| `api/tests/Unit/WorkerSessionTest.php` | Tests unitarios sesiones |

### Modificados

| Archivo | Cambio |
|---------|--------|
| `api/public/index.php` | Nuevas rutas (15 endpoints) |
| `api/src/Domain/Locks/AccessEvent.php` | Nueva constante `KIND_WORKER_EXIT` |
| `api/src/Domain/Presence/IotSessionService.php` | Escenario A (exit rule cierra worker sessions) + Escenario B (door close cierra sesión worker) |
| `api/src/Domain/Presence/ExitActionService.php` | Nuevo parámetro opcional WorkerSessionRepository para cerrar sesiones en salida total |
| `api/src/Domain/Qr/QrTokenizer.php` | Soporte para discriminador `sub` (guest vs worker) |
| `api/public/panel/index.html` | Vistas de workers, roles, métricas, alertas |
| `api/bin/run-tests.sh` | Nuevo BLOCK 27 |

## 8. Lo que NO cambia

- El modelo de Stay, Room, IoT Session se mantienen intactos
- Los endpoints existentes de QR guest no se modifican
- El comportamiento del huésped no cambia
- Los workers no tienen impacto en time slots ni facturación

---

# Fase 39: Identificación de fábrica integrada en ESP32 productivo

## 1. Alcance y principio de integración

F39 modifica exclusivamente el sketch productivo
`docs/esp32-qr-reader/scanner-relay-prod.ino`. Se elimina el concepto de
firmware, modo de ejecución o sketch aislado de fábrica. La identificación es
una responsabilidad auxiliar dentro del `loop()` existente y coexiste con QR,
USB Host, relé, GPIO4/identify, watchdog, heartbeat y command queue.

La integración no bifurca el arranque ni condiciona la operación de cerradura:
el flujo WiFi existente (NVS + WiFiManager) permanece intacto y, cuando informa
conectividad, habilita el scheduler de anuncio F39.

## 2. Identidad estable

`chip_id` se calcula en cada arranque desde `ESP.getEfuseMac()` como 12 dígitos
hexadecimales lowercase sin separadores. Es la clave natural de
`factory_devices` y coincide con `devices.external_id` del RPI que crea el
claim. No se almacena como identidad mutable ni se deduce de WiFi.

## 3. Máquina de estados no bloqueante en el sketch

Se añaden estado efímero de RAM y timestamps, por ejemplo:

```text
factoryAnnouncementEnabled = true     // se reinicia a true en cada boot
factoryAnnouncementInFlight = false
factoryNextAttemptAt = 0
```

Flujo:

```text
setup():
  ejecutar setup productivo existente, incluido WiFi/NVS/WiFiManager
  calcular chip_id e inicializar scheduler F39 (sin consultar NVS de claim)

loop():
  ejecutar el loop productivo existente en su orden actual
  si WiFi conectado y factoryAnnouncementEnabled y now >= factoryNextAttemptAt:
      iniciar/realizar un intento acotado de announce
      programar próximo intento, incluso ante error transportable
      si respuesta válida status=PENDING: mantener enabled=true
      si respuesta válida status=CLAIMED: factoryAnnouncementEnabled=false
  yield/watchdog y resto de tareas continúan normalmente
```

El intento debe tener timeout acotado y no usar `delay()` ni bucles de espera.
Si la librería HTTP disponible no permite una operación incremental real, el
diseño de implementación debe usar el mínimo timeout soportado y preservar el
presupuesto de loop; no se aceptan reintentos internos prolongados. Errores de
red o JSON inválido solo cambian `factoryNextAttemptAt` y se registran en Serial.

`CLAIMED` solo apaga `factoryAnnouncementEnabled` en RAM. No se escribe una
bandera `factory_claimed` en NVS: cada boot, reflasheo o NVS wipe vuelve a
consultar al backend mediante `announce`, que es el árbitro del estado.

## 4. Modelo persistente y backend autoritativo

```text
factory_devices
  id, chip_id UNIQUE NOT NULL, status PENDING|CLAIMED,
  first_announced_at, last_announced_at,
  claimed_at NULL, claimed_by NULL, device_id NULL,
  created_at, updated_at
```

`announce(chip_id)` realiza insert-or-update atómico: inserta `PENDING` en la
primera aceptación; después actualiza solo `last_announced_at`. Cuando el
registro ya es `CLAIMED`, conserva status, auditoría y vínculo RPI. Ningún dato
local del ESP32 puede rebajar o reemplazar ese estado.

## 5. Claim manual y panel

El panel lista `PENDING`, muestra `chip_id` y fechas, y expone una acción
manual de claim. En una única transacción el backend: verifica el registro,
cambia `PENDING → CLAIMED`, fija actor/fecha, crea o vincula exactamente un
`devices` de tipo `RPI` con `external_id=chip_id`, `pack_id=null` y
`room_id=null`, y audita la acción. No hay asociación automática a pack o
habitación; esa configuración pertenece a fases operativas posteriores.

## 6. Concurrencia, errores y no regresión

- La unicidad de `chip_id` y el upsert protegen anuncios concurrentes y reintentos.
- Claim y anuncio concurrentes preservan `CLAIMED`; un claim repetido es idempotente.
- El scheduler se ejecuta después de que el código existente haya tenido oportunidad
  de atender USB/QR y sus tareas periódicas, sin retornar prematuramente del loop.
- F39 no modifica los contratos ni la semántica de QR, relé, GPIO4, watchdog,
  heartbeats, command queue, sensores, rooms o stays.

## 7. Seguridad y trazabilidad

El endpoint de anuncio usa la autenticación de dispositivo existente y claim
requiere autorización administrativa. No se añade una credencial de fábrica
distinta al sketch. El log de auditoría incluye `chip_id`, estado previo/nuevo,
actor y timestamp.

## 8. Archivos previstos

| Archivo | Cambio |
|---------|--------|
| `docs/esp32-qr-reader/scanner-relay-prod.ino` | `chip_id` eFuse + scheduler F39 no bloqueante integrado |
| `api/migrations/0046_factory_devices.sql` | Registro `factory_devices` y vínculo RPI |
| `api/src/Domain/FactoryDevices/*` | Entidad, servicio y repositorio idempotentes |
| `api/src/Http/Controllers/FactoryDeviceController.php` | Anuncio, listado y claim |
| `api/public/index.php` | Rutas y autorización |
| `api/public/panel/index.html` | Cola `PENDING` y claim explícito |
| `api/tests/Unit/FactoryDeviceTest.php` | Dominio, contrato firmware y regresión |
| `api/bin/run-tests.sh` | BLOCK 30 de F39 |

---

# Fase 40: Credenciales individuales ESP32

El firmware genera una clave aleatoria con el generador del ESP32 una única
vez y la almacena en `Preferences` bajo `device-credential`, separado de
`cerraduras`. El fingerprint de firmware puede limpiar WiFi sin tocarla.

El flujo es: `announce` recibe por HTTPS `{chip_id,factory_key}`, almacena solo
su SHA-256 y devuelve estado; el panel hace `claim` administrativo directo por
id con `{label?,pack_id?}`. El claim usa el hash registrado, crea o reutiliza el
único RPI, crea o reutiliza su `api_client` individual y vincula ambos lados
(`api_clients.device_id` y `devices.api_client_id`) dentro de una transacción.
La clave plana nunca se persiste, se solicita al operador o se devuelve.

`api_clients.device_id` es único y nullable para legacy. Las rutas operativas
que conocen el dispositivo comparan el cliente autenticado con el binding y
devuelven `device_mismatch`; clientes legacy sin binding conservan su
comportamiento. El claim idempotente conserva auditoría y no muestra la clave.
El único sketch modificado es `scanner-relay-prod.ino`; usa la clave individual
para anuncio y operación, conserva todas las funciones productivas y exige
HTTPS para el anuncio. La credencial no se expone por Serial ni WiFiManager y
`chip_id` sigue siendo visible. Si el servidor actual no publica TLS, el
despliegue queda bloqueado.
