# ESP-imán — ensayo de mantenimiento 950/50 (F97.1)

> Estado: **ensayo en banco**. No es un valor definitivo hasta medir fuerza,
> temperatura y estabilidad de la alimentación.

## Motivo

Con el perfil 100 ms ON / 100 ms OFF el imán perdía demasiada fuerza. El corte
de 100 ms permite que la corriente de la bobina caiga y la atracción disminuye.
El perfil 950/50 mantiene corriente el 95 % del tiempo y reduce mucho la caída,
a costa de consumir casi lo mismo que en alimentación continua.

## Firmware

- Sketch: `firmware/esp-iman/`
- Versión: `2.1-iman-hold950`
- Mantenimiento: `IMAN_HOLD_ON_MS=950`, `IMAN_HOLD_OFF_MS=50`
- Apertura sin cambios: QR → OFF; `OPEN` → OFF indefinido; cierre estable →
  vuelta a mantenimiento.
- El arranque registra por serie:
  `[IMAN] mantenimiento 950 ms ON / 50 ms OFF`.

## Respaldo

Antes del ensayo ya se guardó la flash completa del equipo:

```text
/home/oficina/esp-backups/r35d-pre-f97-20261029-131734.bin
```

## Protocolo

1. Puerta cerrada y sensor Zigbee en `CLOSED`; confirmar estado `HOLDING`.
2. Medir fuerza de retención con dinamómetro o báscula, siempre en el mismo
   punto y ángulo. Anotar el valor.
3. Medir temperatura ambiente y de la fuente/conversor antes de empezar.
4. Comprobar a 1, 5, 15 y 30 minutos:
   - fuerza de retención;
   - temperatura de fuente/conversor;
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

## Resultados

| Tiempo | Fuerza | Temp. fuente | Temp. imán | Observaciones |
|---|---|---|---|---|
| 0 min | | | | |
| 1 min | | | | |
| 5 min | | | | |
| 15 min | | | | |
| 30 min | | | | |

## Decisión

- Si la fuerza es aceptable y la temperatura se mantiene dentro de límites:
  conservar 950/50 como base de pruebas.
- Si la fuerza mejora pero la temperatura es alta: revisar alimentación y etapa
  de potencia; no seguir reduciendo el tiempo de corte a ciegas.
- Si la fuerza sigue siendo insuficiente: valorar alimentación continua o una
  etapa PWM/MOSFET validada por el ingeniero.
