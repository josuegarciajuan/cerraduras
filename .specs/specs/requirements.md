# Requirements — Cerraduras Hotel

---

# Fase 1: Robustez firmware ESP32 QR Reader

## RF-1: Watchdog Timer
- **RF-1.1**: El ESP32 debe configurar un watchdog hardware (`esp_task_wdt_init`) con timeout de 60 segundos. (Nota de implementación: en Arduino-ESP32 core 3.x / IDF 5.x la llamada usa `esp_task_wdt_config_t{timeout_ms=60000, idle_core_mask=0, trigger_panic=true}`; en core 2.x legado `esp_task_wdt_init(60, true)`.)
- **RF-1.2**: El `loop()` principal debe resetear el watchdog (`esp_task_wdt_reset`) en cada iteración.
- **RF-1.3**: Si el código se bloquea >60s (delay infinito, deadlock, crash lógico), el watchdog debe forzar un reinicio automático del ESP32.

## RF-2: Eliminar delays bloqueantes del loop()
- **RF-2.1**: El `delay(1)` al final del loop (L639) se elimina → reemplazar por `yield()` o `vTaskDelay(1)`.
- **RF-2.2**: El `delay(1000)` en la ruta `hasPending && !ensureWiFi()` (L464) se reemplaza por una máquina de estados no bloqueante basada en `millis()`.
- **RF-2.3**: El `delay(3000)` en `relayPulse()` (L143) se reemplaza por un timer no bloqueante: `relayOn()` inmediato, `relayOff()` programado a `millis() + OPEN_DURATION_MS`.
- **RF-2.4**: El `delay(4500)` en `wifiConnectedFeedback()` (L102) se reemplaza por un timer no bloqueante: LED ON inmediato, LED OFF programado a `millis() + LED_ON_MS`.
- **RF-2.5**: Los `delay(500)` en `connectWithNvs()` (L181) y `delay(500)` en `ensureWiFi()` (L239) se mantienen en `setup()` (fuera del loop, no críticos).

## RF-3: Prevenir fragmentación de heap en construcción de JSON
- **RF-3.1**: Cada `String` usado para construir cuerpos JSON debe llamar a `.reserve(N)` antes de las concatenaciones, con N ≥ tamaño máximo esperado del cuerpo.
- **RF-3.2**: El `String body` en el POST de validación QR (L483) debe reservar 600 bytes.
- **RF-3.3**: El `String body` en `sendIdentify()` (L251) debe reservar 200 bytes.
- **RF-3.4**: El `String` del heartbeat batch (L534) debe reservar 250 bytes.
- **RF-3.5**: El `String` de command-result (L588-594) debe reservar 350 bytes.

## RF-4: Auto-reinicio si WiFi caído > 2 minutos
- **RF-4.1**: El ESP32 debe llevar un contador de tiempo sin WiFi (`wifiDownSince`).
- **RF-4.2**: En cada iteración del loop, si WiFi está desconectado y `wifiDownSince == 0`, registrar `millis()` actual.
- **RF-4.3**: Si WiFi sigue desconectado y han pasado >120 segundos desde `wifiDownSince`, ejecutar `ESP.restart()`.
- **RF-4.4**: Si WiFi se reconecta, reiniciar `wifiDownSince = 0`.

## RF-5: Regresión — mantener funcionalidad existente
- **RF-5.1**: El flujo de validación QR (POST /api/v1/qr/validate) debe seguir funcionando exactamente igual.
- **RF-5.2**: El relay debe activarse durante `OPEN_DURATION_MS` (3s) al recibir HTTP 200.
- **RF-5.3**: El heartbeat cada 30s debe seguir funcionando.
- **RF-5.4**: El botón identify (GPIO4) debe seguir funcionando.
- **RF-5.5**: El comando F33 (check SCANNER/LOCK) debe seguir funcionando.
- **RF-5.6**: La conexión WiFi vía NVS + WiFiManager debe seguir funcionando.
- **RF-5.7**: El LED de feedback (GPIO2) debe encenderse `LED_ON_MS` (4500ms) al conectar WiFi por primera vez.

## RF-6: Diagnóstico — log de heap
- **RF-6.1**: En cada heartbeat (cada 30s), incluir `ESP.getFreeHeap()` en el log Serial.
- **RF-6.2**: Si el heap libre baja de 20KB, loguear un warning.

