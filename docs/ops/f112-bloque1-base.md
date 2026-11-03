# F112 · Bloque 1 — Base, evidencia y congelación de alcance

Fecha: 2026-11-03
Rama: `work/20261103T115630Z-f112-bloque1`

Este documento cierra el **Bloque 1** del plan F112. No contiene cambios de
código: fija la evidencia de partida, el alcance permitido y los contratos que
implementarán los bloques siguientes.

## 1. Evidencia de la corrida de referencia

Corrida abierta en el panel: `14-20261103T103500Z-e0f21c`
(sala 14, `almacenZigbee`, tipo `ALMACEN_BEBIDAS`, operador `Jos`).

| Elemento | Valor observado |
|---|---|
| Estado de la corrida | `running` |
| Plan | `door → radar_inside → radar_outside → light` |
| Resumen | `pass=3`, `pending=1`, `total=4`, `green=false` |
| Puerta | `PASS` |
| Radar interior | `PASS` |
| Radar exterior / reentrada | `PASS` |
| Luz | pendiente, **sin intento creado** |
| Cierre | `updated_at = 2026-11-03T10:41:41Z` |

### Dispositivos de la sala

| id | kind | external_id | estado relevante |
|---|---|---|---|
| 437 | PROXIMITY | `0xc09b9efffef431fb` | puerta Zigbee |
| 438 | PRESENCE | `0xa4c138b2a3c49dbe` | `calibration.maximum_range=3.45`, `calibration_previous=3.6` |
| 439 | SWITCH | `0xf044d3fffecd9e8e` | `switch_state=OFF`, `switch_state_at=2026-11-03T11:29:16.454Z` |

### Hecho diferencial importante

En la corrida `…e0f21c` **no existe intento de luz**. El paso `light` se
materializa de forma diferida al crear el intento. Por tanto:

- Los mensajes «la luz no responde» vistos durante la sesión provienen de la
  sonda bajo demanda `switch/test` y del intento cancelado de la corrida
  anterior `14-20261102T144427Z-e0d72b`, no de un intento registrado en esta
  corrida.
- El `PASS` de puerta y radar no debe reinterpretarse al rehacer la fase de luz.

### Copia de seguridad

Evidencia exportada fuera del repositorio (no versionada):

```
/root/cerraduras-backups/f112-20261103T115630Z/
  14-20261103T103500Z-e0f21c.json
  14-20261103T103500Z-e0f21c.jsonl
  SHA256SUMS
```

`fc2f5a2ba80ac410e80fe1aba5bf1ba9ae298ed396740b5958ad817c73fa13dd` (JSON)
`8f9183fbe75c36fd5790d8c5931c619644465e17016822aa9f16d8a892d844ba` (JSONL)

## 2. Alcance congelado

### Se implementa

1. Evidencia persistente de órdenes de luz (publicada, confirmada, sin
   confirmar, discrepante, fallida).
2. Contexto de infraestructura útil para analizar un incidente, con fuentes no
   disponibles declaradas de forma explícita.
3. Consulta y exportación de esa evidencia.
4. Documentación operativa permanente (Bloque 4).
5. Flujo de luz del calibrador: preparación apagada, encendido automático al
   pulsar «Empezar prueba», confirmaciones humanas y fases persistidas.
6. Reanudación sin duplicar órdenes.

### No se modifica

- `RadarCalibrationPlanner`, medición interior, evaluador exterior, reentrada.
- Semántica de `attempt/start|check|cancel` para `door`, `radar_inside` y
  `radar_outside`.
- `preflight` más allá de campos aditivos.
- Base F110 ni el reset de corrida.
- Firmware, conversores Zigbee y configuración de Zigbee2MQTT.
- Cualquier polling REST de Tuya.

### Salvaguardas de no regresión

- Todo campo nuevo en respuestas existentes es **aditivo**.
- El código nuevo de luz se activa solo con `step_id = "light"`.
- Los bloques del runner de puerta, radar interior, radar exterior, F109 y F110
  deben pasar sin cambios en sus aserciones.
