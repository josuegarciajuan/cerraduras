# F107 — Evidencia de distancia del radar (Fase 0)

Fecha: 2026-11-02
Estado: descubrimiento completado; sin cambios permanentes en producción.

## Entorno

| Elemento | Valor |
|---|---|
| Host Zigbee2MQTT | `oficina` (`172.16.51.242`) |
| Zigbee2MQTT | 2.14.2 (Docker) |
| Broker | Mosquitto local del mismo host |
| Dispositivo | `almacen-zb-presencia` |
| IEEE | `0xa4c138b2a3c49dbe` |
| Fabricante | `_TZE204_qasjif9e` |
| Modelo | `ZY-M100-S_2` (`TS0601`) |
| Firmware | Zigbee 1.0.10 / MCU 1.0.3 |
| Conversores externos | Ninguno (antes de esta prueba) |

## Mapa de datapoints verificado en el conversor

| DP | Atributo | Conversión |
|---|---|---|
| 1 | `presence` | booleano |
| 9 | `target_distance` | valor / 100 (m) |
| 12 / 104 | `illuminance` | valor directo |
| 2 | `radar_sensitivity` | directo |
| 3 / 4 | rango mínimo / máximo | / 100 |
| 101 / 102 | retardo / fading | / 10 |

`target_distance` se declara como `STATE` (solo push en el conversor estándar).

## Evidencia histórica

Analizando los logs de Zigbee2MQTT del 2026-11-02:

- **1.820** publicaciones con `target_distance = 0`.
- Solo **14** publicaciones con distancia positiva.
- Con presencia y quietud se observó un hueco de **164 segundos sin ningún
  mensaje** (08:17:49 → 08:20:33 UTC).
- Varios mensajes consecutivos repitieron el mismo valor (`3.23`) mientras
  cambiaba la iluminancia. Eso demuestra que el estado combinado puede incluir
  una distancia cacheada.

Conclusión histórica: Zigbee2MQTT publica cuando cambia cualquier atributo.
No hay una publicación periódica garantizada. Un mensaje con
`target_distance` no demuestra por sí solo que el DP 9 haya llegado en esa
trama.

## Prueba de `dataQuery`

Se añadió temporalmente un conversor externo con el mismo modelo y un
`configure` que enviaba `dataQuery`. Con la sala vacía:

- El dispositivo respondió con `commandDataReport` que contenía
  `dpValues: [{ dp: 9, datatype: 2, data: 0 }]`.
- Por tanto, **el firmware sí atiende `dataQuery` y devuelve el DP 9**, incluso
  cuando la distancia vale `0` y no ha cambiado.

### Incidente y rollback

El `dataQuery` se envió desde `configure`. Zigbee2MQTT vuelve a configurar el
dispositivo en cada cambio de `lastSeen`, por lo que cada respuesta provocaba
otra configuración y otra consulta: un bucle de consultas.

Se detuvo inmediatamente:

1. Se restauró `configuration.yaml` desde el backup previo.
2. Se eliminó el conversor externo temporal.
3. Se reinició Zigbee2MQTT.
4. Se verificó `log_level: info`, ausencia de `dataQuery` y cadencia normal.

No quedaron cambios permanentes.

## Reglas de diseño derivadas

1. **`dataQuery` solo bajo demanda**, nunca desde `configure`, `onEvent` de
   `lastSeen`, ni un temporizador periódico.
2. Una consulta debe ser **explícita** y acotada: por ejemplo, una orden
   `distance_query` en un `toZigbee` del conversor, invocada solo al iniciar o
   cerrar una medición.
3. La respuesta debe transportar el DP 9 como evidencia, no solo el estado
   combinado.
4. El sistema debe ignorar respuestas duplicadas o retransmitidas y limitar el
   número de consultas por ventana.
5. No se debe consultar Tuya Cloud; todo ocurre por Zigbee/MQTT local.

## Decisión para la siguiente fase

El camino viable es:

- Conversor externo con una orden `distance_query` (sin `configure` automático).
- Envío de una consulta al abrir la ventana de medición y, si hace falta, una
  segunda consulta de confirmación.
- Evidencia por atributo en MQTT y almacenamiento separado.
- El algoritmo podrá aceptar distancias iguales porque sabrá que proceden de
  respuestas DP 9 independientes.

Queda pendiente comprobar con presencia real que una consulta devuelve una
distancia positiva actual. Esa comprobación se hará durante la calibración en
modo sombra, no con un protocolo físico dedicado.

## Fase 1 — instalación segura

- Conversor en `data/external_converters/zy-m100-distance-evidence.js`.
- Loaded sin errores y sin `configure` con `dataQuery`.
- Marcas reales observadas con distancia `0` en sala vacía y valores
  `0.62–3.43 m` con presencia/movimiento.
- Consulta única probada: no deja bucle.
- `radar_distance_reports` recibe las marcas; la clave única evita duplicados.

## Fase 3 — modo sombra

- `RADAR_DISTANCE_EVIDENCE_MODE=shadow` y
  `RADAR_DISTANCE_QUERY_ENABLED=true` en pruebas.
- `/radar/state` expone `distance_mode`, `distance_point` y `distance_query`.
- El asistente muestra «Distancia del radar (provisional)».
- El resultado no altera la calibración existente.

Pendiente: validación con una persona en el punto interior/exterior y decisión
de activar el modo `active`.