---

# Fase 35: Detección de anomalías en el flujo de sensores

## Contexto

El sistema Cerraduras gobierna el ciclo de vida de una estancia hotelera mediante 4 máquinas de estado acopladas: **Stay** (RESERVED → OCCUPIED → EXITED → CLOSED), **Room** (FREE → RESERVED → OCCUPIED → FREE), **IoT Session** (door_state × presence_state), y **QR Credential** (issued → consumed → expired).

El flujo correcto de entrada y salida está gobernado por dos tipos de sensores físicos:
- **PROXIMITY** (contacto magnético en la puerta): valores `OPEN` / `CLOSED`
- **PRESENCE** (radar mmWave en la habitación): valores `PRESENT` / `ABSENT`

Una **anomalía** es cualquier secuencia de eventos de sensores que viola el flujo físico esperado dado el estado actual del sistema. No son bugs del software, sino señales de que algo inesperado está ocurriendo en el mundo físico (fallo de sensor, intrusión, fraude, olvido).

## Catálogo de anomalías (A1–A8)

### A1 — Presencia detectada sin apertura de puerta previa
- **RF-35.A1.1**: El sistema debe detectar cuando `presence_state` transita a `PRESENT` sin que exista un evento `PROXIMITY=OPEN` en la sesión IoT actual.
- **RF-35.A1.2**: La ventana de búsqueda hacia atrás para el evento de apertura debe ser desde `first_entry_at` del stay activo (si existe) o desde la última transición a `FREE` de la room.
- **RF-35.A1.3**: Severidad: **ALTA**.

### A2 — Presencia detectada en habitación sin stay activo
- **RF-35.A2.1**: El sistema debe detectar cuando `presence_state = PRESENT` y la room **no** tiene un stay en estado `OCCUPIED` ni `EXITED` (room efectivamente `FREE`).
- **RF-35.A2.2**: Esta anomalía cubre tanto la presencia inicial sin stay como la persistencia de presencia tras un checkout.
- **RF-35.A2.3**: Severidad: **ALTA**.

### A3 — Apertura de puerta sin QR validado y sin stay activo
- **RF-35.A3.1**: El sistema debe detectar cuando `door_state` transita a `OPEN` en una room cuyo stay más reciente no es `OCCUPIED` ni tiene un QR validado en los últimos N segundos (N = 30s por defecto, configurable).
- **RF-35.A3.2**: Quedan excluidas las aperturas por comando de administración (`LOCK_OPEN` vía dashboard).
- **RF-35.A3.3**: Severidad: **CRÍTICA**.

### A4 — Presencia persistente sin actividad de puerta durante tiempo prolongado
- **RF-35.A4.1**: El sistema debe detectar cuando `presence_state = PRESENT` de forma ininterrumpida durante más de `duracion_minutos + umbral_horas` (umbral por defecto: 2h) sin ningún evento `PROXIMITY` (ni OPEN ni CLOSED) en ese intervalo.
- **RF-35.A4.2**: Debe distinguirse del overstay normal (que también implica exceso de tiempo pero con actividad de puerta normal).
- **RF-35.A4.3**: Severidad: **ALTA** (posible huésped incapacitado o sensor atascado).

### A5 — Salida detectada (stay → EXITED) sin apertura de puerta de salida reciente
- **RF-35.A5.1**: El sistema debe detectar cuando la regla de salida dispara (`stay.status → EXITED`) pero el último `PROXIMITY=OPEN` ocurrió hace más de 2 horas (o nunca ocurrió tras el `first_entry_at`).
- **RF-35.A5.2**: Indica que el sensor de puerta falló en detectar la apertura de salida, o que la regla de salida se disparó incorrectamente por un falso ABSENT del radar.
- **RF-35.A5.3**: Severidad: **MEDIA**.

### A6 — Presencia detectada tras salida (re-entrada no autorizada)
- **RF-35.A6.1**: El sistema debe detectar cuando, tras un `EXITED` confirmado, se recibe un evento `PRESENCE=PRESENT` sin que exista un QR validado que justifique una nueva entrada.
- **RF-35.A6.2**: El periodo de vigilancia post-exit debe ser al menos hasta que la room sea asignada a un nuevo stay (o indefinidamente si la room queda FREE).
- **RF-35.A6.3**: Severidad: **ALTA**.

