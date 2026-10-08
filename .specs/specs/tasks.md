# Tasks — Cerraduras Hotel

---

# Fase 1: Robustez firmware ESP32 QR Reader

Archivo a modificar: `docs/esp32-qr-reader/scanner-relay-prod.ino` (640 líneas)

---

## TSK-01: Watchdog timer

**Trazabilidad**: RF-1.1, RF-1.2, RF-1.3
**Archivo**: `scanner-relay-prod.ino`

- [x] Añadir `#include "esp_task_wdt.h"` al principio del archivo (junto a los otros includes, línea ~44).
- [x] En `setup()`, antes del provisioning WiFi (línea ~479), fijar el timeout global del TWDT a 60s:
  ```cpp
  // API según core: core 3.x (IDF 5.x) usa config-struct; 2.x legado (60, true).
#if ESP_ARDUINO_VERSION_MAJOR >= 3
  esp_task_wdt_config_t wdt_cfg = {
      .timeout_ms     = 60000,   // RF-1.1: 60s
      .idle_core_mask = 0,
      .trigger_panic  = true,
  };
  esp_task_wdt_init(&wdt_cfg);
#else
  esp_task_wdt_init(60, true);   // RF-1.1: timeout 60s, panic on timeout (core 2.x)
#endif
  ```
  NOTA (diverge de TSK-01 original): `esp_task_wdt_add(NULL)` NO se hace en `setup()`.
  `loopTask` solo se suscribe en la 1ª iteración de `loop()`, de modo que el
  provisioning largo (WiFiManager, hasta 5 min) nunca queda vigilado con timeout
  corto. No usar `esp_task_wdt_delete(NULL)` en `setup()`: antes de la 1ª iteración
  de `loop()` loopTask aún no está suscrito y esa llamada genera un
  `task_wdt: delete_entry: task not found` benigno en cada arranque.
  NOTA (adaptación core 3.x): `esp_task_wdt_init()` cambió de firma en Arduino-ESP32
  core 3.x (IDF 5.x) a `esp_task_wdt_init(const esp_task_wdt_config_t*)`. La
  configuración de arriba con `#if ESP_ARDUINO_VERSION_MAJOR >= 3` mantiene el
  sketch compilable también en core 2.x.
- [x] En `loop()` (1ª iteración), suscribir `loopTask` y feedear al inicio:
  ```cpp
  esp_task_wdt_add(NULL);   // 1ª iteración únicamente
  esp_task_wdt_reset();     // primera línea de cada iteración
  ```
- [x] Feed de defensa en profundidad: `esp_task_wdt_reset()` + `yield()` antes y
  después de cada grupo HTTP bloqueante del `loop()` (QR-POST, heartbeat + F33,
  announce), ya que cada HTTP puede tardar varios segundos (TLS handshake,
  `getString()` de respuestas grandes) y el timeout global es 60s.

**Verificación**: Compilar y flashear. El ESP32 debe arrancar sin errores (sin
`task_wdt: delete_entry` en el log). Si se introduce un `while(true){}` de prueba,
el ESP32 debe reiniciarse solo a los 60s.

---

## TSK-02: Relé no bloqueante

**Trazabilidad**: RF-2.3
**Archivo**: `scanner-relay-prod.ino`

- [ ] Añadir variables globales (junto a las otras globales, ~línea 69):
  ```cpp
  unsigned long relayOffAt = 0;
  bool          relayPulsing = false;
  ```
- [ ] Modificar `relayPulse()` (líneas 140-146): eliminar `delay(OPEN_DURATION_MS)` y `relayOff()`. Dejar solo:
  ```cpp
  void relayPulse() {
    Serial.println("[RELAY] → ON (abriendo pestillo)");
    relayOn();
    relayOffAt = millis() + OPEN_DURATION_MS;
    relayPulsing = true;
  }
  ```
- [ ] En `loop()`, antes de `yield()` (última línea), añadir:
  ```cpp
  if (relayPulsing && millis() > relayOffAt) {
    relayOff();
    relayPulsing = false;
    Serial.println("[RELAY] → OFF (pestillo cerrado)");
  }
  ```

**Verificación**: Validar un QR. El relé debe activarse y desactivarse exactamente 3s después. El loop no debe bloquearse durante esos 3s (el heartbeat debe seguir funcionando).

---

## TSK-03: LED feedback no bloqueante

**Trazabilidad**: RF-2.4
**Archivo**: `scanner-relay-prod.ino`

- [ ] Añadir variable global:
  ```cpp
  unsigned long ledOffAt = 0;
  ```
- [ ] Modificar `wifiConnectedFeedback()` (líneas 96-105): eliminar `delay(LED_ON_MS)` y `digitalWrite(LED_PIN, LOW)`. Dejar:
  ```cpp
  void wifiConnectedFeedback() {
    Serial.println("[OK] ¡WiFi conectado! IP: " + WiFi.localIP().toString());
    pinMode(LED_PIN, OUTPUT);
    digitalWrite(LED_PIN, HIGH);
    relayClick();
    ledOffAt = millis() + LED_ON_MS - RELAY_CLICK_MS;
    Serial.printf("[OK] LED se apagara en %d ms\n", LED_ON_MS);
  }
  ```
- [ ] En `loop()`, junto al bloque del relé no bloqueante, añadir:
  ```cpp
  if (ledOffAt && millis() > ledOffAt) {
    digitalWrite(LED_PIN, LOW);
    ledOffAt = 0;
    Serial.printf("[OK] LED apagado\n");
  }
  ```

**Verificación**: Al conectar WiFi por primera vez, el LED debe encenderse ~4.5s y apagarse solo. El loop no debe bloquearse.

---

## TSK-04: QR pendiente sin WiFi — no bloqueante

**Trazabilidad**: RF-2.2
**Archivo**: `scanner-relay-prod.ino`

- [ ] En `loop()`, dentro del bloque `if (hasPending)` (líneas 461-518), modificar la rama `!ensureWiFi()` (líneas 462-465):
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

**Verificación**: Desconectar WiFi, escanear un QR. El ESP32 debe loguear el warning cada 5s sin bloquearse.

---

## TSK-05: Eliminar `delay(1)` del final del loop

**Trazabilidad**: RF-2.1
**Archivo**: `scanner-relay-prod.ino`

- [ ] Reemplazar `delay(1)` (línea 639) por `yield()`.

**Verificación**: Comportamiento idéntico, sin bloqueo de 1ms.

---

## TSK-06: String::reserve en construcción de JSON

**Trazabilidad**: RF-3.1, RF-3.2, RF-3.3, RF-3.4, RF-3.5
**Archivo**: `scanner-relay-prod.ino`

- [ ] En el POST QR validate (línea ~483): añadir `body.reserve(600);` antes de la concatenación.
  ```cpp
  String body;
  body.reserve(600);
  body = "{\"qr_text\":\"" + qrEscaped + "\",\"device_id\":\"" + id + "\"}";
  ```
- [ ] En `sendIdentify()` (línea ~251): añadir `body.reserve(200);`.
- [ ] En el heartbeat batch (línea ~534): añadir `hbBody.reserve(250);`.
- [ ] En command-result (línea ~588): añadir `resultBody.reserve(350);`.

**Verificación**: Compilar. Sin cambios funcionales. Para verificar la ausencia de fragmentación, monitorizar el heap en Serial durante varias horas de uso.

---

## TSK-07: WiFi watchdog — auto-reinicio tras 2 min sin conexión

**Trazabilidad**: RF-4.1, RF-4.2, RF-4.3, RF-4.4
**Archivo**: `scanner-relay-prod.ino`

- [ ] Añadir variable global:
  ```cpp
  unsigned long wifiDownSince = 0;
  ```
- [ ] En `loop()`, después del bloque de heartbeat y antes del bloque de identify, añadir:
  ```cpp
  if (WiFi.status() != WL_CONNECTED) {
    if (wifiDownSince == 0) {
      wifiDownSince = millis();
      Serial.println("[WIFI] Conexion perdida — contador 120s para reinicio");
    } else if (millis() - wifiDownSince > 120000) {
      Serial.println("[WIFI] 120s sin conexion — ESP.restart()");
      delay(500);
      ESP.restart();
    }
  } else {
    wifiDownSince = 0;
  }
  ```

**Verificación**: Desconectar el router WiFi. El ESP32 debe loguear "Conexion perdida" y, tras exactamente 120s, reiniciarse solo.

---

## TSK-08: Log de heap en heartbeat

**Trazabilidad**: RF-6.1, RF-6.2
**Archivo**: `scanner-relay-prod.ino`

- [ ] En el bloque de heartbeat (línea ~521), justo después de `lastBeat = now;`, añadir:
  ```cpp
  unsigned long heap = ESP.getFreeHeap();
  Serial.printf("[HB] Free heap: %lu bytes\n", heap);
  if (heap < 20480) {
    Serial.printf("[HB] ⚠️ ADVERTENCIA: heap bajo (%lu bytes)\n", heap);
  }
  ```

**Verificación**: El Serial debe mostrar `[HB] Free heap: XXXXX bytes` cada 30s. El heap no debe decrecer de forma continua (indicaría fuga de memoria).

---

## TSK-09: Compilación, flasheo y smoke test

**Trazabilidad**: RF-5
**Archivo**: `scanner-relay-prod.ino`

- [ ] Compilar el sketch con PlatformIO/Arduino IDE para placa `ESP32-S3-USB-OTG`.
- [ ] Verificar que compila sin errores ni warnings.
- [ ] Flashear al ESP32 físico.
- [ ] Smoke test: arrancar, conectar WiFi, escanear QR válido → relé se activa 3s, heartbeat 30s OK.
- [ ] Test watchdog: introducir `while(true){}` en loop, verificar reinicio a los 60s.
- [ ] Test WiFi guard: desconectar WiFi 2 min, verificar reinicio automático.

---

## Resumen de tareas

| TSK | Descripción | Líneas afectadas | Variables nuevas |
|-----|-------------|-----------------|------------------|
| 01 | Watchdog timer | +1 include, +2 setup, +1 loop | 0 |
| 02 | Relé no bloqueante | ~6 cambios en relayPulse + loop | `relayOffAt`, `relayPulsing` |
| 03 | LED no bloqueante | ~5 cambios en wifiConnectedFeedback + loop | `ledOffAt` |
| 04 | QR sin WiFi no bloqueante | ~5 cambios en loop | 0 |
| 05 | delay(1) → yield() | 1 cambio en loop | 0 |
| 06 | String::reserve JSON | 4 inserciones de `.reserve(N)` | 0 |
| 07 | WiFi watchdog 2 min | ~12 líneas en loop | `wifiDownSince` |
| 08 | Log heap heartbeat | ~5 líneas en heartbeat | 0 |
| 09 | Compilar, flashear, test | — | — |

---

# Fase 35: Detección de anomalías en el flujo de sensores

## TSK-35.01: Migración — tabla `anomalies`

**Trazabilidad**: RF-35.1.1, RF-35.3.1, RF-35.5.1
**Archivos**: `api/bin/migrate.php` (nueva migración)

- [ ] Crear migración SQL para la tabla `anomalies` según el schema definido en design §1.1.
- [ ] Ejecutar migración y verificar que la tabla se crea correctamente.

---

## TSK-35.02: Modelo `Anomaly`

**Trazabilidad**: RF-35.1.1, RF-35.5.1
**Archivo**: `api/src/Domain/Anomalies/Anomaly.php`

- [ ] Crear clase `Anomaly` con propiedades: `id`, `roomId`, `stayId`, `anomalyType`, `severity`, `status`, `contextData`, `detectedAt`, `acknowledgedAt`, `acknowledgedBy`, `dismissedAt`, `dismissedBy`.
- [ ] Métodos `acknowledge(string $actor)` y `dismiss(string $actor)` con guardas de estado.
- [ ] Método `toArray(): array` para serialización JSON.

---

## TSK-35.03: Interfaz `AnomalyDetector` + DTO `AnomalyResult`

**Trazabilidad**: RF-35.2.1
**Archivos**: `api/src/Domain/Anomalies/AnomalyDetector.php`, `api/src/Domain/Anomalies/AnomalyResult.php`

- [ ] Definir interfaz `AnomalyDetector` con método `detect(IotSession, PresenceEvent, ?Stay): ?AnomalyResult`.
- [ ] Definir DTO `AnomalyResult` con `type`, `severity`, `contextData`.

---

## TSK-35.04: Repositorio `AnomalyRepository`

**Trazabilidad**: RF-35.3.2, RF-35.4.2
**Archivos**: `api/src/Domain/Anomalies/AnomalyRepositoryInterface.php`, `api/src/Infrastructure/Persistence/AnomalyRepository.php`

- [ ] Interfaz: `save(Anomaly)`, `findOpenByRoomAndType(int roomId, string type): ?Anomaly`, `findByRoom(int roomId, ?string status): array`, `findAll(array filters): array`.
- [ ] Implementación PDO con `INSERT`, `SELECT` con filtros, `UPDATE` para acknowledge/dismiss.

---

## TSK-35.05: Detectores A1–A8

**Trazabilidad**: RF-35.A1.1–RF-35.A8.3
**Archivos**: `api/src/Domain/Anomalies/Detectors/A1_PresenceWithoutDoorOpen.php` a `A8_SensorFlapping.php`

- [ ] **A1**: `PresenceWithoutDoorOpen` — PRESENCE=PRESENT sin `PROXIMITY=OPEN` desde `first_entry_at`.
- [ ] **A2**: `PresenceWithoutStay` — PRESENCE=PRESENT sin stay OCCUPIED/EXITED.
- [ ] **A3**: `DoorOpenWithoutQr` — PROXIMITY=OPEN sin QR_VALIDATE en ventana 30s y sin stay OCCUPIED.
- [ ] **A4**: `PresenceWithoutDoorActivity` — presencia ininterrumpida > (duración + 2h) sin eventos PROXIMITY.
- [ ] **A5**: `ExitedWithoutDoorOpen` — EXITED sin `PROXIMITY=OPEN` de salida (<2h del exit).
- [ ] **A6**: `PresenceAfterExit` — PRESENCE=PRESENT tras stay EXITED.
- [ ] **A7**: `DoorOpenWithoutPresence` — door OPEN + absence >120s.
- [ ] **A8**: `SensorFlapping` — ≥5 transiciones mismo sensor en 30s.

---

## TSK-35.06: `AnomalyPipeline` — orquestador

**Trazabilidad**: RF-35.2.1
**Archivo**: `api/src/Domain/Anomalies/AnomalyPipeline.php`

- [ ] Registrar los 8 detectores en orden.
- [ ] `evaluate()` itera detectores, captura excepciones individuales (non-blocking), retorna `AnomalyResult[]`.

---

## TSK-35.07: `AnomalyService`

**Trazabilidad**: RF-35.2.1, RF-35.3.2, RF-35.3.3
**Archivo**: `api/src/Domain/Anomalies/AnomalyService.php`

- [ ] `detectAndPersist()`: ejecuta pipeline → deduplica (room+type+OPEN) → persiste nuevas.
- [ ] `acknowledge()`: valida status OPEN → transiciona a ACKNOWLEDGED.
- [ ] `autoResolve()`: para cada anomalía OPEN en una room, re-evalúa la condición; si ya no se cumple → DISMISSED.

---

## TSK-35.08: Integración en `IotSessionService`

**Trazabilidad**: RF-35.2.1
**Archivo**: `api/src/Domain/Presence/IotSessionService.php`

- [ ] Tras persistir `iot_session` actualizado (después de §4 de `processEvent`), inyectar `AnomalyService::detectAndPersist(session, trigger, activeStay)`.
- [ ] Asegurar que las excepciones del pipeline de anomalías no interrumpen el flujo normal.

---

## TSK-35.09: Integración A5 en `ExitActionService`

**Trazabilidad**: RF-35.A5.1
**Archivo**: `api/src/Domain/Presence/ExitActionService.php`

- [ ] Al final de `execute()`, evaluar A5: si `last_open_at` es null o >2h antes de `exit_detected_at`, crear anomalía A5 vía `AnomalyService`.

---

## TSK-35.10: Worker `anomaly-scanner.php`

**Trazabilidad**: RF-35.2.2
**Archivo**: `api/bin/anomaly-scanner.php`

- [ ] Script PHP standalone que itera rooms con `iot_session` activo.
- [ ] Evalúa A4 (presencia sin actividad puerta) y A7 (puerta abierta sin presencia).
- [ ] Ejecuta `autoResolve()` para cerrar anomalías cuya condición desapareció.
- [ ] Se ejecuta cada 15s desde `start-all.sh`.

---

## TSK-35.11: Controller `AnomalyController`

**Trazabilidad**: RF-35.4.2, RF-35.4.3
**Archivo**: `api/src/Http/Controllers/AnomalyController.php`

- [ ] `index()` — `GET /api/v1/anomalies` con filtros y paginación.
- [ ] `acknowledge()` — `POST /api/v1/anomalies/{id}/acknowledge`.

---

## TSK-35.12: Rutas en `index.php`

**Trazabilidad**: RF-35.4.2, RF-35.4.3
**Archivo**: `api/public/index.php`

- [ ] `GET /api/v1/anomalies` → `AnomalyController::index`
- [ ] `POST /api/v1/anomalies/{id}/acknowledge` → `AnomalyController::acknowledge`

---

## TSK-35.13: Extender `RoomLiveController`

**Trazabilidad**: RF-35.4.1
**Archivo**: `api/src/Http/Controllers/RoomLiveController.php`

- [ ] Añadir campo `anomalies` al response con anomalías `OPEN` de la room.

---

## TSK-35.14: Worker en `start-all.sh`

**Trazabilidad**: RF-35.2.2
**Archivo**: `start-all.sh`

- [ ] Añadir entrada para `anomaly-scanner.php` en bucle infinito con sleep 15s.

---

## TSK-35.15: Tests

**Trazabilidad**: RF-35.2.3
**Archivos**: `api/tests/Unit/AnomalyTest.php`, `api/bin/run-tests.sh` (BLOCK 25)

- [ ] Tests unitarios: cada detector con casos positivo y negativo.
- [ ] Tests HTTP: escenarios simulados que disparan anomalías y verifican respuesta en `/live` y `/anomalies`.
- [ ] Test de deduplicación: mismo evento no genera duplicados.
- [ ] Test de auto-dismiss: worker cierra anomalía cuando condición se resuelve.

---

## Resumen de tareas

| TSK | Descripción | Archivos nuevos | Archivos modificados |
|-----|-------------|-----------------|---------------------|
| 35.01 | Migración tabla anomalies | — | `migrate.php` |
| 35.02 | Modelo Anomaly | `Anomaly.php` | — |
| 35.03 | Interfaz + DTO | `AnomalyDetector.php`, `AnomalyResult.php` | — |
| 35.04 | Repositorio | `AnomalyRepositoryInterface.php`, `AnomalyRepository.php` | — |
| 35.05 | 8 detectores A1–A8 | 8 archivos en `Detectors/` | — |
| 35.06 | Pipeline | `AnomalyPipeline.php` | — |
| 35.07 | Servicio | `AnomalyService.php` | — |
| 35.08 | Inyección en IotSessionService | — | `IotSessionService.php` |
| 35.09 | Detección A5 en ExitAction | — | `ExitActionService.php` |
| 35.10 | Worker periódico | `anomaly-scanner.php` | — |
| 35.11 | Controller | `AnomalyController.php` | — |
| 35.12 | Rutas API | — | `index.php` |
| 35.13 | RoomLive extendido | — | `RoomLiveController.php` |
| 35.14 | Arranque worker | — | `start-all.sh` |
| 35.15 | Tests + runner | `AnomalyTest.php` | `run-tests.sh` (BLOCK 25) |

> **Nota**: Las tareas de UI/dashboard (RF-35.6) se documentarán en una fase posterior cuando se aborde la representación visual.

---

# Fase 36: Monitoreo de batería del sensor de puerta MC400D

---

## TSK-36.1: Migración — columna battery_pct

**Trazabilidad**: RF-36.1.1
**Archivo**: `api/migrations/0043_battery_pct.sql`

- [ ] Crear archivo `0043_battery_pct.sql`
- [ ] `ALTER TABLE devices ADD COLUMN IF NOT EXISTS battery_pct TINYINT UNSIGNED NULL;`
- [ ] Registrar en `api/bin/migrate.php`

**Verificación**: Ejecutar migración. `DESCRIBE devices` debe mostrar la columna `battery_pct`.

---

## TSK-36.2: Modelo Device — propiedad batteryPct

**Trazabilidad**: RF-36.1.1
**Archivo**: `api/src/Domain/Devices/Device.php`

- [ ] Añadir propiedad pública `?int $batteryPct = null`
- [ ] Añadir al constructor (parámetro opcional con default null)
- [ ] `toArray()` incluye `'battery_pct' => $this->batteryPct`

**Verificación**: Instanciar Device con y sin battery_pct. `toArray()` debe incluir el campo.

---

## TSK-36.3: DeviceRepository — hydratación + updateBattery

**Trazabilidad**: RF-36.1.1, RF-36.1.2
**Archivos**: `api/src/Domain/Devices/DeviceRepository.php`, `api/src/Domain/Devices/DeviceRepositoryInterface.php`

- [ ] `hydrate()`: leer `$row['battery_pct']` (puede ser null)
- [ ] Nuevo método `updateBattery(int $deviceId, ?int $pct): void`
- [ ] Añadir a la interfaz `DeviceRepositoryInterface`
- [ ] Todos los SELECT en el repositorio ya incluyen `battery_pct` (en los usados por `hydrate()`)

**Verificación**: `updateBattery(3, 87)` + `findById(3)` → el objeto tiene `batteryPct = 87`.

---

## TSK-36.4: TuyaSensorIngress — persistir batería en push webhook

**Trazabilidad**: RF-36.1.1, RF-36.1.2
**Archivo**: `api/src/Infrastructure/Gateways/Sensor/TuyaSensorIngress.php`

- [ ] En `normalize()`, tras identificar el device y antes de iterar DPs:
  - Buscar entre todos los DPs (incluyendo INFO_DPS) el valor de `battery_percentage`
  - Si se encuentra, llamar a `$this->deviceRepo->updateBattery($device->id, (int)$pct)`
- [ ] La iteración actual sobre DPs solo procesa los no-info para el evento canónico — la extracción de batería debe hacerse antes/paralela, no dentro del mismo loop que solo mira DPs accionables.
- [ ] Manejar caso edge: payload sin DPs (heartbeat puro). No hacer nada.

**Verificación**: Enviar un push simulado con `battery_percentage: 85` al webhook. Verificar en BD que `devices.battery_pct = 85`.

---

## TSK-36.5: Nuevo endpoint POST /dashboard-api/battery-refresh

**Trazabilidad**: RF-36.2.1, RF-36.2.2, RF-36.2.3
**Archivo**: `api/public/index.php`

- [ ] Añadir ruta `POST /dashboard-api/battery-refresh` (sin auth, LAN/MVP)
- [ ] Body: `{"device_id": N}`
- [ ] Buscar dispositivo en BD por ID
- [ ] Llamar a `tuyaPresenceApi('GET', "/v1.0/iot-03/devices/{external_id}/status")`
- [ ] Extraer DP `battery_percentage` de `$apiResult['data']['result']` (array de DPs)
- [ ] Persistir vía `UPDATE devices SET battery_pct = ? WHERE id = ?`
- [ ] Calcular `state` según umbrales (normal/low/critical/unknown)
- [ ] Devolver JSON con `ok, device_id, kind, battery_pct, state`

**Verificación**: `POST /dashboard-api/battery-refresh {"device_id": <id del MC400D>}` → respuesta 200 con battery_pct.

---

## TSK-36.6: Exponer batería en dashboard API endpoints

**Trazabilidad**: RF-36.3.1, RF-36.3.2, RF-36.3.3
**Archivos**: `api/public/index.php`, `api/src/Http/Controllers/RoomLiveController.php`

- [ ] `GET /dashboard-api/device-status`: añadir `d.battery_pct` al SELECT, incluir en respuesta
- [ ] `GET /dashboard-api/pack-detail`: ídem, añadir `battery_pct` al SELECT y respuesta
- [ ] `RoomLiveController::show()`: buscar dispositivo PROXIMITY de la room. Si existe, leer `battery_pct` y añadir campo `battery` a la respuesta con `{device_id, kind, label, pct, state}`. State se calcula con la función de umbrales.

**Verificación**: `GET /dashboard-api/device-status?room_id=1` debe mostrar `battery_pct`. `GET /api/v1/rooms/1/live` debe incluir campo `battery`.

---

## TSK-36.7: Panel UI — indicador de batería + botón refresh

**Trazabilidad**: RF-36.5.1, RF-36.5.2, RF-36.5.3
**Archivos**: `api/public/panel/index.html`, `api/public/dashboard.html`

### 7.1 CRM Panel

- [ ] En `openRoomDetail()`, para cada dispositivo `PROXIMITY`, añadir:
  ```html
  <div style="padding:4px 0;font-size:13px">
    🚪 Sensor Puerta: ext_id
    <span class="battery-indicator" id="batt-${d.id}">
      🔋 --% <button class="btn btn-o btn-xs" onclick="refreshBattery(${d.id})">🔄</button>
    </span>
  </div>
  ```
- [ ] Función global `refreshBattery(deviceId)`:
  - Muestra spinner en el botón
  - `fetch('/dashboard-api/battery-refresh', {method:'POST', body: JSON.stringify({device_id})})`
  - Actualiza el span con el porcentaje + color según umbral
- [ ] CSS: colores por estado (green/yellow/red/gray)

### 7.2 Dashboard

- [ ] En `renderDeviceList()` o equivalente, para PROXIMITY: mostrar `🔋 pct%`
- [ ] Color según umbral
- [ ] Botón refresh que llama al mismo endpoint

**Verificación**: Abrir panel, ver detalle de habitación con sensor puerta, pulsar refresh → aparece porcentaje.

---

## TSK-36.8: Tests

**Trazabilidad**: RF-36.1, RF-36.2, RF-36.3
**Archivos**: `api/tests/Unit/ChipBatteryTest.php`, `api/bin/run-tests.sh`

### 8.1 Unit test

- [ ] `ChipBatteryTest.php`: verifica lógica de umbrales (`batteryState()` retorna correcto)
- [ ] Verifica que `updateBattery` persiste correctamente
- [ ] Verifica que `hydrate` lee `battery_pct` de fila SQL

### 8.2 HTTP tests — BLOCK 26

- [ ] GET device-status incluye battery_pct
- [ ] POST battery-refresh con device_id válido → 200
- [ ] POST battery-refresh con device_id inválido → 400/404
- [ ] GET rooms/{id}/live incluye campo battery
- [ ] POST tuya/webhook con battery_percentage persiste el valor

**Verificación**: `bash bin/run-tests.sh` → BLOCK 26 pasa con 0 failures.

---

## Tabla resumen de tareas

| TSK | Descripción | Archivos nuevos | Archivos modificados |
|-----|-------------|-----------------|---------------------|
| 36.01 | Migración battery_pct | `0043_battery_pct.sql` | `migrate.php` |
| 36.02 | Modelo Device + batteryPct | — | `Device.php` |
| 36.03 | Repositorio updateBattery + hydrate | — | `DeviceRepository.php`, `DeviceRepositoryInterface.php` |
| 36.04 | TuyaSensorIngress persistir batería | — | `TuyaSensorIngress.php` |
| 36.05 | Endpoint battery-refresh | — | `index.php` |
| 36.06 | Exponer batería en API endpoints | — | `index.php`, `RoomLiveController.php` |
| 36.07 | Panel UI indicador + botón | — | `panel/index.html`, `dashboard.html` |
| 36.08 | Tests unit + HTTP | `ChipBatteryTest.php` | `run-tests.sh` |

---

# Fase 37: Vista de anomalías con filtro de estado

---

## TSK-37.1: `findResolvableForRoom()` — repositorio

**Trazabilidad**: RF-37.3.1
**Archivos**: `AnomalyRepositoryInterface.php`, `AnomalyRepository.php`

- [x] Añadir método `findResolvableForRoom(int $roomId): array` a la interfaz.
- [x] Implementar con SQL: `WHERE room_id = :rid AND status IN ('OPEN','ACKNOWLEDGED')`.
- [x] Reutiliza `hydrate()` existente para mapear filas a objetos `Anomaly`.

**Verificación**: Sintaxis PHP pasa. El método devuelve anomalías OPEN y ACKNOWLEDGED de una room.

---

## TSK-37.2: `autoResolveForRoom()` — usar `findResolvableForRoom`

**Trazabilidad**: RF-37.3.1, RF-37.3.2
**Archivo**: `AnomalyService.php`

- [x] Cambiar `$this->repo->findOpenForRoom($roomId)` → `$this->repo->findResolvableForRoom($roomId)`.
- [x] El resto del método no cambia: itera, evalúa `isConditionResolved()`, auto-dismiss.

**Verificación**: Las anomalías ACKNOWLEDGED ahora son evaluadas y auto-descartadas cuando la condición desaparece.

---

## TSK-37.3: Controller — soporte `status=ALL`

**Trazabilidad**: RF-37.4.1, RF-37.4.2
**Archivo**: `AnomalyController.php`

- [x] Modificar `index()`: si `status` es vacío o `'ALL'`, no añadir filtro de estado.
- [x] Si `status` no se envía, default sigue siendo `'OPEN'` (retrocompatibilidad).

**Verificación**: `GET /api/v1/anomalies?status=ALL` devuelve anomalías de todos los estados. `GET /api/v1/anomalies` (sin param) devuelve solo OPEN.

---

## TSK-37.4: Frontend — dropdown de estado

**Trazabilidad**: RF-37.1.1, RF-37.1.2, RF-37.1.3
**Archivo**: `api/public/panel/index.html`

- [x] Añadir 4º `<select>` en `filterRow` con opciones: OPEN, ACKNOWLEDGED, DISMISSED, ALL.
- [x] Actualizar los `onchange` de los otros 3 dropdowns para preservar `status` al cambiar filtros.
- [x] `loadAnomalies()`: usar `this.anomalyFilters.status` (default `'OPEN'`), omitir param si `ALL`.

**Verificación**: El dropdown aparece en la barra de filtros. Cambiar estado recarga la lista correctamente.

---

## TSK-37.5: Frontend — renderizado condicional por estado

**Trazabilidad**: RF-37.2.1, RF-37.2.2, RF-37.2.3, RF-37.2.4
**Archivo**: `api/public/panel/index.html`

- [x] Añadir columna "Estado" con badge de color (`status-open` rojo, `status-acked` naranja, `status-dismissed` verde).
- [x] Columna acción: botón "✓ Reconocer" solo si `status==='OPEN'`. Si no, mostrar "por X · fecha".
- [x] Encabezado dinámico: "Anomalías — Pendientes" / "Reconocidas" / "Histórico" / "Todas".
- [x] Mensaje vacío contextual: "Sin anomalías pendientes", etc.
- [x] CSS: clases `.status-open`, `.status-acked`, `.status-dismissed`.

**Verificación**: La tabla muestra badges de estado. El botón Reconocer solo aparece en OPEN.

---

## TSK-37.6: Tests

**Trazabilidad**: RF-37.3, RF-37.4
**Archivos**: `api/bin/run-tests.sh` (BLOCK 27)

- [ ] Añadir BLOCK 27 al runner con tests HTTP:
  - GET anomalías sin status → solo OPEN (retrocompatibilidad)
  - GET anomalías con status=ACKNOWLEDGED → solo reconocidas
  - GET anomalías con status=DISMISSED → solo descartadas
  - GET anomalías con status=ALL → todas
  - Verificar que auto-resolve procesa ACKNOWLEDGED

**Verificación**: `bash bin/run-tests.sh` → BLOCK 27 pasa con 0 failures.

---

## Tabla resumen de tareas

