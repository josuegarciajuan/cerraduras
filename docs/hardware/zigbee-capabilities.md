# Capacidades reales de los dispositivos Zigbee del banco — oficina

> **Fecha**: 2026-10-26 · **Estado**: investigación y documentación; **no** hay integración
> en producción ni cambios en el flujo Tuya.
> **Banco**: nodo `oficina` (172.16.51.242), proyecto Compose `zigbee-local` en
> `/opt/cerraduras-zigbee`. Mosquitto 2.0.22 y Zigbee2MQTT 2.14.2, publicados solo en loopback.
> **Coordinador**: SONOFF Dongle-M EFR32MG24 (Ethernet/PoE, 172.16.51.215:6638), EmberZNet
> 7.4.5 / EZSP 13.
> **Regla respetada**: cero llamadas a Tuya cloud, cero pollers y `permit_join: false` durante
> toda la sesión. No se documentan contraseñas, claves de red ni contenido de `.env`.

## 0. Resumen ejecutivo

| Dispositivo | Rol Z2M | Alimentación | Lo confirmado | Lo dudoso / pendiente | Recomendación |
|---|---|---|---|---|---|
| `puerta-oficina` SNZB-04P | EndDevice | Batería | `contact` push (false=cerrado, true=abierto), `tamper`, batería/voltaje, firmware 8704, sin OTA pendiente | Latencia con marcas físicas; recuperar interview tras incidencia; alcance real | **Integrar** como PROXIMITY; **no** consultar por `/get`; **no** hacer polling |
| `presencia-oficina` ZY-M100-S_2 | Router | Red | `presence` push, `illuminance`, ajustes de radar; 0,35 msg/s con presencia, ~1 msg/s sin presencia; `target_distance` solo con movimiento | Retorno a false y falsos positivos en sala vacía; latencia de transición; calibración fina | **Integrar** como PRESENCE con fusión/histéresis y frescura; **calibrar** en la sala real |
| `luz-oficina` ZBMINIL2 | EndDevice | Red | `state` ON/OFF fiable, `power_on_behavior=off`, `/get` ~0,44 s, OTA 4110 sin actualización | Botón físico, S1/S2, corte real, `state_action`, carga mínima/LED | **Integrar** como SWITCH con `optimistic:false` y deduplicación; **no** es router |

**Decisión corta**: los tres dispositivos son utilizables en local y sin cuota. La puerta y el
relé deben tratarse como *push* (la puerta es un EndDevice dormido: no admite lectura remota
fiable). La presencia es un Router con tráfico alto y periódico; hay que filtrar, deduplicar y
añadir frescura antes de usarla en el dominio. `target_distance` no sirve por sí solo para
decidir presencia.

---

## 1. Método, evidencia y límites

### 1.1 Qué se midió y con qué

- Inventario: `zigbee2mqtt/bridge/devices` (definición, `exposes`, `access`, endpoints,
  reportings) y `zigbee2mqtt/bridge/info` (versión, configuración efectiva).
- Estado cacheado de Z2M: `/app/data/state.json` (solo lectura), útil porque los topics de
  estado **no** son retained.
- Topics y payloads: suscripción a `zigbee2mqtt/#` con `mosquitto_sub -F '%U %t %p'` y cliente
  Node `mqtt` dentro del contenedor. Las credenciales se leyeron de variables de entorno del
  contenedor; no se imprimieron ni se pasaron como argumentos en las medidas finales.
- Latencia de comandos: publicación QoS 1 y timestamps `Date.now()` en el mismo host; se mide
  *publicación → primer mensaje de estado*, no la latencia física del sensor.
- Volumen: ventanas de 298,6 s (presencia con `presence=true`) y 73,1 s (presencia forzada a
  `false`), contando mensajes y bytes reales.

### 1.2 Límites de la evidencia

- No se dispone de marcas temporales físicas sincronizadas (apertura/cierre de puerta, entrada
  de una persona) en esta sesión. Por eso **no** se separa sensor/radio/Z2M/MQTT para la puerta
  ni para las transiciones de presencia. Solo se mide el tramo comando→estado del relé.
- La prueba de presencia se interrumpió por un reinicio del nodo `oficina` (recuperado por el
  operador). Los ajustes se restauraron y verificaron después; ver §7.
- `permit_join` permaneció en `false`; no se emparejó, renombró ni actualizó firmware.
- Códigos de acceso de Zigbee2MQTT usados en las tablas: `1` = solo estado (push), `2` = solo
  escritura, `3` = estado+escritura, `5` = estado+consulta (`/get`), `7` = estado+escritura+
  consulta. `R` = lectura por push, `R/W` = lectura/escritura, `R+get` = además admite `/get`.

### 1.3 Evidencia de referencia

| Ref. | Fuente |
|---|---|
| E1 | `zigbee2mqtt/bridge/devices` (2026-10-26 16:20 CET) |
| E2 | `zigbee2mqtt/bridge/info` (config efectiva: `cache_state`, `retain`, `availability`, `last_seen`, `qos`) |
| E3 | `/app/data/state.json` (estado cacheado de los tres dispositivos) |
| E4 | Captura `mosquitto_sub -F '%U %t %p'` de 298,6 s y 73,1 s |
| E5 | `zigbee2mqtt/bridge/logging` y `bridge/response/*` |
| E6 | `docker compose -p zigbee-local ps` (loopback, healthy) |
| E7 | Logs de Zigbee2MQTT de la sesión (interview, OTA, errores de `/get`) |

