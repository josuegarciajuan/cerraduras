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

## Panel /dashboard — Calibración de sensor de presencia (RF-41)

- **RF-41.1**: El panel `/dashboard` debe permitir calibrar, por habitación seleccionada, el **radio de detección** (`far_detection`, en cm) y la **sensibilidad** (`sensitivity`, 0–9) del sensor de presencia Tuya ZY-M100-5 asignado a esa habitación (canonical room→pack→device).
- **RF-41.2**: Cada sensor se asigna a una habitación distinta; la configuración calibrada debe **persistirse por habitación/dispositivo** (`devices.meta_json.calibration`) y recuperarse al reabrir el panel.
- **RF-41.3**: El panel debe mostrar una **insignia en vivo** verde/roja de presencia basada en la **lectura directa al sensor** (polling ~2 s solo mientras el modal está abierto), no en el estado de dominio (que llega vía webhook y puede ir con retardo).
- **RF-41.4**: Semántica OFF: cuando el radio `far_detection ≤ 1` el sensor se considera apagado → presencia efectiva `ABSENT` y la insignia muestra estado `RADIO APAGADO`.
- **RF-41.5**: La calibración **no inyecta** eventos de presencia en `iot_session` ni en el dominio, para no ensuciar anomalías mientras el técnico entra/sale.
- **RF-41.6**: La calibración respeta la **cuota de la API Tuya**: no más de ~1 llamada cada 2 s (poll + escrituras), con backoff y mensaje de espera cuando se agota la cuota.
- **RF-41.7**: Guardas: habitación sin sensor de presencia → aviso y controles deshabilitados; sensor offline → modal de solo lectura con reintento hasta primera lectura OK.

## Panel /dashboard — Estado verídico de dispositivos (RF-42)

- **RF-42.1**: El punto de cada dispositivo en el panel "Dispositivos" (y el indicador del pack) debe reflejar su **estado real de conectividad**, nunca un estado "online" reactivo derivado de un evento/comando cloud aceptado.
- **RF-42.2**: Los dispositivos **ESP32/local** (`RPI`, `SCANNER`, `LOCK`) se verifican de forma **gratuita y periódica** vía heartbeat (`device-heartbeat`) y command-queue `check`; su estado se deriva de `last_seen_at`/check fresco.
- **RF-42.3**: Los dispositivos **Tuya cloud** (`PRESENCE`, `PROXIMITY`, `SWITCH`) consumen **cuota** de la API Tuya; solo se verifican con una **sonda real** (`GET /devices/{id}` → `result.online`) **al cargar el panel y al pulsar "Comprobar dispositivos"**. **NUNCA** en un poll periódico.
- **RF-42.4**: Un dispositivo Tuya **sin sonda fresca** se muestra como **`unknown` ("sin verificar", ámbar)**, no como verde. Solo una sonda real que devuelva `online=true` pinta verde; `online=false` pinta rojo.
- **RF-42.5**: `POST /dashboard-api/ping-all-devices` sondea Tuya solo cuando el cliente envía `verify_tuya:true` (carga/manual). Sin la flag, los Tuya cloud se devuelven como `unknown` **sin consumir cuota** y sin marcarlos online.
- **RF-42.6**: Un comando Tuya "aceptado" por el cloud (que puede encolarlo aunque el dispositivo físico esté apagado) **no** debe marcar el dispositivo como online (`SwitchService` no refresca `last_seen` por ello).
- **RF-42.7**: El auto-recheck periódico del panel (10 s) solo re-comprueba sub-dispositivos ESP32 (`SCANNER`/`LOCK`); nunca dispositivos Tuya cloud.

---

# Fase 41: Robustez del pipeline de sensores y coreografía del panel

## Contexto

Auditoría en producción realizada el 16-sep-2026 sobre la puerta real de la habitación 12
(«PROTO2», pack 305). El flujo físico de entrada/salida y la representación del mismo en el
panel dejan de ser fiables en varios escenarios encadenados.

**Fallos observados por el usuario:**

1. Tras escanear QR → acceso concedido → apertura de puerta → presencia, el monigote debería
   avanzar hasta la altura de la puerta (umbral) y no lo hace de forma fiable.
2. En el escenario anterior, al cerrar la puerta manteniendo presencia, el monigote debería
   quedar dentro de la habitación.
3. Con el monigote ya dentro, al abrir y cerrar la puerta el sensor de puerta no marca
   apertura/cierre hasta resetear la habitación.
4. El poller de presencia no se desactiva en los momentos oportunos: (a) cuando el monigote ya
   está completamente dentro y (b) cuando la habitación queda 100 % vacía.