### A7 — Puerta abierta con ausencia prolongada (habitación desatendida)
- **RF-35.A7.1**: El sistema debe detectar cuando `door_state = OPEN` y `presence_state = ABSENT` de forma simultánea durante más de `max_open_absent_seconds` (por defecto: 120s).
- **RF-35.A7.2**: No debe activarse durante la salida normal (donde OPEN+ABSENT es transitorio hasta que la puerta se cierra).
- **RF-35.A7.3**: Severidad: **MEDIA**.

### A8 — Flapping de sensor (oscilación rápida de estado)
- **RF-35.A8.1**: El sistema debe detectar cuando un mismo sensor (PROXIMITY o PRESENCE) cambia de estado más de `flapping_threshold` veces dentro de una ventana de `flapping_window_seconds`.
- **RF-35.A8.2**: Umbrales por defecto: 5 transiciones en 30 segundos.
- **RF-35.A8.3**: Severidad: **BAJA** (hardware defectuoso, no evento de negocio).

## Requisitos funcionales del sistema de anomalías

### RF-35.1: Catálogo de anomalías
- **RF-35.1.1**: El sistema debe reconocer los 8 tipos de anomalía (A1–A8) definidos en el catálogo.
- **RF-35.1.2**: Cada tipo de anomalía tiene un código único, nombre, severidad, y descripción.
- **RF-35.1.3**: La severidad sigue la escala: `LOW`, `MEDIUM`, `HIGH`, `CRITICAL`.

### RF-35.2: Detección en tiempo real
- **RF-35.2.1**: La detección de anomalías debe ejecutarse como paso adicional en el pipeline de procesamiento de eventos de sensor (`IotSessionService::processEvent()`), después de actualizar el estado IoT y antes de evaluar la regla de salida.
- **RF-35.2.2**: Las anomalías A4 (presencia sin actividad de puerta), A7 (puerta abierta sin presencia) y A8 (flapping) requieren evaluación periódica además de por evento, ya que dependen de ventanas temporales.
- **RF-35.2.3**: Las anomalías deben detectarse en ambos modos: producción (Tuya/Pulsar) y simulación (`/sim/*`).

### RF-35.3: Estado y ciclo de vida
- **RF-35.3.1**: Cada anomalía detectada debe persistirse con estado: `OPEN`, `ACKNOWLEDGED`, `DISMISSED`.
- **RF-35.3.2**: Una anomalía `OPEN` no debe volver a generarse para la misma room y mismo tipo mientras la anterior no haya sido reconocida (deduplicación por room + tipo + estado OPEN).
- **RF-35.3.3**: Una anomalía `OPEN` debe transitar automáticamente a `DISMISSED` si la condición que la originó deja de cumplirse (ej. A7: se cierra la puerta).

### RF-35.4: Exposición en API
- **RF-35.4.1**: El endpoint `GET /api/v1/rooms/{id}/live` debe incluir las anomalías activas (`OPEN`) para esa room.
- **RF-35.4.2**: Debe existir un endpoint `GET /api/v1/anomalies` con filtros por room, severidad, tipo y estado.
- **RF-35.4.3**: Debe existir un endpoint `POST /api/v1/anomalies/{id}/acknowledge` para marcar una anomalía como revisada.

### RF-35.5: Trazabilidad y auditoría
- **RF-35.5.1**: Cada anomalía debe registrar `detected_at`, `room_id`, `stay_id` (si aplica), `anomaly_type`, y los `context_data` (snapshot del estado IoT en el momento de detección).
- **RF-35.5.2**: Los cambios de estado (`ACKNOWLEDGED`, `DISMISSED`) deben auditarse con `acted_by`.
- **RF-35.5.3**: Las anomalías no bloquean el flujo normal de negocio (QR, exit, lock). Son puramente informativas y de auditoría.

### RF-35.6: Dashboard
- **RF-35.6.1**: El panel debe mostrar un indicador visual de anomalías activas por room (badge/icono en la vista de rooms).
- **RF-35.6.2**: El panel debe tener una vista de lista de anomalías con filtros y capacidad de acknowledge.
- **RF-35.6.3**: Las anomalías de severidad `HIGH` o `CRITICAL` deben destacarse visualmente (color rojo/naranja) respecto a `MEDIUM` (amarillo) y `LOW` (gris).

---

# Fase 36: Monitoreo de batería del sensor de puerta MC400D