| TSK | Descripción | Archivos modificados |
|-----|-------------|---------------------|
| 37.01 | `findResolvableForRoom()` | `AnomalyRepositoryInterface.php`, `AnomalyRepository.php` |
| 37.02 | `autoResolveForRoom()` usa nuevo método | `AnomalyService.php` |
| 37.03 | Controller soporta status=ALL | `AnomalyController.php` |
| 37.04 | Dropdown de estado | `panel/index.html` |
| 37.05 | Renderizado condicional + badges + CSS | `panel/index.html` |
| 37.06 | Tests HTTP BLOCK 27 | `run-tests.sh` |


---
# Fase 38: Workers — Trabajadores del hotel con QR maestro

## F37a: Migración + modelos + repositorios

### TSK-W01: Migración de base de datos

**Trazabilidad**: RF-W1, RF-W2, RF-W5
**Archivos**: `api/migrations/0044_workers.sql`

- [ ] Crear archivo de migración `0044_workers.sql`
- [ ] Incluir `CREATE TABLE worker_roles` con campos id, name, description, created_at, updated_at
- [ ] Incluir `CREATE TABLE worker_role_room_types` con FK a worker_roles y room_types, UNIQUE (role_id, room_type_id)
- [ ] Incluir `CREATE TABLE workers` con FK a worker_roles, qr_token_hash, qr_jti UNIQUE, active, notes
- [ ] Incluir `CREATE TABLE worker_sessions` con FK a workers y rooms, entered_at, exited_at NULLABLE, exit_kind, correlation_id
- [ ] Incluir `ALTER TABLE access_events ADD COLUMN worker_session_id INT NULL AFTER stay_id`
- [ ] Insertar roles por defecto: "Limpieza", "Mantenimiento", "Recepción" con acceso a todos los room_types existentes

**Verificación**: Ejecutar migración → tablas creadas, seed insertado.

---

### TSK-W02: Entidades de dominio

**Trazabilidad**: RF-W1, RF-W2, RF-W5
**Archivos**: `api/src/Domain/Workers/Worker.php`, `WorkerRole.php`, `WorkerSession.php`

- [ ] Crear `Worker.php`: id, name, roleId, qrTokenHash, qrJti, active, notes, createdAt, updatedAt. Métodos: `deactivate()`, `revokeQr(string $newJti, string $newHash)`, `isActive(): bool`
- [ ] Crear `WorkerRole.php`: id, name, description, roomTypeIds, createdAt, updatedAt
- [ ] Crear `WorkerSession.php`: id, workerId, roomId, enteredAt, exitedAt, exitKind, correlationId, createdAt, updatedAt. Métodos: `close(string $exitKind, \DateTimeImmutable $exitedAt)`, `isActive(): bool`

**Verificación**: Sintaxis PHP válida, tipado estricto.

---

### TSK-W03: Interfaces de repositorios

**Trazabilidad**: RF-W1, RF-W2, RF-W5
**Archivos**: `api/src/Domain/Workers/WorkerRepositoryInterface.php`, `WorkerRoleRepositoryInterface.php`, `WorkerSessionRepositoryInterface.php`

- [ ] `WorkerRepositoryInterface`: findById, findByJti, findAll, insert, update, softDelete
- [ ] `WorkerRoleRepositoryInterface`: findById, findAll, insert, update, delete, assignRoomTypes, getRoomTypesForRole
- [ ] `WorkerSessionRepositoryInterface`: findById, findActiveForRoom, findActiveForWorker, findForWorker (con filtros), findForRoom, insert, close, countActiveForRoom

**Verificación**: Interfaces definidas con PHPDoc completo.

---

### TSK-W04: Implementaciones de repositorios (PDO)

**Trazabilidad**: RF-W1, RF-W2, RF-W5
**Archivos**: `api/src/Domain/Workers/WorkerRepository.php`, `WorkerRoleRepository.php`, `WorkerSessionRepository.php`

- [ ] `WorkerRepository` extiende `BaseRepository`: hydrate, insert, update, findByJti (busca por qr_jti + hash), softDelete
- [ ] `WorkerRoleRepository`: hydrate con roomTypeIds desde join/pivot, insert + asignar room_types, update con sync de room_types
- [ ] `WorkerSessionRepository`: findActiveForRoom (exited_at IS NULL), findActiveForWorker, close (UPDATE exited_at + exit_kind), insert, queries con filtros de fecha y room

**Verificación**: Test unitario con SQLite in-memory o mock.

---

## F37b: WorkerRole CRUD API

### TSK-W05: WorkerRoleService

**Trazabilidad**: RF-W2
**Archivos**: `api/src/Domain/Workers/WorkerRoleService.php`

- [ ] `create(data)`: validar nombre único, crear rol, asignar room_types vía pivote
- [ ] `update(id, data)`: actualizar nombre/descripción, sync room_types (borrar y reinsertar)
- [ ] `delete(id)`: verificar que no hay workers con este rol (409 si los hay)
- [ ] `list()`: devolver roles con room_types anidados y contador de workers

**Verificación**: Tests unitarios con repos mock.

---

### TSK-W06: WorkerRoleController + rutas

**Trazabilidad**: RF-W2
**Archivos**: `api/src/Http/Controllers/WorkerRoleController.php`, `api/public/index.php`

- [ ] Crear `WorkerRoleController` con métodos: list, create, show, update, delete
- [ ] Validación de entrada (name requerido, room_type_ids array de ints)
- [ ] Registrar rutas en index.php: GET/POST `/api/v1/worker-roles`, GET/PATCH/DELETE `/api/v1/worker-roles/{id}`
- [ ] Scope: ADMIN o PANEL

**Verificación**: HTTP test → crear rol, listar, editar, borrar, comprobar 409 al borrar con workers.

---

## F37c: Worker CRUD API + QR token

### TSK-W07: WorkerService

**Trazabilidad**: RF-W1, RF-W4
**Archivos**: `api/src/Domain/Workers/WorkerService.php`

- [ ] `create(data)`: validar rol existe, generar JTI (uuid), firmar token (`QrTokenizer::sign()` con sub=worker), guardar worker + hash
- [ ] `update(id, data)`: actualizar nombre, rol, notes
- [ ] `deactivate(id)`: soft delete (active=false, qr_token_hash='')
- [ ] `regenerateQr(id)`: generar nuevo JTI y hash, revocar anterior, devolver token
- [ ] `canAccessRoomType(roleId, roomTypeId)`: verificar pivote
- [ ] `getCurrentRoom(workerId)`: worker_session activa → room info
- [ ] `getSessions(workerId, filters)`: sesiones paginadas con filtros fecha/room
- [ ] `getWorkersInside()`: todos los workers con sesión activa + room info
- [ ] `getStats(workerId)`: contador hoy, tiempo medio por room_type

**Verificación**: Tests unitarios con repos mock.

---

### TSK-W08: WorkerController + rutas

**Trazabilidad**: RF-W1, RF-W4, RF-W7, RF-W8
**Archivos**: `api/src/Http/Controllers/WorkerController.php`, `api/public/index.php`

- [ ] Crear `WorkerController`: list, create, show, update, deactivate, regenerateQr, sessions, inside
- [ ] Validación de entrada (name requerido, role_id int)
- [ ] Registrar rutas en index.php (8 endpoints)
- [ ] `POST /workers` devuelve qr_token en respuesta (solo aquí)
- [ ] `GET /workers/{id}/sessions` con query params: from, to, room_id, limit
- [ ] `GET /workers/inside` devuelve workers activos

**Verificación**: HTTP test → crear worker (verificar token en respuesta), listar, editar, ver detalle, ver sesiones (vacío inicialmente).

---

### TSK-W09: QrTokenizer — soporte sub discriminator

**Trazabilidad**: RF-W4
**Archivos**: `api/src/Domain/Qr/QrTokenizer.php`

- [ ] Añadir soporte para claim `sub` en `sign()`: aceptar 'guest' o 'worker'
- [ ] En `verify()`, validar HMAC + expiración, devolver claims incluyendo `sub` y `wid`
- [ ] Sin cambios en el comportamiento guest (retrocompatibilidad)

**Verificación**: Test unitario → firmar token worker, verificar, comprobar claims.

---

## F37d: QR worker validate + sesiones

### TSK-W10: WorkerQrService

**Trazabilidad**: RF-W3, RF-W5
**Archivos**: `api/src/Domain/Workers/WorkerQrService.php`

- [ ] `validate(token, roomId, deviceId, provider)`: 
  1. Verificar token → obtener claims
  2. Comprobar sub=worker, wid, jti
  3. Buscar worker por jti, verificar active
  4. Resolver device → room (misma lógica que guest QR)
  5. Verificar worker_role_room_types → room_type de la habitación
  6. Verificar que no tenga ya sesión activa en esta habitación (409 already_inside)
  7. Llamar a LockGateway → open
  8. Crear worker_session (entered_at=now)
  9. Escribir access_event (QR_VALIDATE OK, con worker_session_id)

**Verificación**: Tests unitarios con LockGateway mock.

---

### TSK-W11: WorkerQrController + ruta

**Trazabilidad**: RF-W3
**Archivos**: `api/src/Http/Controllers/WorkerQrController.php`, `api/public/index.php`

- [ ] Crear `WorkerQrController::validate()` con el endpoint `POST /api/v1/workers/qr/validate`
- [ ] Scope: RPI o SCANNER (misma auth que guest QR validate)
- [ ] Devolver: ok, worker info, worker_session_id, action=open

**Verificación**: HTTP test → validar QR worker, verificar worker_session creada, verificar access_event.

---

### TSK-W12: AccessEvent — nuevo kind WORKER_EXIT

**Trazabilidad**: RF-W6
**Archivos**: `api/src/Domain/Locks/AccessEvent.php`

- [ ] Añadir constante `KIND_WORKER_EXIT = 'WORKER_EXIT'`
- [ ] Añadir propiedad `workerSessionId` (nullable, ya que el campo existe en la tabla)

**Verificación**: Sintaxis válida.

---

## F37e: Regla de salida con workers

### TSK-W13: ExitActionService — cerrar worker sessions

**Trazabilidad**: RF-W6.1
**Archivos**: `api/src/Domain/Presence/ExitActionService.php`

- [ ] Añadir dependencia opcional `WorkerSessionRepositoryInterface` al constructor
- [ ] En `execute()`, antes de procesar el stay: buscar worker_sessions activas para la room, cerrarlas con `EXIT_RULE`, escribir access_events WORKER_EXIT
- [ ] Si no hay stay activo pero sí worker sessions: ejecutar lock + switch off + cooldown (misma coreografía sin stay)

**Verificación**: Test unitario → simular exit rule con workers dentro, verificar sesiones cerradas.

---

### TSK-W14: IotSessionService — worker exit por door event

**Trazabilidad**: RF-W6.2
**Archivos**: `api/src/Domain/Presence/IotSessionService.php`

- [ ] Añadir dependencia opcional `WorkerSessionRepositoryInterface` al constructor
- [ ] En el case `PROXIMITY CLOSED`, después de actualizar doorState y persistir:
  - Si presence_state = PRESENT: buscar worker_sessions activas, cerrar la más reciente con `DOOR_EVENT`, escribir access_event WORKER_EXIT
- [ ] En el bloque de exit rule (paso 6), antes de handleAutoExit/ExitActionService:
  - Cerrar TODAS las worker_sessions activas con `EXIT_RULE`
  - Escribir access_events WORKER_EXIT por cada una

**Verificación**: Tests unitarios con IotSession mock → simular door close con presencia, verificar sesión worker cerrada. Simular exit rule con workers + guest, verificar todas las sesiones cerradas + stay procesado.

---

## F37f: Panel CRM

### TSK-W15: Panel — Workers listado + formulario

**Trazabilidad**: RF-W8.1, RF-W8.2
**Archivos**: `api/public/panel/index.html`

- [ ] Sección "Trabajadores" en sidebar/nav
- [ ] Vista listado: tabla con nombre, rol, activo/inactivo, ubicación, último acceso, acciones
- [ ] Formulario crear: nombre, rol (dropdown), notas. Al submit → POST /api/v1/workers → mostrar QR token en modal con botón copiar
- [ ] Formulario editar: precargar datos, PATCH
- [ ] Botón desactivar: confirm dialog → DELETE
- [ ] Botón regenerar QR: confirm dialog → POST /workers/{id}/qr → mostrar nuevo token

**Verificación**: Crear worker desde panel, ver token, cerrar modal (token no se vuelve a mostrar).

---

### TSK-W16: Panel — Worker detalle + métricas

**Trazabilidad**: RF-W8.4
**Archivos**: `api/public/panel/index.html`

- [ ] Vista detalle con tabs: Historial | Métricas | Alertas
- [ ] Tab Historial: tabla con room, entered_at, exited_at, duración, exit_kind, paginada
- [ ] Tab Métricas: habitaciones visitadas hoy, tiempo total hoy, tiempo medio por tipo de habitación, ubicación actual
- [ ] Estilo consistente con el resto del panel

**Verificación**: Navegar a detalle de worker, ver sesiones (requiere datos de test previos).

---

### TSK-W17: Panel — Roles CRUD

**Trazabilidad**: RF-W8.3
**Archivos**: `api/public/panel/index.html`

- [ ] Sección "Roles" en sidebar
- [ ] Vista listado: tabla con nombre, descripción, room_types, N workers, acciones
- [ ] Formulario crear/editar: nombre, descripción, checkboxes de room_types disponibles
- [ ] Confirmación al eliminar (solo si workers_count = 0)

**Verificación**: Crear rol, asignar room_types, editar, verificar en listado.

---

### TSK-W18: Panel — Room detail extensión

**Trazabilidad**: RF-W8.5
**Archivos**: `api/public/panel/index.html`, `api/public/dashboard.html`

- [ ] En room detail, nueva pestaña/sección "Servicio":
  - Trabajadores que han entrado hoy (lista con entrada/salida/duración)
  - Tiempo acumulado de servicio hoy
  - Último servicio (fecha + quién)
- [ ] En dashboard, mini-sección "Workers activos ahora" con lista de worker → room

**Verificación**: Abrir room con workers activos, ver sección.

---

### TSK-W19: Panel — Alertas

**Trazabilidad**: RF-W9
**Archivos**: `api/public/panel/index.html`

- [ ] Badge en navbar con contador de alertas activas
- [ ] Dropdown al hacer clic: lista de alertas con tipo, worker, room, timestamp
- [ ] Alerta "Tiempo excesivo": worker X lleva > 120 min en Hab Y
- [ ] Alerta "Hora extraña": worker X entró a Hab Y a las 03:15
- [ ] Las alertas se calculan en frontend desde `GET /workers/inside` + `GET /workers/{id}/sessions`
- [ ] Umbral de tiempo excesivo y rango horario extraño configurable desde panel (guardar en system_settings o worker config)

**Verificación**: Simular worker con sesión larga (>120min) → alerta visible.

---

## F37g: Tests

### TSK-W20: Tests unitarios

**Trazabilidad**: RF-W1–RF-W9
**Archivos**: `api/tests/Unit/WorkerTest.php`, `api/tests/Unit/WorkerSessionTest.php`

- [ ] `WorkerTest.php`: crear worker, QR token firmado, revocar, regenerar, validar acceso por rol
- [ ] `WorkerSessionTest.php`: crear sesión, cerrar, findActiveForRoom, exit rule cierra sesiones
- [ ] Patrón `pass()`/`fail()` sin PHPUnit (como tests existentes)

**Verificación**: `php tests/Unit/WorkerTest.php` → 0 failures.

---

### TSK-W21: Tests HTTP — BLOCK 29

**Trazabilidad**: RF-W1–RF-W9
**Archivos**: `api/bin/run-tests.sh`

- [ ] Reemplazar placeholder `# (PLACEHOLDER) BLOCK 29 — F37 Workers` con tests HTTP:
  - Crear worker con QR token (POST /workers → 201, verificar token)
  - Listar workers (GET /workers → 200)
  - Ver worker (GET /workers/{id} → 200)
  - Editar worker (PATCH /workers/{id} → 200)
  - Regenerar QR (POST /workers/{id}/qr → 200, verificar nuevo token)
  - CRUD roles (POST, GET, PATCH, DELETE)
  - Validar QR worker (POST /workers/qr/validate → 200, verificar worker_session)
  - Validar QR worker revocado (→ 403)
  - Validar QR worker en room_type no permitido (→ 403)
  - Validar QR worker ya dentro de la misma room (→ 409)
  - GET /rooms/{id}/occupants → ver workers activos
  - GET /workers/inside → ver workers dentro
  - GET /workers/{id}/sessions → ver sesiones
  - Desactivar worker (DELETE /workers/{id} → 200)
  - Validar QR worker desactivado (→ 403)

**Verificación**: `bash bin/run-tests.sh` → BLOCK 29 pasa con 0 failures.

---

## Tabla resumen de tareas F37

| TSK | Descripción | Archivos |
|-----|-------------|----------|
| W01 | Migración SQL | `0044_workers.sql` |
| W02 | Entidades Worker/WorkerRole/WorkerSession | `Domain/Workers/*.php` |
| W03 | Interfaces repositorios | `Worker*RepositoryInterface.php` |
| W04 | Implementaciones repos PDO | `Worker*Repository.php` |
| W05 | WorkerRoleService | `WorkerRoleService.php` |
| W06 | WorkerRoleController + rutas | `WorkerRoleController.php`, `index.php` |
| W07 | WorkerService | `WorkerService.php` |
| W08 | WorkerController + rutas | `WorkerController.php`, `index.php` |
| W09 | QrTokenizer sub discriminator | `QrTokenizer.php` |
| W10 | WorkerQrService | `WorkerQrService.php` |
| W11 | WorkerQrController + ruta | `WorkerQrController.php`, `index.php` |
| W12 | AccessEvent KIND_WORKER_EXIT | `AccessEvent.php` |
| W13 | ExitActionService cierra worker sessions | `ExitActionService.php` |
| W14 | IotSessionService worker exit (door + exit rule) | `IotSessionService.php` |
| W15 | Panel workers listado + formulario | `panel/index.html` |
| W16 | Panel worker detalle + métricas | `panel/index.html` |
| W17 | Panel roles CRUD | `panel/index.html` |
| W18 | Panel room detail extensión + dashboard | `panel/index.html`, `dashboard.html` |
| W19 | Panel alertas workers | `panel/index.html` |
| W20 | Tests unitarios | `tests/Unit/Worker*Test.php` |
| W21 | Tests HTTP BLOCK 27 | `run-tests.sh` |

---

# Fase 39: Identificación de fábrica integrada en ESP32 productivo

La Fase 38 de Workers conserva su numeración. F39 no crea ni modifica un
firmware/sketch aislado: toda la lógica de placa se integra en
`docs/esp32-qr-reader/scanner-relay-prod.ino` y debe preservar su operación.

## TSK-39.01: Migración del registro de fábrica

**Trazabilidad**: RF-39.3.5, RF-39.4.5, RF-39.6.1, RF-39.6.2
**Archivos**: `api/migrations/0046_factory_devices.sql`, `api/bin/migrate.php`

- [ ] Crear la tabla `factory_devices` con `chip_id` único y no nulo.
- [ ] Añadir `status` restringido a `PENDING` o `CLAIMED`.
- [ ] Añadir `first_announced_at`, `last_announced_at`, `claimed_at`, `claimed_by`, `device_id`, `created_at` y `updated_at`.
- [ ] Registrar la migración siguiendo el mecanismo existente, sin modificar tablas de Workers.

**Verificación**: Ejecutar la migración y comprobar la restricción única y la persistencia de todos los campos del claim.

## TSK-39.02: Identificador eFuse y scheduler integrado

**Trazabilidad**: RF-39.1.1, RF-39.1.2, RF-39.1.3, RF-39.5.1–RF-39.5.4
**Archivo**: `docs/esp32-qr-reader/scanner-relay-prod.ino`

- [ ] Obtener `chip_id` mediante `ESP.getEfuseMac()` y serializarlo lowercase, sin separadores y de forma determinista.
- [ ] Añadir estado efímero en RAM para habilitación, intento en curso y próximo intento; inicializarlo en cada boot sin leer/escribir una bandera de claim en NVS.
- [ ] Integrar el scheduler en el `loop()` productivo, sin retornos o esperas que priven de servicio a QR, USB, relé, GPIO4, watchdog, heartbeats o command queue.
- [ ] No añadir credenciales ni identificadores alternativos como identidad del equipo.

**Verificación**: Revisar `setup()`/`loop()` y probar dos reinicios: `chip_id` coincide, todas las funciones productivas siguen inicializadas y no existe `factory_claimed` persistido en NVS.

## TSK-39.03: Anuncio automático no bloqueante tras WiFi

**Trazabilidad**: RF-39.2.1–RF-39.2.3
**Archivo**: `docs/esp32-qr-reader/scanner-relay-prod.ino`

- [ ] Reutilizar el flujo NVS + WiFiManager existente; no crear AP ni modo WiFi específico de fábrica.
- [ ] Tras detectar WiFi conectado, programar el anuncio automáticamente, sin depender de GPIO4, QR, habitación o pack.
- [ ] Con `PENDING`, reintentar con intervalo acotado; con fallo de red/timeout, registrar y reprogramar sin bloquear.
- [ ] Con `CLAIMED`, detener anuncios únicamente hasta el siguiente reinicio; no persistir ese resultado en NVS.

**Verificación**: Conectar WiFi y comprobar anuncio automático. Durante PENDING, escanear QR, accionar identify y procesar heartbeat/command queue sin retrasos atribuibles a F39; reiniciar o borrar NVS y comprobar que se anuncia otra vez.

## TSK-39.04: Endpoint de anuncio idempotente

**Trazabilidad**: RF-39.3.1–RF-39.3.4, RF-39.6.3
**Archivos**: `api/src/Domain/FactoryDevices/*`, `api/src/Http/Controllers/FactoryDeviceController.php`, `api/public/index.php`

- [ ] Implementar `POST /api/v1/factory-devices/announce` validando `chip_id` hexadecimal.
- [ ] Crear `PENDING` en el primer anuncio.
- [ ] Hacer upsert transaccional por `chip_id` y actualizar únicamente `last_announced_at` en anuncios posteriores.
- [ ] Conservar estado y campos del claim cuando el registro ya esté `CLAIMED`.

**Verificación**: Enviar dos anuncios con el mismo `chip_id` y comprobar que existe un solo registro, con `created=true` solo en el primero.

## TSK-39.05: Listado y claim manual desde panel

**Trazabilidad**: RF-39.4.1–RF-39.4.6, RF-39.6.4
**Archivos**: `api/src/Http/Controllers/FactoryDeviceController.php`, `api/public/index.php`, `api/public/panel/index.html`

- [ ] Implementar `GET /api/v1/factory-devices?status=...` para el panel.
- [ ] Implementar `POST /api/v1/factory-devices/{id}/claim` con autorización de administración.
- [ ] Cambiar `PENDING` a `CLAIMED` atómicamente y registrar actor y fecha.
- [ ] Hacer idempotente la repetición del claim sin cambiar `claimed_by` ni `claimed_at`.
- [ ] Añadir al panel la cola de pendientes, el `chip_id` y una acción explícita de claim.
- [ ] En el claim, crear o vincular un `devices.kind=RPI` con `external_id=chip_id`, `pack_id=null` y `room_id=null`; no asignar habitación, pack ni montaje.

**Verificación**: Reclamar desde el panel, recargar la página y comprobar `CLAIMED` persistente; repetir la acción y comprobar que no cambia la auditoría.

## TSK-39.06: Auditoría, autoridad backend y no regresión

**Trazabilidad**: RF-39.4.1–RF-39.4.6, RF-39.5.1–RF-39.5.4, RF-39.6.1–RF-39.6.4
**Archivos**: `api/src/Domain/FactoryDevices/*`, `api/src/Http/Controllers/FactoryDeviceController.php`, `docs/esp32-qr-reader/scanner-relay-prod.ino`

- [ ] Registrar el claim con `chip_id`, estado anterior, estado nuevo, actor y timestamp.
- [ ] Garantizar que el backend conserva `CLAIMED` tras anuncios posteriores, reflasheos y NVS wipes; el firmware nunca lo usa como estado persistente autoritativo.
- [ ] Mantener el anuncio sin efectos sobre Workers, QR guest, QR worker, relé, GPIO4, habitaciones o estancias.
- [ ] Documentar errores de autenticación, validación, recurso inexistente y conflictos sin filtrar datos sensibles.

**Verificación**: Revisión de dependencias, rutas y loop para confirmar que F39 no cambia contratos de F38 ni bloquea el flujo productivo.

## TSK-39.07: Tests de F39

**Trazabilidad**: RF-39.1–RF-39.6
**Archivos**: `api/tests/Unit/FactoryDeviceTest.php`, `api/bin/run-tests.sh`

- [ ] Añadir tests unitarios para formato estable de `chip_id`, transición de estados, idempotencia, preservación de claim y creación/vínculo de RPI sin pack/habitación.
- [ ] Añadir tests de contrato del sketch productivo: usa eFuse, no referencia `factory-identification.ino` ni persiste `factory_claimed`, y conserva los puntos de integración QR/USB/relé/GPIO4/watchdog/heartbeat/command queue.
- [ ] Añadir tests HTTP para anuncio inicial PENDING, anuncio repetido, listado PENDING, claim manual, claim repetido, anuncio posterior CLAIMED y preservación del RPI sin pack/habitación.
- [ ] Reemplazar el placeholder `# (PLACEHOLDER) BLOCK 30 — F39` con esos escenarios. Los tests stateful deben obtener claves desde `seeds/dev_api_keys.txt`, usar un `chip_id` único por ejecución y hacer SKIP explícito si falta el estado requerido.

**Verificación**: Ejecutar `cd /root/cerraduras/api && bash bin/run-tests.sh` después de implementar esta tarea; debe finalizar con 0 failures.

## Resumen de tareas F39

| TSK | Descripción | Archivos principales |
|-----|-------------|----------------------|
| 39.01 | Migración y modelo persistente | `migrations/*factory_devices*`, `Domain/FactoryDevices/*` |
| 39.02 | Identidad eFuse y scheduler integrado | `docs/esp32-qr-reader/scanner-relay-prod.ino` |
| 39.03 | Anuncio automático no bloqueante tras WiFi | `scanner-relay-prod.ino` |
| 39.04 | Anuncio idempotente | `FactoryDeviceController.php`, `index.php` |
| 39.05 | Listado y claim manual | `FactoryDeviceController.php`, `panel/index.html` |
| 39.06 | Auditoría y límites | `Domain/FactoryDevices/*`, controller |
| 39.07 | Tests unitarios e HTTP | `FactoryDeviceTest.php`, `run-tests.sh` |

---

# Fase 40: Credenciales individuales ESP32

## TSK-40.08: Provisioning fresco sin destruir credenciales

- [x] Borrar individualmente `ssid`, `pass`, `last_ssid` y `build_marker` en `cerraduras`, sin `prefs.clear()` ni acceso destructivo a `device-cred`.
- [x] Documentar compilación/upload real para activar el marcador automático basado en digest/fecha/hora.
- **Verificación**: test de contrato del sketch y revisión de NVS.

## TSK-40.09: Unicidad y claim sin huérfanos

- [x] Garantizar un RPI por eFuse y un cliente por dispositivo; cualquier conflicto revierte la transacción.
- [x] Confirmar claim repetido desde RPI/auditoría sin filas ni clientes adicionales.
- **Verificación**: tests unitarios/HTTP de unicidad, binding, rollback y `CLAIMED` posterior.

## TSK-40.10: Cleanup y regresión BLOCK 31

- [x] Limpiar automáticamente la fila factory aleatoria y los recursos RPI/cliente creados por el bloque, sin imprimir valores sensibles.
- [x] Añadir regresiones de cleanup y ejecutar la suite completa, separando baseline de regresiones.
- [x] Limpiar solo IDs vivos 8/9 y sus recursos test-owned enlazados, preservando auditoría.
- **Verificación**: PHP lint, `bash -n`, `git diff --check` y `cd api && bash bin/run-tests.sh`.

## TSK-40.01: Migración y binding

- [ ] Añadir hash de enrollment a `factory_devices`.
- [ ] Añadir `api_clients.device_id` único nullable y conservar legacy.
- [ ] Hacer transaccional la creación/reutilización del único RPI y el cliente.

## TSK-40.02: Anuncio y claim directo

- [x] Exigir HTTPS y recibir `chip_id` + `factory_key` en anuncio.
- [x] Reclamar por id administrativo usando el hash registrado, con label/pack opcionales y sin recibir ni devolver la clave.
- [x] Aplicar rate/error handling genérico y auditoría sin datos sensibles.

## TSK-40.03: Firmware productivo

Archivo único: `docs/esp32-qr-reader/scanner-relay-prod.ino`.

- [x] Eliminar API key compartida y generar/guardar una clave por placa en NVS separado.
- [x] Conservarla en NVS y usarla en anuncio y operación, sin mostrarla por Serial/portal.
- [x] Mantener QR, USB, relé, GPIO4, watchdog, heartbeat y command queue.

## TSK-40.04: Panel y compatibilidad

- [x] Formulario Fábrica con chip informativo, etiqueta y pack opcional; enviar claim directo por id.
- [x] No mostrar clave después del claim.
- [ ] Validar `device_mismatch` donde exista binding sin romper legacy.

## TSK-40.05: Tests y regresión

- [x] Tests unitarios RED/GREEN por tarea pequeña y BLOCK 31 HTTP con claves dinámicas.
- [ ] Ejecutar runner completo, PHP lint, `bash -n` y `git diff --check`.

## TSK-40.06: Consumir el registro de fábrica al reclamar
**Trazabilidad**: RF-40.4.1, RF-40.4.2
**Archivos**: `api/src/Infrastructure/Persistence/FactoryDeviceRepository.php`
- [x] En una transacción crear/vincular RPI y cliente, auditar y borrar al final `factory_devices`.
- [x] Mantener audit log, RPI y cliente; cualquier conflicto hace rollback.
**Verificación**: BD confirma ausencia de fila y permanencia de RPI, cliente y auditoría.

## TSK-40.07: Anuncio lógico CLAIMED tras consumo
**Trazabilidad**: RF-40.4.3–RF-40.4.5
**Archivos**: `api/src/Infrastructure/Persistence/FactoryDeviceRepository.php`, `api/tests/Unit/FactoryDeviceTest.php`, `api/bin/run-tests.sh`
- [x] Resolver RPI existente antes de crear `PENDING` y devolver metadatos efímeros `CLAIMED`.
- [x] Rechazar credencial incompatible sin efectos laterales.
- [x] Añadir regresiones unitarias y HTTP para repetición posterior y ausencia de fila.
**Verificación**: `cd /root/cerraduras/api && bash bin/run-tests.sh` termina con 0 failures.

## TSK-40.08: Anuncio terminal ante credencial rechazada y recovery
**Trazabilidad**: RF-40.4.4, RF-40.4.5
**Archivos**: `docs/esp32-qr-reader/scanner-relay-prod*.ino`, `api/tests/Unit/FactoryDeviceTest.php`, `docs/ops.md`
- [x] Detener el anuncio ante un 4xx terminal (salvo 429) en vez de reintentar cada 30 s.
- [x] Cubrir en tests unitarios la existencia de la rama terminal en el firmware canónico.
- [x] Documentar el procedimiento manual de re-enrolado (liberar binding, borrar NVS, reprovisionar, reclamar).
**Verificación**: `bash bin/run-tests.sh` con 0 failures y `docs/ops.md` con el procedimiento.

## TSK-40.10: Sketch de diagnóstico del relé (barrido de excitación)
**Trazabilidad**: hardware/relé 12 V (docs/hardware/esp32-relay-wiring.md)
**Archivos**: `docs/esp32-qr-reader/test-relay-sweep.ino`
- [x] Barrido automático LOW/HIGH/FLOAT en GPIO16 (3 s por estado) para ver si el relé conmuta.
- [x] Instrucciones y checklist (12 V, GND común, jumper) impresas por Serial.
**Verificación**: manual en placa; no entra en `api/bin/run-tests.sh` (sketch de diagnóstico).