---

## 2. `puerta-oficina` — SONOFF SNZB-04P

### 2.1 Identidad

| Campo | Valor observado | Evidencia |
|---|---|---|
| IEEE | `0xc09b9efffef431fb` | E1 |
| Modelo / vendor | `SNZB-04P` / `SONOFF` (`manufacturer: eWeLink`) | E1 |
| Rol Z2M | **EndDevice** (batería; no router) | E1 |
| Alimentación | Batería; 100 % y 3100 mV en la última caché | E1/E3 |
| Firmware | `software_build_id: 2.2.0`; OTA instalada `8704` (`0x2200`) | E1/E3 |
| Fecha de fabricación | `20230927` | E1 |
| LQI | 52–132 en banco (sesión previa); sin reportes periódicos de LQI | E1 / banco |
| Entidades | `contact`, `battery`, `battery_low`, `tamper`, `voltage`, `linkquality`, `update` | E1 |

### 2.2 Entidades y semántica

| Entidad | Tipo | Acceso | Unidad/rango | Topic | Comando/consulta | Observaciones | Evidencia |
|---|---|---|---|---|---|---|---|
| `contact` | binary | **R (1)** | `false`/`true` | `zigbee2mqtt/puerta-oficina` | No admite `/get` (`No converter available for 'contact'`) | **`false` = imán junto = cerrado; `true` = separado = abierto**. Solo push por cambio. | E1/E5 |
| `tamper` | binary | R+get (5) | `false`/`true` | idem | `/get` intentado; sin respuesta con el dispositivo dormido | `true` = tapa manipulada. Se observó alternar al manipular y volver a `false`. | E1/E3/E5 |
| `battery` | numeric | R+get (5) | 0–100 % | idem | `/get` intentado; sin respuesta dormido | Reporte configurado cada 3600–7200 s o cambio ≥2 %. | E1/E3 |
| `voltage` | numeric | R+get (5) | mV | idem | `/get` intentado; sin respuesta dormido | Reporte cada 3600–7200 s o cambio ≥100 mV. | E1/E3 |
| `battery_low` | binary | R (1) | `false`/`true` | idem | — | `true` = batería casi agotada. | E1/E3 |
| `linkquality` | numeric | R (1) | 0–255 lqi | idem | — | Solo llega con mensajes del dispositivo; no hay latido periódico. | E1 |
| `update` | enum/objeto | R | `state`, `installed_version`, `latest_version` | idem | OTA check falló dormido | `installed=8704`, `latest=8704`, `state=idle` → **sin actualización disponible**. | E3/E7 |

### 2.3 Topics y push/consulta

| Topic | Payload real / uso | Notas |
|---|---|---|
| `zigbee2mqtt/puerta-oficina` | `{"battery":100,"voltage":3100,"tamper":false,"contact":true,"battery_low":false,"update":{...}}` | Estado completo cacheado; **no retained**. |
| `zigbee2mqtt/puerta-oficina/get` | `{"battery":""}`, `{"contact":""}` | `contact` devuelve error de converter; `battery`/`genPowerCfg`/`ssIasZone` no respondieron en 45 s. |
| `zigbee2mqtt/puerta-oficina/set` | — | No hay entidad escribible en la definición. |
| `zigbee2mqtt/puerta-oficina/availability` | **no existe** | `availability.enabled=false` en la configuración efectiva. |
| `zigbee2mqtt/bridge/event` | `{"data":{"friendly_name":"puerta-oficina","status":"started|failed"},"type":"device_interview"}` | Observado al intentar interview. |
| `zigbee2mqtt/bridge/response/device/interview` | `{"status":"error","error":"... cannot get active endpoints ..."}` | Ver incidencia §7. |

**Push vs consulta**: todo el estado útil es push. El dispositivo es un EndDevice dormido; los
`/get` y el interview remoto no son viables. No se debe hacer polling.

### 2.4 Comportamiento específico

- **Contacto**: 5 aperturas/cierres → 10 transiciones, una por cambio, sin duplicados (sesión
  previa). En esta sesión el estado cacheado era `contact=true` (imán separado; puerta abierta).
- **Tamper**: `true` mientras se manipula la tapa; `false` con la tapa puesta.
- **Batería**: 100 % / 3100 mV; reporte lento (1–2 h). No sirve para detectar "online" en tiempo
  real; usar `battery_low`/`voltage` y `last_seen` si se habilita.
- **Silencio en reposo**: 0 mensajes en 298,6 s de captura (E4). No hay latido de LQI.
- **Persistencia**: tras reiniciar Z2M la red se recuperó y el dispositivo siguió emparejado;
  el estado cacheado en `state.json` se conservó (E3).
- **Entidades no disponibles**: no hay `action`, no hay `identify` expuesto, no hay medición de
  temperatura ni estado de pila distinto de `battery_low`.