## Contexto

El sensor magnético de puerta MC400D (PROXIMITY) es el único dispositivo del sistema que funciona con pilas. La API de Tuya reporta el nivel de batería a través de los siguientes Data Points (DPs):

| DP Code | Descripción |
|----------|-------------|
| `battery_percentage` | Porcentaje de batería (0-100) |
| `battery_state` | Estado cualitativo (low/medium/high) |
| `battery_value` | Voltaje mV de la batería |

Estos DPs llegan en cada push webhook de Tuya (junto con `doorcontact_state` en cada evento de apertura/cierre) y también son consultables vía la API de Tuya `/v1.0/iot-03/devices/{id}/status`.

Actualmente el sistema recibe estos DPs pero los descarta sin persistirlos (clasificados como `INFO_DPS` en `TuyaSensorIngress`).

## Requisitos funcionales

### RF-36.1: Persistencia de batería desde push webhook
- **RF-36.1.1**: Cuando el webhook de Tuya notifica un evento del sensor MC400D que incluya el DP `battery_percentage`, el sistema debe persistir su valor en el campo `battery_pct` del dispositivo correspondiente en la tabla `devices`.
- **RF-36.1.2**: La persistencia debe ocurrir incluso si el resto de DPs del push son solo informativos (sin cambios en `doorcontact_state`).
- **RF-36.1.3**: El valor debe redondearse a entero (0-100).

### RF-36.2: Consulta de batería bajo demanda
- **RF-36.2.1**: Debe existir un endpoint que, dado un `device_id`, consulte la API de Tuya (`GET /v1.0/iot-03/devices/{id}/status`) y devuelva el valor actual de `battery_percentage`.
- **RF-36.2.2**: El valor consultado debe persistirse en `devices.battery_pct` tras cada consulta exitosa.
- **RF-36.2.3**: El endpoint debe funcionar tanto para dispositivos PROXIMITY como PRESENCE (si aplicara en el futuro).

### RF-36.3: Exposición de batería en API de dashboard
- **RF-36.3.1**: El endpoint `GET /dashboard-api/device-status?room_id=N` debe incluir el campo `battery_pct` para cada dispositivo.
- **RF-36.3.2**: El endpoint `GET /dashboard-api/pack-detail?pack_id=N` debe incluir el campo `battery_pct` para cada dispositivo.
- **RF-36.3.3**: El endpoint `GET /api/v1/rooms/{id}/live` debe incluir un campo `battery` con `{device_id, kind, label, pct, state}` para el dispositivo PROXIMITY de la habitación.

### RF-36.4: Umbrales de batería
- **RF-36.4.1**: El sistema debe clasificar el nivel de batería en 4 estados:
  - `"normal"`: `battery_pct > 20`
  - `"low"`: `10 < battery_pct <= 20`
  - `"critical"`: `battery_pct <= 10`
  - `"unknown"`: `battery_pct` es null
- **RF-36.4.2**: Esta clasificación debe aplicarse en todos los endpoints que exponen batería.

### RF-36.5: Panel de control
- **RF-36.5.1**: El CRM Panel debe mostrar, en el modal de detalle de habitación, el nivel de batería del sensor PROXIMITY (si existe en el pack).
- **RF-36.5.2**: El CRM Panel debe ofrecer un botón "Refrescar batería" que llame al endpoint on-demand y actualice el valor mostrado.
- **RF-36.5.3**: El dashboard de habitación (`dashboard.html`) debe mostrar el nivel de batería en el panel de dispositivos o sensores.

---

# Fase 37: Vista de anomalías con filtro de estado

## Contexto

F35 implementó la detección y listado de anomalías, pero la vista del panel solo mostraba anomalías `OPEN`. Al pulsar "Reconocer", la anomalía pasaba a `ACKNOWLEDGED` y desaparecía de la vista sin que el usuario pudiera consultarla después. Además, el worker `anomaly-scanner.php` solo auto-resolvía anomalías `OPEN`, dejando las `ACKNOWLEDGED` en un limbo perpetuo.

## Requisitos funcionales

### RF-37.1: Filtro de estado en el panel
- **RF-37.1.1**: El panel de anomalías debe incluir un filtro desplegable de estado con opciones: Pendientes (OPEN), Reconocidas (ACKNOWLEDGED), Histórico (DISMISSED), Todas (ALL).
- **RF-37.1.2**: El filtro por defecto debe ser OPEN (Pendientes), preservando el comportamiento actual.
- **RF-37.1.3**: Los demás filtros (habitación, tipo, severidad) deben combinarse con el filtro de estado.

