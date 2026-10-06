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
- **RF-44.1.4** *(F58/RF-65)*: La marca temporal debe conservar la **precisión de milisegundos** que entrega Tuya (`status[].t`); comparar a segundos hacía que dos eventos del mismo segundo (p. ej. OPEN+CLOSED de una puerta) se ordenaran por llegada/lock, revirtiendo el cierre. Los eventos con la misma marca de ms se resuelven por llegada.

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
- **RF-49.2.3**: El conteo de entrada se ancla al cierre de puerta (`last_close_at`) y usa `entry_window_seconds`; el de salida arranca en el cierre acreditado con la ventana prudencial de RF-64 (20 s) y **se apoya en `exit_deadline`** (`last_absent_since + exit_guard_seconds`) en cuanto el backend lo emite. *(Extendido por RF-64.)*

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
- **RF-57.5.1**: ~~El panel debe mostrar la puerta como abierta desde el **evento de apertura del relé** (`access_events` `OPEN` OK, ~instantáneo) hasta que el magneto Tuya confirme un `CLOSED` posterior, con timeout de seguridad (12 s).~~ **SUPERSEDED por RF-63.1** (F56): el comando del relé no debe abrir la puerta del croquis; ver Fase 56.
- **RF-57.5.2**: La animación no debe contradecir el estado real: si llega el `CLOSED` del magneto, la puerta vuelve a cerrada. (Reforzado por RF-63.)

# Fase 50: Estado real del SWITCH por push y robustez del consumer Pulsar

## RF-58: Rastreo push del SWITCH sin cuota

### RF-58.1: El SWITCH se rastrea por push
- **RF-58.1.1**: El consumer Pulsar debe rastrear también los devices `kind = SWITCH` (`TRACKED_KINDS` los incluye) y reenviar su push crudo al webhook, para conocer el estado real de la luz (Bug 2) sin depender de comandos.
- **RF-58.1.2**: El resync REST (`GET /devices/{id}/status`) **no** debe sondear el SWITCH (`RESYNC_KINDS = PROXIMITY, PRESENCE`): el estado llega por push y no debe consumir cuota IoT Core.
- **RF-58.1.3**: El `TuyaSensorIngress` debe extraer el DP `switch` o `switch_1` (case-insensitive) y persistir `switch_state` (`ON`/`OFF`) y `switch_state_at` en `devices.meta_json`, devolviendo un no-op auditable para no alterar el pipeline de puerta/presencia.
- **RF-58.1.4**: `/live` y el SSE deben exponer el estado real en `switch_state.state` (`UNKNOWN` si aún no hay push) y `switch_state.state_at`, de forma aditiva.

### RF-58.2: Robustez de recepción del consumer
- **RF-58.2.1**: El watchdog de silencio debe ser un backstop largo (default 15 min, `CONSUMER_SILENCE_MS` configurable): el ping proactivo de 30 s detecta sockets muertos y el silencio en reposo (packs solo-puerta) es legítimo. Un umbral corto provocaba churn de reconexión y huecos donde se perdían eventos de puerta (Bug 5).
- **RF-58.2.2**: Un `error` del WS (p. ej. DNS `getaddrinfo EAI_AGAIN`) debe reprogramar la reconexión aunque no llegue `close`, con guarda anti-dobles-timers y manteniendo el backoff exponencial base 1 s.
- **RF-58.2.3**: El fichero de estado del consumer debe exponer `last_pong_at` (aditivo) como observabilidad; el `pong` **no** debe forzar reconexión.

### RF-58.3: No pérdida de push sin sala (N10)
- **RF-58.3.1**: Si un device Tuya no resuelve a sala, el ingress debe devolver un no-op con `discard_reason='room_not_found'` (logueando `dev`/`kind`) en vez de dejar fluir `room_id = null`; el webhook debe responder **202** y no 500.
- **RF-58.3.2**: Una `NotFoundException` en `processEvent` (p. ej. sala inexistente) debe responder 202 `{accepted:false, discard_reason:'room_not_found'}`. El 500 se reserva para errores inesperados.

---

# Fase 51: Ciclo de vida del QR de huésped — llegada + estancia (Bug 4)

**Motivo**: el QR de huésped se sellaba con `room_type.qr_usage_window_minutes` (p. ej. 30 min)
contado desde la emisión. Un huésped que tardaba en llegar más de esa ventana no podía entrar, y
como el token HMAC no se puede re-firmar, no había forma de alargarlo sin reimprimir. Se define un
ciclo de vida en DOS ventanas consecutivas.

## RF-59: Doble ventana de validez del QR de huésped

### RF-59.1: Ventana de LLEGADA
- **RF-59.1.1**: El QR de huésped es válido desde su emisión hasta el **primer escaneo**, durante
  una ventana global `QR_ARRIVAL_WINDOW_MINUTES` (default **15** min), común a todas las
  habitaciones (no depende del `room_type`).
- **RF-59.1.2**: Si el primer escaneo llega con la ventana de llegada agotada, la validación
  responde **403 `qr_expired`** con detalle `{"window":"arrival"}`.

### RF-59.2: Ventana de USO (estancia, multi-uso)
- **RF-59.2.1**: En el primer escaneo válido el QR pasa a estado **EN USO** (`first_used_at` queda
  fijado) y es válido hasta `first_used_at + stays.duracion_minutos`.
- **RF-59.2.2**: Durante esa ventana el QR permite **reentradas** (mismo stay), sin exigir que el
  stay siga `OCCUPIED` por la marca de uso; el estado del stay sigue validándose
  (`RESERVED | OCCUPIED`).
- **RF-59.2.3**: Agotada la ventana de uso, la validación responde **403 `qr_expired`** con
  detalle `{"window":"usage"}`.
- **RF-59.2.4**: El primer uso se reclama de forma **atómica** (`UPDATE ... WHERE consumed_at IS
  NULL`): solo un llamador concurrente gana y fija `valid_until`.

### RF-59.3: Sello del token HMAC
- **RF-59.3.1**: Como el token no se puede re-firmar, al emitir se sella
  `exp = emisión + (QR_ARRIVAL_WINDOW_MINUTES + duracion_minutos)`, cubriendo ambas ventanas.
- **RF-59.3.2**: `room_type.qr_usage_window_minutes` queda **deprecado** para el QR de huésped:
  no interviene en el sello ni en la validación.

### RF-59.4: Compatibilidad y auditoría
- **RF-59.4.1**: `consumed_at` se conserva como marca del **primer uso** (compatibilidad y
  auditoría), sincronizada con `first_used_at`.
- **RF-59.4.2**: `qr_credentials` incorpora `first_used_at DATETIME(3) NULL` y
  `valid_until DATETIME(3) NULL` (migración `0114_qr_arrival_window.sql`, con backfill idempotente
  desde `consumed_at` y `stays.duracion_minutos`).
- **RF-59.4.3**: El código de error `qr_expired` se mantiene estable; `qr_already_used` deja de
  emitirse en el flujo de huésped.

### RF-59.5: Panel (`qr_status`)
- **RF-59.5.1**: `expired` se recalcula según la ventana vigente (llegada si no hay uso; uso si
  ya se usó).
- **RF-59.5.2**: `qr_status` expone de forma **aditiva** `first_used_at`, `valid_until`,
  `arrival_deadline` e `in_use`, manteniendo `consumed` por compatibilidad.

---

# Fase 52: Cola muerta del outbox (N6)

**Motivo**: los mensajes veneno del `outbox_vb6` (4xx del bridge WS-VB6, p. ej.
`ApiException(client_error) ... HTTP 422`) acababan en `status='FAILED'` con `attempts>=20`.
`GET /health/deep` los contaba como `outbox_failed` y el sistema quedaba **`degraded` para
siempre**, aunque esos mensajes nunca serán aceptados. La cola muerta los hace visibles sin
degradar permanentemente el servicio.

## RF-60: Cola muerta (dead-letter) del outbox WS-VB6

### RF-60.1: Estado terminal `DEAD`
- **RF-60.1.1**: El enum `outbox_vb6.status` incorpora el valor `DEAD`
  (`ENUM('PENDING','SENDING','SENT','FAILED','DEAD')`, migración `0115_outbox_dead_letter.sql`).
- **RF-60.1.2**: `markPermanentlyFailed()` (4xx del bridge) mueve el item a `'DEAD'`.
- **RF-60.1.3**: `markFailed()` mueve a `'DEAD'` cuando `nextAttempts >= 20`; por debajo de ese
  techo sigue reintentando con `status='PENDING'`.
- **RF-60.1.4**: Los items `DEAD` **no** se reintentan automáticamente (`fetchDue` solo procesa
  `PENDING`).

### RF-60.2: Reclasificación idempotente de venenos
- **RF-60.2.1**: La migración `0115` reclasifica a `DEAD` los items existentes con
  `status='FAILED' AND attempts >= 20 AND last_error LIKE '%client_error%'`.
- **RF-60.2.2**: Tanto el `MODIFY` del enum como el `UPDATE` son idempotentes/repetibles.

### RF-60.3: Reintento manual
- **RF-60.3.1**: `POST /admin/outbox/{id}/retry` acepta cualquier estado (`PENDING`, `FAILED` o
  `DEAD`) y reinicia `status='PENDING'`, `attempts=0`, `last_error=NULL`.
- **RF-60.3.2**: `scheduleRetry($idempotencyKey)` permite reencolar también `DEAD`
  (`status IN ('PENDING','FAILED','DEAD')`).

### RF-60.4: Observabilidad sin degradar
- **RF-60.4.1**: `GET /health/deep` mantiene `outbox_failed` como COUNT de `FAILED` con
  `updated_at < NOW() - INTERVAL 1 HOUR` (reintentables) y **degrada** si `> 0`.
- **RF-60.4.2**: `GET /health/deep` añade `outbox_dead` con `count` de `DEAD` y
  `status='ok'` si `0` / `'warning'` si `> 0`, sin alterar `allOk` (informativo, **no** degrada).
- **RF-60.4.3**: El contrato de `/health/deep` es aditivo: no se rompe la semántica previa.
- **RF-60.4.4**: El panel de administración puede listar/reintentar los items `DEAD`
  (resumen de estados incluye `dead`).

---

# Fase 54: Batería de aceptación E2E (BLOCK 42)

**Motivo**: la suite cubre cada pieza por separado (unitarias y bloques por fase), pero no existía
una prueba que recorriera el **ciclo de vida completo de un huésped** encadenando los endpoints
reales con los simulados `/sim/*`. Una regresión de integración (p. ej. que la salida F49 no
dispare tras una entrada F42, o que el QR de F51 no transicione RESERVED→OCCUPIED antes de la
coreografía) podía pasar desapercibida aunque cada bloque aislado siguiera en verde.

