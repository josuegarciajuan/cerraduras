# Zigbee local — operación y puesta en marcha (F91)

## Estado del banco

- Zigbee2MQTT y Mosquitto viven en el host `oficina` y ya tienen los tres
  dispositivos emparejados.
- El broker escucha solo en `127.0.0.1:1883` en `oficina`.
- Este servidor consume ese broker por un túnel SSH:
  `127.0.0.1:1884` → `oficina:127.0.0.1:1883`.
- No se re-emparejan dispositivos. Renombrar un dispositivo en Zigbee2MQTT no
  rompe el emparejamiento.

## Servicios

Instalar las unidades una vez:

```bash
install -m0644 docs/systemd/cerraduras-zigbee-tunnel.service /etc/systemd/system/
install -m0644 docs/systemd/cerraduras-zigbee-ingress.service /etc/systemd/system/
install -m0644 docs/systemd/cerraduras-zigbee-spool.service /etc/systemd/system/
systemctl daemon-reload
```

No se habilitan por defecto. `start-all.sh` los arranca solo si en `api/.env`
están activos:

```text
ZIGBEE_INGRESS_ENABLED=true
ZIGBEE_SPOOL_ENABLED=true
ZIGBEE_MQTT_HOST=127.0.0.1
ZIGBEE_MQTT_PORT=1884
ZIGBEE_MQTT_USERNAME=<usuario>
ZIGBEE_MQTT_PASSWORD_FILE=run/zigbee-mqtt.pass
```

El fichero de contraseña debe ser `0600` y estar fuera de Git.

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

## Rollback

- Poner los flags a `false` y ejecutar `bash stop-all.sh`.
- Para desactivar un dispositivo, retirar su entrada del mapa y reiniciar ingress/spool.
- No se borran el spool ni la auditoría.
- Para volver a Tuya en una sala existente, restaurar su configuración; `almacenZigbee`
  es una sala nueva y no tiene equivalente Tuya.
