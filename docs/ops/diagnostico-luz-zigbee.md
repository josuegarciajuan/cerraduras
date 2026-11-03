# Diagnóstico de la luz Zigbee (F112)

Guía operativa para investigar un fallo de la luz `ZBMINIL2` (`almacen-zb-luz`)
sin depender de la sesión que lo implementó.

> **Si has llegado aquí porque «ha vuelto a fallar la luz Zigbee»:** no cortes la
> alimentación ni reemparejes nada todavía. Primero exporta la evidencia de la
> sección 4. El corte de alimentación recupera el dispositivo, pero **borra el
> estado que necesitamos para encontrar la causa**.

## 1. Qué hay implementado

### Evidencia persistente de órdenes

Cada orden a la luz queda en la tabla `switch_command_evidence`
(migración `0140`). Columnas relevantes:

| Columna | Significado |
|---|---|
| `operation_id` | identidad única e idempotente de la orden |
| `action` | `ON` u `OFF` |
| `origin` | `sensores_light`, `manual`, … |
| `requested_at` | instante de la petición (UTC) |
| `publish_ok` / `publish_error` | el broker aceptó o rechazó la publicación |
| `published_at` | instante de publicación aceptada |
| `prior_state` / `prior_state_at` | estado conocido **antes** de la orden |
| `reported_state` / `reported_at` | telemetría posterior |
| `fresh` | el reporte es posterior a la orden |
| `report_source` | `command`, `probe` o `none` |
| `human_state` / `human_at` | observación humana explícita |
| `verdict` | `confirmed`, `unconfirmed`, `discrepant`, `publish_failed`, `indeterminate` |
| `diagnosis` | motivo corto del veredicto |

Reglas de lectura:

- `published` **no** significa que la lámpara cambió.
- `reachable` indica respuesta en la ventana o sonda puntual; no que el estado
  sea el pedido.
- `fresh` indica reporte nuevo; no que coincida con la orden.
- Un estado anterior que coincide **no** confirma la orden nueva.
- `discrepant` es evidencia contradictoria, no un diagnóstico de hardware.

### Retención

- Script: `api/bin/switch-evidence-retention.php`.
- Variable: `SWITCH_EVIDENCE_RETENTION_DAYS` (por defecto 30). `0` = conservar.
- Timer: `cerraduras-switch-evidence-retention.timer` (diario 04:40).
- Manual:

```bash
cd /root/cerraduras/api
php bin/switch-evidence-retention.php --dry-run
php bin/switch-evidence-retention.php --days=90
```

### Consulta y exportación

```bash
# Últimas operaciones y estado actual con edad
curl -s 'http://127.0.0.1:8080/dashboard-api/sensores/v2/switch/diagnostics?room_id=14&window_minutes=120&limit=50' \
  | python3 -m json.tool

# Exportación legible para adjuntar a una incidencia
curl -s 'http://127.0.0.1:8080/dashboard-api/sensores/v2/switch/diagnostics/export?room_id=14&window_minutes=120&format=md' \
  -o /root/cerraduras-backups/luz-$(date -u +%Y%m%dT%H%M%SZ).md

# Exportación técnica
curl -s 'http://127.0.0.1:8080/dashboard-api/sensores/v2/switch/diagnostics/export?room_id=14&window_minutes=120&format=json' \
  -o /root/cerraduras-backups/luz-$(date -u +%Y%m%dT%H%M%SZ).json
```

### Fuentes y sus límites

La respuesta incluye `sources[]` con `status` ∈ `ok | partial | unavailable`:

| Fuente | Qué aporta | Límite |
|---|---|---|
| `device_telemetry` | último estado, instante y `last_seen_at` | solo el último reporte conocido |
| `zigbee_services` | estado de túnel, ingreso y spool | no prueba entrega al dispositivo |
| `zigbee2mqtt_log` | disponibilidad de un log configurado | **no se ingiere automáticamente** |

`zigbee2mqtt_log` estará `unavailable (not_configured)` mientras no exista
`ZIGBEE2MQTT_LOG_PATH` en `api/.env` apuntando a un fichero legible. **Aunque se
configure, esta comprobación solo verifica que el fichero existe y se puede
leer; no lo parsea.** Los logs de Zigbee2MQTT siguen recogiéndose a mano en
`oficina` (sección 4).

### Flujo de la prueba de luz en `/sensores`

1. `PREPARE_OFF`: la app pide dejar la lámpara apagada.
2. `READY_TO_START`: confirmas que está apagada. El panel avisa de que
   **«Empezar prueba» encenderá la luz automáticamente**.
3. `CONFIRM_ON`: respondes si se encendió.
4. `CONFIRM_OFF`: la app ya envió el apagado; respondes si se apagó.
5. `READY_TO_VALIDATE`: validas el paso.
6. `INDETERMINATE`: falta evidencia; puedes reintentar (ciclo nuevo) o cancelar.

La fase vive en la corrida (`steps.light.phase`). Recargar el panel no envía
órdenes. Cada acción usa un `operation_id` derivado de corrida + intento + ciclo
+ acción, así que un reintento del navegador no duplica la publicación.

## 2. Zona horaria: cuidado al correlacionar

- `switch_command_evidence`, la API y la BD usan **UTC**.
- Los logs de Zigbee2MQTT de `oficina` muestran **hora local (CET, UTC+1)**.

