# Zigbee local — operación y puesta en marcha (F91)

## Topología actual (servidor definitivo en el hotel)

- El servidor definitivo es el nodo `cerraduras` (Ubuntu 24.04) que se instala
  en el hotel. Allí corren Zigbee2MQTT y Mosquitto en Docker
  (`/opt/cerraduras-zigbee`).
- El coordinador es un **Dongle-M por Ethernet** (no USB), accesible por TCP en
  `ZIGBEE_ADAPTER_HOST:6638` (en el banco, `172.16.51.215`).
- Los tres dispositivos siguen emparejados. No se re-emparejan al mover el
  servidor: se restaura el backup de `data/zigbee2mqtt` (incluye la red y la
  `database.db`).
- El broker escucha solo en `127.0.0.1:1883` dentro del servidor del hotel.
- La app y el dominio siguen en el VPS `liveyourdre2` (`92.113.151.136`). El VPS
  consume el broker por un **túnel SSH inverso** iniciado por el hotel:
  `VPS:127.0.0.1:1884` → `hotel:127.0.0.1:1883`.
- No se abre ningún puerto en el router del hotel. El hotel solo necesita salida
  SSH (22; si se bloquea, ver "Contingencia de red").

## Servicios en el VPS

Instalar la unidad del ingress (el túnel ya no se instala en el VPS):

```bash
install -m0644 docs/systemd/cerraduras-zigbee-ingress.service /etc/systemd/system/
install -m0644 docs/systemd/cerraduras-zigbee-spool.service /etc/systemd/system/
systemctl daemon-reload
```

`start-all.sh` arranca solo el ingress y el spool si en `api/.env` están activos:

```text
ZIGBEE_INGRESS_ENABLED=true
ZIGBEE_SPOOL_ENABLED=true
ZIGBEE_MQTT_HOST=127.0.0.1
ZIGBEE_MQTT_PORT=1884
ZIGBEE_MQTT_USERNAME=<usuario>
ZIGBEE_MQTT_PASSWORD_FILE=run/zigbee-mqtt.pass
```

El fichero de contraseña debe ser `0600` y estar fuera de Git.

## Servidor del hotel (nodo `cerraduras`)

- Proyecto: `/opt/cerraduras-zigbee` (Docker Compose aislado).
- `.env`: copiado del banco, con `ZIGBEE_ADAPTER_HOST` apuntando al Dongle-M y la
  misma credencial MQTT que el VPS.
- `docker-compose.override.yml` fija
  `ZIGBEE2MQTT_CONFIG_SERIAL_PORT=tcp://${ZIGBEE_ADAPTER_HOST}:${ZIGBEE_ADAPTER_PORT}`
  para que cambiar la IP del Dongle sea solo editar `.env`.
- Datos: `data/zigbee2mqtt` (1000:1000, 0700) y `data/mosquitto` (1883:1883,
  0700; `passwd` 0400). No se versionan.
- Túnel inverso: `zigbee-reverse-tunnel.service` (systemd, usuario `lock`) con:
  - `127.0.0.1:1884` → Mosquitto
  - `127.0.0.1:2222` → sshd del hotel
  - `127.0.0.1:8082` → UI de Zigbee2MQTT
- En el VPS, el usuario `zigbee-tunnel` solo acepta esa clave y solo puede
  escuchar esos tres puertos loopback.

## Acceso remoto (sin IP fija ni puertos en el hotel)

Desde un portátil con la clave `admin-cerraduras`:

```bash
ssh -J root@92.113.151.136 -p 2222 lock@127.0.0.1
```

O en `~/.ssh/config`:

```sshconfig
Host cerraduras-hotel
    HostName 127.0.0.1
    Port 2222
    User lock
    ProxyJump root@92.113.151.136
    IdentityFile ~/.ssh/id_ed25519_cerraduras
    ServerAliveInterval 30
```

UI de Zigbee2MQTT:

```bash
ssh -J root@92.113.151.136 -p 2222 \
  -L 8080:127.0.0.1:8080 lock@127.0.0.1 -N
# http://127.0.0.1:8080
```

## Puesta en marcha en el hotel

1. Conectar el servidor y el Dongle-M a la red del hotel por Ethernet.
2. El servidor coge DHCP; el túnel funciona con cualquier IP.
3. Localizar el Dongle-M (MAC `20:e7:c8:d1:ad:eb`) y su IP:
   ```bash
   ip neigh | grep -i '20:e7:c8:d1:ad:eb'
   # o escanear el puerto 6638 en la LAN
   sudo nmap -p 6638 192.168.X.0/24
   ```
4. Fijar IP estática al Dongle (recomendado) o actualizar
   `ZIGBEE_ADAPTER_HOST` en `/opt/cerraduras-zigbee/.env` y reiniciar:
   ```bash
   sudo docker compose up -d --force-recreate zigbee2mqtt
   ```
5. Verificar `3 devices joined` y el flujo en la app.

## Contingencia de red

Si el hotel bloquea la salida al puerto 22 del VPS, usar un VPS con el puerto
443 libre para el túnel o `wstunnel` sobre 443. El túnel inverso no necesita
ningún puerto entrante en el hotel.

## Dependencias

```bash
bash api/bin/zigbee-install-deps.sh
```

## Comprobación

```bash
php api/bin/zigbee-readiness.php
curl -s http://127.0.0.1:8080/api/v1/health/deep | jq .checks.zigbee
```

## Cambio de nombres en Zigbee2MQTT

Renombrar (sin re-emparejar) a:

- `almacen-zb-puerta`
- `almacen-zb-presencia`
- `almacen-zb-luz`

El mapa `api/config/zigbee2mqtt-devices.json` ya usa esos topics.

## Cámaras RTSP del piloto

Las dos cámaras de `almacenZigbee` (room `14`) son **RTSP**, no dispositivos
Zigbee: siguen con `kind=CAMERA` y `transport` sin declarar, y solo se asociaron
a `PACK_ZIGBEE` para que la sala las resuelva por pack (F30).

- `cerraduras-cameras-live.service` sirve MJPEG con `CAMERAS_LIVE_ROOM_ID=14`.
- `cerraduras-camera-motion.service` vigila la cámara INTERIOR con
  `CAMERA_MOTION_ROOM_ID=14`.
- go2rtc registra `almacen_14_EXTERIOR` y `almacen_14_INTERIOR`; los antiguos
  `almacen_12_*` de PROTO2 se retiraron al mover las cámaras.
- La grabación se controla por cámara (`meta_json.record_enabled`) y el recorder
  escribe en `data/cameras/14/`.

## Modo de presencia

`almacenZigbee` usa `mixed`:

- puerta cerrada → `radar_door` (entrada segura);
- puerta abierta → `radar_only` (salidas o retornos con la puerta abierta).

`radar_only_shadow` calcula radar-only sin actuar; `radar_only` decide solo con
el radar.

## Modo normal temporal (pruebas de `/dashboard`)

El piloto puede cambiarse a `TIPO_NORMAL` para probar en `/dashboard` el flujo de
estancia/QR/regla de salida con los mismos dispositivos del `PACK_ZIGBEE`. En este
modo la sala deja de aparecer en `/almacen` y no crea visitas ni grabaciones.

```bash
# 1. Pasar la sala a tipo normal y limpiar el override de presencia
mysql ... <<'SQL'
UPDATE rooms
   SET room_type_id = (SELECT id FROM room_types WHERE code = 'TIPO_NORMAL'),
       presence_mode_override = NULL
 WHERE code = 'almacenZigbee';
SQL

# 2. Reset completo del ciclo (cierra estancia/QR, IoT UNKNOWN, luz OFF)
curl -s -X POST http://127.0.0.1:8080/dashboard-api/rooms/reset \
  -H 'Content-Type: application/json' \
  -d '{"room_id":14}'

# 3. El detector de movimiento no aplica fuera del almacén; pararlo mientras dure la prueba
systemctl stop cerraduras-camera-motion
```

