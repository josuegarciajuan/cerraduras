# F108 — Resultado de la consulta directa (`dataQuery`)

Fecha: 2026-11-02
Ámbito: nodo `oficina`, Zigbee2MQTT 2.14.2, radar `almacen-zb-presencia`
(`ZY-M100-S_2`, `_TZE204_qasjif9e`).

## Prueba realizada

1. Se verificó que la definición activa del dispositivo es **externa**:
   `source: "external"`, con `f107_distance_report` y `distance_query` entre
   sus propiedades.
2. Se confirmó que el conversor `toZigbee` contiene `distance_query`.
3. Se activó temporalmente `log_level: debug`.
4. Se reinició Zigbee2MQTT y se comprobó que **no se envió `dataQuery` al
   arrancar** ni desde `configure`.
5. Se publicó **una única** orden:
   `zigbee2mqtt/almacen-zb-presencia/set` → `{"distance_query":"request"}`.
6. Se capturaron los logs de Zigbee2MQTT durante 15 segundos.
7. Se restauró `log_level: info` y se reinició el servicio.

## Evidencia

La petición llegó y el conversor se ejecutó:

```text
z2m:mqtt: Received MQTT message on 'zigbee2mqtt/almacen-zb-presencia/set'
  with data '{"distance_query":"request"}'
z2m: Publishing 'set' 'distance_query' to 'almacen-zb-presencia'
zh:controller:endpoint: ZCL command 0xa4c138b2a3c49dbe/1
  manuSpecificTuya.dataQuery({}, ...)
```

Pero el envío al dispositivo falló:

```text
zh:ember:ezsp: ezspMessageSentHandler:
  status=ZIGBEE_DELIVERY_FAILED type=DIRECT indexOrDestination=3190
zh:ember: ~x~> DELIVERY_FAILED [indexOrDestination=3190 ...]
```

## Interpretación

- La orden **sí llegó a Zigbee2MQTT**.
- El conversor **sí se seleccionó y ejecutó**.
- El comando **no fue entregado al radar**.
- Por tanto, **no se puede afirmar que exista una consulta directa útil**.
- Los DP 9 que aparecieron después eran reportes espontáneos. El marcador
  `origin=query` de ese momento fue una atribución heurística y **no debe
  considerarse una respuesta**.

Además, durante la prueba también hubo `ZIGBEE_DELIVERY_FAILED` hacia la luz
`almacen-zb-luz`, lo que sugiere un problema de entrega directa en la red
Zigbee, no una capacidad del radar. No se investiga aquí porque la conclusión
para la calibración es la misma: **no depender de consultas directas**.

## Decisión

1. **No usar `dataQuery` en el flujo de calibración.**
2. Mantener `RADAR_DISTANCE_QUERY_ENABLED=false`.
3. No presentar en el panel una consulta como «enviada» o «confirmada» si el
   comando no se entrega.
4. El algoritmo debe basarse en **reportes DP 9 push**, aceptando valores
   iguales cuando tengan identidad independiente.
5. Si en el futuro se investiga la entrega Zigbee, se hará como incidencia de
   red separada, no como requisito de esta calibración.

## Consecuencias para las siguientes fases

- Fase 3: eliminar o marcar como no atribuido cualquier `origin=query`
  producido por la heurística de los cinco segundos.
- Fase 6/7: retirar la promesa de consulta en tiempo real del panel.
- La prueba física final usará únicamente la evidencia push del radar.
- No se enviarán más órdenes `distance_query` durante las pruebas.