### 2.5 Comandos y opciones

- No hay `exposes` escribibles (`options: []` en la definición).
- OTA: `supports_ota: true`; instalada 8704, última 8704, `state=idle`. **No actualizar** sin
  autorización. El check remoto falló por estar dormido, no por falta de imagen.

### 2.6 Límites y calibración

- EndDevice de batería: no es router; no extiende la malla.
- No se puede consultar bajo demanda de forma fiable; la integración debe basarse en push y en
  un estado persistido con marca de tiempo.
- Alcance/ubicación real pendiente (solo probado en banco a corta distancia).
- El interview remoto falló y dejó `interview_state=FAILED`; la definición y los endpoints
  siguen intactos, pero conviene despertar el dispositivo físicamente y reintentar el interview
  (ver §7).

---

## 3. `presencia-oficina` — Tuya ZY-M100-S_2

### 3.1 Identidad

| Campo | Valor observado | Evidencia |
|---|---|---|
| IEEE | `0xa4c138b2a3c49dbe` | E1 |
| Modelo / vendor | `TS0601` / `Tuya`; definición Z2M `ZY-M100-S_2` («Mini human breathe sensor») | E1 |
| Rol Z2M | **Router** (alimentado por red) | E1 |
| Alimentación | Mains (single phase) | E1 |
| Firmware | `Zigbee module: 1.0.10, MCU module: 1.0.3` | E1 |
| LQI | 144–176 en la sesión (255 al emparejar) | E4 |
| Entidades | `presence`, `illuminance`, `target_distance`, `radar_sensitivity`, `minimum_range`, `maximum_range`, `detection_delay`, `fading_time`, `linkquality` | E1 |
| OTA | `supports_ota: false` | E1 |

### 3.2 Entidades y semántica

| Entidad | Tipo | Acceso | Unidad/rango | Topic | Comando/consulta | Observaciones | Evidencia |
|---|---|---|---|---|---|---|---|
| `presence` | binary | **R (1)** | `true`/`false` | `zigbee2mqtt/presencia-oficina` | No admite `/get` (`No converter available for 'get' 'presence'`) | `true` = presencia detectada. Cambio por push; sin consulta. | E1/E5 |
| `illuminance` | numeric | R (1) | lx (sin rango declarado) | idem | No admite `/get` | 40–68 lx en la sesión; cambia en cada reporte y es parte del tráfico periódico. | E1/E4/E5 |
| `target_distance` | numeric | R (1) | m (sin rango declarado) | idem | No admite `/get` | **0 en periodos estáticos/sin movimiento**; 1,85–3,49 m con movimiento real. No usar como única señal. | E1/E4 |
| `radar_sensitivity` | numeric | **R/W (3)** | 0–9, paso 1 | idem | `/set {"radar_sensitivity":N}` | Valor actual 7. Escritura con el mismo valor aceptada y reflejada en el estado. | E1/E4 |
| `minimum_range` | numeric | R/W (3) | 0–9,5 m, paso 0,15 | idem | `/set {"minimum_range":N}` | Actual 0,6 m. | E1/E4 |
| `maximum_range` | numeric | R/W (3) | 0–9,5 m, paso 0,15 | idem | `/set {"maximum_range":N}` | Actual 6 m. | E1/E4 |
| `detection_delay` | numeric | R/W (3) | 0–10 s, paso 0,1 | idem | `/set {"detection_delay":N}` | Actual 0,1 s. | E1/E4 |
| `fading_time` | numeric | R/W (3) | 0,5–1500 s, paso 1 | idem | `/set {"fading_time":N}` | Actual 15 s. | E1/E4 |
| `linkquality` | numeric | R (1) | 0–255 lqi | idem | — | 144–176; llega con cada reporte. | E1/E4 |

### 3.3 Opciones de dispositivo (no son entidades)

| Opción | Acceso | Valores | Default / observado | Uso | Evidencia |
|---|---|---|---|---|---|
| `time_start` | W (2) | `1970`, `2000`, `off` | `off` | Responder a la sincronización de hora Tuya. `1970` es lo recomendado para que el dispositivo deje de pedirla; puede reducir tráfico extra. | E1 |
| `illuminance_calibration` | W (2) | numérico, paso 0,1 | sin cambio | Offset porcentual de iluminancia; se aplica en el siguiente reporte. | E1 |

### 3.4 Topics y push/consulta

| Topic | Payload real / uso | Notas |
|---|---|---|
| `zigbee2mqtt/presencia-oficina` | `{"detection_delay":0.1,"fading_time":15,"illuminance":55,"linkquality":160,"maximum_range":6,"minimum_range":0.6,"presence":true,"radar_sensitivity":7,"target_distance":0}` | 171 B; incluye **todos** los ajustes en cada reporte. **No retained.** |
| `zigbee2mqtt/presencia-oficina/get` | `{"presence":"","illuminance":"","target_distance":""}` | Los tres devuelven `No converter available for 'get'`; no hay consulta. |
| `zigbee2mqtt/presencia-oficina/set` | `{"radar_sensitivity":7}`, `{"maximum_range":0.15}`, etc. | Acepta escritura; el estado se refleja por push (con duplicados). |
| `zigbee2mqtt/presencia-oficina/availability` | **no existe** | `availability.enabled=false`. |
| `zigbee2mqtt/bridge/response/*` | respuestas de `options`/`read` | No se usó para el estado de presencia. |

