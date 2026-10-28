# Calibración y verificación de sensores (F93/F95)

## Flujo universal `/sensores` (F95, Zigbee)

Válido para los tres dispositivos de `almacenZigbee` (SNZB-04P, ZY-M100-S_2,
ZBMINIL2):

1. **Preflight**: muestra los 3 dispositivos (verde/rojo/ámbar) y el plan.
2. **Puerta**: cierra y pulsa `Comprobar polaridad`. La app corrige el montaje
   invertido solo en ese dispositivo. Después, 3 ciclos (normal, lento, rápido).
3. **Radar dentro**: colócate en el punto más lejano y quieto 30 s. Si no detecta,
   `Ajustar si no detecta` sube sensibilidad/rango con confirmación por push.
4. **Radar fuera**: sal, cierra, pasa 3 veces y espera 60 s. Si detecta de más,
   `Ajustar si detecta de más` baja un escalón.
5. **Luz**: `Encender`/`Apagar` y confirma físicamente.
6. **Veredicto**: descarga el informe. `Comprobar reposo` revisa los últimos
   30 min cuando la sala lleve ese tiempo vacía (sin proceso de fondo).

La puerta y el radar son obligatorios; si no hay luz, el paso queda `NO INSTALADO`.

**Indicadores de salud**: el radar (Router) reporta periódicamente; si deja de
hacerlo aparece `SIN DATOS` (ámbar). La puerta (SNZB-04P) y la luz (ZBMINIL2) son
EndDevice y solo envían al cambiar: si no hay actividad reciente se muestran como
`RECONOCIDO` con “sin actividad hace X” (verde). No es un fallo de acceso; se
actualizan al abrir/cerrar o conmutar.


Procedimiento para puerta y presencia con datos reales. No se sondea Tuya: la captura
lee eventos ya persistidos (push) y las marcas físicas del técnico.

## 1. Antes de empezar

- Confirmar la sala, el pack y el transporte de cada dispositivo:
  `ZIGBEE2MQTT` (SNZB-04P + ZY-M100) o Tuya.
- Confirmar el tipo de habitación (`ALMACEN_BEBIDAS` o `TIPO_NORMAL`).
- Comprobar que el consumer/ingress está recibiendo (`api/run/pulsar-consumer-status.json`
  o `health/deep` para Zigbee).
- Anotar la configuración actual del radar: `GET /dashboard-api/presence-calibrate/status?room_id=N`.

Nunca dejar un proceso de sondeo de Tuya en marcha.

## 2. Asistente guiado `/sensores` (recomendado)

Abre `https://<host>/sensores` en el móvil u ordenador del técnico:

1. Sala (por defecto la del piloto Zigbee) y operador.
2. `▶ Empezar corrida`.
3. Sigue la tarjeta: acción física → `✅ Hecho, validar`. El servidor valida la ventana y
   responde PASS/FAIL con el detalle.
4. Si algo no se puede validar solo, usa `Marcar fallo` u `Omitir` (quedan en el informe).
5. En el bloque de calibración puedes leer y aplicar ajustes; se confirman con un reporte
   nuevo del radar.
6. Al final: `✔ Finalizar` y descarga JSON/Markdown. Las corridas quedan en
   `api/run/sensores/`.

Pasos largos (sala vacía, persona inmóvil): el propio paso indica la duración mínima; si
terminas antes, el resultado será SKIP con el tiempo medido.

## 3. Captura manual (alternativa)

```bash
cd /root/cerraduras/api
php bin/sensor-capture.php --room=14 --duration=600
```

En otra terminal, marcar cada acción física:

```bash
php bin/sensor-capture.php --mark=PUERTA_ABRE
php bin/sensor-capture.php --mark=PUERTA_CIERRA
php bin/sensor-capture.php --mark=ENTRA
php bin/sensor-capture.php --mark=QUIETO
php bin/sensor-capture.php --mark=SALE
php bin/sensor-capture.php --mark=SALA_VACIA
```