**Sala de banco**: PROTO2 (`rooms.id=12`, `code='PROTO2'`, pack `305`, RPI `a0e549858428`,
`room_type` STANDARD con franja RENTABLE 24/7).

## RF-61: Batería de aceptación end-to-end determinista (BLOCK 42)

### RF-61.1: Objetivo y alcance
- **RF-61.1.1**: debe existir un único bloque secuencial de aceptación (`BLOCK 42` en
  `api/bin/run-tests.sh`) que recorra el ciclo completo de un huésped sobre PROTO2, compartiendo
  el estado (`stay_id`, `jti`, `qr_text`) entre pasos.
- **RF-61.1.2**: el ciclo mínimo es: emisión de QR (crea stay `RESERVED`) → validación de QR +
  apertura → entrada simulada → consolidación de `entry_confirmed_at` → salida simulada →
  `exit_deadline` → confirmación de salida por el worker `exit-scan` → cierre de la estancia.
- **RF-61.1.3**: cada paso asertará tanto la respuesta HTTP como el estado observable en
  `GET /api/v1/rooms/12/live` y/o la BD (`stays`, `qr_credentials`, `iot_sessions`,
  `access_events`).
- **RF-61.1.4**: el bloque es el último de la suite (tras `BLOCK 41`) y no altera la semántica de
  los bloques 1–41.

### RF-61.2: Determinismo, sin hardware y sin cuota
- **RF-61.2.1**: el bloque no depende de hardware físico: la puerta y la presencia se inyectan con
  `POST /sim/rooms/12/door` y `POST /sim/rooms/12/presence` (`provider=SIMULATED`).
- **RF-61.2.2**: el bloque no consume cuota Tuya/IoT Core: con `rooms.simulated_override=1`,
  `LockGatewayFactory` resuelve `SimulatedLockGateway` (no acciona el relé real) y
  `SensorIngressFactory` resuelve el ingress simulado; no se invoca la nube ni el poller.
- **RF-61.2.3**: las esperas son acotadas y con sondeo (poll con timeout), no `sleep` fijos
  arbitrarios; el bloque no queda colgado si una precondición no se cumple.

### RF-61.3: Precondiciones y SKIP explicativo
- **RF-61.3.1**: si falta el servidor HTTP, alguna de las claves `SIM-CLIENT`, `VB6-MAIN`,
  `RPI-DEV` o `ADMIN-CLI`, o el acceso a BD, el bloque completo se marca **SKIP** con motivo
  explícito (nunca FAIL).
- **RF-61.3.2**: si PROTO2 no tiene un **pack con dispositivo `RPI` resoluble**, se hace SKIP del
  bloque con mensaje.
- **RF-61.3.3**: si `POST /api/v1/qr` no devuelve `201` (p. ej. `409 room_busy` o
  `422 slot_not_rentable`), se hace SKIP del flujo completo, no FAIL.
- **RF-61.3.4**: si no hay ningún worker `exit-scan` en ejecución, el bloque arranca uno propio y
  lo detiene en el cleanup.

### RF-61.4: Idempotencia y limpieza
- **RF-61.4.1**: el bloque es repetible: al inicio normaliza PROTO2 (cierra estancias activas,
  limpia `iot_sessions`/`presence_events`, fija `simulated_override=1` y `presence_check_seconds=5`)
  y usa `POST /dashboard-api/rooms/reset` como reset determinista.
- **RF-61.4.2**: al terminar (éxito o fallo) restaura `simulated_override` y
  `presence_check_seconds` al valor previo, cierra su estancia, borra su `iot_sessions`/
  `presence_events`, deja la sala en `FREE` sin `cooldown_until` y mata solo el `exit-scan` que él
  haya arrancado.
- **RF-61.4.3**: las claves de idempotencia se generan por ejecución (`e2e-f54-qr-…-$(date +%s)`,
  `e2e-f54-close-…-$(date +%s)`) para permitir repetición sin colisiones.
- **RF-61.4.4**: se preservan `access_events` y `qr_credentials` como auditoría (patrón `BLOCK 22`).

### RF-61.5: Trazabilidad
- **RF-61.5.1**: el bloque se documenta en `AGENTS.md` (tabla de fases → runner) como
  `F54 / BLOCK 42`.
- **RF-61.5.2**: cada aserción indica el requisito que cubre (F51 para el ciclo de QR, F42 para la
  ventana de entrada, F49/RF-47 para la salida, F41 para la atomicidad/dedup).
- **RF-61.5.3**: el bloque no introduce cambios de contrato de API (ver `contracts.md`, Fase 54).

### RF-61.6: No regresión
- **RF-61.6.1**: `bash bin/run-tests.sh` termina con **0 failures**; el nuevo bloque solo añade
  PASS o SKIP.
- **RF-61.6.2**: el bloque no degrada `health/deep` por su cuenta más allá del efecto real de
  `POST /stays/{id}/close` (encola `stay.closed` en `outbox_vb6`).

---

# Fase 55: Panel de aceptación manual `/pruebas` (dev tool)

**Motivo**: la batería E2E (F54/BLOCK 42) es automática y determinista, pero las pruebas que
requieren hardware real (escaneo físico del QR, apertura del relé, sensor de puerta, presencia
24G, switch) deben ejecutarse a mano. Faltaba una herramienta de campo para recorrerlas, anotar el
resultado de cada una y guardar corridas comparables hasta que todo quede en verde.

## RF-62: Panel de aceptación manual

### RF-62.1: Página y catálogo
- **RF-62.1.1**: debe existir una página pública (`GET /pruebas`, sin auth, coherente con
  `/dashboard` y `/simula`) que liste las **53 pruebas** del protocolo manual de PROTO2.
- **RF-62.1.2**: el catálogo vive versionado en `api/public/assets/acceptance-tests.json` con
  `blocks` y `tests` (campos `id`, `block`, `type`, `title`, `action`, `expected`, `evidence`);
  la página lo carga con `fetch` y muestra un estado de error si falta o está malformado.
- **RF-62.1.3**: cada prueba es marcable como **PASS / FAIL / N/A / Pendiente** con notas libres;
  `N/A` exige nota por defecto.

### RF-62.2: Progreso y navegación
- **RF-62.2.1**: la UI muestra el progreso global (contadores por estado y porcentaje), la tira de
  celdas por prueba, el bloque actual y la posición (`N/53`).
- **RF-62.2.2**: existen "siguiente pendiente", navegación anterior/siguiente, índice por bloques
  y salto directo a una prueba.
- **RF-62.2.3**: el estado se distingue **nunca solo por color** (icono + etiqueta + forma/patrón).
- **RF-62.2.4**: la página es usable en móvil (objetivos táctiles grandes, barra de veredictos fija)
  y en escritorio (índice lateral + detalle).

### RF-62.3: Captura de estado y criterio de cierre
- **RF-62.3.1**: cada prueba ofrece **📸 Capturar estado**, que adjunta a esa prueba un snapshot de
  `GET /api/v1/rooms/<room>/live` y `GET /api/v1/health/deep` (con `captured_at` y errores si son
  parciales).
- **RF-62.3.2**: el banner **TODO EN VERDE** aparece solo cuando `pending=0` y `fail=0`; por defecto
  `N/A` cuenta como verde (`config.naCountsAsGreen=true`), con modo estricto alternativo.

### RF-62.4: Persistencia y corridas
- **RF-62.4.1**: las corridas se guardan en `api/run/acceptance/<run-id>.json` (fuera de git) a
  través de `POST /dashboard-api/acceptance/save`; `run_id` saneado por whitelist
  `^[A-Za-z0-9_-]{1,64}$` y escritura atómica.
- **RF-62.4.2**: `GET /dashboard-api/acceptance/list` lista las corridas (metadatos y resumen) y
  `GET /dashboard-api/acceptance/get?id=` devuelve una corrida completa.
- **RF-62.4.3**: `DELETE /dashboard-api/acceptance/delete?id=` borra una corrida.
- **RF-62.4.4**: la UI autoguarda (debounce + indicador), permite reanudar una corrida en curso,
  cargar una anterior, exportar JSON/Markdown e imprimir.

### RF-62.5: No regresión
- **RF-62.5.1**: los endpoints existentes no cambian de contrato; las rutas nuevas son aditivas.
- **RF-62.5.2**: `bash bin/run-tests.sh` termina con **0 failures** con el nuevo `BLOCK 43`.
- **RF-62.5.3**: la lógica pura se cubre con `tests/Unit/acceptance-logic.test.js` (sin DOM/red/BD).

# Fase 56: La puerta del croquis sigue al sensor físico (Bug del panel)

**Motivo**: al escanear un QR válido, el panel abría la puerta del croquis en el mismo instante
del comando al relé (`access_events` `OPEN` OK), 5–7 s antes de que la puerta se abriera de
verdad (magneto `PROXIMITY OPEN`). El comando del relé no es apertura física: solo libera el
pestillo. Esto revoca la decisión de RF-57.5.1 (F48/TSK-F48-05).

## RF-63: Puerta visual = evidencia física

### RF-63.1: Al escanear el QR solo cambia el pestillo
- **RF-63.1.1**: al validar un QR, el croquis debe mantener la puerta **cerrada**; el único
  cambio visual de acceso es el **pestillo en verde** (y el toast de acceso concedido).
- **RF-63.1.2**: el comando de apertura (`access_events` `OPEN` OK) **no** debe alterar el
  estado visual de la puerta (arco/hoja) ni encender la bombilla por sí mismo.

### RF-63.2: La puerta se abre con el sensor de puerta
- **RF-63.2.1**: la puerta del croquis se abre **únicamente** con evidencia física:
  `iot_session.door_state = OPEN` (evento `PROXIMITY OPEN` aplicado) o el pulso anti-colapso
  derivado de un `PROXIMITY OPEN` real reciente (`DOOR_PULSE_HOLD_MS = 1200 ms`), que cubre
  `OPEN`+`CLOSED` colapsados en el mismo ciclo SSE.
- **RF-63.2.2**: la puerta vuelve a cerrada con `PROXIMITY CLOSED` (o al expirar el pulso si
  no llega el `CLOSED`).

### RF-63.3: Sin regresiones ni cuota
- **RF-63.3.1**: no cambian contratos de API (`/live`, SSE, `recent_events`, `recent_presence`),
  ni la coreografía del monigote, ni la regla de salida, ni las anomalías.
