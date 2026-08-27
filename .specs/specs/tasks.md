# Tasks — Cerraduras Hotel

---

# Fase 1: Robustez firmware ESP32 QR Reader

Archivo a modificar: `docs/esp32-qr-reader/scanner-relay-prod.ino` (640 líneas)

---

## TSK-01: Watchdog timer

**Trazabilidad**: RF-1.1, RF-1.2, RF-1.3
**Archivo**: `scanner-relay-prod.ino`

- [ ] Añadir `#include "esp_task_wdt.h"` al principio del archivo (junto a los otros includes, línea ~44).
- [ ] En `setup()`, después de `Serial.begin(115200)` (línea 274), añadir:
  ```cpp
  esp_task_wdt_init(60, true);
  esp_task_wdt_add(NULL);
  ```
- [ ] En `loop()` (línea 459), añadir como primera línea dentro de la función:
  ```cpp
  esp_task_wdt_reset();
  ```

**Verificación**: Compilar y flashear. El ESP32 debe arrancar sin errores. Si se introduce un `while(true){}` de prueba, el ESP32 debe reiniciarse solo a los 60s.

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