5. Al abandonar la habitación: con el monigote dentro se abre la puerta → se cierra → empieza
   un temporizador de verificación y el monigote se queda en el umbral; cuando el radar ya no
   detecta presencia, el conteo debe terminar/detenerse, el monigote debe salir fuera y la luz
   debe apagarse.

**Causas raíz confirmadas (evidencia en BD y logs):**

- **RC-1**: actualización no atómica del estado IoT por habitación. Con varios workers PHP
  concurrentes (webhook Tuya de puerta + presencia) y varios procesos de escaneo de salida
  duplicados, la última escritura gana: una escritura de presencia puede arrastrar un estado de
  puerta obsoleto y pisar un cierre recién confirmado.
- **RC-2**: no existe orden temporal ni idempotencia real por evento. Los eventos se aplican en
  orden de llegada, no por su marca de ocurrencia; los reenvíos/duplicados del mensajero se
  procesan como hechos nuevos y pueden llegar en desorden.
- **RC-3 (frontend)**: el cruce por la puerta solo se representa si el estado de puerta está
  abierto; si el evento de apertura se pierde, el avatar salta de «acceso concedido» a «dentro»
  sin pasar por el umbral. Además, la ventana de reciente validación de QR y el instante de
  primera entrada (fijado al validar el QR, antes de entrar) hacen que una apertura tardía se
  confunda con una posible salida, y una bandera de presencia vista durante la apertura queda
  adherida y hace que la verificación de salida se salte.
- **RC-4 (infra)**: procesos de escaneo de salida huérfanos (el arranque no termina los
  wrappers previos), y workers de escaneo de anomalías/overstay que no están corriendo.
- **RC-5 (poller)**: la decisión de capturar depende del estado de puerta; con la puerta
  atascada en abierto el poller captura indefinidamente. La parada «al estar dentro» depende de
  una bandera frágil; no hay condición ligada al estado de dominio (estancia activa/presencia)
  ni al vacío total.
- **RC-6 (salida)**: la evaluación de la regla de salida exige puerta cerrada; con la puerta
  mal el cierre de estancia no dispara y quedan estancia ocupada, luz encendida y monigote en el
  umbral. El reset de habitación tampoco limpia la marca temporal de último cierre.

**Decisiones aprobadas por el usuario:**

- Alcance del cambio: backend + poller de presencia + panel/frontend + infraestructura de workers.
- La habitación se confirma vacía cuando (precondición) hubo presencia y la puerta se abrió y se
  cerró, y después el radar reporta ausencia; entonces se espera un intervalo prudencial
  («unos segundos», configurable, por defecto el `exit_presence_gap_seconds` de la
  habitación/tipo, 15 s) y solo entonces se cierra la estancia, se saca al monigote fuera y se
  apaga la luz. Si la presencia reaparece antes, se cancela y la habitación vuelve a ocupada.
- Coreografía de entrada: el monigote permanece en el umbral (a la altura de la puerta) mientras
  la puerta está abierta, y avanza a dentro cuando la puerta se cierra (con presencia confirmada
  o presencia vista durante la apertura).
- Se autoriza reiniciar workers y limpiar procesos huérfanos.

## RF-43: Consistencia atómica del estado IoT por habitación

### RF-43.1: Serialización y atomicidad por habitación
- **RF-43.1.1**: Todo evento de sensor (puerta o presencia) de una misma habitación debe aplicarse de forma serializada y atómica sobre el estado IoT de esa habitación; dos eventos concurrentes de la misma habitación no pueden intercalar su ciclo de lectura-modificación-escritura.
- **RF-43.1.2**: Toda actualización debe afectar únicamente al estado derivado del evento recibido; no puede reescribir ni sobrescribir estados derivados de otros sensores con una copia obsoleta.
- **RF-43.1.3**: Una escritura originada por un evento antiguo no puede revertir una transición más reciente ya aplicada (por ejemplo, una lectura de presencia no puede volver a marcar la puerta en un estado anterior al último cierre confirmado).
- **RF-43.1.4**: La aplicación de un evento es todo-o-nada: si no puede aplicarse de forma consistente, no debe dejar el estado parcialmente modificado.
- **RF-43.1.5**: El sistema debe garantizar estas propiedades con el número real de productores concurrentes en operación (webhook de puerta, webhook/poll de presencia y procesos de escaneo de salida).

### RF-43.2: Fuente única de verdad y concurrencia entre productores
- **RF-43.2.1**: El estado IoT por habitación debe tener un único punto de escritura autoritativo; ni los webhooks de sensores ni los workers de escaneo pueden escribirlo por caminos independientes que compitan entre sí.
- **RF-43.2.2**: Todos los productores deben aplicar sus eventos a través de ese punto único.
- **RF-43.2.3**: Toda decisión de dominio (regla de salida, coreografía, poller) debe basarse en el estado más reciente confirmado; no se admiten decisiones tomadas sobre copias desactualizadas.

