# Paquete aislado Mosquitto + Zigbee2MQTT (F90)

> **Estado (2026-10-26)**: paquete de despliegue reproducible entregado y verificado solo de forma
> estática. **No se ha desplegado ni ejecutado en `oficina`**, no se han creado contenedores y no
> se ha tocado su Docker, red, firewall, systemd, escritorio ni agentes de cámara. La validación
> real queda para el banco/host que el operador autorice.

Este directorio es un proyecto **independiente**. No forma parte de `start-all.sh`, `stop-all.sh`,
del API hotelero ni de ninguna unidad systemd. No instala paquetes, no modifica el firewall y no
reutiliza contenedores o redes de otros proyectos.

## Contenido

```text
docker-compose.yml                  Proyecto Compose aislado
.env.example                        Variables y placeholders (sin secretos)
.gitignore                          Ignora .env, data/, backups y secretos
mosquitto/mosquitto.conf.example    Plantilla del broker
zigbee2mqtt/configuration.yaml.example
preflight.sh                        Comprobaciones de solo lectura
deploy.sh                           Despliegue idempotente y fail-closed
verify.sh                           Estado/salud/listeners/MQTT/logs
backup.sh                           Backup tar.gz + sha256
restore.sh                          Restauración en destino nuevo
rollback.sh                         Down del proyecto conservando datos
README.md                           Este documento
```

## Requisitos

- Docker Engine y Docker Compose v2 en el host.
- Espacio libre suficiente (por defecto se exigen 2 GB).
- Un adaptador Zigbee accesible por TCP (host y puerto configurables).
- Puertos locales libres (por defecto MQTT 1883 y UI 8080).
- Imágenes de Mosquitto y Zigbee2MQTT fijadas por **tag concreto o digest**. No se admiten
  `latest` ni versiones inventadas por este paquete.

No se requiere que el host sea `oficina`; de hecho, esta entrega no se ha ejecutado allí.

## Garantías de aislamiento

- Proyecto Compose propio (`COMPOSE_PROJECT_NAME`), usado por todos los comandos.
- Puertos publicados solo en `127.0.0.1` por defecto. No hay exposición a Internet.
- Sin modo privilegiado, sin red/PID del host, sin montar el socket del motor Docker, `/`, `/dev`
  ni el home completo.
- `cap_drop: ALL`, solo capabilities de ajuste de permisos/identidad, `no-new-privileges`,
  `read_only`, `tmpfs` mínimos, límites de CPU/memoria/PIDs y logs rotados.
- `preflight.sh` no escribe nada. `deploy.sh` solo crea directorios bajo `data/` de este proyecto.
- No se añaden unidades systemd, cron ni wiring en los scripts de arranque del repositorio.

## Provisión de secretos (fuera de Git)

Los secretos **nunca** se guardan en el repositorio. `.env`, `data/`, `backups/`, `*.tar.gz`,
`*.sha256` y el fichero de contraseñas están ignorados.

1. Copia la plantilla y restringe permisos:

   ```bash
   cp .env.example .env
   chmod 600 .env
   ```

2. Edita `.env` y sustituye los placeholders:
   - `COMPOSE_PROJECT_NAME`: nombre único, p. ej. `zigbee-local`.
   - `MOSQUITTO_IMAGE` y `Z2M_IMAGE`: tag concreto o digest ya revisado por el operador.
   - `MQTT_HOST_PORT` y `Z2M_UI_HOST_PORT`: puertos locales libres.
   - `MQTT_USERNAME` y `MQTT_PASSWORD`: credenciales reales, fuera de Git.
   - `ZIGBEE_ADAPTER_HOST` y `ZIGBEE_ADAPTER_PORT`: host/puerto del adaptador TCP.

3. Crea el fichero de contraseñas de Mosquitto sin escribirlo en el historial. El comando pide la
   contraseña de forma interactiva y usa la imagen fijada; no la imprime:

   ```bash
   MOSQUITTO_IMAGE="$(grep -E '^MOSQUITTO_IMAGE=' .env | cut -d= -f2-)"
   MQTT_USERNAME="$(grep -E '^MQTT_USERNAME=' .env | cut -d= -f2-)"
   mkdir -p data/mosquitto/config
   docker run --rm -it --entrypoint mosquitto_passwd \
     -v "$PWD/data/mosquitto/config:/mosquitto/config" \
     "$MOSQUITTO_IMAGE" -c /mosquitto/config/passwd "$MQTT_USERNAME"
   chmod 600 data/mosquitto/config/passwd
   ```

   Compose monta este fichero como secreto de solo lectura en el contenedor; el fichero del host
   permanece con permisos 600.

   Usa la misma contraseña que `MQTT_PASSWORD` en `.env`. `deploy.sh` no crea ni sobrescribe este
   fichero: si falta, aborta con instrucciones.