**Push vs consulta**: todo el estado es push (DPs Tuya). Los ajustes se escriben con `/set`; no
hay `/get` útil. La configuración se puede leer porque Z2M la incluye en cada payload.

### 3.5 Comportamiento específico

- **Cadencia con `presence=true`**: 112 mensajes en 298,6 s = **0,375 msg/s** (mediana de
  intervalo 2,995 s; mínimo 0,035 s por duplicados; máximo 5,994 s). (E4)
- **Cadencia con `presence=false`** (prueba forzada con `maximum_range=0.15`, `minimum_range=0`):
  77 mensajes en 73,1 s = **1,05 msg/s** (mediana 1,0 s). Es decir, **en reposo reporta más que
  con presencia**. (E4)
- **`illuminance`**: 40–68 lx; cambia con frecuencia y es probablemente el disparador de los
  reportes periódicos. No se midió su precisión absoluta.
- **`target_distance`**: durante ~45 min con `presence=true` se mantuvo en **0**; tras el
  reinicio y con movimiento real reportó 1,85–3,49 m. Conclusión: es una distancia de objetivo
  **en movimiento**; no es fiable para presencia estática ni para decidir sola.
- **Retorno a `false`**: al limitar el alcance a 0,15 m, `presence` pasó a `false` en ~2,5 s
  desde la escritura (incluye la aplicación del ajuste; no es una medida pura de `fading_time`).
  Con el alcance restaurado volvió a `true` al haber movimiento. No se ha medido el retorno
  natural con la sala vacía y `fading_time=15`.
- **Detección estática**: no verificada de forma controlada. La presencia se mantuvo `true`
  durante decenas de minutos; no se puede afirmar si había una persona inmóvil o un falso
  positivo. Es el punto que exige una prueba con la sala vacía.
- **Falsos positivos**: no cuantificados. La prueba de forzar `false` demuestra que el sensor
  respeta el alcance máximo, pero no mide falsos positivos con el alcance normal.
- **Router**: es el único Router de los tres; puede extender la malla y debe estar alimentado.

### 3.6 Comandos y calibración

- Escrituras probadas (mismo valor, sin cambio funcional): `radar_sensitivity=7`,
  `detection_delay=0.1`, `fading_time=15`; todas aceptadas y reflejadas en el estado.
- Prueba de rango: `maximum_range=0.15`, `minimum_range=0` → `presence=false`; restaurado a
  `maximum_range=6`, `minimum_range=0.6`, `radar_sensitivity=7` y verificado.
- No se probó `time_start=1970` ni `illuminance_calibration`; son candidatos de optimización.
- No se actualizó firmware (no hay OTA soportada).

### 3.7 Límites y calibración necesaria

- Sin `/get`; si el worker arranca tarde, no puede pedir el estado actual. Necesita push
  permanente o leer el estado cacheado de Z2M.
- `target_distance` no es una medida de presencia estática.
- El volumen en reposo (~1 msg/s) es alto para 70 habitaciones; hay que filtrar/deduplicar.
- Calibrar en la sala real: `radar_sensitivity`, `minimum_range`, `maximum_range`,
  `detection_delay`, `fading_time`; validar con sala vacía y con una persona inmóvil.
- El sensor es de 24 GHz; puede detectar respiración/movimiento mínimo. No sirve para contar
  personas ni para distancia precisa.

---

## 4. `luz-oficina` — SONOFF ZBMINIL2

### 4.1 Identidad

| Campo | Valor observado | Evidencia |
|---|---|---|
| IEEE | `0xf044d3fffecd9e8e` | E1 |
| Modelo / vendor | `ZBMINIL2` / `SONOFF` | E1 |
| Rol Z2M | **EndDevice** (no es router/repetidor) | E1 |
| Alimentación | Mains (single phase) | E1 |
| Firmware | `software_build_id: 1.0.14`; OTA instalada `4110` (`0x100E`) | E1/E3 |
| Fecha de fabricación | `20230505` | E1 |
| LQI | 152–168 en la sesión | E4 |
| Entidades | `state`, `power_on_behavior`, `linkquality`, `update` | E1 |
| OTA | `supports_ota: true`; última 4110 = instalada → **sin actualización** | E1/E3/E7 |

### 4.2 Entidades y semántica

| Entidad | Tipo | Acceso | Unidad/rango | Topic | Comando/consulta | Observaciones | Evidencia |
|---|---|---|---|---|---|---|---|
| `state` | enum | **R/W+get (7)** | `ON`, `OFF`, `TOGGLE` | `zigbee2mqtt/luz-oficina` | `/set {"state":"ON|OFF|TOGGLE"}`; `/get {"state":""}` | Estado real confirmado por el dispositivo; 2–3 reportes por comando. | E1/E4 |
| `power_on_behavior` | enum | R/W+get (7) | `off`, `on`, `toggle`, `previous` | idem | `/set {"power_on_behavior":"off"}`; `/get` | Valor actual `off`. No probado tras corte real. | E1/E3/E4 |
| `linkquality` | numeric | R (1) | 0–255 lqi | idem | — | 152–168. | E1/E4 |
| `update` | objeto | R | `state`, `installed_version`, `latest_version` | idem | OTA check | `installed=4110`, `latest=4110`, `idle`; la URL de imagen aparece en el payload (287 B). | E1/E3/E7 |