### RF-43.3: Verificación de no regresión
- **RF-43.3.1**: Sometido a ráfagas de eventos de puerta y presencia concurrentes, el estado final debe corresponder a la secuencia temporal real de los hechos y no a la del último escritor.
- **RF-43.3.2**: No debe observarse ningún caso en que un cierre de puerta confirmado quede sobrescrito por una escritura posterior que no aporte un hecho de puerta más nuevo.

## RF-44: Orden temporal e idempotencia de eventos de sensor

### RF-44.1: Orden por marca temporal de ocurrencia
- **RF-44.1.1**: Los eventos de sensor deben aplicarse según su marca temporal de ocurrencia (`occurred_at`), no según el orden de llegada al backend.
- **RF-44.1.2**: Un evento cuya marca temporal sea anterior a la última transición aplicada para ese mismo sensor debe considerarse atrasado y no puede modificar el estado actual.
- **RF-44.1.3**: Cuando el orden relativo entre sensores distintos sea determinante para la coreografía de una misma habitación, la aplicación debe respetar dicho orden temporal.

### RF-44.2: Idempotencia y descarte de duplicados
- **RF-44.2.1**: Cada evento de sensor debe tener una identidad lógica que permita reconocer reenvíos o duplicados del mismo hecho físico, aunque cambie la marca temporal del mensajero.
- **RF-44.2.2**: Procesar dos veces el mismo hecho físico no puede producir una segunda transición de estado ni duplicar efectos de dominio.
- **RF-44.2.3**: Los duplicados y los eventos atrasados deben descartarse de la aplicación sin corromper el estado ni bloquear el resto del pipeline.

### RF-44.3: Registro íntegro del evento bruto
- **RF-44.3.1**: Todo evento de sensor recibido (incluidos duplicados, atrasados o descartados) debe registrarse de forma íntegra como evidencia bruta de auditoría.
- **RF-44.3.2**: Para cada evento debe poder distinguirse si fue aplicado o descartado y el motivo (duplicado, atrasado, inconsistente).
- **RF-44.3.3**: La recepción y el registro del evento bruto no deben depender de que su aplicación al estado tenga éxito.

## RF-45: Desactivación del poller de presencia basada en estado de dominio

### RF-45.1: Condiciones de parada
- **RF-45.1.1**: El poller de presencia debe detener sus llamadas al sensor (y por tanto no consumir cuota) cuando la habitación tenga estancia activa ocupada, presencia confirmada y puerta cerrada (huésped dentro).
- **RF-45.1.2**: El poller debe detenerse cuando no exista estancia activa, la presencia esté ausente y la habitación esté fuera de toda ventana de verificación (habitación 100 % vacía).
- **RF-45.1.3**: El poller solo puede permanecer activo mientras exista una condición de dominio que lo justifique (por ejemplo, entrada en curso o verificación de salida pendiente).

### RF-45.2: Independencia de banderas frágiles
- **RF-45.2.1**: La decisión de capturar debe basarse en el estado de dominio (estancia, presencia, puerta) y no en banderas efímeras que puedan quedar adheridas entre ciclos.
- **RF-45.2.2**: Un cambio de estado de dominio debe reevaluar la captura aunque no haya llegado un nuevo evento de sensor.

### RF-45.3: Watchdog de captura máxima
- **RF-45.3.1**: Debe existir un tiempo máximo de captura continua; al superarse, el poller debe detenerse aunque la condición de dominio aparente justificarlo.
- **RF-45.3.2**: Protege contra puerta atascada o estado inconsistente, impidiendo bucles de captura infinitos.
- **RF-45.3.3**: La superación del máximo debe quedar registrada como señal de diagnóstico.
- **RF-45.3.4**: El watchdog no debe impedir una nueva ventana de captura legítima posterior.

## RF-46: Coreografía de entrada del panel

### RF-46.1: Avance al umbral
- **RF-46.1.1**: Tras un QR validado y la concesión de acceso, el avatar debe avanzar hasta el umbral (altura de la puerta) y permanecer allí mientras la puerta esté abierta.
- **RF-46.1.2**: El avance al umbral debe ocurrir de forma fiable aunque el evento de apertura llegue tarde, se pierda o llegue antes que la validación del QR.
- **RF-46.1.3**: Mientras la puerta esté abierta y exista presencia confirmada o presencia vista durante la apertura, el avatar no debe entrar en la habitación; espera en el umbral.

