# F111 fase 0 — Evidencia de presencia del radar (DP 1)

Fecha: 2026-11-03
Estado: sonda de viabilidad completada y conversor aditivo desplegado.
Ámbito: `ZY-M100-S_2` de `almacenZigbee` (IEEE `0xa4c138b2a3c49dbe`).

## Objetivo

Determinar si una **presencia auténtica** puede distinguirse de un valor
heredado en el payload combinado de Zigbee2MQTT, antes de construir la
verificación exterior de la calibración.

## Entorno

| Elemento | Valor |
|---|---|
| Host Zigbee2MQTT | `oficina` |
| Zigbee2MQTT | 2.14.2 (contenedor `zigbee-local-zigbee2mqtt-1`) |
| Broker | Mosquitto local del mismo host |
| Conversor | `docs/zigbee2mqtt/external_converters/zy-m100-distance-evidence.js` |
| Backup previo | `backups/zigbee-backup-20261103T090700Z.tar.gz` |
| Backup dirigido | `backups/{configuration,external-converters}-f111-20261103T090705Z.*` |

## Cambio desplegado

El conversor emite ahora **dos marcadores independientes**:

| Campo | DP | Formato | Cuándo cambia |
|---|---|---|---|
| `f107_distance_report` | 9 | `v1\|<session>\|<c>\|<m>\|push` | En cada trama con DP 9 |
| `f107_presence_report` | 1 | `p1\|<session>\|<c>\|<PRESENT\|ABSENT>\|push` | Solo cuando llega un DP 1 nuevo |

- El contador de presencia es independiente: no altera la secuencia del
  marcador de distancia de F108.
- El conversor no añade `configure`, `dataQuery` ni ningún comando al
  dispositivo. Se mantiene el incidente F107 cerrado.
- El marcador solo se emite con longitud de DP válida (1 o 4 bytes). No se
  inventa presencia desde bytes ambiguos.

## Hallazgos empíricos

### 1. El radar publica periódicamente, no solo al cambiar

En reposo se observaron **1.223 tramas en 30 minutos** (~1 cada 1,5 s), todas
con `target_distance = 0` y el marcador DP 9 incrementando. Es decir, este
firmware emite *state reports* periódicos.

Consecuencia: el silencio prolongado del radar **no es el caso normal**; si no
llegan tramas, es una señal real de comunicación.

### 2. DP 1 solo viaja cuando cambia la presencia

Durante una presencia real de ~5 s:

```text
f107_presence_report = p1|mw0gscxb|1|PRESENT|push   (12 tramas, mismo marcador)
f107_presence_report = p1|mw0gscxb|2|ABSENT|push    (18 tramas, mismo marcador)
```

- El marcador se repite **idéntico** en todas las tramas posteriores mientras
  el estado no cambia, aunque cambien iluminancia, LQI o distancia.
- Un cambio real de estado produce un marcador nuevo (contador distinto).
- Por tanto, el marcador **sí distingue** un reporte auténtico de un valor
  cacheado en el payload combinado.

### 3. Distancias reales observadas durante la presencia

`1,30 · 0,68 · 1,64 · 3,40 · 2,66 · 3,36 m`, con DP 9 cambiando en cada trama
mientras el marcador DP 1 permanecía estable.

### 4. La ingesta sigue funcionando sin cambios

Los eventos siguen llegando a `presence_events`. En modo `camera_fusion` se
registran como `noop` / `candidate_absent` cuando el radar no está corroborado
por cámara o puerta. Eso confirma que **la calibración debe usar la evidencia
cruda del sensor**, no el estado fusionado de la sala.

## Implicaciones para el diseño (F111)

1. **Exterior:** una transición real a `ABSENT` (contador nuevo) es evidencia
   suficiente de que el radar dejó de detectar; no hace falta exigir dos
   eventos ni una distancia positiva.
2. **Reentrada:** exige un marcador `PRESENT` con contador nuevo posterior al
   inicio de la acción, no un valor heredado.
3. **Inconcluso:** se reserva para falta de tramas, configuración cambiada,
   puerta abierta o interrupción conocida del transporte.
4. **Sin migración todavía:** el marcador DP 1 llega por MQTT, pero la
   aplicación aún no lo persiste. El almacenamiento y el parser corresponden a
   la Fase 1.

## Rollback

```bash
ssh oficina 'set -e; cd /opt/cerraduras-zigbee;
  cp -a backups/configuration-f111-20261103T090705Z.yaml data/zigbee2mqtt/configuration.yaml;
  tar -xzf backups/external-converters-f111-20261103T090705Z.tar.gz -C data/zigbee2mqtt;
  docker restart zigbee-local-zigbee2mqtt-1'
```

El marcador es aditivo: revertir el conversor no afecta a F107/F108 ni a la
calibración actual.