## TSK-40.12: Verificación del relé recuperado (apertura cada 5 s)
**Trazabilidad**: hardware/relé 12 V (docs/hardware/esp32-relay-wiring.md)
**Archivos**: `docs/esp32-qr-reader/test-relay-open-5s.ino`
- [x] Pulso de apertura LOW (2 s) cada 5 s, reposo FLOAT (ACTIVE-LOW tri-state).
- [x] Esperado: clic firme al abrir/cerrar sin zumbido.
**Verificación**: manual en placa; no entra en `api/bin/run-tests.sh` (sketch de diagnóstico).

## TSK-40.11: Adaptar el robusto al relé recuperado (ACTIVE-LOW tri-state)
**Trazabilidad**: hardware/relé 12 V (docs/hardware/esp32-relay-wiring.md)
**Archivos**: `docs/esp32-qr-reader/scanner-relay-prod-12v-robusto.ino`, `api/tests/Unit/FactoryDeviceTest.php`
- [x] `#define RELAY_ACTIVE_LOW 1`: `relayOn()=OUTPUT+LOW`, `relayOff()=INPUT` (FLOAT).
- [x] `checkLock()` con polaridad correcta y vuelta a FLOAT.
- [x] `#else` conserva el módulo ACTIVE-HIGH original (idle LOW).
- [x] Aserción unitaria sobre la polaridad del robusto.
**Verificación**: `bash bin/run-tests.sh` con 0 failures nuevos; clic firme en QR válido y sin zumbido.

## TSK — Calibración de sensor de presencia (RF-41)

**Trazabilidad**: RF-41.1–RF-41.7 · CR-presence-calibrate
**Archivos**: `api/public/index.php`, `api/public/dashboard.html`, `.specs/specs/*.md`
- [x] Helper `resolvePresenceDeviceForRoom()`: resuelve el dispositivo `PRESENCE` de la habitación vía pack.
- [x] Helper `persistPresenceCalibration()`: guarda snapshot en `devices.meta_json.calibration` fusionando el meta.
- [x] `GET /dashboard-api/presence-calibrate/status` (RF-41.3/RF-41.4): lee DP, modo OFF y presencia efectiva.
- [x] `POST /dashboard-api/presence-calibrate/set` (RF-41.1/RF-41.2/RF-41.5): escribe DP y persiste por habitación; sin inyección en dominio.
- [x] Frontend `dashboard.html`: entrada clicable sobre el sensor del croquis SVG + modal `#cal-modal` con slider de radio y sensibilidad, insignia en vivo verde/roja, estados offline/OFF/error, cooldown de cuota Tuya (RF-41.6) y guardas (RF-41.7).
**Verificación**: `php -l` y `node --check` del JS del panel; manual contra `/dashboard?room=<con sensor>` (validar badge al entrar/salir y persistencia al reabrir).

---

# Fase 30: Refactor canónico pack (F30) — eliminar `devices.room_id`

**Objetivo**: dejar SOLO el modelo `device → pack → room`. Un dispositivo pertenece a
un pack (`devices.pack_id`) y la habitación se resuelve SIEMPRE vía `rooms.pack_id`;
se elimina la asociación directa dispositivo→habitación (columna `devices.room_id` y
`Device::$roomId`), que era un residuo legacy que generaba ambigüedad y conflictos.

**Trazabilidad**: RF-16/RF-39 (packs) · corrección de incoherencias del modelo
**Estado**: completada

- [x] Migración `api/migrations/0102_remove_devices_room_id.sql`: sanea `room_id` legacy,
      `DROP FOREIGN KEY fk_devices_room`, `DROP INDEX uniq_devices_room_kind`, `DROP COLUMN room_id`.
- [x] `Device.php`: eliminar propiedad/campo `$roomId`, parámetro constructor y clave `room_id` de `toArray()`.
- [x] `DeviceRepository.php`: quitar `room_id` de todos los `SELECT` y de `hydrate()`; `findIdentified()` resuelve la room vía pack; doc canónica.
- [x] `DeviceRepositoryInterface.php`: doc `resolveRoomId` canónica (device→pack→room).
- [x] Fallbacks legacy eliminados (resolución de room SOLO por pack):
      `DeviceService::findRoomIdForRpiExternalId`, `DeviceService::identify`,
      `QrValidateService::verifyDevice`, `TuyaSensorIngress::normalize`,
      `SwitchService::turnByPack` (ahora usa `rooms->findByPackId`).
- [x] `HealthController` (deep health batería): JOIN por `pack_id` en vez de `d.room_id`.
- [x] `DeviceController::register`: registro canónico por `pack_id` (se retira el fallback `room_id`).
- [x] `DeviceController::create` (`POST /rooms/{id}/devices`): crea el device en el **pack** de la room (nuevo `DeviceService::createInRoom`); si la room no tiene pack → 422 `room_has_no_pack`.
- [x] `FactoryDeviceRepository.php`: el INSERT de claim crea el RPI con `pack_id` (sin `room_id`).
- [x] `index.php` `GET /dashboard-api/pack-detail`: corregido el bloque con `$rows`/`$roomId` indefinidos (consulta por `pack_id`).
- [x] Frontend `panel/index.html`: `loadDevices()` no depende de `device.room_id` (unassigned → room null).
- [x] `bin/register-devices.php`: CSV `external_id,pack_code`; registra con `pack_id` resolviendo `/api/v1/device-packs`.
- [x] Tests unitarios adaptados al modelo canónico (fakes re-keyeados por pack): `SwitchServiceTest`, `DeviceIdentifyTest`, `QrValidateServiceTest`, `ChipBatteryTest`, `FactoryDeviceTest`.
- [x] Regresión unitaria: todos los `api/tests/Unit/*.php` en verde (excepto `WorkerSessionTest` que requiere `.env`/BD en el worktree).

**Nota operacional**: bajo el modelo canónico "mover un dispositivo de habitación" ya no
existe como concepto: se mueve de pack (`PATCH /api/v1/devices/{id}` con `pack_id`), y es el
pack quien determina la habitación. Esto evita estados incoherentes (device con `room_id`
pero `rooms.pack_id` distinto) y es la base para eliminar el fallback legacy en F30.

## F39 — Estado verídico de dispositivos (RF-42)

- [x] Migración `0103_online_probe_columns.sql`: `devices.online_state` + `devices.online_probed_at`.
- [x] Helpers backend en `index.php`: clasificación ESP32 vs Tuya cloud, `probeTuyaOnlineOnce` (sonda real y persistencia), `resolveDeviceOnlineState`.
- [x] `GET /dashboard-api/device-status`: estado de 3 valores (online/offline/unknown) por origen; nunca online sin señal real; expone `state`, `tuya_cloud`, sonda y conteos.
- [x] `POST /dashboard-api/ping-all-devices`: sonda Tuya real solo con `verify_tuya:true`; sin flag Tuya = `unknown` sin cuota; SCANNER/LOCK = pull; RPI = heartbeat. Ya no devuelve `online:true` ciego.
- [x] `assign-and-reset`: reutiliza la sonda real (persiste online_state).
- [x] `SwitchService`: elimina el refresco de `last_seen` por comando cloud "aceptado" (falso online).
- [x] Frontend `/dashboard`: auto-recheck 10 s excluye Tuya cloud; carga + botón "Comprobar" envían `verify_tuya:true`; render 3 estados; indicador pack con "sin verificar".
- [x] CRM `/panel`: detalle de habitación muestra estado real de dispositivos del pack.
- [x] Tests: BLOCK 30 en `run-tests.sh` (contrato device-status + ping sin cuota). Trazabilidad RF-42.
- [x] Docs: requisitos (RF-42), diseño, AGENTS.md (F39).

---

# Fase 41 — Tareas: Robustez del pipeline de sensores y coreografía

**Objetivo**: eliminar la pérdida de escrituras del estado IoT por habitación (RC-1), fijar el
orden temporal e idempotencia real de eventos (RC-2), corregir la coreografía de entrada/salida
del panel (RC-3/RC-6), desactivar el poller por estado de dominio (RC-5) y dejar la infra de
workers en instancia única y observable (RC-4).

**Trazabilidad global**: RF-43…RF-49 · `requirements.md` §Fase 41 · `design.md` §1–§12 ·
`contracts.md` §1–§9.

**Regla de cierre AGENTS.md**: la fase NO se cierra hasta que
`cd /root/cerraduras/api && bash bin/run-tests.sh` termine con **0 failures**.

## Convenciones

- IDs `TSK-F41-<n>`.
- **Tests unitarios PHP**: `api/tests/Unit/<Nombre>Test.php`, script plano con `pass()`/`fail()`
  (se autodescubren en **BLOCK 1**).
- **Tests unitarios JS**: `api/tests/Unit/<nombre>.test.js`, Node plano (sin framework),
  exit code `0` = OK. No hay runner JS: se invocan explícitamente desde `run-tests.sh`.
- **Tests HTTP**: nuevo **BLOCK 33** en `api/bin/run-tests.sh` (último bloque existente:
  BLOCK 32 — F43 Sensor 24G V3). No quedan placeholders, el bloque se inserta antes del
  `RESUMEN`, al final del fichero.
- **Claves API**: siempre desde `api/seeds/dev_api_keys.txt` (`ADMIN-CLI`, `SIM-CLIENT`,
  `RPI-DEV`, `VB6-MAIN`, `TUYA-BRIDGE`); nunca hardcodear. Usar `--idem "f41-<caso>-$RANDOM"`
  en los POST que lo requieran.
- Tests stateful: comprobar estado de BD y `skip` con mensaje explicativo si no se cumple.

## Orden de ejecución y dependencias

```
F41a (tests RED) → F41b (migración/modelos) → F41c (atomicidad) → F41d (orden/dedup)
   → F41e (regla de salida + reset + /live) → F41f (poller) → F41g (panel)
   → F41h (infra) → F41i (HTTP/regresión) → F41j (cierre)
```

---

## F41a: Especificación / tests primero (TDD puro)

> Se escribe el test y se observa fallar (RED) **antes** de tocar dominio. Los tests de esta
> subsección quedan en rojo hasta que las implementaciones de F41b–F41e los pongan en verde;
> es el estado esperado mientras la fase está abierta.

### TSK-F41-01: Test unitario de orden y deduplicación (`decide()`)

**Objetivo**: fijar por contrato el comportamiento de la decisión de aplicación de eventos
(duplicado / atrasado / noop / aplicar) y de la identidad lógica (fingerprint).
**Trazabilidad**: RF-44.1, RF-44.2, RF-44.3 · `contracts.md` §2.
**Archivos**:
- `api/tests/Unit/SensorEventDecisionTest.php` (nuevo).

**Dependencias**: ninguna (es el primer test de la fase).
**Contenido del test**:
- [ ] Fingerprint = `sha1(room_id|sensor|value|segundo)`; mismo hecho con `t` distinto en el
      mismo segundo ⇒ `duplicate`; `OPEN` vs `CLOSED` en el mismo segundo ⇒ no colisionan.
- [ ] `occurred_at` anterior al último aplicado del mismo sensor ⇒ `stale`.
- [ ] Mismo instante y mismo valor ⇒ `duplicate`.
- [ ] Mismo valor vigente ⇒ `noop`.
- [ ] Caso nuevo ⇒ `apply`.
- [ ] El evento bruto se devuelve/registra siempre (aplicado o descartado).

**Criterio de aceptación**: el test existe, **falla** por método/clase aún inexistente (RED) y
cubre los 5 casos; al implementarse TSK-F41-07 pasa a verde sin modificar el test.
**Test propio**: unitario PHP (RED) — autodescubierto en BLOCK 1.

---

### TSK-F41-02: Test unitario de la regla de salida con ciclo acreditado

**Objetivo**: fijar la nueva semántica de `ExitRuleEvaluator` (precondición de ciclo de puerta
acreditado + `entry_confirmed_at` + gap) y **actualizar los tests existentes** que fijan la
semántica antigua.
**Trazabilidad**: RF-47.1, RF-47.2, RF-47.3, RF-47.4 · `contracts.md` §1, §3.
**Archivos**:
- `api/tests/Unit/ExitRuleEvaluatorTest.php` (modificar/extender).

**Dependencias**: TSK-F41-01 (convenciones de helpers de test).
**Contenido del test**:
- [ ] OpenSinClose (hay `last_open_at`, no `last_close_at`) ⇒ no dispara.
- [ ] Close sin ciclo (`last_close_at < last_open_at`) ⇒ no dispara.
- [ ] Sin `entry_confirmed_at` (stay null o fecha null) ⇒ no dispara.
- [ ] `last_open_at <= entry_confirmed_at` (sin nueva apertura desde dentro) ⇒ no dispara.
- [ ] Ciclo completo + ABSENT + gap cumplido ⇒ dispara.
- [ ] Ciclo completo + ABSENT + gap **no** cumplido ⇒ no dispara.
- [ ] `door_state=OPEN` con ciclo acreditado ⇒ **dispara** (ya no depende del estado actual).
- [ ] Cierre con antigüedad > `DOOR_CYCLE_MAX_S` (300 s) ⇒ no dispara.
- [ ] Reapertura / reaparece `PRESENT` ⇒ cancela (`last_absent_since = null`) ⇒ no dispara.
- [ ] Actualizar los casos existentes que asumían `DOOR_CLOSE_WINDOW_S=60` y «door OPEN ⇒ no
      fire» (T1b, T2, T3, T5, T6) a la nueva semántica, dejando traza en el propio test.

**Criterio de aceptación**: el test fija los 9 comportamientos y **falla** con la implementación
actual (RED); al implementarse TSK-F41-08 pasa a verde.
**Test propio**: unitario PHP (RED) — BLOCK 1.

---

## F41b: Migración y modelos

### TSK-F41-03: Migración `0108` + hidratación de modelos

**Objetivo**: crear el esquema de orden/dedup y consolidación de entrada, y exponerlo en los
modelos/repositorios.
**Trazabilidad**: RF-43, RF-44, RF-46, RF-47 · `contracts.md` §1.
**Archivos**:
- `api/migrations/0108_sensor_event_ordering.sql` (nuevo)
- `api/src/Domain/Presence/IotSession.php`
- `api/src/Domain/Presence/PresenceEvent.php`
- `api/src/Domain/Stays/Stay.php`
- `api/src/Domain/Presence/IotSessionRepository.php`
- `api/src/Domain/Presence/PresenceEventRepository.php`
- `api/src/Domain/Stays/StayRepository.php`

**Dependencias**: TSK-F41-02.
**Contenido**:
- [ ] Aplicar el DDL exacto de `contracts.md` §1.1 (4 columnas en `iot_sessions`, 3 + `UNIQUE`
      + índice en `presence_events`, 1 en `stays`), aditivo y nullable.
- [ ] Añadir los campos a `IotSession` (constructor + `toArray()`), `PresenceEvent`
      (`fingerprint`, `applied`, `discardReason`) y `Stay` (`entryConfirmedAt`).
- [ ] Hidratar las columnas nuevas en los `SELECT` y en `hydrate()` correspondientes.
- [ ] `php bin/migrate.php` marca `0108` como aplicada (idempotente en re-ejecución).

**Criterio de aceptación**: `php bin/db-check.php` conecta; `DESCRIBE` de las 3 tablas muestra
las columnas con tipos/nullables correctos; un `fetch` de una fila de prueba devuelve los campos
nuevos (o `null`) sin errores.
**Test propio**: verificación de migración vía BLOCK 2 (schema) + caso en TSK-F41-18.

---

## F41c: Atomicidad (RF-43)

### TSK-F41-04: Repositorio con lock de fila y escrituras por columna

**Objetivo**: dotar al repositorio de las primitivas de escritura atómica.
**Trazabilidad**: RF-43.1, RF-43.2 · `contracts.md` §7 · `design.md` §1.2.
**Archivos**:
- `api/src/Domain/Presence/IotSessionRepositoryInterface.php`
- `api/src/Domain/Presence/IotSessionRepository.php`
- `api/tests/Unit/AbsenceTimerTest.php` (fakes)
- `api/tests/Unit/IotSessionServiceTest.php` (fakes)
- `api/tests/Unit/ExitRuleEvaluatorTest.php` (fakes)

**Dependencias**: TSK-F41-03.
**Contenido**:
- [ ] `lockByRoomId()`: `INSERT … ON DUPLICATE KEY UPDATE id=id` + `SELECT … FOR UPDATE` dentro
      de la transacción abierta.
- [ ] `updateState()`: `UPDATE` solo de las columnas de estado por `id`.
- [ ] `markExitEvaluated()`: update condicional que devuelve `false` si ya estaba marcado.
- [ ] Actualizar **todos** los fakes de test que implementan la interfaz (romperá BLOCK 1 si no).

**Criterio de aceptación**: BLOCK 1 en verde tras adaptar los fakes; `lockByRoomId()` lanza si
no hay transacción activa (guard); `markExitEvaluated()` es idempotente.
**Test propio**: unitario PHP (fakes) + concurrencia real en TSK-F41-18.

---

### TSK-F41-05: `processEvent()` atómico con transacción y efectos post-commit

**Objetivo**: convertir el ciclo leer-modificar-escribir en atómico, sin I/O externo bajo lock.
**Trazabilidad**: RF-43.1, RF-43.2, RF-43.3 · `design.md` §1.3, §1.4.
**Archivos**:
- `api/src/Domain/Presence/IotSessionService.php`

**Dependencias**: TSK-F41-04.
**Contenido**:
- [ ] Estructura `audit (fuera de tx) → beginTransaction → lockByRoomId → mutate → updateState
      → commit → efectos post-commit`.
- [ ] Reintentos acotados (3) con backoff + jitter ante deadlock (`1213`) o lock wait timeout
      (`1205`); rollback antes de reintentar.
- [ ] Mover `switchService->turnOn/Off`, gateway `lock` y detección de anomalías **fuera** del
      lock (post-commit).
- [ ] Conservar la firma pública `processEvent(array $event, string $correlationId): array` y su
      retorno `{accepted, derived_state}` (contrato interno, `contracts.md` §7).

**Criterio de aceptación**: BLOCK 1 en verde; dos `processEvent()` concurrentes de la misma
habitación no se pisan (cubierto en TSK-F41-18); no hay llamadas a Tuya/gateway con la fila
bloqueada (revisión de código + trazas).
**Test propio**: unitario existente (`IotSessionServiceTest`) adaptado + HTTP concurrencia.

---

### TSK-F41-06: `exit-scan` usa el camino idempotente sin reescribir sensores

**Objetivo**: que el worker de salida no compita en escritura con el webhook de puerta.
**Trazabilidad**: RF-48.3, RF-43.2, RF-47.4 · `design.md` §3.3, §7.1.
**Archivos**:
- `api/src/Domain/Presence/ExitActionService.php` (`executeIfPending()`)
- `api/bin/exit-scan.php`

**Dependencias**: TSK-F41-04, TSK-F41-08.
**Contenido**:
- [ ] `executeIfPending(roomId, correlationId)`: nueva transacción, re-lock de sesión + stay,
      re-validación de la regla, `markExitEvaluated()` condicional y solo entonces efectos.
- [ ] `exit-scan.php` llama a `executeIfPending()`; **elimina** cualquier llamada a
      `upsert()`/`updateState()` de estado de sensores.
- [ ] Conservar `tick=5s`, `SIGTERM`/`SIGINT` y `PdoFactory::ping()`.

**Criterio de aceptación**: `exit-scan.php` no contiene llamadas que escriban
`door_state`/`presence_state`; dos ejecuciones concurrentes producen un único cierre de estancia
y una única transición de `stay`.
**Test propio**: cubierto por TSK-F41-18 (idempotencia de salida).

---

## F41d: Orden temporal e idempotencia (RF-44)

### TSK-F41-07: Fingerprint, auditoría de aplicación y `decide()`

**Objetivo**: aplicar eventos por orden temporal, descartar duplicados/atrasados/noop y auditar
siempre el evento bruto.
**Trazabilidad**: RF-44.1, RF-44.2, RF-44.3 · `contracts.md` §1, §2.
**Archivos**:
- `api/src/Domain/Presence/PresenceEventRepositoryInterface.php`
- `api/src/Domain/Presence/PresenceEventRepository.php`
- `api/src/Domain/Presence/IotSessionService.php`
- `api/src/Infrastructure/Gateways/Sensor/TuyaSensorIngress.php`
- `api/tests/Unit/SensorEventDecisionTest.php` (lo pone en verde)

**Dependencias**: TSK-F41-01 (test RED), TSK-F41-03, TSK-F41-05.
**Contenido**:
- [ ] `insertOrGet()`: calcula `event_fingerprint` y devuelve el evento existente ante `1062`
      (por fingerprint o `source_event_id`).
- [ ] `markAudit(id, applied, reason)`: rellena `applied`/`discard_reason`.
- [ ] Implementar `decide()` y `mutate()` según `design.md` §2.4, actualizando
      `last_<sensor>_event_at` y `last_<sensor>_value`.
- [ ] `TuyaSensorIngress`: conservar `source_event_id`, guardar `t` crudo en `meta_json.tuya_t`
      y calcular `occurred_at` truncado a segundo para el fingerprint.
- [ ] Garantizar que un descarte no muta estado y que el evento bruto queda persistido.

**Criterio de aceptación**: `php tests/Unit/SensorEventDecisionTest.php` → 0 failures; BLOCK 1 en
verde; un evento viejo no revierte un `CLOSED` nuevo (caso unitario).
**Test propio**: unitario PHP (TSK-F41-01 en verde).

---

## F41e: Regla de salida, reset y exposición (RF-47)

### TSK-F41-08: `entry_confirmed_at` y regla de salida con ciclo acreditado

**Objetivo**: implementar la precondición de entrada confirmada y la regla sin depender del
estado actual de puerta, con cota y cancelación.
**Trazabilidad**: RF-47.1…RF-47.4 · `contracts.md` §1, §3.
**Archivos**:
- `api/src/Domain/Presence/ExitRuleEvaluator.php`
- `api/src/Domain/Presence/IotSessionService.php` (consolidación al `CLOSED`)
- `api/src/Domain/Stays/StayRepository.php` (`lockActiveForRoom`)
- `api/tests/Unit/ExitRuleEvaluatorTest.php` (lo pone en verde)

**Dependencias**: TSK-F41-02, TSK-F41-03, TSK-F41-05.
**Contenido**:
- [ ] Constante `DOOR_CYCLE_MAX_S = 300` (dominio, sin config externa).
- [ ] `evaluate()`: exige `entry_confirmed_at`, `last_open_at > entry_confirmed_at`, ciclo
      `last_close_at >= last_open_at`, cota `now - last_close_at <= 300`, `ABSENT` + gap.
- [ ] En `mutate()` de `CLOSED`: fijar `stays.entry_confirmed_at` si hay presencia vista
      durante la apertura (`presence_state=PRESENT` o `last_presence_value=PRESENT` con
      `last_presence_event_at >= last_open_at`).
- [ ] Cancelación: `PRESENT` limpia `last_absent_since` (ya en `mutate()`).

**Criterio de aceptación**: `php tests/Unit/ExitRuleEvaluatorTest.php` → 0 failures; BLOCK 1 en
verde.
**Test propio**: unitario PHP (TSK-F41-02 en verde).

---

### TSK-F41-09: Reset de habitación limpia todas las marcas

**Objetivo**: que un reset deje la habitación lista para una secuencia nueva.
**Trazabilidad**: RF-47.5 · `contracts.md` §6.
**Archivos**:
- `api/src/Http/Controllers/QrTestController.php`

**Dependencias**: TSK-F41-03.
**Contenido**:
- [ ] En `roomsReset()`, añadir `last_close_at`, `last_door_event_at`,
      `last_presence_event_at`, `last_door_value`, `last_presence_value` al `ON DUPLICATE KEY
      UPDATE` (además de los ya limpiados).
- [ ] No borrar `presence_events` (auditoría inmutable); no cambiar la forma de la respuesta.

**Criterio de aceptación**: tras reset, `SELECT` de la sesión devuelve todas las marcas en
`NULL` salvo `door_state='CLOSED'`/`presence_state='ABSENT'`; una apertura+cierre posterior se
registra y acredita un ciclo nuevo.
**Test propio**: HTTP en TSK-F41-18.

---

### TSK-F41-10: `/live` y SSE `state` con campos nuevos y `exit_deadline` coherente

**Objetivo**: exponer los campos nuevos y la semántica de `exit_deadline` formalizada.
**Trazabilidad**: RF-46, RF-47 · `contracts.md` §3.
**Archivos**:
- `api/src/Http/Controllers/RoomLiveController.php`
- `api/src/Http/Controllers/EventStreamController.php`

**Dependencias**: TSK-F41-03, TSK-F41-08.
**Contenido**:
- [ ] `iot_session`: `last_door_event_at`, `last_presence_event_at`, `last_door_value`,
      `last_presence_value`.
- [ ] `active_stay`: `entry_confirmed_at`.
- [ ] `exit_deadline`: emitir solo con `presence_state=ABSENT` + ciclo acreditado + `door_state=CLOSED`;
      `null` si la puerta se reabre o reaparece presencia.
- [ ] Mantener intactos el resto de campos y `gap_seconds`.

**Criterio de aceptación**: `GET /api/v1/rooms/1/live` incluye los 5 campos nuevos (JSON válido);
`fetchFullState()` de SSE devuelve el mismo payload; `exit_deadline` es `null` con puerta abierta
o presencia.
**Test propio**: HTTP en TSK-F41-18.

---

## F41f: Poller de presencia (RF-45)

### TSK-F41-11: `isCaptureWindow()` por estado de dominio

**Objetivo**: decidir la captura con el estado de dominio del snapshot, sin banderas pegajosas.
**Trazabilidad**: RF-45.1, RF-45.2 · `contracts.md` §7 · `design.md` §4.
**Archivos**:
- `api/bin/tuya-presence-poller.js`

**Dependencias**: TSK-F41-10.
**Contenido**:
- [ ] Paradas: `inside` (OCCUPIED + PRESENT + CLOSED + `entry_confirmed_at`) y `empty`
      (sin stay + ABSENT + sin deadline).
- [ ] Capturas: `door=OPEN`, `exit_deadline` activo, `entryInProgress` (derivada de
      `qr_status` + `active_stay` + `iot_session`, `ENTRY_WINDOW_MS=90s` interno) y
      `verificationWindow` (`last_close_at` dentro de `gap+10s`).
- [ ] Eliminar `presenceConfirmed` u otras banderas pegajosas; usar solo el snapshot.
- [ ] Conservar throttle (5 s / 2 s con deadline) y backoff de cuota (10 min).

**Criterio de aceptación**: el log deja de llamar a Tuya con el huésped dentro o con la
habitación vacía; `node --check api/bin/tuya-presence-poller.js` OK.
**Test propio**: unitario JS en TSK-F41-12.

---

### TSK-F41-12: Watchdog de captura máxima + tests unitarios JS

**Objetivo**: evitar bucles infinitos de captura y fijar el gate con tests.
**Trazabilidad**: RF-45.3 · `design.md` §4.3, §4.4.
**Archivos**:
- `api/bin/tuya-presence-poller.js`
- `api/tests/Unit/presence-gate.test.js` (nuevo)

**Dependencias**: TSK-F41-11.
**Contenido**:
- [ ] `CAPTURE_MAX_MS=120000`, `CAPTURE_COOLDOWN_MS=30000`; al superar el máximo: log de
      diagnóstico, parar y cooldown. Un cambio de `door` rearma.
- [ ] Exportar `isCaptureWindow()`/`nextCaptureState()` para test (módulo Node).
- [ ] Test JS: `inside`, `empty`, `OPEN`, `deadline`, `entryInProgress`,
      `verificationWindow`, watchdog dispara a los 120 s, cooldown, rearme por cambio de puerta.
- [ ] Invocar `node api/tests/Unit/presence-gate.test.js` desde `run-tests.sh` (BLOCK 33),
      mapeando su exit code a `pass`/`fail`.

**Criterio de aceptación**: `node tests/Unit/presence-gate.test.js` exit `0`; el runner lo
reporta como un PASS más.
**Test propio**: unitario JS (nuevo).

---

## F41g: Máquina de estados del panel (RF-46 / RF-49)

### TSK-F41-13: `deriveChoreography()` pura por episodios (T1–T18)

**Objetivo**: reemplazar la derivación acoplada por una función pura de episodios, sin bandera
pegajosa.
**Trazabilidad**: RF-46.1, RF-46.2, RF-46.3, RF-47.3 · `design.md` §5.
**Archivos**:
- `api/public/dashboard.html`
- `api/tests/Unit/choreography.test.js` (nuevo, recomendado)

**Dependencias**: TSK-F41-10.
**Contenido**:
- [ ] Implementar `deriveChoreography(snapshot, episodes, now)` pura (sin `Date.now()` ni DOM).
- [ ] Estados lógicos y mapeo a claves UI existentes; tiempo de umbral (`EN_UMBRAL`) con puerta
      abierta y consolidación `DENTRO` al cerrar con `entry_confirmed_at`/presencia vista.
- [ ] Sustituir `_presenceDetectedDuringOpen` por `entryEpisode.presenceSeenWhileOpen`
      (reiniciado al consolidar y al cambiar de `stayId`).
- [ ] Distinguir entrada/salida con `entry_confirmed_at` + `last_open_at > entry_confirmed_at`,
      no con la ventana QR de 120 s.
- [ ] `processLiveData()` solo invoca la función y aplica `episodeUpdates`.
- [ ] Cubrir T1–T18 de `design.md` §5.3 con tests de la función pura.

**Criterio de aceptación**: test JS de coreografía exit `0`; en el panel, tras QR + OPEN el
avatar queda en umbral y al cerrar pasa a dentro; una apertura tras entrada confirmada muestra
`POSIBLE_SALIDA` y nunca un salto directo QR→dentro.
**Test propio**: unitario JS (función pura).

---

### TSK-F41-14: Watchdog SSE y conteo por temporizador local

**Objetivo**: detectar silencio del stream y resincronizar; mostrar el conteo de forma fiable.
**Trazabilidad**: RF-49.1, RF-49.2, RF-49.3 · `contracts.md` §4 · `design.md` §6.
**Archivos**:
- `api/public/dashboard.html`

**Dependencias**: TSK-F41-13, TSK-F41-15.
**Contenido**:
- [ ] `_lastSseEventAt` actualizado en `connected`, `state` y `ping`; watchdog (1 s) que
      resincroniza `/live` tras >5 s de silencio (pestaña visible) y fuerza `connectSSE()` a
      los >15 s; throttle para no repetir fetch.
- [ ] `updateCountdown()` en `setInterval(250 ms)` mientras haya `exit_deadline`; ocultar si no
      hay deadline / presencia / `door=CLOSED` / estancia `OCCUPIED`; a 0 → `resyncLive()`.
- [ ] Trazabilidad de transiciones de coreografía bajo flag `DEBUG_CHOREO`.

**Criterio de aceptación**: con el stream vivo pero sin `state`, el panel se resincroniza en
≤ ~6 s; el conteo avanza en cliente aunque no lleguen eventos y desaparece al cancelarse.
**Test propio**: manual (worktree/webapp) + cobertura parcial del test puro de coreografía.

---

### TSK-F41-15: Evento SSE `ping` en backend

**Objetivo**: emitir una señal de vida nombrada cada ~5 s.
**Trazabilidad**: RF-49.1 · `contracts.md` §4.
**Archivos**:
- `api/src/Http/Controllers/EventStreamController.php`

**Dependencias**: ninguna adicional.
**Contenido**:
- [ ] Emitir `event: ping` con `data: {"room_id":N,"ts":"<ISO-8601Z>"}` cada ~5 s.
- [ ] Conservar el comentario `keepalive` cada 15 s y los eventos `connected`/`state`/`close`.

**Criterio de aceptación**: `curl -N` al stream muestra `event: ping` con el payload esperado y
sin alterar `state`.
**Test propio**: HTTP/SSE en TSK-F41-18.