- **RF-63.3.2**: la decisión visual es una **función pura** testeable sin DOM/red/BD.
- **RF-63.3.3**: no consume cuota Tuya (opera sobre el push y el SSE existentes).
- **RF-63.3.4**: la regresión completa (`bash bin/run-tests.sh`) termina con **0 failures**.

# Fase 57: Verificación de salida visible y coherente (Bug del panel)

**Motivo**: con el huésped dentro, al abrir y cerrar la puerta el panel se quedaba 4–5 s sin
reaccionar (sin timer) y después saltaba de dentro a verificando/fuera. Causas: el conteo de
salida solo existía si ya había `exit_deadline` (es decir, `presence = ABSENT`), y la ventana
interna esperaba un `PRESENT` fresco con un hold corto (6 s) que el radar (4–8 s entre eventos,
13–40 s para reportar ausencia) no cubría.

## RF-64: Ventana de verificación de salida visible

### RF-64.1: La verificación arranca al cerrar
- **RF-64.1.1**: tras un cierre de puerta con ciclo de salida acreditado (apertura posterior a
  `entry_confirmed_at`), el monigote debe pasar **inmediatamente** al umbral con `?`.
- **RF-64.1.2**: durante toda la ventana debe verse un **timer encima de la cabeza** del
  monigote, con independencia de que `presence_state` sea `PRESENT`, `ABSENT` o `UNKNOWN`.

### RF-64.2: Ventana prudencial
- **RF-64.2.1**: la ventana dura **20 s** por defecto, anclada en `last_close_at`
  (`DEFAULT_EXIT_VERIFY_SECONDS` en `assets/choreography.js`), nunca menos que
  `exit_guard_seconds + 3` para no contradecir guardas mayores por sala.
- **RF-64.2.2**: si durante la ventana llega un `PRESENT` nuevo tras el cierre, el `?` se
  mantiene hasta agotar la ventana (espera prudencial completa) y solo entonces el monigote
  vuelve dentro.
- **RF-64.2.3**: si la ventana se agota sin ausencia confirmada (presencia o desconocido), la
  verificación se cancela y el monigote vuelve dentro (`DENTRO`), sin residuos de episodio.

### RF-64.3: La ausencia corta la verificación
- **RF-64.3.1**: cuando el backend emite `exit_deadline` (`last_absent_since + exit_guard_seconds`),
  el timer pasa a ese deadline (manda sobre la ventana local).
- **RF-64.3.2**: al confirmar el dominio la salida (`stay.status = EXITED`), el timer se corta y
  el monigote sale fuera (`SALIDA_CONFIRMADA`); no se inventa la confirmación en el cliente.

### RF-64.4: Sin regresiones ni cuota
- **RF-64.4.1**: la verificación de **entrada** (`VERIFICANDO_ENTRADA`, RF-46.4) no cambia;
  tampoco la puerta/pestillo (RF-63), la luz, las anomalías ni la regla de salida del backend.
- **RF-64.4.2**: no cambian contratos de API; la lógica nueva es **pura y testeable** sin
  DOM/red/BD; no consume cuota Tuya.
- **RF-64.4.3**: la regresión completa (`bash bin/run-tests.sh`) termina con **0 failures**.

# Fase 58: Orden por milisegundos de los eventos de sensor (Bug del panel)

**Motivo**: con el huésped dentro, al abrir y cerrar la puerta, el croquis mostraba a veces el
cierre 4–5 s tarde. El pipeline es rápido (0,1–1,3 s) y el panel pinta de `door_state`; la causa
es que Tuya entrega el sello del dispositivo en milisegundos (`status[].t`) pero el pipeline lo
**truncaba a segundos** y comparaba a segundos, por lo que OPEN+CLOSED del mismo segundo se
ordenaban por llegada/lock (no por ocurrencia) y el OPEN podía aplicarse **después** del CLOSED,
dejando `door_state=OPEN` tras un cierre físico hasta el siguiente evento (3–5 s).

## RF-65: Orden por ocurrencia con precisión de milisegundos

### RF-65.1: Conservación del sello del dispositivo
- **RF-65.1.1**: `occurred_at` de un evento real de Tuya debe conservar los milisegundos del
  sello `status[].t` (13 dígitos); un sello en segundos (10 dígitos) se acepta como fallback.
- **RF-65.1.2**: `presence_events.occurred_at` e `iot_sessions.last_door_event_at` /
  `last_presence_event_at` (ya `DATETIME(3)`) deben almacenar la fracción; sin migración.

### RF-65.2: Decisión por ms
- **RF-65.2.1**: `SensorEventDecision::decide()` debe comparar `occurred_at` en **milisegundos**:
  un evento con ms anterior al último aplicado del mismo sensor es `stale` aunque esté en el
  mismo segundo (p. ej. OPEN llegado tarde tras CLOSED).
- **RF-65.2.2**: El `fingerprint` lógico (`sha1(room|sensor|value|segundo)`) se mantiene a
  segundos para conservar la idempotencia de reenvíos; la igualdad de ms + mismo valor sigue
  siendo `duplicate`.
- **RF-65.2.3**: Sellos convertidos a MySQL (auditoría y estado de sesión) deben preservar la
  fracción; los parsers aceptan ISO-8601 con `.sss`.

### RF-65.3: No regresión
- **RF-65.3.1**: No cambian contratos de forma, rutas, códigos ni la lógica de puerta/presencia,
  la coreografía del panel (F56/F57), la regla de salida, la luz ni las anomalías.
- **RF-65.3.2**: Los eventos simulados (`/sim/*`, `provider=SIMULATED`) y las estancias no se
  ven afectados; la lógica es **pura y testeable**.
- **RF-65.3.3**: La regresión completa (`bash bin/run-tests.sh`) termina con **0 failures**,
  con un caso nuevo que reproduce la llegada invertida del mismo segundo.

# Fase 59: Un QR nuevo limpia el ciclo anterior (Bug del panel)

**Motivo**: tras una prueba completa (salida confirmada, monigote fuera), al generar un nuevo QR
el monigote saltaba dentro. La salida deja `rooms.cooldown_until = +20 s` (anti-reentrada) y
marcas IoT del ciclo anterior; la creación de QR (`/dashboard-api/qr-test/create` y la emisión
real `POST /api/v1/qr`) **no limpiaba** ni el cooldown ni el estado IoT. La coreografía evalúa
`cooldown && (OCCUPIED || RESERVED)` antes del grupo QR → `ANTI_REENTRADA` (monigote `INSIDE`).
Además, tras una salida el panel no muestra QR activo (`qr_status` solo considera estancias
RESERVED/OCCUPIED), por lo que el único botón visible es "➕ Crear QR": la UI empujaba a la ruta
sin reset.

## RF-66: La emisión de un QR nuevo parte de un ciclo limpio

### RF-66.1: Limpieza al crear/emitir
- **RF-66.1.1**: Al crear un QR nuevo (panel de pruebas `POST /dashboard-api/qr-test/create` y
  emisión real `POST /api/v1/qr`), la habitación debe quedar sin cooldown heredado
  (`rooms.cooldown_until = NULL`).
- **RF-66.1.2**: El estado IoT heredado del ciclo anterior debe quedar neutro:
  `door_state`/`presence_state = UNKNOWN` y `last_open_at`, `last_close_at`,
  `last_absent_since`, `last_door_event_at`, `last_presence_event_at`, `last_door_value` y
  `last_presence_value` a `NULL`. La limpieza es **idempotente** y compartida por ambos flujos.
- **RF-66.1.3**: No se crean/alteran estancias, deudas ni credenciales por la limpieza; cada
  flujo conserva sus guardas (`404`/`409`/`422` en el panel de pruebas, `room_busy` en la
  emisión real) y su respuesta sin cambios de forma.

### RF-66.2: Efecto en panel y validación
- **RF-66.2.1**: Tras generar un QR nuevo no debe mostrarse `ANTI_REENTRADA` por el ciclo
  anterior; el panel debe representar el estado del QR nuevo (p. ej. `QR_DISPONIBLE`, fuera).
- **RF-66.2.2**: El QR nuevo debe poder escanearse sin el rechazo `room_cooldown` provocado por
  el ciclo anterior.

### RF-66.3: No regresión
- **RF-66.3.1**: No se altera la semántica de `ANTI_REENTRADA` en la coreografía, ni F56/F57/F58,
  ni la regla de salida, la luz, las anomalías o el resto de endpoints.
- **RF-66.3.2**: La limpieza es una pieza **única y compartida** (interfaz + implementación PDO)
  usada por el panel de pruebas (crear/reset) y por la emisión real; sin SQL duplicado divergente.
- **RF-66.3.3**: La regresión completa (`bash bin/run-tests.sh`) termina con **0 failures**, con
  un caso que ensucia cooldown + IoT y verifica la limpieza al crear el QR (panel y real).

# Fase 60–F66: Almacén de bebidas — tipo `AlmacenBebidas`, cámaras IP y panel `/almacen`

**Motivo**: se quiere controlar un almacén de bebidas con acceso por QR de empleado y evidencia
en vídeo de cada entrada/salida. El sistema ya modela habitaciones, packs de dispositivos,
empleados con QR propio, permisos rol→tipo de habitación, sensores de puerta/presencia y un
pipeline IoT. Falta: un tipo de habitación de almacén, un dispositivo **cámara IP con subtipo**
(EXTERIOR/INTERIOR), **permisos por empleado**, el **registro de visitas con sus vídeos** y un
panel único en `/almacen`.

**Decisiones aprobadas** (2026-09-30): cámaras IP estilo `reconocimientoFacial` (RTSP); directo
con **go2rtc + WebRTC**; retención por defecto **1 día** (pruebas) / sin borrado en real;
permisos **rol + excepción por empleado (la excepción manda)**; panel **público en LAN**;
entrega de esta fase = **especificaciones**.

## RF-67: Tipo de habitación AlmacenBebidas y pack
- **RF-67.1**: Debe existir un tipo de habitación `ALMACEN_BEBIDAS` (código único) con nombre
  "Almacén de bebidas", reutilizando `room_types` (misma validación de código/ventanas).
- **RF-67.2**: El tipo define dos ventanas nuevas: `warehouse_confirm_seconds` (X, por defecto
  **40**) y `warehouse_exterior_margin_seconds` (M, por defecto **5**), validadas de forma pura
  (X ≥ 10 y ≤ 600; M ≥ 0 y ≤ 60).
- **RF-67.3**: Una habitación de tipo AlmacenBebidas es una `rooms` normal (estado, `pack_id`,
  cooldown, QR de huésped/empleado) y recibe un pack de dispositivos, sin campos especiales.
