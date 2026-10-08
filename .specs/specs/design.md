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
  // Arduino-ESP32 core 3.x (IDF 5.x): esp_task_wdt_init() toma un config-struct.
  // Core 2.x legado (IDF 4.x) usaba esp_task_wdt_init(60, true).
#if ESP_ARDUINO_VERSION_MAJOR >= 3
  esp_task_wdt_config_t wdt_cfg = {
      .timeout_ms     = 60000,   // 60s
      .idle_core_mask = 0,       // no vigilar idle tasks
      .trigger_panic  = true,    // panic/reboot al expirar
  };
  esp_task_wdt_init(&wdt_cfg);
#else
  esp_task_wdt_init(60, true);   // core 2.x legado
#endif
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
#if ESP_ARDUINO_VERSION_MAJOR >= 3
  esp_task_wdt_config_t wdt_cfg = {
      .timeout_ms     = 60000,
      .idle_core_mask = 0,
      .trigger_panic  = true,
  };
  esp_task_wdt_init(&wdt_cfg);   // core 3.x (IDF 5.x): config-struct
#else
  esp_task_wdt_init(60, true);   // core 2.x legado (IDF 4.x)
#endif
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

## Correcciones de identidad, provisioning y consumo

- `chip_id` solo usa los seis bytes bajos de `ESP.getEfuseMac()`, serializados con `%02x` en 12 caracteres lowercase. SSID, MAC WiFi, IP y credencial no son identidad.
- `clearNvsIfNewFirmware()` conserva `device-cred` intacta y, en `cerraduras`, elimina solo `ssid`, `pass`, `last_ssid` y `build_marker`. El marcador combina `ESP.getSketchMD5()` con `__DATE__` y `__TIME__`.
- El claim bloquea y resuelve un único RPI por `(kind, external_id)` y un único cliente por `device_id`; crea, vincula, audita y elimina la fila factory en una transacción. Un error revierte también cualquier cliente nuevo.
- Tras consumir la fila, `announce()` valida la credencial contra el cliente del RPI y devuelve un DTO efímero `CLAIMED`; un claim repetido se reconstruye desde auditoría sin crear recursos.

### Requisito de build/upload

Hay que recompilar y cargar `scanner-relay-prod.ino` con el flujo Arduino/ESP32-S3-USB-OTG documentado en el sketch. No usar una carga sin recompilar ni editar el marcador manualmente.

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

## Fase 40.4: Consumo del registro y anuncio lógico

El claim se ejecuta en una única transacción: bloquear el pendiente, crear o
reutilizar RPI y cliente API, escribir el audit log y eliminar finalmente la fila
de `factory_devices`. El audit log es append-only y el borrado no toca el RPI ni
el cliente. En un anuncio posterior, si falta la fila, se resuelve el RPI por
`external_id` y se valida la credencial contra su cliente vinculado. Si coincide
se devuelve una entidad efímera `CLAIMED` con `device_id` y datos auditados; si no
coincide se devuelve `invalid_factory_credential` sin upsert ni modificación.
El único sketch modificado es `scanner-relay-prod.ino`; usa la clave individual
para anuncio y operación, conserva todas las funciones productivas y exige
HTTPS para el anuncio. La credencial no se expone por Serial ni WiFiManager y
`chip_id` sigue siendo visible. Si el servidor actual no publica TLS, el
despliegue queda bloqueado.

**Credencial desincronizada (recovery).** Si la credencial de la placa cambia
(reflasheo con la NVS borrada), el anuncio recibe `403
invalid_factory_credential` de forma permanente y el QR `validate`/`identify` de
esa placa falla con 401. El firmware detiene el anuncio ante un 4xx terminal
(salvo 429) en lugar de reintentar cada 30 s. La recuperación es manual:
liberar el binding obsoleto (`DELETE` del `api_client` del chip y de su fila
`factory_devices`), borrar la NVS de la placa, reprovisionar WiFi y volver a
reclamar desde el panel. Procedimiento detallado en `docs/ops.md`.

## Diseño — Calibración de sensor de presencia (RF-41)

**Descubrimiento por habitación.** Se resuelve el dispositivo `PRESENCE` de la room
siguiendo la cadena canónica room→pack→device (misma consulta que
`/dashboard-api/device-status`). Cada sensor vive en una habitación distinta, por lo
que su configuración es única por habitación.

**Endpoints LAN** (`/dashboard-api/presence-calibrate/{status,set}`, sin auth como el
resto del dashboard): generalizan la herramienta dev `/simula/*` (que queda intacta,
hardcodeada a un único dispositivo) reutilizando `tuyaPresenceApi()` y su backoff de cuota.

**Badge en vivo.** Se lee el DP crudo del sensor (polling ~2 s solo con el modal abierto)
porque `presence_state` de dominio solo transita vía webhook/poller y puede tardar varios
segundos. No se inyecta nada en `iot_session` para no ensuciar anomalías.

**Persistencia por habitación.** Tras un `set` confirmado se fusiona en
`devices.meta_json.calibration` `{far_detection_cm, sensitivity, calibrated_at, source}`.
El valor de verdad sigue siendo el DP del sensor (el dispositivo lo recuerda); el `meta`
es respaldo/consulta para reabrir el modal o tras sustituir el sensor.

**Panel.** Entrada: zona clicable sobre el sensor del croquis SVG (con icono ⚙️ en hover).
Modal `#cal-modal` siguiendo el patrón `.modal-overlay/.modal-box`: slider de radio
(0–800 cm, paso 5 cm) y slider de sensibilidad (0–9), insignia `DETECTANDO PRESENCIA`
(verde) / `SIN PRESENCIA` (rojo) / `RADIO APAGADO` (naranja, radio ≤ 1) / `SIN DATOS`
(gris, offline/sin lectura), estados "Enviando/Guardando", banner de error (Tuya/offline/
quota con espera ~15 s), read-back del valor confirmado y hint al técnico. Cooldown de
~2 s entre llamadas Tuya y timers limpiados al cerrar.

## Diseño — Estado verídico de dispositivos (F39 / RF-42)

**Origen de verificación.** Se clasifica cada dispositivo por cómo se confirma su conexión:
- `DEVICE_KIND_ESP32 = [RPI, SCANNER, LOCK]` → heartbeat/command-queue, sin cuota.
- `DEVICE_KIND_TUYA  = [PRESENCE, PROXIMITY, SWITCH]` → sonda `GET /devices/{id}` (`result.online`), con cuota.

**Modelo (migración 0103).** `devices.online_state TINYINT(1) NULL` (1/0/NULL) +
`devices.online_probed_at DATETIME(3) NULL`, que registran la última **sonda real** Tuya.
`last_seen_at` queda como actividad reactiva y deja de ser prueba de conexión para Tuya cloud.

**device-status.** Para ESP32 se deriva online de `last_seen_at` fresco; para Tuya cloud,
si `online_probed_at` está dentro de `TUYA_PROBE_TTL_SECONDS` (600 s) se muestra el
`online_state` (verde/rojo); si la sonda es vieja o no existe → `state=unknown`, `online=null`
(ámbar). Respuesta incluye `state`, `tuya_cloud`, `online_state`, `online_probed_at` y conteos
`online/offline/unknown`.

**ping-all-devices.** Sonda real solo si `verify_tuya:true` (carga/manual); sin flag devuelve
Tuya como `unknown` sin llamar a Tuya (no quema cuota). `SCANNER`/`LOCK` → encola `check`
(pull). `RPI` → heartbeat.

**SwitchService.** Ya no refresca `last_seen_at` tras un comando cloud "aceptado" (Tuya lo
encola aunque el físico esté apagado → era la causa del falso online de "Luz").

**Frontend /dashboard.** NO hay auto-recheck de dispositivos (se eliminó el poll de 10 s
que lanzaba `check` a cerradura/relé y lector QR y los hacía actuar en bucle). La
verificación se hace SOLO al cargar la página o pulsar "Comprobar dispositivos". El
heartbeat del chip ESP32 mantiene el estado online; el lector de `device-status` (GET,
solo lectura) refresca el panel sin actuar hardware.

---

# Fase 41 — Diseño: Robustez del pipeline de sensores y coreografía del panel

> **Trazabilidad**: RF-43 … RF-49 (`requirements.md` §Fase 41). Este diseño no incluye
> código de negocio; define módulos, algoritmos, esquema de datos y contratos a formalizar
> en `contracts.md`. Los nombres concretos de columnas/clases propuestos aquí son
> decisiones de diseño y deben quedar reflejados en `contracts.md` antes de implementar.

## 0. Resumen y trazabilidad

| RF | Problema (causa raíz) | Sección | Componente principal |
|----|------------------------|---------|----------------------|
| RF-43 | RC-1: lost update no atómico en `iot_sessions` | §1 | `IotSessionService` + `IotSessionRepository` (lock de fila) |
| RF-44 | RC-2: sin orden temporal ni idempotencia real | §2 | `PresenceEventRepository` + columnas nuevas (`iot_sessions`, `presence_events`) |
| RF-47 | RC-6: la regla de salida exige `door=CLOSED` y no limpia el reset | §3 | `ExitRuleEvaluator` + `ExitActionService` |
| RF-45 | RC-5: `isCaptureWindow()` depende de `door_state` y de una bandera pegajosa | §4 | `bin/tuya-presence-poller.js` |
| RF-46 / RF-47 | RC-3: coreografía confundida por ventana QR y `_presenceDetectedDuringOpen` | §5 | `dashboard.html` (`deriveChoreography()`) |
| RF-49 | Pérdida de stream / conteo no fiable | §6 | `dashboard.html` + `EventStreamController` |
| RF-48 | RC-4: workers huérfanos y ausentes | §7 | `start-all.sh`/`stop-all.sh` + `/dashboard-api/system-status` |
| Contratos | Campos nuevos que deben exponerse | §8 | `/live`, SSE, `/dashboard-api/system-status` |

**Principio rector**: el estado IoT de una habitación es la fuente de verdad y tiene un
**único escritor autoritativo**. Todo lo demás (frontend, poller, exit-scan) **deriva** de
ese estado, nunca lo reescribe.

---

## 1. Atomicidad del estado IoT por habitación (RF-43)

### 1.1 Diagnóstico del lost update

`IotSessionService::processEvent()` ejecuta hoy:

```
rooms->findById()                  (I/O)
presenceEvents->insert()           (I/O)
iotSessions->findByRoomId()        (I/O — LECTURA sin lock)
... mutación en memoria ...
iotSessions->upsert()              (I/O — reescribe TODAS las columnas)
```

Con `PHP_CLI_SERVER_WORKERS=8` y varios productores de la misma habitación (webhook de
puerta + webhook de presencia + `exit-scan` con 9 instancias), dos hilos pueden leer el
mismo snapshot y el último `upsert()` gana, pisando la transición del otro. Evidencia real:
`last_close_at`/`door_state='CLOSED'` quedó sobrescrito por `door_state='OPEN'` al aplicar una
escritura de presencia que arrastraba un snapshot viejo.

`INSERT … ON DUPLICATE KEY UPDATE` **no lo arregla por sí solo**: aunque el motor resuelva la
inserción/actualización de forma atómica a nivel de fila, la sentencia escribe los valores de
un *snapshot leído antes* (`:door`, `:pres`, `:open`, `:close`, …). Sigue siendo
read-modify-write a nivel de aplicación: no existe la decisión "¿este evento es más nuevo
que el estado actual?" dentro de la operación atómica.

### 1.2 Punto único de escritura

Se introduce un **escritor único** (`IotSessionService::applyEvent()`, evolución de
`processEvent()`) que envuelve todo el ciclo leer-modificar-escribir en una transacción con
**lectura bloqueante de la fila** (`SELECT … FOR UPDATE`). Requisitos:

- Solo `IotSessionService` puede escribir `door_state`, `presence_state`, `last_open_at`,
  `last_close_at`, `last_absent_since` y las nuevas marcas de orden.
- `ExitRuleEvaluator` / `ExitActionService` **no** escriben estado de sensores: como mucho
  actualizan `exit_evaluated_at` con un update condicional (`markExitEvaluated()`), dentro de
  su propia transacción y re-validando la precondición.
- `QrTestController::roomsReset` usa una sentencia de reset explícita y documentada (no es una
  escritura de sensor).
- Webhooks de puerta y presencia, y cualquier futuro productor, entran por `applyEvent()`.

Interfaz propuesta:

```php
interface IotSessionRepositoryInterface
{
    public function findByRoomId(int $roomId): ?IotSession;

    /** SELECT ... FOR UPDATE dentro de una transacción ya abierta.
     *  Crea la fila si no existe (INSERT no-op) y la devuelve bloqueada. */
    public function lockByRoomId(int $roomId): IotSession;

    /** UPDATE por id de las columnas de estado (no reescribe el resto). */
    public function updateState(IotSession $session): void;

    /** Update condicional de exit_evaluated_at; devuelve false si otro ya lo marcó. */
    public function markExitEvaluated(int $roomId, string $nowUtc): bool;
}
```