---

## F41h: Infraestructura de workers (RF-48)

### TSK-F41-16: `stop-all.sh`/`start-all.sh` deterministas e instancia única

**Objetivo**: eliminar wrappers huérfanos y garantizar una instancia por worker.
**Trazabilidad**: RF-48.1, RF-48.4 · `design.md` §7.1.
**Archivos**:
- `stop-all.sh` (nuevo)
- `start-all.sh`

**Dependencias**: TSK-F41-06.
**Contenido**:
- [ ] `stop-all.sh`: matar primero los wrappers (`bash -c … bin/<worker>`), después los hijos
      (`php bin/<worker>.php`, `node …`), espera acotada y escalado `TERM → KILL`.
- [ ] `start-all.sh` invoca `stop-all.sh` al inicio; lanza cada worker con `setsid` y PID file
      en `api/run/<worker>.pid`; si el PID vive, no relanza.
- [ ] Incluir `exit-scan`, `overstay-scan`, `outbox-worker`, `anomaly-scanner`,
      `presence-poller-manager` y `pulsar-consumer`.
- [ ] Documentar en el propio script el patrón de parada (sin `reset --hard` ni comandos
      destructivos de git).

**Criterio de aceptación**: dos ejecuciones seguidas de `start-all.sh` dejan exactamente una
instancia por worker (`pgrep -fc`); tras `stop-all.sh` no quedan procesos.
**Test propio**: verificación manual + `instances` en TSK-F41-18.

---

### TSK-F41-17: `system-status` ampliado (6 workers, `instances`/`healthy`/`degraded`)

**Objetivo**: exponer instancias y salud de los workers.
**Trazabilidad**: RF-48.2 · `contracts.md` §5.
**Archivos**:
- `api/public/index.php`

**Dependencias**: TSK-F41-16.
**Contenido**:
- [ ] Ampliar a las 6 claves: `exit-scan`, `overstay-scan`, `outbox-worker`,
      `anomaly-scanner`, `presence-poller-manager`, `pulsar-consumer`.
- [ ] Campos: `label`, `online`, `pid`, `expected=1`, `instances`, `pids` (`[]` si no hay),
      `healthy = instances === expected`, `degraded = instances > expected`.
- [ ] Conservar el frontend existente: `renderSystemStatus()` usa `label`/`online` (sigue
      funcionando).

**Criterio de aceptación**: la respuesta JSON contiene las 6 claves y los 8 campos; con 0
instancias `pids=[]`, `online=false`, `healthy=false`; con 1, `healthy=true`.
**Test propio**: HTTP en TSK-F41-18.

---

## F41i: Tests HTTP / regresión

### TSK-F41-18: BLOCK 33 — escenarios de coreografía, concurrencia y contratos

**Objetivo**: cubrir de extremo a extremo la fase en el runner acumulativo.
**Trazabilidad**: RF-43…RF-49 · `contracts.md` §3, §4, §5, §6.
**Archivos**:
- `api/bin/run-tests.sh` (nuevo BLOCK 33, antes del `RESUMEN`)

**Dependencias**: TSK-F41-12, TSK-F41-17 (y por transitividad todas las anteriores).
**Contenido** (usar `/sim/rooms/1/{door,presence}` con `SIM-CLIENT`, `ADMIN-CLI` para panel,
claves de `seeds/dev_api_keys.txt` y `--idem`):
- [ ] Invocación JS: `node tests/Unit/presence-gate.test.js` mapeada a `pass`/`fail`.
- [ ] **Entrada umbral→dentro**: reset → QR/pack → `door OPEN` → `presence PRESENT` →
      `GET /live` (avatar/estado `EN_UMBRAL`, `last_open_at`/`last_presence_event_at`) →
      `door CLOSED` → `GET /live` (`entry_confirmed_at` no nulo, estado dentro).
- [ ] **Apertura+cierre con presencia**: dentro con PRESENT y `door CLOSED` ⇒ `exit_deadline=null`.
- [ ] **Sensores dentro**: con el huésped dentro, `door OPEN` y `door CLOSED` se registran
      (`last_door_event_at` cambia y `last_door_value` = valor enviado) — antes no ocurría.
- [ ] **Salida con conteo**: `door OPEN` → `door CLOSED` → `presence ABSENT` ⇒ `exit_deadline`
      no nulo; esperar el gap ⇒ estancia `EXITED`, `room FREE`, (luz off best-effort).
- [ ] **Reaparición cancela**: misma secuencia pero `presence PRESENT` antes del gap ⇒
      `exit_deadline=null` y estancia `OCCUPIED`.
- [ ] **Concurrencia (RC-1)**: dos `POST /sim/rooms/1/door` **simultáneos** (OPEN y CLOSED,
      lanzados en background con `curl … &` + `wait`) ⇒ `GET /live` final con
      `last_door_value=CLOSED` y `door_state=CLOSED` (no debe quedar OPEN).
- [ ] **Idempotencia de salida**: dos evaluaciones concurrentes ⇒ un solo cierre (un solo
      `exit_detected_at`/transición).
- [ ] **`/live` campos nuevos**: presencia de los 5 campos y tipo correcto.
- [ ] **`system-status`**: 6 claves, `expected=1`, `pids` array, `healthy`/`degraded` coherentes.
- [ ] **Reset limpia marcas**: tras reset, `last_close_at`, `last_door_event_at`,
      `last_presence_event_at`, `last_door_value`, `last_presence_value` a `null`.
- [ ] **SSE `ping`**: lectura con `curl -N --max-time 6` del stream contiene `event: ping`.
- [ ] Marcar `skip` con mensaje si la BD/estado no permite un caso stateful.

**Criterio de aceptación**: `bash bin/run-tests.sh` incluye BLOCK 33 y todos sus casos pasan
(0 failures) en una BD en el estado esperado.
**Test propio**: HTTP/integración (nuevo bloque).

---

## F41j: Cierre de fase

### TSK-F41-19: Regresión completa, documentación y log

**Objetivo**: cerrar la fase con evidencia.
**Trazabilidad**: AGENTS.md (tabla de fases y testing obligatorio).
**Archivos**:
- `AGENTS.md`
- `api/logs/test-results.log` (generado por el runner)
- `.specs/specs/tasks.md` (marcar las casillas de esta fase)

**Dependencias**: TSK-F41-18.
**Contenido**:
- [ ] Ejecutar `cd /root/cerraduras/api && bash bin/run-tests.sh` → **0 failures**.
- [ ] Actualizar la tabla de fases de `AGENTS.md`: añadir
      `| **F41 Robustez sensores + coreografía** | **BLOCK 33** | **Completado** |`.
- [ ] Confirmar que el bloque del runner (tabla de fases) refleja BLOCK 33.
- [ ] Registrar el resultado en `api/logs/test-results.log` (lo escribe el runner; verificar).
- [ ] Marcar `- [x]` en todas las tareas F41 al completarlas.

**Criterio de aceptación**: runner con `0 failures`; AGENTS.md y tasks.md actualizados; log con
la ejecución final.
**Test propio**: ejecución de regresión completa.

---

## Tabla resumen de tareas F41

| TSK | Título | RF | Archivos principales | Test |
|-----|--------|----|----------------------|------|
| F41-01 | Test orden/dedup `decide()` (RED) | RF-44 | `tests/Unit/SensorEventDecisionTest.php` | unit PHP |
| F41-02 | Test regla de salida + migrar tests viejos (RED) | RF-47 | `tests/Unit/ExitRuleEvaluatorTest.php` | unit PHP |
| F41-03 | Migración 0108 + hidratación | RF-43/44/46/47 | `migrations/0108_*`, `IotSession`, `PresenceEvent`, `Stay` + repos | BLOCK 2 |
| F41-04 | `lockByRoomId`/`updateState`/`markExitEvaluated` | RF-43 | `IotSessionRepository*` + fakes de test | unit PHP |
| F41-05 | `processEvent()` atómico + post-commit | RF-43 | `IotSessionService.php` | unit + HTTP |
| F41-06 | `exit-scan` idempotente sin escribir sensores | RF-43/47/48 | `ExitActionService.php`, `bin/exit-scan.php` | HTTP |
| F41-07 | Fingerprint + `decide()` + auditoría | RF-44 | `PresenceEventRepository*`, `TuyaSensorIngress.php` | unit PHP |
| F41-08 | `entry_confirmed_at` + ciclo acreditado | RF-47 | `ExitRuleEvaluator.php`, `StayRepository.php` | unit PHP |
| F41-09 | Reset limpia marcas | RF-47.5 | `QrTestController.php` | HTTP |
| F41-10 | `/live` + SSE campos nuevos y deadline | RF-46/47 | `RoomLiveController.php`, `EventStreamController.php` | HTTP |
| F41-11 | Gate del poller por dominio | RF-45 | `bin/tuya-presence-poller.js` | unit JS |
| F41-12 | Watchdog 120 s/30 s + tests JS | RF-45.3 | `tuya-presence-poller.js`, `tests/Unit/presence-gate.test.js` | unit JS |
| F41-13 | `deriveChoreography()` pura T1–T18 | RF-46/47 | `dashboard.html`, `tests/Unit/choreography.test.js` | unit JS |
| F41-14 | Watchdog SSE + conteo local | RF-49 | `dashboard.html` | manual |
| F41-15 | Evento SSE `ping` | RF-49 | `EventStreamController.php` | HTTP/SSE |
| F41-16 | `stop-all.sh`/`start-all.sh` + PID files | RF-48 | `start-all.sh`, `stop-all.sh` | manual |
| F41-17 | `system-status` ampliado | RF-48.2 | `public/index.php` | HTTP |
| F41-18 | BLOCK 33: coreografía, concurrencia, contratos | RF-43…49 | `bin/run-tests.sh` | HTTP |
| F41-19 | Cierre: regresión + docs + log | — | `AGENTS.md`, `logs/test-results.log` | regresión |

**Número de BLOCK elegido**: **BLOCK 33** (último existente: BLOCK 32 — F43 Sensor 24G V3; sin
placeholders pendientes; se inserta antes del `RESUMEN`).

---

## F42: Ventana de verificación de entrada (RF-46.4)

**Contexto**: al escanear un QR, abrir y cerrar la puerta sin que el radar haya detectado aún
presencia, el monigote volvía fuera (lector QR) y no había ventana de espera. Además,
`exit_deadline` se emitía sin `entry_confirmed_at`, mostrando un conteo de salida durante la
entrada.

### TSK-F42-01: `deriveChoreography()` — estado `VERIFICANDO_ENTRADA`

**Objetivo**: función pura con la ventana de entrada y `entryTimedOut`.
**Trazabilidad**: RF-46.4.1–46.4.5 · `design.md` §5.
**Archivos**: `api/public/assets/choreography.js`
**Contenido**:
- [x] `emptyEpisodes().entryTimedOut`; reset en `startEntry()`/`consolidateEntry()` y en OPEN.
- [x] Bloque `door == CLOSED` de entrada: ciclo acreditado + sin PRESENT + `now - last_close_at < gap`
      ⇒ `VERIFICANDO_ENTRADA`; al agotar ⇒ `entryTimedOut = true` ⇒ `ESPERANDO_APERTURA`.
- [x] T8 no consolida si `entryTimedOut` (la presencia tardía exige nueva apertura).
- [x] `LOGICAL_TO_UI.VERIFICANDO_ENTRADA`.

**Criterio de aceptación**: test JS de coreografía en verde (casos T-w1…T-w7).
**Test propio**: unitario JS.

### TSK-F42-02: Panel — umbral, `?` y conteo de entrada

**Objetivo**: render del nuevo estado y conteo anclado a `last_close_at`.
**Trazabilidad**: RF-46.4.1, RF-49.2.1/49.2.3 · `design.md` §5.
**Archivos**: `api/public/dashboard.html`
**Contenido**:
- [x] `STATES.VERIFICANDO_ENTRADA` (moni `WAITING`, `door:'closed'`, icono `❓`).
- [x] `applyState()`: `verifyingEntry` pinta el `?`.
- [x] `updateCountdown()`: modo entrada (`currentState === 'VERIFICANDO_ENTRADA'` + `CLOSED` +
      `OCCUPIED`), ancla `last_close_at + gap_seconds`, etiqueta "ventana entrada".
- [x] `updatePollingFrequency()`: `VERIFICANDO_ENTRADA` → 4 (captura rápida).
- [x] `updateSensorPanel()`: indicador "❓ entrada (Ns)".

**Criterio de aceptación**: el conteo avanza en cliente y desaparece al consolidar/expirar.
**Test propio**: manual (webapp) + cobertura de la función pura.

### TSK-F42-03: Backend — consolidación de entrada por presencia tardía

**Objetivo**: fijar `entry_confirmed_at` si el PRESENT llega tras el cierre dentro del gap.
**Trazabilidad**: RF-46.4.2 · `contracts.md` §1.2/§3.
**Archivos**: `api/src/Domain/Presence/IotSessionService.php`
**Contenido**:
- [x] En `SENSOR_PRESENCE`/`PRESENT`, con `door_state == CLOSED`, invocar
      `consolidateEntry(..., requireRecentClose: true)`.
- [x] `consolidateEntry()`: parámetros `?Room $room`, `bool $requireRecentClose`; exige cierre
      dentro de `resolveGapSeconds($room)`.
- [x] Con la puerta abierta NO consolida (el avatar espera en el umbral, RF-46.1.3).

**Criterio de aceptación**: unit tests PHP F42 en verde.
**Test propio**: unitario PHP (`IotSessionServiceTest.php`).

### TSK-F42-04: Backend — `exit_deadline` exige `entry_confirmed_at`

**Objetivo**: no emitir el conteo de salida en una entrada sin consolidar.
**Trazabilidad**: RF-46.4.5 · `contracts.md` §3.3.
**Archivos**: `api/src/Http/Controllers/RoomLiveController.php`,
`api/src/Http/Controllers/EventStreamController.php`
**Contenido**:
- [x] Añadir a la guarda de `exit_deadline`: `active_stay.entry_confirmed_at` no nulo.
- [x] Documentar la precondición en `contracts.md` §3.3.

**Criterio de aceptación**: `/live` devuelve `exit_deadline=null` con entrada sin confirmar;
activo tras consolidar + ABSENT.
**Test propio**: HTTP (BLOCK 34).

### TSK-F42-05: Tests JS/PHP y BLOCK 34

**Objetivo**: cobertura acumulada de la fase.
**Trazabilidad**: RF-46.4 · `design.md` §5.
**Archivos**: `api/tests/Unit/choreography.test.js`, `api/tests/Unit/IotSessionServiceTest.php`,
`api/bin/run-tests.sh`
**Contenido**:
- [x] T-w1…T-w7 en `choreography.test.js` (ventana, expiración, rearme, presencia tardía).
- [x] Casos F42 en `IotSessionServiceTest.php` (dentro/fuera de gap, puerta abierta).
- [x] BLOCK 34 en el runner: `exit_deadline` nulo sin confirmar, consolidación por PRESENT
      tardío, `exit_deadline` activo tras confirmar.

**Criterio de aceptación**: BLOCK 1 y BLOCK 34 en verde.
**Test propio**: unitario + HTTP.

### TSK-F42-06: Specs y regresión

**Objetivo**: cerrar la fase con documentación y regresión completa.
**Trazabilidad**: AGENTS.md.
**Archivos**: `.specs/specs/{requirements,design,contracts,tasks}.md`, `AGENTS.md`,
`api/logs/test-results.log`.
**Contenido**:
- [x] RF-46.4 y RF-49.2.3; `design.md` §5; `contracts.md` §3.3; esta sección.
- [x] `bash bin/run-tests.sh` → 0 failures y log actualizado (247 passed, 0 failed, 7 skipped).
- [x] Añadir la fila F42/BLOCK 34 a la tabla de fases de `AGENTS.md`.

**Criterio de aceptación**: runner con 0 failures; AGENTS.md y log actualizados.
**Test propio**: regresión completa.

### Tabla resumen de tareas F42

| TSK | Título | RF | Archivos principales | Test |
|-----|--------|----|----------------------|------|
| F42-01 | `deriveChoreography()` `VERIFICANDO_ENTRADA` | RF-46.4 | `choreography.js` | unit JS |
| F42-02 | Panel: umbral + `?` + conteo | RF-46.4/49.2 | `dashboard.html` | manual |
| F42-03 | Consolidación por presencia tardía | RF-46.4.2 | `IotSessionService.php` | unit PHP |
| F42-04 | `exit_deadline` exige `entry_confirmed_at` | RF-46.4.5 | `RoomLiveController.php`, `EventStreamController.php` | HTTP |
| F42-05 | Tests + BLOCK 34 | RF-46.4 | `choreography.test.js`, `IotSessionServiceTest.php`, `run-tests.sh` | unit + HTTP |
| F42-06 | Specs + regresión | — | `.specs/`, `AGENTS.md`, log | regresión |

**Número de BLOCK elegido**: **BLOCK 34** (último existente: BLOCK 33 — F41; se inserta antes del
`RESUMEN`).

---

## Riesgos de secuenciación detectados

1. **Tests existentes que fijan la semántica antigua** — `api/tests/Unit/ExitRuleEvaluatorTest.php`
   (T1b «door OPEN ⇒ no fire», T2/T3/T5, T6 dependiente de `DOOR_CLOSE_WINDOW_S=60`) quedará en
   rojo al cambiar `evaluate()`. Se resuelve en **TSK-F41-02** actualizando esos casos a la nueva
   semántica; hasta TSK-F41-08 BLOCK 1 tendrá fallos conocidos y acotados a ese fichero.
2. **Cambio de interfaz rompe fakes** — añadir métodos a `IotSessionRepositoryInterface` rompe
   los fakes de `AbsenceTimerTest.php`, `IotSessionServiceTest.php` y `ExitRuleEvaluatorTest.php`;
   añadir `lockActiveForRoom` a `StayRepositoryInterface` afecta además a
   `QrValidateServiceTest.php`, `DebtsServiceTest.php`, `StayStateMachineTest.php`,
   `QrIssueServiceTest.php`. **TSK-F41-04 debe actualizar todos los fakes en el mismo paso**;
   de lo contrario BLOCK 1 cae entero.
3. **Ventana RED entre F41a y F41d/F41e** — los tests de F41a quedan en rojo hasta sus
   implementaciones (F41-07/F41-08). Es el estado esperado en una fase abierta; no cerrar la fase
   (TSK-F41-19) hasta que BLOCK 1 esté 100 % verde.
4. **Dependencia F41-06 → F41-08** — `executeIfPending()` necesita la regla revisada; no se
   puede adelantar el worker sin la nueva semántica.
5. **Sin runner JS** — el poller y `deriveChoreography()` no se autodescubren; hay que añadir la
   invocación `node` explícita en BLOCK 33 (TSK-F41-12) o los tests JS no se ejecutarán.
6. **Datos vivos fuera de git** — `api/seeds/dev_api_keys.txt` no existe en el worktree
   (gitignored); los tests HTTP deben hacer `skip` explicativo si falta, y las claves se leen
   siempre del fichero (nunca hardcodeadas).
7. **Instancia única vs. entorno de desarrollo** — `stop-all.sh` mata procesos por patrón; debe
   acotarse a `bin/` y no tocar el servidor PHP (`php -S`) ni los tests.
8. **`FOR UPDATE` y `exit-scan`** — si TSK-F41-06 no elimina la escritura de sensores del worker,
   la concurrencia seguirá produciendo lost updates aunque F41-05 esté correcto.

---

# F44 — Tiempo real de sensores y presencia bajo demanda

**Trazabilidad**: RF-50, RF-51, RF-52 · design.md §13 · contracts.md Anexo F44
**Bloque runner**: BLOCK 35

## F44a: Consumer Pulsar (RF-50)

### TSK-F44-01: Instancia única (systemd dueño)
- **Cambio**: `start-all.sh` reinicia `cerraduras-pulsar-consumer` en vez de lanzar wrapper;
  `stop-all.sh` lo detiene con `systemctl stop` y no lo mata por patrón/cwd.
- **Test propio**: `systemctl is-active` + `system-status.pulsar-consumer.instances == 1`.

### TSK-F44-02: Devices dinámicos + resync
- **Cambio**: `api/bin/tuya-pulsar-consumer/index.js` — `parseDeviceIds()`, `loadKnownDevices()`,
  `isKnownDevice()`, `buildStatusPayload()`, `resyncKnownDevices()`; `require.main` guard y exports.
- **Test propio**: `tests/Unit/tuya-pulsar-consumer.test.js`.

## F44b: Poller de presencia (RF-51)

### TSK-F44-03: Ventanas configurables y muestreo inmediato
- **Cambio**: `api/bin/tuya-presence-poller.js` — `resolveEntryWindowMs()`, `resolveExitCheckMs()`,
  throttle 2 s, `lastTuyaCallAt=0` al abrir ventana.
- **Test propio**: `tests/Unit/presence-poller-gate.test.js` (casos F44).

### TSK-F44-04: Migración y `/live`
- **Cambio**: `migrations/0109_presence_entry_window.sql`; `RoomLiveController` expone
  `entry_window_seconds` y `exit_check_seconds`.
- **Test propio**: BLOCK 35 (`/live` campos nuevos).

## F44c: Semántica de presencia (RF-52)

### TSK-F44-05: Eliminar `far_detection ≤ 1 → ABSENT`
- **Cambio**: `tuya-presence-poller.js` (efectivo por `presence_state`), comentario en
  `TuyaSensorIngress.php`.
- **Test propio**: `tests/Unit/TuyaPresenceMoveTest.php` (T6a/T6b).

## F44d: Panel SSE (RF-50.4)

### TSK-F44-06: Reconexión tras cierre
- **Cambio**: `dashboard.html` — `scheduleSseReconnect()` con backoff; reset en `connected`.
- **Test propio**: BLOCK 35 (SSE `connected` + `ping`).

## F44e: Calibración (RF-52.3)

### TSK-F44-07: Calibrar PROTO2 a rango corto
- **Cambio**: `POST /dashboard-api/presence-calibrate/set {room_id:12, far_detection:150, sensitivity:10}`
  (el 24G V3 rechaza 75 cm; mínimo efectivo 150 cm, RF-52.3.3).
- **Test propio**: BLOCK 35 (read-back `GET status`).

## F44g: Salida fiable y prueba de paseo (RF-51.1.6 / RF-52.4)

### TSK-F44-09: Ciclo de salida no detiene el muestreo
- **Cambio**: `api/bin/tuya-presence-poller.js` — nueva función pura `pendingExitVerification(live, now)`
  (ciclo acreditado `last_open_at >= entry_confirmed_at` + `last_close_at >= last_open_at` dentro de
  `exit_check_seconds`); se excluye del stop `inside` de `shouldCapture()`; se exporta para tests.
- **Test propio**: `tests/Unit/presence-poller-gate.test.js` (BLOCK 33) — regresión del caso
  "ciclo de salida + PRESENT + CLOSED → capture".

### TSK-F44-10: Modo prueba de paseo
- **Cambio**: `api/public/dashboard.html` — bloque guiado en `#cal-modal` (límite → alejamiento →
  recomendación) y función pura `suggestFarAction(readings, caps)`; reutiliza
  `presence-calibrate/status|set` con `persist:false` y respeta el cooldown de cuota.
- **Test propio**: `tests/Unit/cal-walktest.test.js` (recomendación) + BLOCK 35.

## F44f: Cierre

### TSK-F44-08: BLOCK 35 y regresión
- **Cambio**: `bin/run-tests.sh` (BLOCK 35), `AGENTS.md` (tabla + sección F44).
- **Test propio**: `bash bin/run-tests.sh` con 0 failures.

## Tabla resumen

| Tarea | Descripción | RF | Archivos | Test |
|-------|-------------|----|----------|------|
| F44-01 | Instancia única consumer | RF-50.2 | `start-all.sh`, `stop-all.sh` | system-status |
| F44-02 | Consumer dinámico + resync | RF-50.1/50.3 | `bin/tuya-pulsar-consumer/index.js` | unit JS |
| F44-03 | Ventanas + muestreo inmediato | RF-51.1/51.2 | `bin/tuya-presence-poller.js` | unit JS |
| F44-04 | Migración 0109 + `/live` | RF-51.3 | `migrations/0109_*`, `RoomLiveController.php` | BLOCK 35 |
| F44-05 | Semántica presencia | RF-52.1/52.2 | poller, ingress | unit PHP |
| F44-06 | SSE reconnect | RF-50.4 | `public/dashboard.html` | BLOCK 35 |
| F44-07 | Calibrar PROTO2 | RF-52.3 | datos/dispositivo | BLOCK 35 |
| F44-08 | BLOCK 35 + regresión | — | `bin/run-tests.sh`, `AGENTS.md` | regresión |
| F44-09 | Ciclo de salida no detiene muestreo | RF-51.1.6 | `bin/tuya-presence-poller.js` | unit JS (BLOCK 33) |
| F44-10 | Modo prueba de paseo | RF-52.4 | `public/dashboard.html` | unit JS + BLOCK 35 |

---

# Fase 47 — Diagnóstico de latencia, robustez de recepción Tuya y arranque consistente

## F47a: Arranque consistente (RF-55)

### TSK-F47-01: Alinear pool de workers y verificar
- **Cambio**: `start-all.sh` — fallback manual del API a `PHP_CLI_SERVER_WORKERS=16` (mismo
  valor que `cerraduras-api.service`), comentario de acoplamiento con F46 y comprobación
  post-arranque del número de procesos `php -S 0.0.0.0:8080` (avisa, no aborta).
- **Test propio**: arranque manual + `pgrep -fc "php -S 0.0.0.0:8080"` == 16.

## F47b: Sonda de latencia sin cuota (RF-53)

### TSK-F47-02: `bin/presence-latency-probe.js`
- **Cambio**: nuevo script Node (sin deps nuevas) que cruza SSE, `presence_events`/
  `access_events` y marcas físicas; exporta funciones puras (`parseMarker`, `formatDelta`)
  para tests. No realiza llamadas a Tuya.
- **Test propio**: `tests/Unit/latency-probe.test.js` (parseo de marcas y deltas).

### TSK-F47-03: Runbook de prueba presencial
- **Cambio**: `docs/ops.md` — protocolo de marcas (`PUERTA_ABRE`, `DELANTE_SENSOR`,
  `QUIETO`, `ALEJO`), uso de la sonda y del sensor de puerta como control.
- **Test propio**: ejecución real durante la prueba (evidencia en informe).

## F47c: Robustez del consumer (RF-54)

### TSK-F47-04: Reconexión, resync por hueco y watchdog
- **Cambio**: `bin/tuya-pulsar-consumer/index.js` — base de reconexión 1 s; `disconnectedAt`
  y resync por hueco real; watchdog de silencio con devices rastreados; log de latencia
  `recv - tuya_t`; fichero `run/pulsar-consumer-status.json`. Funciones puras exportadas
  (`shouldResync`, `silenceExceeded`).
- **Test propio**: ampliar `tests/Unit/tuya-pulsar-consumer.test.js`.

### TSK-F47-05: Estado del consumer en `system-status`
- **Cambio**: `public/index.php` — campos **aditivos** en `pulsar-consumer`
  (`ws_connected`, `ws_silent`, `status_reason`) a partir del fichero de estado.
  **No** se alteran `healthy`/`degraded` (contrato F41 §5: `healthy === (instances === expected)`).
- **Test propio**: BLOCK 36 del runner (`system-status` con `healthy`).

## F47d: Latencia de puerta (RF-56)

### TSK-F47-06: Instrumentar `scan→post` en firmware
- **Cambio**: `docs/esp32-qr-reader/scanner-relay-prod-12v-robusto-lowpower.ino` — registrar
  el instante de encolado del QR y log `[QR] Encolado→POST: %lu ms`; conservar el log de
  validación. Sin cambios de TLS.
- **Test propio**: medición manual con monitor serie (antes/después).

### TSK-F47-07: Prioridad de QR en el bucle
- **Cambio**: mismo sketch — si `hasPending`, procesar el QR antes de iniciar
  health/heartbeat/announce (reordenación del `loop()`), sin reuso de TLS.
- **Test propio**: medición manual de `scan→post` y `postMs` con QR durante heartbeat.

## F47e: Cierre

### TSK-F47-08: Runner y regresión
- **Cambio**: `bin/run-tests.sh` (BLOCK 36), `AGENTS.md` (tabla + sección F47).
- **Test propio**: `bash bin/run-tests.sh` con 0 failures.

### TSK-F47-10: Keep-alive TLS en el firmware (RF-56.4)
- **Motivo**: el handshake TLS por petición costaba ~1,8 s (medido 2026-09-17,
  `Validación HTTP 2133 ms`; Apache `KeepAliveTimeout 5` forzaba conexión nueva).
- **Cambio**: `docs/esp32-qr-reader/scanner-relay-prod-12v-robusto-lowpower.ino` —
  `http.setReuse(httpReuseEnabled)` (ON) con salvaguardas: timeout de socket 4 s,
  reset de socket si hueco > 60 s, reintento único en el QR con conexión nueva,
  log `reuse=0/1`, y `noteHttpResult()` que desactiva el reuse tras 3 fallos.
- **Corrección clave**: `~HTTPClient()` llama `_client->stop()`; con objetos locales
  destruía el socket compartido tras cada petición (por eso `reuse=0`). Se usa una
  **sesión persistente** `apiHttpSession()` (static) referenciada por todos los
  call sites, de modo que el destructor no corre en runtime.
- **Servidor**: `KeepAliveTimeout 75` + `MaxKeepAliveRequests 1000` en el vhost
  `cerraduras.josue.ink-le-ssl.conf` (Apache, fuera del repo).
- **Test propio**: BLOCK 36.3 (grep de `noteHttpResult` y `setReuse(httpReuseEnabled)`)
  + medición en serie del `Validación HTTP (ms)` (objetivo ~100-250 ms).
- **Rollback**: reflashear el binario previo; revertir la línea de Apache.

### TSK-F47-09 (TEMP): Botonera de marcas en el panel
- **Motivo**: facilitar la prueba física sin SSH. **Herramienta temporal**.
- **Cambio**: `public/dashboard.html` — barra `#latency-marker-bar` bajo el croquis,
  visible solo con `?debug=1`, con botones `PUERTA_ABRE`, `DELANTE_SENSOR`, `QUIETO`,
  `ALEJO` y función `latencyMark()`; `public/index.php` — ruta temporal
  `POST /dashboard-api/latency-mark` (whitelist, append a `api/run/latency-marker`);
  el reset de habitación (`/dashboard-api/rooms/reset`) vacía el fichero.
- **Test propio**: BLOCK 36.4 (presencia + whitelist 200/400).
- **Reversión**: al terminar las pruebas, quitar la barra + JS + ruta temporal y el
  vaciado en `rooms/reset`. BLOCK 36.4 pasa a SKIP.

## Tabla resumen F47

| Tarea | Descripción | RF | Archivos | Test |
|-------|-------------|----|----------|------|
| F47-01 | Alinear pool de workers + verificación | RF-55 | `start-all.sh` | arranque + pgrep |
| F47-02 | Sonda de latencia sin cuota | RF-53 | `bin/presence-latency-probe.js` | unit JS |
| F47-03 | Runbook prueba presencial | RF-53 | `docs/ops.md` | prueba real |
| F47-04 | Reconexión/resync/watchdog consumer | RF-54 | `bin/tuya-pulsar-consumer/index.js` | unit JS |
| F47-05 | Estado consumer en `system-status` | RF-54.4 | `public/index.php` | BLOCK 36 |
| F47-06 | Instrumentar `scan→post` firmware | RF-56.1 | sketch ESP32 | serie (manual) |
| F47-07 | Prioridad de QR en bucle | RF-56.2 | sketch ESP32 | serie (manual) |
| F47-08 | BLOCK 36 + regresión | — | `bin/run-tests.sh`, `AGENTS.md` | regresión |
| F47-09 (TEMP) | Botonera de marcas en el panel | RF-53.1.2 | `public/dashboard.html`, `public/index.php` | BLOCK 36.4 |
| F47-10 | Keep-alive TLS en firmware + Apache | RF-56.4 | sketch ESP32, vhost Apache | BLOCK 36.3 + serie |

