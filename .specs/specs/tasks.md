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