Resta una hora al log de Zigbee2MQTT para compararlo con la API. Un error de log
«cercano» en el tiempo es una **pista**, no una causa atribuida.

## 3. Interpretación rápida

| Observación | Lectura correcta |
|---|---|
| Sin fila en `switch_command_evidence` | la orden no llegó al endpoint; revisa el panel o la ruta |
| `publish_ok = 0` | el broker/túnel rechazó la publicación |
| `publish_ok = 1` y `reported_state` nulo | publicada, sin telemetría; puede ser redundante o no entregada |
| `publish_ok = 1`, `fresh = 1`, estado = acción | `confirmed` por telemetría |
| `publish_ok = 1`, `fresh = 1`, estado ≠ acción | `discrepant`: la orden no se aplicó o el reporte es de otro estado |
| `fresh = 0` y humano confirma | `confirmed` por observación humana |
| `fresh = 0` y sin humano | `unconfirmed`: no hay evidencia suficiente |
| `Delivery failed for '61600'` en el log | Zigbee2MQTT no pudo entregar al dispositivo |
| El dispositivo sigue reportando (LQI) | la vía dispositivo→servidor funciona; no prueba la inversa |

## 4. Protocolo ante un fallo nuevo

1. **No cortes la alimentación todavía.**
2. Anota la hora exacta (UTC) y qué viste físicamente.
3. Exporta la evidencia (sección 1) y guárdala fuera del repositorio:

   ```bash
   mkdir -p /root/cerraduras-backups
   ```

4. Recoge el log de Zigbee2MQTT en `oficina` (solo lectura):

   ```bash
   ssh oficina "docker logs --since 3h zigbee-local-zigbee2mqtt-1 2>&1" \
     | grep -E 'almacen-zb-luz|Delivery failed|device_announce|Adapter' \
     > /root/cerraduras-backups/zigbee2mqtt-luz-$(date -u +%Y%m%dT%H%M%SZ).log
   ```

5. Revisa la salud de los servicios y el estado del dispositivo:

   ```bash
   systemctl is-active cerraduras-zigbee-tunnel cerraduras-zigbee-ingress cerraduras-zigbee-spool
   curl -s 'http://127.0.0.1:8080/dashboard-api/sensores/v2/switch/diagnostics?room_id=14' | python3 -m json.tool
   ```

6. Si necesitas ver la topología o el coordinador, hazlo **sin reiniciar** ni
   reemparejar. El coordinador es un Dongle-M Ethernet/PoE
   (`172.16.51.215:6638`), no un USB del servidor.
7. Solo si el operador lo autoriza y la luz no responde: corte **solo de ese
   dispositivo**, anotando la hora. Al volver, espera `device_announce` y un
   reporte de estado.
8. Repite la consulta de diagnóstico y compara: ¿aparece `confirmed`? ¿sigue
   `unconfirmed`? ¿hay `discrepant`?
9. Documenta el incidente en la sección 6.

### Lo que NO se debe hacer

- No reemparejar el dispositivo como primer remedio.
- No borrar la red Zigbee ni la configuración de Zigbee2MQTT.
- No automatizar el corte de alimentación.
- No sondear la API REST de Tuya: el estado llega por push.
- No dejar bucles de `switch/test` ni de `/get`.

## 5. Antecedentes conocidos

Dos episodios del mismo relé, con recuperación tras un ciclo de alimentación y
sin reemparejar:

| Fecha | Hechos |
|---|---|
| 2026-11-02 | Fallos `genOnOff` con `Delivery failed for '61600'` entre ~12:16 y ~13:18 CET. Tras cortar y restablecer, `device_announce` y reportes `OFF`/`ON`. |
| 2026-11-03 | Fallo de lectura ~11:42 CET y de `OFF` ~11:51 CET. A las ~11:54 CET el relé publicó `state: ON` con LQI 80 (la lámpara estaba encendida). Tras un corte controlado, `device_announce` ~12:16 CET y `state: OFF`. Después, un ciclo manual `ON`/`OFF` confirmó telemetría fresca y respuesta física. |

**Causa raíz: no determinada.** Las hipótesis abiertas son el propio módulo o su
alimentación/carga (es «no neutral»), la ruta/radio Zigbee y un fallo parcial del
coordinador o su enlace Ethernet/PoE. Que otros dispositivos sigan reportando
descarta una caída total sostenida, no un problema parcial.

**El corte de alimentación es un remedio observado, no una solución.** No debe
automatizarse ni presentarse como reparación.

## 6. Registro de incidentes

Añade una fila por incidente nuevo:

| Fecha/hora UTC | Operación | `verdict` | Síntoma | Acción tomada | Conclusión |
|---|---|---|---|---|---|
| 2026-11-03 11:54 | — | — | sin respuesta a `OFF`; luego `ON` espontáneo | corte de alimentación | recuperado, causa desconocida |

## 7. Referencias

- Contrato y fases: `.specs/specs/contracts.md` §F112.
- Diseño y decisiones: `.specs/specs/design.md` §F112.
- Requisitos: `.specs/specs/requirements.md` §F112.
- Preparación de la prueba física: `docs/ops-sensores.md` §12.
- Zigbee local y servicios: `docs/ops-zigbee.md`.