---

# Fase 48 — Credibilidad de presencia por contexto (RF-57)

### TSK-F48-01: Decisión pura con contexto
- **Cambio**: `src/Domain/Presence/SensorEventDecision.php` — nuevo `NO_CONTEXT`,
  `presenceCredible()`, y `decide(..., $entryWindowActive, $stayActive)`.
- **Test propio**: `tests/Unit/SensorEventDecisionTest.php` (casos F48).

### TSK-F48-02: Contexto en el servicio (único escritor)
- **Cambio**: `src/Domain/Presence/IotSessionService.php` — `isEntryWindowActive()`,
  `resolveEntryWindowSeconds()`, `hasActiveStay()`; se pasan a `decide()`.
- **Test propio**: unit JS/PHP puros + regresión BLOCK 1.

### TSK-F48-04: `recent_presence` solo aplicados (bug)
- **Cambio**: `PresenceEventRepositoryInterface::listForRoom(..., bool $appliedOnly=false)`,
  `PresenceEventRepository` (`AND applied = 1`), `RoomLiveController` (true) y
  `EventStreamController::fetchRecentPresence` (`AND applied = 1`).
- **Test propio**: unit `IotSessionServiceTest`/`AbsenceTimerTest` (fakes compatibles) + regresión.

### TSK-F48-05: Apertura optimista en el panel (latencia percibida)
- **Cambio**: `public/dashboard.html` — `renderSensorSvg()` mantiene la puerta abierta
  desde el `OPEN` de acceso (`recent_events`) hasta el `CLOSED` del magneto o 12 s.
- **Test propio**: verificación manual + regresión.

### TSK-F48-03: Contrato y docs
- **Cambio**: `contracts.md` (`no_context` en `applied`/`discard_reason`),
  `requirements.md` (RF-57), `design.md` (§14.6), `docs/ops.md`.
- **Test propio**: `bash bin/run-tests.sh` 0 failures.

## Tabla resumen F48

| Tarea | Descripción | RF | Archivos | Test |
|-------|-------------|----|----------|------|
| F48-01 | Decisión pura de credibilidad | RF-57.1/57.2 | `SensorEventDecision.php` | unit PHP |
| F48-02 | Contexto en `IotSessionService` | RF-57.2/57.3 | `IotSessionService.php` | unit + regresión |
| F48-03 | Contrato y documentación | RF-57.3 | `contracts.md`, `design.md`, `ops.md` | regresión |
| F48-04 | `recent_presence` solo aplicados | RF-57.2.2 | `PresenceEventRepository.php`, `RoomLiveController.php`, `EventStreamController.php` | unit + regresión |
| F48-05 | Apertura optimista del panel | RF-57.5 | `public/dashboard.html` | manual + regresión |

---

# Fase 49 — Guarda de salida corta y desacople entrada/salida (Bug 1, RF-47.2.4/47.2.5)

**Motivo**: `gap_seconds` (override de sala) gobernaba a la vez la ventana de consolidación de
ENTRADA y la guarda de SALIDA; un override legacy de ~15 s retrasaba ~30 s la confirmación de
salida. Se desacoplan: ENTRADA = `entry_window_seconds` (90 s), SALIDA =
`exit_guard_seconds` (default 3 s).

### TSK-F49-01: Resolución pura de la guarda de salida
- **Cambio**: `src/Domain/Presence/ExitRuleEvaluator.php` — `DEFAULT_GUARD_SECONDS=3`,
  `resolveGuardSeconds(?int $roomOverride, ?int $globalOverride): int` (estática y pura);
  `resolveGapSeconds(Room)` se conserva por wiring pero devuelve la guarda de salida.
- **Test propio**: casos T16–T19 en `tests/Unit/ExitRuleEvaluatorTest.php`.

### TSK-F49-02: Ventana de entrada desacoplada
- **Cambio**: `src/Domain/Presence/IotSessionService.php` — `consolidateEntry(requireRecentClose)`
  usa `resolveEntryWindowSeconds($room)` (default 90 s), no la guarda de salida (evita la
  regresión de F42).
- **Test propio**: sección F42 en `tests/Unit/IotSessionServiceTest.php` (caso "fuera de ventana"
  avanzado a > 90 s).

### TSK-F49-03: Exponer `exit_guard_seconds` y usarlo en `exit_deadline`
- **Cambio**: `RoomLiveController.php` y `EventStreamController.php` — `exit_deadline` usa la
  guarda; campo aditivo `exit_guard_seconds`; `gap_seconds` y `entry_window_seconds` intactos.
- **Test propio**: regresión HTTP BLOCK (pendiente de bloque de fase).

### TSK-F49-04: Migración de overrides legacy
- **Cambio**: `migrations/0113_exit_absence_guard.sql` — limpia
  `rooms.presence_check_seconds >= 10` para no conservar la tolerancia antigua.
- **Test propio**: `bash bin/run-tests.sh` (pendiente de bloque de fase).

### TSK-F49-05: Configuración, contrato y documentación
- **Cambio**: `.env.example` (`EXIT_ABSENCE_GUARD_SECONDS=3`); `requirements.md` (RF-47.2.4/47.2.5,
  RF-46.4.1, RF-49.2.3); `design.md` (§3.1/§3.4/§4/§8.1); `contracts.md` (§3.3, anexo F44);
  `tasks.md`.
- **Test propio**: `bash bin/run-tests.sh` 0 failures.

## Tabla resumen F49

| Tarea | Descripción | RF | Archivos | Test |
|-------|-------------|----|----------|------|
| F49-01 | Resolución pura de la guarda | RF-47.2.4 | `ExitRuleEvaluator.php` | unit PHP |
| F49-02 | Ventana de entrada desacoplada | RF-47.2.5 | `IotSessionService.php` | unit PHP |
| F49-03 | Exponer `exit_guard_seconds` | RF-47.2.4 | `RoomLiveController.php`, `EventStreamController.php` | regresión |
| F49-04 | Migración de overrides legacy | RF-47.2.4 | `migrations/0113_exit_absence_guard.sql` | regresión |
| F49-05 | Config + contrato/docs | RF-47.2.4/47.2.5 | `.env.example`, specs | regresión |

---

# Fase 50 — Estado real del SWITCH por push y robustez del consumer Pulsar (RF-58)

**Motivo**: el panel no conocía el estado real de la luz (Bug 2) y el consumer reconectaba en
reposo por un watchdog demasiado corto (churn, Bug 5); además un push sin sala se perdía con
500 (N10). Se rastrea el SWITCH por push (sin cuota) y se endurece el consumer.

### TSK-F50-01: Consumer rastrea SWITCH sin cuota
- **Cambio**: `api/bin/tuya-pulsar-consumer/index.js` — `TRACKED_KINDS` incluye `SWITCH`;
  nuevo `RESYNC_KINDS` + `loadResyncDevices()`; `resyncKnownDevices()` itera solo
  `resyncDeviceIds`; reconciliación de ambas listas.
- **Test propio**: `tests/Unit/tuya-pulsar-consumer.test.js` (kinds).

### TSK-F50-02: Watchdog largo, pong y reintento DNS
- **Cambio**: mismo archivo — `CONSUMER_SILENCE_MS` default `900000`; `lastPongAt` +
  `pongAgeMs` + `last_pong_at` en el status; `scheduleReconnect()` compartido por `close` y
  `error` (guarda anti-dobles-timers), base 1 s / backoff actual.
- **Test propio**: `pongAgeMs` y backstop en el unit JS.

### TSK-F50-03: Ingress SWITCH + room_not_found
- **Cambio**: `api/src/Infrastructure/Gateways/Sensor/TuyaSensorIngress.php` —
  `extractSwitchState()`/`persistSwitchState()` (`switch`/`switch_1` case-insensitive →
  `meta_json.switch_state`/`switch_state_at`); no-op `room_not_found` cuando no hay sala.
- **Test propio**: `tests/Unit/TuyaSwitchIngressTest.php` (fake repo).

### TSK-F50-04: Webhook 202 ante sala ausente
- **Cambio**: `api/src/Http/Controllers/TuyaWebhookController.php` — `discard_reason` aditivo
  en el 202 de no-op; captura `NotFoundException` de `processEvent` → 202
  `{accepted:false, discard_reason:'room_not_found'}`; 500 solo para errores inesperados.
- **Test propio**: cubierto por el unit del ingress + regresión HTTP de la fase.

### TSK-F50-05: Exponer `state`/`state_at`
- **Cambio**: `RoomLiveController.php` y `EventStreamController::fetchSwitchState()` —
  campos aditivos `state` (`meta.switch_state ?? 'UNKNOWN'`) y `state_at`.
- **Test propio**: regresión `/live` (BLOCK de la fase).

### TSK-F50-06: Contratos y documentación
- **Cambio**: `contracts.md` (§3.5 `state`/`state_at`, §3.6 descartes no-op/202, §2 consumer,
  status `last_pong_at`); `requirements.md` (RF-58); `design.md` (§15).
- **Test propio**: `bash bin/run-tests.sh` 0 failures.

## Tabla resumen F50

| Tarea | Descripción | RF | Archivos | Test |
|-------|-------------|----|----------|------|
| F50-01 | Consumer rastrea SWITCH (sin cuota) | RF-58.1.1/58.1.2 | `bin/tuya-pulsar-consumer/index.js` | unit JS |
| F50-02 | Watchdog 15 min + pong + reintento DNS | RF-58.2 | `bin/tuya-pulsar-consumer/index.js` | unit JS |
| F50-03 | Ingress SWITCH + room_not_found | RF-58.1.3/58.3.1 | `TuyaSensorIngress.php` | unit PHP |
| F50-04 | Webhook 202 ante sala ausente | RF-58.3.2 | `TuyaWebhookController.php` | regresión |
| F50-05 | Exponer `state`/`state_at` | RF-58.1.4 | `RoomLiveController.php`, `EventStreamController.php` | regresión |
| F50-06 | Contrato, requisitos y diseño | RF-58 | `contracts.md`, `requirements.md`, `design.md` | regresión |

---

# Fase 51 — Ciclo de vida del QR de huésped: llegada + estancia (Bug 4, RF-59)

**Motivo**: el QR se sellaba con `room_type.qr_usage_window_minutes` desde la emisión; quien
llegaba tarde no podía entrar y el token HMAC no se puede re-firmar. Se definen dos ventanas:
llegada (global) y uso (duración de la estancia, multi-uso).

### TSK-F51-01: Migración `0114_qr_arrival_window.sql`
- **Cambio**: `qr_credentials` += `first_used_at DATETIME(3) NULL`, `valid_until DATETIME(3) NULL`
  (`ADD COLUMN IF NOT EXISTS`); backfill idempotente `first_used_at=consumed_at`,
  `valid_until=consumed_at + INTERVAL stays.duracion_minutos MINUTE`.
- **Test propio**: regresión (BLOCK de la fase) + `php -l` no aplica (SQL).

### TSK-F51-02: Lógica pura `QrWindows`
- **Cambio**: nuevo `api/src/Domain/Qr/QrWindows.php` (`evaluate`, `arrivalDeadline`,
  `usageDeadline`).
- **Test propio**: `tests/Unit/QrArrivalWindowTest.php`.

### TSK-F51-03: Modelo + repositorio
- **Cambio**: `QrCredential` (`firstUsedAt`, `validUntil`);
  `QrCredentialRepositoryInterface::markFirstUse()`; `QrCredentialRepository::markFirstUse()` +
  hidratación de `first_used_at`/`valid_until`.
- **Test propio**: `tests/Unit/QrValidateServiceTest.php` (fake con `markFirstUse`).

### TSK-F51-04: Emisión unificada
- **Cambio**: `QrIssueService` `exp = iat + (QR_ARRIVAL_WINDOW_MINUTES + duracion) * 60`;
  `QrTestController::create/doCreate` mismo cálculo; `RoomType::qrUsageWindowMinutes` documentado
  como deprecado; `api/.env.example` += `QR_ARRIVAL_WINDOW_MINUTES=15`.
  (`api/bin/make-reservation-qr` emite vía `POST /api/v1/qr`: sin cálculo local que cambiar.)
- **Test propio**: `tests/Unit/QrIssueServiceTest.php` (`exp - iat == 4500`).

### TSK-F51-05: Validación por ventanas
- **Cambio**: `QrValidateService` — carga el `Stay` en el paso 4, sustituye el bloque
  consumed/S11 por `QrWindows::evaluate`; `qr_expired {window:arrival|usage}`; reclamo atómico
  `markFirstUse` al final; reentrada sin exigir `OCCUPIED` por la marca de uso.
- **Test propio**: `tests/Unit/QrValidateServiceTest.php` (4 casos + primer uso + reentrada).

### TSK-F51-06: Panel (`qr_status`)
- **Cambio**: `RoomLiveController::fetchQrStatus` y `EventStreamController::fetchQrStatus` —
  `expired` por ventanas; campos aditivos `first_used_at`, `valid_until`, `arrival_deadline`,
  `in_use`.
- **Test propio**: regresión `/live` + SSE (BLOCK de la fase).

### TSK-F51-07: Specs y contrato
- **Cambio**: `requirements.md` (RF-59), `design.md` (§16), `contracts.md` (§F51),
  `tasks.md`.
- **Test propio**: `bash bin/run-tests.sh` 0 failures.

## Tabla resumen F51

| Tarea | Descripción | RF | Archivos | Test |
|-------|-------------|----|----------|------|
| F51-01 | Migración columnas + backfill | RF-59.4.2 | `migrations/0114_qr_arrival_window.sql` | regresión |
| F51-02 | Lógica pura de ventanas | RF-59.1/59.2 | `QrWindows.php` | unit PHP |
| F51-03 | Modelo + `markFirstUse` atómico | RF-59.2.4/59.4.1 | `QrCredential.php`, `QrCredentialRepository*.php` | unit PHP |
| F51-04 | Sello `exp` unificado | RF-59.3 | `QrIssueService.php`, `QrTestController.php`, `RoomType.php`, `.env.example` | unit PHP |
| F51-05 | Validación por ventanas | RF-59.1.2/59.2 | `QrValidateService.php` | unit PHP |
| F51-06 | `qr_status` aditivo | RF-59.5 | `RoomLiveController.php`, `EventStreamController.php` | regresión |
| F51-07 | Specs y contrato | RF-59 | `requirements/design/contracts/tasks.md` | regresión |

---

# Fase 52 — Cola muerta del outbox (N6, RF-60)

**Motivo**: los mensajes veneno (4xx de WS-VB6) quedaban `FAILED` para siempre y `health/deep`
los contaba en `outbox_failed`, dejando el sistema `degraded` de forma indefinida.

### TSK-F52-01: Migración del enum + reclasificación
- **Cambio**: `migrations/0115_outbox_dead_letter.sql` — `MODIFY status ENUM(...,'DEAD')` y
  `UPDATE ... SET status='DEAD' WHERE status='FAILED' AND attempts>=20 AND last_error LIKE
  '%client_error%'` (idempotente).
- **RF**: RF-60.1.1, RF-60.2.1, RF-60.2.2.
- **Test propio**: guarda de fuente en `tests/Unit/OutboxDeadLetterTest.php`.

### TSK-F52-02: Estados terminales en el repositorio
- **Cambio**: `OutboxVb6Repository::markPermanentlyFailed()` → `'DEAD'`;
  `markFailed()` → `nextAttempts >= 20 ? 'DEAD' : 'PENDING'`;
  `scheduleRetry()` → `status IN ('PENDING','FAILED','DEAD')`; docblocks.
- **RF**: RF-60.1.2, RF-60.1.3, RF-60.1.4, RF-60.3.2.
- **Test propio**: `tests/Unit/OutboxDeadLetterTest.php`.

### TSK-F52-03: Health diferenciado (`outbox_dead`)
- **Cambio**: `HealthController::deep` mantiene `outbox_failed` (degrada) y añade `outbox_dead`
  [`status`, `count`, `note`] sin tocar `$allOk`.
- **RF**: RF-60.4.1, RF-60.4.2, RF-60.4.3.
- **Test propio**: `tests/Unit/OutboxDeadLetterTest.php` (guarda de fuente).

### TSK-F52-04: Reintento manual y visibilidad
- **Cambio**: `AdminController::retryOutbox` documenta y confirma reencolado de cualquier estado
  (incluido `DEAD`); `listOutbox` añade `dead` al `summary`.
- **RF**: RF-60.3.1, RF-60.4.4.
- **Test propio**: regresión del contrato de admin (sin cambio de forma).

### TSK-F52-05: Specs y contrato
- **Cambio**: `requirements.md` (RF-60), `design.md` (§17), `contracts.md` (§2.1/§2.2), `tasks.md`.
- **RF**: RF-60.
- **Test propio**: `php api/tests/Unit/OutboxDeadLetterTest.php`.

## Tabla resumen F52

| Tarea | Descripción | RF | Archivos | Test |
|-------|-------------|----|----------|------|
| F52-01 | Migración enum + reclasificación | RF-60.1.1/60.2 | `migrations/0115_outbox_dead_letter.sql` | unit PHP (guarda) |
| F52-02 | Estados terminales DEAD | RF-60.1.2/60.1.3/60.3.2 | `OutboxVb6Repository.php` | unit PHP (guarda) |
| F52-03 | `outbox_dead` en health | RF-60.4.1/60.4.2 | `HealthController.php` | unit PHP (guarda) |
| F52-04 | Reintento manual + summary | RF-60.3.1/60.4.4 | `AdminController.php` | unit PHP (guarda) |
| F52-05 | Specs y contrato | RF-60 | `requirements/design/contracts/tasks.md` | unit PHP |

---

# F53 — Panel: selector de habitación robusto (bugfix UX)

**Motivo**: en el panel (`/dashboard`) el desplegable de habitación no se abría
(Firefox/Safari escritorio). Diagnóstico: `fetchRooms()` corría cada 5 s y reconstruía los
`<option>` con `innerHTML=''`; la guarda de foco sólo se comprobaba **antes** del `await`, así
que una respuesta en vuelo cerraba/impedía el picker nativo. En algunos navegadores el click
enfoca el `<select>` pero no abre la lista (`showPicker()` sí). Los logs de `api.log` muestran
que el poll de `/dashboard-api/rooms` se detenía en seco al enfocar el selector mientras el
resto de polls seguían, confirmando el foco sobre el `<select>`.

### TSK-F53-01: Decisión pura + hardening de `fetchRooms`
- **Cambio**: nuevo `api/public/assets/room-selector.js` (UMD) con
  `shouldRebuildRoomOptions(...)`; `dashboard.html::fetchRooms` la usa y **re-comprueba tras el
  `await`** (foco o ventana `_roomPickerBusyUntil`); actualiza etiquetas **en sitio** cuando no
  cambian los ids; `_roomsSignature` sólo se fija tras un rebuild efectivo; el interval de 5 s
  también respeta la ventana de interacción.
- **Test propio**: `tests/Unit/room-selector.test.js` (16 casos).

### TSK-F53-02: Fallback del picker nativo (`showPicker`)
- **Cambio**: `wireRoomPicker()` en `dashboard.html` — en `#room-selector` y
  `#modal-room-select`, listeners `focusin/pointerdown/mousedown/touchstart` marcan la ventana
  de interacción; el listener `click` llama a `showPicker()` sólo si la lista no está ya
  abierta (`:open`), con try/catch para navegadores sin soporte `:open`.
- **Test propio**: verificación manual en Chromium/Firefox; cubierto también por la decisión
  pura de TSK-F53-01.

### TSK-F53-03: Runner y specs
- **Cambio**: `bin/run-tests.sh` añade el bloque JS `room-selector.test.js`; `tasks.md` (F53).
- **Test propio**: `bash bin/run-tests.sh` 0 failures.

## Tabla resumen F53

| Tarea | Descripción | RF | Archivos | Test |
|-------|-------------|----|----------|------|
| F53-01 | Decisión pura + hardening `fetchRooms` | UX panel | `assets/room-selector.js`, `dashboard.html` | unit JS |
| F53-02 | Fallback `showPicker` | UX panel | `dashboard.html` | manual + unit JS |
| F53-03 | Runner y specs | UX panel | `bin/run-tests.sh`, `tasks.md` | regresión |

---

# Fase 54 — Batería de aceptación E2E (BLOCK 42, RF-61)

**Motivo**: no existe una prueba integrada del ciclo de vida completo del huésped; los bloques
aislados pueden pasar con la integración rota.

**Sala de banco**: PROTO2 (`rooms.id=12`, pack `305`, RPI `a0e549858428`); `SIMULATED_MODE=false`
exige `simulated_override=1` para `/sim/*` y el lock simulado.

### TSK-F54-01: Specs y contrato
- **Cambio**: `requirements.md` (RF-61), `design.md` (§18), `contracts.md` (Fase 54), `tasks.md`.
- **RF**: RF-61.1, RF-61.5.
- **Test propio**: revisión de que no hay cambios de contrato de API (solo test).

### TSK-F54-02: Preflight, normalización y restauración de PROTO2
- **Cambio**: en `bin/run-tests.sh`, cabecera de `BLOCK 42`: gating de SERVER_UP/claves/BD/RPI;
  guardado `E2E_SAVE_*`; `_e2e_normalize` (cierre de stays, limpieza IoT/presence,
  `simulated_override=1`, `presence_check_seconds=5`, `FREE`); `POST /dashboard-api/rooms/reset`;
  `_e2e_cleanup`.
- **RF**: RF-61.3.1, RF-61.3.2, RF-61.4.1, RF-61.4.2.
- **Test propio**: el propio bloque (SKIP en precondición insuficiente).

### TSK-F54-03: Emisión y validación de QR (S1–S4)
- **Cambio**: `POST /api/v1/qr` (captura `stay_id`/`jti`/`qr_text`), aserciones `/live` RESERVED,
  `POST /api/v1/qr/validate` (device_id del RPI del pack), aserciones `/live` OCCUPIED y BD
  (`first_used_at`).
- **RF**: RF-61.1.2, RF-61.1.3, RF-61.3.3, RF-61.2.2.
- **Test propio**: pasos S1–S4 de BLOCK 42.

### TSK-F54-04: Entrada, salida y confirmación (S5–S9)
- **Cambio**: ciclo `/sim/*` de entrada (PRESENT/OPEN/CLOSED) y de salida (OPEN/CLOSED/ABSENT);
  aserciones de `entry_confirmed_at` y `exit_deadline`; reutilización/arranque de `exit-scan` y
  sondeo de `EXITED`/sala `FREE`/`AUTO_LOCK`.
- **RF**: RF-61.1.2, RF-61.2.1, RF-61.2.3, RF-61.3.4.
- **Test propio**: pasos S5–S9 de BLOCK 42.

### TSK-F54-05: Cierre de estancia, overstay y cleanup (S10–S12)
- **Cambio**: `POST /stays/{id}/close` (`stay.closed`), `GET /stays/{id}/overstay` (lectura),
  `_e2e_cleanup` + verificación de PROTO2 en `FREE`.
- **RF**: RF-61.1.2, RF-61.4.2, RF-61.4.4, RF-61.6.2.
- **Test propio**: pasos S10–S12 de BLOCK 42.

### TSK-F54-06: Registro en AGENTS.md
- **Cambio**: fila `F54 — Batería de aceptación E2E | BLOCK 42` en la tabla de fases del runner.
- **RF**: RF-61.5.1.
- **Test propio**: —

## Tabla resumen F54

| Tarea | Descripción | RF | Archivos | Test |
|-------|-------------|----|----------|------|
| F54-01 | Specs y contrato | RF-61.1/61.5 | `requirements/design/contracts/tasks.md` | revisión (sin cambio de API) |
| F54-02 | Preflight + normalize/restore PROTO2 | RF-61.3/61.4 | `api/bin/run-tests.sh` | BLOCK 42 (SKIP si falta precondición) |
| F54-03 | QR: emisión + validación (S1–S4) | RF-61.1/61.2/61.3 | `api/bin/run-tests.sh` | BLOCK 42 |
| F54-04 | Entrada/salida/confirmación (S5–S9) | RF-61.1/61.2/61.3 | `api/bin/run-tests.sh` | BLOCK 42 |
| F54-05 | Cierre, overstay y cleanup (S10–S12) | RF-61.1/61.4 | `api/bin/run-tests.sh` | BLOCK 42 |
| F54-06 | Registro en tabla de fases | RF-61.5 | `AGENTS.md` | — |

---

# Fase 55 — Panel de aceptación manual `/pruebas` (RF-62)

**Motivo**: las pruebas con hardware real deben ejecutarse a mano y dejar traza comparable hasta
que todo quede en verde.

### TSK-F55-01: Specs y contrato
- **Cambio**: `requirements.md` (RF-62), `design.md` (§19), `contracts.md` (F55), `tasks.md`, `AGENTS.md`.
- **RF**: RF-62.1, RF-62.5.
- **Test propio**: revisión de rutas aditivas.

### TSK-F55-02: Catálogo + lógica pura
- **Cambio**: `public/assets/acceptance-tests.json` (53 pruebas), `public/assets/acceptance-logic.js` (UMD),
  `tests/Unit/acceptance-logic.test.js`.
- **RF**: RF-62.1.2, RF-62.2.1, RF-62.3.2, RF-62.5.3.
- **Test propio**: `node tests/Unit/acceptance-logic.test.js` (BLOCK 1/43).

### TSK-F55-03: Página y controlador
- **Cambio**: `public/pruebas.html`, `public/assets/acceptance-app.js` (marcado, navegación,
  captura, autosave, corridas, export, banner verde).
- **RF**: RF-62.2, RF-62.3, RF-62.4.4.
- **Test propio**: `GET /pruebas` 200 + prueba manual.

### TSK-F55-04: Rutas y persistencia
- **Cambio**: `public/index.php` — `GET /pruebas` y `dashboard-api/acceptance/{save,list,get,delete}`
  con whitelist de `run_id` y escritura atómica en `api/run/acceptance/`.
- **RF**: RF-62.1.1, RF-62.4.1, RF-62.4.2, RF-62.4.3.
- **Test propio**: `BLOCK 43`.

### TSK-F55-05: Runner
- **Cambio**: `bin/run-tests.sh` — `BLOCK 43` (lógica JS, catálogo, endpoints save/get/list/delete,
  run_id inseguro → 400, 404).
- **RF**: RF-62.5.2.
- **Test propio**: `bash bin/run-tests.sh` 0 failures.

### TSK-F55-06: Registro en AGENTS.md
- **Cambio**: fila `F55 — Panel de aceptación manual | BLOCK 43`.
- **RF**: RF-62.1.
- **Test propio**: —

## Tabla resumen F55

| Tarea | Descripción | RF | Archivos | Test |
|-------|-------------|----|----------|------|
| F55-01 | Specs y contrato | RF-62.1/62.5 | `requirements/design/contracts/tasks.md`, `AGENTS.md` | revisión |
| F55-02 | Catálogo + lógica pura | RF-62.1.2/62.2.1/62.3.2 | `assets/acceptance-tests.json`, `assets/acceptance-logic.js`, `tests/Unit/acceptance-logic.test.js` | unit JS |
| F55-03 | Página + controlador | RF-62.2/62.3/62.4.4 | `public/pruebas.html`, `assets/acceptance-app.js` | `GET /pruebas` + manual |
| F55-04 | Rutas + persistencia | RF-62.1.1/62.4 | `public/index.php` | BLOCK 43 |
| F55-05 | Runner | RF-62.5.2 | `bin/run-tests.sh` | regresión |
| F55-06 | Registro AGENTS.md | RF-62.1 | `AGENTS.md` | — |

---

# Fase 56 — La puerta del croquis sigue al sensor físico (RF-63)

**Motivo**: al escanear el QR, el panel abría la puerta del croquis con el comando al relé
(`access_events` `OPEN` OK), 5–7 s antes de la apertura física. F48-05 (RF-57.5) queda revocado.

### TSK-F56-01: Specs y trazabilidad
- **Cambio**: `requirements.md` (RF-63, RF-57.5.1 superseded), `design.md` §20,
  `contracts.md` (sin cambios de contrato), `tasks.md`, `AGENTS.md`.
- **RF**: RF-63.1/63.2/63.3.
- **Test propio**: revisión + regresión.

### TSK-F56-02: Regla pura de puerta visual
- **Cambio**: `public/assets/choreography.js` — `resolveDoorOpen(doorState, pulseUntilMs, nowMs)`
  (aditiva; el comando del relé no es entrada de la función).
- **RF**: RF-63.2.1, RF-63.3.2.
- **Test propio**: casos F56 en `tests/Unit/choreography.test.js`.

### TSK-F56-03: Panel sin apertura optimista
- **Cambio**: `public/dashboard.html` — `renderSensorSvg()` elimina `optimisticOpen`
  (`latestAccessOpenMs`/`latestDoorClosedMs`) y calcula la puerta con `resolveDoorOpen`
  (fallback inline si el asset está cacheado); se conserva el pulso `PROXIMITY` y el pestillo
  verde de `QR_OK`; texto de fase de `QR_OK` ajustado.
- **RF**: RF-63.1.1, RF-63.1.2, RF-63.2.2.
- **Test propio**: unit JS (BLOCK 33) + verificación manual en `/dashboard`.

### TSK-F56-04: Criterio de aceptación manual
- **Cambio**: `public/assets/acceptance-tests.json` — P20 actualizada: pestillo verde y puerta
  cerrada hasta la apertura física; la puerta del croquis abre al abrirla de verdad.
- **RF**: RF-63.1.1, RF-63.2.1.
- **Test propio**: verificación manual (P19–P23) + regresión.

## Tabla resumen F56

| Tarea | Descripción | RF | Archivos | Test |
|-------|-------------|----|----------|------|
| F56-01 | Specs y trazabilidad | RF-63.1/63.2/63.3 | `requirements/design/contracts/tasks.md`, `AGENTS.md` | revisión |
| F56-02 | Regla pura `resolveDoorOpen` | RF-63.2.1/63.3.2 | `assets/choreography.js` | unit JS |
| F56-03 | Panel sin apertura optimista | RF-63.1/63.2 | `public/dashboard.html` | unit JS + manual |
| F56-04 | Criterio de aceptación manual | RF-63.1/63.2 | `assets/acceptance-tests.json` | manual + regresión |

---

# Fase 57 — Verificación de salida visible y coherente (RF-64)

**Motivo**: al cerrar la puerta tras una salida, el panel se quedaba 4–5 s sin timer y saltaba
de dentro a verificando/fuera. El conteo de salida exigía `exit_deadline` (solo con `ABSENT`) y
la ventana interna (6 s) no cubría la cadencia del radar (4–8 s) ni su ausencia (13–40 s).

### TSK-F57-01: Specs y trazabilidad
- **Cambio**: `requirements.md` (RF-64; RF-49.2.3 extendido), `design.md` (§5.2, §5.3, §6.2,
  §21), `contracts.md` (sin cambios de API), `tasks.md`, `AGENTS.md`.
- **RF**: RF-64.1–64.4.
- **Test propio**: revisión + regresión.

### TSK-F57-02: Ventana de verificación de salida (pura)
- **Cambio**: `public/assets/choreography.js` — `DEFAULT_EXIT_VERIFY_SECONDS = 20`,
  `max(20, exit_guard + 3)`; episodio `exitVerifyUntil`; T11 siempre `VERIFICANDO`; T13 espera
  la ventana completa; T13b al agotar → `DENTRO`; T14 deadline del backend manda; reapertura
  re-arma la ventana.
- **RF**: RF-64.1, RF-64.2, RF-64.3.1.
- **Test propio**: casos F57 en `tests/Unit/choreography.test.js` (BLOCK 33).

