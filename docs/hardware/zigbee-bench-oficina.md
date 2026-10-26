# Banco Zigbee local — oficina (provisional)

> **Fecha**: 2026-10-26 · **Estado**: coordinador y broker desplegados; sensores pendientes.
> **Naturaleza**: banco de pruebas, no infraestructura definitiva.
> **Secretos**: no se documentan contraseñas, claves de red Zigbee ni contenido de `.env`.

## Objetivo

Validar Mosquitto + Zigbee2MQTT y el Dongle-M en el nodo de trabajo `oficina` antes de
trasladar los dispositivos al almacén de bebidas y, más adelante, al servidor definitivo.

## Topología desplegada

```text
Dongle-M (172.16.51.215:6638, Ethernet/PoE)
        |
Zigbee2MQTT 2.14.2 (contenedor zigbee-local-zigbee2mqtt-1)
        |
Mosquitto 2.0.22 (contenedor zigbee-local-mosquitto-1)
        |
127.0.0.1:1883 (MQTT) · 127.0.0.1:8080 (UI Zigbee2MQTT)
```

- Nodo: `oficina` (`172.16.51.242`).
- Directorio del proyecto aislado: `/opt/cerraduras-zigbee`.
- Proyecto Compose: `zigbee-local`.
- Imágenes fijadas por digest:
  - `eclipse-mosquitto:2.0.22@sha256:09b62ff32c2e7b64d4b431b9a89236073289b68db273de2b089edeb9b0d92b5c`
  - `ghcr.io/koenkk/zigbee2mqtt:2.14.2@sha256:addbfb08fcef2e4477de1535636118241e10d13e898fbe4d9faba8a4d7396311`
- Contenedores no-root: Mosquitto `1883:1883`; Zigbee2MQTT `1000:1000`.
- Puertos publicados **solo en loopback**.
- `permit_join: false`; **0 dispositivos emparejados** al cerrar esta etapa.

## Estado del Dongle-M

- Modo del chip EFR32MG24: `Zigbee Coordinator Mode`.
- Firmware Zigbee observado por Zigbee2MQTT: EmberZNet `7.4.5`, EZSP 13.
- Panel del Dongle: versión `1.0.4`; ESP32 `V1.0.7` (el panel ofrecía `V1.0.13`; no se actualizó).
- Red Zigbee formada por Zigbee2MQTT en el primer arranque y **recuperada tras reinicio**
  (`Adapter network matches config`, `resumed`). No se documenta la clave de red.

## Verificación ejecutada

- `preflight.sh`: OK (puertos libres, Dongle alcanzable, espacio, sin colisión de proyecto).
- `deploy.sh`: creación de configuración inicial y arranque del proyecto aislado.
- `verify.sh`: **10 OK, 0 fallos** (contenedores, salud, loopback, MQTT y ausencia de secretos en logs).
- Ciclo probado: `rollback.sh --yes` → `backup.sh` → `deploy.sh` → `verify.sh`.
- Backup inicial: `backups/zigbee-backup-20261026T123101Z.tar.gz` (+ `.sha256`).
- `systemctl --failed` conserva las mismas 6 unidades previas a la instalación; no se modificó
  systemd, red, firewall ni otros contenedores.
- El contenedor ajeno de `autotube-sd` no fue tocado por el despliegue.

## Sensor de puerta SNZB-04P — emparejado y probado

- **IEEE**: `0xc09b9efffef431fb` · **friendly name**: `puerta-oficina`.
- **Modelo**: SONOFF SNZB-04P · `model_id` SNZB-04P · fabricante `eWeLink`.
- **Fecha de fabricación**: `20230927` · **software build**: `2.2.0` ·
  firmware instalado reportado: `8704` (`0x2200`), sin actualización pendiente.
- **Batería**: 100 % · **voltaje**: 3100 mV · **LQI**: 52–132 en banco.
- **Entidades reales**: `contact`, `battery`, `battery_low`, `tamper`, `voltage`,
  `linkquality`, `update`.
- **Botones**: el ovalado superior (junto al QR) es el botón de emparejamiento/reset; el
  alargado trasero es el pulsador de tapa/antimanipulación. No se usó eWeLink.
- **Emparejamiento**: `permit_join` 254 s; pulsación de 5 s del botón superior; parpadeo lento
  de 180 s; `Device joined` a las 14:03:15 e interview correcto a las 14:03:21. Después se
  renombró y se desactivó `permit_join`.

### Semántica observada de `contact`

| Payload | Significado físico |
|---|---|
| `contact: false` | imán junto al transmisor → **cerrado** |
| `contact: true` | imán separado → **abierto** |

Prueba de 5 aperturas/cierres: **10 transiciones**, una por cambio, sin duplicados. Los
eventos llegaron en 1–4 s según la manipulación manual; no se midió latencia con marcas
temporales. `tamper` alternó mientras se manipulaba la tapa y quedó en `false` con la tapa
correctamente colocada. Tras reiniciar el proyecto: `Adapter network matches config`,
`1 device joined`, `contact: true`, `tamper: false`.

- Backup con el sensor ya emparejado: `backups/zigbee-backup-20261026T134153Z.tar.gz`
  (+ `.sha256`).

## Límites y pendientes

- SNZB-04P validado solo en banco y a corta distancia; falta prueba de alcance/ubicación real.
- No se ha validado el SNZB-06P ni el relé ZBMINIL2.
- `permit_join` queda desactivado.
- La UI de Zigbee2MQTT solo escucha en loopback: se abre desde `oficina` en
  `http://127.0.0.1:8080`.
- El host definitivo, TLS/ACL del broker, firewall y backup fuera del nodo siguen pendientes.
- No se ha activado el worker MQTT del proyecto ni se ha conectado a la base de datos de la API.

## Operación básica en el banco

```bash
cd /opt/cerraduras-zigbee
./preflight.sh
./deploy.sh
./verify.sh
./backup.sh
./rollback.sh          # pide confirmación; conserva datos
```

Los secretos y `.env` permanecen en `/opt/cerraduras-zigbee` fuera de Git, con permisos
restrictivos. No copiar `.env`, `data/` ni `backups/` al repositorio.

## Siguiente paso

Emparejar el sensor de presencia SNZB-06P, observar sus entidades y payloads reales
(`occupancy`, `illuminance`, etc.), y validar falsos positivos/ausencias antes de definir el
mapa MQTT del worker. El relé ZBMINIL2 queda para después y requiere electricista.