4. Verifica que `.env` y el fichero de contraseñas no aparecen en `git status` antes de continuar.

## Uso en un banco (incluido `oficina`, solo si el operador lo autoriza)

Esta entrega **no ejecuta** estos pasos. Si en el futuro se decide usar `oficina` como banco:

```bash
./preflight.sh          # solo lectura; falla cerrado
./deploy.sh             # valida, crea data/ y levanta SOLO este proyecto
./verify.sh             # estado, salud, loopback, MQTT y logs
```

Comprobaciones de no interferencia antes/después:

```bash
# Solo deben aparecer contenedores de este proyecto.
docker ps --filter "label=com.docker.compose.project=$COMPOSE_PROJECT_NAME"

# No debe haber cambios en servicios del sistema ni en otros proyectos.
systemctl is-system-running || true
docker ps --format '{{.Names}}' | sort
```

No ejecutes `deploy.sh` en el ordenador de trabajo sin autorización explícita. El paquete no
necesita tocar el host para aprobar los tests estáticos.

## Verificación

`verify.sh` comprueba:

- contenedores `mosquitto` y `zigbee2mqtt` en ejecución y con healthcheck;
- listeners publicados en loopback en los puertos configurados;
- publicación y suscripción MQTT temporal con QoS 1 en un topic de verificación;
- que los logs recientes no contienen la contraseña MQTT.

No reinicia servicios ni modifica la configuración. Si falla, revisa `.env`, el adaptador TCP y
los logs del proyecto (sin pegar secretos en incidencias).

## Backup y restauración de la red Zigbee

El backup incluye `data/zigbee2mqtt` (con `database.db`, `configuration.yaml`, `secret.yaml` y
`coordinator_backup.json` cuando existan) y la configuración/datos de Mosquitto. Contiene secretos:
mantenlo fuera de Git y con permisos restrictivos.

```bash
./backup.sh
# salida: backups/zigbee-backup-<UTC>.tar.gz + .sha256
```

Para una copia consistente de `database.db` (SQLite), detén antes el proyecto con
`./rollback.sh --yes`. El backup no modifica ni detiene nada por sí mismo.

La restauración exige un destino nuevo y confirmación explícita; **no sobrescribe datos vivos**:

```bash
./restore.sh backups/zigbee-backup-<UTC>.tar.gz /ruta/nueva/restore
# escribe RESTORE para confirmar
```

Para promover: para el proyecto con `./rollback.sh --yes`, conserva el `data/` actual, coloca el
contenido restaurado en `data/` y vuelve a ejecutar `./deploy.sh` y `./verify.sh`. Hazlo solo con
el proyecto parado y tras revisar el backup.

## Rollback

```bash
./rollback.sh          # pide confirmación
./rollback.sh --yes    # confirmación explícita no interactiva
```

Solo ejecuta el `down` del proyecto Compose. **Conserva** contenedores eliminados de forma normal,
red del proyecto, bind mounts y datos. La eliminación de datos no es la acción por defecto y
requiere una decisión manual del operador.

## Traslado al servidor definitivo

1. Clona en el servidor la versión aprobada de este repositorio (no copies el `.env` ni `data/`
   desde el banco).
2. Crea allí `.env` y el fichero de contraseñas fuera de Git, con los valores del servidor.
3. Restaura el backup en un destino nuevo y, con el proyecto parado, promociona los datos.
4. Ejecuta `./preflight.sh`, `./deploy.sh` y `./verify.sh`.
5. Revisa firewall, autenticación y TLS **antes** de publicar el broker o la UI fuera de loopback.

No se migran contenedores ni se reutiliza la configuración de red del banco. El host definitivo
sigue sin decidirse en esta fase.

## Límites conocidos

- Autenticación MQTT por usuario/contraseña; **no** se configura TLS ni ACL avanzada en esta
  entrega. No expongas el broker a Internet sin resolverlo antes.
- `adapter: ember`, `baudrate: 115200` y `rtscts: false` son valores iniciales a validar con el
  Dongle-M real. No se asumen modelos, entidades ni topics de dispositivos SNZB.
- `permit_join: false` por defecto; el emparejamiento requiere una acción explícita y temporal.
- La UI de Zigbee2MQTT escucha en `0.0.0.0` **dentro** del contenedor para que Docker pueda
  publicarla; en el host se publica únicamente en loopback.
- No hay instalación de paquetes, unidades systemd, cron, polling de Tuya ni integración con el
  arranque del API.
- Las comprobaciones de TCP del adaptador son de alcance de red; no validan el firmware ni el
  protocolo del dongle.

## Tests de esta entrega

- `api/tests/Unit/ZigbeeDeployPackageTest.php`: verificación estática del paquete.
- BLOCK 63 de `api/bin/run-tests.sh`: guardia de no interferencia sin Docker, red ni hardware.

Ninguno de estos tests levanta el proyecto ni contacta con el adaptador real.