- **RF-67.4**: Debe poder crearse un pack de almacén con `RPI`, `LOCK`, `PROXIMITY`, `PRESENCE`,
  `SWITCH` y **2× `CAMERA`** (una EXTERIOR y una INTERIOR).

## RF-68: Dispositivo cámara IP y subtipo
- **RF-68.1**: `devices.kind` admite `CAMERA`; `Device::KIND_CAMERA` y `Device::allKinds()` lo
  incluyen y `DeviceService::validateKind()` lo acepta.
- **RF-68.2**: La cámara tiene **subtipo de posición** `EXTERIOR` o `INTERIOR`
  (`devices.subtype`), que determina **en qué momento se activa**; un valor distinto se rechaza.
- **RF-68.3**: La conexión RTSP (`rtsp://…`) y flags (`enabled`, `record_enabled`) se guardan en
  `devices.meta_json`, editables desde el panel; **nunca en git** (las URLs contienen credenciales).
- **RF-68.4**: Un pack puede tener más de una cámara
  (`uniq_devices_external(kind, external_id)` lo permite).
- **RF-68.5**: Las cámaras **no** consumen cuota Tuya ni entran en el pipeline ESP32/Tuya: no se
  añaden a `DEVICE_KIND_ESP32`/`DEVICE_KIND_TUYA`, a los pull-kinds, a `TRACKED_KINDS`/
  `RESYNC_KINDS` del consumer Pulsar ni al heartbeat.

## RF-69: Permisos de acceso (rol + excepción por empleado)
- **RF-69.1**: Sin excepción, la decisión es la existente `worker_role_room_types`
  (rol→tipo de habitación: concedido / denegado).
- **RF-69.2**: Se puede registrar una **excepción por empleado** (`worker_room_overrides`) con
  efecto `ALLOW` o `DENY` sobre un tipo de habitación; `UNIQUE(worker_id, room_type_id)`.
- **RF-69.3**: **La excepción del empleado manda sobre su rol**: `ALLOW` añade acceso aunque el
  rol no lo conceda; `DENY` lo quita aunque el rol sí lo conceda.
- **RF-69.4**: La política se aplica en la validación del QR de empleado (`WorkerQrService`)
  **antes** de abrir la puerta; el rechazo conserva el código/motivo existente `access_denied`.
- **RF-69.5**: El panel `/almacen` permite consultar y editar permisos de rol y de empleado.
- **RF-69.6**: La política es pura y testeable (`WarehouseAccessPolicy`); sin excepción el
  comportamiento es idéntico al actual (retrocompatible).

## RF-70: Visitas al almacén
- **RF-70.1**: Cada ciclo de acceso genera una `warehouse_visit` documentada con: empleado (si
  hubo QR), disparador (`QR`/`DOOR`/`PRESENCE`), hora de QR, entrada confirmada, salida y
  resultado (`ENTERED`/`NO_SHOW`/`ANONYMOUS`/`DENIED`).
- **RF-70.2**: La visita agrupa sus grabaciones por cámara y por episodio
  (`ENTRY`/`EXIT`/`PRESENCE`).
- **RF-70.3**: Las visitas son consultables y filtrables por empleado, fecha y resultado.
- **RF-70.4**: Un acceso denegado por permisos se registra como intento `DENIED` con motivo,
  **sin** abrir puerta ni iniciar grabación.
- **RF-70.5**: Una visita `NO_SHOW` (QR/door sin presencia en X) queda documentada con la
  evidencia exterior (caso QR) o sin vídeo (caso door), según RF-71.

## RF-71: Motor de grabación (casos de uso)
**Señales**: `QR_OK`, `DOOR_OPEN`, `DOOR_CLOSE`, `PRESENT`, `ABSENT`. **Ventanas**: X =
`warehouse_confirm_seconds` (40 s), M = `warehouse_exterior_margin_seconds` (5 s).
- **RF-71.1**: QR válido → se inician **ambas** cámaras (episodio `ENTRY`) y arranca la ventana X
  (sin presencia todavía).
- **RF-71.2**: Si en X **no** hay presencia interior → se para y **descarta** la grabación
  INTERIOR; la EXTERIOR **se conserva** (hasta cierre de puerta + M) como evidencia del intento.
  Resultado `NO_SHOW`.
- **RF-71.3**: Si en X **hay** presencia → la entrada se consolida (`ENTERED`) y se sigue grabando
  hasta la salida.
- **RF-71.4**: La puerta abre **sin** QR → se inician ambas; si en X no hay presencia se paran y
  **descartan las dos**. Se refleja como visita (y anomalía `DoorOpenWithoutQr` ya existente).
- **RF-71.5**: Se detecta **presencia sin grabación activa** (p. ej. puerta que quedó abierta de un
  ciclo anterior) → se inician ambas (episodio `PRESENCE`).
- **RF-71.6**: Tras haber habido presencia, al perderse (`ABSENT`): **INTERIOR para ya**;
  **EXTERIOR para M segundos después**; se cierra la visita (`exited_at`).
- **RF-71.7**: Orden por **milisegundos** (F58) y deduplicación de señales (fingerprint);
  los eventos simulados (`/sim`, `provider=SIMULATED`) son soportados.
- **RF-71.8**: La decisión es **pura y testeable** (`WarehouseRecordingDecision`); el servicio
  **ignora** habitaciones cuyo tipo no sea `ALMACEN_BEBIDAS` (no afecta al flujo de huésped).

## RF-72: Directo con go2rtc
- **RF-72.1**: go2rtc se instala como servicio `systemd` y mantiene un stream por cámara a partir
  de su `meta_json.rtsp_url`.
- **RF-72.2**: El panel muestra el directo de ambas cámaras (EXTERIOR/INTERIOR) con indicador de
  grabación por cámara.
- **RF-72.3**: La sincronización de streams con go2rtc ocurre al crear/editar/borrar cámaras y en
  el arranque; **las URLs RTSP no se escriben en logs**.
- **RF-72.4**: El directo es **bajo demanda** (solo mientras hay panel abierto); sin espectadores
  no se decodifica vídeo.

## RF-73: Recorder y retención
- **RF-73.1**: Un daemon `systemd` (`warehouse-recorder`) arranca/para `ffmpeg` por cámara según el
  motor; graba MP4/H.264 en `.tmp` y **renombra al cerrar limpio** (patrón `reconocimientoFacial`).
- **RF-73.2**: El descarte borra el parcial/fichero y marca `DISCARDED`; un error marca `FAILED`
  con motivo; nunca se deja un `.tmp` huérfano.
- **RF-73.3**: Retención configurable en `system_settings` (`warehouse.retention_days`, por defecto
  **1** en pruebas; `0`/negativo = **sin borrado automático**, entorno real).
- **RF-73.4**: Los clips se sirven con soporte **HTTP Range** (seek) y **miniatura** (poster).
- **RF-73.5**: Todo es local; **cero cuota Tuya** y sin dependencia de la nube para grabar.

## RF-74: Panel `/almacen`
- **RF-74.1**: `GET /almacen` sirve una **página única** (una sola vista, sin secciones),
  pública en LAN, servida por el mismo PHP (patrón de `/dashboard`).
- **RF-74.2**: La página muestra: estado del almacén (libre/ocupado), visita activa (empleado y
  tiempo dentro), directo de las 2 cámaras, visitas con sus vídeos de entrada/salida, permisos y
  controles.
- **RF-74.3**: Estado en vivo por **SSE propio** (`/almacen-api/event-stream`) con reconexión y
  fallback a polling; nunca queda en polling permanente.
- **RF-74.4**: Interfaz **sencilla y responsiva**; en MVP LAN **sin login**.
- **RF-74.5**: Endpoints `/almacen-api/*` públicos con forma estable: `state`, `visits`,
  `visits/{id}`, `recordings/{id}/video|poster`, `cameras` (CRUD), `access`, `door/open`,
  `event-stream`.
- **RF-74.6**: Los vídeos de una visita se ven **juntos** (entrada y salida) desde el mismo panel.

## RF-75: Brainstorm — qué más ver/controlar (deseable, no bloqueante)
Requisitos **deseables/futuros** para el mismo panel (lista ampliable):
- **RF-75.1**: Ocupación en vivo, tiempo dentro, última entrada/salida, aforo.
- **RF-75.2**: Métricas: visitas/día, duración media, horas punta, empleado más frecuente.
- **RF-75.3**: Alertas: puerta abierta demasiado, presencia sin QR, cámara offline, grabación
  fallida, retención/espacio en disco.
- **RF-75.4**: Buscar/filtrar visitas (empleado, fecha, resultado) y exportar CSV.
- **RF-75.5**: Miniatura (snapshot) por visita y descarga de clips.
- **RF-75.6**: Historial de accesos denegados con motivo.
- **RF-75.7**: Matriz de permisos rol × empleado editable.
- **RF-75.8**: Salud de cámaras/go2rtc/recorder y uso de disco.
- **RF-75.9**: Abrir puerta, forzar grabación y marcar incidencia.
- **RF-75.10**: Selector de varios almacenes si hubiera más habitaciones del tipo.
- **RF-75.11**: (Futuro) inventario de bebidas, temperatura, audio bidireccional si la cámara lo
  permite.

## RF-76: No regresión y pruebas
- **RF-76.1**: No se alteran rutas, códigos ni campos existentes; el tipo y los permisos nuevos
  son **retrocompatibles** (sin excepción = comportamiento actual).
- **RF-76.2**: Tests unitarios puros (decisión de grabación, política de acceso, validación de
  subtipo) + **BLOCK 44** del runner (HTTP/BD).
- **RF-76.3**: La regresión completa (`bash bin/run-tests.sh`) termina con **0 failures**.

---

# Fase 67: Croquis en vivo del almacén en `/almacen` (RF-77)

**Motivo**: el panel `/almacen` muestra el directo de las cámaras, las visitas y los permisos,
pero no hay una representación visual inmediata del estado físico de la sala. Se quiere un
**croquis compacto y simple** (no el plano detallado del `/dashboard`) que permita ver de un
vistazo si la puerta está abierta, si hay alguien dentro, la luz y las cámaras. Debe integrarse
en el panel existente **sin añadir secciones nuevas**.

## RF-77: Croquis en vivo del almacén
- **RF-77.1**: El panel `/almacen` muestra un **croquis compacto en vivo**, integrado dentro de la
  sección existente **"Directo"** (junto a las cámaras), **sin crear secciones nuevas**. El croquis
  representa: puerta abierta/cerrada, persona dentro/fuera, luz (switch) y cámaras
  (EXTERIOR/INTERIOR, con indicador de grabación).