`lockByRoomId()` materializa la fila con un no-op antes de bloquear (evita el caso "fila
ausente" y garantiza lock real incluso en `READ COMMITTED`):

```sql
INSERT INTO iot_sessions (room_id, door_state, presence_state, updated_at)
VALUES (:rid, 'UNKNOWN', 'UNKNOWN', UTC_TIMESTAMP(3))
ON DUPLICATE KEY UPDATE id = id;   -- no-op: crea la fila si falta y toma el lock

SELECT id, room_id, stay_id, door_state, presence_state,
       last_open_at, last_close_at, last_absent_since, exit_evaluated_at,
       last_door_event_at, last_presence_event_at,
       last_door_value, last_presence_value
FROM iot_sessions
WHERE room_id = :rid
FOR UPDATE;
```

### 1.3 Pseudocódigo del algoritmo de aplicación

```
función applyEvent(event, correlationId):
    # (A) Auditoría bruta fuera de la transacción de estado: idempotente por fingerprint.
    #     Registra SIEMPRE el evento, aunque luego no se aplique (RF-44.3).
    raw = presenceEvents.insertOrGet(event)        # 1062 en source_event_id/fingerprint → existente

    decision = null
    para intento en 1..3:
        intenta:
            pdo.beginTransaction()
            session = iotSessions.lockByRoomId(roomId)      # SELECT ... FOR UPDATE

            # (B) Decisión de aplicación: duplicado / atrasado / noop / aplicar (RF-44)
            decision = decide(event, session)

            si decision != APLICAR:
                presenceEvents.markAudit(raw.id, applied=false, reason=decision)
                pdo.commit()
                returns {accepted:true, derived_state: session.toArray()}

            # (C) Mutación pura sobre el snapshot bloqueado
            mutate(session, event)                           # actualiza estado + marcas de orden
            iotSessions.updateState(session)                 # UPDATE por id
            presenceEvents.markAudit(raw.id, applied=true, reason=null)
            pdo.commit()
            rompe
        captura Deadlock(1213/40001) o LockWaitTimeout(1205):
            pdo.rollback()
            si intento == 3: relanza
            backoff(20 * intento ms + jitter)

    # (D) Efectos externos SIEMPRE post-commit y fuera del lock (no I/O pesado bajo lock)
    si se aplicó PROXIMITY=OPEN: switch.turnOn(roomId) best-effort
    anomalyService.detectAndPersist(sessionFresh, event) best-effort (A1–A8, informativas)
    exitActionService.executeIfPending(roomId, correlationId)   # §3.3 (re-lockea)

    returns {accepted:true, derived_state: refreshedSession.toArray()}
```

Puntos clave:

- **El lock solo cubre la decisión + el `UPDATE`**. Ninguna llamada a Tuya, gateway de
  cerradura o cálculo de anomalías ocurre mientras la fila está bloqueada. Objetivo: lock
  < 20 ms.
- El `exit-scan` jamás llama a `updateState()`; delega en `executeIfPending()`.
- Un fallo tras el commit (p. ej. la luz) no revierte el estado de sensores; se registra.

### 1.4 Deadlocks y reintentos

- Orden de adquisición único: **primero `iot_sessions`, después `stays`** (o ninguna otra
  tabla). `executeIfPending()` respeta el mismo orden para evitar ciclos.
- Reintento acotado (3) con backoff + jitter ante `1213` (deadlock) y `1205` (lock wait
  timeout). Si se agota, se responde error reintentable y el evento ya quedó auditado; el
  `outbox`/reintento del emisor no pierde el hecho.
- `innodb_lock_wait_timeout` se mantiene bajo (p. ej. 5 s) para que un lock atascado no
  bloquee workers de otras habitaciones; el lock es **por habitación**, no global, así que
  habitaciones distintas no compiten.

### 1.5 Por qué no basta el upsert a ciegas

| Alternativa | Motivo de descarte |
|---|---|
| `INSERT … ON DUPLICATE KEY UPDATE` con `VALUES()` | Escribe el snapshot completo; una escritura vieja revierte columnas nuevas. No expresa "aplicar solo si es más reciente". |
| `UPDATE` condicional por columna (`GREATEST`, `IF`) | Puede arreglar una columna, pero el estado derivado es multivariable (door + presence + timestamps + regla de ciclo). No equivale a "aplicar el evento X sobre el estado más reciente". |
| Cola de eventos + worker único | Correcto pero introduce latencia y un bus nuevo; el lock de fila por habitación es suficiente y de menor alcance. |
| `SELECT` sin lock + reintento optimista (versión) | Requiere columna de versión y reintentos en todos los call sites; el `FOR UPDATE` es más simple y el contention es por habitación. |

---

## 2. Orden temporal e idempotencia de eventos (RF-44)

### 2.1 Identidad lógica de evento (fingerprint)

`source_event_id` actual (`tuya-<devId>-<t>`) es un id de **transporte**: si Pulsar reenvía el
mismo hecho con otro `t` (o el poller lo reenvía con su propio `now`), se procesa como nuevo.
Se añade una identidad **lógica**:

```
fingerprint = sha1( room_id | sensor | value | floor(occurred_at a segundo) )
```

- Incluye `value`, así `OPEN` y `CLOSED` en el mismo segundo se conservan ambos.
- Trunca a segundo porque dos eventos idénticos en el mismo segundo son el mismo hecho a
  efectos de dominio (idempotencia de reenvíos). **F58/RF-65**: el orden entre eventos del mismo
  segundo NO usa el fingerprint: se decide por `occurred_at` con milisegundos.
- Se persiste con índice `UNIQUE` (`uq_presence_fingerprint`); `1062` ⇒ duplicado.

**Trade-off** frente a una ventana anti-rebote temporal (p. ej. descartar mismo valor < 1 s):
la ventana descarta también transiciones legítimas rápidas (rebote real de puerta) y depende
de reloj; el fingerprint es determinista y solo colapsa eventos **idénticos** en el mismo
segundo, que por definición son un no-op de estado. La ventana se usa solo como criterio
secundario de `noop` (mismo valor que el ya aplicado), nunca para borrar hechos.

### 2.2 Columnas de orden en `iot_sessions`

| Columna | Tipo | Semántica |
|---|---|---|
| `last_door_event_at` | `DATETIME(3) NULL` | `occurred_at` del último evento de puerta **aplicado**. |
| `last_presence_event_at` | `DATETIME(3) NULL` | `occurred_at` del último evento de presencia **aplicado**. |
| `last_door_value` | `VARCHAR(8) NULL` | Último valor de puerta aplicado (`OPEN`/`CLOSED`). |
| `last_presence_value` | `VARCHAR(8) NULL` | Último valor de presencia aplicado (`PRESENT`/`ABSENT`). |

Con esto, un evento con `occurred_at` anterior al último aplicado de su sensor se descarta
(`atrasado`) sin corromper el estado, y no hace falta leer `presence_events` para decidir.

### 2.3 Auditoría de aplicación en `presence_events`

| Columna | Tipo | Semántica |
|---|---|---|
| `event_fingerprint` | `CHAR(40) NULL` | Identidad lógica; `UNIQUE`. NULL en filas legacy. |
| `applied` | `TINYINT(1) NULL` | `1` aplicado, `0` descartado, `NULL` pendiente/legacy. |
| `discard_reason` | `VARCHAR(16) NULL` | `duplicate` \| `stale` \| `noop`. |

El evento bruto se inserta **siempre** (RF-44.3.1); `applied`/`discard_reason` se rellenan en
la misma transacción del estado. Un descarte no borra evidencia.

### 2.4 Decisión de aplicación (`decide()`)

```
función decide(event, session):
    si event.sensor == PROXIMITY:
        lastAt = session.last_door_event_at
        lastVal = session.last_door_value
    sino:
        lastAt = session.last_presence_event_at
        lastVal = session.last_presence_value

    # 1) Duplicado lógico exacto: ya existe fingerprint (detectado en insertOrGet) → duplicate
    # 2) Atrasado: el hecho ocurrió antes que el último aplicado de ese sensor
    #    (F58/RF-65: comparación en MILISEGUNDOS, no en segundos)
    si lastAt != null y event.occurred_at_ms < lastAt_ms:
        returns ATRASADO

    # 3) Mismo instante y mismo valor → no aporta transición (Pulsar reenvía)
    si lastAt != null y event.occurred_at == lastAt y event.value == lastVal:
        returns DUPLICADO

    # 4) Valor ya vigente sin cambio → noop (evita reescrituras y ruido)
    si event.value == lastVal:
        returns NOOP

    returns APLICAR
```

`mutate()` al aplicar:

```
si sensor == PROXIMITY:
    session.last_door_event_at = event.occurred_at
    session.last_door_value    = event.value
    si value == OPEN:
        door_state = OPEN;  last_open_at = occurred_at
    sino si value == CLOSED:
        door_state = CLOSED; last_close_at = occurred_at
        si presence_state == ABSENT y last_absent_since == null: last_absent_since = occurred_at
        # Consolidación de entrada (§3.3): presencia vista durante la apertura
        si hay stay activo y (presence_state == PRESENT
             o (last_presence_value == PRESENT y last_presence_event_at >= last_open_at)):
            stays.entry_confirmed_at = occurred_at (si null)
        # F38 Escenario B: cierre de worker_session (se mantiene)
sino si sensor == PRESENCE:
    session.last_presence_event_at = event.occurred_at
    session.last_presence_value    = event.value
    si value == PRESENT:
        presence_state = PRESENT; last_absent_since = null      # cancela conteo (RF-47.3)
    sino si value == ABSENT:
        si presence_state != ABSENT: last_absent_since = occurred_at
        presence_state = ABSENT
```

### 2.5 Ajuste de `TuyaSensorIngress`

- Se conserva `source_event_id` para idempotencia de transporte, pero **deja de ser la única
  defensa**; el fingerprint cubre reenvíos con distinto `t`.
- `occurred_at` sigue derivándose de `t` (segundos). Se guarda el `t` crudo (ms) en
  `meta_json.tuya_t` para auditoría fina.
- El poller ya envía `t = now.getTime()` (ms); su truncado a segundo alimenta el fingerprint.

### 2.6 Migración `0108` (siguiente libre tras `0107`)

Nombre propuesto: `0108_sensor_event_ordering.sql`. Aditiva y nullable (segura en caliente):

```sql
-- 0108_sensor_event_ordering.sql — Orden temporal, idempotencia y consolidación de entrada
-- Trazabilidad: RF-43, RF-44, RF-46, RF-47.

ALTER TABLE iot_sessions
  ADD COLUMN last_door_event_at     DATETIME(3) NULL AFTER last_absent_since,
  ADD COLUMN last_presence_event_at DATETIME(3) NULL AFTER last_door_event_at,
  ADD COLUMN last_door_value        VARCHAR(8)  NULL AFTER last_presence_event_at,
  ADD COLUMN last_presence_value    VARCHAR(8)  NULL AFTER last_door_value;

ALTER TABLE presence_events
  ADD COLUMN event_fingerprint CHAR(40)    NULL AFTER source_event_id,
  ADD COLUMN applied           TINYINT(1)  NULL AFTER event_fingerprint,
  ADD COLUMN discard_reason    VARCHAR(16) NULL AFTER applied,
  ADD UNIQUE KEY uq_presence_fingerprint (event_fingerprint),
  ADD KEY idx_presence_room_occ (room_id, occurred_at);

ALTER TABLE stays
  ADD COLUMN entry_confirmed_at DATETIME(3) NULL AFTER first_entry_at;
```

> `entry_confirmed_at` es la marca autoritativa de "huésped confirmado dentro" (sustituye el
> uso de `first_entry_at`, que se fija al validar el QR **antes** de entrar y por eso confunde
> una apertura tardía con una salida — RC-3).

---

## 3. Regla de salida y verificación (RF-47)

### 3.1 Cambios conceptuales

1. **No depender del estado actual de puerta**: la condición pasa de `door_state == CLOSED` a
   exigir un **ciclo de puerta acreditado** (`last_open_at` y `last_close_at` presentes y
   `last_close_at >= last_open_at`). Así, aunque `door_state` quede inconsistente, la salida
   no se bloquea.
2. **Precondición de presencia**: el huésped debe haber sido confirmado dentro
   (`stays.entry_confirmed_at IS NOT NULL`) **y** debe existir una **nueva apertura posterior**
   a esa confirmación (`last_open_at > entry_confirmed_at`). Esto evita que un falso `ABSENT`
   del radar cierre la estancia sin que nadie haya abierto la puerta desde dentro.
3. **Conteo anclado a `last_absent_since`** (sin cambios): la guarda de salida es
   `room.presence_check_seconds ?? EXIT_ABSENCE_GUARD_SECONDS ?? 3` (Bug 1). `gap_seconds`
   deja de gobernar la salida y queda como campo legacy de compatibilidad.
4. **Cancelación**: si llega `PRESENT`, `last_absent_since` se limpia y `exit_deadline`
   desaparece; la habitación vuelve a `OCCUPIED`.

### 3.4 Desacoplamiento entrada/salida (Bug 1)

Hasta F48, un único valor (`gap_seconds` = override de sala `presence_check_seconds` ??
`room_type.exit_presence_gap_seconds` ?? 15) se usaba a la vez para:

- consolidar la **ENTRADA** cuando el radar confirma presencia tras el cierre
  (`IotSessionService::consolidateEntry(..., requireRecentClose: true)`), y
- gobernar la **guarda de ausencia de la SALIDA** (`ExitRuleEvaluator`).

Con un override de sala de ~15 s, la salida tardaba ~30 s en confirmarse. Se **desacoplan**:

| Uso | Configuración | Default | Resolución |
|---|---|---|---|
| Ventana de ENTRADA | `room_types.presence_entry_window_seconds` (`entry_window_seconds` en `/live`) | 90 s | tipo de habitación → 90 |
| Guarda de SALIDA | `EXIT_ABSENCE_GUARD_SECONDS` (global) + `rooms.presence_check_seconds` (override) | 3 s | `resolveGuardSeconds()`: sala (>0) → global (>0) → 3 |
| `gap_seconds` (legacy) | `rooms.presence_check_seconds` ?? `room_types.exit_presence_gap_seconds` | 15 s | solo contrato/UI retrospectiva; **no** gobierna la regla |

```php
// ExitRuleEvaluator (estático, puro)
public const DEFAULT_GUARD_SECONDS = 3;

public static function resolveGuardSeconds(?int $roomOverride, ?int $globalOverride): int
{
    if ($roomOverride !== null && $roomOverride > 0) return $roomOverride;
    if ($globalOverride !== null && $globalOverride > 0) return $globalOverride;
    return self::DEFAULT_GUARD_SECONDS;
}

// resolveGapSeconds(Room) se conserva por compatibilidad de wiring, pero ahora
// devuelve resolveGuardSeconds($room->presenceCheckSeconds, env).
```

`IotSessionService::consolidateEntry(..., requireRecentClose: true)` usa
`resolveEntryWindowSeconds($room)` (90 s), de modo que bajar la guarda a 3 s **no** reintroduce
la regresión de F42 ("PRESENT fuera de ventana NO consolida"). `exit_deadline` en `/live` y en
el SSE `state` usa `exit_guard_seconds`; `exit_guard_seconds` es aditivo en el payload.

### 3.2 `ExitRuleEvaluator::evaluate()` revisado

```
función evaluate(session, stay, gapSeconds, nowTs):
    si stay == null o stay.entry_confirmed_at == null: returns false
    si session.last_open_at == null o session.last_close_at == null: returns false
    openTs  = epoch(session.last_open_at)
    closeTs = epoch(session.last_close_at)
    entryTs = epoch(stay.entry_confirmed_at)

    # (a) ciclo de puerta completo, posterior a la confirmación de entrada
    si !(openTs > entryTs): returns false
    si !(closeTs >= openTs): returns false
    # (b) el ciclo no es arbitrariamente viejo (cota de seguridad)
    si (nowTs - closeTs) > DOOR_CYCLE_MAX_S: returns false

    # (c) ausencia sostenida
    si session.presence_state != ABSENT: returns false
    si session.last_absent_since == null: returns false
    absentTs = epoch(session.last_absent_since)
    returns (nowTs - absentTs) >= gapSeconds
```

`DOOR_CYCLE_MAX_S` sustituye al antiguo `DOOR_CLOSE_WINDOW_S=60` como cota superior (default
propuesto 300 s, configurable), manteniendo compatibilidad con radares de retención lenta.
Se conserva `shouldExit(roomId, nowTs)` cargando stay activo + sesión.

### 3.3 `ExitActionService::executeIfPending()`

La ejecución de efectos debe ser idempotente y atómica con la precondición, aunque la dispare
el webhook o el `exit-scan`:

```
función executeIfPending(roomId, correlationId):
    para intento en 1..3:
        intenta:
            pdo.beginTransaction()
            session = iotSessions.lockByRoomId(roomId)      # mismo orden de lock
            stay    = stays.lockActiveForRoom(roomId)        # SELECT ... FOR UPDATE
            room    = rooms.findById(roomId)
            si !evaluate(session, stay, gapSeconds, now):     # re-validación bajo lock
                pdo.commit(); returns false                   # otro ya salió / se canceló
            si !iotSessions.markExitEvaluated(roomId, now):   # update condicional
                pdo.commit(); returns false                   # ya marcado por otro worker
            # efectos (lock de puerta, EXITED, cooldown, FREE, luz, worker_sessions)
            exitAction.applyEffects(room, session, stay, roomType, correlationId, now)
            pdo.commit()
            returns true
        captura Deadlock/LockWaitTimeout:
            pdo.rollback(); backoff; continúa
```

- `markExitEvaluated()` con `WHERE room_id=:rid AND (exit_evaluated_at IS NULL OR
  exit_evaluated_at < :close_at)` da idempotencia real entre webhook y N instancias de
  `exit-scan`.
- Los efectos externos (gateway `lock`, `turnOff`) se ejecutan después de validar la
  precondición. Si fallan, la estancia ya transicionó y se registra el fallo en
  `access_events` (best-effort, como hoy).
- El `entry_confirmed_at` no se borra al salir: queda como histórico de la estancia; la nueva
  estancia nace con `NULL`.

### 3.4 Reset de habitación

`POST /dashboard-api/rooms/reset` debe limpiar, además de lo actual (`last_open_at`,
`last_absent_since`, `exit_evaluated_at`), las **nuevas marcas** y el `last_close_at`
(hoy no se limpia — RC-6):

```sql
ON DUPLICATE KEY UPDATE
  door_state='CLOSED', presence_state='ABSENT',
  last_open_at=NULL, last_close_at=NULL, last_absent_since=NULL, exit_evaluated_at=NULL,
  last_door_event_at=NULL, last_presence_event_at=NULL,
  last_door_value=NULL, last_presence_value=NULL,
  updated_at=UTC_TIMESTAMP(3)
```

Así una nueva secuencia de apertura/cierre se detecta inmediatamente tras el reset.

---

## 4. Gate del poller de presencia (RF-45)

### 4.1 Principio

`isCaptureWindow()` se rediseña para decidir **solo con el estado de dominio del snapshot
`/live`** (sin banderas pegajosas locales), y para tener una parada explícita por estado y un
watchdog de captura máxima. Se elimina `presenceConfirmed`; el estado "dentro" se deriva de
`active_stay.status == OCCUPIED ∧ presence == PRESENT ∧ door == CLOSED ∧ entry_confirmed_at`
presente.

### 4.2 Pseudocódigo

```
función isCaptureWindow(live, now):
    si !live: returns false
    io   = live.iot_session || {}
    door = io.door_state || 'UNKNOWN'
    pres = io.presence_state || 'UNKNOWN'
    stay = live.active_stay
    stayStatus = stay ? stay.status : null
    deadline = live.exit_deadline ? epoch(live.exit_deadline) : null

    # ── PARADAS por estado de dominio (RF-45.1) ──
    inside = (stayStatus == 'OCCUPIED' && pres == 'PRESENT' && door == 'CLOSED'
              && stay.entry_confirmed_at)                    # huésped dentro
    si inside: return false

    empty  = (!stay && pres == 'ABSENT' && !deadline)         # habitación 100% vacía
    si empty: return false

    # ── CAPTURA (RF-45.1.3) ──
    si door == 'OPEN': return true                            # entrada/salida en curso
    si deadline != null && deadline > now: return true        # verificación de salida
    si entryInProgress(live, now): return true                # QR reciente, aún no consolidado
    si verificationWindow(live, now): return true             # cierre reciente + gap

    return false

función entryInProgress(live, now):
    # solo para "seguir capturando mientras el huésped aún no está dentro";
    # NO se usa para distinguir entrada de salida (eso lo hace entry_confirmed_at)
    return live.qr_status?.consumed == true
        && !live.active_stay?.entry_confirmed_at
        && now - lastQrConsumedAt < ENTRY_WINDOW_MS          # p. ej. 90_000

función verificationWindow(live, now):
    io = live.iot_session || {}
    si !io.last_close_at: return false
    gapMs = (live.gap_seconds || 15) * 1000
    return (now - epoch(io.last_close_at)) < (gapMs + 10_000)
```

### 4.3 Watchdog de captura máxima (RF-45.3)

```
estado local: captureStartedAt = 0, captureBlockedUntil = 0

en cada tick:
    capturing = isCaptureWindow(live, now)

    si capturing y captureStartedAt == 0: captureStartedAt = now
    si !capturing:
        captureStartedAt = 0
        captureBlockedUntil = 0

    si capturing y (now - captureStartedAt) > CAPTURE_MAX_MS (120_000):
        log "⚠ watchdog de captura: forzando parada (door=..., stay=..., deadline=...)"
        captureBlockedUntil = now + CAPTURE_COOLDOWN_MS (30_000)
        captureStartedAt = 0
        capturing = false

    si now < captureBlockedUntil: capturing = false

    # un cambio de puerta rearma inmediatamente (nueva ventana legítima)
    si door != lastDoorState: captureBlockedUntil = 0
```

### 4.4 Caso "puerta atascada en OPEN"

Con la puerta atascada, `door == 'OPEN'` haría capturar para siempre. El watchdog corta a los
120 s, **registra el diagnóstico** y deja de consumir cuota; un cambio de puerta (al
recuperarse) rearma la captura. El poller nunca queda en bucle infinito de llamadas a Tuya.

### 4.5 Cuota Tuya

| Situación | Llamada a Tuya |
|---|---|
| `inside` (OCCUPIED + PRESENT + CLOSED) | **No** |
| `empty` (sin stay + ABSENT + sin deadline) | **No** |
| `door == OPEN` | Sí (intervalo estándar 5 s) |
| `deadline` activo | Sí (intervalo rápido 2 s) |
| `entryInProgress` / `verificationWindow` | Sí (estándar) |
| watchdog disparado | **No** durante 30 s |

Se conserva el backoff de 10 min ante `quota exhausted`.

---

## 5. Máquina de estados del monigote (RF-46 / RF-47)

### 5.1 Episodios (sustituye la bandera pegajosa)

Se reemplaza `_presenceDetectedDuringOpen` por un modelo de **episodio** explícito y
reiniciables:

```
entryEpisode = {
  stayId, qrAt, doorOpenedAt, presenceSeenWhileOpen, consolidatedAt, timedOut
}
exitEpisode = {
  stayId, doorOpenedAt, closedAt, presenceSeenWhileClosed, absentAt, deadline
}
hasBeenInside(stayId) = active_stay.entry_confirmed_at != null    // autoridad del backend
```

- `entryEpisode.timedOut` (F42 / RF-46.4): se pone a `true` cuando la ventana de
  verificación de entrada se agota sin presencia; bloquea la consolidación por una
  presencia posterior y se limpia en la siguiente apertura (`door == OPEN`).

- `entryEpisode.presenceSeenWhileOpen` se pone a `true` si llega PRESENT con la puerta abierta;
  **se limpia al consolidar DENTRO/OCUPADA** (nunca queda pegada).
- La distinción entrada/salida usa `hasBeenInside` (backend) + `last_open_at` posterior, **no**
  una ventana QR de 120 s. La ventana QR solo se usa para "entrada en curso" (aún sin
  consolidar).
- El episodio se reinicia cuando cambia `stayId`.

### 5.2 Estados (lógicos) y mapeo a la UI

| Estado lógico | UI key (existente) | Monigote | Significado |
|---|---|---|---|
| `LOADING` | `LOADING` | `OUTSIDE` | Sin datos aún. |
| `FREE` | `ESPERANDO` | `OUTSIDE` | Habitación libre sin estancia. |
| `QR_DISPONIBLE` | `QR_DISPONIBLE` | `NEAR_QR` | QR escaneable. |
| `QR_OK` | `QR_OK` | `AT_QR` | QR validado, puerta aún no abierta. |
| `ESPERANDO_APERTURA` | `QR_ESPERANDO` | `AT_QR` | QR validado, se espera el evento OPEN. |
| `EN_UMBRAL` | `HUESPED_EN_PUERTA` | `CROSSING` | Puerta abierta, avatar en el umbral. |
| `VERIFICANDO_ENTRADA` | `VERIFICANDO_ENTRADA` | `WAITING` | F42: puerta cerrada tras apertura acreditada, sin presencia aún; conteo `gap_seconds` + `?`. |
| `DENTRO` | `OCUPADA` | `INSIDE` | Entrada consolidada (puerta cerrada + presencia). |
| `POSIBLE_SALIDA` | `PUERTA_ABIERTA` | `WAITING` | Apertura tras entrada confirmada. |
| `VERIFICANDO` | `VERIFICANDO_PRESENCIA` | `WAITING` | F57 (RF-64): cierre tras ciclo de salida; verificación visible (20 s, `?` + timer) en cualquier estado de presencia. |
| `SALIDA_CONFIRMADA` | `SALIDA_DETECTADA` / `HUESPED_HA_SALIDO` | `OUTSIDE` | Estancia cerrada; luz off. |
| `ANOMALIA_*`, `EXCESO_TIEMPO`, `LIMPIEZA`, `FUERA_SERVICIO`, `ANTI_REENTRADA`, `SIN_CONFIGURAR`, `SENSORES_DESCONECTADOS` | igual | igual | Sin cambios (A1–A8 informativas, RF-35). |

### 5.3 Tabla de transiciones

| # | Origen | Guarda | Acción | Destino |
|---|---|---|---|---|
| T1 | `LOADING` | llegan sensores reales | — | `FREE`/`QR_*` |
| T2 | `FREE` | `qr.scannable` | — | `QR_DISPONIBLE` |
| T3 | `QR_DISPONIBLE` | `QR_VALIDATE OK` reciente | inicia `entryEpisode(qrAt)` | `QR_OK` |
| T4 | `QR_OK` | `door == OPEN` | `entryEpisode.doorOpenedAt = last_open_at` | `EN_UMBRAL` |
| T5 | `QR_OK` | `door == UNKNOWN` y QR reciente | — | `ESPERANDO_APERTURA` |
| T6 | `ESPERANDO_APERTURA` | `door == OPEN` | igual que T4 | `EN_UMBRAL` |
| T7 | `EN_UMBRAL` | `door == OPEN` y PRESENT | `entryEpisode.presenceSeenWhileOpen = true` | `EN_UMBRAL` |
| T8 | `EN_UMBRAL` | `door == CLOSED` y (`entry_confirmed_at` o `presenceSeenWhileOpen`) | consolida entrada; limpia `entryEpisode` | `DENTRO` |
| T8b | `EN_UMBRAL` | `door == CLOSED`, ciclo acreditado, sin PRESENT y `now - last_close_at < gap` | `entryEpisode.timedOut = false` | `VERIFICANDO_ENTRADA` (umbral, `?`, conteo) |
| T8c | `VERIFICANDO_ENTRADA` | llega PRESENT (aunque sea tras el cierre, dentro del gap) | consolida entrada (backend fija `entry_confirmed_at`); limpia `entryEpisode` | `DENTRO` |
| T8d | `VERIFICANDO_ENTRADA` | `now - last_close_at >= gap` sin PRESENT | `entryEpisode.timedOut = true`; exige nueva apertura | `ESPERANDO_APERTURA` (fuera) |
| T8e | `EN_UMBRAL` | `door == OPEN` con `entryEpisode.timedOut` | re-ancla `doorOpenedAt`; limpia `timedOut` | `EN_UMBRAL` |
| T9 | `DENTRO` | `door == OPEN` y `entry_confirmed_at` y `last_open_at > entry_confirmed_at` | inicia `exitEpisode` | `POSIBLE_SALIDA` |
| T10 | `DENTRO` | `presence == ABSENT` sin apertura | — (no cierra estancia) | `DENTRO` |
| T11 | `POSIBLE_SALIDA` | `door == CLOSED` | `exitEpisode.closedAt = last_close_at`; `exitVerifyUntil = last_close_at + 20 s` (RF-64) | `VERIFICANDO` (siempre; `?` + timer) |
| T12 | `POSIBLE_SALIDA` | PRESENT y `door == OPEN` | mantiene; re-arma la ventana al cerrar de nuevo | `POSIBLE_SALIDA` |
| T13 | `VERIFICANDO` | llega PRESENT dentro de la ventana | **no** cancela: espera prudencial completa (RF-64.2.2) | `VERIFICANDO` |
| T13b | `VERIFICANDO` | `now >= exitVerifyUntil` sin `exit_deadline` | cancela y limpia el episodio | `DENTRO` |
| T14 | `VERIFICANDO` | `exit_deadline` emitido (ausencia + guarda) | muestra el conteo del backend (manda el deadline) | `VERIFICANDO` |
| T15 | `VERIFICANDO` | `deadline` cumplido y estancia `EXITED` | limpia episodios | `SALIDA_CONFIRMADA` |
| T16 | `SALIDA_CONFIRMADA` | `room FREE` sin estancia | — | `FREE` |
| T17 | cualquiera | `stay.status == EXITED` precedido de OCCUPIED | — | `SALIDA_CONFIRMADA` |
| T18 | cualquiera | anomalías A1–A8 activas | **no altera** la coreografía (solo badge) | mismo estado |

> **RF-46.1.2**: si el evento OPEN se pierde, T4 no dispara; pero `entry_confirmed_at` se fija
> en el backend al aplicar CLOSED con presencia, así que T8 consolida `DENTRO` y el avatar no
> salta a interior sin pasar por umbral: mientras `door == OPEN` el estado es `EN_UMBRAL`, y
> si el OPEN nunca llega, se muestra `ESPERANDO_APERTURA` (T5) en vez de `DENTRO`.

> **RF-46.4 (F42)**: la ventana de entrada usa `entry_window_seconds`
> (`room_types.presence_entry_window_seconds`, default 90 s), **no** `gap_seconds`. Bug 1:
> se desacopla de la guarda de salida (`exit_guard_seconds`, default 3 s). El backend
> consolida `entry_confirmed_at` cuando llega PRESENT **con la puerta cerrada** y dentro de la
> ventana (`IotSessionService::consolidateEntry(..., requireRecentClose: true)`); con la
> puerta abierta no consolida, para que el avatar espere en el umbral (RF-46.1.3).
> `exit_deadline` solo se emite si `entry_confirmed_at` está fijado (RoomLiveController y
> EventStreamController, alineados con `ExitRuleEvaluator`), de modo que una entrada sin
> consolidar nunca muestra el conteo de salida.

### 5.4 Función pura `deriveChoreography()`

La máquina es una **función pura y testeable**: recibe el snapshot + episodios + `now`, no lee
`Date.now()` ni el DOM, y devuelve estado + mutaciones de episodio:

```js
function deriveChoreography(snapshot, episodes, now) {
  // snapshot: { door, presence, stayStatus, entryConfirmedAt, exitDeadline,
  //             lastOpenAt, lastCloseAt, gapSeconds, qrConsumed, qrOkAt, anomalies, roomStatus }
  // episodes: { entry, exit, hasBeenInside }
  // returns:  { state, episodeUpdates }
}
```

`processLiveData()` solo la invoca y aplica `episodeUpdates` a las variables de módulo
(eliminando `_presenceDetectedDuringOpen`). Los casos de la tabla T1–T18 se cubren con tests
unitarios de la función pura (sin navegador).

### 5.5 Diagramas de secuencia

**Entrada (RF-46):**

```mermaid
sequenceDiagram
    participant H as Huésped
    participant L as Lector QR
    participant API as API
    participant DB as iot_sessions (FOR UPDATE)
    participant P as Poller presencia
    participant D as Dashboard

    H->>L: escanea QR
    L->>API: POST /qr/validate
    API->>API: QR OK → stay OCCUPIED
    API-->>D: SSE state (QR_OK)
    D->>D: QR_OK (avatar AT_QR)
    H->>API: PROXIMITY=OPEN (webhook/Pulsar)
    API->>DB: tx lock → door=OPEN, last_open_at
    API-->>D: SSE state
    D->>D: EN_UMBRAL (avatar CROSSING)
    Note over P: /live door=OPEN → captura
    P->>API: PRESENCE=PRESENT
    API->>DB: tx lock → presence=PRESENT, last_presence_event_at
    API-->>D: SSE state
    D->>D: EN_UMBRAL + presenceSeenWhileOpen=true
    H->>API: PROXIMITY=CLOSED
    API->>DB: tx lock → door=CLOSED, last_close_at
    API->>API: entry_confirmed_at = close
    API-->>D: SSE state
    D->>D: DENTRO / OCUPADA (avatar INSIDE)
    Note over P: gate "inside" → deja de llamar a Tuya
```

**Salida (RF-47):**

```mermaid
sequenceDiagram
    participant H as Huésped
    participant API as API
    participant DB as iot_sessions (FOR UPDATE)
    participant P as Poller
    participant X as exit-scan
    participant D as Dashboard

    Note over P,X: stay OCCUPIED + entry_confirmed_at
    H->>API: PROXIMITY=OPEN
    API->>DB: tx lock → door=OPEN, last_open_at
    API-->>D: SSE state → POSIBLE_SALIDA
    Note over P: captura (door OPEN)
    P->>API: PRESENCE=ABSENT
    API->>DB: tx lock → presence=ABSENT, last_absent_since
    H->>API: PROXIMITY=CLOSED
    API->>DB: tx lock → door=CLOSED, last_close_at
    API-->>D: SSE state → VERIFICANDO (conteo)
    X->>API: executeIfPending() (re-valida bajo lock)
    alt presencia reaparece
        P->>API: PRESENCE=PRESENT
        API->>DB: last_absent_since=NULL
        API-->>D: SSE state → DENTRO (conteo cancelado)
    else gap cumplido sin presencia
        X->>API: applyEffects()
        API->>API: stay EXITED + lock + room FREE + luz OFF
        API-->>D: SSE state → SALIDA_CONFIRMADA (avatar OUTSIDE)
    end
```

---

## 6. Auto-recuperación y observabilidad del panel (RF-49)

### 6.1 Watchdog del stream SSE

Problema: `EventSource` reconecta solo si el socket cae; si el servidor sigue conectado pero
deja de emitir `state`, el panel queda congelado sin detectarlo. Diseño:

```js
let _lastSseEventAt = 0;   // se actualiza en 'connected', 'state' y 'ping'
const SSE_SILENCE_MS = 5000;
const SSE_FORCE_RECONNECT_MS = 15000;

setInterval(() => {
  if (!_sseConnected) return;
  if (document.visibilityState !== 'visible') return;
  const silence = Date.now() - _lastSseEventAt;
  if (silence > SSE_SILENCE_MS) {
    resyncLive();                       // fetch puntual a /live + processLiveData
    _lastSseEventAt = Date.now();        // evita tormenta de fetches
  }
  if (silence > SSE_FORCE_RECONNECT_MS) connectSSE();   // reabre el stream
}, 1000);
```

Para que la señal de vida sea fiable, el servidor emite un evento SSE nombrado `ping` cada 5 s
(el `keepalive` actual es un **comentario** y no dispara listeners JS). Ver §8.

### 6.2 Conteo por temporizador local

`updateCountdown()` no depende de la llegada de eventos: se ejecuta en un
`setInterval(…, 250)` y pinta el conteo con el reloj del navegador.

**Entrada (RF-46.4, sin cambios):** anclado a `last_close_at + entry_window_seconds` mientras el
estado es `VERIFICANDO_ENTRADA`.

**Salida (F57 / RF-64):**
- se muestra en `VERIFICANDO_PRESENCIA` con `door == CLOSED` y estancia `OCCUPIED`, **con
  independencia de `presence_state`** (antes exigía `ABSENT`, por eso no aparecía);
- sin `exit_deadline`: se ancla a `exitVerifyUntil` (cierre + 20 s, `DEFAULT_EXIT_VERIFY_SECONDS`);
- con `exit_deadline` (ausencia + guarda): manda el deadline del backend, que puede cortar la
  ventana antes de tiempo;
- al llegar a 0, llama a `resyncLive()` para reflejar la confirmación/ventana real (no inventa el
  cierre en cliente);
- el arco usa la ventana real como total (antes usaba `gap_seconds`, descuadrado);
- se oculta en cuanto la coreografía abandona `VERIFICANDO_PRESENCIA` (dentro o fuera).

### 6.3 Trazabilidad de la coreografía

`deriveChoreography()` registra en consola (y opcionalmente en un buffer visible) cada
transición con `{from, to, guard, snapshotMin}` bajo un flag `DEBUG_CHOREO`, permitiendo
diagnosticar la coreografía desde el propio panel sin acceso a BD.

---

## 7. Infraestructura de workers (RF-48)

### 7.1 Arranque/parada determinista

`start-all.sh` arranca cada worker con un wrapper `while true; do php bin/...; sleep N; done`
lanzado con `nohup bash -c`. Al reiniciar, `pkill` puede matar al hijo `php` mientras el
wrapper lo relanza, o dejar wrappers vivos: resultado, 9 instancias de `exit-scan`.

Diseño:

- **`stop-all.sh`** dedicado y llamado al inicio de `start-all.sh`:
  1. `pkill -TERM -f 'bash -c .*bin/exit-scan'` y equivalentes para cada worker (mata el
     wrapper **primero**, para que no relance);
  2. `pkill -TERM -f 'php .*bin/<worker>.php'` / `node .*tuya-presence-poller` /
     `tuya-pulsar-consumer`;
  3. bucle de espera (p. ej. hasta 5 s) hasta que no queden procesos; si persisten,
     `pkill -KILL`;
  4. el manager de presencia (`presence-poller-manager.sh`) también se detiene antes de
     relanzarlo (su reconciliación hace `pgrep` y evitaría duplicados, pero no mata wrappers).
- **Instancia única**: cada worker se lanza con `setsid` y escribe su PID en
  `api/run/<worker>.pid`. `start-all.sh` verifica con `flock`/PID file; si ya hay una
  instancia viva, no lanza otra. Se elimina el patrón `while true` para `exit-scan` (el propio
  script ya tiene su bucle interno de 5 s) y se estandariza para el resto con supervisión por
  systemd o un único supervisor con backoff.
- **`exit-scan` sin escritura de sensores**: llama a `ExitActionService::executeIfPending()`
  (que re-lockea y re-valida), nunca a `updateState()` ni `upsert()`.

### 7.2 `/dashboard-api/system-status` ampliado

La implementación actual (`api/public/index.php`) solo mira `tuya-presence-poller`,
`tuya-pulsar-consumer`, `exit-scan` y `overstay-scan` con `pgrep … | head -1`. Se amplía:

| Worker | Patrón | ¿Requerido? |
|---|---|---|
| `tuya-presence-poller` | `tuya-presence-poller` | Sí |
| `tuya-pulsar-consumer` | `tuya-pulsar-consumer` | Sí |
| `exit-scan` | `bin/exit-scan` | Sí (exactamente 1) |
| `overstay-scan` | `bin/overstay-scan` | Sí (exactamente 1) |
| `outbox-worker` | `bin/outbox-worker` | Sí (exactamente 1) |
| `anomaly-scanner` | `bin/anomaly-scanner` | Sí (exactamente 1) |

Respuesta ampliada (aditiva, retrocompatible con `renderSystemStatus`):

```json
{
  "exit-scan": {
    "label": "Regla de Salida (Exit)",
    "online": true,
    "pid": 12345,
    "pids": [12345],
    "instances": 1,
    "healthy": true
  }
}
```

`healthy = online && instances == 1` (para los workers requeridos); `instances > 1` se marca
como **condición anómala** (duplicado).

### 7.3 Reset de habitación

Además de §3.4, el reset limpia contadores locales de workers solo si aplica (no hay estado
local persistente). El `presence-poller-manager` reconciliará por BD.

---

## 8. Contratos a formalizar (`contracts.md`)

### 8.1 `GET /api/v1/rooms/{id}/live` y evento SSE `state`

Campos **nuevos** (aditivos; los existentes no cambian de nombre):

| Ruta JSON | Tipo | Semántica |
|---|---|---|
| `iot_session.last_door_event_at` | string ISO/null | `occurred_at` del último evento de puerta aplicado. |
| `iot_session.last_presence_event_at` | string ISO/null | `occurred_at` del último evento de presencia aplicado. |
| `iot_session.last_door_value` | string/null | `OPEN`/`CLOSED` aplicado. |
| `iot_session.last_presence_value` | string/null | `PRESENT`/`ABSENT` aplicado. |
| `active_stay.entry_confirmed_at` | string ISO/null | Confirmación de "dentro" (autoridad de `hasBeenInside`). |

Cambios de semántica (documentar explícitamente):

| Campo | Antes | Ahora |
|---|---|---|
| `exit_deadline` | requería `door_state == CLOSED` | se calcula con ciclo de puerta acreditado, sin exigir estado actual CLOSED; `last_absent_since + exit_guard_seconds`. |
| `gap_seconds` | sin cambios | **legacy**: override de sala > room_type > 15. Ya no gobierna la regla de salida; se expone por compatibilidad. |
| `exit_guard_seconds` | — | **nuevo (Bug 1)**: guarda de ausencia de SALIDA; sala (`rooms.presence_check_seconds`) > `EXIT_ABSENCE_GUARD_SECONDS` > 3 s. |
| `first_entry_at` | se usaba como "dentro" | se mantiene informativo; `entry_confirmed_at` es la autoridad de coreografía. |

Evento SSE nuevo:

| Evento | Frecuencia | Payload | Uso |
|---|---|---|---|
| `ping` | cada 5 s | `{ "ts": "<ISO>" }` | watchdog de silencio del panel (RF-49). |

### 8.2 `GET /dashboard-api/system-status`

| Campo | Tipo | Semántica |
|---|---|---|
| `<worker>.instances` | int | Nº de procesos vivos con ese patrón. |
| `<worker>.pids` | int[] | Lista de PIDs. |
| `<worker>.healthy` | bool | `online ∧ instances == 1` (workers requeridos). |
| `<worker>.label`, `online`, `pid` | — | Se conservan (retrocompatibilidad). |

Se añaden las claves `outbox-worker` y `anomaly-scanner`.

### 8.3 Migración

`0108_sensor_event_ordering.sql` (§2.6) debe reflejarse en `contracts.md` como cambio de
esquema (columnas aditivas y nullable, sin ruptura).

---

## 9. Archivos afectados

| Archivo | Cambio |
|---|---|
| `api/migrations/0108_sensor_event_ordering.sql` | **Nuevo** — columnas de orden/auditoría + `stays.entry_confirmed_at`. |
| `api/src/Domain/Presence/IotSession.php` | Campos nuevos + `toArray()` extendido. |
| `api/src/Domain/Presence/IotSessionRepositoryInterface.php` | `lockByRoomId`, `updateState`, `markExitEvaluated`. |
| `api/src/Domain/Presence/IotSessionRepository.php` | Implementación con lock y updates por columnas. |
| `api/src/Domain/Presence/PresenceEventRepository.php` / interface | Fingerprint, `applied`, `discard_reason`, `insertOrGet`/`markAudit`. |
| `api/src/Domain/Presence/IotSessionService.php` | `applyEvent()` con transacción + `decide()` + `mutate()` + post-commit. |
| `api/src/Infrastructure/Gateways/Sensor/TuyaSensorIngress.php` | Guardar `tuya_t` crudo; conservar `source_event_id`. |
| `api/src/Domain/Presence/ExitRuleEvaluator.php` | Precondiciones por ciclo + `entry_confirmed_at` + `DOOR_CYCLE_MAX_S`. |
| `api/src/Domain/Presence/ExitActionService.php` | `executeIfPending()` idempotente bajo lock. |
| `api/bin/exit-scan.php` | Usar `executeIfPending()`; no escribir sensores. |
| `api/bin/tuya-presence-poller.js` | `isCaptureWindow()` por dominio + watchdog + sin bandera pegajosa. |
| `start-all.sh` + `stop-all.sh` | Parada determinista, instancia única, PID files. |
| `api/public/index.php` | `system-status` ampliado; reset de habitación (§3.4). |
| `api/src/Http/Controllers/QrTestController.php` | `roomsReset()` limpia nuevas marcas y `last_close_at`. |
| `api/src/Http/Controllers/RoomLiveController.php` | Campos nuevos; `exit_deadline` sin exigir CLOSED. |
| `api/src/Http/Controllers/EventStreamController.php` | Campos nuevos; evento SSE `ping`; deadline coherente. |
| `api/public/dashboard.html` | `deriveChoreography()` pura; watchdog SSE; conteo local. |
| `api/tests/Unit/*` y `api/bin/run-tests.sh` | Tests unitarios puros + bloque HTTP de la fase. |

---

## 10. Riesgos y regresiones

| Riesgo | Probabilidad | Impacto | Mitigación |
|---|---|---|---|
| `FOR UPDATE` con 8 workers PHP (webhook de puerta + presencia) | Media | Latencia del webhook | Lock **por habitación** y breve (<20 ms); sin I/O externo bajo lock; no hay contención entre habitaciones. |
| Deadlock entre `iot_sessions` y `stays` | Baja | Error 500 puntual | Orden único de adquisición; reintento 3× con backoff. |
| `SELECT … FOR UPDATE` sobre fila inexistente | Baja | Lock no efectivo | `INSERT … ON DUPLICATE KEY UPDATE id=id` antes del `SELECT FOR UPDATE`. |
| Regla de salida dispara de más (falso `ABSENT` con huésped dentro) | Media | Cierre indebido | Exigir `entry_confirmed_at` **y** `last_open_at > entry_confirmed_at` (nueva apertura); sin apertura no hay salida. |
| Salida no dispara si el cierre se pierde | Media | Estancia colgada | Ya no exige `door_state == CLOSED`; si falta `last_close_at` el caso es ambigüedad de hardware y lo cubren anomalías A5/A7 y el watchdog del poller. |
| Cuota Tuya | Media | Bloqueo temporal de API | Paradas `inside`/`empty`, watchdog 120 s, backoff 10 min, intervalo rápido solo con `deadline`. |
| Duplicados con fingerprint colapsan transiciones legítimas | Baja | Pérdida de un evento | Fingerprint incluye `value`; solo colapsa mismo sensor+valor en el mismo segundo (no-op de estado). |
| Cambio de `entry_confirmed_at` rompe lógica que usaba `first_entry_at` | Media | Overstay/analytics | `first_entry_at` se conserva; `entry_confirmed_at` es aditivo. Revisar consumidores de `first_entry_at`. |
| SSE `ping` nuevo y watchdog provocan fetches en cascada | Baja | Carga | Throttle: `resyncLive()` como máximo 1/5 s; reconexión forzada a los 15 s. |
| Reintentos no idempotentes en `executeIfPending` | Baja | Doble cierre de worker_sessions | `markExitEvaluated` condicional bajo lock evita el segundo efecto. |
| Regresión del firmware/QR (F1, F39, F40) | Baja | — | No se toca el sketch; F41 es backend + poller + panel + infra. |

---

## 11. Estrategia de pruebas (TDD, AGENTS.md)

Al ser lógica de dominio y de UI, se prioriza **función pura primero**:

1. **Unitarios PHP** (`api/tests/Unit/*Test.php`, auto-descubiertos por el runner):
   - `IotSessionEventOrderingTest`: `decide()` con eventos atrasados, duplicados, noop y
     aplicables; verifica que un evento viejo no revierte un `CLOSED` nuevo.
   - `ExitRuleEvaluatorTest`: ciclo completo + `entry_confirmed_at` → true; sin nueva apertura
     → false; `PRESENT` → false; `gap` no cumplido → false; `door_state=OPEN` con cierre
     acreditado → true (no depende del estado actual).
   - `ChoreographyTest` (si la función pura se porta a PHP) o tests JS del `deriveChoreography`.
2. **Unitarios JS** del poller: exportar `isCaptureWindow()`/`watchdog()` y testear `inside`,
   `empty`, `OPEN`, `deadline`, watchdog y rearme.
3. **Tests HTTP/integración**: nuevo bloque en `api/bin/run-tests.sh` (BLOCK reservado) que
   cubra `/live` con campos nuevos, `system-status` con `instances/healthy`, y el reset de
   habitación limpiando `last_close_at`.
4. **Regresión completa**: `cd /root/cerraduras/api && bash bin/run-tests.sh` con 0 failures
   antes de cerrar la fase.

Se sigue el ciclo RED → GREEN → REFACTOR: cada test se escribe y falla antes de implementar.

---

## 12. Lo que NO cambia

- El sketch ESP32 (`scanner-relay-prod.ino`) y su flujo QR/USB/relé/GPIO4/watchdog.
- Los contratos públicos existentes de QR (`/qr/validate`, firmas HMAC) y de trabajadores.
- Las anomalías A1–A8: siguen siendo informativas y no bloquean el flujo (RF-35, RF-49.4).
- El modelo canónico device → pack → room (F30) y la verificación de dispositivos (F39/RF-42).
- Los umbrales de negocio existentes (`exit_presence_gap_seconds`, cooldown de reentrada).
- El firmware y los secretos: este diseño no introduce credenciales nuevas.

---

## 13. F44 — Tiempo real de sensores y presencia bajo demanda

### 13.1 Consumer Pulsar: dueño único
El consumer vive exclusivamente bajo `cerraduras-pulsar-consumer.service` (systemd).
`start-all.sh` ejecuta `systemctl restart` (no lanza wrapper) y `stop-all.sh` ejecuta
`systemctl stop` (no lo mata por patrón/cwd, para no chocar con `Restart=always`).
Esto elimina la doble conexión Reader y el doble reenvío al webhook.

### 13.2 Consumer Pulsar: devices dinámicos + resync
- `resolveDeviceIds()` lee de la BD los `external_id` de `kind IN (PROXIMITY,PRESENCE)` y
  se reconcilia cada 60 s. Sin ids hardcodeados.
- Al `ws.on('open')` se ejecuta `resyncKnownDevices()`: una sonda `GET /v1.0/iot-03/devices/{id}/status`
  por device rastreado (rate-limited a 20 s), normalizada con `buildStatusPayload()` y
  reenviada al webhook. Cubre las transiciones perdidas durante el hueco del Reader.
- Backoff de reconexión: base 2 s + jitter, máximo 60 s.

### 13.3 Poller de presencia: ventanas
`shouldCapture()` se mantiene (RF-45) con estos ajustes (RF-51):
- Entrada: puerta OPEN o `entryInProgress` (< `entry_window_seconds`).
- Tras cierre: `verificationWindow` (< `exit_check_seconds`).
- Paradas: huésped dentro consolidado (`entry_confirmed_at` + PRESENT + CLOSED) y sala vacía.
- Throttle dentro de ventana: 2 s; muestreo inmediato al entrar en ventana (`lastTuyaCallAt=0`).
- Watchdog 120 s / cooldown 30 s sin cambios.

**RF-51.1.6 — ciclo de salida acreditado**: la parada "huésped dentro consolidado" se
**anula** mientras exista `pendingExitVerification(live, now)`: `entry_confirmed_at` presente,
`door=CLOSED`, una apertura posterior a la confirmación y su cierre (`last_open_at >=
entry_confirmed_at`, `last_close_at >= last_open_at`) dentro de `exit_check_seconds`. En ese
caso el poller sigue muestreando aunque `presence_state=PRESENT`, para capturar el `none` real
y permitir que `ExitRuleEvaluator` confirme la salida. Fuera de esa ventana, o si el cierre es
el de la propia consolidación de entrada (`last_open_at < entry_confirmed_at`), aplica la parada
normal. Justificación (validación PROTO2): el radar reporta `none` ~3 s después de salir; el
gate perdía ese `none` al parar en el cierre con `PRESENT` obsoleto.

### 13.4 Ventanas configurables
`room_types.presence_entry_window_seconds` (default 90) — migración `0109`.
`/live` expone `entry_window_seconds` y `exit_check_seconds = gap_seconds + 10`.

### 13.5 Presencia y calibración
La presencia efectiva es `presence_state ∈ {presence, move}`; `far_detection` no fuerza
`ABSENT`. La calibración persiste `devices.meta_json.calibration` vía
`/dashboard-api/presence-calibrate/set` (ya existente).

### 13.6 SSE
Ante el evento `close` del servidor el panel programa `connectSSE()` con backoff
exponencial (2 s → 30 s), reseteado en `connected`. No hay degradación permanente a polling.

### 13.7 Prueba de paseo (RF-52.4)
Dentro del modal `#cal-modal` se añade un modo guiado **sin backend nuevo**: reutiliza
`GET /dashboard-api/presence-calibrate/status` y `POST /dashboard-api/presence-calibrate/set`
respetando el cooldown de cuota existente (~2 s). Secuencia:
1. **Límite**: el técnico se coloca en la distancia máxima deseada y pulsa "Iniciar prueba";
   el panel muestrea en vivo el badge (`presence`/`move`/`none`).
2. **Alejamiento**: el técnico se aleja; el panel confirma que pasa a `none` y en cuántos
   segundos (retención).
3. **Recomendación**: función pura `suggestFarAction(readings, caps)` que propone subir/bajar
   `far_detection` un paso (`far_step`) según si detecta o no en el límite. Se aplica en vivo
   (`persist:false`) y solo "Guardar" persiste el snapshot (`devices.meta_json.calibration`).
La UI muestra explícitamente que el 24G V3 **no reporta distancia** (el campo "objetivo" queda
en `—`), para no prometer umbrales métricos.

### 13.8 `target_dis_closest` no fiable en 24G V3 (RF-52.4.3)
El 24G V3 (producto `5lld8pgsoynvctqa`, `hps`) declara el DP `target_dis_closest` pero reporta
**siempre 0** (verificado: 95/95 lecturas del poller y pushes nativos del webhook). El ZY-M100 sí
reporta distancia. Por tanto no se implementa corte por distancia; el único control de alcance es
`far_detection` + `sensitivity` (paso de 75 cm, mínimo efectivo 150 cm) y el apantallado/
reorientación física documentados como recomendación operativa.

### 13.9 Push de sensores Tuya (Message Service / Pulsar) — configuración y alta de devices

**Cómo llega el tiempo real.** Tuya publica los mensajes de los dispositivos en el topic Pulsar
`<clientId>/out/event`. El consumer `bin/tuya-pulsar-consumer/index.js` se conecta como *Reader*
a `wss://mqe.tuyaeu.com:8285/ws/v2/reader/persistent/<accessId>/out/event?messageId=latest`,
descifra (AES-GCM/ECB) y reenvía al webhook `POST /api/v1/tuya/webhook`, que normaliza
(`TuyaSensorIngress`) y actualiza el dominio (`IotSessionService`). **No consume cuota de API
IoT Core** (es Message Service, no llamadas HTTP).

**Regla de mensajes (filtro) en la consola Tuya.** Sin regla, los mensajes se filtran y NO llegan
al topic. Estado correcto (2026-09-17, PROTO2):

```
Messaging rules — Production Environment (ENABLED)
  BizCode (Message type) IN statusReport
  Device id in bf4c7e7d2cef28cea2nkwk,bf9a278e76e2c3f01ay0cs
```
- `bf4c7e7d2cef28cea2nkwk` = MC400D puerta (PROXIMITY).
- `bf9a278e76e2c3f01ay0cs` = 24G V3 presencia (PRESENCE, PROTO2).

**Procedimiento al añadir un sensor Tuya nuevo (otra habitación):**
1. Registrar el device en `devices` (kind `PROXIMITY`/`PRESENCE`; ver migración `0104`).
2. Consola Tuya → **Cloud → proyecto `cerraduras` → Message Service → Messaging rules
   (Production Environment)**: añadir el **device id** del nuevo sensor a
   `Device id in …` (lista separada por comas). **No** borrar los existentes.
   - Si la UI no admite varios ids en una regla, crear una **regla adicional** con
     `BizCode IN statusReport` + `Device id in <nuevo_id>`.
   - Si el dispositivo es **IoT Core** (protocolo 1000) y no llegan sus mensajes, añadir su
     tipo a `BizCode IN …` (p. ej. `devicePropertyMessage`); el consumer ya soporta
     `bizData`/`properties`.
3. **Presencia = push, siempre** (F78/RF-101). Ya **no** existe poller de nube:
   la ampliación de la regla de mensajes (paso 2) es lo único necesario. El flag
   histórico `devices.meta_json.presence_source` (`push`/`disabled`) se conserva
   solo como documentación; **no** arranca ningún proceso. Nunca reintroducir un
   poller continuo de Tuya.
4. Verificar en `api/logs/pulsar-consumer.log` que aparece el `devId` del nuevo sensor y en
   `/live` que el estado (puerta/presencia) se actualiza al mover el sensor.

**Diagnóstico rápido:** si un sensor Tuya no llega en tiempo real pero sí aparece en *Device
Debug* de la consola, la causa es casi siempre la **regla de mensajes** (device id o BizCode no
incluidos). El consumer solo reenvía devices presentes en `devices` (kind PROXIMITY/PRESENCE).

**Cuota:** el Message Service tiene su propia cuota (distinta de IoT Core API). Ampliar la regla
a más devices NO consume cuota de API. El 24G reporta además `illuminance_value`/`man_state`,
que el ingress ignora en silencio (`INFO_DPS`).

**Valores de `devices.meta_json.presence_source` (F78: solo informativo, ya no arranca nada):**

| Valor | Significado | Consumer Pulsar | Sondas Tuya | Calibración |
|-------|-------------|:---:|:---:|:---:|
| ausente / `NULL` | Sin marcar (se sigue tratando como presencia normal) | ✅ | ✅ bajo demanda | ✅ |
| `push` | Llega en tiempo real por el consumer (regla de mensajes Tuya ampliada) | ✅ | ✅ bajo demanda | ✅ |
| `disabled` | **Apagado fuerte** (no se usa; se conserva para el futuro) | ❌ | ❌ | ❌ |

**F78/RF-101**: ya **no existe poller de nube** en ningún caso. El flag `presence_source`
**no** lanza ningún proceso; la presencia entra por push (o queda apagada con `disabled`).
`disabled` además hace que el consumer no lo rastree, que `probeTuyaOnlineOnce()` no lo sondee
y que `resolvePresenceDeviceForRoom()` lo ignore (la calibración responde "sin sensor").
Ejemplo: `ZY-M100 bf98d27d…` (banco de pruebas, migración `0112`).

**Unidad de `backoffUntil` (guardia de cuota, F46++):** en `api/run/tuya-quota.json`,
`backoffUntil` se guarda **en segundos epoch** (misma unidad en el poller Node y en PHP).
Los valores legados en milisegundos (> 1e12) se normalizan al leer. Un desajuste ms/s hacía
que `/live` reportara `tuya_quota.state='exhausted'` de forma permanente y que se bloquearan
las sondas de verificación y la calibración (aunque la cuota real estuviera disponible).

> **Alta/migración de cuenta/proyecto Tuya**: ver
> [`docs/tuya-account-migration.md`](../../docs/tuya-account-migration.md). Este
> runbook cubre el cambio de cuenta/data center, el re-emparejamiento y la
> propagación de los nuevos `device_id`.

---

## 14. F47 — Diagnóstico de latencia, robustez de recepción Tuya y arranque consistente

### 14.1 Sonda de latencia sin cuota (RF-53)

**Herramienta**: `api/bin/presence-latency-probe.js` (Node, sin dependencias nuevas).

- Suscribe a `GET /dashboard-api/event-stream?room_id=<id>` y, en paralelo, sondea
  `presence_events`/`access_events` por la CLI de `mysql` (mismo patrón que
  `bin/tuya-pulsar-consumer/index.js`).
- Por cada cambio imprime una línea con: `physical_marker`, `device_t` (`meta_json.tuya_t`),
  `occurred_at`, `received_at`, `server_ts` (SSE) y `now`, más los deltas.
- Las marcas físicas se leen por `stdin` (Enter con etiqueta) o por el fichero
  `api/run/latency-marker`. Etiquetas libres; recomendadas `PUERTA_ABRE`, `DELANTE_SENSOR`,
  `QUIETO`, `ALEJO`.
- **Cero llamadas a Tuya**: es solo lectura de SSE + BD. No usa `tuya-presence-listen.js`.

**Atribución (RF-53.3)**:

| Tramo | Cómo se mide | Lectura |
|-------|--------------|---------|
| físico → dispositivo | `device_t - physical_marker` | retardo del sensor/firmware |
| dispositivo → BD | `received_at - device_t` | latencia Message Service + consumer + webhook |
| BD → panel | `server_ts` SSE − `received_at` (aprox. `server_ts`) | latencia SSE/event loop |

El sensor de puerta es el grupo de control: mismo camino Tuya y evento físico observable. Si
la puerta cae en ~1 s y la presencia en 15–20 s, la variable es el sensor de presencia.

**Nota sobre reloj del dispositivo**: `device_t` es el sello del dispositivo. La validez del
reloj se comprueba con los `device_t` de iluminancia (~cada 10 s) y de la puerta: si el tramo
`dispositivo → BD` es estable y pequeño, el reloj está alineado y `device_t - physical` es
atribuible al sensor.

### 14.2 Robustez del consumer Pulsar (RF-54)

Archivo: `api/bin/tuya-pulsar-consumer/index.js`.

- **Reconexión** (RF-54.1): base `1000 ms` + jitter, backoff exponencial ×2 hasta `60000 ms`.
  El hueco del Reader `latest` se acorta; el resync cubre lo perdido.
- **Resync por hueco real** (RF-54.2): se registra `disconnectedAt` en `ws.on('close')`; en
  `ws.on('open')` se permite el resync si `now - disconnectedAt >= REAL_GAP_MS` (p. ej. 10 s)
  o si no hubo resync previo en `RESYNC_MIN_INTERVAL_MS`. Se mantiene el rate-limit para
  parpadeos, pero no bloquea la recuperación de un corte real.
- **Watchdog de silencio** (RF-54.3): si `knownDeviceIds.length > 0` y
  `now - lastMessageAt > SILENCE_MS` (por defecto 180 s, env `CONSUMER_SILENCE_MS`),
  `ws.terminate()` para forzar reconexión. Con 0 devices rastreados no actúa. Es una red de
  seguridad secundaria: la fuente primaria de reconexión es el `close` del WS.
- **Latencia** (RF-54.4.1): cada mensaje relevante registra `Date.now() - tuya_t` en ms.
- **Salud** (RF-54.4.2): escribe `api/run/pulsar-consumer-status.json`
  (`{connected, last_msg_at, known_devices, updated_at}`) tras cada mensaje/cambio de estado.
  Lo consume `system-status` sin endpoint nuevo y lo expone como campos **aditivos**
  (`ws_connected`, `ws_silent`, `status_reason`), **sin** alterar
  `healthy`/`degraded` (contrato F41 §5: `healthy === (instances === expected)`).
- **Sin cuota** (RF-54.5): el resync REST de `/status` es la única llamada IoT Core y ya
  existía (rate-limited); el watchdog y la reconexión no añaden llamadas.

### 14.3 Arranque consistente (RF-55)

- **Fuente única**: el valor canónico del API es `PHP_CLI_SERVER_WORKERS=16`
  (`cerraduras-api.service:11`). El fallback manual de `start-all.sh` debe usar 16.
- **Verificación**: tras `systemctl restart`, `start-all.sh` cuenta los procesos
  `php -S 0.0.0.0:8080` y avisa si el total de hijos ≠ 16. No aborta (el arranque ya ocurrió).
- **Motivo**: el fallback a 8 reintroducía la degradación de SSE de F46 (pool agotado por SSE
  zombie → panel a polling lento). Se documenta el acoplamiento.
- Opcional (mismo alcance): añadir `api-server` a `system-status` con `expected=16` para
  detectar la regresión como `degraded`.

### 14.4 Latencia del mecanismo de puerta (RF-56)

**Causa medida**: el backend responde en ~1 ms (`access_events` QR_VALIDATE y OPEN en el
mismo milisegundo); el retardo percibido está en el firmware ESP32.

- En modo LOCAL el chip acciona el relé tras el 200 de `/api/v1/qr/validate`. El `loop()` es
  secuencial, de modo que un QR escaneado durante la cadena de health/heartbeat/announce
  espera a que termine (TLS bloqueante + `delay(HTTP_GAP_MS)`).
- **Medición primero** (RF-56.1): instrumentar `scan→postMs` (del callback USB al inicio del
  POST) y conservar el `[QR] Validación HTTP %d (%lu ms)`.
- **Fix seguro** (RF-56.2): prioridad de QR. Si `hasPending`, el bucle procesa el QR antes de
  iniciar cualquier tarea TLS de mantenimiento. Cambio puro de orden del `loop()`, sin tocar
  la configuración TLS.
- **Causa medida (2026-09-17)**: con `http.setReuse(false)` y Apache `KeepAliveTimeout 5`, cada
  petición hace **handshake TLS completo (~1,8 s)** medido en el ESP32
  (`[QR] Validación HTTP 200 (2133 ms)`; el backend responde en ~1 ms). El log de Apache
  confirma peticiones consecutivas separadas ~2 s.
- **Fix keep-alive** (RF-56.4): `http.setReuse(httpReuseEnabled)` (ON) sobre el
  `WiFiClientSecure` dedicado al `loop()`, con salvaguardas obligatorias:
  `setTimeout(4)`, cierre de socket si hueco > `HTTP_IDLE_RESET_MS` (60 s), reintento único con
  conexión nueva en el path QR, log `reuse=0/1`, y `noteHttpResult()` que desactiva el reuse
  tras `HTTP_REUSE_FAIL_LIMIT` (3) fallos consecutivos. Servidor: `KeepAliveTimeout 75` y
  `MaxKeepAliveRequests 1000` en el vhost (Apache), de modo que el heartbeat cada 30 s
  mantiene la conexión viva.
- **Historial**: el reuso de TLS provocó cuelgues en el pasado. Por eso RF-56.3 sigue vigente
  como principio (no habilitar reuso *sin* salvaguardas) y RF-56.4 define las condiciones bajo
  las cuales sí se permite. El cliente SOLO se usa desde `loop()` (nunca desde callbacks USB),
  que era la causa raíz de la concurrencia que provocaba los panics.
- **No regresión**: el flujo LOCAL (chip acciona el relé tras el 200) se conserva intacto.

### 14.5 Estrategia de pruebas

- **Unidad (sin hardware, sin cuota)**: funciones puras nuevas del consumer
  (`shouldResync`, `silenceExceeded`) y del probe (`formatDelta`, parseo de marcas), ampliando
  `tests/Unit/tuya-pulsar-consumer.test.js` y añadiendo `tests/Unit/latency-probe.test.js`.
- **Arranque**: `bash start-all.sh` + comprobación de pool; `system-status` en verde.
- **Firmware**: medición manual con monitor serie (antes/después); no automatizable.
- **Regresión**: `bash bin/run-tests.sh` con 0 failures.
- **Prueba presencial**: protocolo de marcas físicas con el sensor de puerta como control.

### 14.6 F48 — Credibilidad de presencia por contexto (RF-57)

**Síntoma**: el sensor 24G V3 (PROTO2) marcaba `PRESENT` a 2–3 m e incluso desde el
pasillo, pese a tener `far_detection=150` aplicado (el propio device lo reporta por
push). La calibración **no** era el problema.

**Causa**: el push entrega `presence_state` como `none`/`presence`/`move`, y el
pipeline contaba `presence` **y** `move` por igual como `PRESENT`. En estos 24G la
detección de movimiento (`move`) tiene alcance propio, mayor que el rango gobernado
por `far_detection`, por lo que el tráfico del pasillo generaba `move` → `PRESENT`.

**Alcance**: la regla aplica a eventos **reales** (`provider = TUYA`). Las inyecciones
de desarrollo/tests (`/sim/*`, `provider = SIMULATED`) se respetan tal cual, para no
falsear la herramienta de simulación ni los tests.

**Decisión (única, pura, v2)**: `SensorEventDecision::decide()` acepta el contexto
(`entryWindowActive`, `insideNoExitCycle`) y aplica `presenceCredible()`:
- `entryWindowActive` = hay **apertura reciente** (`last_open_at` dentro de
  `presence_entry_window_seconds`) **y** la entrada **aún no está confirmada**
  (sin estancia activa o `entry_confirmed_at IS NULL`).
- `insideNoExitCycle` = estancia activa con `entry_confirmed_at` **y** sin apertura
  posterior (`last_open_at < entry_confirmed_at`).
- Una transición a `PRESENT` (`presence` o `move`) es creíble si
  `entryWindowActive || insideNoExitCycle`. Toda apertura posterior a la
  confirmación (ciclo de salida) **desactiva** el contexto: la presencia de pasillo
  (que en estos 24G no respeta `far_detection`) no puede re-afirmar el estado.
- `ABSENT` (`none`) siempre se aplica.

**Bug corregido (panel)**: `/live` (`RoomLiveController`) y el SSE
(`EventStreamController::fetchRecentPresence`) entregaban `recent_presence` **sin
filtrar `applied`**; los eventos descartados llegaban a la coreografía y
`freshPresenceAfterClose` cancelaba salidas. Ahora solo se exponen `applied = 1`
(`listForRoom(..., appliedOnly: true)`).

**Latencia percibida de la puerta (panel)**: `renderSensorSvg()` muestra la puerta
abierta desde el evento de **apertura del relé** (`access_events` `OPEN` OK) hasta el
primer `CLOSED` del magneto posterior, con timeout de 12 s. Así el usuario ve la
apertura al instante aunque el magneto Tuya tarde segundos en reportar.

**Dónde**: `IotSessionService::presenceContext()` (dentro de la transacción; lecturas
sin lock de estancia y tipo) → `decide()`.

**Trazabilidad**: cada descarte queda en `presence_events.discard_reason='no_context'`
(varchar(16)); sirve para auditar sin cuota Tuya.

**Trade-off asumido**: una apertura posterior a la confirmación invalida el contexto
de presencia hasta una nueva entrada acreditada. Es el precio de no poder distinguir
con distancia (el 24G no reporta `target_dis_closest`). El ajuste fino
(`sensitivity`/orientación) queda como opción cuando haya cuota.

**Tests**: `tests/Unit/SensorEventDecisionTest.php` (casos F48 v2, puros).

---

## 15. F50 — Estado real del SWITCH por push y robustez del consumer (RF-58)

### 15.1 SWITCH por push, sin cuota (Bug 2)

**Síntoma**: el panel no conocía el estado real de la luz (relé EAWCBT-J); solo el
`last_command`/`commanded_at` que escribía `TuyaSwitchGateway` al comandar. Si un comando
no se aplicaba o la luz cambiaba por otra vía, el panel mentía.

**Decisión**: rastrear el SWITCH por el mismo canal push que la puerta/presencia.

- `api/bin/tuya-pulsar-consumer/index.js`:
  - `TRACKED_KINDS = ['PROXIMITY','PRESENCE','SWITCH']` (el consumer lo reenvía).
  - `RESYNC_KINDS = ['PROXIMITY','PRESENCE']`; `loadResyncDevices()` da la lista del resync
    REST. El resync **no** sondea el SWITCH → cero cuota IoT Core.
  - `mapToPresenceEvent()` no cambia: para el SWITCH devuelve `null` y se reenvía el payload
    crudo, como ya hacía.
- `api/src/Infrastructure/Gateways/Sensor/TuyaSensorIngress.php`:
  - Si `$device->kind === Device::KIND_SWITCH`, busca un DP `switch`/`switch_1`
    (case-insensitive); `true`/`'true'`/`1`/`'1'` → `ON`, resto → `OFF`.
  - Persiste merge sobre `$device->meta`: `switch_state` y `switch_state_at` (`tsToIso($t)`)
    vía `deviceRepo->update($id, ['meta_json' => json_encode(...)])`.
  - Devuelve un `_noop` con `meta.discard_reason='switch_state'` → el webhook responde 202 y
    el evento no entra en `IotSessionService`.
  - No toca el pipeline de `PROXIMITY`/`PRESENCE`.
- Panel: `RoomLiveController` y `EventStreamController::fetchSwitchState` añaden
  `state` (`meta.switch_state ?? 'UNKNOWN'`) y `state_at` (`meta.switch_state_at ?? null`),
  aditivos junto a `last_command`/`last_error`.

**Interacción con comandos**: `TuyaSwitchGateway::persistMeta()` hace merge sobre el meta
existente, por lo que no borra `switch_state`; el push tampoco borra `last_command`. Ambos
conviven (comando = intención; estado = realidad).

### 15.2 Robustez del consumer (Bug 5, N10)

- **Watchdog largo**: `CONSUMER_SILENCE_MS` default `900000` (15 min). El ping proactivo de
  30 s ya detecta sockets muertos; el silencio en reposo (packs solo-puerta) es normal. El
  backstop corto (180 s) reconectaba en reposo (churn ~9k cierres) y abría huecos donde se
  perdían eventos de puerta.
- **`last_pong_at`**: `ws.on('pong')` deja de ser no-op y registra el instante; se expone en
  `api/run/pulsar-consumer-status.json` (aditivo). **No** decide reconexión (no está probado
  que Tuya responda siempre con pong).
- **DNS / error sin `close`**: `scheduleReconnect(reason)` (guardas `reconnectTimer` +
  `reconnectScheduled`) se usa desde `close` y `error`. Un `getaddrinfo EAI_AGAIN` que no
  emite `close` ya no deja al consumer muerto. Se conserva el backoff exponencial base 1 s.
  El cierre de un socket obsoleto (`ws !== currentWs`) se ignora para no duplicar conexiones.
- **`room_not_found` (N10)**: el ingress, sin sala, devuelve `_noop`
  (`discard_reason='room_not_found'`, log `dev`/`kind`) y el webhook responde 202. Además,
  `TuyaWebhookController` captura `NotFoundException` de `processEvent` → 202
  `{accepted:false, discard_reason:'room_not_found'}`; el 500 queda para errores inesperados.
  Así un push no se pierde por una sala transitoriamente no resuelta.

### 15.3 Pruebas

- **Unit JS** (`tests/Unit/tuya-pulsar-consumer.test.js`, sin red ni cuota):
  `TRACKED_KINDS` incluye `SWITCH`; `RESYNC_KINDS` no; `pongAgeMs`; backstop 15 min.
- **Unit PHP** (`tests/Unit/TuyaSwitchIngressTest.php`, fake repo): `switch=true`→ON con
  `switch_state_at`; `switch_1=false` (case-insensitive)→OFF; sala nula → `_noop`
  `room_not_found`; no regresión de `PRESENCE`.
- **Regresión**: `bash bin/run-tests.sh` con 0 failures (BLOCK correspondiente de la fase).

---

# Fase 51: Ciclo de vida del QR de huésped (Bug 4)

## 16.1 Modelo de datos (migración 0114)

`qr_credentials` añade dos columnas idempotentes (`ADD COLUMN IF NOT EXISTS`):

| Columna | Tipo | Significado |
|---------|------|-------------|
| `first_used_at` | `DATETIME(3) NULL` | Instante del primer escaneo. `NULL` = nunca usado. |
| `valid_until` | `DATETIME(3) NULL` | `first_used_at + stays.duracion_minutos`. `NULL` = aún sin usar. |

Backfill (idempotente, solo filas con `first_used_at IS NULL`):

```sql
UPDATE qr_credentials qc JOIN stays s ON s.id = qc.stay_id
   SET qc.first_used_at = qc.consumed_at,
       qc.valid_until   = DATE_ADD(qc.consumed_at, INTERVAL s.duracion_minutos MINUTE)
 WHERE qc.consumed_at IS NOT NULL AND qc.first_used_at IS NULL;
```

`consumed_at` se conserva y queda sincronizada con `first_used_at` como marca del primer uso
(auditoría / compatibilidad con consultas y tests previos).

## 16.2 Lógica pura: `QrWindows`

`api/src/Domain/Qr/QrWindows.php` encapsula la decisión de ventana sin BD ni reloj:

```php
QrWindows::evaluate($issuedAt, $firstUsedAt, $validUntil, $duracionMin, $arrivalMin, $now)
// → STATE_OK_UNUSED | STATE_EXPIRED_ARRIVAL | STATE_OK_IN_USE | STATE_EXPIRED_USAGE
```

- Sin uso (`firstUsedAt === null`): expira si `now > issuedAt + arrivalMin*60`.
- Con uso: deadline = `validUntil ?? firstUsedAt + duracionMin*60`; expira si `now > deadline`.
- Comparación estricta `>`: justo en el deadline aún es válido (caso límite cubierto en tests).

El panel reutiliza el mismo helper (`RoomLiveController::fetchQrStatus`,
`EventStreamController::fetchQrStatus`) para que el `expired` mostrado coincida con el backend.

## 16.3 Emisión (sello del token)

El token HMAC **no** se puede re-firmar, así que `exp` cubre ambas ventanas:

```
exp = iat + (QR_ARRIVAL_WINDOW_MINUTES + duracion_minutos) * 60
```

Unificado en `QrIssueService`, `QrTestController::create/doCreate` (y, por delegación,
`QrController`; `api/bin/make-reservation-qr` emite vía `POST /api/v1/qr`, sin cálculo local).
`room_type.qr_usage_window_minutes` queda deprecado para el QR de huésped.

## 16.4 Validación

`QrValidateService::validate()` carga el `Stay` en el **paso 4** (necesita `duracion_minutos` y
reutiliza la instancia en el paso 8). Nota: un stay ausente/desajustado ahora devuelve el 404
`not_found` antes de los checks de room/device/cooldown (antes iba al final). El antiguo paso
"consumed / S11" se sustituye por:

1. No usado + fuera de llegada → 403 `qr_expired` `{window:'arrival'}`.
2. No usado + dentro → se permite; el primer uso se reclama **al final** del flujo, tras pasar
   todos los checks (device, cooldown, estado del stay), para no quemar el QR en un intento
   rechazado.
3. Usado + `valid_until` (o `consumed_at + duracion` si es `NULL`) `< now` → 403 `qr_expired`
   `{window:'usage'}`.
4. Usado + dentro → reentrada permitida; el stay sigue validándose `RESERVED | OCCUPIED`.

El reclamo atómico es `QrCredentialRepository::markFirstUse($jti, $validUntilUtc)`
(`UPDATE ... SET first_used_at=UTC_TIMESTAMP(3), consumed_at=UTC_TIMESTAMP(3), valid_until=:v
WHERE jti=:j AND consumed_at IS NULL AND revoked_at IS NULL`). Solo el ganador transiciona
`RESERVED → OCCUPIED`.

El chequeo del `exp` sellado (`QR_EXP_FROM_DB=false`) se mantiene; con el nuevo sello no bloquea
dentro de ninguna de las dos ventanas. El modo `QR_EXP_FROM_DB=true` sigue usando `expires_at`.

## 16.5 Panel

`qr_status` (en `/live` y en el SSE) recalcula `expired` con `QrWindows` y expone de forma aditiva
`first_used_at`, `valid_until`, `arrival_deadline` e `in_use`. `consumed` y `scannable` conservan su
semántica previa. El `qr_text` reconstruido sigue usando `issued_at`/`expires_at`.

## 16.6 Pruebas

- `tests/Unit/QrArrivalWindowTest.php`: lógica pura `QrWindows` (4 casos + límites + fallback
  legacy + `valid_until` autoritativo).
- `tests/Unit/QrValidateServiceTest.php`: primer uso fija `first_used_at`/`valid_until`; reentrada;
  uso fuera de ventana → `qr_expired`.
- `tests/Unit/QrIssueServiceTest.php`: `exp - iat == (arrival + duración)`.

---

# Fase 52 — Cola muerta del outbox (N6, RF-60)

## 17.1 Problema y decisión

El `outbox_vb6` usaba un único estado terminal `FAILED` para dos situaciones muy distintas:
(a) fallos **transitorios** que agotaron 20 reintentos y (b) **venenos** (4xx de WS-VB6) que nunca
serán aceptados. `HealthController::deep` contaba todo `FAILED` con `updated_at` antiguo como
`outbox_failed` y marcaba `overall.status='degraded'`; como el mensaje veneno es permanente, el
sistema quedaba `degraded` de forma indefinida.

**Decisión**: separar ambos casos con un estado terminal `DEAD` (cola muerta). `DEAD` es visible
(health + panel) pero informativo: no degrada el servicio. Los `FAILED` se reservan para fallos
reintentables recientes.

## 17.2 Modelo de datos (migración `0115_outbox_dead_letter.sql`)

```sql
ALTER TABLE `outbox_vb6`
    MODIFY `status` ENUM('PENDING','SENDING','SENT','FAILED','DEAD') NOT NULL DEFAULT 'PENDING';

UPDATE `outbox_vb6`
   SET `status` = 'DEAD'
 WHERE `status` = 'FAILED' AND `attempts` >= 20 AND `last_error` LIKE '%client_error%';
```

- El `MODIFY` es seguro de repetir.
- El `UPDATE` solo reclasifica venenos aún en `FAILED`; tras la primera pasada no afecta a más filas.
- `idx_outbox_due(status, next_attempt_at)` sigue sirviendo: `fetchDue` únicamente lee `PENDING`.

## 17.3 Repositorio (`OutboxVb6Repository`)

| Método | Antes | Ahora |
|--------|-------|-------|
| `markPermanentlyFailed()` | `status='FAILED'` | `status='DEAD'` |
| `markFailed()` (techo) | `nextAttempts >= 20 → 'FAILED'` | `nextAttempts >= 20 → 'DEAD'` |
| `scheduleRetry()` | `status IN ('PENDING','FAILED')` | `status IN ('PENDING','FAILED','DEAD')` |

`fetchDue()` no cambia: `DEAD` nunca se procesa automáticamente.

## 17.4 Health (`GET /health/deep`)

- `outbox_failed`: sin cambios — `COUNT` de `FAILED` con `updated_at < NOW()-INTERVAL 1 HOUR`,
  `warning` si `> 0` y **degrada** (`allOk=false`).
- `outbox_dead` (nuevo): `COUNT` de `DEAD`; `status='ok'` si `0`, `'warning'` si `> 0`; `count` y
  `note` (`"<N> mensajes en cola muerta; reintentar desde el panel"`). **No** toca `$allOk`.
- El contrato es aditivo: consumidores antiguos siguen leyendo `outbox_failed` igual.

## 17.5 Recuperación manual (`POST /admin/outbox/{id}/retry`)

`retryOutbox` no filtra por status, así que ya reencola cualquier fila (`WHERE id = :id`); se
documenta explícitamente que `DEAD` es reencolable (`status='PENDING'`, `attempts=0`,
`last_error=NULL`). `listOutbox` añade `dead` al `summary` de estados para visibilidad.

## 17.6 Riesgos y regresiones

- **No romper `/health/deep`**: `outbox_dead` es una clave nueva; `outbox_failed` conserva su
  semántica.
- **No reintroducir degradación permanente**: el bloque `outbox_dead` **no** debe asignar
  `$allOk = false`. Cubierto por `tests/Unit/OutboxDeadLetterTest.php`.
- **No procesar `DEAD` por el worker**: `fetchDue` filtra `PENDING`.
- **Enum**: si una migración previa no hubiese aplicado `0014`, el `MODIFY` fallaría; en este
  proyecto `0014` es la base y siempre precede a `0115`.

## 17.7 Pruebas

- `tests/Unit/OutboxDeadLetterTest.php` (script plano, autodescubierto en BLOCK 1): guardas de
  fuente para (a) `DEAD` en `markPermanentlyFailed` y en el techo de `markFailed`, (b) `scheduleRetry`
  incluye `DEAD`, (c) `HealthController` expone `outbox_dead` sin tocar `$allOk`, (d) existencia y
  contenido de la migración `0115`.

---

# Fase 54 — Batería de aceptación E2E (BLOCK 42, RF-61)

## 18.1 Problema y decisión

La suite valida capacidades por bloques, pero **nadie recorre el ciclo completo de un huésped con
estado compartido**. Un fallo de integración (que la salida F49 no dispare tras una entrada F42, o
que el QR F51 no transicione RESERVED→OCCUPIED antes de la coreografía) no se detecta si cada
bloque se prueba por separado con su propio setup.

**Decisión**: un único bloque secuencial `BLOCK 42` en `api/bin/run-tests.sh` que encadena los
endpoints reales y `/sim/*` sobre **PROTO2** (`rooms.id=12`, pack `305`, RPI `a0e549858428`),
compartiendo `stay_id`/`jti`/`qr_text` entre pasos.

## 18.2 Por qué un bloque secuencial y no tests sueltos

- El valor es la **secuencia**: emitir → validar → entrar → salir → cerrar. Cada paso depende del
  estado dejado por el anterior (`stay_id`, `first_used_at`, `entry_confirmed_at`, `last_absent_since`).
- Un test aislado tendría que recrear el preámbulo entero, duplicando setup y volviendo el fallo
  más difícil de localizar. En secuencia, un rojo señala el eslabón exacto.
- Se mantiene el estilo del runner (helpers `pass/fail/skip/http_test`, `get_key`, `$MYSQL`).

## 18.3 Por qué PROTO2 y por qué `/sim/*` + API real

- PROTO2 es la sala de banco del proyecto (pack `proto2`, room_type STANDARD con franja RENTABLE
  24/7), ya usada por BLOCK 32/35; es el entorno donde el usuario realiza las pruebas físicas.
- La puerta y la presencia **reales** dependen de Tuya/ESP32 (no deterministas y con cuota).
  `/sim/*` inyecta eventos `provider=SIMULATED`.
- Con `rooms.simulated_override=1`, `LockGatewayFactory` (regla 1) resuelve
  `SimulatedLockGateway` y `SensorIngressFactory` el ingress simulado: **no** se acciona el relé
  real ni se consume cuota. El resto del recorrido usa la **API real** (`POST /api/v1/qr`,
  `POST /api/v1/qr/validate`, `GET /live`, `POST /stays/{id}/close`, `GET /stays/{id}/overstay`)
  y la BD real, de modo que cubre los contratos de producción.

## 18.4 Estado compartido entre pasos

- **No existe `POST /api/v1/stays`** (solo `GET`): la estancia `RESERVED` la crea `POST /api/v1/qr`
  (`QrIssueService::issue()`), que devuelve `stay_id`, `jti`, `qr_text`, `issued_at`, `expires_at`.
- `POST /api/v1/qr/validate` reclama el primer uso (`first_used_at`/`valid_until`) y transiciona
  RESERVED→OCCUPIED de forma atómica (`markFirstUse`); precondición de la coreografía.
- La entrada se consolida con un ciclo de puerta acreditado + presencia (`entry_confirmed_at`). El
  orden probado (BLOCK 22) es `PRESENT → OPEN → CLOSED`.
- La salida exige un ciclo de puerta **posterior** a `entry_confirmed_at` (`OPEN → CLOSED`) y luego
  `ABSENT` (RF-47). Entonces `/live` emite `exit_deadline = last_absent_since + exit_guard_seconds`
  (`exit_guard_seconds` = `rooms.presence_check_seconds` > `EXIT_ABSENCE_GUARD_SECONDS` > 3).
- La confirmación la hace `bin/exit-scan.php` (supervisado por systemd con tick 2 s,
  `ExitActionService::executeIfPending` idempotente). El stay pasa a `EXITED` y la sala a `FREE`.
- `POST /stays/{id}/close` (ADMIN-CLI) lleva `EXITED→CLOSED` y encola `stay.closed` en `outbox_vb6`.

## 18.5 Limpieza y restauración

`BLOCK 42` es el último y deja el banco limpio. Patrón:

1. Guardar `simulated_override` y `presence_check_seconds` de PROTO2 (`E2E_SAVE_*`).
2. Normalizar: cerrar estancias activas (SQL) + borrar `presence_events`/`iot_sessions` de la sala
   + fijar `pack_id`, `simulated_override=1`, `presence_check_seconds=5`, `status='FREE'`,
   `cooldown_until=NULL`; después `POST /dashboard-api/rooms/reset`.
3. Cleanup final (éxito o fallo, `_e2e_cleanup`): cerrar su estancia, borrar sus
   `presence_events`/`iot_sessions`, restaurar `simulated_override`/`presence_check_seconds`, dejar
   la sala en `FREE` sin `cooldown_until`, y matar solo el `exit-scan` propio. Se conservan
   `access_events`/`qr_credentials` como auditoría (patrón BLOCK 22).
4. No se usa `trap EXIT` para no pisar el `trap _cleanup_factory_test EXIT` de F40; el cleanup se
   invoca explícitamente al final del bloque.

> **PROTO2 queda en `FREE`**, no se restaura el `status='RESERVED'` con estancia `OVERSTAY` que
> pudiera existir antes de la corrida: la sala es un banco de pruebas y se normaliza deliberadamente.

## 18.6 SKIP vs FAIL

**SKIP** (con motivo), nunca FAIL, cuando falta una precondición del entorno: servidor HTTP caído;
claves `SIM-CLIENT`/`VB6-MAIN`/`RPI-DEV`/`ADMIN-CLI` ausentes; BD no accesible; PROTO2 sin pack/RPI
resoluble; `POST /qr` ≠ `201`. **FAIL** solo ante una violación real de contrato/estado.

## 18.7 Riesgos de regresión y mitigación

- **Push real de presencia**: el sensor 24G (`presence_source='push'`) podría alterar el estado si
  alguien se mueve en la sala durante la corrida. La sala está en reposo durante el test; riesgo
  bajo. No se llama a la nube ni al poller.
- **`POST /qr` ocupado/no rentable**: se normaliza antes (SQL + reset); si aun así ≠ 201 → SKIP.
- **Resolución del RPI**: `/qr/validate` rechaza `device_mismatch` si el `device_id` no pertenece a
  la sala; se resuelve por SQL desde `rooms.pack_id` y se SKIP si no existe.
- **`exit-scan` concurrente**: puede haber varias instancias; `executeIfPending` garantiza una sola
  transición. Se reutiliza el de systemd si existe; solo se arranca/mata uno propio en su defecto.
- **Tiempo**: el sondeo de salida tiene timeout de 25 s; el bloque no cuelga la suite.
- **Ruido en `outbox_vb6`**: `POST /stays/{id}/close` encola `stay.closed` (efecto real de
  contrato); no degrada `health/deep`.

## 18.8 Pruebas (este bloque es la prueba)

- Unitarias puras (F42/F49/F51) ya cubren la lógica; `BLOCK 42` cubre la **integración**.
- Criterio de aceptación: `bash bin/run-tests.sh` con **0 failures** y `BLOCK 42` en verde (o SKIP
  justificado por entorno).
- Guardas de no regresión: los 297 passed previos no deben caer; el bloque solo añade PASS/SKIP.

---

# Fase 55 — Panel de aceptación manual `/pruebas` (RF-62)

## 19.1 Problema y decisión

La aceptación de hardware (escaneo físico, relé, sensor de puerta, presencia, switch) no se puede
automatizar sin hardware ni cuota. Ejecutarla desde un documento hace perder el estado entre
pruebas y no deja traza comparable. **Decisión**: una página vanilla en `GET /pruebas`
(`api/public/pruebas.html`) que consume un catálogo JSON versionado y persiste cada corrida como
un fichero JSON en `api/run/acceptance/` (gitignored), siguiendo el patrón de `/dashboard`,
`/simula` y los `dashboard-api/*` sin auth (LAN/MVP).

## 19.2 Arquitectura

| Capa | Artefacto | Responsabilidad |
|------|-----------|-----------------|
| Catálogo | `public/assets/acceptance-tests.json` | 53 pruebas + bloques (datos, versionado) |
| Lógica pura | `public/assets/acceptance-logic.js` (UMD) | `summarize`, `isGreen`, `nextPending`, `nextOpen`, `progressCells`, `diffRuns`, `toMarkdown` |
| UI | `public/pruebas.html` | marcado + CSS (tema oscuro del dashboard), sin build |
| Controlador | `public/assets/acceptance-app.js` | estado, render, autosave, captura, navegación |
| Rutas | `public/index.php` | `GET /pruebas` + `dashboard-api/acceptance/{save,list,get,delete}` |

Sin frameworks ni build: coherente con `dashboard.html` y `cal-walktest.js`.

## 19.3 Datos

**Catálogo** (`version`, `room_id`, `room_label`, `blocks[]`, `tests[]`). Tipos de prueba:
`shell`, `nav`, `fisico`, `mixto`.

**Corrida** `api/run/acceptance/<run-id>.json`:
`run_id`, `operator`, `room_id`, `commit`, `started_at`, `updated_at`, `revision`, `config`
(`naCountsAsGreen`, `naRequiresNote`), `tests` (`{id:{status,notes,snapshot}}`) y `summary`
calculado en el servidor (`pass/fail/na/pending/total/green`).

## 19.4 Persistencia y seguridad

- `run_id` se valida con whitelist `^[A-Za-z0-9_-]{1,64}$` → evita path traversal.
- Escritura atómica: `file_put_contents(<path>.tmp)` + `rename`, con `LOCK_EX`.
- El directorio `api/run/` está en `.gitignore`: **git = código, no datos**.
- Sin auth (coherente con el resto de `dashboard-api`); no expone secretos ni credenciales.

## 19.5 UX (resumen)

Modelo dual *Foco* (móvil, una prueba) / *Panel* (escritorio, índice+detalle). Tira de progreso de
53 celdas, contadores por estado, "siguiente pendiente", auto-avance configurable con **Deshacer**,
barra de veredictos fija (objetivos ≥56 px), captura de estado con feedback (idle/loading/ok/parcial/
error), autosave con indicador y cola offline en `localStorage`, reanudar/lista/comparar corridas,
export JSON/Markdown e impresión. Estado nunca solo por color. Atajos `P/F/N/U`, `J/K`, `S`, `C`.

## 19.6 Riesgos y mitigación

- **Página densa** → modelo dual, índice con bloques, evidencia colapsada.
- **Pérdida de anotaciones en campo** → autosave + `localStorage` + Deshacer.
- **`N/A` perezoso** → nota obligatoria, gris (nunca verde), contador propio y toggle estricto.
- **Escritura sin auth** → whitelist de `run_id` + directorio fijo + escritura atómica.
- **Catálogo roto** → estado de error explícito; no bloquea el resto de la app.

## 19.7 Pruebas

- `tests/Unit/acceptance-logic.test.js` (Node, autodescubierto en BLOCK 1) cubre la lógica pura.
- `BLOCK 43` del runner: catálogo (53 ids únicos), `GET /pruebas`, y ciclo
  save/get/list/delete + validación de `run_id` inseguro y 404.
- Criterio de aceptación: `bash bin/run-tests.sh` con **0 failures** y `BLOCK 43` en verde.

---

# 20. F56 — La puerta del croquis sigue al sensor físico (RF-63)

## 20.1 Síntoma

Al escanear un QR válido, el croquis del panel abría la puerta **en el mismo instante** del
escaneo, 5–7 s antes de que la puerta se abriera físicamente.

## 20.2 Causa raíz

- Backend correcto: al validar el QR se escriben `QR_VALIDATE OK` y `OPEN OK` (comando al
  relé) en `access_events`; `door_state` **no** se falsea (`QrValidateService.php`, F28).
- Panel: `renderSensorSvg()` construía `optimisticOpen` desde `recent_events` (`kind=OPEN`,
  `result=OK`) y lo sumaba a `doorOpen` (`dashboard.html`, TSK-F48-05/RF-57.5). El comando del
  relé liberaba el pestillo, pero la puerta seguía cerrada hasta el empujón físico.
- Evidencia (sala 12): `QR_VALIDATE OK` 07:32:44.643 + `OPEN OK` 07:32:44.644 vs
  `PROXIMITY OPEN` 07:32:50.000 → 5,4 s de puerta "abierta" en pantalla sin apertura real.

## 20.3 Decisión (F56/RF-63)

1. Se elimina la apertura optimista por comando. El comando del relé **no** es entrada de la
   decisión visual de la puerta.
2. El feedback del acceso es el **pestillo en verde** en `QR_OK` (ya existente) y el toast.
3. Se conserva el **pulso anti-colapso** (`_doorPulseUntil`, `DOOR_PULSE_HOLD_MS = 1200 ms`)
   derivado de `PROXIMITY OPEN` **aplicado**: cubre `OPEN`+`CLOSED` colapsados en el mismo
   ciclo SSE sin inventar apertura.
4. La regla queda pura y testeable: `Choreography.resolveDoorOpen(doorState, pulseUntilMs, nowMs)`
   (`assets/choreography.js`), con fallback inline en el panel por si el navegador conserva una
   caché antigua del asset.

## 20.4 Qué NO cambia

- `/live` y SSE (contratos y campos intactos), coreografía del monigote (usa `io.door_state`
  real), regla de salida, anomalías, toast y panel QR (EN USO), contadores de debug.
- La luz sigue encendiéndose con la apertura física (`IotSessionService` post-commit sobre
  `PROXIMITY OPEN`), que era el comportamiento de F28.
- Sin cuota Tuya: todo ocurre sobre push/SSE existentes.

## 20.5 Trade-off asumido

Entre el escaneo y la apertura física el panel muestra la puerta cerrada (típico 5–7 s). Es el
precio de no contradecir el sensor; el pestillo verde cubre el feedback inmediato. Se acepta
frente a la alternativa (F48) de mentir sobre el estado físico.

## 20.6 Pruebas

- `tests/Unit/choreography.test.js` (BLOCK 33): casos F56 de `resolveDoorOpen` (CLOSED sin
  pulso → cerrada aunque exista comando; OPEN real → abierta; pulso vigente/expirado).
- `acceptance-tests.json` (P20): criterio manual actualizado — pestillo verde y puerta cerrada
  hasta la apertura física.
- Regresión: `bash bin/run-tests.sh` con **0 failures**.

---

# 21. F57 — Verificación de salida visible y coherente (RF-64)

## 21.1 Síntoma

Con el huésped dentro (`OCUPADA`), al abrir y cerrar la puerta:
1. la representación tardaba 4–5 s en reaccionar (monigote y, percibido, la puerta);
2. el monigote quedaba con `?` pero **sin timer**;
3. después saltaba de dentro a verificando/fuera de forma extraña.

## 21.2 Causa raíz

- **Timer ausente (estructural)**: `updateCountdown()` exigía `exit_deadline` +
  `presence_state = ABSENT` (`dashboard.html`). El backend solo emite `exit_deadline` cuando ya
  hay `ABSENT` (`last_absent_since + exit_guard_seconds`; `RoomLiveController` /
  `EventStreamController`). En la ventana real (puerta recién cerrada, presencia aún `PRESENT`
  o `UNKNOWN`) no había deadline ⇒ el timer no podía aparecer.
- **Decisión tardía**: `T11` esperaba un `PRESENT` **aplicado después del cierre** con un hold de
  `exit_guard + 3 = 6 s`; el radar aplica `PRESENT` cada 4–8 s y reporta `ABSENT` 13–40 s después
  del cierre (evidencia sala 12: cierre 07:58:05 → ausencia 07:58:45 → EXITED 07:58:48). De ahí
  los 4–5 s sin reacción y los saltos dentro→verificando→fuera.
- **Arco descuadrado**: el total del arco de salida usaba `gap_seconds` (15 s) mientras el
  deadline real es `exit_guard_seconds` (3 s).

## 21.3 Decisión (F57/RF-64)

1. **Ventana única de verificación de salida**: al cerrar un ciclo acreditado, el episodio fija
   `exitVerifyUntil = last_close_at + 20 s` (`DEFAULT_EXIT_VERIFY_SECONDS`, nunca menor que
   `exit_guard_seconds + 3`) y la coreografía devuelve **siempre** `VERIFICANDO` con `?`.
2. **Timer visible en todos los estados de presencia**: `updateCountdown()` lo pinta en
   `VERIFICANDO_PRESENCIA` anclado a `exitVerifyUntil`; si el backend emite `exit_deadline`
   (ausencia + guarda), **manda el deadline** y puede cortar la ventana.
3. **Espera prudencial completa**: un `PRESENT` nuevo durante la ventana **no** cancela; el `?`
   se mantiene hasta agotar la ventana y entonces el monigote vuelve dentro (decisión del
   usuario). Al agotarse sin `exit_deadline`, se limpia el episodio (`DENTRO`).
4. **Fuera solo con confirmación del dominio**: al llegar a 0 el panel llama a `resyncLive()`;
   la salida (`SALIDA_CONFIRMADA`) la decide el backend (`stay = EXITED`). No se inventa.
5. **Sin tocar entrada, puerta/pestillo, luz, anomalías ni regla de salida**.

## 21.4 Diagnóstico pendiente (puerta del croquis)

El retardo percibido de la **puerta** no se explica por el panel: `renderSensorSvg()` la pinta
de `io.door_state` en cada evento y el SSE emite en ≤200 ms tras el cambio en BD (evidencia:
`CLOSED` recibido 0,3–1,3 s tras el sello de Tuya). Protocolo de medida (sin cambios de código):

1. Abrir `/dashboard?debug=1` en la misma pestaña (badge con `door age`, `state age`,
   `door_changes`).
2. Cerrar la puerta físicamente y anotar el `door age` en el momento en que el croquis cierra.
   - ~1 s → el panel va bien; el retardo percibido era la decisión del monigote (resuelto aquí).
   - 4–5 s → cruzar con `presence_events.received_at − occurred_at` y `[LAT] recv-tuya_t` del
     consumer para separar Tuya ↔ consumer ↔ API; si el sello de Tuya llega tarde (físico→cloud),
     se documenta y se decide fase aparte (no se parchea el panel a ciegas).

## 21.5 Pruebas

- `tests/Unit/choreography.test.js` (BLOCK 33): cierre → `VERIFICANDO` con `exitVerifyUntil`
  (+20 s); `PRESENT` a +5 s sigue verificando y a +21 s → `DENTRO`; `ABSENT` con deadline →
  conteo del backend; reapertura re-arma la ventana; `UNKNOWN` al final → `DENTRO`.
- `acceptance-tests.json` (P35): el croquis muestra `?` + timer durante la verificación.
- Regresión: `bash bin/run-tests.sh` con **0 failures**.

---

# 22. F58 — Orden por milisegundos de los eventos de sensor (RF-65)

## 22.1 Síntoma

Con el huésped dentro, al abrir y cerrar la puerta, el croquis mostraba el cierre **a veces**
con 4–5 s de retardo. El pipeline y el panel estaban descartados: `recv-tuya_t` 111–412 ms,
`received_at − occurred_at` 0,1–1,3 s, SSE ≤200 ms y la puerta se pinta de `door_state` (el
pulso anti-colapso retiene como máximo 1,2 s).

## 22.2 Causa raíz

- Tuya entrega el sello del DP en **milisegundos** (`status[].t`, 13 dígitos), pero
  `TuyaSensorIngress::tsToIso()` hacía `(int)($ts/1000)` y formateaba **sin fracción**
  (el `tuya_t` en ms solo sobrevivía en `meta_json`).
- `SensorEventDecision::decide()` comparaba `occurred_at` con `getTimestamp()` (segundos): la
  guarda `stale` (`<` estricto) no podía distinguir dos eventos del mismo segundo.
- El consumer reenvía los mensajes con `ws.on('message', async …)` sin serializar; el API
  serializa por sala con `FOR UPDATE`, pero el **orden de adquisición del lock no es FIFO**.
  Una pareja OPEN+CLOSED del mismo segundo podía aplicarse invertida.
- Resultado: tras un cierre físico, `door_state` podía quedar `OPEN` (y `last_close_at` sin
  actualizar respecto a `last_open_at`) hasta el **siguiente** evento de puerta (3–5 s en los
  ciclos de prueba). Evidencia: 18 respuestas históricas con OPEN aplicado tras CLOSED del
  mismo segundo (`last_close_at == last_open_at`); última 2026-09-29 07:22:33.

## 22.3 Decisión (F58/RF-65)

1. `TuyaSensorIngress::tsToIso()` conserva ms (`Y-m-d\TH:i:s.v\Z`); acepta 10 dígitos (s) como
   fallback. Función pura y testeable.
2. `SensorEventDecision::toEpoch()` compara en **ms**; `fingerprint()` sigue floors a segundo
   (idempotencia de reenvíos intacta).
3. `IotSessionService::isoToMysqlUtc()` y `PresenceEventRepository::toMysqlUtc()` aceptan
   ISO-8601 con fracción (las columnas ya son `DATETIME(3)`; **sin migración**).
4. Con esto, un OPEN que llegue tarde y cuyo sello sea anterior al CLOSED ya aplicado se marca
   `stale` y **no revierte** el cierre; una secuencia legítima cierre→reapertura (sellos
   ascendentes) se aplica igual.

## 22.4 Qué NO cambia

- Forma de contratos, rutas y códigos; panel (F56/F57), coreografía, regla de salida, luz,
  anomalías, workers ni cuota. Los eventos `SIMULATED` no se ven afectados.

## 22.5 Verificación de campo (si algún cierre tardase)

1. `?debug=1` en el panel: al cerrar, `door age` debe resetear en ~1 s.
2. Si no: `SELECT value, occurred_at, applied, discard_reason,
   JSON_UNQUOTE(JSON_EXTRACT(meta_json,'$.tuya_t')) FROM presence_events
   WHERE room_id=12 AND sensor='PROXIMITY' ORDER BY id DESC LIMIT 6`.
   Diferencia esperada: la pareja completa con `tuya_t` ascendente y el OPEN descartado
   (`applied=0, discard_reason='stale'`) si llegó invertido.
3. Si apareciera una inversión con `tuya_t` **también invertido** (es decir, el sello de Tuya
   fuese hora de nube y no del dispositivo), se escalaría a serializar el consumer y/o usar una
   secuencia del dispositivo (fase aparte; no se parchea a ciegas).

## 22.6 Pruebas

- `tests/Unit/SensorEventDecisionTest.php`: mismo segundo, OPEN con ms anterior al CLOSED
  aplicado → `stale`; OPEN con ms posterior → `apply`.
- `tests/Unit/TuyaSensorIngressTest.php` (nuevo): `tsToIso` conserva ms y acepta segundos.
- `BLOCK 33` del runner: caso de llegada invertida del mismo segundo vía `/sim` → gana CLOSED
  y el OPEN queda `applied=0/stale`; y el caso ascendente legítimo.
- Regresión: `bash bin/run-tests.sh` con **0 failures**.

---

# 23. F59 — Un QR nuevo limpia el ciclo anterior (RF-66)

## 23.1 Síntoma

Tras una prueba completa (salida confirmada, monigote fuera), al pulsar "generar un nuevo QR"
en el dashboard el monigote **saltaba dentro de la habitación** (`ANTI_REENTRADA`, con bombilla
encendida).

## 23.2 Causa raíz (evidencia)

- 09:13:48 salida confirmada (stay EXITED) → `ExitActionService` fija anti-reentrada:
  `rooms.cooldown_until = +20 s`.
- 09:13:58 el log de API registra `POST /dashboard-api/qr-test/create 201` (sin `rooms/reset`):
  la ruta de creación **no limpia** el cooldown ni el estado IoT heredado.
- La coreografía evalúa `if (s.cooldown && (roomStatus OCCUPIED || RESERVED))` **antes** del
  grupo RESERVED/QR (`choreography.js`), así que con el nuevo stay RESERVED y el cooldown activo
  devuelve `ANTI_REENTRADA`, cuyo estado UI tiene `moni:'INSIDE'` (`dashboard.html`).
- Reproducción pura: con `cooldown=true` → `ANTI_REENTRADA` (INSIDE); con `cooldown=false` →
  `QR_DISPONIBLE` (NEAR_QR, fuera).
- **Detonante de UI**: tras una salida, `qr_status` solo considera estancias RESERVED/OCCUPIED
  (`RoomLiveController::fetchQrStatus`), el panel queda "Sin QR activo" y el único botón visible
  es "➕ Crear QR" (la ruta que no resetea).

No es una regresión de F56/F57/F58 (esos cambios no tocan este camino): es un hueco latente que
solo aparece si se genera un QR dentro de los 20 s posteriores a una salida **sin** pulsar reset.

## 23.3 Decisión (F59/RF-66)

1. **Limpieza compartida**: `RoomCycleResetterInterface` + `RoomCycleResetter` (PDO):
   `rooms.cooldown_until = NULL` + `iot_sessions` a `UNKNOWN`/marcas `NULL` (mismo SQL que el
   reset del panel, idempotente; no toca `rooms.status`, estancias, deudas ni credenciales).
2. **Panel de pruebas** (`QrTestController`): `doCreate()` ejecuta la limpieza antes de insertar
   la estancia; `create()` delega en `doCreate()` tras sus guardas (404/409/422 intactas) y
   `reset()`/`roomsReset()` reutilizan el resetter (sin SQL duplicado).
3. **Emisión real** (`QrIssueService::issue`): ejecuta la limpieza tras validar (incluido
   `room_busy`) y antes de `insertReserved`.
4. **Panel (UI)**: `createTestQr()` usa el endpoint `/dashboard-api/qr-test/reset` (mismo camino
   reset+create), de modo que ambos botones garantizan el ciclo limpio.

## 23.4 Qué NO cambia

- La semántica de `ANTI_REENTRADA` en la coreografía, F56/F57/F58, la regla de salida, la luz,
  las anomalías, ni las respuestas de los endpoints (misma forma y códigos).
- La limpieza no cierra estancias ni revoca QRs: eso sigue siendo responsabilidad de
  `reset`/`roomsReset` cuando corresponde.

## 23.5 Pruebas

- `BLOCK 19` del runner: ensuciar `cooldown_until` + marcas IoT → `qr-test/create` → 201 y estado
  limpio (cooldown NULL, IoT UNKNOWN/NULL).
- Bloque de emisión real del runner (`POST /api/v1/qr`): mismo ensuciado → emisión → estado limpio.
- `tests/Unit/QrIssueServiceTest.php`: espía del resetter invocado en `issue()`.
- Regresión: `bash bin/run-tests.sh` con **0 failures**.

---

# 24. F60 — Tipo AlmacenBebidas, dispositivo CAMERA y pack (RF-67/68)

## 24.1 Modelo de datos

**Migración `0116_camera_device_kind.sql`**
- `ALTER TABLE devices MODIFY kind ENUM('RPI','LOCK','PROXIMITY','PRESENCE','SWITCH','SCANNER','CAMERA') NOT NULL;`
- `ALTER TABLE devices ADD COLUMN subtype VARCHAR(32) NULL AFTER kind;` (valores `EXTERIOR`/`INTERIOR`
  para `CAMERA`; `NULL` para el resto). Índice opcional `idx_devices_kind_subtype (kind, subtype)`.

**Migración `0117_roomtype_warehouse_windows.sql`**
- `ALTER TABLE room_types ADD COLUMN warehouse_confirm_seconds SMALLINT UNSIGNED NOT NULL DEFAULT 40;`
- `ALTER TABLE room_types ADD COLUMN warehouse_exterior_margin_seconds SMALLINT UNSIGNED NOT NULL DEFAULT 5;`

**Migración `0120_warehouse_seed.sql`**
- `INSERT` del tipo `ALMACEN_BEBIDAS` ("Almacén de bebidas") con X=40 / M=5.
- `INSERT` del pack `ALMACEN_BEBIDAS` ("Pack Almacén de bebidas").
- Opcional/entorno: crear la habitación del almacén y asignarle el pack (documentado; no fija
  RTSP ni secretos).

**Representación en código**
- `Device.php`: `KIND_CAMERA = 'CAMERA'` y en `allKinds()`.
- `DeviceService::validateKind()` acepta `CAMERA`; **validación de subtipo** en un método puro
  `DeviceService::validateSubtype(kind, subtype)`: `CAMERA` exige `EXTERIOR|INTERIOR`; otros kinds
  exigen `subtype = NULL`.
- `DeviceRepository::insert/update/patch` incluyen `subtype` en el whitelist.
- `meta_json` de cámara: `{ "rtsp_url": "rtsp://user:pass@host:554/Streaming/Channels/101",
  "enabled": true, "record_enabled": true, "resolution": "1920x1080", "note": "…" }`.

## 24.2 Pack y cámaras

- El pack de almacén agrupa `RPI`, `LOCK`, `PROXIMITY`, `PRESENCE`, `SWITCH` y 2×`CAMERA`
  (`subtype` EXTERIOR/INTERIOR). Las cámaras se añaden con `POST /api/v1/devices/register`
  (`{pack_id, kind:'CAMERA', subtype, external_id, meta_json}`) o desde el panel.
- La cámara **no** se detecta por `uniq_devices_room_kind` (esa columna ya no existe tras `0102`);
  el único único relevante es `uniq_devices_external(kind, external_id)`, que **permite varias
  CAMERA** por pack.

## 24.3 Fuera de alcance (evitar regresiones)

- Las cámaras **no** entran en `DEVICE_KIND_ESP32` / `DEVICE_KIND_TUYA`, pull-kinds de
  `ping-all-devices`/`check-device`, heartbeat ESP32, ni en `TRACKED_KINDS`/`RESYNC_KINDS` del
  consumer Pulsar. `device-status` las reporta como `unknown` salvo comprobación propia
  (online por ping RTSP/go2rtc, ver §25).

---

# 25. F61 — Gestión de cámaras y directo con go2rtc (RF-68/72)

## 25.1 Instalación de go2rtc

- `bin/install-go2rtc.sh`: descarga el binario `go2rtc_linux_amd64` a `/usr/local/bin/go2rtc`
  (idempotente; si ya existe, no descarga), crea `/etc/go2rtc/go2rtc.yaml` con:
  ```yaml
  api: { listen: "0.0.0.0:1984" }
  rtsp: { listen: ":8554" }
  webrtc: { listen: ":8555", candidates: ["<IP_LAN>:8555"] }
  log: { level: "info" }
  ```
- Unidad `deploy/systemd/cerraduras-go2rtc.service` (`Restart=always`, usuario `root`).
- **Exposición**: para el MVP el panel (mismo host, puerto 1984) embebe go2rtc; en LAN se accede a
  `http://<IP>:1984`. Nota de diseño: si se quiere mismo origen, añadir un proxy Apache
  (`/almacen-live/ → 127.0.0.1:1984`); no es necesario para el MVP. Las URLs RTSP **no** se loguean.

## 25.2 Sincronización de streams

- `bin/go2rtc-sync.php` (CLI) lee las cámaras de la BD (`kind='CAMERA' AND enabled=1`) y las
  registra en go2rtc vía `PUT /api/streams?name=<n>&src=<rtsp>`; las que ya no existen se borran
  (`DELETE /api/streams?src=<n>`). Nombre canónico: `almacen_<roomId>_<position>` (p. ej.
  `almacen_1_EXTERIOR`).
- Se invoca: al arrancar (`start-all.sh`), al crear/editar/borrar una cámara (desde el controller)
  y por `POST /almacen-api/cameras/sync`.
- La URL RTSP se pasa a go2rtc por API, no a fichero de configuración, para no persistir credenciales.

## 25.3 Directo en el panel

- Cada mosaico embebe `http://<host>:1984/stream.html?src=almacen_<room>_<position>` (WebRTC/MSE
  de go2rtc) en un `<iframe>` **cargado bajo demanda** (solo al abrir `/almacen`); al cerrar se
  destruye el iframe y go2rtc deja de decodificar.
- El indicador de grabación por cámara proviene del estado del dominio (`camera_recordings` con
  `status='RECORDING'`), no de go2rtc.
- Verificación de disponibilidad de cámara: `GET /almacen-api/cameras` puede hacer un `onlinestate`
  ligero (TCP a host:puerto RTSP con timeout 1 s) — **nunca** en bucle cerrado.

---

# 26. F62 — Política de acceso: rol + excepción por empleado (RF-69)

## 26.1 Esquema

**Migración `0118_worker_room_overrides.sql`**
```sql
CREATE TABLE worker_room_overrides (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  worker_id     INT NOT NULL,
  room_type_id  BIGINT UNSIGNED NOT NULL,
  effect        ENUM('ALLOW','DENY') NOT NULL,
  created_at    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uq_wro_worker_roomtype (worker_id, room_type_id),
  CONSTRAINT fk_wro_worker    FOREIGN KEY (worker_id)    REFERENCES workers(id)    ON DELETE CASCADE,
  CONSTRAINT fk_wro_room_type FOREIGN KEY (room_type_id) REFERENCES room_types(id) ON DELETE CASCADE
);
```

## 26.2 Resolución (pura y testeable)

`WarehouseAccessPolicy::resolve(?overrideEffect, bool $roleAllows): bool`
1. Si hay excepción (`ALLOW`/`DENY`) → **manda la excepción**.
2. Si no → `$roleAllows` (consulta existente `worker_role_room_types`).

Sin excepción, el resultado es **idéntico** al actual (retrocompatible, RF-69.6).

## 26.3 Enforcement

- `WorkerQrService::validate()` sustituye `roleRepo->canAccessRoomType(...)` por
  `accessPolicy->canAccess(workerId, roleId, roomTypeId)`; el rechazo conserva `access_denied`
  (403) y registra el intento `DENIED` de visita (RF-70.4) sin abrir puerta (se evalúa **antes**
  de `LockGatewayFactory::make()`).
- `WorkerService::canAccessRoomType()` delega en la política (mismo resultado; extensible).

## 26.4 API y panel

- `GET /api/v1/worker-roles/{id}` ya expone los tipos del rol; se añade
  `GET /almacen-api/access?room_type_id=` (empleados + roles + permisos efectivos) y
  `PUT /almacen-api/access/role/{role_id}` / `PUT /almacen-api/access/worker/{worker_id}`
  (efecto `ALLOW`/`DENY`/`NULL` = borrar excepción).
- Scopes existentes (`worker-roles:write`, `workers:write`) se mantienen para las rutas `/api/v1/*`.

---

# 27. F63 — Visitas y motor de grabación (RF-70/71)

## 27.1 Esquema

**Migración `0119_warehouse_visits_recordings.sql`**

`warehouse_visits`
| Columna | Tipo | Notas |
|---|---|---|
| id | BIGINT UNSIGNED AI PK | |
| room_id | BIGINT UNSIGNED NOT NULL | FK rooms |
| worker_id | INT NULL | FK workers (NULL si no hubo QR) |
| worker_session_id | BIGINT UNSIGNED NULL | FK worker_sessions (`0102`-era: entrada/salida empleado) |
| entry_trigger | ENUM('QR','DOOR','PRESENCE') NOT NULL | |
| outcome | ENUM('ENTERED','NO_SHOW','ANONYMOUS','DENIED') NOT NULL DEFAULT 'NO_SHOW' | |
| denied_reason | VARCHAR(64) NULL | |
| qr_at / entered_at / exited_at | DATETIME(3) NULL | |
| created_at / updated_at | DATETIME(3) | |

`camera_recordings`
| Columna | Tipo | Notas |
|---|---|---|
| id | BIGINT UNSIGNED AI PK | |
| visit_id | BIGINT UNSIGNED NULL | FK warehouse_visits |
| room_id | BIGINT UNSIGNED NOT NULL | FK rooms |
| device_id | BIGINT UNSIGNED NOT NULL | FK devices (CAMERA) |
| position | ENUM('EXTERIOR','INTERIOR') NOT NULL | copia del subtipo |
| episode | ENUM('ENTRY','EXIT','PRESENCE') NOT NULL | |
| trigger | ENUM('QR','DOOR','PRESENCE') NOT NULL | |
| status | ENUM('PENDING','RECORDING','SAVED','DISCARDED','FAILED') NOT NULL DEFAULT 'PENDING' | |
| requested_at / started_at / stopped_at | DATETIME(3) | |
| duration_s | INT UNSIGNED NULL | |
| file_path / poster_path | VARCHAR(255) NULL | relativos a `data/cameras/` |
| size_bytes | BIGINT UNSIGNED NULL | |
| pid | INT UNSIGNED NULL | proceso ffmpeg |
| stop_requested | TINYINT(1) NOT NULL DEFAULT 0 | |
| error | VARCHAR(255) NULL | |
| created_at / updated_at | DATETIME(3) | |

Índices: `idx_cr_visit(visit_id)`, `idx_cr_status(status)`, `idx_cr_started(started_at)`,
`idx_wv_room_created(room_id, created_at)`, `idx_wv_worker(worker_id)`.

`warehouse_state` (una fila por habitación, estado mutable del motor)
| Columna | Tipo |
|---|---|
| room_id | BIGINT UNSIGNED PK (FK rooms) |
| state | ENUM('IDLE','QR_PENDING','RECORDING_INSIDE','EXTERIOR_ONLY','EXIT_PENDING') NOT NULL DEFAULT 'IDLE' |
| current_visit_id | BIGINT UNSIGNED NULL |
| entry_trigger | ENUM('QR','DOOR','PRESENCE') NULL |
| deadline_x | DATETIME(3) NULL |
| deadline_m | DATETIME(3) NULL |
| presence_confirmed | TINYINT(1) NOT NULL DEFAULT 0 |
| updated_at | DATETIME(3) |

## 27.2 Máquina de estados (regla pura)

Estados y transiciones (X = confirm, M = margen exterior):

| Estado actual | Evento | Acciones | Estado destino |
|---|---|---|---|
| IDLE | `QR_OK` | crear visita(QR), iniciar EXT+INT (ENTRY), `X=now+confirm` | QR_PENDING |
| IDLE | `DOOR_OPEN` | crear visita(DOOR), iniciar EXT+INT (ENTRY), `X=now+confirm` | QR_PENDING |
| IDLE | `PRESENT` | crear visita(PRESENCE, ANONYMOUS), iniciar EXT+INT, `presence_confirmed=1` | RECORDING_INSIDE |
| QR_PENDING | `PRESENT` | `presence_confirmed=1`, visita→ENTERED, `entered_at=now`, anula X | RECORDING_INSIDE |
| QR_PENDING | X expira (trigger QR) | parar+**descartar INT**; EXT sigue; visita→NO_SHOW; `M=now+M` | EXTERIOR_ONLY |
| QR_PENDING | X expira (trigger DOOR) | parar+**descartar EXT e INT**; visita→NO_SHOW | IDLE |
| QR_PENDING | `DOOR_CLOSE` | (no cambia: se espera X) | QR_PENDING |
| RECORDING_INSIDE | `ABSENT` | parar INT (SAVE EXIT), `exited_at=now`, `M=now+M` | EXIT_PENDING |
| RECORDING_INSIDE | `DOOR_CLOSE` | (no cambia; la salida la decide `ABSENT`) | RECORDING_INSIDE |
| EXTERIOR_ONLY | `DOOR_CLOSE` | `M=now+M` | EXTERIOR_ONLY |
| EXTERIOR_ONLY | M expira | parar EXT (SAVE) | IDLE |
| EXIT_PENDING | M expira | parar EXT (SAVE), cerrar visita | IDLE |
| EXIT_PENDING | `PRESENT` (reaparece) | cancela M; vuelve a grabar INT (nuevo EXIT) | RECORDING_INSIDE |

- **Idempotencia/dedup**: reutiliza `event_fingerprint`/orden por ms del pipeline (`0108`, F58).
- **Un solo escritor** por habitación: `WarehouseRecordingService` bloquea la fila
  `warehouse_state … FOR UPDATE` y actualiza `camera_recordings` en la misma transacción; los
  procesos ffmpeg los gestiona el daemon leyendo `PENDING`/`stop_requested`.
- **Reaparición durante EXIT_PENDING**: la visita **no** se reabre; se registra como nuevo
  episodio `EXIT` de la misma visita o como nueva visita `PRESENCE` (decisión: nueva visita
  `PRESENCE` si ya pasó M, para no fusionar ciclos).

## 27.3 Clase pura

`WarehouseRecordingDecision` (sin BD): `decide(array $state, array $event, array $config): array`
devuelve `[nextState, actions[], visitPatch]` donde `actions` ∈
`{START_EXT, START_INT, STOP_EXT, STOP_INT, DISCARD_EXT, DISCARD_INT, SAVE_EXT, SAVE_INT,
CREATE_VISIT, CONFIRM_ENTRY, CLOSE_VISIT, SET_DEADLINE_X, SET_DEADLINE_M}`.
Testeable en `tests/Unit/WarehouseRecordingDecisionTest.php` (reemplaza la necesidad de hardware).

## 27.4 Enganches (hooks)

- `WorkerQrService::validate()` tras abrir y crear la `worker_session` → `onSignal(roomId,'QR_OK')`.
- `IotSessionService::processEvent()` **post-commit** (door open/close, PRESENT/ABSENT) →
  `onSignal(roomId, signal)`; ignora habitaciones no-`ALMACEN_BEBIDAS`.
- El servicio no lanza procesos: solo persiste estado, visitas, grabaciones `PENDING` y marcas de
  parada/descarte; el daemon (§28) materializa el vídeo. Así el pipeline IoT no se bloquea.

---

# 28. F64 — Recorder daemon y retención (RF-73)

## 28.1 Daemon

- `bin/warehouse-recorder.php` como servicio `cerraduras-warehouse-recorder.service`
  (`Restart=always`, `KillMode=control-group`), bucle cada 1 s:
  1. `PENDING` (con `enabled`/`record_enabled`) → `proc_open` ffmpeg, guardar `pid`,
     `status=RECORDING`, `started_at`.
  2. `RECORDING` + `stop_requested=1` → `SIGINT` al pid, `waitpid` (timeout 5 s → `SIGKILL`),
     `status=SAVED`, `stopped_at`, `duration_s`, `size_bytes`, `poster_path`.
  3. `RECORDING` + `DISCARD` (marca de descarte) → `SIGINT`/kill, borrar fichero/parcial,
     `status=DISCARDED`.
  4. Proceso muerto inesperadamente y sin parada → `status=FAILED`, `error`; borrar `.tmp`.
  5. Reconciliación al arranque: `RECORDING` sin pid vivo → `FAILED` y limpiar `.tmp`.
- `start-all.sh` / `stop-all.sh` lo gestionan con `systemctl` (patrón F46).

## 28.2 Comando ffmpeg

```
ffmpeg -nostdin -rtsp_transport tcp -i "<rtsp_url>" -c:v copy -an \
       -f mp4 -movflags +faststart -y "<tmp>"
# al parar limpio: rename <tmp> -> <final> (moov finalizado)
```
- `-c:v copy` (sin reencode) como en `reconocimientoFacial`; si la cámara no entrega H.264,
  fallback documentado a `-c:v libx264 -preset veryfast` (configurable en `meta_json.transcode`).

## 28.3 Almacenamiento

- Raíz `data/cameras/` (ignorada por git). Ruta:
  `data/cameras/<room_id>/<YYYYMMDD>/<visit_id>_<position>_<episode>_<started_at>.mp4` +
  `…_poster.jpg` (1 frame).
- El daemon escribe solo bajo `data/cameras/` (validación anti path-traversal al servir).

## 28.4 Retención

- `bin/warehouse-retention.php` (cron diario o tick del daemon cada 1 h): borra ficheros y filas
  `SAVED` con `stopped_at < now - retention_days`. `warehouse.retention_days` en `system_settings`
  (**1** en pruebas; `0`/negativo = sin borrado, entorno real). El panel muestra días configurados
  y uso de disco.

---

# 29. F65 — Panel `/almacen` (RF-74)

## 29.1 Ruta y ficheros

- `GET /almacen` → `public/almacen.html` (patrón de `/dashboard`, `public/index.php`), público LAN.
- `public/assets/almacen.js`: controlador de la página (SSE + render + acciones). CSS embebido
  (una sola vista, minimalista, responsiva).

## 29.2 Endpoints `/almacen-api/*` (públicos LAN, sin auth)

| Método | Ruta | Propósito |
|---|---|---|
| GET | `/almacen-api/state?room_id=` | estado almacén, visita activa, cámaras, grabaciones en curso |
| GET | `/almacen-api/visits?room_id=&worker_id=&from=&to=&outcome=&limit=` | listado de visitas |
| GET | `/almacen-api/visits/{id}` | detalle + grabaciones de la visita |
| GET | `/almacen-api/recordings/{id}/video` | MP4 con `Range`/`ETag` |
| GET | `/almacen-api/recordings/{id}/poster` | miniatura JPEG |
| GET | `/almacen-api/cameras?room_id=` | cámaras (posición, online, grabando) |
| POST | `/almacen-api/cameras` | crear cámara (`device_id`/`pack_id`, subtype, rtsp_url) |
| PATCH | `/almacen-api/cameras/{id}` | editar rtsp_url/enabled/record_enabled/label |
| DELETE | `/almacen-api/cameras/{id}` | desactivar/borrar cámara |
| POST | `/almacen-api/cameras/sync` | re-sincronizar go2rtc |
| GET | `/almacen-api/access?room_type_id=` | empleados + roles + permisos efectivos |
| PUT | `/almacen-api/access/role/{role_id}` | `room_type_id` + `allow` |
| PUT | `/almacen-api/access/worker/{worker_id}` | `room_type_id` + `effect` (`ALLOW`/`DENY`/`NULL`) |
| POST | `/almacen-api/door/open` | apertura manual (registra `access_event`) |
| GET | `/almacen-api/event-stream?room_id=` | SSE de estado (bypass middleware, patrón SSE existente) |

- Reutiliza `/dashboard-api/rooms` y `/dashboard-api/device-status` donde aplique.
- Forma de error uniforme: `{ "error": "<code>", "message": "<texto>" }` (estilo `ErrorHandler`).

## 29.3 SSE

- `AlmacenEventStreamController`: fingerprint ligero (estado + visita activa + grabaciones) cada
  200 ms; `ping` cada 5 s; `max_lifetime` 60 s con `retry: 3000` (mismo modelo que F46).
- La página reconecta con backoff y cae a `GET /almacen-api/state` cada 2 s si el SSE no está.

## 29.4 Layout (una sola vista)

1. **Cabecera**: nombre del almacén, badge estado (LIBRE/OCUPADO), visita activa (empleado + tiempo).
2. **Directo**: 2 mosaicos EXTERIOR/INTERIOR con indicador rojo de grabación.
3. **Visitas**: lista con filtros; al pulsar, se ven los clips de entrada y salida juntos (2×2:
   posición × episodio) con `<video controls preload="metadata">` y poster.
4. **Permisos**: tabla rol×tipo y buscador de empleado con toggle permitir/denegar (excepción).
5. **Controles**: abrir puerta, sincronizar cámaras, refrescar; salud de cámaras/go2rtc/recorder.

## 29.5 Servido de vídeo

- `recordings/{id}/video` reutiliza la lógica de `Range`/`ETag` de `reconocimientoFacial/video.php`
  (206, `Content-Range`, `304`), validando que la ruta resuelta esté bajo `data/cameras/`.
- `/almacen` no requiere sesión (MVP LAN); si en el futuro se protege, se reutiliza
  `CrmSessionMiddleware` sin cambiar los endpoints de datos.

---

# 30. F66 — Pruebas y trazabilidad (RF-76)

## 30.1 Unit tests (puros, sin BD ni hardware)

- `tests/Unit/WarehouseRecordingDecisionTest.php`: los 4 casos (A QR, B door, C presence, D exit)
  y casos límite (X justo, M justo, `ABSENT` sin presencia previa, reaparición).
- `tests/Unit/WarehouseAccessPolicyTest.php`: sin override = rol; `ALLOW` sobre rol DENY; `DENY`
  sobre rol ALLOW; `NULL` = sin excepción.
- `tests/Unit/DeviceCameraSubtypeTest.php`: `CAMERA` exige `EXTERIOR|INTERIOR`; otros kinds `NULL`.
- `tests/Unit/warehouse-visit-format.test.js` (opcional, JS puro del panel si aplica).

## 30.2 Runner (HTTP/BD)

- **BLOCK 44 — F60–F66: Almacén de bebidas** en `api/bin/run-tests.sh`: tipo `ALMACEN_BEBIDAS`,
  CRUD de cámaras (crear con subtype, listar, editar, borrar), política de acceso (rol + override
  con `ALLOW`/`DENY`), visita `NO_SHOW` (QR sin presencia → INT descartada, EXT guardada),
  `GET /almacen` 200, `/almacen-api/state`, `/almacen-api/visits`, servido de un MP4 simulado con
  `Range` (fichero de prueba en `data/cameras/`).
- Estado de BD/ficheros no esperado → `SKIP` con mensaje (patrón del runner).
- Los tests de grabación real con cámara RTSP quedan como **aceptación manual** (no en runner).

## 30.3 No regresión

- Rutas, códigos y campos existentes intactos; sin override de permisos = comportamiento actual;
  el motor ignora habitaciones no-`ALMACEN_BEBIDAS`. Regresión completa con **0 failures**.

## 30.4 Trazabilidad

| RF | Diseño | Contrato | Tareas |
|---|---|---|---|
| RF-67/68 | §24 | Fase 60 | F60-* |
| RF-68/72 | §25 | Fase 61 | F61-* |
| RF-69 | §26 | Fase 62 | F62-* |
| RF-70/71 | §27 | Fase 63 | F63-* |
| RF-73 | §28 | Fase 64 | F64-* |
| RF-74/75 | §29 | Fase 65 | F65-* |
| RF-76 | §30 | Fase 66 | F66-* |

---

# 31. F67 — Croquis en vivo del almacén (RF-77)

## 31.1 Objetivo y alcance

Añadir un croquis compacto del almacén al panel `/almacen` que muestre de un vistazo **puerta,
persona, luz y cámaras**, con etiquetas de texto y última actividad. Es una **capa de
presentación**: no cambia la lógica de dominio, ni la de grabación, ni la coreografía del
dashboard.

## 31.2 Ubicación e integración (sin secciones nuevas)

- La sección existente **"Directo"** se envuelve en `<div class="directo-wrap">`:
  - primera tarjeta: `.cam.croquis-card` con el croquis;
  - después, `#cams` con las tarjetas de vídeo existentes.
- En escritorio (`min-width:900px`) la rejilla es `minmax(280px,330px) 1fr`; en móvil, columna.
- El croquis **no** vive dentro de `#cams` para no ser destruido por `renderCameras()`
  (que reescribe `innerHTML`).

## 31.3 SVG del croquis

- Un único `<svg id="croquis-svg" viewBox="0 0 320 190" role="img">` declarado una sola vez.
  El JS **no reconstruye** el SVG: conmuta clases y textos (animación por CSS).
- Elementos:
  - suelo de la sala + paredes con hueco de puerta;
  - `#croquis-door` (grupo con `transform-origin` en la bisagra) + `#croquis-door-line`;
  - `.croquis-person` (monigote) posicionado por clase (`dentro`/`pasillo`);
  - `.croquis-halo` (pulso de presencia);
  - `#croquis-bulb` + `#croquis-bulb-glow` (luz);
  - `.croquis-cam[data-position="EXTERIOR|INTERIOR"]` + `.cam-rec`.
- Chips de texto en HTML (fuera del SVG): `#croquis-door-chip`, `#croquis-presence-chip`,
  `#croquis-light-chip`; y `#croquis-meta` para la última entrada/salida.
- Clases raíz que aplica el JS: `is-open`, `is-occupied`, `is-outside`, `is-unknown`,
  `is-light-on`; por cámara: `is-recording`, `is-off`.

## 31.4 Lógica pura (`api/public/assets/croquis-logic.js`)

- UMD (igual patrón que `choreography.js`): `module.exports` en Node, global `CroquisLogic` en
  el navegador.
- `deriveCroquis(snapshot, now) → { doorOpen, personInside, door, presence, light, classes, desc }`.
- La apertura de puerta reutiliza **`Choreography.resolveDoorOpen`** (F56) con
  `pulseUntil = parseTime(last_open_at) + DOOR_PULSE_MS (1200)`; si `Choreography` no está
  disponible, aplica la regla inline equivalente (`door_state === 'OPEN' || now < pulseUntil`).
- Persona dentro = `occupied` (visita) **o** `presence === 'PRESENT'`.
- Se carga con `<script src="/assets/croquis-logic.js">` después de `choreography.js`.

## 31.5 Backend

- `WarehouseStateController::stateArray()` añade `live` (aditivo):
  - `iot_sessions` de la sala: `door_state`, `presence_state`, `last_open_at`, `last_close_at`,
    `last_absent_since` (default `UNKNOWN`/`null`);
  - `devices` de la sala con `kind='SWITCH'`: `meta_json.switch_state` (default `UNKNOWN`).
- `AlmacenEventStreamController::stream()` incluye `$snapshot['live']` en el
  `md5(json_encode([...]))` que calcula el fingerprint (línea del fingerprint actual).
- Sin nuevas rutas; sin llamadas a Tuya (solo lecturas de BD).

## 31.6 Render en `almacen.js`

- `renderCroquis()` (usa `CroquisLogic.deriveCroquis`) y `renderCroquisMeta()`; helpers
  `setChip()`, `fmtClock()`, `fmtDur()`.
- Enganche en `applyState()` **antes** de `renderCameras()`.
- `setInterval(1000)` que solo reescribe `#croquis-meta` (tiempo dentro) mientras haya visita.

## 31.7 Responsive y accesibilidad

- SVG `width:100%;height:auto`; chips con `flex-wrap`; sin scroll horizontal a 360px.
- Doble codificación (color + texto); `<title>/<desc>` actualizados por el JS; `aria-live="polite"`
  solo en el bloque de chips; `#croquis-meta` fuera del `aria-live`.
- `@media (prefers-reduced-motion: reduce)` desactiva transiciones/pulsos.

## 31.8 Trazabilidad

| RF | Diseño | Contrato | Tareas |
|---|---|---|---|
| RF-77 | §31 | Fase 67 | F67-01…F67-06 |

---

# 32. F68 — Reproducción de visitas en `/almacen` (RF-78)

## 32.1 Objetivo y alcance

Reconstruir **visualmente** una visita del almacén en el propio panel: el croquis anima al
monigote (acercamiento, escaneo de QR, cruce, estancia dentro, salida), el reloj de la visita
avanza y las cámaras reproducen sus clips **sincronizados**. Todo dentro de la sección existente
**"Directo"**; sin secciones nuevas.

## 32.2 Modelo de reproducción (reloj maestro)

- El **clip EXTERIOR** cubre la visita completa (F63: `START_EXT` al inicio y `STOP_EXT` en el
  cierre). Es el **reloj maestro**; si falta, se usa el clip guardado más largo; si no hay
  vídeos, un reloj interno (`performance.now`).
- `horaVisita(t) = origenVisita + t`; en cada fotograma se calculan: fase del monigote, puerta,
  luz, tiempo dentro, clip activo por posición y su `currentTime` local
  (`t − offsetDelClip`).
- Al **buscar/scrub** o cambiar de velocidad se reposicionan el maestro y los demás clips.
- Velocidades 1×/2×/4×/8× aplicadas con `video.playbackRate` (clips sin audio).

## 32.3 Reconstrucción por hitos

`visit-playback.js` (UMD puro, sin DOM) construye:

```
buildVisitTimeline(visit) -> {
  originMs, endMs, durationMs, startWall,
  markers: [{type:'QR'|'ENTRY'|'EXIT', atMs, label, icon}],
  inside: {startMs, endMs} | null,
  clips: [{id, position, episode, startMs, endMs, wallStart, wallEnd, videoUrl, posterUrl}],
  hasRecordings
}
frameAt(timeline, wallMs) -> {
  phase, personPos, doorOpen, lightOn, personInside,
  elapsedLabel, wallClockLabel, insideSeconds,
  chips:{door,presence,light}, activeClips:{EXTERIOR,INTERIOR}, desc
}
```

- `clipStartMs = requested_at (preferente) || started_at`; `clipEndMs = stopped_at ||
  clipStart + duration_s`; `originMs = min(clips, qr_at, created_at)`.
- Fases del monigote: `approach` (→ `qr_at`), `scan` (`qr_at`), `enter` (inicio clip ENTRY →
  `entered_at`), `inside` (`entered_at` → inicio salida), `exit` (→ `exited_at`), `after`.
- `DOOR`/`PRESENCE` (sin `qr_at`): sin acercamiento/escaneo. `NO_SHOW` (sin `entered_at`): vuelve
  a `after`.
- Reutiliza `Choreography.parseTime` y la forma de `chips` de `croquis-logic.js`.

## 32.4 UI

- `almacen.html`: la columna derecha de "Directo" pasa a `.directo-right` = **banda de tiempo**
  (`#play-timeline`) + `#cams`. La banda queda entre croquis y cámaras (en móvil, apilada).
  Contiene: `#play-clock`, `#play-window` (inicio/salida), `#play-inside`, barra `#play-range`
  con marcadores `#play-markers`, `#play-toggle`, botones de velocidad y `#play-live`.
- SVG: se añade un **lector QR** junto a la puerta (fuera) y clases de posición del monigote
  (`pos-outside`, `pos-qr`, `pos-crossing`, `pos-inside`) + destello de QR.
- `almacen.js`: `applyCroquisView(vm)` reusable (vivo y reproducción); controlador
  `playVisit/togglePlay/seek/setSpeed/exitPlayback` con `requestAnimationFrame`;
  `renderCamerasReplay(clips)` sustituye los iframes por `<video>` y se restaura con
  `renderCameras()`. `applyState()` no pisa croquis/cámaras si `playback` está activo.
- Botón **▶** en cada fila de la lista de visitas y en el detalle de la visita.

## 32.5 Backend

- `WarehouseVisitController`: añadir `requested_at` (y `requested_at` en el `SELECT`) a cada
  `recording` del JSON. **Aditivo**.
- **Fix F63 (bug latente)**: `trigger` es **palabra reservada en MariaDB 10.9**; la `SELECT` del
  panel y el `INSERT` de `WarehouseRecordingService` deben entrecomillarla (`` `trigger` ``). Sin
  esto, `camera_recordings` nunca se poblaba y no había vídeo que reproducir.

## 32.6 Trazabilidad

| RF | Diseño | Contrato | Tareas |
|---|---|---|---|
| RF-78 | §32 | Fase 68 | F68-01…F68-06 |

---

# 33. F69 — Vista en directo y encendido de cámaras (RF-79)

## 33.1 Objetivo

Botón superior **"Ver en directo"** que devuelve el panel al estado actual (croquis con sensores
+ cámaras en directo) y **enciende las cámaras apagadas** sincronizando go2rtc.

## 33.2 Comportamiento (`goLive`)

1. Si hay `playback` activo → `exitPlayback()`.
2. `ensureCamerasLive()`: para cada cámara de `state.cameras` con `enabled === false`,
   `PATCH /almacen-api/cameras/{id}` `{ "enabled": true }`; después `POST /almacen-api/cameras/sync`.
3. Re-render de croquis y cámaras; refresco de estado (SSE/poll).

- **No** se envía `record_enabled`: la grabación no cambia de política (RF-79.5).
- Sin rutas nuevas: se reutilizan `PATCH`/`sync` de F61.

## 33.3 UI

- Cabecera: botón `#btn-live` "Ver en directo" con `#btn-live-dot` (verde = directo, ámbar =
  cámaras apagadas). `goLive()` también es el destino del botón "Volver en vivo" de la banda de
  reproducción (F68).
- Sección "Directo": si hay cámaras apagadas, aviso con la misma acción.

## 33.4 Backend

- Sin cambios de código: `WarehouseCameraController::update` (`enabled`) y `sync` ya existen.
- Operación de datos **puntual**: encender las cámaras reales del almacén (`enabled=true`) y
  llamar a `sync`.

## 33.5 Trazabilidad

| RF | Diseño | Contrato | Tareas |
|---|---|---|---|
| RF-79 | §33 | Fase 69 | F69-01…F69-05 |

---

# 34. F70 — Directo de cámaras por MJPEG (RF-80)

## 34.1 Problema

`WarehouseStateController` devuelve `live_url = http://host:1984/stream.html?src=…` (go2rtc) y el
panel lo carga en un `<iframe>`. El puerto **1984 no está en ufw** (solo 8080), así que el iframe
queda en negro. Patrón de referencia: `reconocimientoFacial/live/mjpeg-stream.js` + Apache
`ProxyPass /reconocimientoFacial/live → 127.0.0.1:8084/live` y `<img src="../live?id=N">`.

## 34.2 Servidor MJPEG (`api/bin/cameras-live.js`)

- Fuente de cámaras: `GET /almacen-api/state` (para `room.id`) + `GET /almacen-api/cameras?room_id=`
  (devuelve `rtsp_url`), refrescada cada `LIVE_REFRESH_MS` (10 s). Solo `enabled=true`.
  Alternativa: `LIVE_ROOM_ID`.
- `GET /live?id=<deviceId>` → `multipart/x-mixed-replace; boundary=frame`.
- Un `ffmpeg` por cámara: `-rtsp_transport tcp -loglevel error -i <rtsp> -vf fps=<FPS>,scale=<SCALE>
  -q:v <Q> -f mjpeg -`; fan-out a N `<res>`; se mata a los `LIVE_IDLE_MS` sin espectadores.
- Parser puro `extractJpegFrames(buffer)` (marcadores `FFD8`/`FFD9`) exportado para test unitario;
  el arranque del servidor solo ocurre con `require.main === module`.
- `GET /status` → `{ok, cameras, streams, detalle}`.
- **Nunca** loguea la URL RTSP (credenciales); solo `id` y códigos de salida.

## 34.3 Configuración

- `.env`: `CAMERAS_LIVE_PORT` (8086), `CAMERAS_LIVE_BASE_URL`
  (p. ej. `https://cerraduras.josue.ink/almacen-live`), `CAMERAS_LIVE_FPS` (5),
  `CAMERAS_LIVE_SCALE` (640:-2), `CAMERAS_LIVE_QUALITY` (6), `CAMERAS_LIVE_IDLE_MS` (5000).
- systemd `cerraduras-cameras-live.service` (`WorkingDirectory=/root/cerraduras/api`,
  `ExecStart=/usr/bin/node bin/cameras-live.js`, `Restart=always`).
- Apache (`cerraduras.josue.ink`, 443): `ProxyPass /almacen-live http://127.0.0.1:8086/live`
  (+`ProxyPassReverse`), **antes** del catch-all `/ → 8099`.

## 34.4 Backend (aditivo)

- `WarehouseStateController` y `WarehouseCameraController` añaden `mjpeg_url` =
  `rtrim(CAMERAS_LIVE_BASE_URL,'/') . '?id=' . deviceId` si la variable está definida; si no,
  `null` (el panel cae al iframe de go2rtc).

## 34.5 Panel

- `renderCameras()`: si `c.mjpeg_url` y `c.enabled`, pinta
  `<img class="mjpeg" src="…">`; `onerror` → placeholder "sin señal"; si no, mantiene el iframe.
- Al re-renderizar `#cams` (o reproducir/salir) se **reemplaza el nodo `<img>`**, lo que aborta la
  conexión multipart (mismo criterio que RF).

## 34.6 Trazabilidad

| RF | Diseño | Contrato | Tareas |
|---|---|---|---|
| RF-80 | §34 | Fase 70 | F70-01…F70-07 |

---

# 35. F71 — Presencia real del almacén y frescura de señal (RF-81 / RF-82)

## 35.1 Problema

En `ALMACEN_BEBIDAS` no existen `stays`/QR de huésped, así que `presenceContext()` de
`IotSessionService` devuelve `[entryWindowActive=false, insideNoExitCycle=false]` pasados los
`presence_entry_window_seconds` (90 s). `SensorEventDecision` marca entonces todo `PRESENT` como
`no_context` (auditado en `presence_events.discard_reason`). Efectos: `iot_sessions.presence_state`
nunca pasa a `PRESENT`; el croquis no muestra al monigote dentro; `WarehouseRecordingDecision` no
recibe `EV_PRESENT` (solo se le llama si el evento fue `apply`). Además el croquis no distingue
"puerta cerrada" de "sin datos" cuando el push del sensor de puerta se interrumpe.

## 35.2 Credibilidad de presencia por tipo de sala

- `presenceContext(Room, IotSession, now)` gana un cortocircuito: si la sala es
  `ALMACEN_BEBIDAS` (vía `roomType.code`), devuelve `[true, true]`. Así `presenceCredible()`
  acepta `move`/`presence` como `PRESENT`.
- `ABSENT` (`none`) ya se aplica siempre, por lo que no requiere cambio.
- Detección encapsulada en un helper privado `isWarehouseRoom(Room): bool` que resuelve el tipo con
  `$this->roomTypes->findById($room->roomTypeId)`.
- La lógica pura `SensorEventDecision` **no cambia** (sigue siendo genérica y testeable); el tipo
  de sala se resuelve en el servicio.

## 35.3 Efectos de huésped en salas de almacén

- `anomalyService->detectAndPersist()` se **omite** para salas `ALMACEN_BEBIDAS` (evita A2
  `presence_without_stay` y ruido). `exitActionService->executeIfPending()` ya es no-op sin stay.
- La luz (`switchService->turnOn` en `DOOR_OPEN`) se mantiene (comportamiento deseado).

## 35.4 Coherencia de la visita iniciada por presencia

- `WarehouseRecordingDecision::decide(IDLE, EV_PRESENT)` pasa a devolver
  `[A_CREATE_VISIT, A_CONFIRM_ENTRY, A_START_EXT, A_START_INT]` (antes sin `A_CONFIRM_ENTRY`),
  de modo que la visita creada por presencia queda `outcome=ENTERED` y `warehouse.occupied=true`.
- Se actualiza el test C de `WarehouseRecordingDecisionTest.php` acorde.

## 35.5 Frescura de señal (`live`)

- `WarehouseStateController::stateArray()` añade a `live`:
  `door_age_seconds` y `presence_age_seconds` (`null` si no hay evento). Se calculan con una única
  consulta `SELECT sensor, MAX(received_at) AS last FROM presence_events WHERE room_id=:r GROUP BY
  sensor` y `age = now - last`.
- El SSE ya incluye `live` en el fingerprint, por lo que la frescura se empuja sin cambios.

## 35.6 Croquis (`croquis-logic.js` / `almacen.js`)

- `deriveCroquis(snapshot, now)` acepta `live.door_age_seconds`. Con `DOOR_STALE_SECONDS` (por
  defecto 300), si `door_age_seconds > umbral` la puerta se presenta como `unknown` ("PUERTA SIN
  DATOS") en lugar de `CERRADA`, y `classes.unknown` se activa.
- `personInside = occupied || presence === 'PRESENT'` se mantiene (RF-81.2).
- `almacen.js` pasa `state.live` completo a `deriveCroquis` (ya lo hace) y no requiere más cambios
  salvo el nuevo chip.

## 35.7 Tests

- `api/tests/Unit/croquis-logic.test.js`: puerta fresca cerrada → `CERRADA`; puerta con
  `door_age_seconds` alto → `SIN DATOS`; `presence_state=PRESENT` → `personInside`.
- `api/tests/Unit/IotSessionServiceTest.php`: sala almacén aplica `PRESENT` sin stay; sala huésped
  sigue `no_context`.
- `api/tests/Unit/WarehouseRecordingDecisionTest.php`: IDLE + PRESENT incluye `A_CONFIRM_ENTRY`.
- `api/bin/run-tests.sh`: **BLOCK 49** (HTTP/DB) — publica un `PRESENT` de almacén y verifica
  `live.presence_state=PRESENT` y la presencia de `*_age_seconds`; restaura el estado.

## 35.8 Trazabilidad

| RF | Diseño | Contrato | Tareas |
|---|---|---|---|
| RF-81 | §35.2–35.4 | Fase 71 | F71-01…F71-05 |
| RF-82 | §35.5–35.6 | Fase 71 | F71-01, F71-04, F71-05 |

---

# 36. F72 — Estado persistente de la puerta y resync periódico (RF-83 / RF-84)

## 36.1 Problema y corrección de §35.6

El MC400D es un contacto **edge-triggered**: solo emite al cambiar. Un umbral de frescura
(`DOOR_STALE_SECONDS = 300`) para degradar el chip a "sin datos" es **semánticamente incorrecto**:
una puerta `OPEN` sigue abierta hasta que llegue `CLOSED`. Se **deroga RF-82.2**: el chip de puerta
vuelve a depender solo del último estado de dominio. `door_age_seconds` se conserva en `live` como
diagnóstico, sin efecto en la presentación.

## 36.2 Croquis (`croquis-logic.js`)

- Se elimina `DOOR_STALE_SECONDS` y la variable `doorStale` de la decisión de `chips`/`desc`.
- Regla final del chip de puerta:
  - `doorOpen` (F56 `resolveDoorOpen`) → `PUERTA ABIERTA`;
  - `door === 'CLOSED'` → `PUERTA CERRADA`;
  - sin estado (`UNKNOWN`/ausente) → `PUERTA SIN DATOS`.
- `personInside = occupied || presence === 'PRESENT'` se mantiene (F71).

## 36.3 Resync REST periódico (`tuya-pulsar-consumer/index.js`)

- Nueva constante `DOOR_RESYNC_MS = parseInt(process.env.CONSUMER_DOOR_RESYNC_MS || '600000', 10)`.
- `resyncKnownDevices(reason, kinds)` gana un parámetro opcional de kinds/ids para reutilizar el
  bucle existente. El resync periódico llama con `['PROXIMITY']`.
- Contador `lastDoorResyncAt` **separado** de `lastResyncAt`; el helper puro
  `periodicDoorResyncDue(now, lastDoorResyncAt, intervalMs)` decide si toca.
- Antes de sondear, `quotaCheck()` reutiliza el contador compartido `api/run/tuya-quota.json`
  (`TUYA_HOURLY_BUDGET`/`TUYA_DAILY_BUDGET`); si está agotado, se omite el ciclo.
- Timer `setInterval(... DOOR_RESYNC_MS).unref()` en `start()`, solo con WS `OPEN`.

## 36.4 Tests

- `api/tests/Unit/croquis-logic.test.js`: `CLOSED` con edad enorme → `PUERTA CERRADA`; `OPEN` con
  edad enorme → `PUERTA ABIERTA`; `door_state` ausente → `PUERTA SIN DATOS`.
- `api/tests/Unit/tuya-pulsar-consumer.test.js`: `periodicDoorResyncDue` (primera vez, intervalo
  cumplido/no cumplido).
- `api/bin/run-tests.sh`: **BLOCK 50** (estáticos + `CONSUMER_DOOR_RESYNC_MS` en `.env.example`);
  ajustar **BLOCK 49** (ya no exige `DOOR_STALE_SECONDS`).

## 36.5 Trazabilidad

| RF | Diseño | Contrato | Tareas |
|---|---|---|---|
| RF-83 | §36.1–36.2 | Fase 72 | F72-02, F72-03 |
| RF-84 | §36.3 | Fase 72 | F72-04, F72-05 |

---

# 37. F73 — Tope de grabación en pruebas y purga del almacén (RF-85 / RF-86)

## 37.1 Problema

El motor de grabación (`WarehouseRecordingDecision`) solo emite `A_STOP_*` al cambiar de estado
(salida de la visita, margen M agotado o descarte). Una presencia 24G que se mantiene en `PRESENT`
deja el clip `RECORDING` indefinidamente. En pruebas eso llena el disco (observado 912 MB en 22
clips) sin aportar valor. Hasta producción se necesita un tope duro y poder purgar la sala.

## 37.2 Tope de duración en el recorder

- Nueva variable de entorno `WAREHOUSE_MAX_RECORDING_SECONDS` (entero, default `0`). Se lee en el
  daemon con `Config::getInt('WAREHOUSE_MAX_RECORDING_SECONDS', 0)`. `0` = sin límite.
- `WarehouseRecordingService::enforceRecordingCap(int $maxSeconds): int` es el escritor único y
  encapsula la política:

  ```sql
  UPDATE camera_recordings SET stop_requested=1
   WHERE status='RECORDING' AND stop_requested=0
     AND started_at IS NOT NULL
     AND started_at <= (UTC_TIMESTAMP(3) - INTERVAL :s SECOND)
  ```

  Devuelve las filas afectadas. Con `$maxSeconds <= 0` es un no-op (no ejecuta SQL).
- `bin/warehouse-recorder.php` llama a `enforceRecordingCap()` al inicio de cada tick (si el tope
  es > 0). El siguiente paso del mismo tick procesa `stop_requested=1` con `stopRecording()`, que
  finaliza el `.tmp.mp4` → `.mp4`, genera el póster y sella `duration_s`; por tanto el clip queda
  `SAVED` (no `FAILED`). No se encadena un nuevo clip hasta que el motor vuelva a emitir
  `A_START_*`.

## 37.3 Purga total del almacén

- `api/bin/warehouse-purge.php [--room=N] [--all]` (mantenimiento, no expuesto en la API):
  1. para cada sala `ALMACEN_BEBIDAS`, marca `stop_requested=1, discard_requested=1` en
     `PENDING`/`RECORDING` (mata el ffmpeg en el siguiente tick y no deja ficheros colgando);
  2. borra `warehouse_state` (FK `current_visit_id`), luego `camera_recordings` y finalmente
     `warehouse_visits`;
  3. elimina los ficheros bajo `data/cameras/<room_id>/` resolviendo con `realpath` y verificando
     que quedan dentro de `data/cameras/` (mismo criterio que `warehouse-retention.php`).
- Procedimiento operativo recomendado: `systemctl stop cerraduras-warehouse-recorder`, ejecutar la
  purga, `systemctl start cerraduras-warehouse-recorder`.

## 37.4 Tests

- `api/tests/Unit/WarehouseRecordingCapTest.php` (PHP puro, doble de `PDO`): `maxSeconds <= 0` no
  ejecuta `UPDATE`; `maxSeconds > 0` ejecuta el `UPDATE` con el parámetro del tope y devuelve el
  número de filas.
- `api/bin/run-tests.sh`: **BLOCK 51** — inserta una fila `RECORDING` con `started_at` antiguo y
  otra reciente, invoca el tope, comprueba que solo la antigua queda `stop_requested=1` y restaura
  el estado.
## 37.5 Trazabilidad

| RF | Diseño | Contrato | Tareas |
|---|---|---|---|
| RF-85 | §37.2 | Fase 73 | F73-02, F73-04 |
| RF-86 | §37.3 | Fase 73 | F73-03, F73-04 |

---

# 38. F74 — Sin polling periódico que consuma cuota Tuya (RF-87)

## 38.1 Problema

La API REST de Tuya se factura por llamada. El resync periódico de puerta (§36.3) sondeaba
`/devices/{id}/status` cada 10 min de forma indefinida, gastando créditos sin intervención del
usuario. Se **deroga RF-84** y se elimina ese sondeo. El diseño correcto es: **los sensores entran
por push** (Message Service, sin cuota); REST solo en acciones explícitas.

## 38.2 Eliminación en el consumer (`tuya-pulsar-consumer/index.js`)

- Se eliminan: `DOOR_RESYNC_MS`, `doorResyncDeviceIds`, `lastDoorResyncAt`,
  `loadDoorResyncDevices()`, `periodicDoorResyncDue()`, `resyncDoorPeriodic()`, los helpers de
  `quota*` y el `setInterval` de sondeo periódico.
- `resyncKnownDevices()` vuelve a su forma previa a F72 (solo resync puntual al `ws-open`, con
  `shouldResync`/rate-limit). El fichero queda **idéntico** al estado pre-F72.
- No se añade ningún temporizador que llame a `tuyaRequest(.../status)`.

## 38.3 Alcance del gasto que se conserva

- Push del Message Service: **sin cuota**.
- Resync puntual al (re)conectar el WS: REST, pero **event-driven**, no periódico (se mantiene).
- Acciones explícitas del panel/calibración y `ping-all-devices`: REST **bajo demanda** (se
  mantienen, fuera del alcance de esta fase).

## 38.4 Tests

- `api/tests/Unit/tuya-pulsar-consumer.test.js`: se comprueba que el módulo **no** exporta
  `periodicDoorResyncDue` ni `DOOR_RESYNC_MS`.
- `api/bin/run-tests.sh`: **BLOCK 50** verifica la ausencia de resync periódico en el consumer y de
  `CONSUMER_DOOR_RESYNC_MS` en `.env.example`, además de mantener los casos F72 de estado
  persistente del croquis.

## 38.5 Trazabilidad

| RF | Diseño | Contrato | Tareas |
|---|---|---|---|
| RF-87 | §38.1–38.3 | Fase 74 | F74-01…F74-04 |

---

# 39. F75 — Refresco de sensores del almacén bajo demanda (RF-88/89/90)

## 39.1 Problema

La batería E2E (`run-tests.sh` BLOCK 42) borra `iot_sessions` y `presence_events` de PROTO2 (sala
del almacén). Como el MC400D es edge-triggered y la puerta siguió abierta (sin nuevo `OPEN`), el
sistema quedó en `door_state=UNKNOWN`. Sin polling periódico (F74) no hay forma de recuperar el
estado hasta un cambio físico. Además la presencia del 24G con `move` (pasillo) pintaba monigote
dentro sin nadie.

## 39.2 Presencia estricta del almacén (RF-89)

- `SensorEventDecision::decide(..., bool $warehousePresence = false)`:
  ```php
  if ($provider === TUYA && $sensor === PRESENCE && $value === PRESENT) {
      if ($warehousePresence) {
          if (strtolower((string) ($meta['tuya_raw_val'] ?? '')) !== 'presence') {
              return self::NO_CONTEXT;   // move del 24G no es presencia fiable
          }
      } elseif (!self::presenceCredible($raw, $entryWindowActive, $insideNoExitCycle)) {
          return self::NO_CONTEXT;
      }
  }
  ```
- `IotSessionService::processEvent()` pasa `$this->isWarehouseRoom($room)` como `$warehousePresence`.
- `ABSENT` (`none`) se sigue aplicando siempre; el comportamiento F48 de huéspedes no cambia.

## 39.3 Endpoint de refresco bajo demanda (RF-88)

- `POST /almacen-api/sensors/refresh` (público LAN). Body opcional `{"room_id": N}`.
- Resuelve la sala `ALMACEN_BEBIDAS` y su device `PROXIMITY` (via pack).
- **Cooldown**: `ALMACEN_SENSOR_REFRESH_COOLDOWN_SECONDS` (default `1800`), leído con
  `Config::getInt`. Marca en `devices.meta_json.status_probed_at` (ISO). Si `now - status_probed_at
  < cooldown` → `200 {ok:true, probed:false, reason:"throttled"}` sin llamar a Tuya.
- Lectura: `tuyaPresenceApi('GET','/v1.0/iot-03/devices/{externalId}/status', null)` (respeta
  presupuesto/backoff compartido).
- Aplica el resultado por la ingesta: `['devId'=>externalId, 'status'=>result]` →
  `$tuyaIngress->normalize()` → `$iotSessionService->processEvent()` (si no es `_noop`).
- Responde con el snapshot: `{ok:true, probed:true, state: <stateArray()>}`.
- Nunca se invoca por temporizador; solo panel/botón.

## 39.4 Panel (RF-88.4)

- `almacen.js`: `refreshSensors()` (POST al endpoint, aplica `state`), llamado **una vez** al inicio
  tras `connectSSE()`; botón `#btn-refresh` en la cabecera que lo fuerza.
- El SSE ya incluye `live` en el fingerprint: el estado refrescado se propaga solo.

## 39.5 Regresión sin borrar el almacén (RF-90)

- En `run-tests.sh` `_e2e_normalize`/`_e2e_cleanup`: si la sala es `ALMACEN_BEBIDAS`, no ejecutar
  `DELETE FROM iot_sessions/presence_events`; en su lugar hacer **snapshot** de la fila
  `iot_sessions` (door_state, presence_state, `last_*`, `last_*_event_at`, `last_*_value`) y
  **restaurarla** con `INSERT ... ON DUPLICATE KEY UPDATE` al final de la limpieza.
- `presence_events` no se borra para salas de almacén.

## 39.6 Tests

- `api/tests/Unit/SensorEventDecisionTest.php`: caso `warehousePresence=true` → `move` = `no_context`,
  `presence` = `apply`.
- `api/tests/Unit/IotSessionServiceTest.php`: F75 — almacén con `move` no aplica; con `presence` sí;
  huésped sin contexto sigue descartando.
- `api/bin/run-tests.sh`: **BLOCK 52** (estáticos + units; HTTP del refresh guardado por cooldown).

## 39.7 Trazabilidad

| RF | Diseño | Contrato | Tareas |
|---|---|---|---|
| RF-88 | §39.3–39.4 | Fase 75 | F75-02, F75-03 |
| RF-89 | §39.2 | Fase 75 | F75-02 |
| RF-90 | §39.5 | Fase 75 | F75-04 |

---

# 40. F76 — Presencia del almacén anclada al ciclo de puerta (RF-91 / RF-92)

## 40.1 Problema

El 24G reporta `presence` falso con la sala vacía. F71/F75 lo hacían siempre creíble → se creaba una
visita (`entry_trigger=PRESENCE`) que convertía el fantasma en estado persistente. Con la puerta
abierta, el sensor además ve el pasillo por el hueco.

## 40.2 Contexto de presencia del almacén

- `IotSessionService::warehousePresenceContext()`:
  - Si `session.doorState === DOOR_OPEN` → `[false, false]` (no creíble).
  - Si no: `entryConfirmedAt = warehouseRecorder->activeEnteredVisitAt(room)`.
    - `entryWindowActive` = sin visita anclada y (`last_open_at` o `last_close_at` dentro de
      `presence_entry_window_seconds`).
    - `insideNoExitCycle` = hay visita anclada y `last_open_at < entered_at`.
- `SensorEventDecision::decide()` (rama `warehousePresence`): aplica solo si
  `tuya_raw_val === 'presence'` **y** (`entryWindowActive || insideNoExitCycle`).
- Se retira el cortocircuito `[true,true]` que F71 puso en `presenceContext()` (queda solo para
  huéspedes).

## 40.3 Ancla sin auto-justificación

- `WarehouseRecordingServiceInterface::activeEnteredVisitAt(int $roomId): ?string` devuelve
  `warehouse_visits.entered_at` de la visita ENTRADA activa con `entry_trigger IN ('DOOR','QR')`.
- Una visita creada por `IDLE + EV_PRESENT` (trigger `PRESENCE`) **no** ancla; así un falso positivo
  no se perpetúa.

## 40.4 Tests

- `SensorEventDecisionTest.php`: almacén + `presence` sin contexto → `no_context`; con ventana o
  visita → `apply`.
- `IotSessionServiceTest.php`: almacén con puerta OPEN + `presence` → no aplica; tras CLOSE dentro
  de ventana → aplica.

## 40.5 Trazabilidad

| RF | Diseño | Contrato | Tareas |
|---|---|---|---|
| RF-91 | §40.2 | Fase 76 | F76-02 |
| RF-92 | §40.3 | Fase 76 | F76-03 |

## 41. Fase 77 — Correcciones del panel /almacen

### 41.1 Servido de clips (RF-93 / F77.1)
`WarehouseRecordingController` resuelve `storageRoot` y la base de rutas con `dirname(__DIR__, 3)`
= `api/` (antes `api/src` → `realpath` fallaba y todos los clips daban 404). Aplica también a
`WarehouseStateController::retentionInfo()` (`disk_used_pct`).

### 41.2 Estado de puerta robusto (RF-94 / F77.5)
- Frescura real desde `iot_sessions.last_door_event_at` / `last_presence_event_at` (solo `APPLY`).
- `deriveCroquis` pinta `PUERTA SIN DATOS (hace X)` si `live.door_stale`.
- `IotSessionService::warehousePresenceContext()`: veta presencia con `OPEN` **solo si el sensor está
  fresco** (`DOOR_STALE_SECONDS`, default 300 s); si está mudo devuelve `[true,false]` (recuperación F71).

### 41.3 Reproducción (RF-95 / F77.2)
`visit-playback.js` ancla `crossOutMs` a `exited_at` (o clip EXIT explícito) restando `CROSS_MS`; ya no
usa el fin del clip INTERIOR, que con el tope de grabación termina antes que la estancia.

### 41.4 Directo (RF-96 / F77.3)
Fingerprint SSE sin `*_age_seconds`; `renderCameras` solo reconstruye el DOM si cambia la firma de cámaras
y no está en modo replay.

### 41.5 Consumer sin poll periódico (RF-97 / F77.4)
`silenceExceeded()` usa `max(lastMessageAt, lastPongAt)`; `resyncKnownDevices()` reserva cuota en
`api/run/tuya-quota.json` antes de cada `tuyaRequest` (token y status).

### 41.6 Recorder (RF-98 / F77.6)
Reconcile 3b: mata ffmpeg gestionados cuyo id ya no está en `camera_recordings`.

### 41.7 Luz y detalle (RF-99/RF-100 / F77.7–F77.8)
`switch_state_inferred` (último comando) mostrado como estimación; `selectedVisitId` evita cerrar el
detalle en el refresco de 15 s.

### 41.8 Trazabilidad

| RF | Diseño | Contrato | Tareas |
|---|---|---|---|
| RF-93 | §41.1 | §4 | F77-01 |
| RF-94 | §41.2 | §4 | F77-02 |
| RF-95 | §41.3 | §4 | F77-03 |
| RF-96 | §41.4 | §4 | F77-04 |
| RF-97 | §41.5 | §4 | F77-05 |
| RF-98 | §41.6 | §4 | F77-06 |
| RF-99/100 | §41.7 | §4 | F77-07 |

---

# 42. F78 — Eliminación total del poller de presencia (RF-101)

## 42.1 Requisito inquebrantable

**Prohibido el sondeo continuo/automático de la API REST de Tuya.** La cuota de IoT Core se
factura por llamada y un poller de fondo la agota en horas (incidente 2026-09-17: ~1.400
llamadas/hora por una puerta atascada). Por eso **no debe existir ningún proceso que sondee
Tuya en bucle**: ni en `start-all.sh`, ni en systemd, ni en cron, ni en diagnósticos manuales
que se dejen corriendo.

Fuentes de estado **permitidas** (ninguna es sondeo continuo):

1. **Push** del Message Service (consumer `tuya-pulsar-consumer`): sin cuota IoT Core.
2. **Sondas REST bajo demanda** (panel, calibración, `ping-all-devices`,
   `/almacen-api/sensors/refresh`): solo ante acción explícita y con el presupuesto compartido
   `api/run/tuya-quota.json`.
3. **Resync puntual** al (re)conectar el WS del consumer: event-driven, no periódico.

## 42.2 Qué se elimina (deroga el poller de F44/F46)

Se eliminan por completo (código + arranque + contrato + tests):

- `api/bin/tuya-presence-poller.js` (poller Node gated).
- `api/bin/presence-poller-manager.sh` (supervisor multi-sensor).
- `api/bin/wrapper-poller.sh` (wrapper de respaldo).
- Diagnósticos de sondeo continuo `api/bin/tuya-presence-listen.js` y
  `api/bin/tuya-presence-sensor-read.php`.
- Tests `api/tests/Unit/presence-poller-gate.test.js` y
  `api/tests/Unit/presence-poller-manager.test.js`.
- Unit systemd `cerraduras-presence-poller.service` (instalado en el servidor).

`GET /dashboard-api/system-status` pasa de **6 a 5** workers (se retira
`presence-poller-manager`). `HealthController` deja de comprobar `presence-poller`. El flag
`devices.meta_json.presence_source` queda **derogado como disparador de procesos**.

## 42.3 Guardia anti-regresión

- `start-all.sh` ([4/8], guardia anti-poller): **mata** cualquier
  `tuya-presence-poller.js` vivo y **deshabilita** el unit legacy si reapareciera.
- `api/bin/run-tests.sh` (BLOCK 35, marcadores F78): falla si existe un proceso
  `tuya-presence-poller.js`, si reaparecen los scripts o si el unit legacy vuelve a estar
  habilitado. Es la regresión que impide reintroducir el poller en silencio.
- `stop-all.sh`, `smoke-test.sh`, `watchdog.sh` y `worker-loop.sh` ya no conocen poller.

## 42.4 Alternativa para sensores sin push

Si un sensor Tuya no llega por push, la solución es **ampliar la regla de mensajes** de Tuya
(§13.9: añadir su `device id` a la regla `statusReport`), **nunca** reintroducir un poller
continuo.

## 42.5 Trazabilidad

| RF | Diseño | Contrato | Tareas |
|---|---|---|---|
| RF-101 | §42.1–42.4 | §5 (`system-status`, 5 claves) | F78-01…F78-06 |

## 43. F79 — Visitas fantasma del almacén (RF-102)

### 43.1 Contexto y evidencia

El 2026-10-05 el listado de `/almacen` mostraba 4 visitas; solo la de las 12:44:39 (local)
era real. Las otras tres se originaron así:

- **Visita 78 (10:07:04 UTC, DOOR/NO_SHOW)**: creada por el propio runner de tests
  (`run-tests.sh` BLOCK 17) al inyectar un `POST /api/v1/tuya/webhook` con el **device real**
  `bf4c7e7d2cef28cea2nkwk` (sensor de puerta de la sala del almacén) y
  `doorcontact_state=true`. El payload crudo (sin `dataId`/`productKey`) está en
  `logs/systemd.log`. No había cleanup.
- **Visita 79 (10:08:01 UTC, PRESENCE/ENTERED)**: creada por el BLOCK 42 (F54), que ejecuta el
  ciclo completo del huésped con `/sim/rooms/12/*` sobre la sala del almacén. El cleanup
  (`_e2e_cleanup`) cerraba `stays` y borraba `iot_sessions`, pero no `warehouse_visits`,
  `camera_recordings` ni `warehouse_state`.
- **Visita 81 (10:24:20 UTC, DOOR/NO_SHOW)**: push real de Tuya
  (`doorcontact_state=true`, con `dataId`/`productKey`) sin presencia interior (solo `move`,
  rechazado por F76). El motor RF-71.4 la documenta como `NO_SHOW` y descarta ambos clips.
- **Patrón sistémico**: el MC400D reemite `doorcontact_state=true` cada ~15 min
  (`systemd.log` 07:44…09:46) y el resync REST del consumer reenvía el estado cacheado de
  Tuya al reconectar. Cualquier re-reporte aplicado mientras el estado local es `CLOSED`
  se convertía en una transición y generaba visita.

### 43.2 Diseño de la corrección

1. **Motor (RF-102.1/102.2)**: el motor del almacén solo reacciona a ciclos reales. Se añade
   `meta.source` (propagado por `TuyaSensorIngress` desde `_source` del payload) y se usa
   `provider`. `WarehouseRecordingService::onSignal` retorna sin efecto si
   `provider=SIMULATED` o `source=resync`. El estado IoT sigue intacto.
2. **Runner (RF-102.4)**: BLOCK 17 usa un device sintético; BLOCK 42 hace
   snapshot/restore de las tablas del almacén (baseline `MAX(id)` + copia de
   `warehouse_state`) y purga clips; S12 verifica que no quedan visitas nuevas.
3. **Panel (RF-102.3)**: `/almacen-api/visits` excluye por defecto
   `outcome='NO_SHOW' AND entry_trigger='DOOR'` y las visitas solo-`PRESENCE`; `include_no_show=1`
   los muestra. La creación de visitas por presencia se mantiene (F80/RF-103) para el croquis.
4. **Datos (RF-102.5)**: purga puntual de 78/79/81 y sus clips.

### 43.3 Trazabilidad

| RF | Diseño | Contrato | Tareas |
|---|---|---|---|
| RF-102.1/102.2 | §43.2.1 | §1 (`meta.source`) | F79-02 |
| RF-102.3 | §43.2.3 | §1 (`visits`, `include_no_show`) | F79-03 |
| RF-102.4 | §43.2.2 | — | F79-01 |
| RF-102.5 | §43.2.4 | — | F79-04 |

---

# 44. F80 — Presencia del almacén en tiempo real (RF-103)

## 44.1 Problema

El motor `WarehouseRecordingDecision` ya acepta QR, puerta **o** presencia como disparador de visita
(RF-71.5: `IDLE + EV_PRESENT → RECORDING_INSIDE`, `entry_trigger=PRESENCE`, `A_CREATE_VISIT` +
`A_CONFIRM_ENTRY`). El bloqueo está **antes** del motor:

- `SensorEventDecision::decide()` descarta el `PRESENT` del almacén con `no_context` cuando la puerta
  está `OPEN` o no hay ciclo de puerta reciente (F76, `warehousePresenceContext`), y además F75 exige
  `tuya_raw_val="presence"` (rechaza `move`). El `EV_PRESENT` nunca llega a `onSignal()`.
- El croquis (`deriveCroquis`) pinta solo el estado final (`occupied || presence_state==='PRESENT'`)
  y nunca las fases de acercamiento/cruce → "no se mueve".

## 44.2 Presencia siempre creíble en el almacén (restaura F71)

- En `ALMACEN_BEBIDAS` **no** se calcula contexto de puerta/visita: `IotSessionService`
  `warehousePresenceContext()` pasa a devolver siempre credibilidad. `SensorEventDecision::decide()`
  (rama `warehousePresence`) pasa a devolver `APPLY` para todo `PRESENT` de provider TUYA
  (`presence` y `move`). `ABSENT`/`none` sigue aplicándose siempre.
- Efecto: `iot_sessions.presence_state=PRESENT` al instante → post-commit `EV_PRESENT` →
  `WarehouseRecordingService::onSignal()` crea la visita (`PRESENCE`) y arranca ambas cámaras.
- `WarehouseRecordingService::activeEnteredVisitAt()` incluye `PRESENCE` como ancla (la visita por
  presencia es real); deja de ser un vetador de credibilidad.
- Sin cambios en F48 (huéspedes): `presenceContext()` y su comportamiento quedan intactos.

## 44.3 Contrato del croquis en vivo (aditivo)

- `WarehouseStateController::stateArray()` añade `live.recent_presence` (últimos eventos aplicados:
  `sensor`, `value`, `occurred_at`), leídos de `presence_events`.
- `AlmacenEventStreamController` ya incluye `live` en el fingerprint → empuja el cambio sin tocar el
  stream.

## 44.4 Fases del monigote (lógica pura)

`croquis-logic.js::deriveCroquis(snapshot, now)` amplía su retorno con `phase`:

- `qr`: `live.scanning`/evento QR reciente (solo en reproducción; en vivo no hay QR).
- `crossing`: `doorOpen` y hay PRESENT reciente (< ventana) o `presence_state=PRESENT`.
- `inside`: `occupied` o `presence_state=PRESENT` estable.
- `near`: `doorOpen`/apertura reciente sin presencia confirmada.
- `outside`: por defecto.
- Reutiliza `Choreography.resolveDoorOpen` (ya cargado en `almacen.html`); la función sigue siendo
  pura (recibe `recent_presence`, no toca red ni DOM). `almacen.js::renderCroquis` aplica la clase
  `pos-<phase>` (CSS ya presente en `almacen.html`).

## 44.5 Derogaciones y alcance

- **Deroga**: RF-91.2 (presencia no creíble con puerta `OPEN`) y RF-92.1 (la presencia no ancla).
- **Vigente**: RF-88 (refresco bajo demanda), RF-91.3 (F48 de huéspedes), RF-101 (prohibido el poller).
- **No se toca** `/dashboard` ni su pipeline.

## 44.6 Riesgos

- Al aceptar `move` y puerta abierta pueden reaparecer fantasmas del pasillo (motivo original de F76).
  Se mitiga con `ABSENT` (que limpia el estado), `far_detection` del 24G y sin sondeo/cuota. Decisión
  explícita del operador por el requisito de los 3 disparadores.

## 44.7 Trazabilidad

| RF | Diseño | Contrato | Tareas |
|---|---|---|---|
| RF-103.1–103.2 | §44.1–44.2 | §6 | F80-01, F80-02 |
| RF-103.3 | §44.3 | §6 | F80-03 |
| RF-103.4 | §44.4 | §6 | F80-04 |
| RF-103.5–103.7 | §44.5 | §6 | F80-05 |

---

# 45. F81 — Cierre de visita del almacén (RF-104)

## 45.1 Motor: evento interno `DOOR_CLOSE_ABSENT`

`WarehouseRecordingDecision` añade la constante `EV_DOOR_CLOSE_ABSENT = 'DOOR_CLOSE_ABSENT'`. En
`STATE_RECORDING_INSIDE` se trata **igual que `ABSENT`**: parar la cámara interior, marcar la salida
y fijar el margen exterior → `EXIT_PENDING`. `EV_DOOR_CLOSE` (cierre con presencia interior) sigue
siendo **no-op** en `RECORDING_INSIDE`. El evento no toca `QR_PENDING`: un cierre sin presencia en
esa fase sigue esperando X.

Fila nueva en la tabla de transiciones (§27.2):

| Estado actual | Evento | Acciones | Estado destino |
|---|---|---|---|
| RECORDING_INSIDE | `DOOR_CLOSE_ABSENT` | parar INT (SAVE EXIT), `exited_at=now`, `M=now+M` | EXIT_PENDING |

La fila existente `RECORDING_INSIDE + DOOR_CLOSE → (no cambia)` se mantiene para el cierre con
presencia interior.

## 45.2 Enganche: `IotSessionService::processEvent()` (post-commit)

En el bloque post-commit del motor del almacén (F63/RF-71), al mapear una señal `PROXIMITY=CLOSED` se
consulta el `presence_state` de la sesión IoT **ya mutada** (la decisión se aplicó antes del commit):

- `presence_state === ABSENT` → se entrega `EV_DOOR_CLOSE_ABSENT` (salida sin presencia interior).
- En cualquier otro caso → se entrega `EV_DOOR_CLOSE` (comportamiento actual; con `PRESENT` es no-op
  en `RECORDING_INSIDE`).

Se mantienen los filtros F79 (provider `SIMULATED` y `source` `resync`/`sim` se ignoran en el motor).
No hay rutas nuevas, ni temporizadores, ni llamadas a Tuya.

## 45.3 Croquis: fase `inside` con presencia

`croquis-logic.js::deriveCroquis` prioriza la presencia sobre la apertura de puerta:

- con `presence_state=PRESENT` u `occupied` → fase `inside` (aunque `door_state=OPEN`);
- puerta abierta sin presencia → fase `near`;
- sin puerta ni presencia → `outside`.

La fase `inside` se pinta más al interior de la habitación (clase CSS `pos-inside`), evitando el
aspecto de "medio afuera". La función sigue siendo pura (no toca red ni DOM).

## 45.4 Alcance y derogaciones

- **Sin cambios** en `QR_PENDING`: la ventana X y el no-op de `DOOR_CLOSE` se mantienen.
- **Derogado**: el no-op de `DOOR_CLOSE` en `RECORDING_INSIDE` **solo cuando no hay presencia
  interior** (pasa a `DOOR_CLOSE_ABSENT`); con presencia sigue siendo no-op.
- **Vigente**: RF-101 (prohibido el poller), RF-103 (presencia siempre creíble en el almacén) y F79.
- **No se toca** `/dashboard` ni la semántica F48 de habitaciones de huésped.

## 45.5 Trazabilidad

| RF | Diseño | Contrato | Tareas |
|---|---|---|---|
| RF-104.1 | §45.1–45.2 | §F81.2 | F81-01, F81-02 |
| RF-104.2–104.4 | §45.1–45.2 | §F81.2 | F81-01, F81-02 |
| RF-104.5 | §45.3 | §F81.3 | F81-03 |
| RF-104.6–104.8 | §45.4 | §F81.4 | F81-04 |

---

# 46. F82 — Listado de visitas del almacén con presencia (RF-105)

## 46.1 Filtro del listado

En `WarehouseVisitController::index()` el filtro por defecto pasa a ser **solo**
`NOT (outcome='NO_SHOW' AND entry_trigger='DOOR')`; desaparece la condición adicional
`entry_trigger <> 'PRESENCE'` que introdujo F79/RF-102.3. El parámetro `include_no_show=1` y el
filtro explícito `outcome=…` conservan su comportamiento actual.

## 46.2 Motivo

Coherencia con F80/RF-103 (la detección de presencia es un disparador válido de visita) y con
F81/RF-104 (la visita iniciada por presencia tiene inicio y fin reales). El listado de `/almacen`
debe reflejar las visitas que el motor realmente crea, sin exigir un parámetro.

## 46.3 Alcance

- **No se toca** el motor (`WarehouseRecordingDecision`, `WarehouseRecordingService`), el croquis
  (`croquis-logic.js`) ni la grabación/recorder.
- **Sin cuota Tuya**: solo cambia una consulta local a BD (RF-101 intacto).
- Revisa parcialmente RF-102.3 (ocultación por defecto de las visitas solo-`PRESENCE`).

## 46.4 Trazabilidad

| RF | Diseño | Contrato | Tareas |
|---|---|---|---|
| RF-105.1 | §46.1–46.2 | §F82.1 | TSK-F82-01, TSK-F82-02 |
| RF-105.2 | §46.1 | §F82.1 | TSK-F82-01, TSK-F82-02 |
| RF-105.3 | §46.1 | §F82.1 | TSK-F82-01 |
| RF-105.4 | §46.3 | §F82.2–82.3 | TSK-F82-02 |

---

# 47. F83 — Presencia fiel en el panel del almacén (RF-106)

## 47.1 API: `WarehouseStateController::stateArray()` (aditivo + redefinición)

`GET /almacen-api/state?room_id=` publica sin cambiar la forma de los campos previos:

- `live.presence_active` (bool): `iot_sessions.presence_state === 'PRESENT'`.
- `live.presence_known` (bool): `presence_state ∈ {PRESENT, ABSENT}` (es decir, `≠ UNKNOWN`).
- `warehouse.exiting` (bool): `warehouse_state.state === 'EXIT_PENDING'` **o** la visita actual ya tiene
  `exited_at` no nulo (salida marcada y cámara EXTERIOR aún dentro de su margen).
- `current_visit.exited_at` (string MySQL UTC | null): se añade al `SELECT` de `warehouse_visits`.

**Redefinición de `warehouse.occupied`** (RF-106.5): `presence_known ? presence_active : activeVisit`,
con `activeVisit = current_visit.outcome='ENTERED' && current_visit.exited_at IS NULL`. Antes era solo
`outcome='ENTERED'`, de ahí que una visita enlazada tapara el `ABSENT`.

Los helpers `presenceKnown`, `presenceActive`, `activeVisit` y `exiting` se calculan tras resolver
`$wsRow` y `$visit`. Sin rutas nuevas y sin llamadas a Tuya: todo sale de BD (estado por push ya
persistido).

## 47.2 Croquis: `croquis-logic.js::deriveCroquis` (lógica pura)

- `personInside = presenceKnown ? (presence === 'PRESENT') : occupied`. Con `presence_state` conocido
  (`PRESENT`/`ABSENT`) **manda el radar** y `occupied` no lo tapa: `ABSENT` → `personInside=false`
  aunque haya visita o un `PRESENT` anterior.
- `UNKNOWN` (ni `PRESENT` ni `ABSENT`): único caso en que se usa `occupied` como **fallback** (visita
  activa sin salida) → muñeco dentro **atenuado**.
- **Fase**: `if (personInside) 'inside' else if (doorOpen || recentOpen) 'near' else 'outside'`. Se
  elimina la rama que recolocaba a `inside` por `recentPresent`: un `PRESENT` anterior a un `ABSENT` ya
  no devuelve el muñeco dentro; `recentPresent` queda solo como diagnóstico.
- **Chip de presencia**: `PRESENT`→`PRESENCIA` (`warn`), `ABSENT`→`VACÍO` (`dim`), `UNKNOWN`→
  `SIN DATOS` (`dim`). `occupied` ya **no** fuerza `PRESENTE`. El fallback atenuado se representa con
  `personInside=true` + chip `SIN DATOS` dim, nunca `PRESENTE`/`PRESENCIA`.
- La función sigue siendo pura (sin red ni DOM).

## 47.3 Panel: `almacen.js` (encabezado y meta)

- `renderHeader()`: prioridad `warehouse.exiting` → **`SALIENDO`**; si no, `warehouse.occupied` →
  **`OCUPADO`**; si no, `warehouse.state !== 'IDLE'` → estado; si no → **`LIBRE`**.
- `renderCroquisMeta()`: "Dentro: …" **solo** si hay presencia real (`live.presence_state === 'PRESENT'`
  / `live.presence_active`). Con `UNKNOWN` + visita activa se muestra un texto atenuado tipo "Visita en
  curso · presencia sin confirmar" (nunca "Dentro"). El caso "última salida / sin actividad" se
  mantiene.
- El croquis consume `deriveCroquis` ya existente; no se añaden fuentes de datos ni polling.

## 47.4 Margen exterior 10 s (migración `0121_warehouse_exterior_margin_10.sql`)

`room_types.warehouse_exterior_margin_seconds = 10` para `ALMACEN_BEBIDAS` (idempotente:
`UPDATE room_types SET warehouse_exterior_margin_seconds=10 WHERE code='ALMACEN_BEBIDAS'`). El motor
(`WarehouseRecordingService::roomConfig()` → `SET_DEADLINE_M`) ya consume `M`; **no** cambia la máquina
de estados (`WarehouseRecordingDecision`) ni el contrato de `EXIT_PENDING` (F81/RF-104.3).

## 47.5 Alcance, derogaciones y no-objetivos

- **Revisa parcialmente**: RF-103.4 (fase `inside` disparada por `recent_presence`) y RF-104.5 (croquis
  `inside` con presencia sin filo `UNKNOWN`).
- **Deroga** la lectura de `warehouse.occupied` como "visita activa" en el croquis y el panel.
- **Vigente**: RF-101 (sin poller/cuota), F81/RF-104.4 (cierre con presencia interior no termina),
  F80/RF-103 (credibilidad de presencia del almacén), F82/RF-105 (listado).
- **Sin cambios**: motor (`WarehouseRecordingDecision`/`WarehouseRecordingService` salvo el margen por
  configuración), recorder, `/dashboard` y el resto de contratos de rutas.

## 47.6 Trazabilidad

| RF | Diseño | Contrato | Tareas |
|---|---|---|---|
| RF-106.1 | §47.2 | §F83.1–F83.2 | TSK-F83-02 |
| RF-106.2 | §47.1–47.3 | §F83.1–F83.2 | TSK-F83-01, TSK-F83-02, TSK-F83-03 |
| RF-106.3 | §47.4 | §F83.1 | TSK-F83-04 |
| RF-106.4 | §47.5 | §F83.2 | TSK-F83-05 |
| RF-106.5 | §47.1 | §F83.1 | TSK-F83-01, TSK-F83-03 |
| RF-106.6 | §47.5 | §F83.3 | TSK-F83-05 |

---

# 48. F84 — Antiruido del radar del almacén y reproducción fiel de la puerta (RF-107/RF-108)

## 48.1 Problema y evidencia

La madrugada del 2026-10-06 (sala 12, vacía) el radar produjo 73 episodios
`move`→`presence`→`none` y F80/RF-103 los aceptó al instante (con o sin contexto), creando 76
visitas `PRESENCE` y ~1,1 GB de clips. El crudo de un falso positivo es **idéntico** al de una
entrada real (mismo DP `presence_state`; no hay distancia/confianza fiable). F80 se mantiene por
requisito del operador (presencia con o sin contexto: p. ej. volver tras dejar la puerta abierta).

Análisis de 60 h (91 episodios) sobre `presence_events`:

| Grupo | Patrón dominante | Conclusión |
|---|---|---|
| Sin evento de puerta (fantasmas) | `m1 p1` (1 `move`, 1 `presence`), 20-180 s; **ninguno** con ≥2 `move` | parpadeo único |
| Con puerta (actividad real) | `move`/`presence` **repetidos** (ej. 07:00: 6 eventos en 64 s; episodio largo: 135 `move`) | el radar reemite transiciones al moverse la persona |

## 48.2 Clasificador puro `PresenceEvidence` (nuevo)

`App\Domain\Warehouse\PresenceEvidence::isConfirmed(int $moves, int $events, float $seconds, bool $doorEvent, array $config): bool`
— función pura, sin I/O, testeable:

```
confirmed = moves  >= config.min_moves    (def. 2)
         || events >= config.min_events   (def. 3)
         || seconds >= config.static_seconds (def. 300)
         || doorEvent
```

`doorEvent` (evento `PROXIMITY` aplicado dentro del episodio) es **evidencia adicional**, nunca un
veto: respeta RF-107.1 (con o sin contexto).

## 48.3 Motor: evaluación en el fin del episodio

`WarehouseRecordingService::onSignal()`:

- La creación/provisión no cambia: `IDLE + EV_PRESENT` crea visita (`PRESENCE`) y arranca cámaras
  (F80 intacto).
- En `RECORDING_INSIDE` con `EV_ABSENT` o `EV_DOOR_CLOSE_ABSENT` y `entry_trigger='PRESENCE'`, antes
  de decidir se calcula la evidencia con una consulta local (sin cuota):
  - ventana `[visit.created_at - 2 s, now]`;
  - `COUNT(DISTINCT event_fingerprint)` de `PRESENT`, y de ellos los `tuya_raw_val='move'`;
  - `EXISTS` de `PROXIMITY` aplicado en la ventana;
  - duración desde `visit.entered_at`.
- Se pasa `presence_confirmed` a `WarehouseRecordingDecision::decide()` (nuevo parámetro de contexto,
  mismo patrón que `SensorEventDecision`).
  - `confirmed=true` → ruta actual a `EXIT_PENDING` (STOP_INT, MARK_EXIT, M).
  - `confirmed=false` → nuevo camino de ruido: `STOP_EXT`, `STOP_INT`, `DISCARD_EXT`, `DISCARD_INT`,
    `A_MARK_NOISE` (visita `outcome='NOISE'`), estado `IDLE` (sin margen `M`).
- Los clips ya solicitados se descartan vía `discard_requested=1`; el recorder borra los ficheros
  (F64/F77.6). Las filas de `warehouse_visits`/`camera_recordings` se conservan como auditoría.

## 48.4 Migración `0122`

- `ALTER TABLE warehouse_visits MODIFY outcome ENUM('ENTERED','NO_SHOW','ANONYMOUS','DENIED','NOISE')`.
- `ALTER TABLE room_types ADD COLUMN warehouse_presence_min_moves TINYINT UNSIGNED NOT NULL DEFAULT 2`,
  `warehouse_presence_min_events TINYINT UNSIGNED NOT NULL DEFAULT 3`,
  `warehouse_presence_static_seconds INT UNSIGNED NOT NULL DEFAULT 300` (idempotente: `ADD COLUMN IF
  NOT EXISTS`).
- `UPDATE room_types SET ... WHERE code='ALMACEN_BEBIDAS'` para fijar los defaults del almacén.

## 48.5 Listado y contrato

`WarehouseVisitController::index()` oculta `outcome='NOISE'` por defecto (además del `NO_SHOW` DOOR ya
oculto). `include_noise=1` o `outcome=NOISE` los muestran. `show()` sigue accesible por id para
auditoría.

## 48.6 Reproducción fiel de la puerta

`WarehouseVisitController::show()` adjunta `visit.door` leyendo `presence_events`
(`sensor='PROXIMITY'`, `applied=1`): `state_at_start` (último valor aplicado antes de
`created_at`) y `events[]` en `[created_at - 10 s, fin + margen]`. `visit-playback.js` convierte esos
eventos en intervalos OPEN→CLOSED y `frameAt()` deriva `doorOpen`/chip/descripción de ellos; sin
eventos, puerta cerrada. Fallback sintético solo si `door` no viene (compatibilidad).

## 48.7 Alcance y derogaciones

- **No se toca**: el detector (config/hardware), `SensorEventDecision`, `IotSessionService`,
  `TuyaSensorIngress`, el recorder, `/dashboard` ni RF-101 (sin poller/cuota).
- **Revisa** el efecto de RF-103.2 (la visita por presencia puede acabar en `NOISE` si no hay
  evidencia) y la representación de RF-78 (replay deja de inventar la puerta).
- **Vigente**: F80/RF-103 (credibilidad al instante), F81/RF-104, F82/RF-105, F83/RF-106.

## 48.8 Trazabilidad

| RF | Diseño | Contrato | Tareas |
|---|---|---|---|
| RF-107.1–107.2 | §48.1–48.3 | §F84.2 | TSK-F84-02, TSK-F84-03 |
| RF-107.3–107.4 | §48.3, §48.5 | §F84.1, §F84.3 | TSK-F84-03, TSK-F84-04 |
| RF-107.5 | §48.4 | §F84.1 | TSK-F84-01 |
| RF-107.6–107.8 | §48.7 | §F84.4 | TSK-F84-06 |
| RF-108.1 | §48.6 | §F84.3 | TSK-F84-04 |
| RF-108.2–108.4 | §48.6 | §F84.3 | TSK-F84-05 |

---

# 49. F85 — Modelo de detección del pack almacén, puerta fiel y re-entradas (RF-109…RF-114)

## 49.1 Problema (evidencia 2026-10-06, sala 12)

- **Re-entrada fusionada**: `13:34:43` `DOOR_OPEN` → visita 238; `13:35:58` `ABSENT` →
  `EXIT_PENDING` (`exited_at`); `13:36:04` `PRESENT` → el motor volvía a `RECORDING_INSIDE` de la
  **misma** visita (`WarehouseRecordingDecision`, `EXIT_PENDING + PRESENT`). Se esperaban **2**
  visitas.
- **Puerta atascada**: `13:34:43` `PROXIMITY OPEN` aplicado; **ningún** `CLOSED` en
  `presence_events`, `systemd.log` (RAW) ni `pulsar-consumer.log`. `door_state=OPEN`,
  `door_age>300 s`, `door_stale=true`. La última sonda REST fue `11:25:52Z`; la relectura F75 solo
  corre al abrir el panel con cooldown 30 min. Sin resync periódico (F74), no hay red de seguridad.

## 49.2 Modelo de detección (almacén ≠ dashboard)

En packs `ALMACEN_BEBIDAS`, una **visita** es una estancia; se abre por el **primer** disparador de
`{QR_OK, DOOR_OPEN, PRESENCE}` y se cierra cuando **deja de haber presencia**. Es independiente del
modelo de habitaciones de hotel (`/dashboard`), que **no se toca**.

| Disparador | Semántica | `entry_trigger` |
|---|---|---|
| `QR_OK` | Lectura de QR correcta (abre el relé) | `QR` |
| `DOOR_OPEN` | Apertura física del contacto de puerta (o manual del panel, RF-111.4) | `DOOR` |
| `PRESENCE` | Radar 24G detecta personas dentro (entrada sin QR / puerta abierta) | `PRESENCE` |

Dedupe: el estado `warehouse_state.state` decide si un disparador inicia ciclo (`IDLE`) o es parte del
ciclo en curso.

## 49.3 Máquina de estados revisada

`WarehouseRecordingDecision::decide()` (pura). Cambios F85 marcados con **▲**; `EXTERIOR_ONLY` queda
**deprecado** (sin transición que lo alcance) pero se conserva el enum por compatibilidad.

| Estado | Evento | Acciones | Destino |
|---|---|---|---|
| IDLE | `QR_OK` | crear(QR), EXT+INT ENTRY, `X` | QR_PENDING |
| IDLE | `DOOR_OPEN` | crear(DOOR), EXT+INT ENTRY, `X` | QR_PENDING |
| IDLE | `PRESENT` | crear(PRESENCE, ENTERED), EXT+INT | RECORDING_INSIDE |
| QR_PENDING | `PRESENT` | `CONFIRM_ENTRY`, `CLEAR_DEADLINES` | RECORDING_INSIDE |
| QR_PENDING | `X_EXPIRED` **▲** | `STOP_EXT+INT`, `DISCARD_EXT+INT`, `VISIT_NO_SHOW`, `CLOSE_VISIT` | IDLE |
| QR_PENDING | `DOOR_CLOSE`/`DOOR_OPEN` | no-op | QR_PENDING |
| RECORDING_INSIDE | `ABSENT`/`DOOR_CLOSE_ABSENT` | `STOP_INT`, `MARK_EXIT`, `M` | EXIT_PENDING |
| RECORDING_INSIDE | `DOOR_CLOSE`/`DOOR_OPEN` | no-op | RECORDING_INSIDE |
| EXIT_PENDING | `PRESENT` **▲** | `MARK_EXIT`(prev), `STOP_EXT`(prev), **`CREATE_VISIT`(PRESENCE)**, `CONFIRM_ENTRY`, `START_EXT+INT`, `CLEAR_DEADLINES` | RECORDING_INSIDE (visita nueva) |
| EXIT_PENDING | `M_EXPIRED` | `STOP_EXT`, `CLOSE_VISIT` | IDLE |
| QUERY (F84) | `ABSENT`/`DOOR_CLOSE_ABSENT` con `entry_trigger=PRESENCE` y sin evidencia | `STOP_EXT+INT`, `DISCARD_EXT+INT`, `MARK_NOISE`, `CLOSE_VISIT` | IDLE |

Notas:
- **▲ QR sin entrada** (`RF-110.1/110.3`): se descarta todo (antes, con trigger `QR`, se conservaba
  el EXTERIOR en `EXTERIOR_ONLY`). Revisa RF-102.3 para los `NO_SHOW` de QR.
- **▲ Re-entrada** (`RF-109.4`): finaliza la visita anterior en su `exited_at`, **detiene su clip
  EXTERIOR** y crea una visita nueva con sus propios clips de entrada. No se fusionan ciclos.
- La reaparición en `EXIT_PENDING` sigue sin reabrir la visita anterior.

## 49.4 Deduplicación de disparadores

- `IDLE` es el único estado que **crea** visita con `QR_OK`/`DOOR_OPEN`/`PRESENT`.
- `QR_PENDING` solo avanza con `PRESENT` (o expira `X`); `DOOR_OPEN`/`DOOR_CLOSE` son no-op.
- `RECORDING_INSIDE` solo cierra con `ABSENT`/`DOOR_CLOSE_ABSENT`.
- `EXIT_PENDING` solo avanza con `PRESENT` (nueva visita) o `M` expira.
- Resultado: QR+apertura+presencia = 1 visita (`QR`); un segundo `DOOR_OPEN` no duplica.

## 49.5 Re-entrada: gestión de clips (evita doble ffmpeg)

La visita anterior y la nueva no pueden compartir fila de `camera_recordings` (el detalle y el replay
se consultan por `visit_id`). Al re-entrar:

1. `A_STOP_EXT` (visit_id anterior) marca `stop_requested=1` en su EXTERIOR.
2. `A_CREATE_VISIT` + `A_START_EXT/INT` insertan las filas `PENDING` de la visita nueva.
3. El recorder **no arranca** un `PENDING` cuyo `device_id` ya tenga una fila `RECORDING`
   (**guarda nueva**); en el mismo tick para el EXTERIOR anterior y en el siguiente arranca el nuevo.
   Ventana de ~1 s, aceptable.

## 49.6 Estado de puerta fiel y recuperación acotada

`POST /almacen-api/sensors/refresh` (RF-111.2):

```
doorStale  = state.live.door_stale === true
unresolved = iot_sessions.last_door_event_at > devices.meta_json.status_probed_at
probe      = !cooldownActive || (doorStale && unresolved)
```

- Si `probe === false` → `{ok:true, probed:false, reason:'throttled'}` (sin gastar crédito).
- Si se fuerza por transición sin resolver → respuesta **aditiva** `reason:'unresolved_transition'`.
- Sigue respetando `api/run/tuya-quota.json` (presupuesto/backoff compartido).

El panel (`almacen.js`), además de la lectura al arrancar (F75), dispara **una** sonda cuando el SSE
publica un snapshot con `live.door_stale===true` (una vez por transición; sin temporizador). Sin
poller (RF-101).

## 49.7 Apertura manual del panel

`POST /almacen-api/door/open` → tras `lockService->open(...)`, llama
`warehouseRecorder->onSignal(roomId, EV_DOOR_OPEN, ['provider'=>'PANEL','source'=>'panel'])`. El
disparador real (push del contacto) queda deduplicado por el estado `QR_PENDING`. Si no hay presencia
dentro de `X`, aplica RF-110.2 (descarte). No cambia la respuesta del endpoint (aditiva si se
añade `visit_started`).

## 49.8 Evidencia de re-entradas

`WarehouseRecordingService::presenceEvidenceConfirmed()` añade contexto de ciclo real: existe una
visita previa en la misma sala con `entry_trigger IN ('DOOR','QR')`, `outcome='ENTERED'` y
`exited_at` dentro de `now - warehouse_reentry_context_seconds` (def. 300 s) → el episodio de
presencia se confirma (no `NOISE`). Sin ciclo real reciente se aplica F84/RF-107.2 sin cambios. Solo
BD local (sin cuota).

## 49.9 Panel (`/almacen`)

- Filtro de resultados: añadir la opción **`NOISE`** y un checkbox **"mostrar descartes"** que usa
  `include_noise=1` / `include_no_show=1` (RF-113). Reverifica que el filtro actual (sin `NOISE`) no
  permite auditar F84 desde la UI.
- El croquis ya pinta `PUERTA SIN DATOS` con `door_stale` (F77.5); con RF-111 se auto-recupera al
  recibir el estado real.
- "Actualizar estado" se mantiene; ahora también funciona dentro del cooldown si hay transición sin
  resolver.

## 49.10 Migración

- `ALTER TABLE room_types ADD COLUMN IF NOT EXISTS warehouse_reentry_context_seconds INT UNSIGNED NOT
  NULL DEFAULT 300` (migración `0123`).
- `UPDATE room_types SET warehouse_reentry_context_seconds=300 WHERE code='ALMACEN_BEBIDAS'`.
- `warehouse_visits.outcome` ya admite `NOISE` (F84 `0122`). Sin cambios de forma en otras tablas.

## 49.11 Alcance y derogaciones

- **No se toca**: el detector (hardware/config), `SensorEventDecision`, `TuyaSensorIngress`,
  `IotSessionService` (salvo el enganche de la apertura manual), el recorder (salvo la guarda de
  `PENDING`), `/dashboard` ni RF-101.
- **Deroga**: el comportamiento de `EXTERIOR_ONLY` en la expiración de `QR_PENDING` por trigger `QR`
  (RF-110.1) y la fusión de la re-entrada en `EXIT_PENDING` (RF-109.4).
- **Vigente**: F80/RF-103, F81/RF-104, F82/RF-105, F83/RF-106, F84/RF-107-108 (con la salvedad
  RF-112.1).

## 49.11bis Riesgo: fragmentación por parpadeo del radar

El modelo RF-109.3 ("salida = dejar de haber presencia") hace que cualquier `ABSENT` seguido de
`PRESENT` cree visita nueva. Si el 24G emite un parpadeo `presence→none→presence` durante una misma
estancia, se fragmentaría en dos visitas. Mitigaciones previstas:

- RF-112 confirma la nueva visita solo si hay ciclo real reciente; si el parpadeo ocurre **dentro** de
  una visita real, el contexto la confirmaría igualmente → se acepta el riesgo (el operador prefiere
  no perder re-entradas).
- Si en campo se observa fragmentación, se puede introducir un `warehouse_exit_debounce_seconds`
  (def. 0 = desactivado) que exija que el `ABSENT` se mantenga antes de cerrar la visita, sin tocar
  el resto del modelo. Queda documentado como ajuste futuro, no implementado en F85.

## 49.12 Trazabilidad

| RF | Diseño | Contrato | Tareas |
|---|---|---|---|
| RF-109.1–109.3 | §49.2–49.4 | §F85.2 | TSK-F85-02 |
| RF-109.4 | §49.3, §49.5 | §F85.2 | TSK-F85-02, TSK-F85-03 |
| RF-110.1–110.3 | §49.3 | §F85.2 | TSK-F85-02 |
| RF-110.4–110.7 | §49.4, §49.6 | — | TSK-F85-02, TSK-F85-05 |
| RF-111.1–111.3 | §49.6 | §F85.1 | TSK-F85-04, TSK-F85-05 |
| RF-111.4 | §49.7 | §F85.1 | TSK-F85-05 |
| RF-112 | §49.8 | §F85.1 | TSK-F85-01, TSK-F85-03 |
| RF-113 | §49.9 | §F85.3 | TSK-F85-06 |
| RF-114 | §49.11 | §F85.4 | TSK-F85-07 |

---

# 50. F86 — Puerta fiel ante reportes repetidos y re-entrada con contexto (RF-115)

## 50.1 Problema

El MC400D es **edge-triggered**: solo reporta cuando cambia. Si un `CLOSED` se pierde, el dominio
queda `OPEN` "atascado"; una apertura real posterior reporta `OPEN` otra vez y `SensorEventDecision`
la clasifica `noop` (mismo valor), con lo que: no se refresca la frescura, `door_stale` sigue
`true`, no hay push SSE (el fingerprint no cambia) y el motor del almacén **no recibe** el
disparador `DOOR`, creando la visita por `PRESENCE` cuando llega el radar.

## 50.2 Decisión `refresh` (pura)

En `SensorEventDecision::decide()`, tras el dedup por fingerprint/instante y antes del `noop`:

```
si  es PROXIMITY
y   value === lastDoorValue (no nulo)
y   provider === TUYA
y   meta.source !== 'resync'
→ REFRESH
```

- Un reporte **real** del mismo valor con instante nuevo es un **edge** del sensor: la puerta
  cambió a ese estado cuando el dominio creía el anterior.
- El resync REST se excluye (reconciliación, no transición; F77.5). Las inyecciones simuladas y el
  mismo instante exacto siguen `noop`/`duplicate`.
- La identidad lógica (`fingerprint`) no cambia: mismo segundo + mismo valor siguen colapsando.

## 50.3 Efectos del refresh (`IotSessionService`)

- `lastDoorEventAt = evtUtc`; `lastOpenAt`/`lastCloseAt = evtUtc` según el valor. **`doorState` y
  `lastDoorValue` no cambian.**
- Auditoría: `presence_events.applied=0`, `discard_reason='refresh'` (`VARCHAR(16)`; sin migración).
- Post-commit (mismos efectos que un APPLY de puerta):
  - luz: `turnOn` en `OPEN` (F28);
  - motor del almacén: `onSignal(EV_DOOR_OPEN|EV_DOOR_CLOSE)`; en `IDLE`, `DOOR_OPEN` crea visita
    `DOOR` (RF-109).
- El sondeo `sensors/refresh` llega con `source='real'` ⇒ refresca y limpia `door_stale`. El resync
  del consumer lleva `source='resync'` ⇒ no.

## 50.4 Re-entrada con contexto de puerta

`WarehouseRecordingService::presenceEvidenceConfirmed()` añade a RF-112 el requisito
`iot_sessions.door_state='OPEN'` además de la visita real reciente
(`entry_trigger IN ('DOOR','QR')`, `ENTERED`, `exited_at` dentro de la ventana). Un fantasma con la
puerta cerrada no se confirma.

## 50.5 Alcance

- **No se toca**: la UI del croquis (decisión del operador: el sensor manda y el monigote sigue al
  radar hasta el `ABSENT`), el detector/config del 24G, `TuyaSensorIngress`, la firma de eventos ni
  RF-101 (sin poller; el sondeo solo es bajo demanda).
- **Revisa**: F85/RF-112.1 (añade contexto de puerta) y F41/RF-44 (nuevo estado `refresh` auditable).

---

# 51. F87 — Veracidad de presencia y limpieza de grabaciones (RF-116…RF-121)

## 51.1 Problema

El 2026-10-08 se observaron tres defectos encadenados en `/almacen`:

1. **Presencia pegada**: el 24G retuvo `presence_state=presence` **9 min** con la sala vacía y no
   emitió `none`. `iot_sessions.presence_state` siguió `PRESENT` hasta el siguiente `ABSENT`, así que
   el croquis pintó "dentro" y `warehouse_state` quedó `RECORDING_INSIDE`. No existe un umbral de
   frescura de presencia análogo a `DOOR_STALE_SECONDS`.
2. **Fantasmas confirmados**: `PresenceEvidence::isConfirmed` confirmaba por `seconds >= 300`
   (el radar mantiene `PRESENT` >5 min en falso) y por `events >= 3` (contaba `presence` no-op).
   Visitas 505/515/554/569/575/896 (1 solo `move`) quedaron `ENTERED`.
3. **Clips NOISE no borrados**: el tope F73 (`WAREHOUSE_MAX_RECORDING_SECONDS=60`) finaliza los
   clips como `SAVED`; `requestDiscard` solo marcaba `PENDING`/`RECORDING` y el recorder nunca
   revisa `SAVED` → 410 clips = 4,7 GB. Además `warehouse-retention.php` no tiene timer/cron.

## 51.2 Frescura de presencia (`WarehouseStateController` + croquis)

- `stateArray()` calcula `live.presence_stale`:
  `presence_state === 'PRESENT' && (presence_age_seconds === null || > PRESENCE_STALE_SECONDS)`.
  Nueva config `PRESENCE_STALE_SECONDS` (def. **180**), helper `presenceStaleSeconds()` espejo de
  `doorStaleSeconds()`.
- `warehouse.occupied`: si la presencia es conocida, `occupied = presenceActive && !presenceStale`
  (asesorar `LIBRE` si la señal está vieja); si es `UNKNOWN`, se mantiene el fallback a visita
  activa. La visita y `warehouse_state` **no** se tocan (RF-116.2).
- `croquis-logic.js`: `const presenceStale = live.presence_stale === true;`
  `personInside = presenceStale ? false : (presenceKnown ? presence==='PRESENT' : occupied)`;
  chip de presencia "SIN DATOS" y `phase` no `inside` cuando `presenceStale`.
- El SSE ya incluye `live` en el fingerprint; `presence_stale` entra (cambia al cruzar el umbral) y
  `presence_age_seconds` sigue excluido del fingerprint.

## 51.3 Evidencia reforzada (`PresenceEvidence` + `WarehouseRecordingService`)

- `PresenceEvidence::isConfirmed(moves, events, seconds, doorEvent, config)`:
  ```
  if (moves >= min_moves) return true;
  if (seconds >= static_seconds) return true;   // def. 1800
  return $doorEvent;
  ```
  `events`/`min_events` dejan de participar (RF-117.2). Se mantienen `doorEvent` (F84) y, en el
  servicio, el cortocircuito de re-entrada F85 y el requisito de puerta abierta F86.
- `WarehouseRecordingService::presenceEvidenceConfirmed()`: la consulta sigue contando `moves`
  (solo `tuya_raw_val='move'`) y `doorEvent` (PROXIMITY aplicado). Se sigue pasando `events` por
  compatibilidad de firma, sin efecto.
- Migración `0124`: `warehouse_presence_static_seconds` default **1800** y valor 1800 para
  `ALMACEN_BEBIDAS` (idempotente). `warehouse_presence_min_events` queda como columna vestigial.

## 51.4 Grabación fiel al ciclo de puerta (`WarehouseRecordingDecision` + servicio)

- Nueva acción `A_ENSURE_RECORDING`. En `STATE_RECORDING_INSIDE`, `EV_DOOR_OPEN` pasa de `[]` a
  `[A_ENSURE_RECORDING]` (el estado no cambia). Es un refuerzo: no crea visitas.
- `WarehouseRecordingService`: en `A_ENSURE_RECORDING`, si no hay filas `PENDING`/`RECORDING` para
  `visit_id`, llama a `startRecordings(EXT)` y `startRecordings(INT)` con
  `episode = episodeFor($preState)` (EXIT en `RECORDING_INSIDE`). Si ya hay una activa, no-op.
- No se reintroduce grabación continua por tiempo: el tope F73 de 60 s se mantiene (decisión del
  operador). Solo una apertura física real puede reanudar.

## 51.5 Descarte efectivo (`WarehouseRecordingService` + `warehouse-recorder.php`)

- `requestDiscard($room, $visit, $position)`:
  `UPDATE camera_recordings SET discard_requested=1, stop_requested=1
   WHERE room_id=:r AND position=:pos AND status <> 'DISCARDED' [AND visit_id=:vid]`.
- `warehouse-recorder.php`: nueva fase tras el stop:
  - `PENDING`/`SAVED`/`FAILED` con `discard_requested=1` → borrar `file_path` (y `poster_path`)
    bajo `data/cameras/` y `UPDATE status='DISCARDED', stop_requested=1, stopped_at=UTC_TIMESTAMP(3)`.
  - Corrige el `Undefined array key "started_at"` seleccionando `started_at` en la consulta de stop
    (o pasando `null`), y `//` el parámetro no usado de `durationSeconds`.
- Limpieza de datos (operativa, no código): borrar los 410 clips NOISE `SAVED` y sus ficheros.

## 51.6 Retención automática (`docs/systemd` + `start-all.sh`)

- `docs/systemd/cerraduras-warehouse-retention.service` (Type=oneshot, ExecStart=`php
  bin/warehouse-retention.php`, WorkingDirectory=`/root/cerraduras/api`) y
  `cerraduras-warehouse-retention.timer` (OnCalendar diario 04:00, `Persistent=true`).
- `start-all.sh` `[5c/8]`: `systemctl enable --now cerraduras-warehouse-retention.timer` si existe
  el unit; `stop-all.sh`: `systemctl stop ...timer`. Sin wrappers ni procesos extra.
- `0124` declara `system_settings (service='api', setting_key='warehouse.retention_days', value='1')`
  con `INSERT ... ON DUPLICATE KEY UPDATE value=value` (no pisa el valor de producción).

## 51.7 Tests

- `api/tests/Unit/PresenceEvidenceTest.php`: `m1 p2` corto → ruido; `moves=2` → real; estático
  1801 s → real; 300 s → ruido; puerta → real; default `static_seconds=1800`.
- `api/tests/Unit/WarehouseRecordingDecisionTest.php`: `RECORDING_INSIDE + DOOR_OPEN` →
  `[A_ENSURE_RECORDING]`; `A_MARK_NOISE` descarta EXT+INT con todos los estados.
- `api/tests/Unit/croquis-logic.test.js`: `presence_stale` → chip SIN DATOS y `personInside=false`.
- `api/bin/run-tests.sh`: **BLOCK 60** con marcadores F87 (presencia estancada → `presence_stale`;
  visita NOISE con clip `SAVED` → `DISCARDED`; timer de retención instalado).

## 51.8 Trazabilidad

| RF | Diseño | Contrato | Tareas |
|---|---|---|---|
| RF-116 | §51.2 | §F87.1, §F87.2 | TSK-F87-01, TSK-F87-02 |
| RF-117 | §51.3 | §F87.3 | TSK-F87-02 |
| RF-118 | §51.4 | §F87.4 | TSK-F87-03 |
| RF-119 | §51.5 | §F87.5 | TSK-F87-04 |
| RF-120 | §51.6 | §F87.6 | TSK-F87-05 |
| RF-121 | §51.2–51.7 | — | TSK-F87-06 |

## 50.6 Trazabilidad

| RF | Diseño | Contrato | Tareas |
|---|---|---|---|
| RF-115.1–115.2 | §50.2–50.3 | §F86.1 | TSK-F86-01, TSK-F86-03 |
| RF-115.3 | §50.2–50.3 | §F86.1 | TSK-F86-01 |
| RF-115.4 | §50.4 | §F86.2 | TSK-F86-02 |
| RF-115.5 | §50.3 | §F86.3 | TSK-F86-01 |
| RF-115.6 | §50.5 | §F86.3 | TSK-F86-04 |

---

# 51. (reservado F87)

---

# 52. F88 — Presencia robusta por fusión de sensores (RF-122…RF-126)

## 52.1 Problema

El radar 24G es la única señal y emite fantasmas con la sala vacía (`move`→`presence`→`none` cada
1–3 min). F80 lo acepta al instante y el sistema graba. F84/F87 solo limpian **a posteriori**
(`NOISE`), pero el ciclo se repite y se sigue grabando. Falta una **verificación física
independiente**.

## 52.2 Worker de movimiento (`bin/camera-motion-worker.js`)

- Node, un `ffmpeg` por cámara habilitada del almacén. Fuente: **restream RTSP de go2rtc**
  (`rtsp://127.0.0.1:8554/<stream>`, `CAMERA_MOTION_RTSP_BASE`) para no añadir conexiones directas
  a la cámara.
- Comando: `ffmpeg -nostdin -rtsp_transport tcp -i <rtsp> -an -vf fps=FPS,scale=W:H -pix_fmt gray
  -f rawvideo -`. El worker acumula `W*H` bytes por frame y compara con el anterior:
  `cambios = #{|px_i - px_prev| > threshold}`; `mov = cambios/frameSize*100 >= MIN_PCT`.
- Histéresis: buffer rodante de N decisiones; `hay = sum(últimas N) >= MIN_FRAMES`. Al pasar a
  `hay`, y respetando `POST_MIN_INTERVAL_MS`, hace `POST /almacen-api/motion {device_id}`.
- Reconexión con backoff si `ffmpeg` sale; nunca loguea la URL. Funciones puras exportadas
  (`parseScale`, `frameDiffPct`, `debounce`) para tests.
- systemd `cerraduras-camera-motion.service` (Restart=always) + `start-all.sh` `[5e/8]` /
  `stop-all.sh`.

## 52.3 Persistencia y endpoint

- Migración `0125`: `devices.last_motion_at DATETIME(3) NULL`.
- `POST /almacen-api/motion` (loopback, sin auth LAN): body `{device_id}` → valida que el device
  sea `CAMERA` de una sala `ALMACEN_BEBIDAS` y hace `UPDATE devices SET last_motion_at =
  UTC_TIMESTAMP(3)`. Devuelve `{ok:true, device_id, at}`.
- `/almacen-api/cameras` (`WarehouseCameraController::formatRow`) y `WarehouseStateController::
  stateArray()` añaden `last_motion_at` y `motion_active` (aditivos).

## 52.4 Fusión en el pipeline IoT

- `WarehouseRecordingServiceInterface` gana `latestMotionAt(int $roomId, ?string $position = null):
  ?string` (MAX de `devices.last_motion_at` de las cámaras de la sala, filtrando por `subtype`).
- `IotSessionService`, para `PRESENT` de radar TUYA en almacén:
  - `doorRecent` = `session->lastDoorEventAt` dentro de `FUSION_DOOR_WINDOW_SECONDS` (def. 90).
  - `motionRecent` = `latestMotionAt($roomId, 'INTERIOR')` dentro de `FUSION_MOTION_WINDOW_SECONDS`
    (def. 20). Con `FUSION_INCLUDE_EXTERIOR=true` también vale el máximo de cualquier cámara. Si la
    sala no tiene cámara INTERIOR, solo la puerta corrobora (modo degradado).
  - `physicalEvidence = doorRecent || motionRecent`.
- `SensorEventDecision::decide(..., bool $warehousePresence = false, bool $physicalEvidence = true)`:
  en la rama de almacén, si `!$physicalEvidence` → devuelve la nueva constante
  `SensorEventDecision::UNCORROBORATED` (auditada, no aplica). Si hay evidencia → `APPLY` (F80 se
  mantiene cuando hay corroboración física). `ABSENT` no pasa por la rama.
- Consecuencia: sin `APPLY` no hay `post-commit`, así que `WarehouseRecordingService::onSignal` no
  recibe `EV_PRESENT` → **sin visita ni grabación**.

## 52.5 Degradación

- Sin worker (`last_motion_at` nulo) el radar aislado queda `uncorroborated`; la **puerta** sigue
  corroborando (edge real). No se pierde una entrada con apertura de puerta.
- Sin cámaras configuradas, sólo la puerta confirma; se documenta como modo degradado.

## 52.6 Diagnóstico y hardware

- `bin/presence-fusion-report.php`: para una ventana temporal lista los eventos `PRESENCE` de la
  sala cruzados con `last_motion_at` de las cámaras, con conteo de `applied`/`uncorroborated`.
  Solo lectura, sin cuota.
- Documentar la revisión física del MC400D y la recalibración del 24G.

## 52.7 Tests

- Unit JS `camera-motion-worker.test.js`: `parseScale`, `frameDiffPct` (frame igual → 0; frame con
  bloque → >0), `debounce`.
- `SensorEventDecisionTest`: almacén + `PRESENT` sin evidencia → `uncorroborated`; con evidencia →
  `apply`; hotel intacto.
- `IotSessionServiceTest`: `FakeWarehouseRecorder::latestMotionAt`; PRESENT con puerta reciente →
  PRESENT; PRESENT sin puerta ni movimiento → no aplica (`uncorroborated`) y no llega señal al motor;
  PRESENT con movimiento → aplica.
- Runner **BLOCK 61** (marcadores F88) + regresión.

## 52.8 Trazabilidad

| RF | Diseño | Contrato | Tareas |
|---|---|---|---|
| RF-122 | §52.2 | §F88.1 | TSK-F88-01 |
| RF-123 | §52.3–52.4 | §F88.2, §F88.3 | TSK-F88-02, TSK-F88-03 |
| RF-124 | §52.3 | §F88.2 | TSK-F88-04 |
| RF-125 | §52.6 | — | TSK-F88-05 |
| RF-126 | §52.7 | — | TSK-F88-06 |
