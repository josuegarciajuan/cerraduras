# ESP-imán — ensayos de mantenimiento (F97)

> Estado: **ensayo en banco**. Los valores no son definitivos hasta medir fuerza,
> temperatura y estabilidad de la alimentación.

## Historial de perfiles

| Perfil | Mantenimiento | Resultado |
|---|---|---|
| F97.1 | 950 ms ON / 50 ms OFF | Seguía dando poca fuerza |
| **F97.2 (actual)** | **Continuo a máxima potencia** | Pendiente de medir |

## F97.2 — máxima potencia (actual)

Objetivo: comprobar la fuerza real del imán sin ningún parpadeo de
mantenimiento. Es la referencia de máxima potencia y también la de mayor
consumo y calentamiento.

- Sketch: `firmware/esp-iman/`
- Versión: `2.2-iman-continuous`
- `IMAN_HOLD_CONTINUOUS=1`: la salida permanece encendida.
- `IMAN_HOLD_CONTINUOUS=0` recupera el perfil pulsado 950/50.
- El arranque registra por serie:
  `[IMAN] mantenimiento CONTINUO (ensayo F97.2 máxima potencia)`.
- Apertura sin cambios: QR → OFF; `OPEN` → OFF indefinido; cierre estable →
  vuelta a mantenimiento.

## Respaldo

```text
/home/oficina/esp-backups/r35d-pre-f97-20261029-131734.bin
```

## Protocolo de medida

1. Puerta cerrada y sensor Zigbee en `CLOSED`; confirmar `HOLDING`.
2. Medir fuerza de retención con dinamómetro o báscula, siempre en el mismo
   punto y ángulo. Anotar el valor.
3. Medir temperatura ambiente y de la fuente/conversor antes de empezar.
4. Comprobar a **1, 3, 5, 10 y 15 minutos**:
   - fuerza de retención;
   - temperatura de fuente/conversor y del imán;
   - ruido, vibración u olor;
   - reinicios del ESP o pérdida del canal SSE.
5. Probar apertura y cierre:
   - QR correcto → el imán debe quedar sin corriente;
   - puerta abierta → sin corriente mientras siga abierta;
   - cierre → vuelve a mantenimiento tras la confirmación.

## Criterios de parada

Detener el ensayo y desenergizar si:

- la fuente o el conversor superan su temperatura nominal;
- la temperatura sube de forma rápida y sostenida;
- hay olor, zumbido anómalo o calentamiento del imán;
- el ESP se reinicia o pierde el canal de puerta.

## Resultados F97.2

| Tiempo | Fuerza | Temp. fuente | Temp. imán | Observaciones |
|---|---|---|---|---|
| 0 min | | | | |
| 1 min | | | | |
| 3 min | | | | |
| 5 min | | | | |
| 10 min | | | | |
| 15 min | | | | |

## Decisión

- Si a máxima potencia la fuerza es suficiente pero la temperatura es alta:
  revisar alimentación y etapa de potencia; no volver a bajar el tiempo de
  corte a ciegas.
- Si a máxima potencia la fuerza sigue siendo insuficiente: el problema no es
  el perfil de mantenimiento; revisar el imán, el voltaje real en la bobina,
  el cableado y la mecánica de la puerta.
- Si se necesita reducir consumo más adelante: valorar PWM/MOSFET validado por
  el ingeniero, no un relé mecánico conmutando rápido.