- **RF-77.2**: El croquis incluye **etiquetas de texto** (p. ej. `PUERTA ABIERTA`, `PRESENTE`,
  `VACÍO`, `LUZ ON/OFF`) y la **última entrada/salida** (`last_open_at`/`last_close_at`), de modo
  que el estado no dependa solo del color.
- **RF-77.3**: `GET /almacen-api/state` expone un bloque **aditivo** `live` con
  `door_state`, `presence_state`, `switch_state`, `last_open_at`, `last_close_at` y
  `last_absent_since` de la sala `ALMACEN_BEBIDAS`, leídos de `iot_sessions` y del
  `devices.meta_json.switch_state` del `SWITCH`. **No se altera ningún campo existente.**
- **RF-77.4**: El SSE `/almacen-api/event-stream` incluye el bloque `live` en su fingerprint, de
  modo que un cambio de puerta/presencia/luz se empuja sin recargar la página.
- **RF-77.5**: La puerta del croquis sigue la **regla F56** (`Choreography.resolveDoorOpen`): se
  abre **solo** con el sensor físico `PROXIMITY` (más pulso anti-colapso derivado de
  `last_open_at`); el comando del relé **no** la abre.
- **RF-77.6**: El croquis es **responsivo** (columna en móvil, lado a lado en escritorio) y
  **accesible**: texto además de color, `role="img"` con `<title>/<desc>`, y respeta
  `prefers-reduced-motion`.
- **RF-77.7**: **No regresión**: no se alteran rutas, códigos ni campos existentes de
  `/almacen-api/*` ni `/api/v1/*`; la regresión completa (`bash bin/run-tests.sh`) termina con
  **0 failures** y un **BLOCK 45** nuevo.

---

# Fase 68: Reproducción de visitas en `/almacen` (RF-78)

**Motivo**: el panel permite ver los clips de una visita, pero no **reconstruir visualmente** lo
que ocurrió. Se quiere poder pulsar **"Reproducir visita"** y ver, sincronizado, al monigote
del croquis escaneando el QR, cruzando la puerta, permaneciendo dentro (con el tiempo dentro
avanzando) y saliendo, mientras el reloj de la visita avanza y las cámaras reproducen sus
grabaciones. Debe ser lo más visual y entendible posible y **no crear secciones nuevas**.

## RF-78: Reproducción de una visita
- **RF-78.1**: Cada visita del listado ofrece una acción **"Reproducir visita"** que activa el
  **modo reproducción** en la sección existente **"Directo"** (croquis + cámaras), sin secciones
  nuevas.
- **RF-78.2**: En modo reproducción, el croquis **anima al monigote** según los hitos de la
  visita: acercamiento al lector, **escaneo del QR**, cruce de la puerta (puerta abierta),
  **estancia dentro** (halo y luz) y salida. El croquis incorpora un **lector QR** junto a la
  puerta.
- **RF-78.3**: Entre el croquis y las cámaras se muestra una **banda de tiempo** con: reloj de la
  **hora real** de la visita (`HH:MM:SS`), hora de **entrada** y **salida**, **contador de tiempo
  dentro** y una **barra con marcadores** (QR · Entrada · Salida) navegable.
- **RF-78.4**: Las **cámaras** (EXTERIOR/INTERIOR) reproducen en modo reproducción los clips
  guardados de la visita, **sincronizados** con el reloj maestra. Sin grabación en un tramo se
  muestra un aviso ("sin grabación").
- **RF-78.5**: Controles: **play/pausa**, **búsqueda** (scrub) y **velocidades 1×/2×/4×/8×**; botón
  **"Volver en vivo"** que restaura el panel en directo.
- **RF-78.6**: Visitas **sin grabaciones** (p. ej. `NO_SHOW` o cámaras desactivadas) se reproducen
  igualmente con croquis + reloj; las cámaras muestran "sin grabación".
- **RF-78.7**: `GET /almacen-api/visits/{id}` añade **aditivamente** `requested_at` a cada
  `recording` (origen de sincronización). No cambia ningún campo ni ruta existente.
- **RF-78.8**: La lógica de reconstrucción es **pura y testeable** (`visit-playback.js`): construye
  la línea de tiempo y resuelve el fotograma por instante sin DOM ni red.
- **RF-78.9**: **No regresión**: no se alteran rutas, códigos ni campos existentes; la regresión
  completa termina con **0 failures** y un **BLOCK 46** nuevo.

---

# Fase 69: Vista en directo y encendido de cámaras en `/almacen` (RF-79)

**Motivo**: al reproducir una visita o si las cámaras están apagadas, no hay una forma directa y
visible de volver al **estado actual** de la sala. Se quiere un botón superior **"Ver en directo"**
que muestre el croquis con el estado real de los sensores (presencia, puerta, posición del
monigote) y las cámaras en directo, **encendiendo las cámaras** si están apagadas.

## RF-79: Vista en directo y encendido de cámaras
- **RF-79.1**: La cabecera del panel `/almacen` ofrece un botón **"Ver en directo"**.
- **RF-79.2**: Al pulsarlo, si había una **reproducción** activa se cierra y se muestra la vista
  en vivo: el croquis con el **estado actual** (presencia, puerta abierta/cerrada y **posición del
  monigote según los sensores**) y las cámaras en directo.
- **RF-79.3**: Si alguna cámara está **apagada** (`devices.meta_json.enabled = false`), el botón
  la **enciende** (`enabled=true`) y **sincroniza go2rtc** para que el directo funcione. Reutiliza
  `PATCH /almacen-api/cameras/{id}` y `POST /almacen-api/cameras/sync` (sin rutas nuevas).
- **RF-79.4**: El botón refleja su estado (**en directo** / **cámaras apagadas**); si hay cámaras
  apagadas, la sección "Directo" muestra un aviso con la acción de encender.
- **RF-79.5**: El botón **no** altera `record_enabled` (encender el directo no cambia la política
  de grabación).
- **RF-79.6**: **No regresión**: no se alteran rutas, códigos ni campos existentes; la regresión
  completa termina con **0 failures** y un **BLOCK 47** nuevo.

---

# Fase 70: Directo de cámaras por MJPEG en `/almacen` (RF-80)

**Motivo**: el iframe de go2rtc (`http://…:1984/stream.html`) se ve **negro** porque el puerto
1984 **no está abierto en el firewall** (ufw) y WebRTC/MSE no es fiable. El proyecto de
**reconocimiento facial** ya resuelve esto con un servidor **MJPEG** propio (un `ffmpeg` por
cámara, `multipart/x-mixed-replace`) mostrado en el navegador con un simple `<img>`. Se replica
ese enfoque en el panel `/almacen`.

## RF-80: Directo de cámaras por MJPEG
- **RF-80.1**: Un **servidor MJPEG** propio (`api/bin/cameras-live.js`) sirve cada cámara del
  almacén como `multipart/x-mixed-replace` a partir de su RTSP, con **un único `ffmpeg` por
  cámara compartido** entre espectadores y parada en reposo (idle).
- **RF-80.2**: El servidor escucha en **loopback** (`127.0.0.1`, puerto configurable, por defecto
  `8086`) y se expone al navegador por **proxy Apache** (`/almacen-live`), usando un puerto ya
  abierto (443/80). **No** se abre ningún puerto nuevo en el firewall.
- **RF-80.3**: El servidor se supervisa con **systemd** (`cerraduras-cameras-live.service`,
  `Restart=always`) e integra en `start-all.sh`/`stop-all.sh`.
- **RF-80.4**: `GET /almacen-api/state` (y `/almacen-api/cameras`) exponen **aditivamente**
  `mjpeg_url` por cámara, construido desde `CAMERAS_LIVE_BASE_URL`.
- **RF-80.5**: El panel `/almacen` muestra el directo con un **`<img>`** apuntando a `mjpeg_url`;
  si una cámara no responde, muestra **"sin señal"** sin romper el resto. Si `mjpeg_url` no está
  configurado, se conserva el iframe de go2rtc como **fallback**.
- **RF-80.6**: La lista de cámaras del servidor MJPEG se refresca periódicamente desde la API
  local (`/almacen-api/state` + `/almacen-api/cameras`), solo cámaras **habilitadas**; no se
  registran credenciales RTSP en logs.
- **RF-80.7**: **No regresión**: no se alteran rutas, códigos ni campos existentes; la regresión
  completa termina con **0 failures** y un **BLOCK 48** nuevo.

---

# Fase 71: Presencia real del almacén y frescura de señal (RF-81 / RF-82)

**Motivo**: en la sala `ALMACEN_BEBIDAS` (PROTO2) el croquis del panel `/almacen` no muestra al
monigote dentro aunque el sensor de presencia 24G sí detecta gente. Los eventos `PRESENT` llegan
por push, pero la credibilidad F48 (`no_context`) los descarta porque la sala no tiene `stays`/QR;
`iot_sessions.presence_state` queda en `ABSENT`. Además, cuando el sensor de puerta deja de
empujar, el croquis afirma `PUERTA CERRADA` con datos antiguos en lugar de indicar "sin datos".
La puerta física reporta CERRADA en Tuya (incidencia de montaje/sensor, fuera de alcance del
software): el panel debe reflejarlo con honestidad y marcar la señal como no fresca.

## RF-81: Presencia real del almacén
- **RF-81.1**: Los eventos `PRESENT` del sensor `PRESENCE` (provider TUYA) en salas
  `ALMACEN_BEBIDAS` son **siempre creíbles**: no se les aplica el filtro de contexto F48
  (`entryWindowActive`/`insideNoExitCycle`) pensado para habitaciones con estancia.
- **RF-81.2**: El croquis muestra al **monigote dentro** cuando `presence_state = PRESENT`, aunque
  no exista una visita de almacén (QR/puerta) activa.
- **RF-81.3**: Los eventos de presencia se propagan al motor de grabación
  (`WarehouseRecordingDecision`: `EV_PRESENT`/`EV_ABSENT`), de modo que un ciclo iniciado por
  presencia es posible.
- **RF-81.4**: En salas `ALMACEN_BEBIDAS` **no** se generan anomalías de huésped (p. ej. A2
  `presence_without_stay`) por la presencia normal del almacén.
- **RF-81.5**: El comportamiento F48 de las habitaciones de huésped **no cambia**.

## RF-82: Frescura de la señal en el croquis
- **RF-82.1**: `GET /almacen-api/state` expone **aditivamente** en `live`
  `door_age_seconds` y `presence_age_seconds`, calculados desde el último evento **recibido** por
  sensor (`presence_events.received_at`); `null` si no hay evento previo.