### 4.3 Opciones de dispositivo

| Opción | Acceso | Default | Uso | Evidencia |
|---|---|---|---|---|
| `state_action` | W (2) | `false` | Si `true`, los cambios de estado también publican `action`. En nuestra prueba con `/set` remoto **no** apareció `action`; probablemente aplica a pulsación física/S1-S2. Pendiente de probar físicamente. | E1/E4 |
| `optimistic` | config | `true` | Publica el estado esperado tras `/set` antes de la confirmación del dispositivo. Para integración conviene `false`. | E1/E2 |
| `qos` | config | 0 (no fijado) | QoS de publicación de Z2M para el dispositivo. Por defecto 0, aunque la suscripción sea QoS 1. | E2 |
| `retain` | config | `false` | Los topics de estado no son retained. | E2/E3 |
| `throttle` | config | sin límite | Descarta payloads mientras dura el throttle. | E2 |
| `filtered_attributes` | config | vacío | Permite eliminar `update` u otros campos del payload. | E2 |

### 4.4 Topics y push/consulta

| Topic | Payload real / uso | Notas |
|---|---|---|
| `zigbee2mqtt/luz-oficina` | `{"linkquality":160,"power_on_behavior":"off","state":"OFF","update":{"installed_version":4110,"latest_version":4110,"state":"idle",...}}` | 287 B; incluye el bloque `update`. **No retained.** |
| `zigbee2mqtt/luz-oficina/set` | `{"state":"ON"}`, `{"state":"OFF"}`, `{"power_on_behavior":"off"}` | Aceptado; el `PUBACK` no prueba cambio físico. |
| `zigbee2mqtt/luz-oficina/get` | `{"state":""}`, `{"power_on_behavior":""}` | Funciona; respuesta en 440–459 ms (5 repeticiones). |
| `zigbee2mqtt/luz-oficina/availability` | **no existe** | `availability.enabled=false`. |
| `zigbee2mqtt/bridge/response/options` | `{"status":"ok","data":{"restart_required":false}}` | Se usó para `optimistic`/`state_action`; ver incidencia de persistencia §7. |

**Push vs consulta**: `state` se publica al cambiar y también responde a `/get`. No hay polling
necesario. El dispositivo es EndDevice (no router).

### 4.5 Comportamiento específico

- **Ciclos ON/OFF**: 5 pares; la lámpara respondió físicamente en la sesión previa y en esta se
  confirmó el estado por MQTT. Quedó en `OFF` al terminar.
- **Duplicados**: cada comando produce **2–3 mensajes de estado** con el mismo valor y LQI a
  veces distinto; son reportes del propio dispositivo, no solo ecos de Z2M. Hay que deduplicar.
- **Latencia**: `/get state` 440–459 ms; primer estado tras `/set` 191–697 ms (ON) y 41–698 ms
  (OFF), con una anomalía (un `OFF` espurio 2,25 s después de un `ON`, no reproducida en la
  prueba de 20 s con `ON` mantenido). Ver §5.
- **Estado estable**: un `ON` se mantuvo 19,3 s sin auto-apagado; no se observó "inching".
- **`power_on_behavior=off`**: tras pérdida de tensión debería quedar `OFF`; **no** se probó un
  corte real (requiere electricista).
- **Botón físico / S1-S2**: no probados; el dispositivo tiene `genOnOffSwitchCfg` y la opción
  `state_action` para detectar acciones físicas, pero no se pudieron pulsar remotamente.
- **Carga mínima / LED**: no verificados; el ZBMINIL2 es "no neutral" y depende de la carga
  mínima. La lámpara del banco funciona, pero no se midió el umbral.

### 4.6 Límites

- **No es router**: no amplía la malla. Para 70 habitaciones hay que contar con los Routers
  (la presencia ZY-M100-S_2 sí lo es).
- Sin medición de consumo, sin brillo, sin color; es un interruptor on/off.
- `optimistic=true` por defecto: el primer mensaje puede no ser la confirmación física. Para el
  dominio, usar `optimistic:false` (verificado en runtime, pero la persistencia por API falló,
  §7) o exigir el reporte del dispositivo con deduplicación.
- OTA: 4110 es la última; no hay actualización pendiente.

---

## 5. Latencia

### 5.1 Método

- Relé: cliente Node dentro del contenedor Z2M; `publish` QoS 1 y `message` QoS 1; se registra
  `Date.now()` inmediatamente antes de publicar y al recibir el estado. Reloj del mismo host.
- Presencia/puerta: no hay marcas físicas sincronizadas; solo se reporta la cadencia y los
  tiempos de respuesta a escrituras, no la latencia física de detección.