`provision-zigbee-almacen.php` detecta este estado: el `--dry-run` informa y no
escribe; `--apply` exige restaurar `ALMACEN_BEBIDAS` antes de continuar.

Rollback:

```bash
mysql ... <<'SQL'
UPDATE rooms
   SET room_type_id = (SELECT id FROM room_types WHERE code = 'ALMACEN_BEBIDAS'),
       presence_mode_override = 'mixed'
 WHERE code = 'almacenZigbee';
SQL
curl -s -X POST http://127.0.0.1:8080/dashboard-api/rooms/reset \
  -H 'Content-Type: application/json' -d '{"room_id":14}'
systemctl start cerraduras-camera-motion
```

## Estado de conectividad en `/dashboard` (F92)

El panel decide la liveness por transporte, no por `kind`:

- Zigbee usa `availability` de Zigbee2MQTT si está fresca; si no, el estado de
  integración (`operational`/`degraded`) y el último push.
- Las cámaras usan una sonda RTSP bajo demanda (ffprobe con fallback TCP) al
  cargar el panel o pulsar **Comprobar dispositivos**. No hay polling.
- Zigbee y cámaras nunca llaman a la API de Tuya.

Para detección real de `offline` en Zigbee, activar en Zigbee2MQTT:

```yaml
availability:
  enabled: true
  active: { timeout: 10 }
  passive: { timeout: 1500 }
```

- `active` aplica a routers/alimentados (la luz). `passive` aplica a batería
  (la puerta): un dispositivo silencioso puede tardar en marcar `offline`; es el
  límite físico de un end-device dormido.
- Los mensajes `<topic>/availability` solo actualizan conectividad; no cambian
  presencia, puerta, visitas ni grabaciones.

## Diagnóstico de la luz (`ZBMINIL2`)

Un fallo de entrega (`Delivery failed for '<nwk>'`) no se resuelve reemparejando.
Antes de cortar la alimentación, exporta la evidencia persistida de las órdenes:

```bash
curl -s 'http://127.0.0.1:8080/dashboard-api/sensores/v2/switch/diagnostics?room_id=14&window_minutes=120'
```

Procedimiento completo, interpretación de veredictos y recogida de logs:
[`diagnostico-luz-zigbee.md`](diagnostico-luz-zigbee.md).
Los logs de Zigbee2MQTT están en CET (UTC+1); la API y la BD en UTC.

## Rollback

- Corte de Zigbee: parar el proyecto nuevo en el hotel, arrancar el antiguo en
  `oficina` (`docker compose up -d`) y reactivar el túnel antiguo en el VPS
  (`systemctl enable --now cerraduras-zigbee-tunnel.service`). Después reiniciar
  `cerraduras-zigbee-ingress`.
- Poner los flags a `false` y ejecutar `bash stop-all.sh`.
- Para desactivar un dispositivo, retirar su entrada del mapa y reiniciar ingress/spool.
- No se borran el spool ni la auditoría.
- **No hay rollback a Tuya** (F122). El rollback es restaurar el backup Zigbee y
  volver a la versión anterior del código. `almacenZigbee` no tiene equivalente Tuya.

## F125.1.2 — Perfil MQTT por dispositivo (DEV vs producción)

Hay dos brokers locales en el VPS:

| Puerto | Origen | Uso |
|---|---|---|
| `127.0.0.1:1884` | túnel de producción | `almacen-zb-puerta`, `almacen-zb-presencia`, `almacen-zb-luz` |
| `127.0.0.1:1886` | túnel de oficina/dev | `dev-zb-presencia` (DEVELOPMENTROOM) |

La API usa el `.env` (1884) por defecto. Un dispositivo puede declarar
`meta.mqtt_profile` para usar otro perfil. El fichero
`api/config/zigbee-mqtt-profiles.json` no contiene contraseñas, solo puerto,
usuario y ruta al fichero de credenciales:

```json
{
  "version": 1,
  "profiles": {
    "dev": {
      "port": 1886,
      "username": "zigbee2mqtt-dev",
      "password_file": "/etc/cerraduras/zigbee-dev-mqtt.pass"
    }
  }
}
```

- `devices.meta_json.mqtt_profile` ausente o `default` → `.env` (producción).
- `dev` → perfil dev (1886), sin tocar `.env` ni el control de producción.
- Un perfil desconocido o inválido impide publicar (fallo cerrado).

Diagnóstico y asignación:

```bash
php api/bin/zigbee-mqtt-profile.php --device=1339 --json
php api/bin/zigbee-mqtt-profile.php --device=1339 --set-profile=dev --dry-run
php api/bin/zigbee-mqtt-profile.php --device=1339 --set-profile=dev --apply --confirm
php api/bin/zigbee-mqtt-profile.php --device=1339 --clear --apply --confirm
```

El script guarda un backup del `meta_json` anterior en `api/run/` y nunca
imprime la contraseña. Los dispositivos de producción no se tocan y siguen
resolviendo al perfil `default` (1884).

## F125.2 — Reconexión del spool tras un hueco

`zigbee-spool-worker.php --watch` comprueba `PdoFactory::ping()` en cada vuelta.
Si MySQL cerró la conexión inactiva (`wait_timeout`), reconecta y recrea el
pipeline antes de procesar. Además, un `2006/2013` durante el procesamiento se
trata como error transitorio: el mensaje se devuelve a `incoming` sin consumir
intentos ni ir a cuarentena. Un radar apagado horas ya no deja el panel en
`SIN DATOS` al volver.

## F125.4 — Rendimiento del spool, retención y confirmación directa

### Por qué el spool se atrasaba

`ZigbeeSpoolQueue::counts()` hacía `glob()` sobre `processed` en **cada**
mensaje y `writeStatus()` se ejecutaba después de cada uno. Con cientos de
miles de ficheros en `processed`, el worker no podía seguir el ritmo del
ingress: el retraso crecía y los eventos del radar llegaban a la BD minutos
tarde.

Ahora:

- `lightCounts()` cuenta solo `incoming`, `processing` y `quarantine` (dirs
  pequeños), con caché corta.
- `counts.processed` se mantiene con un contador persistido (`processed_total`)
  y no con un `glob`.
- El estado se escribe como máximo una vez por segundo (o al terminar cada
  lote).
- `processed` y `quarantine` se purgan por antigüedad.

Variables:

```dotenv
ZIGBEE_SPOOL_PROCESSED_RETENTION_DAYS=2
ZIGBEE_SPOOL_QUARANTINE_RETENTION_DAYS=14
```

Purga manual (dry-run por defecto; `--apply` borra):

```bash
cd /root/cerraduras/api
php bin/zigbee-spool-prune.php
php bin/zigbee-spool-prune.php --apply
php bin/zigbee-spool-prune.php --dir=run/zigbee-spool-dev --processed-days=2 --apply
```

Nunca toca `incoming` ni `processing`.

### Confirmación directa del radar

`ZigbeeRadarCalibrationService::apply()` y `resetToBaseline()` confirman la
orden suscribiéndose al topic de estado del radar (`readDirect`), sin esperar a
que el spool la persista. Si el broker no responde, se cae al read-back por BD.

```dotenv
RADAR_DIRECT_CONFIRM_ENABLED=true
RADAR_DIRECT_CONFIRM_TIMEOUT_SECONDS=6
```

### Panel estable

Si el último push trae un valor imposible (`maximum_range=-334889.04`), pero
hay una lectura válida dentro de la ventana de frescura, el estado usa la
válida y deja el inválido como aviso secundario (`reported_invalid`). Sin
lectura válida reciente se mantiene `invalid_reported` de forma estable; no se
alterna con `converging`.