- **RF-82.2**: El croquis trata la puerta como **"sin datos"** (no como `CERRADA`) cuando la edad
  de la señal supera un umbral de frescura (`DOOR_STALE_SECONDS`). *(Derogado por RF-83.2, F72.)*
- **RF-82.3**: El SSE `/almacen-api/event-stream` incluye los nuevos campos en su fingerprint.
- **RF-82.4**: **No regresión**: no se alteran rutas, códigos ni campos existentes; la regresión
  completa termina con **0 failures** y un **BLOCK 49** nuevo.

---

# Fase 72: Estado persistente de la puerta y resync periódico (RF-83 / RF-84)

**Motivo**: el sensor de puerta MC400D es **edge-triggered**: solo emite al abrir/cerrar. Por eso
un estado `OPEN` (o `CLOSED`) es la **última verdad conocida** y debe permanecer hasta que llegue el
evento contrario; no debe caducar a "sin datos" por el mero paso del tiempo (RF-82.2 resultó
incorrecto). Como contrapartida, si un push se pierde (hueco real observado 09:19–10:20), el estado
se queda congelado en el valor anterior; se añade un **resync REST periódico** del sensor de puerta
para recuperar transiciones perdidas.

## RF-83: El croquis conserva el último estado de puerta conocido
- **RF-83.1**: El chip/estado de puerta del croquis se decide **solo** por el último estado de
  dominio: `OPEN` → `PUERTA ABIERTA` (regla F56), `CLOSED` → `PUERTA CERRADA`.
- **RF-83.2**: El croquis muestra `PUERTA SIN DATOS` **únicamente** cuando nunca hubo estado
  (`door_state` ausente/`UNKNOWN`). La antigüedad de la señal (`door_age_seconds`) **no** degrada
  el chip; queda como dato de diagnóstico.
- **RF-83.3**: El croquis de presencia no cambia: `PRESENT` pinta al monigote dentro.

## RF-84: Resync REST periódico del sensor de puerta  *(DEROGADO por RF-87, F74)*
- **RF-84.1**: El consumer Pulsar sondea por REST (`GET /devices/{id}/status`) solo el sensor
  **PROXIMITY** del almacén cada `CONSUMER_DOOR_RESYNC_MS` (por defecto `600000` ms = 10 min),
  además del resync puntual al (re)conectar el WS.
- **RF-84.2**: El contador de rate-limit del resync periódico es **independiente** del de
  `ws-open`, para no suprimir el resync tras una reconexión.
- **RF-84.3**: El sondeo periódico respeta el **presupuesto de cuota** compartido
  (`api/run/tuya-quota.json`): si el presupuesto horario/diario está agotado, no sondea.
- **RF-84.4**: El resync reenvía el estado por el webhook, de modo que un `OPEN`/`CLOSED` perdido
  se recupera y `door_age_seconds` se refresca. Sin rutas nuevas ni llamadas a Tuya desde el panel.
- **RF-84.5**: **No regresión**: no se alteran rutas, códigos ni campos existentes; la regresión
  completa termina con **0 failures** y un **BLOCK 50** nuevo.

---

# Fase 73: Tope de duración de grabaciones en pruebas y purga del almacén (RF-85 / RF-86)

**Motivo**: en pruebas (`cerraduras.duckdns.org`) las grabaciones de `/almacen` pueden durar toda
la estancia: el motor solo detiene al cambiar de estado y una presencia ruidosa prolonga el clip.
Observado: 22 clips = 912 MB y subiendo. Hasta pasar a producción se limita cada grabación a 1
minuto y se añade una purga total de la sala.

## RF-85: Tope configurable de duración de grabación
- **RF-85.1**: El recorder detiene y **finaliza como `SAVED`** toda grabación en estado
  `RECORDING` cuya `started_at` supere `WAREHOUSE_MAX_RECORDING_SECONDS`.
- **RF-85.2**: El tope aplica por igual a `EXTERIOR` e `INTERIOR`; cada episodio produce como
  máximo un clip acotado (no se encadena hasta que el motor cambie de estado).
- **RF-85.3**: `WAREHOUSE_MAX_RECORDING_SECONDS=0` o ausente = **sin límite** (valor de
  producción). Se lee de `.env`; por defecto `0`.
- **RF-85.4**: La finalización reutiliza el camino existente (`stop_requested` → renombrado,
  póster y `duration_s`); los clips acotados no se marcan como `FAILED`.

## RF-86: Purga total de grabaciones y visitas del almacén
- **RF-86.1**: Existe una herramienta de mantenimiento que elimina, para una sala
  `ALMACEN_BEBIDAS` (o todas), las filas de `camera_recordings`, `warehouse_visits` y
  `warehouse_state`, y los ficheros bajo `data/cameras/<room_id>/`.
- **RF-86.2**: La herramienta solo borra ficheros dentro de `data/cameras/` (anti path-traversal)
  y detiene antes las grabaciones activas para no dejar procesos ffmpeg huérfanos.
- **RF-86.3**: **No regresión**: no se alteran rutas, códigos ni campos de la API existente; la
  regresión completa termina con **0 failures** y un **BLOCK 51** nuevo.
---

# Fase 74: Sin polling periódico que consuma cuota Tuya (RF-87)

**Motivo**: la cuenta de Tuya se factura por llamadas a la API. Cualquier sondeo REST **periódico**
(el resync de puerta de RF-84) gasta créditos de forma continua sin que el usuario lo controle. La
recepción de sensores debe depender **solo de los push del Message Service de Tuya** (sin cuota);
las consultas REST quedan reservadas a acciones explícitas del operador (cargar el panel, pulsar
"Comprobar dispositivos", calibración) y, como máximo, al resync puntual que ya existía al
(re)conectar el WS. Se elimina el resync periódico introducido en F72.

## RF-87: Sin polling periódico de cuota Tuya
- **RF-87.1**: Ningún proceso consulta la API REST de Tuya mediante un temporizador (`setInterval`)
  que consuma cuota. Se elimina el resync periódico del sensor de puerta.
- **RF-87.2**: El estado de puerta y presencia llega por **push** (consumer Pulsar → webhook) y se
  conserva como último estado conocido (RF-83). No se refresca por sondeo temporal.
- **RF-87.3**: Se mantiene **solo** el resync puntual al (re)conectar el WS (comportamiento previo
  a F72), que no es periódico.
- **RF-87.4**: **No regresión**: no se alteran rutas, códigos ni campos existentes; la regresión
  completa termina con **0 failures** y el **BLOCK 50** actualizado.

---

# Fase 75: Refresco de sensores del almacén bajo demanda (RF-88 / RF-89 / RF-90)

**Motivo**: la puerta del almacén aparecía "cerrada/sin datos" estando físicamente abierta porque la
batería E2E (`run-tests.sh`, BLOCK 42) borra `iot_sessions` y `presence_events` de PROTO2 (la sala
del almacén), y el sensor MC400D es edge-triggered: al seguir abierta no reemite, así que el sistema
no puede re-aprender el estado sin una relectura. Además, la presencia del 24G se mostraba con
`move` (movimiento del pasillo), generando monigote dentro sin nadie. Se resuelve sin ningún sondeo
periódico: una lectura puntual al abrir el panel (con cooldown) y un botón manual, y presencia
estricta.

## RF-88: Refresco de sensores bajo demanda (sin cuota periódica)
- **RF-88.1**: `POST /almacen-api/sensors/refresh` (público LAN, como el resto de `/almacen-api`)
  realiza **una** lectura REST del sensor de puerta (`PROXIMITY`) de la sala y la aplica al estado
  de dominio por la ingesta existente (`TuyaSensorIngress` + `IotSessionService`).
- **RF-88.2**: La lectura respeta el **presupuesto/backoff** compartido de Tuya
  (`api/run/tuya-quota.json`) y **nunca** se ejecuta por temporizador; solo por petición del panel.
- **RF-88.3**: Existe un **cooldown** en servidor (`ALMACEN_SENSOR_REFRESH_COOLDOWN_SECONDS`, por
  defecto `1800`) por dispositivo de puerta, persistido en `devices.meta_json.status_probed_at`. Si
  está vigente, responde `throttled` sin gastar crédito.
- **RF-88.4**: El panel `/almacen` realiza **una** lectura al abrirse (tras conectar el SSE) y
  ofrece un botón **"Actualizar estado"** que fuerza la lectura. La respuesta se aplica al croquis.
- **RF-88.5**: La ruta es **aditiva**; no se alteran rutas, códigos ni campos existentes.

## RF-89: Presencia estricta del almacén (solo `presence`, no `move`)
- **RF-89.1**: En salas `ALMACEN_BEBIDAS`, un evento `PRESENT` de presencia solo es creíble si
  `tuya_raw_val === 'presence'`; los `move` se auditan como `no_context` y **no** cambian el estado.
- **RF-89.2**: `ABSENT` (`none`) se sigue aplicando siempre.
- **RF-89.3**: Se mantiene el resto del comportamiento de F48 para habitaciones de huésped.

## RF-90: La regresión no borra el estado del almacén
- **RF-90.1**: `run-tests.sh` (BLOCK 42, E2E PROTO2) **no** borra `presence_events` ni
  `iot_sessions` de una sala `ALMACEN_BEBIDAS`: guarda y restaura la fila `iot_sessions` y conserva
  el historial de eventos.
- **RF-90.2**: **No regresión**: la regresión completa termina con **0 failures** y un **BLOCK 52**
  nuevo.

---

# Fase 76: Presencia del almacén anclada al ciclo de puerta (RF-91 / RF-92)

**Motivo**: el sensor 24G reporta `presence` (no solo `move`) con el almacén vacío, y como F71/F75
la hacían siempre creíble, creaba visitas falsas (`entry_trigger=PRESENCE`) que a su vez
"justificaban" la presencia. Con la puerta abierta el sensor ve el pasillo por el hueco. Se ancla la
presencia al ciclo de puerta (F48 aplicado al almacén) y se impide que la propia presencia ancle la
estancia, **sin ningún sondeo periódico**.

## RF-91: Contexto de puerta para la presencia del almacén
- **RF-91.1**: En `ALMACEN_BEBIDAS`, un `PRESENT` (con `tuya_raw_val="presence"`) solo es creíble si
  hay contexto de ciclo de puerta: apertura/cierre reciente dentro de `presence_entry_window_seconds`
  (entrada en curso) **o** una visita ENTRADA con trigger `DOOR`/`QR` y sin apertura posterior
  (huésped dentro).
- **RF-91.2**: Con `door_state = OPEN`, la presencia **no** es creíble (el 24G puede ver el pasillo a
  través del hueco), evitando el fantasma mientras la puerta está abierta.