### TSK-F57-03: Timer visible en el panel
- **Cambio**: `public/dashboard.html` — `updateCountdown()` pinta el timer en
  `VERIFICANDO_PRESENCIA` con cualquier `presence_state`, anclado a `exitVerifyUntil`; con
  `exit_deadline` usa el deadline; arco con la ventana real; etiqueta `ventana salida`; al
  llegar a 0 → `resyncLive()`. La rama de entrada no se toca.
- **RF**: RF-64.1.2, RF-64.2, RF-64.3.2.
- **Test propio**: unit JS (BLOCK 33) + verificación manual en `/dashboard`.

### TSK-F57-04: Criterio de aceptación manual
- **Cambio**: `public/assets/acceptance-tests.json` — P35 actualizada: `?` + timer visible
  durante la verificación de salida (20 s / deadline real).
- **RF**: RF-64.1.2, RF-64.3.
- **Test propio**: verificación manual (P34–P37) + regresión.

### TSK-F57-05: Diagnóstico de latencia de la puerta (si procede)
- **Cambio**: ninguno (protocolo en `design.md` §21.4). Medir con `?debug=1` el `door age` al
  cerrar; cruzar con `presence_events.received_at − occurred_at` y `[LAT] recv-tuya_t`. Si el
  retardo es de Tuya (físico→cloud), documentar y decidir fase aparte.
- **RF**: RF-64.4.1.
- **Test propio**: evidencia en la corrida de aceptación.

## Tabla resumen F57

| Tarea | Descripción | RF | Archivos | Test |
|-------|-------------|----|----------|------|
| F57-01 | Specs y trazabilidad | RF-64.1/64.2/64.3/64.4 | `requirements/design/contracts/tasks.md`, `AGENTS.md` | revisión |
| F57-02 | Ventana de salida pura | RF-64.1/64.2/64.3.1 | `assets/choreography.js` | unit JS |
| F57-03 | Timer visible en el panel | RF-64.1.2/64.2/64.3.2 | `public/dashboard.html` | unit JS + manual |
| F57-04 | Criterio de aceptación manual | RF-64.1.2/64.3 | `assets/acceptance-tests.json` | manual + regresión |
| F57-05 | Diagnóstico latencia puerta | RF-64.4.1 | `design.md` §21.4 | evidencia manual |

---

# Fase 58 — Orden por milisegundos de los eventos de sensor (RF-65)

**Motivo**: OPEN+CLOSED del mismo segundo podían aplicarse invertidos (el sello de Tuya se
truncaba a segundos y la decisión comparaba a segundos), dejando la puerta abierta en el croquis
3–5 s tras un cierre físico. Evidencia: 18 casos históricos `last_close_at == last_open_at` en
un OPEN aplicado tras un CLOSED del mismo segundo.

### TSK-F58-01: Specs y trazabilidad
- **Cambio**: `requirements.md` (RF-65, RF-44.1.4), `design.md` (§2.1, §2.4, §22),
  `contracts.md` (precisión de `occurred_at`), `tasks.md`, `AGENTS.md`.
- **RF**: RF-65.1/65.2/65.3.
- **Test propio**: revisión + regresión.

### TSK-F58-02: Sello del dispositivo con ms
- **Cambio**: `src/Infrastructure/Gateways/Sensor/TuyaSensorIngress.php` — `tsToIso()` conserva
  ms (`Y-m-d\TH:i:s.v\Z`) con fallback a segundos (10 dígitos).
- **RF**: RF-65.1.1.
- **Test propio**: `tests/Unit/TuyaSensorIngressTest.php` (nuevo).

### TSK-F58-03: Decisión y parsers en ms
- **Cambio**: `src/Domain/Presence/SensorEventDecision.php` (`toEpoch` en ms; `fingerprint`
  floors a segundo), `IotSessionService::isoToMysqlUtc()`,
  `PresenceEventRepository::toMysqlUtc()` (aceptan fracción).
- **RF**: RF-65.2.1/65.2.2/65.2.3.
- **Test propio**: casos F58 en `tests/Unit/SensorEventDecisionTest.php` (BLOCK 1) +
  `IotSessionServiceTest.php`.

### TSK-F58-04: Regresión de llegada invertida
- **Cambio**: `bin/run-tests.sh` — caso en `BLOCK 33`: CLOSED `.900` antes de OPEN `.100` del
  mismo segundo → gana CLOSED y el OPEN queda `applied=0/stale`; y el caso ascendente legítimo.
- **RF**: RF-65.3.3.
- **Test propio**: `bash bin/run-tests.sh` 0 failures.

### TSK-F58-05: Verificación de campo
- **Cambio**: ninguno (protocolo en `design.md` §22.5): repetir apertura/cierre rápido y, si
  tardase, revisar `applied`/`discard_reason`/`meta.tuya_t`.
- **RF**: RF-65.3.1.
- **Test propio**: evidencia manual en la corrida de aceptación.

## Tabla resumen F58

| Tarea | Descripción | RF | Archivos | Test |
|-------|-------------|----|----------|------|
| F58-01 | Specs y trazabilidad | RF-65.1/65.2/65.3 | `requirements/design/contracts/tasks.md`, `AGENTS.md` | revisión |
| F58-02 | Sello del dispositivo con ms | RF-65.1.1 | `TuyaSensorIngress.php` | unit PHP |
| F58-03 | Decisión y parsers en ms | RF-65.2 | `SensorEventDecision.php`, `IotSessionService.php`, `PresenceEventRepository.php` | unit PHP |
| F58-04 | Regresión de llegada invertida | RF-65.3.3 | `bin/run-tests.sh` (BLOCK 33) | regresión |
| F58-05 | Verificación de campo | RF-65.3.1 | `design.md` §22.5 | manual |

---

# Fase 59 — Un QR nuevo limpia el ciclo anterior (RF-66)

**Motivo**: tras una salida confirmada queda `cooldown_until` (+20 s) y estado IoT del ciclo
anterior; crear un QR nuevo (panel o real) sin resetear hacía que la coreografía mostrara
`ANTI_REENTRADA` (monigote dentro). Evidencia: `qr-test/create` a las 09:13:58 con cooldown
activo hasta 09:14:08; reproducción pura `cooldown=true → ANTI_REENTRADA (INSIDE)`.

### TSK-F59-01: Specs y trazabilidad
- **Cambio**: `requirements.md` (RF-66), `design.md` §23, `contracts.md` (semántica de
  creación/emisión), `tasks.md`, `AGENTS.md`.
- **RF**: RF-66.1/66.2/66.3.
- **Test propio**: revisión + regresión.

### TSK-F59-02: Limpieza compartida
- **Cambio**: `src/Domain/Rooms/RoomCycleResetterInterface.php` +
  `src/Domain/Rooms/RoomCycleResetter.php` (PDO): cooldown NULL + `iot_sessions` a UNKNOWN/null.
- **RF**: RF-66.1.1, RF-66.1.2, RF-66.3.2.
- **Test propio**: cobertura vía runner (BLOCK 19) + unit del servicio real.

### TSK-F59-03: Panel de pruebas
- **Cambio**: `QrTestController` — `doCreate()` limpia con el resetter; `create()` delega en
  `doCreate()` tras sus guardas; `reset()`/`roomsReset()` reutilizan el resetter.
- **RF**: RF-66.1.3.
- **Test propio**: `BLOCK 19` (create sucio → limpio) + regresión.

### TSK-F59-04: Emisión real
- **Cambio**: `QrIssueService::issue()` limpia antes de `insertReserved` (respetando
  `room_busy`); wiring en `public/index.php`.
- **RF**: RF-66.1.1/66.1.2.
- **Test propio**: `QrIssueServiceTest` (espía del resetter) + bloque de emisión del runner.

### TSK-F59-05: Panel UI
- **Cambio**: `public/dashboard.html` — `createTestQr()` usa `/dashboard-api/qr-test/reset`
  (reset+create) para que ambos botones garanticen el ciclo limpio.
- **RF**: RF-66.2.1.
- **Test propio**: verificación manual + regresión.

## Tabla resumen F59

| Tarea | Descripción | RF | Archivos | Test |
|-------|-------------|----|----------|------|
| F59-01 | Specs y trazabilidad | RF-66.1/66.2/66.3 | `requirements/design/contracts/tasks.md`, `AGENTS.md` | revisión |
| F59-02 | Limpieza compartida | RF-66.1/66.3.2 | `Domain/Rooms/RoomCycleResetter*.php` | runner + unit |
| F59-03 | Panel de pruebas | RF-66.1.3 | `QrTestController.php` | BLOCK 19 |
| F59-04 | Emisión real | RF-66.1 | `QrIssueService.php`, `index.php` | unit + runner |
| F59-05 | Panel UI | RF-66.2.1 | `public/dashboard.html` | manual |

---

# Fase 60 — Tipo AlmacenBebidas, dispositivo CAMERA y pack (RF-67/68)

**Motivo**: base del almacén: tipo de habitación propio, kind `CAMERA` con subtipo de posición
(EXTERIOR/INTERIOR) y ventanas X/M del tipo.

### TSK-F60-01: Specs y trazabilidad
- **Cambio**: `requirements.md` (RF-67/68), `design.md` §24, `contracts.md` Fase 60, `tasks.md`.
- **RF**: RF-67, RF-68.
- **Test propio**: revisión.

### TSK-F60-02: Migración `0116_camera_device_kind.sql`
- **Cambio**: `devices.kind` ENUM + `CAMERA`; `devices.subtype VARCHAR(32) NULL AFTER kind`; índice
  `(kind, subtype)`.
- **RF**: RF-68.1, RF-68.2, RF-68.4.
- **Test propio**: consulta INFORMATION_SCHEMA / runner (BLOCK 44).

### TSK-F60-03: Migración `0117_roomtype_warehouse_windows.sql`
- **Cambio**: `room_types.warehouse_confirm_seconds` (40) y `warehouse_exterior_margin_seconds` (5).
- **RF**: RF-67.2.
- **Test propio**: validación de rangos (unit del RoomTypeService).

### TSK-F60-04: Dominio kind/subtype
- **Cambio**: `Device.php` (`KIND_CAMERA`, `allKinds`), `DeviceService::validateKind`/
  `validateSubtype`, whitelists de `subtype` en `DeviceRepository`/`DeviceService`/`DeviceController`.
- **RF**: RF-68.1/68.2/68.3.
- **Test propio**: `tests/Unit/DeviceCameraSubtypeTest.php`.

### TSK-F60-05: Migración `0120_warehouse_seed.sql`
- **Cambio**: seed tipo `ALMACEN_BEBIDAS` + pack `ALMACEN_BEBIDAS`; helper documentado (opcional)
  para crear la habitación y asignar el pack. Sin RTSP ni secretos.
- **RF**: RF-67.1/67.3/67.4.
- **Test propio**: runner (tipo y pack existen).

## Tabla resumen F60

| Tarea | Descripción | RF | Archivos | Test |
|-------|-------------|----|----------|------|
| F60-01 | Specs y trazabilidad | RF-67/68 | `*.md` | revisión |
| F60-02 | Migración kind+subtype | RF-68.1/68.2/68.4 | `migrations/0116_*.sql` | BLOCK 44 |
| F60-03 | Migración ventanas de tipo | RF-67.2 | `migrations/0117_*.sql` | unit |
| F60-04 | Dominio kind/subtype | RF-68 | `Device.php`, `DeviceService.php`, `DeviceRepository.php` | unit |
| F60-05 | Seed tipo+pack | RF-67 | `migrations/0120_*.sql` | BLOCK 44 |

---

# Fase 61 — Gestión de cámaras y directo con go2rtc (RF-68/72)

**Motivo**: poder dar de alta cámaras (RTSP) y verlas en directo.

### TSK-F61-01: Instalación go2rtc + systemd
- **Cambio**: `bin/install-go2rtc.sh`, `/etc/go2rtc/go2rtc.yaml`,
  `deploy/systemd/cerraduras-go2rtc.service`; `GO2RTC_BASE_URL` en `.env.example`; arranque en
  `start-all.sh`.
- **RF**: RF-72.1.
- **Test propio**: `systemctl is-active cerraduras-go2rtc` + `GET :1984/api`.

### TSK-F61-02: Sincronización de streams
- **Cambio**: `bin/go2rtc-sync.php` (`PUT`/`DELETE /api/streams`), nombres
  `almacen_<room>_<position>`; sin log de URLs RTSP.
- **RF**: RF-72.3.
- **Test propio**: unit (construcción de nombre/opciones) + manual.

### TSK-F61-03: CRUD de cámaras `/almacen-api/cameras`
- **Cambio**: `WarehouseCameraController` (list/create/patch/delete/sync) + rutas en
  `public/index.php`; enmascarado de `rtsp_url` en listados públicos.
- **RF**: RF-68.3, RF-72.2, RF-74.5.
- **Test propio**: BLOCK 44 (crear/listar/editar/borrar).

### TSK-F61-04: Directo en el panel
- **Cambio**: mosaicos EXTERIOR/INTERIOR con `live_url` (go2rtc), carga bajo demanda y destrucción
  al cerrar; indicador de grabación.
- **RF**: RF-72.2/72.4.
- **Test propio**: verificación manual (aceptación).

## Tabla resumen F61

| Tarea | Descripción | RF | Archivos | Test |
|-------|-------------|----|----------|------|
| F61-01 | go2rtc + systemd | RF-72.1 | `bin/install-go2rtc.sh`, unit systemd | manual |
| F61-02 | Sync de streams | RF-72.3 | `bin/go2rtc-sync.php` | unit + manual |
| F61-03 | CRUD cámaras | RF-72.2 | `WarehouseCameraController.php`, `index.php` | BLOCK 44 |
| F61-04 | Directo en panel | RF-72.2/72.4 | `public/almacen.html`, `assets/almacen.js` | manual |

---

# Fase 62 — Permisos rol + excepción por empleado (RF-69)

**Motivo**: conceder/denegar acceso al almacén a un rol o a un empleado concreto.

### TSK-F62-01: Migración `0118_worker_room_overrides.sql`
- **Cambio**: tabla de excepciones con UNIQUE(worker_id, room_type_id).
- **RF**: RF-69.2.
- **Test propio**: BLOCK 44.

### TSK-F62-02: `WarehouseAccessPolicy` (pura) + tests
- **Cambio**: `src/Domain/Workers/WarehouseAccessPolicy.php`; repositorio de overrides.
- **RF**: RF-69.1/69.3/69.6.
- **Test propio**: `tests/Unit/WarehouseAccessPolicyTest.php`.

### TSK-F62-03: Enforcement en la validación de QR
- **Cambio**: `WorkerQrService::validate()` usa la política antes de abrir; `WorkerService`
  delega; registro de visita `DENIED` (engancha con F63).
- **RF**: RF-69.4, RF-70.4.
- **Test propio**: `WorkerTest`/BLOCK 44 (rol allow + override DENY → 403).

### TSK-F62-04: Endpoints y UI de permisos
- **Cambio**: `/almacen-api/access` (GET) + `PUT .../role` + `PUT .../worker`; tabla/buscador en
  `almacen.html`.
- **RF**: RF-69.5, RF-74.5.
- **Test propio**: BLOCK 44 + manual.

## Tabla resumen F62

| Tarea | Descripción | RF | Archivos | Test |
|-------|-------------|----|----------|------|
| F62-01 | Migración overrides | RF-69.2 | `migrations/0118_*.sql` | BLOCK 44 |
| F62-02 | Política pura | RF-69.1/69.3 | `Domain/Workers/WarehouseAccessPolicy.php` | unit |
| F62-03 | Enforcement QR | RF-69.4 | `WorkerQrService.php`, `WorkerService.php` | BLOCK 44 |
| F62-04 | API+UI permisos | RF-69.5 | `WarehouseAccessController.php`, panel | BLOCK 44 |

---

# Fase 63 — Visitas y motor de grabación (RF-70/71)

**Motivo**: decidir cuándo grabar cada cámara y documentar cada visita.

### TSK-F63-01: Migración `0119_warehouse_visits_recordings.sql`
- **Cambio**: `warehouse_visits`, `camera_recordings`, `warehouse_state` (+ índices).
- **RF**: RF-70.1/70.2, RF-73.1 (soporte).
- **Test propio**: BLOCK 44.

### TSK-F63-02: `WarehouseRecordingDecision` (pura) + tests
- **Cambio**: clase de decisión con la tabla de §27.2; acciones tipadas.
- **RF**: RF-71.1–71.8.
- **Test propio**: `tests/Unit/WarehouseRecordingDecisionTest.php` (casos A–D + límites).

### TSK-F63-03: `WarehouseRecordingService` + enganches
- **Cambio**: servicio transaccional (`FOR UPDATE` sobre `warehouse_state`); enganches en
  `WorkerQrService` (`QR_OK`) e `IotSessionService` post-commit (door/presence); filtra por tipo
  `ALMACEN_BEBIDAS`.
- **RF**: RF-70.4/70.5, RF-71.1–71.8.
- **Test propio**: `IotSessionServiceTest` (no-almacén intacto) + BLOCK 44 (NO_SHOW).

### TSK-F63-04: Endpoints de visitas
- **Cambio**: `/almacen-api/visits` y `/visits/{id}` con filtros y grabaciones.
- **RF**: RF-70.3, RF-74.5/74.6.
- **Test propio**: BLOCK 44.

## Tabla resumen F63

| Tarea | Descripción | RF | Archivos | Test |
|-------|-------------|----|----------|------|
| F63-01 | Migración visits/recordings/state | RF-70.1/70.2 | `migrations/0119_*.sql` | BLOCK 44 |
| F63-02 | Motor puro | RF-71 | `Domain/Warehouse/WarehouseRecordingDecision.php` | unit |
| F63-03 | Servicio + hooks | RF-70/71 | `WarehouseRecordingService.php`, `WorkerQrService.php`, `IotSessionService.php` | unit + BLOCK 44 |
| F63-04 | Endpoints visitas | RF-70.3 | `WarehouseVisitController.php` | BLOCK 44 |

---

# Fase 64 — Recorder daemon y retención (RF-73)

**Motivo**: materializar el vídeo con ffmpeg y no llenar el disco en pruebas.

### TSK-F64-01: Daemon `warehouse-recorder`
- **Cambio**: `bin/warehouse-recorder.php` + `deploy/systemd/cerraduras-warehouse-recorder.service`;
  gestión en `start-all.sh`/`stop-all.sh`; reconciliación al arranque.
- **RF**: RF-73.1/73.2.
- **Test propio**: manual + `system-status` incluye el servicio.

### TSK-F64-02: Ciclo ffmpeg
- **Cambio**: `PENDING→RECORDING→SAVED|DISCARDED|FAILED`, `.tmp`+rename, poster JPEG, señales de
  parada/descarte.
- **RF**: RF-73.1/73.2.
- **Test propio**: con fichero/stream de prueba local (sin cámara real) en BLOCK 44.

### TSK-F64-03: Retención configurable
- **Cambio**: `bin/warehouse-retention.php`; `system_settings.warehouse.retention_days` (1 pruebas;
  0 = sin borrado).
- **RF**: RF-73.3.
- **Test propio**: unit/runner con ficheros viejos simulados.

### TSK-F64-04: Servido de clips
- **Cambio**: `/almacen-api/recordings/{id}/video|poster` con `Range`/`ETag`/anti-traversal.
- **RF**: RF-73.4.
- **Test propio**: BLOCK 44 (Range sobre MP4 de prueba).

## Tabla resumen F64

| Tarea | Descripción | RF | Archivos | Test |
|-------|-------------|----|----------|------|
| F64-01 | Daemon recorder | RF-73.1/73.2 | `bin/warehouse-recorder.php`, unit systemd | manual |
| F64-02 | Ciclo ffmpeg | RF-73.1/73.2 | `bin/warehouse-recorder.php` | BLOCK 44 |
| F64-03 | Retención | RF-73.3 | `bin/warehouse-retention.php` | unit |
| F64-04 | Servido clips | RF-73.4 | `WarehouseRecordingController.php` | BLOCK 44 |

---

# Fase 65 — Panel `/almacen` (RF-74/75)

**Motivo**: una sola vista para ver y controlar el almacén.

### TSK-F65-01: Ruta + página + assets
- **Cambio**: `GET /almacen`, `public/almacen.html`, `public/assets/almacen.js` (una vista,
  responsiva, CSS embebido).
- **RF**: RF-74.1/74.4.
- **Test propio**: BLOCK 44 (`GET /almacen` 200 text/html).

### TSK-F65-02: Estado + SSE
- **Cambio**: `/almacen-api/state` + `AlmacenEventStreamController` (bypass middleware, ping 5 s,
  `max_lifetime` 60 s); reconexión/fallback en el cliente.
- **RF**: RF-74.2/74.3.
- **Test propio**: manual (SSE) + BLOCK 44 (`state`).

### TSK-F65-03: Visitas y reproducción
- **Cambio**: listado con filtros y reproducción conjunta entrada/salida (2×2) con `<video>`,
  poster y descarga.
- **RF**: RF-70.3, RF-74.6.
- **Test propio**: manual.

### TSK-F65-04: Permisos y controles
- **Cambio**: UI de permisos (rol/empleado) + abrir puerta + sincronizar cámaras + salud.
- **RF**: RF-69.5, RF-74.5, RF-75.3/75.8/75.9.
- **Test propio**: manual + BLOCK 44 (endpoints).

### TSK-F65-05: Backlog RF-75 (deseables)
- **Cambio**: registrar como backlog las mejoras (métricas, export CSV, snapshots, multi-almacén,
  inventario/temperatura/audio). Endpoints concretos se congelarán al implementarlas.
- **RF**: RF-75.
- **Test propio**: n/a (documentado).

## Tabla resumen F65

| Tarea | Descripción | RF | Archivos | Test |
|-------|-------------|----|----------|------|
| F65-01 | Página /almacen | RF-74.1/74.4 | `public/almacen.html`, `assets/almacen.js`, `index.php` | BLOCK 44 |
| F65-02 | Estado + SSE | RF-74.2/74.3 | `AlmacenEventStreamController.php` | BLOCK 44 + manual |
| F65-03 | Visitas UI | RF-70.3/74.6 | `assets/almacen.js` | manual |
| F65-04 | Permisos/controles UI | RF-69.5 | `assets/almacen.js`, `Warehouse*Controller.php` | BLOCK 44 |
| F65-05 | Backlog RF-75 | RF-75 | `docs`/`tasks.md` | n/a |

---

# Fase 66 — Pruebas, trazabilidad y cierre (RF-76)

**Motivo**: verificación acumulada y no regresión.

### TSK-F66-01: Unit tests puros
- **Cambio**: `WarehouseRecordingDecisionTest.php`, `WarehouseAccessPolicyTest.php`,
  `DeviceCameraSubtypeTest.php`.
- **RF**: RF-76.2.
- **Test propio**: BLOQUE 1 del runner (autodescubierto).

### TSK-F66-02: BLOCK 44 del runner
- **Cambio**: `api/bin/run-tests.sh` nuevo bloque F60–F66 (tipo, cámaras, permisos, visita NO_SHOW,
  `/almacen` 200, `state`, `visits`, `Range`).
- **RF**: RF-76.2.
- **Test propio**: el propio bloque.

### TSK-F66-03: AGENTS.md, arranque y regresión
- **Cambio**: `AGENTS.md` (tabla de fases + F60–F66), `start-all.sh` (go2rtc + recorder),
  `stop-all.sh`; ejecutar regresión completa.
- **RF**: RF-76.3.
- **Test propio**: `bash bin/run-tests.sh` → 0 failures.

## Tabla resumen F66

| Tarea | Descripción | RF | Archivos | Test |
|-------|-------------|----|----------|------|
| F66-01 | Unit tests | RF-76.2 | `tests/Unit/*` | BLOQUE 1 |
| F66-02 | BLOCK 44 | RF-76.2 | `bin/run-tests.sh` | runner |
| F66-03 | Docs + arranque + regresión | RF-76.3 | `AGENTS.md`, `start-all.sh`, `stop-all.sh` | regresión |

---

## Orden de ejecución y dependencias (F60–F66)

```
F60 (tipo+cámara+pack)
  └─> F61 (cámaras + go2rtc)
  └─> F62 (permisos)  ─────────────┐
  └─> F63 (visitas + motor) ───────┤
  └─> F64 (recorder + retención) ──┴─> F65 (panel /almacen) ─> F66 (tests + cierre)
```
- F60 es prerequisito de todas.
- F62 y F63 pueden solaparse; F64 depende de F63 (necesita `camera_recordings`).
- F65 consume todo lo anterior; F66 cierra con la regresión completa.
- **Aceptación manual** (no en runner): directo go2rtc con cámara real, grabación real por los
  casos A–D, retención de 1 día y reproducción con seek.

---

# Fase 67 — Croquis en vivo del almacén (RF-77)

**Dependencias**: F65 (panel `/almacen`, `/almacen-api/state` y SSE) y F56 (`Choreography.resolveDoorOpen`).

## TSK-F67-01: Specs Fase 67
- **Cambio**: `requirements.md` (RF-77), `design.md` (§31), `contracts.md` (Fase 67),
  `tasks.md` (esta fase).
- **RF**: RF-77.
- **Test propio**: revisión de trazabilidad (sin ejecución).

## TSK-F67-02: Bloque `live` en el backend
- **Cambio**: `api/src/Http/Controllers/WarehouseStateController.php` — añadir `live` (lectura de
  `iot_sessions` + `devices.meta_json.switch_state`); `api/src/Http/Controllers/AlmacenEventStreamController.php`
  — incluir `live` en el fingerprint.
- **RF**: RF-77.3, RF-77.4.
- **Test propio**: BLOCK 45 (`/almacen-api/state` contiene `"live"`).

## TSK-F67-03: Lógica pura del croquis
- **Cambio**: nuevo `api/public/assets/croquis-logic.js` (UMD, `deriveCroquis`) reutilizando
  `Choreography.resolveDoorOpen`.
- **RF**: RF-77.1, RF-77.2, RF-77.5.
- **Test propio**: `api/tests/Unit/croquis-logic.test.js` (Node) → `0 failed`.

## TSK-F67-04: Markup + CSS del croquis
- **Cambio**: `api/public/almacen.html` — `.directo-wrap`, `.cam.croquis-card`, SVG, chips,
  `#croquis-meta`, CSS nuevo y `<script src="/assets/croquis-logic.js">`.
- **RF**: RF-77.1, RF-77.2, RF-77.6.
- **Test propio**: BLOCK 45 (HTML contiene `croquis-svg`).

## TSK-F67-05: Render en vivo
- **Cambio**: `api/public/assets/almacen.js` — `renderCroquis()`, `renderCroquisMeta()`, helpers,
  enganche en `applyState()` y segundero de `#croquis-meta`.
- **RF**: RF-77.1, RF-77.2, RF-77.4.
- **Test propio**: `croquis-logic.test.js` + verificación manual del panel.

## TSK-F67-06: Runner, AGENTS.md y regresión
- **Cambio**: `api/bin/run-tests.sh` (BLOCK 45), `AGENTS.md` (fila F67);
  ejecutar `bash bin/run-tests.sh`.
- **RF**: RF-77.7.
- **Test propio**: regresión completa → `0 failures`.

## Tabla resumen F67

| Tarea | Descripción | RF | Archivos | Test |
|-------|-------------|----|----------|------|
| F67-01 | Specs | RF-77 | `.specs/specs/*` | revisión |
| F67-02 | Bloque `live` backend | RF-77.3/4 | `WarehouseStateController.php`, `AlmacenEventStreamController.php` | BLOCK 45 |
| F67-03 | Lógica pura | RF-77.1/2/5 | `assets/croquis-logic.js` | unit Node |
| F67-04 | Markup + CSS | RF-77.1/2/6 | `almacen.html` | BLOCK 45 |
| F67-05 | Render en vivo | RF-77.1/2/4 | `assets/almacen.js` | unit + manual |
| F67-06 | Runner + docs + regresión | RF-77.7 | `bin/run-tests.sh`, `AGENTS.md` | regresión |

## Orden de ejecución y dependencias (F67)

```
F67-01 (specs)
  └─> F67-02 (backend live)
  └─> F67-03 (lógica pura)
        └─> F67-04 (markup/CSS) ─> F67-05 (render) ─> F67-06 (runner + regresión)
```

---

# Fase 68 — Reproducción de visitas (RF-78)

**Dependencias**: F63 (visitas + grabaciones), F64 (vídeo/retención), F65 (panel/`visits/{id}`) y
F67 (croquis).

## TSK-F68-01: Specs Fase 68
- **Cambio**: `requirements.md` (RF-78), `design.md` (§32), `contracts.md` (Fase 68),
  `tasks.md` (esta fase).
- **RF**: RF-78.
- **Test propio**: revisión de trazabilidad.

## TSK-F68-02: `requested_at` en el contrato de visitas
- **Cambio**: `api/src/Http/Controllers/WarehouseVisitController.php` — seleccionar y exponer
  `requested_at` en `recordings[]` (aditivo).
- **RF**: RF-78.7.
- **Test propio**: BLOCK 46 (`GET /almacen-api/visits/{id}` incluye `requested_at`).

## TSK-F68-03: Lógica pura de la línea de tiempo
- **Cambio**: nuevo `api/public/assets/visit-playback.js` (`buildVisitTimeline`, `frameAt`).
- **RF**: RF-78.2, RF-78.3, RF-78.6, RF-78.8.
- **Test propio**: `api/tests/Unit/visit-playback.test.js` (Node) → `0 failed`.

## TSK-F68-04: Banda de tiempo, lector QR y CSS
- **Cambio**: `api/public/almacen.html` — `.directo-right`, banda `#play-timeline`, marcadores,
  lector QR en el SVG, clases de fase del monigote y CSS; carga `visit-playback.js`.
- **RF**: RF-78.2, RF-78.3.
- **Test propio**: BLOCK 46 (estáticos de markup).

## TSK-F68-05: Controlador de reproducción y sincronía
- **Cambio**: `api/public/assets/almacen.js` — `applyCroquisView`, `playVisit`, `togglePlay`,
  `seek`, `setSpeed`, `exitPlayback`, `renderCamerasReplay`, botón ▶ por visita.
- **RF**: RF-78.1, RF-78.4, RF-78.5, RF-78.6.
- **Test propio**: `visit-playback.test.js` + verificación manual.

## TSK-F68-06: Runner, AGENTS.md y regresión
- **Cambio**: `api/bin/run-tests.sh` (BLOCK 46) + `AGENTS.md`; ejecutar `bash bin/run-tests.sh`.
- **RF**: RF-78.9.
- **Test propio**: regresión completa → `0 failures`.

## Tabla resumen F68

| Tarea | Descripción | RF | Archivos | Test |
|-------|-------------|----|----------|------|
| F68-01 | Specs | RF-78 | `.specs/specs/*` | revisión |
| F68-02 | `requested_at` | RF-78.7 | `WarehouseVisitController.php` | BLOCK 46 |
| F68-03 | Lógica pura | RF-78.2/3/6/8 | `assets/visit-playback.js` | unit Node |
| F68-04 | Banda + QR + CSS | RF-78.2/3 | `almacen.html` | BLOCK 46 |
| F68-05 | Reproducción + sync | RF-78.1/4/5/6 | `assets/almacen.js` | unit + manual |
| F68-06 | Runner + docs + regresión | RF-78.9 | `bin/run-tests.sh`, `AGENTS.md` | regresión |

## Orden de ejecución y dependencias (F68)

```
F68-01 (specs)
  └─> F68-02 (requested_at)
  └─> F68-03 (lógica pura)
        └─> F68-04 (banda/CSS) ─> F68-05 (reproducción) ─> F68-06 (runner + regresión)
```

---

# Fase 69 — Vista en directo y encendido de cámaras (RF-79)

**Dependencias**: F61 (gestión de cámaras/go2rtc), F67 (croquis en vivo) y F68 (reproducción).

## TSK-F69-01: Specs Fase 69
- **Cambio**: `requirements.md` (RF-79), `design.md` (§33), `contracts.md`, `tasks.md`.
- **RF**: RF-79.
- **Test propio**: revisión de trazabilidad.