### RF-46.2: Paso al interior
- **RF-46.2.1**: El paso al interior debe ocurrir cuando la puerta se cierre con presencia confirmada o con presencia vista durante la apertura.
- **RF-46.2.2**: El tránsito por el umbral no debe interpretarse como salida ni disparar la verificación de salida.
- **RF-46.2.3**: Si la puerta no se cierra, el avatar permanece en el umbral; la coreografía no puede saltar directamente de acceso concedido a interior sin transitar el umbral.
- **RF-46.2.4**: Una apertura tardía tras la validación del QR no puede confundirse con una apertura de salida.

### RF-46.3: Consistencia con el estado real
- **RF-46.3.1**: La posición del avatar debe reconciliarse con el estado de dominio real (estancia, puerta, presencia), no solo con eventos sueltos.
- **RF-46.3.2**: Al recargar o resincronizar el panel, la posición debe reflejar el estado real y no una secuencia parcial de eventos.

### RF-46.4: Ventana de verificación de entrada (F42)
- **RF-46.4.1**: Tras un QR validado, una apertura acreditada y el cierre de la puerta, si aún no se ha detectado presencia, el avatar debe permanecer en el umbral (no volver fuera), con la puerta cerrada, símbolo de interrogación y un conteo de espera visible durante `entry_window_seconds` (`room_types.presence_entry_window_seconds`, por defecto 90 s).
- **RF-46.4.2**: Si la presencia se detecta dentro de la ventana, el avatar debe avanzar al interior y la entrada debe consolidarse (aunque el radar confirme después del cierre).
- **RF-46.4.3**: Si la ventana se agota sin presencia, se asume que no entró nadie: el avatar vuelve fuera, la entrada queda sin consolidar y se exige una nueva apertura acreditada para reintentar la entrada.
- **RF-46.4.4**: Mientras la puerta esté abierta con presencia detectada, el avatar espera en el umbral sin contar; al cerrarse la puerta, avanza al interior.
- **RF-46.4.5**: Una entrada sin consolidar no debe mostrar el conteo de salida ni emitir `exit_deadline`.

## RF-47: Verificación y confirmación de salida

### RF-47.1: Precondición de la verificación
- **RF-47.1.1**: La verificación de salida solo puede iniciarse cuando se cumplan como precondición: hubo presencia, la puerta se abrió y después se cerró.
- **RF-47.1.2**: Sin esa secuencia acreditada, la ausencia del radar no debe cerrar la estancia.

### RF-47.2: Espera prudencial tras la ausencia
- **RF-47.2.1**: Detectado el cierre de puerta, si el radar deja de detectar presencia, el sistema debe iniciar un conteo de espera de unos segundos antes de confirmar el vacío.
- **RF-47.2.2**: La duración de la espera debe ser configurable por habitación/tipo, con valor por defecto de 15 segundos (`exit_presence_gap_seconds`, expuesto como `gap_seconds`). **Legacy**: este valor ya **no** gobierna ni la consolidación de entrada ni la guarda de salida; se mantiene por compatibilidad de contrato.
- **RF-47.2.3**: El conteo debe terminar o detenerse en cuanto el radar vuelva a detectar presencia.
- **RF-47.2.4**: La guarda de ausencia sostenida que confirma la salida (`exit_guard_seconds`) debe ser configurable: global mediante `EXIT_ABSENCE_GUARD_SECONDS` (valor por defecto **3 s**) y sobrescribible por habitación mediante `rooms.presence_check_seconds`. La resolución es: override de sala (> 0) → global (> 0) → 3 s.
- **RF-47.2.5**: La guarda de salida (`exit_guard_seconds`, default 3 s) es **independiente** de la ventana de consolidación de entrada (`entry_window_seconds`, `room_types.presence_entry_window_seconds`, default 90 s). `gap_seconds` queda como campo legacy de compatibilidad y no gobierna ninguna de las dos.

### RF-47.3: Cancelación por reaparición de presencia
- **RF-47.3.1**: Si la presencia reaparece antes de agotarse la espera, la verificación debe cancelarse y la habitación debe volver a considerarse ocupada.
- **RF-47.3.2**: La cancelación no debe dejar residuos de estado que impidan una nueva verificación futura.

### RF-47.4: Efectos finales de la salida confirmada
- **RF-47.4.1**: Confirmado el vacío tras la espera, deben ejecutarse los tres efectos: cierre de la estancia, salida del avatar fuera de la habitación y apagado de la luz.
- **RF-47.4.2**: Los efectos deben ser consistentes a nivel de dominio: no puede quedar estancia cerrada con luz encendida ni avatar dentro.
- **RF-47.4.3**: La confirmación de salida no debe depender de que la puerta permanezca en un estado concreto si la secuencia de apertura y cierre ya quedó acreditada.