- **RF-91.3**: El flujo F48 de habitaciones de huésped no cambia.

## RF-92: La presencia no ancla la estancia
- **RF-92.1**: Para el contexto "huésped dentro" solo cuentan las visitas de almacén con
  `entry_trigger IN ('DOOR','QR')`; una visita creada solo por presencia (`PRESENCE`) **no** ancla la
  credibilidad (rompe la auto-justificación).
- **RF-92.2**: **No regresión**: la regresión completa termina con **0 failures**; el **BLOCK 52**
  se amplía con los marcadores F76.

## RF-93 (F77.1): Los clips del almacén se sirven de verdad
- **RF-93.1**: `GET /almacen-api/recordings/{id}/video|poster` resuelve la ruta relativa desde la raíz
  `api/` (no `api/src/`), de modo que un clip `SAVED` con fichero existente responde 200 (antes siempre 404).
- **RF-93.2**: Se mantiene el bloqueo de path traversal (nada fuera de `data/cameras/`).

## RF-94 (F77.5): Estado de puerta robusto
- **RF-94.1**: `door_age_seconds`/`presence_age_seconds` se calculan desde el último evento **aplicado**
  (`iot_sessions.last_door_event_at`/`last_presence_event_at`), no desde `MAX(received_at)`.
- **RF-94.2**: `GET /almacen-api/state` expone `door_stale` y `door_stale_seconds`; un `OPEN` viejo se
  pinta como `PUERTA SIN DATOS` en el croquis.
- **RF-94.3**: Con sensor de puerta mudo (`> DOOR_STALE_SECONDS`, default 300 s) la presencia **no** se
  veta (se recupera F71). Con sensor fresco y puerta `OPEN` se mantiene la supresión de fantasmas (F76).

## RF-95 (F77.2): Reproducción coherente con el tope de grabación
- **RF-95.1**: El inicio de la fase de salida se ancla a `exited_at` (un clip EXIT explícito manda), no al
  fin del clip INTERIOR; con clips más cortos que la estancia el monigote permanece DENTRO.

## RF-96 (F77.3): Directo estable
- **RF-96.1**: El fingerprint SSE excluye las edades de señal (diagnóstico) y el panel no recrea el DOM de
  cámaras si no cambia nada relevante, evitando reiniciar el MJPEG en cada push.

## RF-97 (F77.4): Sin poll periódico a Tuya
- **RF-97.1**: El watchdog de silencio considera el PONG del ping proactivo como señal de vida; un socket
  sano en reposo no se reconecta (antes cada 15 min → resync REST ~192 llamadas/día).
- **RF-97.2**: El resync puntual REST respeta y contabiliza el presupuesto compartido
  (`api/run/tuya-quota.json`, `TUYA_HOURLY_BUDGET`/`TUYA_DAILY_BUDGET`, `backoffUntil`).

## RF-98 (F77.6): Purga sin ffmpeg huérfanos
- **RF-98.1**: El recorder aborta los ffmpeg gestionados cuyo registro ya no existe en `camera_recordings`.

## RF-99 (F77.7): Estado de luz honesto
- **RF-99.1**: Sin push del SWITCH, `/state` expone `switch_state_inferred` (último comando); el croquis lo
  muestra como estimación (`LUZ ON?`) sin encender el foco.

## RF-100 (F77.8): Detalle de visita persistente
- **RF-100.1**: El refresco periódico de la lista no cierra el detalle de la visita seleccionada.

## RF-101 (F78): Prohibido el sondeo continuo de la API Tuya
- **RF-101.1**: El sistema NO debe mantener ningún proceso que sondee en bucle la API REST de Tuya
  (ni en `start-all.sh`, ni en systemd, ni en cron, ni en workers). El poller de presencia de
  F44/F46 (`presence-poller-manager.sh`, `tuya-presence-poller.js`) queda eliminado por completo.
- **RF-101.2**: El estado de presencia/puerta llega **solo** por push del Message Service (consumer
  `tuya-pulsar-consumer`) o por **sondas REST bajo demanda** (acción explícita del usuario) sujetas
  al presupuesto compartido `api/run/tuya-quota.json`.
- **RF-101.3**: `GET /dashboard-api/system-status` expone **5** workers (sin
  `presence-poller-manager`) y `HealthController` no comprueba ningún poller.
- **RF-101.4**: El runner (`api/bin/run-tests.sh`, BLOCK 35) y `start-all.sh` incluyen una guardia
  que falla/remedia si reaparecen los scripts, un proceso `tuya-presence-poller.js` o el unit
  legacy `cerraduras-presence-poller` habilitado.
- **RF-101.5**: Si un sensor no entrega por push, la solución documentada es ampliar la regla de
  mensajes de Tuya (`design.md` §13.9), nunca reintroducir un poller.

---

# Fase 79: Visitas fantasma del almacén (RF-102)

## RF-102 (F79): Visitas fantasma del almacén
- **RF-102.1**: El motor de grabación del almacén (`WarehouseRecordingService::onSignal`) **NO**
  crea ni modifica visitas ni grabaciones a partir de señales que no sean un ciclo real:
  - eventos con `provider=SIMULATED` (inyecciones `/sim/*` y panel de pruebas), y
  - eventos reenviados por el **resync REST** del consumer Pulsar al (re)conectar
    (`meta.source='resync'`).
  El estado IoT (`iot_sessions`) sí se sigue actualizando; solo se evita el efecto sobre
  `warehouse_visits`/`camera_recordings`/`warehouse_state`.
- **RF-102.2**: El resync del consumer marca los payloads reenviados con `_source='resync'`; el
  ingress Tuya lo propaga a `meta.source` y `IotSessionService` lo entrega al motor del almacén.
- **RF-102.3**: `GET /almacen-api/visits` **oculta por defecto** los intentos sin entrada
  (`outcome='NO_SHOW' AND entry_trigger='DOOR'`), que no tienen vídeo (RF-70.5/71.4). Se pueden
  listar con `include_no_show=1` o filtrando `outcome=NO_SHOW`. Los `NO_SHOW` con `QR`
  (con evidencia exterior) se mantienen visibles.
- **RF-102.4**: El runner de tests (`api/bin/run-tests.sh`) **no contamina** la sala/dispositivo
  de producción:
  - BLOCK 17 usa un device **sintético** para el webhook (nunca `bf4c7e7d2cef28cea2nkwk`).
  - BLOCK 42 (F54) hace snapshot/restore de `warehouse_visits`, `camera_recordings` y
    `warehouse_state` de la sala del almacén y purga los clips generados; si la suite deja
    visitas nuevas, el runner falla.
- **RF-102.5**: Se purgan las visitas fantasma existentes (78/79/81) y sus clips, dejando solo
  las visitas reales.

---

# Fase 80: Presencia del almacén en tiempo real (RF-103)

**Motivo**: el croquis de `/almacen` no refleja la presencia al instante. El motor de grabación ya
acepta QR, puerta o **presencia** como disparadores de visita (RF-71), pero F75/F76 descartan el
`PRESENT` del 24G antes de llegar al motor (con la puerta abierta o sin ciclo de puerta previo) y el
croquis solo pinta el estado final. Requisito del panel: una visita puede empezar al escanear QR, al
abrir la puerta (pueden entrar sin escanear) **o directamente al detectar presencia**, y la puerta
puede quedar abierta a propósito (salir y volver). Se restaura la inmediatez de F71 y se representa el
monigote por fases, tomando como muestra el dashboard operativo (`/dashboard`, sin modificarlo).

## RF-103: Presencia del almacén en tiempo real
- **RF-103.1**: En `ALMACEN_BEBIDAS`, todo `PRESENT` del sensor `PRESENCE` (provider TUYA, valores
  crudos `presence` **o** `move`) es **creíble al instante**: no se aplica el filtro de contexto F48 ni
  el veto de puerta de F76. `ABSENT` (`none`) se aplica siempre (limpia el estado).
- **RF-103.2**: Un `PRESENT` sin ciclo de puerta inicia una visita de almacén
  (`entry_trigger=PRESENCE`, `outcome=ENTERED`) y arranca ambas cámaras
  (`WarehouseRecordingDecision`), también con la puerta abierta (RF-71.5).
- **RF-103.3**: `GET /almacen-api/state` expone **aditivamente** en `live.recent_presence` los últimos
  eventos de presencia **aplicados** (`sensor`, `value`, `occurred_at`); el SSE lo incluye en su
  fingerprint.
- **RF-103.4**: El croquis de `/almacen` refleja la presencia al instante y anima el monigote por
  fases en vivo (`near`, `qr`, `crossing`, `inside`, `outside`), usando `live` + `recent_presence` y
  reutilizando `Choreography.resolveDoorOpen`. La lógica de fases es pura y testeable.
- **RF-103.5**: La representación del dashboard operativo (`/dashboard`) **no se modifica**.
- **RF-103.6**: **No regresión / sin cuota**: no se introduce ningún sondeo periódico de Tuya (RF-101);
  la regresión completa termina con **0 failures** y un **BLOCK 53** nuevo.
- **RF-103.7**: Se **derogan** RF-91.2 (veto de presencia con puerta `OPEN`) y RF-92.1 (la presencia no
  ancla la estancia); RF-91.1/RF-91.3, RF-88 (refresco bajo demanda) y RF-101 quedan vigentes.

---

# Fase 81: Cierre de visita del almacén (RF-104)

**Motivo**: con la puerta abierta y el radar marcando presencia interior, al salir la visita y la
grabación no terminaban hasta que se cerraba la puerta. El operador puede salir y dejar la puerta
abierta a propósito; la salida debe finalizar la visita cuando el radar deja de detectar presencia,
y el cierre de puerta sin presencia debe iniciar la verificación de salida (`EXIT_PENDING`) sin
esperar. El cierre con presencia interior no debe cortar la grabación.

## RF-104: Cierre de visita del almacén
- **RF-104.1**: El **inicio** de visita del almacén funciona con **cualquiera de los 3 disparadores**:
  lectura de QR (`QR_OK`), apertura de puerta (`DOOR_OPEN`) o detección de presencia (`PRESENT`), con
  el comportamiento actual del motor (`QR_PENDING` para QR/puerta; `RECORDING_INSIDE` para presencia).
- **RF-104.2**: En `RECORDING_INSIDE`, la **ausencia del radar** (`ABSENT`) termina la fase interior
  **aunque la puerta siga abierta**: para la cámara interior (`STOP_INT`), marca la salida
  (`MARK_EXIT`) y fija el margen exterior (`SET_DEADLINE_M`) → `EXIT_PENDING`.