- No se separa con precisión sensor/radio/Z2M/MQTT. El broker es local (loopback) y el MQTT
  añade como máximo unos pocos ms; el grueso es Zigbee + dispositivo + procesado de Z2M.

### 5.2 Resultados medidos

| Medición | N | Mín. | Mediana | Máx. | Notas |
|---|---:|---:|---:|---:|---|
| Relé `/get {"state":""}` → estado | 5 | **440 ms** | **446 ms** | **459 ms** | Ida y vuelta completa por Zigbee + Z2M + MQTT local. |
| Relé `/set ON` → primer estado `ON` | 5 | **191 ms** | **437 ms** | **697 ms** | Primer mensaje; puede incluir publicación optimista si la opción no estaba aplicada. |
| Relé `/set OFF` → primer estado `OFF` | 5 | **41 ms** | **194 ms** | **698 ms** | Un `OFF` espurio previo al comando en un ciclo; ver §7. |
| Presencia `/set` mismo valor → eco de estado | 3 | ~0,04 s | ~0,04 s | ~0,04 s | Duplicado optimista/cache; **no** es latencia física. |
| Puerta `contact` / transición | — | — | — | — | **Pendiente** con marcas físicas. |
| Puerta `/get battery` / `read` | — | — | — | — | Sin respuesta en 45 s (EndDevice dormido). |

### 5.3 Qué falta para separar tramos

Para separar sensor/radio/Z2M/MQTT hacen falta marcas físicas (apertura/cierre de puerta,
entrada/salida de una persona) y un reloj común. Sin eso, los números de la tabla son
*comando→estado* del relé, no latencia de detección. No se debe afirmar que la puerta o la
presencia tienen esa latencia.

---

## 6. Volumen, frecuencia e impacto a 70 habitaciones

### 6.1 Medido en el banco

| Dispositivo / estado | Ventana | Mensajes | msg/s | Payload | Bytes/s | Bytes/día (1 ud.) |
|---|---:|---:|---:|---:|---:|---:|
| Presencia `presence=true` | 298,6 s | 112 | **0,375** | 171 B | 64,1 | **5,5 MB** |
| Presencia `presence=false` (forzado) | 73,1 s | 77 | **1,05** | 171 B | 180,1 | **15,6 MB** |
| Puerta en reposo | 298,6 s | 0 | ~0 | — | 0 | ~0 (solo batería 1–2 h) |
| Relé en reposo | 298,6 s | 0 | ~0 | — | 0 | ~0 |
| Relé por comando | — | 2–3 | — | 287 B | — | — |

La presencia es el único dispositivo con tráfico continuo relevante. La puerta y el relé son
casi silenciosos; el relé solo habla al cambiar de estado (y repite 2–3 veces).

### 6.2 Proyección a 70 habitaciones

Suponiendo un sensor de presencia por habitación y 24 h:

| Escenario | msg/s | Payload/s | Volumen/día |
|---|---:|---:|---:|
| Todas con `presence=true` | **26,3** | 4,5 KB/s | **~388 MB** |
| Todas con `presence=false` | **73,5** | 12,6 KB/s | **~1,09 GB** |
| Mezcla realista (alternando) | 26–74 | 4,5–12,6 KB/s | 0,4–1,1 GB |

Añadir puerta (batería ~1 mensaje/1–2 h) y relé (solo comandos) es despreciable. El broker
Mosquitto puede con 74 msg/s; el riesgo está en el **worker PHP** que parsee y procese cada
mensaje y en el tamaño del payload, no en el broker.

### 6.3 Qué limitar y cómo

| Medida | Efecto | Riesgo / condición |
|---|---|---|
| `filtered_attributes` en presencia (quitar `radar_sensitivity`, rangos, delays, `fading_time`) | Reduce el payload de 171 B a ~80 B | El worker debe leer esos ajustes solo cuando los necesite (no vienen en cada mensaje). |
| `cache_state:false` | Publica solo atributos cambiados; mensajes más pequeños | El worker debe mantener estado parcial; no asumir payload completo. |
| `filtered_attributes: ^update$` en relé | 287 B → ~120 B | Se pierde el estado OTA en el topic; consultarlo aparte. |
| `throttle` en presencia (p. ej. 2 s) | Reduce mensajes | **Puede descartar una transición de presencia**; no recomendado para la señal de dominio. |
| Worker: procesar solo cambios de `presence`/`contact`/`state` | Reduce CPU y escrituras | Ignorar `illuminance` si no se usa; deduplicar 2–3 reportes del relé. |
| QoS 0 para presencia; QoS 1 para puerta/relé | Menos overhead | La presencia es tolerante a pérdida de un reporte periódico; la puerta/relé no. |
| `retain:true` en puerta/relé | Un suscriptor tardío recibe el último estado | Estado retenido puede ser viejo; acompañar de `last_seen`. No usar retain en presencia sin frescura. |
| `last_seen: ISO_8601_local` | Añade marca temporal a cada payload | +~30 B; imprescindible para detectar estado viejo si no hay retain. |
| `availability` | Detecta routers caídos | Para batería es pasiva (1500 min por defecto), demasiado lenta; usar `last_seen` en puerta. Activar añade pings locales a routers. |

