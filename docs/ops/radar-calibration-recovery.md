# Recuperación — calibración fiable del radar (F108)

Este documento prepara la vuelta atrás de los cambios de Zigbee2MQTT y de la
calibración antes de modificar producción.

## Versiones registradas (2026-11-02)

| Elemento | Valor |
|---|---|
| Host Zigbee2MQTT | `oficina` |
| Imagen | `ghcr.io/koenkk/zigbee2mqtt:2.14.2@sha256:addbfb08…` |
| Configuración | `/opt/cerraduras-zigbee/data/zigbee2mqtt/configuration.yaml` |
| Conversores externos | `/opt/cerraduras-zigbee/data/zigbee2mqtt/external_converters/` |
| Conversor F107 | `zy-m100-distance-evidence.js` |
| Log level | `info` |
| Broker | Mosquitto local del nodo `oficina` |
| API | `cerraduras-api.service` |
| Ingesta | `cerraduras-zigbee-ingress.service` |
| Spool | `cerraduras-zigbee-spool.service` |
| Túnel | `cerraduras-zigbee-tunnel.service` |

## Backup antes de tocar el nodo

```bash
ssh oficina 'cd /opt/cerraduras-zigbee && ./backup.sh'
```

El script crea un `tar.gz` y su `sha256` en `backups/`, con permisos
restrictivos. No imprime secretos.

Backup verificado el 2026-11-02:

```text
/opt/cerraduras-zigbee/backups/zigbee-backup-20261102T100743Z.tar.gz
/opt/cerraduras-zigbee/backups/zigbee-backup-20261102T100743Z.tar.gz.sha256
sha256sum -c → OK
```

Backup adicional solo de configuración y conversor:

```bash
ssh oficina 'set -e; TS=$(date -u +%Y%m%dT%H%M%SZ);
  cp -a data/zigbee2mqtt/configuration.yaml backups/configuration-f108-$TS.yaml;
  chmod 600 backups/configuration-f108-$TS.yaml;
  tar -czf backups/external-converters-f108-$TS.tar.gz \
    -C data/zigbee2mqtt external_converters;
  chmod 600 backups/external-converters-f108-$TS.tar.gz;
  echo "$TS"'
```

## Rollback del conversor y la configuración

1. Detener cambios de aplicación que envíen consultas:
   - poner `RADAR_DISTANCE_EVIDENCE_MODE=off` y
     `RADAR_DISTANCE_QUERY_ENABLED=false` en `api/.env`.
2. Restaurar la configuración y retirar el conversor:

```bash
ssh oficina 'set -e; TS=<timestamp>;
  cp -a backups/configuration-f108-$TS.yaml data/zigbee2mqtt/configuration.yaml;
  rm -f data/zigbee2mqtt/external_converters/zy-m100-distance-evidence.js;
  rmdir data/zigbee2mqtt/external_converters 2>/dev/null || true;
  docker restart zigbee-local-zigbee2mqtt-1'
```

3. Verificar:

```bash
ssh oficina 'docker logs --since 2m zigbee-local-zigbee2mqtt-1 2>&1 \
  | grep -E "Loaded external|error|dataQuery"'
```

No debe aparecer `Loaded external converter` ni `dataQuery` en bucle.

## Rollback de la aplicación

- Revertir el merge de F108 en Git.
- No borrar `radar_distance_reports` ni `radar_distance_queries`: son tablas
  aditivas y pueden conservarse para auditoría.
- Reiniciar `cerraduras-api` y `cerraduras-zigbee-spool`.

## Verificación posterior al rollback

- `cerraduras-api`, `cerraduras-zigbee-ingress`, `cerraduras-zigbee-spool` y
  `cerraduras-zigbee-tunnel` activos.
- `zigbee2mqtt` sano.
- Sin consultas periódicas ni `dataQuery`.
- `/sensores` responde.
- El panel vuelve al modo anterior sin errores.

## Datos que no se deben tocar sin autorización

- `data/` del proyecto.
- `.env` y secretos.
- ficheros históricos de `api/run/sensores/`.
- tabla `radar_distance_reports` más allá de pruebas controladas.