- **RF-104.3**: En `RECORDING_INSIDE`, el **cierre de puerta sin presencia interior**
  (`presence_state ≠ PRESENT`) inicia la verificación de salida exactamente igual que `ABSENT`
  (`STOP_INT`, `MARK_EXIT`, `SET_DEADLINE_M`) → `EXIT_PENDING`; el clip exterior continúa hasta agotar
  su margen `M`.
- **RF-104.4**: El **cierre de puerta con presencia interior** (`presence_state = PRESENT`) **no**
  termina la visita ni la grabación: es un no-op y la visita permanece en `RECORDING_INSIDE`.
- **RF-104.5**: El croquis sitúa al **monigote dentro de la habitación** en cuanto se detecta presencia
  (`presence_state=PRESENT`/`occupied`), con la puerta abierta o cerrada; la puerta abierta sin
  presencia se representa como `near`, no como "medio afuera". Deroga **parcialmente RF-103.4**: la
  fase `crossing` deja de usarse en vivo (se conserva solo en la reproducción de visitas, F68).
- **RF-104.6**: **Sin cuota Tuya**: el cierre de visita se decide con el estado de la sesión IoT ya
  mutada por push; no se introduce ningún sondeo periódico ni llamada bajo demanda (hereda RF-101).
- **RF-104.7**: **Alcance**: `QR_PENDING` no cambia (la ventana de llegada X sigue igual; un cierre
  sin presencia en `QR_PENDING` sigue esperando X). Solo cambia `RECORDING_INSIDE`.
- **RF-104.8**: **No regresión**: la regresión completa termina con **0 failures** y el runner
  incorpora los marcadores F81.

---

# Fase 82: Listado de visitas del almacén con presencia (RF-105)

**Motivo**: F80/RF-103 convirtió la detección de presencia en un disparador válido de visita del
almacén (`entry_trigger='PRESENCE'`, con inicio y fin reales según F81/RF-104), pero el listado de
`/almacen` seguía ocultándolas por defecto (F79/RF-102.3). El listado debe reflejar la realidad del
almacén y mostrar esas visitas sin parámetros. Se mantiene la ocultación de los intentos sin entrada.

## RF-105: Listado de visitas del almacén
- **RF-105.1**: `GET /almacen-api/visits` **muestra por defecto** las visitas con
  `entry_trigger='PRESENCE'` (creadas por detección de presencia), sin necesidad de parámetros.
- **RF-105.2**: se **mantiene** la ocultación por defecto de los intentos sin entrada
  (`outcome='NO_SHOW' AND entry_trigger='DOOR'`), visibles con `include_no_show=1`.
- **RF-105.3**: **sin cambio de forma** del contrato (mismos endpoints, campos y tipos); solo cambia
  el conjunto devuelto por defecto.
- **RF-105.4**: **sin cuota Tuya** (RF-101 intacto) y **no regresión** (runner con 0 failures y
  marcadores F82).
- **Nota**: **revisa parcialmente RF-102.3**, que ocultaba por defecto las visitas disparadas solo por
  `PRESENCE`.

---

# Fase 83: Presencia fiel en el panel del almacén (RF-106)

**Motivo**: en `/almacen` el croquis mostraba `PRESENTE` mientras existía una visita enlazada, aunque
el radar ya hubiera reportado `ABSENT`. Evidencia (visita 94, 2026-10-05): `ABSENT` aplicado
14:14:16.247 con la puerta abierta; la visita registró salida 14:14:16.596 y paró la cámara INTERIOR
14:14:17.289; la puerta cerró 14:14:18.942; la cámara EXTERIOR paró 14:14:22.723 (margen). El backend
actuó bien; el defecto era la **representación del panel**: `warehouse.occupied` significaba "visita
activa", no "presencia real", y el croquis dejaba que `occupied`/`recent_presence` taparan un `ABSENT`
confirmado. Revisa parcialmente RF-103.4 y RF-104.5.

## RF-106: Presencia fiel en el panel del almacén
- **RF-106.1**: `ABSENT` manda sobre la visita histórica, sobre `occupied` y sobre cualquier evento
  `PRESENT` anterior. Con `presence_state=ABSENT` el croquis representa al monigote **fuera**, aunque
  exista una visita activa/enlazada o la puerta siga abierta.
- **RF-106.2**: Fidelidad al radar: `PRESENT` → dentro; `ABSENT` → fuera; `UNKNOWN` → chip
  **"SIN DATOS"** y nunca se afirma presencia. Con `UNKNOWN` **y** una visita activa confirmada sin
  salida se permite, como **fallback**, mostrar el muñeco dentro **atenuado** (indicador "SIN DATOS"),
  sin afirmar PRESENCIA. `UNKNOWN` no es del radar: es "sin datos" (nunca llegó evento o se limpió); no
  significa ausencia.
- **RF-106.3**: `ABSENT` cierra la visita, para la cámara INTERIOR al instante y programa la parada de
  la EXTERIOR tras el margen `M` (**10 s** en `ALMACEN_BEBIDAS`) para registrar cómo se va el individuo.
- **RF-106.4**: el cierre de puerta con presencia interior **no** termina la visita (se mantiene
  F81/RF-104.4).
- **RF-106.5**: `warehouse.occupied` pasa a significar **presencia real** (`PRESENT`→`true` /
  `ABSENT`→`false` / `UNKNOWN`→visita activa sin salida). Es un **cambio de semántica de contrato**; el
  único consumidor es el panel `/almacen`.
- **RF-106.6**: **sin cuota Tuya** (RF-101 intacto) y **no regresión**: la regresión completa termina
  con **0 failures** y marcadores F83 (**BLOCK 56**).
- **Nota**: revisa parcialmente **RF-103.4** (la fase ya no se recoloca a `inside` por un `PRESENT`
  anterior) y **RF-104.5** (el fallback `UNKNOWN`+visita activa se muestra atenuado, nunca como
  presencia afirmada).

---

# Fase 84: Antiruido del radar del almacén y reproducción fiel de la puerta (RF-107 / RF-108)

**Motivo**: la madrugada del 2026-10-06 el radar del almacén (24G V3, room 12) emitió 73 secuencias
`move`→`presence`→`none` con la sala vacía; F80 las acepta al instante (con o sin contexto) y cada
una creó una visita `entry_trigger=PRESENCE` y grabó clips (~1,1 GB). El dato crudo de un falso
positivo es **indistinguible** del de una entrada real (mismo DP `presence_state`), y F80 se mantiene
por requisito del operador (la presencia debe crear/grabar con o sin contexto, p. ej. volver a entrar
con la puerta abierta). La separación se hace con **evidencia del propio radar**: una persona en
movimiento reemite transiciones (`move`/`presence`) mientras que el fantasma es un parpadeo único
(1 `move` + 1 `presence`). Además, la reproducción de visitas pintaba la puerta de forma sintética
(`phase=enter/exit`) aunque el sensor MC400D no la hubiese abierto.

## RF-107: Antiruido del radar del almacén (visita provisional + evidencia)
- **RF-107.1**: F80 se mantiene intacto: en `ALMACEN_BEBIDAS` todo `PRESENT` del radar (provider TUYA,
  `presence` **o** `move`) es creíble al instante, con o sin contexto; crea la visita
  (`entry_trigger=PRESENCE`) y arranca ambas cámaras desde el primer segundo. Volver a detectar
  presencia (p. ej. tras dejar la puerta abierta) vuelve a crear visita y grabar.
- **RF-107.2**: Al terminar un episodio de una visita iniciada **solo por presencia**
  (`entry_trigger='PRESENCE'`), el motor clasifica el episodio como **real** si se cumple al menos una
  condición de evidencia:
  - `moves` (eventos `move` distintos) ≥ `warehouse_presence_min_moves` (def. **2**), **o**
  - eventos `PRESENT` distintos (move+presence, incluidos los auditados como `noop`) ≥
    `warehouse_presence_min_events` (def. **3**), **o**
  - duración del episodio ≥ `warehouse_presence_static_seconds` (def. **300 s**), **o**
  - un evento `PROXIMITY` aplicado dentro del episodio (evidencia adicional; **no** es un requisito
    de contexto ni un veto).
- **RF-107.3**: Si el episodio **no** tiene evidencia, la visita pasa a `outcome='NOISE'`, se solicita
  el descarte de sus clips (`discard_requested=1`, el recorder borra los ficheros) y queda **oculta
  por defecto** en `GET /almacen-api/visits` (visible con `include_noise=1` o `outcome=NOISE`). No
  genera basura visible ni conserva vídeo del falso positivo.
- **RF-107.4**: Con evidencia, el comportamiento es el actual: `outcome='ENTERED'`, clips
  conservados, `ABSENT`/`DOOR_CLOSE_ABSENT` → `EXIT_PENDING` con margen `M` (F81/F83).
- **RF-107.5**: Los umbrales son configurables por tipo de sala
  (`room_types.warehouse_presence_min_moves|min_events|static_seconds`, migración `0122`), con los
  valores por defecto anteriores. Ajustarlos no requiere desplegar.
- **RF-107.6**: **Sin tocar el detector** (ni `far_detection`, ni `sensitivity`, ni orientación) y
  **sin cuota Tuya**: la evidencia sale de `presence_events` (push ya persistido) y de la BD.
- **RF-107.7**: Las visitas `NOISE` se conservan como auditoría (no se borran filas); la consulta por
  id sigue disponible.
- **RF-107.8**: **No regresión**: la regresión completa termina con **0 failures**; los tests F80
  existentes permanecen válidos (la credibilidad del radar no cambia) y se añade **BLOCK 57**.

## RF-108: Reproducción fiel de la puerta en las visitas (RF-108)
- **RF-108.1**: `GET /almacen-api/visits/{id}` expone **aditivamente** `visit.door`
  (`{ state_at_start, events[] }`): `state_at_start` es el último valor aplicado de `PROXIMITY`
  anterior al inicio de la visita (`OPEN`/`CLOSED`/`null`) y `events` la lista de eventos
  `PROXIMITY` aplicados (`OPEN`/`CLOSED` + `occurred_at`) que caen en la ventana de la visita.
- **RF-108.2**: `visit-playback.js` deriva `doorOpen` de esos intervalos reales OPEN→CLOSED. **Nunca**
  inventa una apertura: sin eventos de puerta en la visita, la puerta se muestra cerrada. La fase y el
  movimiento del monigote no cambian.
- **RF-108.3**: Compatibilidad: si la respuesta no trae `door` (cliente antiguo), se conserva el
  comportamiento sintético previo como fallback.
- **RF-108.4**: Sin cuota Tuya y sin cambios en el motor ni en el resto de contratos.