- Un test comprueba que los archivos de dominio del radar no cambian de
  comportamiento (mismas firmas y mismos motivos de decisión).

## 3. Decisiones de diseño para los bloques siguientes

### 3.1 Persistencia de órdenes

Se creará la tabla aditiva `switch_command_evidence` (migración `0140`).
No se reutiliza `device_commands`: esa cola pertenece al ciclo
`pending/picked_up/done/timeout` de los ESP y no modela confirmación por
telemetría, observación humana ni correlación con logs de Zigbee2MQTT.

Campos previstos (detalle final en contrato del Bloque 2):

- identidad: `operation_id` único, sala, dispositivo, `external_id`, proveedor;
- petición: origen, acción, instante, actor;
- publicación: resultado, error, instante;
- estado previo: valor e instante;
- confirmación: valor reportado, instante, frescura, origen;
- veredicto: `confirmed`, `unconfirmed`, `discrepant`, `publish_failed`,
  `indeterminate`;
- observación humana separada de la telemetría.

Índices previstos: `UNIQUE(operation_id)`, `(room_id, requested_at)`,
`(device_id, requested_at)`.

Retención: script `bin/switch-evidence-retention.php` con timer systemd diario,
patrón de `radar-distance-retention`. Días configurables; `0` = conservar.

### 3.2 Fases de la prueba de luz

Se persisten en la corrida (`steps.light`), no en el navegador:

```
PREPARE_OFF → READY_TO_START → ON_PENDING → CONFIRM_ON
            → OFF_PENDING → CONFIRM_OFF → READY_TO_VALIDATE
            → DONE | INDETERMINATE
```

Reglas:

- `PREPARE_OFF` y `READY_TO_START` no envían ninguna orden por sí solas.
- Solo la acción explícita del operador envía `ON`.
- Una recarga reconstruye el estado y **nunca** reenvía una orden.
- Repetir una petición con el mismo `operation_id` devuelve el resultado
  conocido sin actuar de nuevo.
- Un resultado desconocido se muestra como `INDETERMINATE`, nunca como éxito.

### 3.3 Diagnóstico de infraestructura

- Se usará primero lo que ya llega al sistema (eventos del bridge, anuncios,
  estado del dispositivo, salud de ingreso/spool/túnel).
- Lo que no esté disponible se devuelve como `unavailable` con instrucciones de
  recogida manual; no se simula.
- La proximidad temporal de un error de Zigbee2MQTT es una pista, no una causa
  atribuida, salvo correlación fiable.

## 4. Estrategia de pruebas

Entorno: producción, autorizado para las pruebas necesarias.

- Antes de la regresión completa: volcado lógico de BD a una ruta protegida.
- Tests dirigidos al cerrar cada bloque.
- Regresión completa solo en el Bloque 5, fuera de una prueba física.
- Resultado esperado: `0 failed`. Un fallo no se convierte en `SKIP`.
- Si el runner modifica estado de pruebas, aplicar su snapshot/restore y
  verificar después luz, puerta y radar.

## 5. Bloques y estado

| Bloque | Contenido | Estado |
|---|---|---|
| 1 | Base, evidencia y alcance | **Cerrado con este documento** |
| 2 | Evidencia persistente, contexto, consulta y exportación | Pendiente |
| 3 | Máquina de estados de luz y panel `/sensores` | Pendiente |
| 4 | Runbook y documentación permanente | Pendiente |
| 5 | Regresión completa en producción | Pendiente |
| 6 | Publicación final y prueba física del usuario | Pendiente |

## 6. Criterios de aceptación del bloque

- [x] Corrida identificada y resumida sin alterarla.
- [x] Copia de seguridad verificable fuera del repositorio.
- [x] Alcance permitido y prohibido escrito.
- [x] Decisiones de persistencia y fases fijadas.
- [x] Estrategia de pruebas acordada.
- [x] Sin cambios de código ni de datos de la corrida.