### RF-47.5: Reset de habitación
- **RF-47.5.1**: La operación de reset de habitación debe limpiar todas las marcas temporales de actividad de puerta y presencia, dejando la habitación lista para una nueva secuencia.
- **RF-47.5.2**: Tras un reset no deben quedar condiciones heredadas que bloqueen la detección de nuevas aperturas y cierres.

## RF-48: Operación de workers

### RF-48.1: Instancia única
- **RF-48.1.1**: Cada worker (exit-scan, overstay-scan, outbox, anomaly-scanner) debe ejecutarse como máximo en una instancia a la vez.
- **RF-48.1.2**: El arranque o reinicio debe terminar los procesos previos, incluidos sus wrappers, antes de lanzar la nueva instancia, sin dejar huérfanos.
- **RF-48.1.3**: anomaly-scanner y overstay-scan deben quedar operativos como parte del arranque normal; su ausencia no puede pasar desapercibida.

### RF-48.2: Visibilidad del estado de los workers
- **RF-48.2.1**: El estado del sistema debe exponer qué workers están corriendo y cuántas instancias de cada uno, de forma consultable.
- **RF-48.2.2**: Una instancia duplicada o ausente debe ser detectable como condición anómala de operación.

### RF-48.3: Separación de responsabilidades de escritura
- **RF-48.3.1**: exit-scan no debe competir en la escritura del estado IoT con el webhook de puerta; su función se limita a disparar o delegar la evaluación, nunca a reescribir el estado de sensores.

### RF-48.4: Idempotencia del reinicio
- **RF-48.4.1**: Reiniciar los workers repetidamente debe converger siempre al mismo estado (una instancia por worker) sin acumular procesos ni efectos duplicados.

## RF-49: Observabilidad y auto-recuperación del panel

### RF-49.1: Resincronización tras pérdida del stream
- **RF-49.1.1**: Si el canal de tiempo real deja de emitir, el panel debe detectar la pérdida y resincronizar el estado real en un tiempo acotado.
- **RF-49.1.2**: La resincronización no debe requerir intervención manual ni recargar la página.
- **RF-49.1.3**: Tras resincronizar, la posición del avatar, la estancia y el estado de sensores mostrados deben corresponder al estado real del backend.

### RF-49.2: Conteo de verificación visible
- **RF-49.2.1**: Mientras exista una verificación activa (de salida o de entrada, RF-46.4), el panel debe mostrar de forma fiable el conteo restante.
- **RF-49.2.2**: El conteo debe reflejar el estado real (inicio, cancelación por reaparición de presencia, confirmación) y no quedar congelado ni mostrarse cuando ya no hay verificación.
- **RF-49.2.3**: El conteo de entrada se ancla al cierre de puerta (`last_close_at`) y usa `entry_window_seconds`; el de salida se ancla a `exit_deadline`, calculado como `last_absent_since + exit_guard_seconds`.

### RF-49.3: Trazabilidad de la coreografía
- **RF-49.3.1**: Las transiciones de coreografía (umbral, interior, verificación de salida, salida confirmada) deben ser observables y diagnosticables a partir de logs o estado consultable.

### RF-49.4: Convivencia con anomalías informativas
- **RF-49.4.1**: Las anomalías A1–A8 siguen siendo informativas y no pueden bloquear ni alterar la coreografía de entrada/salida, la desactivación del poller ni el cierre de estancia.
- **RF-49.4.2**: Ninguna anomalía puede enmascarar la coreografía ni impedir la confirmación de salida cuando se cumplan las precondiciones de RF-47.1.

## RF-50: Frescura y fiabilidad de la puerta (push Pulsar)

### RF-50.1: Sin listas hardcodeadas
- **RF-50.1.1**: El consumer Pulsar debe resolver los dispositivos que reenvía desde la fuente canónica (`devices` → `pack` → `room`), nunca desde una lista de `external_id` hardcodeada.
- **RF-50.1.2**: Cualquier sensor de cualquier pack asignado (p.ej. PROTO2) debe entrar automáticamente en la ruta de tiempo real al reconciliarse la lista.
- **RF-50.1.3**: La reconciliación de la lista debe ser periódica y no bloquear el bucle de mensajes.

### RF-50.2: Instancia única del consumer
- **RF-50.2.1**: El consumer Pulsar debe tener un único dueño (el unit systemd `cerraduras-pulsar-consumer.service`).
- **RF-50.2.2**: El arranque (`start-all.sh`) no debe lanzar un segundo consumer; debe reiniciar el servicio existente.
- **RF-50.2.3**: La parada (`stop-all.sh`) no debe matar por patrón el proceso gestionado por systemd; debe detenerlo por servicio.
- **RF-50.2.4**: `system-status` debe exponer `instances` real y `healthy` del worker `pulsar-consumer`.