---

## 7. Incidencias, seguridad y estado final

### 7.1 Incidencias de la sesión

1. **Interview remoto de la puerta**: se lanzó `bridge/request/device/interview` sobre un
   EndDevice dormido. Falló con `cannot get active endpoints` y dejó
   `interview_state=FAILED` / `interview_completed=false`. La definición, los `exposes` y los
   endpoints siguen presentes; el estado cacheado se conserva. **No repetir en remoto.**
   *Remediación*: despertar la puerta físicamente (pulsación corta según el manual del
   SNZB-04P; **no** mantener 5 s, que entra en pairing) y relanzar el interview desde Z2M, o
   esperar a un reporte natural y relanzarlo. No se tocó `permit_join`.
2. **Prueba de presencia forzada interrumpida por reinicio del nodo**: se puso
   `maximum_range=0.15`/`minimum_range=0` para observar `presence=false`; el nodo se reinició.
   Tras recuperarlo se restauró y verificó: `radar_sensitivity=7`, `minimum_range=0.6`,
   `maximum_range=6`, `presence=true` (con movimiento) y `target_distance` 1,85–3,49 m.
3. **Persistencia de opciones por API**: `bridge/request/options` con `optimistic` y
   `state_action` respondió `ok`, pero Z2M 2.14.2 escribió las claves en el **nivel superior**
   de `configuration.yaml`, no bajo `devices.<ieee>`. Se eliminaron y se validó el YAML. Por
   eso la prueba de latencia con `optimistic:false` **no es concluyente**; el primer mensaje
   podría ser optimista. Para fijar opciones por dispositivo, editar el bloque del dispositivo
   en `configuration.yaml` (con backup) o usar la UI, y reiniciar; no confiar solo en la API.
4. **Credenciales en argumentos**: en las primeras capturas se pasó la contraseña MQTT a
   `mosquitto_sub` como argumento, visible en `ps` dentro del contenedor. Se dejó de usar; las
   medidas finales usan un cliente Node que lee variables de entorno. No se documenta el valor.
   Para producción, usar un fichero de credenciales o un cliente que no exponga el secreto en
   `argv`.

### 7.2 Hallazgos de seguridad (sin valores)

- `configuration.yaml` contiene `mqtt.password` y `advanced.network_key` en claro, con permisos
  `0600` y propietario del UID de Z2M. El diseño RF-137 pedía credenciales MQTT solo por
  entorno; Z2M las persiste en el YAML. **Recomendación**: mover la clave de red a `secret.yaml`,
  revisar permisos y no compartir backups. No se ha rotado nada.
- Broker y UI siguen en `127.0.0.1` (E6); no se abrieron puertos.
- `permit_join=false` (E2); no se emparejó ningún dispositivo nuevo.
- `availability` y `last_seen` están desactivados; un estado cacheado puede parecer actual.
- La UI no tiene `auth_token`, pero solo escucha en loopback. Para el host definitivo, añadir
  autenticación/TLS si se expone fuera de loopback.

### 7.3 Estado final verificado

- Contenedores `zigbee-local-mosquitto-1` y `zigbee-local-zigbee2mqtt-1` **healthy**; puertos
  `127.0.0.1:1883` y `127.0.0.1:8080`.
- `permit_join=false`; tres dispositivos emparejados.
- Presencia: `radar_sensitivity=7`, `minimum_range=0.6`, `maximum_range=6`,
  `detection_delay=0.1`, `fading_time=15`; `presence=true` con movimiento y
  `target_distance` no nulo.
- Relé: `state=OFF`, `power_on_behavior=off`; opciones devueltas a los valores por defecto
  (`optimistic=true`, `state_action=false`) y YAML limpio.
- Puerta: `contact=true` (imán separado; puerta abierta), `tamper=false`, `battery=100`,
  `voltage=3100`, OTA 8704/8704; `interview_state=FAILED` pendiente de despertar físicamente.
- No se hizo ninguna llamada a Tuya ni se añadió poller.

---

## 8. Qué se puede optimizar en el proyecto

### 8.1 Hechos (medidos)

- La presencia publica **~0,375 msg/s con presencia** y **~1,05 msg/s sin presencia**, 171 B,
  sin `/get`, con todos los ajustes en cada payload.
- La puerta y el relé son **casi silenciosos**; la puerta no admite `/get` fiable y el relé
  repite 2–3 estados por comando.
- Los topics de estado **no son retained** y `availability`/`last_seen` están desactivados.
- El relé es **EndDevice**, no router; la presencia sí es Router.
- `target_distance` es 0 en estático y no nulo con movimiento.
- `power_on_behavior=off`; no hay actualización OTA para ninguno de los tres.

### 8.2 Hipótesis / propuestas (a validar)