### RF-37.2: Visualización adaptativa por estado
- **RF-37.2.1**: Cada fila de la tabla debe mostrar una columna "Estado" con badge de color (rojo=OPEN, naranja=ACKNOWLEDGED, verde=DISMISSED).
- **RF-37.2.2**: Solo las anomalías OPEN deben mostrar el botón "✓ Reconocer". Las ACKNOWLEDGED y DISMISSED deben mostrar quién actuó y cuándo.
- **RF-37.2.3**: El encabezado de la sección debe reflejar el filtro activo ("Anomalías — Pendientes", "Anomalías — Histórico", etc.).
- **RF-37.2.4**: El mensaje de lista vacía debe ser contextual ("Sin anomalías pendientes", "Sin anomalías reconocidas", etc.).

### RF-37.3: Auto-resolución de anomalías ACKNOWLEDGED
- **RF-37.3.1**: El worker `anomaly-scanner.php` debe evaluar tanto anomalías OPEN como ACKNOWLEDGED para auto-dismiss.
- **RF-37.3.2**: Cuando la condición desaparezca, una anomalía ACKNOWLEDGED debe transitar automáticamente a DISMISSED (igual que OPEN).

### RF-37.4: API — filtro "todas"
- **RF-37.4.1**: El endpoint `GET /api/v1/anomalies` debe soportar `status=ALL` para devolver anomalías de todos los estados.
- **RF-37.4.2**: Si no se especifica `status`, el comportamiento por defecto sigue siendo `OPEN` (retrocompatibilidad).

---
# Fase 38: Workers — Trabajadores del hotel con QR maestro

## Contexto

Actualmente el sistema solo gestiona QRs de huésped vinculados a estancias (`stays`). Los trabajadores del hotel (limpieza, mantenimiento, recepción, etc.) necesitan acceder a las habitaciones con un QR maestro permanente, sin estar sujetos a las reglas de estancia (cooldown, stay activo, time slots).

Cada trabajador tiene un QR fijo que actúa como llave universal. El sistema debe registrar cada entrada y salida, mostrar ocupación en tiempo real, y ofrecer métricas y alertas desde el panel CRM.

## RF-W1: CRUD de trabajadores

- **RF-W1.1**: El sistema debe permitir crear trabajadores con: nombre, rol, notas. El QR maestro se genera automáticamente al crear.
- **RF-W1.2**: El token QR se devuelve **una sola vez** en la respuesta de creación (mismo patrón que guest QR). Solo se persiste el hash SHA-256.
- **RF-W1.3**: El sistema debe permitir listar, ver detalle, editar y desactivar (soft delete) trabajadores.
- **RF-W1.4**: Al desactivar un trabajador (`active=false`), su QR debe quedar revocado inmediatamente.

## RF-W2: CRUD de roles de trabajador

- **RF-W2.1**: El sistema debe permitir crear, listar, editar y eliminar roles de trabajador.
- **RF-W2.2**: Cada rol define a qué `room_types` tiene acceso el trabajador (tabla pivote `worker_role_room_types`).
- **RF-W2.3**: No se puede eliminar un rol que tenga trabajadores asignados.

## RF-W3: Validación de QR maestro

- **RF-W3.1**: Endpoint `POST /api/v1/workers/qr/validate` que acepta `{token, room_id, device_id}`.
- **RF-W3.2**: El QR maestro **no** está sujeto a: cooldown de habitación, presencia de stay activo, time slots, ni límite de usos.
- **RF-W3.3**: El QR maestro **sí** verifica: firma HMAC, worker existe + activo, rol permite el room_type de la habitación.
- **RF-W3.4**: La validación exitosa abre la puerta y crea una `worker_session` (entrada).
- **RF-W3.5**: Si el worker ya tiene una sesión activa (no cerrada) en la misma habitación, se rechaza (no puede entrar dos veces sin haber salido).

## RF-W4: QR token del trabajador