### RF-50.3: Recuperación de eventos tras reconexión
- **RF-50.3.1**: El Reader `messageId=latest` pierde mensajes durante el hueco de reconexión; al (re)conectar debe ejecutarse una resincronización puntual del estado de los sensores rastreados.
- **RF-50.3.2**: La resincronización debe estar limitada en frecuencia (rate-limited) para no consumir cuota ante reconexiones frecuentes.
- **RF-50.3.3**: La resincronización debe reenviar el estado por el mismo pipeline de ingesta (deduplicación incluida).

### RF-50.4: Panel sin degradación permanente
- **RF-50.4.1**: Si el stream SSE se cierra por parte del servidor (p.ej. `max_lifetime` de 30 min), el panel debe reconectar SSE automáticamente con backoff.
- **RF-50.4.2**: El panel no puede quedar en modo polling permanente tras un cierre del servidor.

## RF-51: Poller de presencia bajo demanda (ventanas acotadas)

### RF-51.1: Muestreo solo en ventanas justificadas
- **RF-51.1.1**: El poller de presencia solo consulta Tuya dentro de ventanas con justificación de dominio; en reposo (huésped dentro o habitación vacía) no realiza ninguna llamada.
- **RF-51.1.2**: Al **abrirse la puerta** se inicia la ventana de entrada y debe hacerse un muestreo inmediato, sin esperar al throttle.
- **RF-51.1.3**: La ventana de entrada se mantiene hasta detectar presencia o agotar `entry_window_seconds` (configurable, por defecto 90 s).
- **RF-51.1.4**: Al **cerrarse la puerta con presencia confirmada** (huésped dentro) se detiene el muestreo y se asume presencia hasta la siguiente apertura.
- **RF-51.1.5**: Al **cerrarse la puerta sin presencia** se mantiene el muestreo hasta `exit_check_seconds` (configurable; por defecto `gap_seconds + 10 s`) para decidir si la persona salió o solo se abrió y cerró la puerta.
- **RF-51.1.6**: Al cerrarse la puerta tras un **ciclo de salida acreditado** (una apertura posterior a `entry_confirmed_at`, seguida de cierre) el muestreo **no se detiene** aunque `presence_state` sea `PRESENT`: se mantiene hasta observar `ABSENT` o agotar `exit_check_seconds`. Evita perder el `none` real por una lectura `PRESENT` obsoleta o de un objetivo fuera del radio útil (F44+).

### RF-51.2: Frescura dentro de la ventana
- **RF-51.2.1**: Dentro de una ventana activa, el intervalo entre llamadas a Tuya debe ser el mínimo viable sin saturar cuota (objetivo 2 s), nunca el intervalo lento histórico de 5 s.
- **RF-51.2.2**: La ventana debe respetar el watchdog de captura máxima y el cooldown vigentes (RF-45.3).

### RF-51.3: Configurabilidad
- **RF-51.3.1**: `entry_window_seconds` debe ser configurable por tipo de habitación y exponerse en `/live`.
- **RF-51.3.2**: `exit_check_seconds` debe derivarse de la configuración existente del gap de salida y exponerse en `/live`.

## RF-52: Semántica de calibración y detección de presencia

### RF-52.1: `far_detection` es configuración, no señal
- **RF-52.1.1**: `far_detection` (radio de detección, cm) no puede traducirse por sí mismo en `ABSENT`; la regla heredada `far_detection ≤ 1 → ABSENT` queda eliminada del pipeline de producción.
- **RF-52.1.2**: Un payload que solo contenga `far_detection` no debe generar un evento de presencia; se considera no-op.

### RF-52.2: Presencia decidida por `presence_state`
- **RF-52.2.1**: La presencia efectiva se decide exclusivamente por `presence_state`: `presence` o `move` → `PRESENT`; `none` → `ABSENT` (RF-43, 24G V3 incluido).

### RF-52.3: Calibración de rango corto
- **RF-52.3.1**: El panel debe permitir fijar radio (`far_detection`, respetando `dp_caps`) y sensibilidad (`sensitivity`), persistiéndolo por dispositivo/habitación.
- **RF-52.3.2**: Para detección ágil en rango corto (~1 m) con corte eficaz al salir del rango, se usará el menor radio del rango del dispositivo (paso 75 cm en 24G V3) y sensibilidad máxima.
- **RF-52.3.3**: Si el firmware del dispositivo impone un radio mínimo superior (p.ej. el 24G V3 rechaza 75 cm y su mínimo efectivo es 150 cm), se usará ese mínimo con sensibilidad máxima y se documentará la limitación.