| Propuesta | Beneficio esperado | Riesgo | Cómo validar |
|---|---|---|---|
| `last_seen` + `retain:true` en puerta/relé | Un worker que arranca tarde conoce el último estado y su antigüedad | Estado retenido viejo; requiere frescura | Activar en un dispositivo de banco y reiniciar el worker |
| `qos:1` en puerta/relé; QoS 0 en presencia | Durabilidad donde importa; menos overhead en el flujo masivo | QoS 1 no aumenta la del publisher si Z2M publica a 0; hay que fijarlo por dispositivo | Medir con el broker y un suscriptor desconectado |
| `optimistic:false` en el relé | El estado publicado es la confirmación del dispositivo, no el deseo de Z2M | Puede aumentar la latencia percibida; la persistencia por API falló | Editar `configuration.yaml` bajo `devices.<ieee>`, reiniciar y repetir 5 ciclos |
| `state_action:true` en el relé | Detectar pulsación física/S1-S2 como `action` | No verificado; puede añadir payload | Probar con una pulsación física y S1/S2 |
| `filtered_attributes` + `cache_state:false` en presencia | Reducir payload y CPU del worker | Worker debe manejar payloads parciales | Comparar bytes y eventos en 5 min |
| `time_start=1970` en presencia | Evitar peticiones de sincronización de hora | Cambio de opción; verificar que no rompe la hora | Medir mensajes extra antes/después |
| `availability` para routers | Detectar presencia caída en ~10 min | Pings locales; en batería pasiva es lenta | Activar y observar `availability` y tráfico |
| Worker: solo cambios + dedupe | Reduce escrituras/SSE y evita 2–3 eventos por comando | Hay que definir ventana de deduplicación | Test de 5 ciclos y de reportes repetidos |
| Fusión puerta + presencia + frescura | Evita falsos positivos y salidas fantasma | Requiere calibración y umbrales por sala | Prueba con sala vacía y con persona inmóvil |

### 8.3 Recomendación de integración

- **Integrar** puerta como `PROXIMITY` (mapear `contact=false` → `CLOSED`, `contact=true` →
  `OPEN`; `tamper` como diagnóstico), presencia como `PRESENCE` (con histéresis/frescura) y
  relé como `SWITCH` (con `optimistic:false` y deduplicación).
- **Calibrar** la presencia en la sala real antes de usarla para decisiones de estancia:
  `radar_sensitivity`, `minimum_range`, `maximum_range`, `detection_delay`, `fading_time`.
  Validar con sala vacía (falsos positivos) y con persona inmóvil (detección estática).
- **Descartar** por ahora: `target_distance` como única señal de presencia; `/get`/interview
  remoto de la puerta; cualquier polling; OTA (no hay actualización).
- **No tocar** el flujo Tuya actual: esta caracterización es local y no habilita el worker ni
  cambia `start-all.sh`/`stop-all.sh`/systemd.

---

## 9. Pendientes concretos

1. Puerta: despertar físicamente y relanzar interview; medir 5 transiciones con marcas
   temporales; verificar `tamper` y recuperación tras reinicio; comprobar alcance/ubicación.
2. Presencia: prueba controlada de sala vacía (retorno a `false`, falsos positivos), persona
   inmóvil (detección estática), latencia de transición y calibración fina; validar
   `time_start=1970`.
3. Relé: botón físico, S1/S2, `state_action`, corte real de tensión (electricista),
   `power_on_behavior` tras corte, carga mínima/LED, y `optimistic:false` persistido.
4. Integración: decidir `last_seen`/`retain`/QoS/`filtered_attributes`; probar un worker con
   payloads reales y 70 topics simulados; medir CPU/msg/s.
5. Especificaciones: actualizar RF-142/TSK-90.15 con estos hallazgos antes de aprobar el mapping
   definitivo (no se modificaron en esta sesión).

---

## Apéndice A — Comandos de evidencia (sin secretos)

```bash
# Estado de contenedores y binds loopback
docker compose -p zigbee-local ps

# El broker exige autenticación. Para reproducir estas consultas, usar un cliente que lea
# las credenciales del entorno del contenedor y NO las exponga en argv (en el banco se usó
# finalmente un cliente Node; no se documenta ningún valor).
#
# Definiciones y configuración efectiva (tópicos):
#   zigbee2mqtt/bridge/devices
#   zigbee2mqtt/bridge/info
#
# Estado cacheado (no retained):
docker exec zigbee-local-zigbee2mqtt-1 node -e \
  'const d=require("/app/data/state.json"); console.log(JSON.stringify(d,null,1))'

# Captura con timestamps: formato "%U %t %p" (epoch con fracción, topic, payload)
# sobre zigbee2mqtt/#.
```

## Apéndice B — Muestras de payload (reales, resumidas)

```json
// puerta-oficina (state.json, 2026-10-26)
{"battery":100,"voltage":3100,"tamper":false,"contact":true,"battery_low":false,
 "update":{"state":"idle","installed_version":8704,"latest_version":8704}}

// presencia-oficina (presence=true, movimiento)
{"detection_delay":0.1,"fading_time":15,"illuminance":46,"linkquality":160,
 "maximum_range":6,"minimum_range":0.6,"presence":true,"radar_sensitivity":7,
 "target_distance":2.43}

// luz-oficina (OFF)
{"linkquality":160,"power_on_behavior":"off","state":"OFF",
 "update":{"installed_version":4110,"latest_version":4110,"state":"idle", "...":"..."}}
```