- **RF-W4.1**: El token usa el mismo formato JWT que el guest QR, con discriminador `sub: "worker"` y claims: `wid` (worker_id), `jti`, `iat`, `exp`.
- **RF-W4.2**: La expiración del token es de ~10 años (QR fijo, no temporal).
- **RF-W4.3**: El endpoint `POST /api/v1/workers/{id}/qr` regenera el QR (revoca el anterior) y devuelve el nuevo token una sola vez.
- **RF-W4.4**: Al regenerar, se actualiza `workers.qr_jti` y `workers.qr_token_hash`. El token anterior deja de ser válido.

## RF-W5: Sesiones de trabajador (worker_sessions)

- **RF-W5.1**: Cada entrada de un trabajador a una habitación crea una `worker_session` con `entered_at`.
- **RF-W5.2**: La salida se detecta por sensores (no por QR). Cuando se detecta salida, se establece `exited_at` y `exit_kind` (EXIT_RULE o DOOR_EVENT).
- **RF-W5.3**: El sistema debe permitir consultar el histórico de sesiones de un trabajador y de una habitación.

## RF-W6: Regla de salida con trabajadores

- **RF-W6.1**: Escenario A — salida total (door CLOSED + absence sostenida): se cierran todas las `worker_sessions` activas en la habitación (`exit_kind=EXIT_RULE`), y luego se procesa el stay del huésped con el comportamiento actual.
- **RF-W6.2**: Escenario B — solo sale el worker (door CLOSED + presence PRESENT): al detectarse el cierre de puerta, si hay `worker_sessions` activas y presencia sigue detectada, se cierra la sesión más reciente del worker (`exit_kind=DOOR_EVENT`). El huésped se asume que permanece dentro.
- **RF-W6.3**: Se escribe un `access_event` con kind `WORKER_EXIT` por cada worker_session cerrada.

## RF-W7: Ocupación en tiempo real

- **RF-W7.1**: Endpoint `GET /api/v1/rooms/{id}/occupants` devuelve quién está dentro ahora: guests (stay activo) + workers (worker_sessions sin exited_at).
- **RF-W7.2**: Endpoint `GET /api/v1/workers/inside` devuelve todos los workers actualmente dentro de alguna habitación.

## RF-W8: Panel CRM — trabajadores y roles

- **RF-W8.1**: Vista de listado de trabajadores con filtro por rol y estado (activo/inactivo).
- **RF-W8.2**: Formulario de creación/edición con generación de QR (mostrado una sola vez).
- **RF-W8.3**: Vista de gestión de roles con asignación de room_types.
- **RF-W8.4**: Vista de detalle de trabajador con: historial de sesiones (habitación, entrada, salida, duración), contador de habitaciones visitadas hoy, ubicación actual, último acceso, tiempo medio por tipo de habitación.
- **RF-W8.5**: Vista de detalle de habitación enriquecida con: trabajadores que han entrado hoy, tiempo acumulado de servicio, último servicio.

## RF-W9: Alertas de trabajadores

- **RF-W9.1**: Alerta por tiempo excesivo: si un worker lleva más de X minutos (configurable, default 120) en una habitación, se genera una alerta visible en el panel.
- **RF-W9.2**: Alerta por hora extraña: si un worker entra a una habitación fuera del horario laboral configurable (default: 22:00–07:00), se genera una alerta.
- **RF-W9.3**: Las alertas deben ser visibles en el panel y registrarse en el sistema (audit_log o tabla específica).

---

# Fase 39: Identificación de fábrica integrada en ESP32 productivo

## Contexto

La identificación de fábrica se incorpora al sketch productivo existente
`docs/esp32-qr-reader/scanner-relay-prod.ino`; no existe ni se distribuirá un
firmware/sketch aislado para fábrica. Tras obtener WiFi mediante el flujo actual
(NVS + WiFiManager), la placa se anuncia automáticamente al backend para poder
ser identificada antes o después del montaje, sin asociarse automáticamente a
una habitación o pack.

El anuncio es trabajo auxiliar en segundo plano: mientras el backend responda
`PENDING`, se reintenta periódicamente y nunca bloquea QR, USB Host, relé,
GPIO4, watchdog, heartbeats ni command queue. `CLAIMED` detiene los anuncios
solo durante el arranque actual. El backend, no NVS ni el firmware, conserva el
estado autoritativo tras reflasheos, reinicios o borrados de NVS.

## RF-39.1: Identificador físico estable