## TSK-F69-02: Botón "Ver en directo"
- **Cambio**: `api/public/almacen.html` — `#btn-live` en la cabecera con punto de estado y estilos.
- **RF**: RF-79.1, RF-79.4.
- **Test propio**: BLOCK 47 (estáticos).

## TSK-F69-03: `goLive` + `ensureCamerasLive`
- **Cambio**: `api/public/assets/almacen.js` — `goLive()` (cierra reproducción, enciende cámaras
  apagadas, sincroniza, re-renderiza) y enganche con "Volver en vivo".
- **RF**: RF-79.2, RF-79.3, RF-79.5.
- **Test propio**: BLOCK 47 + verificación manual.

## TSK-F69-04: Encender cámaras reales
- **Cambio**: operación de datos (`enabled=true` + `sync`) sobre las cámaras del almacén.
- **RF**: RF-79.3.
- **Test propio**: `GET /almacen-api/state` con `enabled=true` y `live_url`.

## TSK-F69-05: Runner, AGENTS.md y regresión
- **Cambio**: `api/bin/run-tests.sh` (BLOCK 47) + `AGENTS.md`; ejecutar `bash bin/run-tests.sh`.
- **RF**: RF-79.6.
- **Test propio**: regresión completa → `0 failures`.

## Tabla resumen F69

| Tarea | Descripción | RF | Archivos | Test |
|-------|-------------|----|----------|------|
| F69-01 | Specs | RF-79 | `.specs/specs/*` | revisión |
| F69-02 | Botón cabecera | RF-79.1/4 | `almacen.html` | BLOCK 47 |
| F69-03 | `goLive`/encendido | RF-79.2/3/5 | `assets/almacen.js` | BLOCK 47 |
| F69-04 | Encender cámaras | RF-79.3 | datos runtime | `state` |
| F69-05 | Runner + docs + regresión | RF-79.6 | `bin/run-tests.sh`, `AGENTS.md` | regresión |

## Orden de ejecución (F69)

```
F69-01 (specs) ─> F69-02 (botón) ─> F69-03 (goLive) ─> F69-04 (encender) ─> F69-05 (runner + regresión)
```

---

# Fase 70 — Directo de cámaras por MJPEG (RF-80)

**Dependencias**: F61 (cámaras/go2rtc), F69 (encendido de cámaras). Referencia:
`reconocimientoFacial/live/mjpeg-stream.js` + Apache `ProxyPass`.

## TSK-F70-01: Specs Fase 70
- **Cambio**: `requirements.md` (RF-80), `design.md` (§34), `contracts.md`, `tasks.md`.
- **RF**: RF-80.
- **Test propio**: revisión de trazabilidad.

## TSK-F70-02: Servidor MJPEG
- **Cambio**: nuevo `api/bin/cameras-live.js` (ffmpeg por cámara, fan-out, `/live`, `/status`,
  parser puro `extractJpegFrames`).
- **RF**: RF-80.1, RF-80.6.
- **Test propio**: `api/tests/Unit/cameras-live.test.js` (Node) → 0 failed.

## TSK-F70-03: systemd + arranque
- **Cambio**: `docs/systemd/cerraduras-cameras-live.service`; `start-all.sh`/`stop-all.sh`;
  `.env.example` (variables `CAMERAS_LIVE_*`).
- **RF**: RF-80.3.
- **Test propio**: BLOCK 48 (unit/systemd estáticos).

## TSK-F70-04: `mjpeg_url` en la API
- **Cambio**: `WarehouseStateController` y `WarehouseCameraController` (aditivo).
- **RF**: RF-80.4.
- **Test propio**: BLOCK 48 (HTTP `state.cameras[].mjpeg_url`).

## TSK-F70-05: Panel con `<img>`
- **Cambio**: `api/public/assets/almacen.js` y `almacen.html` (CSS `.mjpeg`, "sin señal",
  fallback iframe).
- **RF**: RF-80.5.
- **Test propio**: BLOCK 48 (estáticos) + verificación manual.

## TSK-F70-06: Apache proxy
- **Cambio**: vhost `cerraduras.josue.ink` (443): `ProxyPass /almacen-live → 127.0.0.1:8086/live`.
- **RF**: RF-80.2.
- **Test propio**: `curl -I https://cerraduras.josue.ink/almacen-live` sensible a
  `multipart/x-mixed-replace` (SKIP si no responde).

## TSK-F70-07: Runner, AGENTS.md y regresión
- **Cambio**: `api/bin/run-tests.sh` (BLOCK 48) + `AGENTS.md`; ejecutar `bash bin/run-tests.sh`.
- **RF**: RF-80.7.
- **Test propio**: regresión completa → `0 failures`.

## Tabla resumen F70

| Tarea | Descripción | RF | Archivos | Test |
|-------|-------------|----|----------|------|
| F70-01 | Specs | RF-80 | `.specs/specs/*` | revisión |
| F70-02 | Servidor MJPEG | RF-80.1/6 | `bin/cameras-live.js` | unit Node |
| F70-03 | systemd + arranque | RF-80.3 | `docs/systemd/*`, `start-all.sh`, `stop-all.sh`, `.env.example` | BLOCK 48 |
| F70-04 | `mjpeg_url` | RF-80.4 | `WarehouseStateController.php`, `WarehouseCameraController.php` | BLOCK 48 |
| F70-05 | Panel `<img>` | RF-80.5 | `almacen.html`, `assets/almacen.js` | BLOCK 48 |
| F70-06 | Apache proxy | RF-80.2 | vhost Apache | curl |
| F70-07 | Runner + docs + regresión | RF-80.7 | `bin/run-tests.sh`, `AGENTS.md` | regresión |

## Orden de ejecución (F70)

```
F70-01 (specs) ─> F70-02 (servidor MJPEG) ─> F70-03 (systemd) ─> F70-04 (mjpeg_url)
   ─> F70-05 (panel) ─> F70-06 (Apache) ─> F70-07 (runner + regresión)
```

---

# Fase 71: Presencia real del almacén y frescura de señal (RF-81 / RF-82)

## TSK-F71-01: Specs
- **Cambio**: `requirements.md` (RF-81/82), `design.md` (§35), `contracts.md` (Fase 71),
  `tasks.md`; fila en `AGENTS.md`.
- **RF**: RF-81, RF-82.
- **Test propio**: revisión de trazabilidad.

## TSK-F71-02: Credibilidad de presencia del almacén
- **Cambio**: `api/src/Domain/Presence/IotSessionService.php` — `presenceContext()` con
  cortocircuito `isWarehouseRoom()`; omitir `anomalyService` en salas de almacén.
- **RF**: RF-81.1, RF-81.2, RF-81.3, RF-81.4, RF-81.5.
- **Test propio**: casos F71 en `IotSessionServiceTest.php`.

## TSK-F71-03: Visita por presencia confirmada
- **Cambio**: `api/src/Domain/Warehouse/WarehouseRecordingDecision.php` — `IDLE + EV_PRESENT`
  añade `A_CONFIRM_ENTRY`.
- **RF**: RF-81.3.
- **Test propio**: `WarehouseRecordingDecisionTest.php` (test C).

## TSK-F71-04: Frescura en `live`
- **Cambio**: `api/src/Http/Controllers/WarehouseStateController.php` —
  `door_age_seconds`/`presence_age_seconds` aditivos.
- **RF**: RF-82.1, RF-82.3.
- **Test propio**: BLOCK 49 (HTTP `state.live.*_age_seconds`).

