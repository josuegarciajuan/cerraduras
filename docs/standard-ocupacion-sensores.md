# Ocupación STANDARD, sensores y radar (F138)

Este documento es la referencia canónica para **todas las habitaciones STANDARD**.
No existe una rama “hotelera” separada: QR de reserva, llave QR, ficha, puerta,
radar y estancia alimentan el mismo motor.

Almacén (`ALMACEN_BEBIDAS`) y timbrado (`TIMBRADO`) conservan su flujo propio.

## 1. Capas separadas

| Capa | Qué es | Quién manda |
|---|---|---|
| Señal física | DP1 (presencia), DP9 (distancia), contacto de puerta | Sensor |
| Ocupación | `VACANT_CONFIRMED`…`OCCUPANCY_UNCERTAIN` | Motor STANDARD |
| Luz | `light_desired`, estado reportado del switch | Switch + motor |
| Presentación | banner, badge, monigote, bombilla | Proyección del servidor |

La UI **no** decide presencia, ocupación, luz ni posición. Solo representa.

## 2. Radar

- El DP de presencia es **DP1**. No hay DP0 en el conversor local.
- El DP de distancia es **DP9**.
- El alcance máximo se escribe al radar por **DP4**; además existe una barrera
  software histórica. Ninguna de las dos es una valla física perfecta.
- DP9 puede ser cero, escaso, cacheado o exterior. **No se usa para crear
  ausencia ni para apagar una sala ocupada.**
- DP1 puede llegar antes que DP9; no se espera DP9 para detectar.
- DP1 tiene un heartbeat periódico (~10 min). Un marcador nuevo con el mismo
  valor no es una transición.
- Silencio, gap o configuración incierta = incertidumbre; nunca vacío.
- Un DP9 exterior se registra como `RANGE_CHALLENGED` y no cambia la ocupación.

## 3. Estados

| Estado | Significado | Luz |
|---|---|---|
| `VACANT_CONFIRMED` | Vacío confirmado | OFF permitido |
| `ACCESS_PENDING` | Credencial válida, esperando entrada | ON |
| `DOOR_OPEN` | Puerta abierta; monigote en umbral | ON |
| `ENTRY_VERIFY` | Puerta cerrada; comprobando entrada | ON |
| `OCCUPIED` | Presencia plausible/confirmada | ON |
| `EXIT_VERIFY` | Ciclo de puerta con posible salida | ON |
| `OCCUPANCY_UNCERTAIN` | Evidencia insuficiente o contradictoria | ON protegida |

## 4. Reglas

1. **DP1 PRESENT** → presencia plausible, cancela OFF, solicita ON.
2. **DP1 ABSENT en OCCUPIED sin ciclo de salida** → `OCCUPANCY_UNCERTAIN`;
   no se apaga. Puede ser una persona inmóvil o un falso negativo.
3. **DP1 ABSENT con ciclo de salida reciente** → `EXIT_VERIFY`.
4. **Puerta abierta** → siempre `DOOR_OPEN`; el monigote no entra.
5. **Cierre con presencia** → `OCCUPIED`; si el ciclo era de salida, se
   conserva durante una ventana corta para admitir una ausencia inmediata.
6. **Cierre sin presencia** → `ENTRY_VERIFY` con plazo.
7. **Vacío** solo si: `ENTRY_VERIFY`/`EXIT_VERIFY`, DP1 ABSENT auténtico,
   continuidad del radar, ciclo de puerta/acceso y guarda cumplida.
8. **Sin datos** → incertidumbre; se conserva ocupación y luz.
9. **Switch sin confirmar** → se conserva la intención y se reintenta con
   límite. `confirmed_off` exige telemetría posterior a la orden; un PUBACK es
   `published_off_unconfirmed`. La bombilla muestra `?` si el reporte es
   anterior al último comando.
10. **Reset de ciclo** limpia ocupación, plazos, intención de luz y episodios.

## 5. Tiempos iniciales

| Variable | Default | Uso |
|---|---:|---|
| `STANDARD_PRESENCE_GUARD_MS` | 400 ms | Anti-parpadeo de presencia |
| `presence_entry_window_seconds` | 20 s (STANDARD) | Entrada sin presencia |
| `STANDARD_VACANCY_GUARD_SECONDS` | 10 s | Confirmación de vacío |
| `STANDARD_LIGHT_OFF_DELAY_SECONDS` | 1 s | Retardo de OFF cancelable |
| `STANDARD_EXIT_CYCLE_MAX_SECONDS` | 30 s | Ventana de ciclo de salida |
| `RADAR_PRESENCE_STALE_SECONDS` | 30 s | Radar “sin datos” |
| `STANDARD_EVENT_MAX_AGE_SECONDS` | 600 s | Descarta eventos históricos |
| `ZIGBEE_SPOOL_MAX_EVENT_AGE_SECONDS` | 600 s | No aplica mensajes recuperados antiguos |

La entrada se puede ajustar por tipo de sala. El vacío es deliberadamente más
lento que la presencia.

## 6. Persistencia

Columnas aditivas en `iot_sessions` (migración `0153`):

- `occupancy_state`
- `occupancy_json`
- `entry_deadline_at`
- `vacancy_deadline_at`
- `light_desired`
- `light_last_result`
- `radar_health`
- `uncertain_since`

Migración `0154` limpia `presence_mode_override` en STANDARD para que ninguna UI
o servicio antiguo presente otra política. Las salas especiales no se tocan.

## 7. Invariantes de regresión

- Misma secuencia de hechos con 0, 1 o varias UIs → mismo estado.
- Ningún GET mueve ocupación, luz o calibración operativa.
- Ningún timeout solo apaga si hay presencia o duda.
- DP9 exterior no vacía una sala ocupada.
- Puerta abierta → monigote en umbral.
- Bombilla = estado reportado del switch; si no hay, último comando con `?`.
- Almacén y timbrado no cambian.

## 8. Pruebas

- `tests/Unit/StandardOccupancyPolicyTest.php`
- `tests/Unit/StandardOccupancyEngineTest.php`
- `croquis-logic.test.js` (monigote y umbral)
- `radar-scope.test.js` (raw vs ocupación)
- Regresión completa aislada antes de publicar.

La validación física se hace en PROTO2 con puerta, radar y luz. DEVELOPMENTROOM
sirve para probar el sensor aislado, no para validar la ocupación completa.