- **RF-39.1.1**: El sketch productivo debe obtener `chip_id` exclusivamente de `ESP.getEfuseMac()`.
- **RF-39.1.2**: El `chip_id` debe serializarse en formato hexadecimal determinista, lowercase, sin separadores, y ser igual en cada arranque de la misma placa.
- **RF-39.1.3**: SSID, IP, MAC de interfaz WiFi y valores aleatorios no pueden ser la identidad principal.

## RF-39.2: Activación automática integrada

- **RF-39.2.1**: Se reutiliza el provisioning WiFi existente del sketch productivo (credenciales NVS + WiFiManager/AP cuando corresponda); F39 no introduce un modo WiFi ni AP de fábrica separado.
- **RF-39.2.2**: Cuando WiFi esté conectado, el sketch debe programar automáticamente el primer anuncio sin requerir GPIO4, un QR, una habitación o intervención del operador.
- **RF-39.2.3**: El anuncio debe ejecutarse como una máquina de estados temporizada y no bloqueante; conexiones, timeouts y reintentos no pueden usar esperas que impidan el loop operativo.

## RF-39.3: Anuncio idempotente y ciclo `PENDING`

- **RF-39.3.1**: El sketch debe enviar `chip_id` a `POST /api/v1/factory-devices/announce` y tratar cada envío como parte de una única alta lógica idempotente por `chip_id`.
- **RF-39.3.2**: El primer anuncio crea o mantiene un registro `PENDING`; timeout, pérdida de red, reinicio o reintento no crean duplicados.
- **RF-39.3.3**: Mientras una respuesta válida del backend indique `PENDING`, el sketch debe seguir programando anuncios periódicos durante ese arranque.
- **RF-39.3.4**: Los fallos transportables o respuestas no concluyentes deben registrar diagnóstico y reintentarse con intervalo acotado, sin alterar el flujo operativo.
- **RF-39.3.5**: El backend debe registrar como mínimo `chip_id`, `first_announced_at`, `last_announced_at` y `status`; anuncios repetidos preservan los datos de claim.

## RF-39.4: `CLAIMED`, fuente autoritativa y claim manual

- **RF-39.4.1**: Una respuesta válida `CLAIMED` debe detener los anuncios de F39 únicamente hasta que termine el arranque actual, sin detener ninguna capacidad productiva.
- **RF-39.4.2**: El sketch no debe persistir `CLAIMED` como verdad de negocio ni usar NVS para omitir el anuncio de futuros arranques.
- **RF-39.4.3**: Tras reflashear, borrar NVS o reiniciar, la placa debe volver a anunciar su mismo `chip_id`; el backend devuelve el estado persistente y sigue siendo la fuente autoritativa.
- **RF-39.4.4**: El panel CRM debe listar registros `PENDING` por `chip_id` y permitir un claim manual y explícito.
- **RF-39.4.5**: El claim cambia atómicamente `PENDING → CLAIMED`, registra actor y fecha, y crea o vincula un `devices.kind=RPI` con `external_id=chip_id`, sin pack ni habitación.
- **RF-39.4.6**: Un anuncio posterior nunca rebaja `CLAIMED`; repetir el claim devuelve el estado existente sin modificar `claimed_at` ni `claimed_by`.

## RF-39.5: No regresión del sketch productivo

- **RF-39.5.1**: F39 no debe bloquear, desactivar ni cambiar el flujo de QR ni los callbacks USB.
- **RF-39.5.2**: F39 no debe bloquear, desactivar ni cambiar relé, GPIO4/identify, watchdog, heartbeats o command queue.
- **RF-39.5.3**: F39 no debe requerir, asignar ni inferir una habitación o pack desde el anuncio; el claim tampoco asigna pack ni habitación.
- **RF-39.5.4**: El estado de fábrica es observacional y de inventario; no condiciona validación QR, apertura, sensores, sesiones, estancias ni comandos operativos.

## RF-39.6: Persistencia, seguridad y trazabilidad

- **RF-39.6.1**: `PENDING` y `CLAIMED` persisten exclusivamente en backend y sobreviven reinicios de servicio, reflasheos y NVS wipes de la placa.
- **RF-39.6.2**: El registro conserva `chip_id`, `status`, `first_announced_at`, `last_announced_at`, `claimed_at`, `claimed_by` y el `device_id` RPI creado/vinculado por claim.
- **RF-39.6.3**: El anuncio y claim reutilizan autenticación/autorización API existentes, sin incorporar secretos nuevos al sketch ni a estos documentos.
- **RF-39.6.4**: El claim manual queda auditado con `chip_id`, estado anterior/posterior, actor y timestamp.