## TSK-F71-05: Croquis con "sin datos"
- **Cambio**: `api/public/assets/croquis-logic.js` (`DOOR_STALE_SECONDS`, chip "PUERTA SIN
  DATOS"); `api/public/assets/almacen.js` si procede.
- **RF**: RF-82.2.
- **Test propio**: `croquis-logic.test.js`.

## TSK-F71-06: Runner, AGENTS.md y regresión
- **Cambio**: `api/bin/run-tests.sh` (BLOCK 49) + `AGENTS.md`; ejecutar `bash bin/run-tests.sh`.
- **RF**: RF-82.4.
- **Test propio**: regresión completa → `0 failures`.

## Tabla resumen F71

| Tarea | Descripción | RF | Archivos | Test |
|-------|-------------|----|----------|------|
| F71-01 | Specs | RF-81/82 | `.specs/specs/*`, `AGENTS.md` | revisión |
| F71-02 | Presencia almacén | RF-81 | `IotSessionService.php` | unit PHP |
| F71-03 | Visita por presencia | RF-81.3 | `WarehouseRecordingDecision.php` | unit PHP |
| F71-04 | Frescura `live` | RF-82.1/3 | `WarehouseStateController.php` | BLOCK 49 |
| F71-05 | Croquis sin datos | RF-82.2 | `croquis-logic.js`, `almacen.js` | unit Node |
| F71-06 | Runner + regresión | RF-82.4 | `bin/run-tests.sh`, `AGENTS.md` | regresión |

## Orden de ejecución (F71)

```
F71-01 (specs) ─> F71-02 (presencia almacén) ─> F71-03 (visita) ─> F71-04 (frescura)
   ─> F71-05 (croquis) ─> F71-06 (runner + regresión)
```

---

# Fase 72: Estado persistente de la puerta y resync periódico (RF-83 / RF-84)

## TSK-F72-01: Specs
- **Cambio**: `requirements.md` (RF-83/84, deroga RF-82.2), `design.md` (§36), `contracts.md`,
  `tasks.md`; fila en `AGENTS.md`.
- **RF**: RF-83, RF-84.
- **Test propio**: revisión de trazabilidad.

## TSK-F72-02: Croquis con estado persistente
- **Cambio**: `api/public/assets/croquis-logic.js` — quitar `DOOR_STALE_SECONDS`/`doorStale` del
  chip y la descripción; la puerta muestra el último estado conocido.
- **RF**: RF-83.1, RF-83.2, RF-83.3.
- **Test propio**: `tests/Unit/croquis-logic.test.js` (casos F72).

## TSK-F72-03: Ajuste de tests F71
- **Cambio**: `tests/Unit/croquis-logic.test.js` — los casos de "puerta vieja" pasan a exigir
  `PUERTA CERRADA`/`PUERTA ABIERTA`; solo sin estado → `PUERTA SIN DATOS`.
- **RF**: RF-83.
- **Test propio**: mismo archivo.

## TSK-F72-04: Resync periódico del sensor de puerta
- **Cambio**: `api/bin/tuya-pulsar-consumer/index.js` — `DOOR_RESYNC_MS`,
  `periodicDoorResyncDue()`, `lastDoorResyncAt`, `resyncKnownDevices(reason, kinds)`, chequeo de
  presupuesto y timer en `start()`.
- **RF**: RF-84.1, RF-84.2, RF-84.3, RF-84.4.
- **Test propio**: `tests/Unit/tuya-pulsar-consumer.test.js`.

## TSK-F72-05: Runner, `.env.example`, AGENTS.md y regresión
- **Cambio**: `api/bin/run-tests.sh` (**BLOCK 50**; ajustar **BLOCK 49**), `.env.example`
  (`CONSUMER_DOOR_RESYNC_MS`), `AGENTS.md`.
- **RF**: RF-84.5.
- **Test propio**: regresión completa → `0 failures`.

## Tabla resumen F72

| Tarea | Descripción | RF | Archivos | Test |
|-------|-------------|----|----------|------|
| F72-01 | Specs | RF-83/84 | `.specs/specs/*`, `AGENTS.md` | revisión |
| F72-02 | Croquis persistente | RF-83 | `croquis-logic.js` | unit Node |
| F72-03 | Ajuste tests F71 | RF-83 | `croquis-logic.test.js` | unit Node |
| F72-04 | Resync periódico | RF-84 | `tuya-pulsar-consumer/index.js` | unit Node |
| F72-05 | Runner + env + regresión | RF-84.5 | `bin/run-tests.sh`, `.env.example`, `AGENTS.md` | regresión |

## Orden de ejecución (F72)

```
F72-01 (specs) ─> F72-02 (croquis) ─> F72-03 (tests) ─> F72-04 (resync)
   ─> F72-05 (runner + env + regresión)
```

---

# Fase 73: Tope de grabación en pruebas y purga del almacén (RF-85 / RF-86)

## TSK-F73-01: Specs
- **Cambio**: `requirements.md` (RF-85/86), `design.md` (§37), `contracts.md`, `tasks.md`,
  `AGENTS.md`.
- **RF**: RF-85, RF-86.
- **Test propio**: revisión.

## TSK-F73-02: Tope de duración en el servicio y el recorder
- **Cambio**: `api/src/Domain/Warehouse/WarehouseRecordingService.php` —
  `enforceRecordingCap(int $maxSeconds): int`; `api/bin/warehouse-recorder.php` — leer
  `WAREHOUSE_MAX_RECORDING_SECONDS` y llamar al tope en cada tick.
- **RF**: RF-85.1, RF-85.2, RF-85.3, RF-85.4.
- **Test propio**: `tests/Unit/WarehouseRecordingCapTest.php`.

## TSK-F73-03: Herramienta de purga
- **Cambio**: `api/bin/warehouse-purge.php` (nuevo).
- **RF**: RF-86.1, RF-86.2.
- **Test propio**: BLOCK 51 (purga de sala de prueba y restauración).

## TSK-F73-04: Runner, `.env.example` y regresión
- **Cambio**: `api/bin/run-tests.sh` (**BLOCK 51**), `api/.env.example`
  (`WAREHOUSE_MAX_RECORDING_SECONDS`), `docs/ops.md`.
- **RF**: RF-85.3, RF-86.3.
- **Test propio**: regresión completa → `0 failures`.

---

## Tabla resumen F73

| Tarea | Descripción | RF | Archivos | Test |
|-------|-------------|----|----------|------|
| F73-01 | Specs | RF-85/86 | `.specs/specs/*`, `AGENTS.md` | revisión |
| F73-02 | Tope de duración | RF-85 | `WarehouseRecordingService.php`, `warehouse-recorder.php` | unit PHP |
| F73-03 | Purga total | RF-86 | `warehouse-purge.php` | BLOCK 51 |
| F73-04 | Runner + env + docs | RF-85.3, RF-86.3 | `run-tests.sh`, `.env.example`, `docs/ops.md` | regresión |

## Orden de ejecución (F73)

```
F73-01 (specs) ─> F73-02 (tope) ─> F73-03 (purga) ─> F73-04 (runner + env + regresión)
```

---

# Fase 74: Sin polling periódico que consuma cuota Tuya (RF-87)

> **Nota**: F72-04 (resync periódico) queda **derogado** por RF-87 (F74). El croquis persistente
> (F72-02/F72-03) se mantiene.

## TSK-F74-01: Specs
- **Cambio**: `requirements.md` (RF-87; RF-84 derogado), `design.md` (§38), `contracts.md`,
  `tasks.md`; `AGENTS.md`.
- **RF**: RF-87.
- **Test propio**: revisión de trazabilidad.

## TSK-F74-02: Eliminar el resync periódico del consumer
- **Cambio**: `api/bin/tuya-pulsar-consumer/index.js` — revertir a su forma pre-F72 (sin
  `DOOR_RESYNC_MS`, sin helpers de cuota, sin `loadDoorResyncDevices`, sin `resyncDoorPeriodic`,
  sin `setInterval` de sondeo). Se mantiene el resync puntual `ws-open`.
- **RF**: RF-87.1, RF-87.2, RF-87.3.
- **Test propio**: `tests/Unit/tuya-pulsar-consumer.test.js` (ausencia de helpers periódicos).

## TSK-F74-03: Limpiar `.env.example` y tests
- **Cambio**: `api/.env.example` (sin `CONSUMER_DOOR_RESYNC_MS`),
  `tests/Unit/tuya-pulsar-consumer.test.js` (quitar casos F72 de periodicidad).
- **RF**: RF-87.1.
- **Test propio**: unit Node.

## TSK-F74-04: Runner y regresión
- **Cambio**: `api/bin/run-tests.sh` (**BLOCK 50** verifica la ausencia de resync periódico) +
  `AGENTS.md`; ejecutar `bash bin/run-tests.sh`.
- **RF**: RF-87.4.
- **Test propio**: regresión completa → `0 failures`.

# Fase 74: Sin polling periódico que consuma cuota Tuya (RF-87)

## Tabla resumen F74

| Tarea | Descripción | RF | Archivos | Test |
|-------|-------------|----|----------|------|
| F74-01 | Specs | RF-87 | `.specs/specs/*`, `AGENTS.md` | revisión |
| F74-02 | Quitar resync periódico | RF-87.1/2/3 | `tuya-pulsar-consumer/index.js` | unit Node |
| F74-03 | Limpiar env y tests | RF-87.1 | `.env.example`, `tuya-pulsar-consumer.test.js` | unit Node |
| F74-04 | Runner + regresión | RF-87.4 | `bin/run-tests.sh`, `AGENTS.md` | regresión |

## Orden de ejecución (F74)

```
F74-01 (specs) ─> F74-02 (consumer) ─> F74-03 (env/tests) ─> F74-04 (runner + regresión)
```

---

# Fase 75: Refresco de sensores del almacén bajo demanda (RF-88/89/90)

## TSK-F75-01: Specs
- **Cambio**: `requirements.md` (RF-88/89/90), `design.md` (§39), `contracts.md`, `tasks.md`,
  `AGENTS.md`.
- **RF**: RF-88, RF-89, RF-90.
- **Test propio**: revisión de trazabilidad.

## TSK-F75-02: Presencia estricta del almacén
- **Cambio**: `api/src/Domain/Presence/SensorEventDecision.php` (flag `warehousePresence`),
  `api/src/Domain/Presence/IotSessionService.php` (pasar el flag).
- **RF**: RF-89.
- **Test propio**: `SensorEventDecisionTest.php`, `IotSessionServiceTest.php`.

## TSK-F75-03: Endpoint + panel de refresco
- **Cambio**: `api/public/index.php` (ruta `POST /almacen-api/sensors/refresh` con cooldown),
  `api/public/assets/almacen.js` (una lectura al abrir + botón), `api/public/almacen.html` (botón),
  `api/.env.example` (`ALMACEN_SENSOR_REFRESH_COOLDOWN_SECONDS`).
- **RF**: RF-88.
- **Test propio**: BLOCK 52 (estáticos + HTTP guardado).

## TSK-F75-04: La regresión no borra el estado del almacén
- **Cambio**: `api/bin/run-tests.sh` `_e2e_normalize`/`_e2e_cleanup` (snapshot/restore de
  `iot_sessions`, no borrar `presence_events` en salas de almacén) + **BLOCK 52**.
- **RF**: RF-90.
- **Test propio**: regresión completa → `0 failures`.

## Tabla resumen F75

| Tarea | Descripción | RF | Archivos | Test |
|-------|-------------|----|----------|------|
| F75-01 | Specs | RF-88/89/90 | `.specs/specs/*`, `AGENTS.md` | revisión |
| F75-02 | Presencia estricta | RF-89 | `SensorEventDecision.php`, `IotSessionService.php` | unit PHP |
| F75-03 | Refresh + panel | RF-88 | `index.php`, `almacen.js`, `almacen.html`, `.env.example` | BLOCK 52 |
| F75-04 | Runner sin wipe | RF-90 | `run-tests.sh` | regresión |

## Orden de ejecución (F75)

```
F75-01 (specs) ─> F75-02 (presencia) ─> F75-03 (refresh+panel) ─> F75-04 (runner + regresión)
```

---

# Fase 76: Presencia del almacén anclada al ciclo de puerta (RF-91 / RF-92)

## TSK-F76-01: Specs
- **Cambio**: `requirements.md` (RF-91/92), `design.md` (§40), `contracts.md`, `tasks.md`,
  `AGENTS.md`.
- **RF**: RF-91, RF-92.
- **Test propio**: revisión.

## TSK-F76-02: Contexto de puerta en la presencia del almacén
- **Cambio**: `api/src/Domain/Presence/IotSessionService.php`
  (`warehousePresenceContext()`, retirar cortocircuito F71); `SensorEventDecision.php` (exigir
  contexto en la rama `warehousePresence`).
- **RF**: RF-91.
- **Test propio**: `SensorEventDecisionTest.php`, `IotSessionServiceTest.php`.

## TSK-F76-03: Ancla sin auto-justificación
- **Cambio**: `WarehouseRecordingServiceInterface::activeEnteredVisitAt()` +
  `WarehouseRecordingService` (solo `DOOR`/`QR`).
- **RF**: RF-92.
- **Test propio**: casos F76 (no requiere fakes nuevos; se cubre con el flujo de puerta).

## TSK-F76-04: Runner y regresión
- **Cambio**: `api/bin/run-tests.sh` (**BLOCK 52** con marcadores F76) + `AGENTS.md`; ejecutar
  `bash bin/run-tests.sh`.
- **RF**: RF-92.2.
- **Test propio**: regresión completa → `0 failures`.

## Tabla resumen F76

| Tarea | Descripción | RF | Archivos | Test |
|-------|-------------|----|----------|------|
| F76-01 | Specs | RF-91/92 | `.specs/specs/*`, `AGENTS.md` | revisión |
| F76-02 | Contexto de puerta | RF-91 | `IotSessionService.php`, `SensorEventDecision.php` | unit PHP |
| F76-03 | Ancla sin auto-justificación | RF-92 | `WarehouseRecordingService*.php` | unit PHP |
| F76-04 | Runner + regresión | RF-92.2 | `run-tests.sh`, `AGENTS.md` | regresión |

## Orden de ejecución (F76)

```
F76-01 (specs) ─> F76-02 (contexto) ─> F76-03 (ancla) ─> F76-04 (runner + regresión)
```

---

# Fase 77 — Correcciones del panel /almacen (RF-93..RF-100)

| Tarea | Descripción | RF | Archivos | Verificación |
|-------|-------------|----|----------|--------------|
| F77-00 | Specs F77 | RF-93..100 | `.specs/specs/*`, `AGENTS.md` | revisión |
| F77-01 | Servido de clips (base `api/`) | RF-93 | `WarehouseRecordingController.php`, `WarehouseStateController.php`, `WarehouseClipPathTest.php` | unit PHP |
| F77-02 | Estado de puerta robusto (frescura real + STALE) | RF-94 | `WarehouseStateController.php`, `IotSessionService.php`, `croquis-logic.js` | unit PHP/JS |
| F77-03 | Replay coherente con tope | RF-95 | `visit-playback.js` | unit JS |
| F77-04 | Directo estable | RF-96 | `almacen.js`, `AlmacenEventStreamController.php` | unit + estático |
| F77-05 | Consumer: pong + presupuesto compartido | RF-97 | `tuya-pulsar-consumer/index.js` | unit JS |
| F77-06 | Purga sin huérfanos | RF-98 | `warehouse-recorder.php`, `warehouse-purge.php` | estático + manual |
| F77-07 | Luz inferida + detalle persistente + menores | RF-99/100 | `WarehouseStateController.php`, `croquis-logic.js`, `almacen.js` | unit JS + estático |
| F77-08 | Runner + regresión completa | RF-93..100 | `run-tests.sh`, `AGENTS.md` | `bash bin/run-tests.sh` |

## Orden de ejecución (F77)

```
F77-00 (specs) ─> F77-05 (consumer) ─> F77-01 (clips) ─> F77-02 (puerta)
              ─> F77-03 (replay) ─> F77-04 (directo) ─> F77-06 (purga)
              ─> F77-07 (luz/detalle) ─> F77-08 (runner + regresión)
```

## Estado de ejecución (F77)

- [x] F77-01 clips servidos + `WarehouseClipPathTest` (4/4)
- [x] F77-02 puerta robusta + `IotSessionServiceTest` (26/26) + `croquis-logic` (42/42)
- [x] F77-03 replay + `visit-playback` (63/63)
- [x] F77-04 directo estable (diff DOM + fingerprint)
- [x] F77-05 consumer pong/budget + `tuya-pulsar-consumer` (37/37)
- [x] F77-06 recorder aborta huérfanos
- [x] F77-07 luz inferida + detalle persistente
- [ ] F77-08 regresión completa (`run-tests.sh`)

---

## Fase 78 — Eliminación total del poller de presencia (RF-101)

**Objetivo**: garantizar que **no existe ningún poller** que sondee la API REST de Tuya en
continuo. La presencia es solo push (Pulsar) + sondas bajo demanda. Deroga el poller de F44/F46.

| Tarea | Descripción | RF | Archivos | Verificación |
|-------|-------------|----|----------|--------------|
| F78-00 | Specs F78 + regla inquebrantable | RF-101 | `.specs/specs/*`, `AGENTS.md` | revisión |
| F78-01 | Borrar poller + manager + wrapper + diagnósticos continuos | RF-101 | `api/bin/{tuya-presence-poller.js,presence-poller-manager.sh,wrapper-poller.sh,tuya-presence-listen.js,tuya-presence-sensor-read.php}` | ausencia de ficheros |
| F78-02 | Borrar tests JS del poller | RF-101 | `api/tests/Unit/presence-poller-*.test.js` | `run-tests.sh` |
| F78-03 | Arranque/parada sin poller + guardia | RF-101 | `start-all.sh`, `stop-all.sh`, `smoke-test.sh`, `watchdog.sh`, `worker-loop.sh`, `docs/systemd/cerraduras-workers.service` | `bash -n` |
| F78-04 | Contrato `system-status` con 5 workers | RF-101 | `api/public/index.php`, `HealthController.php`, `contracts.md` | BLOCK 33 |
| F78-05 | Guardia anti-regresión en el runner | RF-101 | `run-tests.sh` (BLOCK 35) | marcadores F78 |
| F78-06 | Docs/runbooks sin referencias al poller | RF-101 | `docs/ops.md`, `docs/runbook.md`, `docs/tuya-account-migration.md`, `design.md` | revisión |

## Orden de ejecución (F78)

```
F78-00 (specs) ─> F78-01/02 (borrado) ─> F78-03 (arranque) ─> F78-04 (contrato)
              ─> F78-05 (runner) ─> F78-06 (docs) ─> regresión completa
```

## Estado de ejecución (F78)

- [ ] F78-00 specs
- [ ] F78-01 borrado scripts
- [ ] F78-02 borrado tests
- [ ] F78-03 arranque/parada/guardia
- [ ] F78-04 contrato 5 workers
- [ ] F78-05 runner (BLOCK 35)
- [ ] F78-06 docs/runbooks

---

## Fase 79 — Visitas fantasma del almacén (RF-102)

**Objetivo**: que el listado de `/almacen` solo contenga visitas reales. El motor no crea
visitas desde señales simuladas ni desde reenvíos de estado (resync); el runner no contamina
la sala/dispositivo de producción; el panel oculta los intentos sin entrada.

| Tarea | Descripción | RF | Archivos | Verificación |
|-------|-------------|----|----------|--------------|
| F79-00 | Specs F79 | RF-102 | `.specs/specs/*`, `AGENTS.md` | revisión |
| F79-01 | Runner sin contaminar producción | RF-102.4 | `api/bin/run-tests.sh` (BLOCK 17, BLOCK 42) | BLOCK 42 S12 |
| F79-02 | Motor ignora SIMULATED/resync | RF-102.1/102.2 | `WarehouseRecordingService.php`, `IotSessionService.php`, `TuyaSensorIngress.php`, `tuya-pulsar-consumer/index.js` | unit PHP + JS |
| F79-03 | Visits oculta NO_SHOW DOOR por defecto | RF-102.3 | `WarehouseVisitController.php` | BLOCK 44 |
| F79-04 | Purga de fantasmas 78/79/81 | RF-102.5 | datos (`warehouse-purge`) | listado `/almacen` |

## Orden de ejecución (F79)

```
F79-00 (specs) ─> F79-01 (runner) ─> F79-02 (motor) ─> F79-03 (panel) ─> F79-04 (purga)
              ─> regresión completa
```

## Estado de ejecución (F79)

- [ ] F79-00 specs
- [ ] F79-01 runner
- [ ] F79-02 motor
- [ ] F79-03 panel
- [ ] F79-04 purga

---

# Fase 80 — Presencia del almacén en tiempo real (RF-103)

## Tareas

| ID | Descripción | RF | Archivos | Verificación |
|----|-------------|----|----------|--------------|
| F80-00 | Specs F80 (requirements/design/contracts/tasks) | RF-103 | `.specs/specs/*` | revisión |
| F80-01 | Presencia del almacén siempre creíble (`presence`+`move`), sin veto de puerta | RF-103.1 | `src/Domain/Presence/SensorEventDecision.php`, `src/Domain/Presence/IotSessionService.php` | BLOCK 1 |
| F80-02 | Ancla de visita por presencia + visita/disparo sin ciclo de puerta | RF-103.2 | `src/Domain/Warehouse/WarehouseRecordingService.php` | BLOCK 1 |
| F80-03 | `live.recent_presence` aditivo en `/almacen-api/state` + SSE | RF-103.3 | `src/Http/Controllers/WarehouseStateController.php` | BLOCK 53 |
| F80-04 | Fases en vivo del croquis (`near`/`crossing`/`inside`/`outside`) | RF-103.4 | `public/assets/croquis-logic.js`, `public/assets/almacen.js` | `croquis-logic.test.js` |
| F80-05 | Tests F80 + BLOCK 53 + regresión | RF-103.6 | `tests/Unit/*`, `bin/run-tests.sh`, `AGENTS.md` | `bash bin/run-tests.sh` |

## Orden de ejecución (F80)

```
F80-00 (specs) ─> F80-01/02 (backend presencia) ─> F80-03 (contrato live)
              ─> F80-04 (croquis) ─> F80-05 (tests + BLOCK 53) ─> regresión completa
```

## Estado de ejecución (F80)

- [ ] F80-00 specs
- [ ] F80-01 presencia siempre creíble
- [ ] F80-02 ancla por presencia
- [ ] F80-03 `live.recent_presence`
- [ ] F80-04 fases del croquis
- [ ] F80-05 tests + BLOCK 53

---

# Fase 81: Cierre de visita del almacén (RF-104)

## TSK-F81-01: Evento interno `DOOR_CLOSE_ABSENT` en el motor
- **Trazabilidad**: RF-104.2, RF-104.3, RF-104.4.
- **Archivo(s)**: `api/src/Domain/Warehouse/WarehouseRecordingDecision.php`,
  `api/tests/Unit/WarehouseRecordingDecisionTest.php`.
- **Pasos**:
  - [ ] Añadir la constante `EV_DOOR_CLOSE_ABSENT = 'DOOR_CLOSE_ABSENT'`.
  - [ ] En `STATE_RECORDING_INSIDE`, tratar `EV_DOOR_CLOSE_ABSENT` igual que `EV_ABSENT`:
        acciones `STOP_INT`, `MARK_EXIT`, `SET_DEADLINE_M` → `EXIT_PENDING`.
  - [ ] Mantener `EV_DOOR_CLOSE` como no-op en `RECORDING_INSIDE` (presencia interior).
  - [ ] Tests: `DOOR_CLOSE_ABSENT` en `RECORDING_INSIDE` → `EXIT_PENDING`; `DOOR_CLOSE` sigue
        no-op; `QR_PENDING + DOOR_CLOSE` sigue esperando X.
- **Verificación**: `api/tests/Unit/WarehouseRecordingDecisionTest.php` y
  `cd /root/cerraduras/api && bash bin/run-tests.sh`.

## TSK-F81-02: Elegir el evento según `presence_state` post-commit
- **Trazabilidad**: RF-104.1, RF-104.2, RF-104.3, RF-104.6.
- **Archivo(s)**: `api/src/Domain/Presence/IotSessionService.php`,
  `api/tests/Unit/IotSessionServiceTest.php`.
- **Pasos**:
  - [ ] En el bloque post-commit del motor del almacén, al procesar `PROXIMITY=CLOSED`, leer el
        `presence_state` de la sesión IoT ya mutada.
  - [ ] Entregar `EV_DOOR_CLOSE_ABSENT` si `presence_state=ABSENT`; `EV_DOOR_CLOSE` en caso
        contrario (incluye `PRESENT`).
  - [ ] Mantener los filtros F79 (provider `SIMULATED`, `source` `resync`/`sim`).
  - [ ] Tests: cierre sin presencia → `EXIT_PENDING`; cierre con presencia → `RECORDING_INSIDE`.
- **Verificación**: `api/tests/Unit/IotSessionServiceTest.php` y
  `cd /root/cerraduras/api && bash bin/run-tests.sh`.

## TSK-F81-03: Croquis `inside` al detectar presencia
- **Trazabilidad**: RF-104.5.
- **Archivo(s)**: `api/public/assets/croquis-logic.js`, `api/public/almacen.html`,
  `api/tests/Unit/croquis-logic.test.js`.
- **Pasos**:
  - [ ] `deriveCroquis`: si hay presencia (`PRESENT`/`occupied`) la fase es `inside` aunque la
        puerta esté abierta; puerta abierta sin presencia → `near`; sin nada → `outside`.
  - [ ] Posición de `inside` más al interior en el CSS de `almacen.html`.
  - [ ] Tests: `inside` con puerta abierta + presencia; `near` con puerta abierta sin presencia.
- **Verificación**: `api/tests/Unit/croquis-logic.test.js` y
  `cd /root/cerraduras/api && bash bin/run-tests.sh`.

## TSK-F81-04: Regresión del runner con marcadores F81
- **Trazabilidad**: RF-104.6, RF-104.7, RF-104.8.
- **Archivo(s)**: `api/bin/run-tests.sh`.
- **Pasos**:
  - [ ] Ampliar el BLOCK vigente con casos F81 (inicio por los 3 disparadores; fin por `ABSENT` con
        puerta abierta; fin por cierre sin presencia; cierre con presencia no termina).
  - [ ] Verificar que no se introduce ningún sondeo/cuota Tuya (hereda RF-101).
  - [ ] Ejecutar la regresión completa.
- **Verificación**: `cd /root/cerraduras/api && bash bin/run-tests.sh` con **0 failures**.

## Orden de ejecución (F81)

```
F81-01 (motor) ─> F81-02 (enganche) ─> F81-03 (croquis) ─> F81-04 (runner + regresión)
```

## Estado de ejecución (F81)

- [ ] F81-01 evento `DOOR_CLOSE_ABSENT`
- [ ] F81-02 elección según `presence_state`
- [ ] F81-03 croquis `inside`
- [ ] F81-04 BLOCK del runner + regresión

---

# Fase 82: Listado de visitas del almacén con presencia (RF-105)

## TSK-F82-01: Mostrar por defecto las visitas disparadas por presencia
- **Trazabilidad**: RF-105.1, RF-105.2, RF-105.3.
- **Archivo(s)**: `api/src/Http/Controllers/WarehouseVisitController.php`.
- **Pasos**:
  - [ ] En `index()`, eliminar la exclusión por defecto de `entry_trigger='PRESENCE'` del filtro
        (la consulta por defecto queda como `NOT (outcome='NO_SHOW' AND entry_trigger='DOOR')`).
  - [ ] Mantener `include_no_show=1` y el filtro explícito `outcome=…` con su comportamiento actual
        (los `NO_SHOW` con `DOOR` siguen ocultos por defecto).
  - [ ] No alterar la forma del JSON (`visits[]` con los mismos campos y tipos).
- **Verificación**: `php -l api/src/Http/Controllers/WarehouseVisitController.php` y BLOCK del
  runner con marcadores F82.

## TSK-F82-02: Regresión del runner con marcadores F82
- **Trazabilidad**: RF-105.4.
- **Archivo(s)**: `api/bin/run-tests.sh`.
- **Pasos**:
  - [ ] Ampliar BLOCK(s) del runner con casos F82: una visita `entry_trigger='PRESENCE'` aparece en
        `GET /almacen-api/visits` sin parámetros; un `NO_SHOW` con `DOOR` sigue oculto por defecto y
        visible con `include_no_show=1`.
  - [ ] Verificar que no se introduce ningún sondeo/cuota Tuya (hereda RF-101).
  - [ ] Ejecutar la regresión completa.
- **Verificación**: `cd /root/cerraduras/api && bash bin/run-tests.sh` con **0 failures**.

## Orden de ejecución (F82)

```
F82-01 (filtro del listado) ─> F82-02 (BLOCK del runner + regresión)
```

## Estado de ejecución (F82)

- [ ] F82-01 eliminar exclusión por defecto de PRESENCE en el listado
- [ ] F82-02 BLOCK del runner con marcadores F82 + regresión

---

# Fase 83: Presencia fiel en el panel del almacén (RF-106)

## TSK-F83-01: API `WarehouseStateController` (presencia fiel + `exited_at`)
- **Trazabilidad**: RF-106.1, RF-106.2, RF-106.5.
- **Archivo(s)**: `api/src/Http/Controllers/WarehouseStateController.php`.
- **Pasos**:
  - [ ] En `stateArray()`, calcular `presenceKnown` (`presence_state` ∈ `PRESENT`/`ABSENT`) y
        `presenceActive` (`PRESENT`); publicar `live.presence_active` y `live.presence_known` (aditivos).
  - [ ] Añadir `exited_at` al `SELECT` de la visita y exponer `current_visit.exited_at`.
  - [ ] Calcular `activeVisit` (`outcome='ENTERED'` y `exited_at IS NULL`) y redefinir
        `warehouse.occupied = presence_known ? presence_active : activeVisit`.
  - [ ] Calcular `warehouse.exiting` (`warehouse_state.state='EXIT_PENDING'` o `exited_at` no nulo).
  - [ ] No añadir rutas ni llamadas a Tuya (solo BD).
- **Verificación**: `php -l api/src/Http/Controllers/WarehouseStateController.php` y **BLOCK 56** con
  marcadores F83.

## TSK-F83-02: Croquis `croquis-logic.js` (el radar manda)
- **Trazabilidad**: RF-106.1, RF-106.2.
- **Archivo(s)**: `api/public/assets/croquis-logic.js`, `api/tests/Unit/croquis-logic.test.js`.
- **Pasos**:
  - [ ] `personInside = presenceKnown ? (presence === 'PRESENT') : occupied`; `ABSENT` con visita,
        `occupied` o `PRESENT` anterior → **fuera**.
  - [ ] Eliminar la rama `recentPresent → 'inside'`: la fase usa `personInside` y, en su defecto, la
        puerta (`recentPresent` queda solo como diagnóstico).
  - [ ] Chip de presencia sin forzar `PRESENTE` por `occupied`: `PRESENT`→`PRESENCIA`,
        `ABSENT`→`VACÍO`, `UNKNOWN`→`SIN DATOS` (dim). Fallback `UNKNOWN`+visita activa: muñeco dentro
        pero **atenuado**.
  - [ ] Añadir los casos unit en `croquis-logic.test.js`.
- **Verificación**: `node api/tests/Unit/croquis-logic.test.js` y
  `cd /root/cerraduras/api && bash bin/run-tests.sh`.

## TSK-F83-03: Panel `almacen.js` (encabezado y meta)
- **Trazabilidad**: RF-106.2, RF-106.5.
- **Archivo(s)**: `api/public/assets/almacen.js`.
- **Pasos**:
  - [ ] `renderHeader()`: `warehouse.exiting` → `SALIENDO`; si no `occupied` → `OCUPADO`; si no
        `warehouse.state !== 'IDLE'` → estado; si no → `LIBRE`.
  - [ ] `renderCroquisMeta()`: "Dentro: …" solo con `live.presence_state === 'PRESENT'`
        (`live.presence_active`); con `UNKNOWN`+visita activa, texto atenuado "presencia sin confirmar"
        (nunca "Dentro").
  - [ ] Mantener el caso de "última salida / sin actividad".
- **Verificación**: revisión del panel `/almacen` y **BLOCK 56** (marcadores F83).

## TSK-F83-04: Margen exterior 10 s (migración `0121`)
- **Trazabilidad**: RF-106.3.
- **Archivo(s)**: `api/migrations/0121_warehouse_exterior_margin_10.sql`.
- **Pasos**:
  - [ ] `UPDATE room_types SET warehouse_exterior_margin_seconds=10 WHERE code='ALMACEN_BEBIDAS'`
        (idempotente).
  - [ ] No cambiar `WarehouseRecordingDecision`: el motor ya consume `M` vía `roomConfig()`.
- **Verificación**: aplicar la migración y comprobar
  `room_types.warehouse_exterior_margin_seconds=10`.

## TSK-F83-05: Tests (unit JS + BLOCK 56) y regresión
- **Trazabilidad**: RF-106.1–RF-106.6.
- **Archivo(s)**: `api/tests/Unit/croquis-logic.test.js`, `api/bin/run-tests.sh`.
- **Pasos**:
  - [ ] Casos unit: `ABSENT`+`occupied`+`PRESENT` anterior → `personInside=false`/`phase='outside'` y
        chip `VACÍO`; `PRESENT` → dentro; `UNKNOWN`+visita activa → `personInside=true` con chip
        `SIN DATOS` (nunca `PRESENTE`/`PRESENCIA`).
  - [ ] Añadir **BLOCK 56** al runner con marcadores F83: `state` publica `live.presence_active`,
        `live.presence_known`, `warehouse.exiting` y `current_visit.exited_at`; `warehouse.occupied`
        refleja `ABSENT` aunque haya visita; sin rutas nuevas ni cuota Tuya.
  - [ ] Ejecutar la regresión completa.
- **Verificación**: `cd /root/cerraduras/api && bash bin/run-tests.sh` con **0 failures**.

## Orden de ejecución (F83)

```
F83-01 (API) ─┬─> F83-02 (croquis) ─> F83-03 (panel) ─┐
             └─> F83-04 (migración margen) ───────────┴─> F83-05 (tests + BLOCK 56) ─> regresión
```

## Estado de ejecución (F83)

- [ ] TSK-F83-01 API `WarehouseStateController`
- [ ] TSK-F83-02 croquis `croquis-logic.js`
- [ ] TSK-F83-03 panel `almacen.js`
- [ ] TSK-F83-04 migración `0121_warehouse_exterior_margin_10.sql`
- [ ] TSK-F83-05 tests + BLOCK 56 + regresión

---

# Fase 84: Antiruido del radar y reproducción fiel de la puerta (RF-107 / RF-108)

## TSK-F84-01: Migración `0122` (enum `NOISE` + umbrales)
- **Trazabilidad**: RF-107.5.
- **Archivo(s)**: `api/migrations/0122_warehouse_presence_noise.sql`.
- **Pasos**:
  - [ ] `ALTER TABLE warehouse_visits MODIFY outcome ENUM(...,'NOISE')`.
  - [ ] `ADD COLUMN IF NOT EXISTS` de `warehouse_presence_min_moves` (2), `warehouse_presence_min_events` (3),
        `warehouse_presence_static_seconds` (300) en `room_types`.
  - [ ] `UPDATE room_types` de `ALMACEN_BEBIDAS` con los valores por defecto.
- **Verificación**: aplicar migración y comprobar columnas/enum.

## TSK-F84-02: Clasificador puro `PresenceEvidence`
- **Trazabilidad**: RF-107.2.
- **Archivo(s)**: `api/src/Domain/Warehouse/PresenceEvidence.php`, `api/tests/Unit/PresenceEvidenceTest.php`.
- **Pasos**:
  - [ ] `isConfirmed(moves, events, seconds, doorEvent, config)` con la regla del diseño §48.2.
  - [ ] Tests unit: fantasma `m1 p1` 25 s → false; `m2 p1` → true; `m1 p1` 400 s → true; `m1 p1` + puerta → true;
        umbrales configurados a medida.
- **Verificación**: `php -l` + autodescubierto en BLOCK 1.

## TSK-F84-03: Motor: evaluar evidencia y descartar ruido
- **Trazabilidad**: RF-107.2/107.3/107.4/107.7.
- **Archivo(s)**: `api/src/Domain/Warehouse/WarehouseRecordingService.php`,
  `api/src/Domain/Warehouse/WarehouseRecordingDecision.php`, `api/tests/Unit/WarehouseRecordingDecisionTest.php`.
- **Pasos**:
  - [ ] `WarehouseRecordingDecision::decide()` acepta contexto `presence_confirmed`; en
        `RECORDING_INSIDE + (ABSENT|DOOR_CLOSE_ABSENT)` con `entry_trigger=PRESENCE` y
        `presence_confirmed=false` → acciones de descarte + `A_MARK_NOISE` + `IDLE`.
  - [ ] `A_MARK_NOISE`: `UPDATE warehouse_visits SET outcome='NOISE'`.
  - [ ] `WarehouseRecordingService::onSignal()`: en el fin de episodio calcula la evidencia (SQL local
        sobre `presence_events` + duración + puerta) y la pasa al decisor; lee umbrales de `room_types`.
  - [ ] Respeta F79 (provider `SIMULATED` / `source` `resync` siguen ignorados).
- **Verificación**: `php -l` + tests unit + BLOCK 57.

## TSK-F84-04: Listado y `door` en el detalle
- **Trazabilidad**: RF-107.3, RF-108.1.
- **Archivo(s)**: `api/src/Http/Controllers/WarehouseVisitController.php`.
- **Pasos**:
  - [ ] `index()`: oculta `outcome='NOISE'` por defecto; `include_noise=1` o `outcome=NOISE` los muestran.
  - [ ] `show()`: adjunta `visit.door` (`state_at_start` + `events[]`) desde `presence_events`.
  - [ ] Sin rutas nuevas ni llamadas a Tuya.
- **Verificación**: `php -l` + BLOCK 57 HTTP.

## TSK-F84-05: Reproducción fiel de la puerta
- **Trazabilidad**: RF-108.2/108.3.
- **Archivo(s)**: `api/public/assets/visit-playback.js`, `api/tests/Unit/visit-playback.test.js`.
- **Pasos**:
  - [ ] `buildVisitTimeline`: integra `visit.door` en intervalos OPEN→CLOSED (`state_at_start`).
  - [ ] `frameAt`: `doorOpen`/chip/descripción desde los intervalos reales; sin eventos → cerrada.
  - [ ] Fallback sintético si `door` no viene (compatibilidad).
  - [ ] Tests unit: visita PRESENCE sin puerta → nunca abierta; visita DOOR con intervalos reales; `state_at_start=OPEN`;
        visitas sin `door` → fallback previo.
- **Verificación**: `node api/tests/Unit/visit-playback.test.js` + BLOCK 46/57.

## TSK-F84-06: Runner BLOCK 57 + AGENTS + regresión
- **Trazabilidad**: RF-107.8, RF-108.4.
- **Archivo(s)**: `api/bin/run-tests.sh`, `AGENTS.md`.
- **Pasos**:
  - [ ] **BLOCK 57** con marcadores F84: `PresenceEvidence`, `A_MARK_NOISE`, filtro `NOISE`, `visit.door`,
        `visit-playback.js` puerta fiel, sin cuota/ruido.
  - [ ] HTTP: `visits` oculta NOISE; `visits/{id}` expone `door`; detalle de visita de prueba.
  - [ ] AGENTS.md: fila F84 + sección.
  - [ ] Regresión completa 0 failures.
- **Verificación**: `cd api && bash bin/run-tests.sh`.

## TSK-F84-07: Purga sala 12 y verificación en vivo
- **Trazabilidad**: RF-107.3.
- **Pasos**:
  - [ ] `systemctl stop cerraduras-warehouse-recorder` → `php bin/warehouse-purge.php --room=12` →
        `systemctl start cerraduras-warehouse-recorder`.
  - [ ] Verificar: sala vacía no aparecen visitas nuevas; una detección fantasma acaba en `NOISE`
        (oculta); una entrada real (movimiento) se conserva; replay de visita DOOR coincide con el vídeo.
- **Verificación**: consultas a BD + panel `/almacen`.

## Orden de ejecución (F84)

```
F84-01 (migración) ─> F84-02 (clasificador) ─> F84-03 (motor) ─┬─> F84-04 (controladores) ─> F84-05 (replay)
                                                                └─> F84-06 (runner/AGENTS) ─> F84-07 (purga)
```

## Estado de ejecución (F84)

- [ ] TSK-F84-01 migración `0122`
- [ ] TSK-F84-02 clasificador `PresenceEvidence`
- [ ] TSK-F84-03 motor (evidencia + NOISE)
- [ ] TSK-F84-04 controladores (filtro + door)
- [ ] TSK-F84-05 `visit-playback.js`
- [ ] TSK-F84-06 runner BLOCK 57 + AGENTS + regresión
- [ ] TSK-F84-07 purga sala 12 + verificación en vivo

---

# Fase 85: Modelo de detección del pack almacén (RF-109…RF-114)

## TSK-F85-01: Migración `0123` — contexto de re-entrada
- **Trazabilidad**: RF-112.3.
- **Archivo(s)**: `api/migrations/0123_warehouse_reentry_context.sql`.
- **Pasos**:
  - [ ] `ALTER TABLE room_types ADD COLUMN IF NOT EXISTS warehouse_reentry_context_seconds INT UNSIGNED NOT NULL DEFAULT 300`.
  - [ ] `UPDATE room_types SET warehouse_reentry_context_seconds=300 WHERE code='ALMACEN_BEBIDAS'`.
  - [ ] Idempotente; sin tocar `warehouse_visits.outcome` (ya admite `NOISE`, `0122`).
- **Verificación**: `php api/bin/migrate.php` + `DESCRIBE room_types`.

## TSK-F85-02: Motor `WarehouseRecordingDecision` (modelo y fallbacks)
- **Trazabilidad**: RF-109.1–109.4, RF-110.1–110.5.
- **Archivo(s)**: `api/src/Domain/Warehouse/WarehouseRecordingDecision.php`,
  `api/tests/Unit/WarehouseRecordingDecisionTest.php`.
- **Pasos**:
  - [ ] `IDLE` sigue siendo el único que crea visita con los 3 disparadores (dedupe).
  - [ ] `QR_PENDING + X_EXPIRED` → `IDLE` con `STOP+DISCARD EXT+INT`, `VISIT_NO_SHOW`, `CLOSE_VISIT`
    (antes `EXTERIOR_ONLY` para trigger `QR`); marcar `EXTERIOR_ONLY`/`A_DISCARD_EXT` como deprecados.
  - [ ] `EXIT_PENDING + PRESENT` → `MARK_EXIT`(prev) + `STOP_EXT`(prev) + `CREATE_VISIT`(PRESENCE) +
    `CONFIRM_ENTRY` + `START_EXT`/`START_INT` + `CLEAR_DEADLINES` → `RECORDING_INSIDE` (visita nueva).
  - [ ] Mantener `DOOR_OPEN`/`DOOR_CLOSE` no-op en `QR_PENDING`/`RECORDING_INSIDE`.
  - [ ] Tests unit: QR+apertura+presencia=1 visita QR; QR sin entrada descarta todo; DOOR sin
    presencia descarta; re-entrada tras ABSENT = 2 visitas; segundo `DOOR_OPEN` no duplica.
- **Verificación**: `php api/tests/Unit/WarehouseRecordingDecisionTest.php` + BLOCK 58.

## TSK-F85-03: Servicio `WarehouseRecordingService` (re-entrada y evidencia)
- **Trazabilidad**: RF-109.4, RF-112.1.
- **Archivo(s)**: `api/src/Domain/Warehouse/WarehouseRecordingService.php`,
  `api/tests/Unit/WarehouseReentryTest.php`.
- **Pasos**:
  - [ ] Procesar en orden `A_STOP_EXT`(prev) → `A_CREATE_VISIT`(nueva) → `A_START_*`(nueva) para no
    mezclar `visit_id`.
  - [ ] `presenceEvidenceConfirmed()`: añadir contexto de re-entrada (`entry_trigger IN ('DOOR','QR')`,
    `outcome='ENTERED'`, `exited_at >= now - warehouse_reentry_context_seconds`, `id <> visita actual`)
    → real aunque el episodio tenga 1 `move`.
  - [ ] No usar contexto para los fantasmas sin ciclo real (siguen `NOISE`).
- **Verificación**: `php api/tests/Unit/WarehouseReentryTest.php` + BLOCK 58.

## TSK-F85-04: Guarda del recorder (evitar doble ffmpeg)
- **Trazabilidad**: RF-109.4.
- **Archivo(s)**: `api/bin/warehouse-recorder.php`.
- **Pasos**:
  - [ ] No arrancar un `PENDING` si el mismo `device_id` ya tiene una fila `RECORDING`.
  - [ ] El EXTERIOR anterior (stop solicitado) se para en el tick y el nuevo arranca en el siguiente
    (~1 s).
  - [ ] Test/verificación manual: en la re-entrada no quedan dos ffmpeg del mismo `device_id`.
- **Verificación**: `php -l api/bin/warehouse-recorder.php` + inspección `ps`/BD.

## TSK-F85-05: API — recuperación acotada y apertura manual
- **Trazabilidad**: RF-111.2–111.4.
- **Archivo(s)**: `api/public/index.php` (rutas `sensors/refresh`, `door/open`).
- **Pasos**:
  - [ ] `sensors/refresh`: permitir la sonda con `door_stale && last_door_event_at > status_probed_at`
    aunque el cooldown esté vigente; `reason:'unresolved_transition'` (aditivo).
  - [ ] `door/open`: emitir `EV_DOOR_OPEN` (`source='panel'`) tras abrir; respuesta aditiva
    `visit_started`.
  - [ ] Mantener presupuesto/backoff compartido; sin temporizadores.
- **Verificación**: BLOCK 58 HTTP.

## TSK-F85-06: Panel `/almacen` (visibilidad y auto-recuperación)
- **Trazabilidad**: RF-113, RF-111.3.
- **Archivo(s)**: `api/public/almacen.html`, `api/public/assets/almacen.js`.
- **Pasos**:
  - [ ] Filtro de resultados con opción `NOISE` + checkbox "mostrar descartes"
    (`include_noise=1`/`include_no_show=1`).
  - [ ] Disparar **una** `refreshSensors` al observar `live.door_stale===true` (una vez por
    transición), sin temporizador; mantener la del arranque.
  - [ ] Reverificar croquis/replay (sin regresiones de F67/F68/F84).
- **Verificación**: BLOCK 58 (estáticos) + prueba manual en `/almacen`.

## TSK-F85-07: Runner BLOCK 58 + AGENTS + regresión
- **Trazabilidad**: RF-114.
- **Archivo(s)**: `api/bin/run-tests.sh`, `AGENTS.md`.
- **Pasos**:
  - [ ] **BLOCK 58** con marcadores F85: re-entrada = 2 visitas; QR sin entrada descarta todo;
    `reason:"unresolved_transition"`; `door/open` dispara el motor; filtro `NOISE` en UI; guarda del
    recorder.
  - [ ] AGENTS.md: fila F85 + sección de resumen.
  - [ ] Regresión completa 0 failures.
- **Verificación**: `cd api && bash bin/run-tests.sh`.

## TSK-F85-08: Verificación en vivo (sala 12)
- **Trazabilidad**: RF-109, RF-111.
- **Pasos**:
  - [ ] Abrir panel → `Actualizar estado` recupera el `CLOSED` real si la puerta está cerrada.
  - [ ] Ciclo: entrar (apertura) → salir (`ABSENT`) → re-entrar ≤ M → **2 visitas** en el listado.
  - [ ] Cerrar puerta: el croquis pasa a `PUERTA CERRADA` (o se recupera con la sonda acotada).
- **Verificación**: consultas a BD + panel `/almacen`.

## Orden de ejecución (F85)

```
F85-01 (migración) ─> F85-02 (motor) ─┬─> F85-03 (servicio/evidencia) ─> F85-04 (recorder)
                                       ├─> F85-05 (API refresh/door) ─> F85-06 (panel)
                                       └─> F85-07 (runner/AGENTS) ─> F85-08 (verificación en vivo)
```

## Estado de ejecución (F85)

- [x] TSK-F85-01 migración `0123`
- [x] TSK-F85-02 motor `WarehouseRecordingDecision`
- [x] TSK-F85-03 servicio/evidencia
- [x] TSK-F85-04 guarda del recorder
- [x] TSK-F85-05 API refresh/door
- [x] TSK-F85-06 panel `/almacen`
- [x] TSK-F85-07 runner BLOCK 58 + AGENTS + regresión (**512 passed, 0 failed, 2 skipped**)
- [x] TSK-F85-08 verificación en vivo (sonda real `reason:"ok"`; la re-entrada física queda pendiente de prueba del operador)

---

# Fase 86: Puerta fiel ante reportes repetidos (RF-115)

## TSK-F86-01: Decisión `refresh` + efectos en `IotSessionService`
- **Trazabilidad**: RF-115.1–115.3, RF-115.5.
- **Archivo(s)**: `api/src/Domain/Presence/SensorEventDecision.php`,
  `api/src/Domain/Presence/IotSessionService.php`.
- **Pasos**:
  - [x] `SensorEventDecision::REFRESH`: PROXIMITY TUYA con mismo valor e instante nuevo y
    `meta.source !== 'resync'`.
  - [x] `IotSessionService`: refresca `last_door_event_at` y `last_open_at`/`last_close_at` sin
    cambiar `door_state`; audita `discard_reason='refresh'`; dispara post-commit (luz + motor).
- **Verificación**: units + BLOCK 59.

## TSK-F86-02: Re-entrada con contexto de puerta
- **Trazabilidad**: RF-115.4.
- **Archivo(s)**: `api/src/Domain/Warehouse/WarehouseRecordingService.php`.
- **Pasos**:
  - [x] `presenceEvidenceConfirmed()`: exigir `iot_sessions.door_state='OPEN'` para el atajo de
    re-entrada de RF-112; sin puerta abierta se aplica F84 sin cambios.
- **Verificación**: BLOCK 59 + prueba en vivo.

## TSK-F86-03: Tests
- **Trazabilidad**: RF-115.1–115.5.
- **Archivo(s)**: `api/tests/Unit/SensorEventDecisionTest.php`,
  `api/tests/Unit/IotSessionServiceTest.php`.
- **Pasos**:
  - [x] `refresh` real; `noop` para resync/simulado; presencia mismo valor sigue `noop`.
  - [x] `IotSessionService`: refresh no cambia `door_state`, actualiza frescura y alimenta al motor;
    resync no refresca ni alimenta.
- **Verificación**: `php tests/Unit/SensorEventDecisionTest.php` +
  `php tests/Unit/IotSessionServiceTest.php`.

## TSK-F86-04: Runner BLOCK 59 + AGENTS + regresión
- **Trazabilidad**: RF-115.6.
- **Archivo(s)**: `api/bin/run-tests.sh`, `AGENTS.md`.
- **Pasos**:
  - [x] **BLOCK 59** con marcadores F86 (decisión `REFRESH`, `doorRefreshed`, contexto de puerta en
    la evidencia) + units.
  - [x] AGENTS.md: fila F86 + sección.
  - [x] Regresión completa 0 failures (**519 passed, 0 failed, 2 skipped**).
  - [x] Verificación en vivo: un `CLOSED` real repetido limpia `door_stale` (`discard_reason='refresh'`);
    un `OPEN` real en reposo crea visita `DOOR` (cubierto por unit `IotSessionServiceTest` T5b).
- **Verificación**: `cd api && bash bin/run-tests.sh`.

## Estado de ejecución (F86)

- [x] TSK-F86-01 decisión `refresh` + efectos
- [x] TSK-F86-02 re-entrada con contexto de puerta
- [x] TSK-F86-03 tests unit
- [x] TSK-F86-04 runner BLOCK 59 + AGENTS + regresión + verificación en vivo

---

# Fase 87: Veracidad de presencia y limpieza de grabaciones (RF-116…RF-121)

## TSK-F87-01: `presence_stale` en el estado y el croquis
- **Trazabilidad**: RF-116.1–116.3.
- **Archivo(s)**: `api/src/Http/Controllers/WarehouseStateController.php`,
  `api/public/assets/croquis-logic.js`.
- **Pasos**:
  - [ ] `presenceStaleSeconds()` (def. 180) + `live.presence_stale`; `occupied` falso si stale.
  - [ ] `croquis-logic.js`: `presence_stale` → chip "SIN DATOS" y `personInside=false`.
- **Verificación**: `node tests/Unit/croquis-logic.test.js`.

## TSK-F87-02: Evidencia reforzada
- **Trazabilidad**: RF-117.1–117.4.
- **Archivo(s)**: `api/src/Domain/Warehouse/PresenceEvidence.php`,
  `api/src/Domain/Warehouse/WarehouseRecordingService.php`,
  `api/migrations/0124_warehouse_evidence_retention.sql`,
  `api/tests/Unit/PresenceEvidenceTest.php`.
- **Pasos**:
  - [ ] `isConfirmed` sin `min_events`; `static_seconds` def. 1800.
  - [ ] Migración `0124` (static 1800 + retention_days 1), idempotente.
  - [ ] Consulta de evidencia: mantiene `moves`/`doorEvent`; `events` pasa sin efecto.
- **Verificación**: `php tests/Unit/PresenceEvidenceTest.php`.

## TSK-F87-03: Grabación fiel al ciclo de puerta
- **Trazabilidad**: RF-118.1–118.3.
- **Archivo(s)**: `api/src/Domain/Warehouse/WarehouseRecordingDecision.php`,
  `api/src/Domain/Warehouse/WarehouseRecordingService.php`,
  `api/tests/Unit/WarehouseRecordingDecisionTest.php`.
- **Pasos**:
  - [ ] `A_ENSURE_RECORDING` en `RECORDING_INSIDE + DOOR_OPEN`.
  - [ ] Servicio: arranca EXT+INT solo si no hay PENDING/RECORDING de la visita.
- **Verificación**: `php tests/Unit/WarehouseRecordingDecisionTest.php`.

## TSK-F87-04: Descarte robusto y limpieza
- **Trazabilidad**: RF-119.1–119.3.
- **Archivo(s)**: `api/src/Domain/Warehouse/WarehouseRecordingService.php`,
  `api/bin/warehouse-recorder.php`.
- **Pasos**:
  - [ ] `requestDiscard` sobre todo estado `<> 'DISCARDED'`.
  - [ ] Recorder: fase de descarte de `SAVED`/`PENDING`/`FAILED` (borra fichero + póster).
  - [ ] Fix `started_at` (B6) y finalización de `PENDING` descartados (B7).
  - [ ] Limpieza puntual de los 410 clips NOISE `SAVED`.
- **Verificación**: `php` + `node`; BLOCK 60.

## TSK-F87-05: Retención automática
- **Trazabilidad**: RF-120.1–120.3.
- **Archivo(s)**: `docs/systemd/cerraduras-warehouse-retention.{service,timer}`,
  `start-all.sh`, `stop-all.sh`.
- **Pasos**:
  - [ ] Service (oneshot) + timer (diario 04:00).
  - [ ] `start-all.sh` enable --now con guarda; `stop-all.sh` stop del timer.
- **Verificación**: `systemd-analyze verify` si disponible; BLOCK 60 estático.

## TSK-F87-06: Runner BLOCK 60 + AGENTS + regresión
- **Trazabilidad**: RF-121.1–121.4.
- **Archivo(s)**: `api/bin/run-tests.sh`, `AGENTS.md`.
- **Pasos**:
  - [ ] **BLOCK 60** con marcadores F87 + units.
  - [ ] AGENTS.md: fila F87 + sección.
  - [ ] Regresión completa 0 failures + verificación en vivo.
- **Verificación**: `cd api && bash bin/run-tests.sh`.

## Estado de ejecución (F87)

- [ ] TSK-F87-01 `presence_stale` estado + croquis
- [ ] TSK-F87-02 evidencia reforzada + migración 0124
- [ ] TSK-F87-03 grabación en door open
- [ ] TSK-F87-04 descarte robusto + limpieza
- [ ] TSK-F87-05 retención automática
- [ ] TSK-F87-06 runner BLOCK 60 + AGENTS + regresión + verificación en vivo