### RF-52.4: Prueba de paseo y DP de distancia no fiable
- **RF-52.4.1**: El panel debe ofrecer un modo **prueba de paseo** guiado que muestre en vivo `presence_state` mientras el técnico se coloca en el límite deseado y luego se aleja, para elegir empíricamente `far_detection` (respetando `dp_caps`; mínimo efectivo 150 cm en 24G V3) y `sensitivity`.
- **RF-52.4.2**: El modo prueba de paseo aplica los cambios en vivo (`persist:false`) y solo persiste el snapshot con la acción explícita de guardar; debe respetar el cooldown de cuota de la calibración.
- **RF-52.4.3**: El 24G V3 declara `target_dis_closest` pero **no reporta distancia utilizable** (siempre 0). Ninguna decisión de presencia puede basarse en ese DP ni prometerse umbrales métricos por distancia con este modelo.

# Fase 47: Diagnóstico de latencia end-to-end, robustez de recepción Tuya y arranque consistente

## RF-53: Medición de latencia end-to-end sin consumo de cuota

### RF-53.1: Sonda de latencia medible
- **RF-53.1.1**: Debe existir una sonda que, para cada cambio observable de estado de sensor (`presence_events` y `access_events`), registre: el sello del dispositivo (`tuya_t`), `occurred_at`, `received_at`, el `server_ts` del evento SSE y el instante físico marcado por el operador.
- **RF-53.1.2**: La sonda debe poder marcar eventos físicos (p. ej. `PUERTA_ABRE`, `DELANTE_SENSOR`, `ALEJO`) para comparar el instante real con el de llegada.
- **RF-53.1.3**: La salida debe permitir calcular, por evento, los tramos `físico→dispositivo`, `dispositivo→BD`, `BD→SSE` y total.

### RF-53.2: Sin consumo de cuota IoT Core
- **RF-53.2.1**: La sonda no debe realizar ninguna llamada a la API de Tuya (IoT Core); solo lee SSE y base de datos.
- **RF-53.2.2**: La atribución sensor vs. Tuya vs. panel no puede depender de sondeos REST a Tuya.

### RF-53.3: Grupo de control con el sensor de puerta
- **RF-53.3.1**: El sensor de puerta (`PROXIMITY`) actúa como grupo de control al compartir el mismo camino Tuya (Message Service → consumer → webhook → BD → SSE) y tener un instante físico observable.
- **RF-53.3.2**: Si la puerta reporta en ~segundos y la presencia tarda un orden de magnitud más, el retardo se atribuye al sensor de presencia; si ambos tardan igual, la causa está en Tuya o en el panel.

## RF-54: Salud y auto-recuperación del consumer Pulsar

### RF-54.1: Reconexión rápida acotada
- **RF-54.1.1**: La reconexión base del consumer debe ser ≤1 s, conservando el backoff exponencial hasta 60 s ante fallos persistentes.
- **RF-54.1.2**: El `jitter` debe mantenerse para no sincronizar reintentos.

### RF-54.2: Resync según hueco real
- **RF-54.2.1**: El resync al (re)conectar debe ejecutarse cuando el hueco de conexión haya sido real (duración registrada), no cuando es un parpadeo, sin el bloqueo ciego de 20 s.
- **RF-54.2.2**: El resync sigue reenviando por el pipeline de ingesta (deduplicación incluida).

### RF-54.3: Watchdog de silencio del consumer
- **RF-54.3.1**: Si existen devices rastreados y no se recibe ningún mensaje del WS durante un umbral configurable, el consumer debe forzar la reconexión.
- **RF-54.3.2**: El watchdog no debe dispararse cuando no hay devices rastreados.

### RF-54.4: Observabilidad
- **RF-54.4.1**: El consumer debe registrar la latencia de recepción (`recv - tuya_t`) en milisegundos.
- **RF-54.4.2**: El consumer debe exponer su salud (`connected`, `last_msg_at`) en un fichero de estado legible sin endpoint nuevo.

### RF-54.5: Sin cuota
- **RF-54.5.1**: Ninguna de estas mejoras puede consumir cuota de IoT Core (el Message Service es un canal distinto).

## RF-55: Arranque consistente (sin divergencia de workers)

### RF-55.1: Fuente única del número de workers
- **RF-55.1.1**: El número de workers del API (16) no puede divergir entre `start-all.sh` y `cerraduras-api.service`.
- **RF-55.1.2**: El fallback manual de `start-all.sh` debe usar exactamente los mismos valores que los units systemd.

### RF-55.2: Verificación post-arranque
- **RF-55.2.1**: `start-all.sh` debe comprobar tras el arranque que el pool del API coincide con el esperado y avisar (sin abortar) si no.
- **RF-55.3.1**: El cambio no debe reintroducir la degradación del SSE de F46 (pool agotado por SSE zombie).