Salida: `api/run/captures/<room>-<fecha>Z.jsonl` (0600). Contiene la señal, `applied`,
`discard_reason`, `radar_decision` y `settings`.

## 4. Prueba de puerta (10 transiciones)

1. Cinco ciclos normales, separados ≥2 s.
2. Un cierre lento, una apertura rápida y movimiento cerca del contacto.
3. Puerta mantenida abierta 60 s y cerrada 60 s.

Revisar por ciclo:

| Campo | Esperado |
|---|---|
| Eventos `PROXIMITY` | uno por transición |
| `applied` | `true` en la transición |
| Inversión OPEN/CLOSED | ninguna |
| Evento extra por batería/presencia | ninguno con `contact` cacheado |

Criterio: 10/10 transiciones correctas. Solo si aparecen rebotes se añade antirrebote
(no antes).

## 5. Matriz del radar

| Escenario | Repeticiones | Qué se mide |
|---|---:|---|
| Entrada/salida normal | 5 | latencia y transiciones |
| Persona inmóvil, 3 posiciones | 5–10 min cada una | pérdida de presencia |
| Paso por el pasillo | 10 | falsos positivos |
| Salir y volver con puerta abierta | 5 | reentrada |
| Sala vacía, puerta cerrada | 30–60 min | ruido de fondo |
| Sala vacía, puerta abierta | 30–60 min | ruido + contexto |

Ajustar **una variable cada vez**, en este orden: colocación/orientación, rangos,
sensibilidad, `detection_delay`, `fading_time`.

`target_distance` es diagnóstico: `0` no significa ausencia.

### Calibración desde `/dashboard`

El modal abre el sensor de la sala actual. Con Zigbee muestra:

- rango máximo (m) y sensibilidad del radar;
- ajustes avanzados: distancia mínima, retardo de detección y tiempo sin presencia.

Al soltar un control se publica por MQTT y se espera un **reporte nuevo** del radar. Si
no confirma, el valor no se guarda. Con Tuya se mantiene la lectura REST bajo demanda y
el cooldown de cuota.

## 6. Análisis sin efectos

```bash
php bin/sensor-capture.php --analyze=run/captures/14-20261028T100000Z.jsonl --mode=radar_only
```

Devuelve número de eventos, marcas, recuento por estado y transiciones. No escribe en BD
ni acciona nada. Comparar el modo operativo (`mixed`, `radar_door`, `camera_fusion`) con
el candidato antes de promoverlo.

## 7. Interpretación de estados

| Estado | Significado |
|---|---|
| `CANDIDATE_PRESENT` | señal positiva sin evidencia suficiente; **no** es ocupación |
| `PRESENT_CONFIRMED` | ocupación efectiva |
| `CANDIDATE_ABSENT` | negativo breve; la ocupación sigue vigente |
| `ABSENT_CONFIRMED` | ausencia efectiva (permite salida) |
| `stale` | el sensor dejó de reportar; no es ausencia |

## 8. Criterios de aceptación

- 10/10 transiciones de puerta correctas.
- Ninguna entrada real perdida en la matriz.
- Ningún efecto falso (visita, grabación, luz, apertura) en sala vacía durante la
  ventana acordada.
- Paneles coherentes con el servidor (incluido `?`/candidato).
- Reconexión/duplicados sin efectos nuevos.
- Regresión: `cd api && bash bin/run-tests.sh` con 0 fallos.

## 9. Límites conocidos

- La puerta Zigbee es un EndDevice dormido: `contact` no admite `/get`.
- El radar Zigbee publica su configuración en cada reporte; una publicación aceptada no
  prueba que el firmware la aplicó.
- El radar 24G puede detectar respiración/movimiento mínimo; no cuenta personas.
- La latencia física total incluye sensor + radio + broker + worker + panel.