---

# Fase 40: Credenciales individuales de dispositivos ESP32

## RF-40.1: Enrollment seguro

- **RF-40.1.1**: Cada ESP32 genera una `factory_key` aleatoria una sola vez y la conserva en un namespace NVS separado del namespace de WiFi.
- **RF-40.1.2**: `POST /api/v1/factory-devices/announce` requiere HTTPS y recibe `chip_id` + `factory_key`; no usa una API key compartida.
- **RF-40.1.3**: El backend solo almacena el hash de `factory_key`; nunca persiste ni devuelve la clave plana.
- **RF-40.1.4**: El anuncio es idempotente por `chip_id` y no modifica datos de claim existentes.

## RF-40.2: Claim directo administrativo

- **RF-40.2.1**: El claim requiere únicamente autorización administrativa/sesión y el id del registro; acepta `label` y `pack_id` opcionales.
- **RF-40.2.2**: Usa el `enrollment_key_hash` almacenado por el anuncio; el cliente nunca envía ni recibe `factory_key`.
- **RF-40.2.3**: En una transacción se crea o reutiliza un `api_client` individual y se vincula `devices.api_client_id`; cada cliente solo puede vincularse a un device.
- **RF-40.2.4**: El claim es idempotente; un registro sin hash o un binding incompatible se rechaza con conflicto.
- **RF-40.2.5**: La respuesta y el panel nunca muestran `factory_key` después del claim.

## RF-40.3: Operación y compatibilidad

- **RF-40.3.1**: El firmware usa la clave individual en anuncio y llamadas operativas, sin modificar QR, USB, relé, GPIO4, watchdog, heartbeat ni command queue.
- **RF-40.3.2**: Las rutas que conocen el dispositivo devuelven `device_mismatch` cuando el binding no coincide; clientes legacy sin binding siguen funcionando.
- **RF-40.3.3**: `clearNvsIfNewFirmware()` no borra el namespace de credenciales.
- **RF-40.3.4**: La credencial individual se conserva en NVS para anuncio y operación, pero nunca se muestra por Serial ni WiFiManager; `chip_id` permanece visible.
- **RF-40.3.5**: TLS directo se determina por el runtime; `X-Forwarded-Proto` solo es válido desde `TRUSTED_PROXY_IPS` explícitamente configurados.
- **RF-40.3.6**: El namespace NVS de credenciales no se borra al cambiar firmware; el portal solo se reabre mediante reset de fábrica explícito.

## RF-40.4: Finalización del claim y anuncios posteriores
- **RF-40.4.1**: Un claim válido crea o vincula el RPI y su cliente API, registra auditoría y elimina la fila operativa de `factory_devices` dentro de la misma transacción.
- **RF-40.4.2**: El claim no deja clientes API huérfanos; cada cliente creado queda vinculado a un único RPI o la transacción revierte completamente.
- **RF-40.4.3**: No puede existir más de un RPI para el mismo `ESP.getEfuseMac()` ni más de un cliente vinculado al mismo dispositivo.
- **RF-40.4.4**: Tras consumir la fila, un anuncio con credencial válida devuelve lógicamente `CLAIMED` sin recrear `PENDING`; una credencial inválida no tiene efectos laterales.
- **RF-40.4.5**: Cada sketch nuevo borra únicamente `ssid`, `pass`, `last_ssid` y el marcador de build de `Preferences("cerraduras")`; conserva `device-cred`, `factory_key`, la identidad eFuse y otras claves.
- **RF-40.4.6**: El marcador cambia automáticamente mediante digest del sketch y `__DATE__`/`__TIME__`; compilar y cargar el sketch real es el único requisito, sin editar constantes.
- **RF-40.4.2**: El borrado no elimina ni modifica `audit_log`, el RPI ni su cliente API.
- **RF-40.4.3**: Un anuncio posterior para un `chip_id` vinculado a un RPI devuelve metadatos lógicos `CLAIMED` y no crea `PENDING`.
- **RF-40.4.4**: Una credencial distinta se rechaza sin efectos laterales.
- **RF-40.4.5**: Se preservan compatibilidad, autenticación, auditoría y no regresión.