## RF-56: Latencia del mecanismo de puerta (sin reuso de TLS)

### RF-56.1: Medición en firmware
- **RF-56.1.1**: El firmware debe registrar el tiempo desde el encolado del QR (callback USB) hasta el inicio del POST (`scan→post`) y el tiempo del POST (`postMs`), para separar espera de bucle de handshake.
- **RF-56.1.2**: La medición debe ser observable por el monitor serie.

### RF-56.2: Prioridad del QR
- **RF-56.2.1**: Con un QR pendiente, el bucle no debe iniciar tareas TLS de mantenimiento (health/heartbeat/announce) que retrasen la validación.
- **RF-56.2.2**: El fix debe ser lógica de bucle, sin cambios en la configuración TLS.

### RF-56.3: No reintroducir reuso de TLS
- **RF-56.3.1**: No se debe habilitar `setReuse(true)` sobre el cliente TLS compartido si puede reintroducir cuelgues.
- **RF-56.3.2**: Si tras medir el handshake sigue siendo dominante, cualquier alternativa se decide con el operador y sin reuso de TLS.

### RF-56.4: Keep-alive TLS con salvaguardas
- **RF-56.4.1**: El firmware puede reutilizar la conexión TLS (keep-alive) para evitar el handshake por petición, **con estas salvaguardas obligatorias**: timeout de socket corto (≤4 s), cierre de la conexión si el hueco entre peticiones supera el umbral (≤60 s), y reintento único con conexión nueva en el path de QR.
- **RF-56.4.2**: Si se acumulan fallos consecutivos con la conexión reutilizada (≥3), el firmware debe desactivar el keep-alive en caliente y volver al modo de conexión nueva.
- **RF-56.4.3**: El servidor debe mantener la conexión el tiempo suficiente para cubrir el intervalo de heartbeat del dispositivo (Apache `KeepAliveTimeout` > intervalo de heartbeat).
- **RF-56.4.4**: El cambio no debe exponer el tráfico en claro: se mantiene HTTPS.

## RF-57: Credibilidad de presencia por contexto

### RF-57.1: Presencia con contexto (v2)
- **RF-57.1.1**: El valor crudo `move` de `presence_state` **no** debe contar como `PRESENT` por sí solo: en los sensores 24G su alcance no respeta `far_detection` y dispara desde el pasillo/exterior.
- **RF-57.1.2 (entrada en curso)**: una transición a `PRESENT` (`presence` o `move`) es creíble si hay una **apertura reciente** y la entrada **aún no está confirmada** (`entry_confirmed_at IS NULL`), dentro de `presence_entry_window_seconds`.
- **RF-57.1.3 (huésped dentro)**: también es creíble si hay **estancia confirmada** y **ninguna apertura posterior** a la confirmación (`last_open_at < entry_confirmed_at`). Una apertura posterior (ciclo de salida) **invalida** el contexto: la presencia de pasillo no re-afirma el estado.
- **RF-57.1.4**: `presence` y `move` comparten la misma regla de credibilidad; `ABSENT` (`none`) siempre se aplica.

### RF-57.2: Contención por contexto
- **RF-57.2.1**: En una habitación sin estancia activa y sin ventana de entrada (FREE), ninguna transición a `PRESENT` debe alterar el estado IoT ni el panel; se audita como `no_context`.
- **RF-57.2.2**: Ningún evento de presencia **descartado** (`applied = 0`) debe llegar a la coreografía del panel: `/live` y el stream SSE solo exponen eventos aplicados en `recent_presence`.

### RF-57.3: Trazabilidad
- **RF-57.3.1**: Los descartes por contexto deben quedar auditados en `presence_events.discard_reason = 'no_context'` (misma transacción, sin pérdida de evidencia).
- **RF-57.3.2**: La decisión debe ser lógica pura y testeable sin hardware ni cuota Tuya.

### RF-57.4: No regresión de entrada/salida
- **RF-57.4.1**: La entrada (QR + apertura + presencia) debe seguir consolidándose de forma ágil dentro de la ventana.
- **RF-57.4.2**: La salida (`none` + ciclo acreditado + CLOSED) no debe verse afectada por `move` de pasillo.
- **RF-57.4.3**: El cambio no debe consumir cuota Tuya (opera sobre el push).

### RF-57.5: Latencia percibida de la puerta (panel)
- **RF-57.5.1**: El panel debe mostrar la puerta como abierta desde el **evento de apertura del relé** (`access_events` `OPEN` OK, ~instantáneo) hasta que el magneto Tuya confirme un `CLOSED` posterior, con timeout de seguridad (12 s).
- **RF-57.5.2**: La animación no debe contradecir el estado real: si llega el `CLOSED` del magneto, la puerta vuelve a cerrada.
