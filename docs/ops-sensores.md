# Calibración y verificación de sensores (F93/F95)

## Flujo universal `/sensores` (F95, Zigbee)

Válido para los tres dispositivos de `almacenZigbee` (SNZB-04P, ZY-M100-S_2,
ZBMINIL2):

1. **Preflight**: muestra los 3 dispositivos (verde/rojo/ámbar) y el plan.
   Si hay una corrida en curso, puedes continuarla o pulsar **Reiniciar toda la prueba**.
   La anterior queda archivada como interrumpida y la nueva empieza en Puerta; la
   polaridad y los ajustes físicos se conservan.
2. **Puerta**: cierra y pulsa `Comprobar polaridad`. La app asume que la puerta
   está cerrada y usa el último reporte del sensor aunque haya pasado tiempo (el
   SNZB-04P solo emite al cambiar). Corrige el montaje invertido solo en ese
   dispositivo. Después, 3 ciclos (normal, lento, rápido). El contador `0/3`
   solo suma pares de esa ventana; los eventos anteriores no cuentan.
3. **Radar dentro**: colócate en el punto más lejano y quieto. La app mide en una
   ventana de 30 s; durante la cuenta atrás solo puedes cancelar. El ZY-M100
   **solo publica cuando cambia algo**: si no hay reportes nuevos, la app usa el
   estado crudo al inicio de la ventana. Un `ABSENT` sí se publica y hace fallar
   la medición. Al terminar muestra presencia, distancia orientativa y calidad.
   Si la medición es suficiente y no detecta, `Ajustar y volver a medir` propone
   un cambio y lo confirma por push; el ajuste interior no exige una medición
   exterior.
4. **Radar fuera (autocalibración)**: colócate en el punto donde NO quieres que
   te detecte (por ejemplo, justo al otro lado de la puerta) y pulsa
   `Medir punto exterior`. Permanece quieto 20-30 s. La app mide tu distancia
   `P` ahí y calcula el punto medio entre la referencia interior (`I`) y `P`;
   lo aplica sola y espera confirmación por push. Después verifica el mismo
   punto, la reentrada y la pasada por el pasillo. Si el radar ya no te detecta
   en ese punto, no hay `P`: se mantiene el alcance actual y se continúa. Si
   `P <= I`, la app **no** baja la sensibilidad a ciegas: se detiene y pide
   revisar la colocación/repetir la medición exterior. `Restaurar ajustes
   iniciales` revierte alcance/sensibilidad/fading/retardo y exige confirmación.
5. **Luz**: la app pide dejar la lámpara **apagada** y que lo confirmes. Al pulsar
   `Empezar prueba` **se enciende automáticamente**; confirmas que encendió, la app
   la apaga y confirmas que apagó. Una pregunta por orden. La telemetría solo cuenta
   si es posterior a la orden; si no llega, manda tu observación. La fase se guarda
   en el servidor: recargar no reenvía órdenes. Si algo falla, consulta
   [`docs/ops/diagnostico-luz-zigbee.md`](diagnostico-luz-zigbee.md) **antes** de
   cortar la alimentación.
6. **Veredicto**: descarga el informe. `Comprobar reposo` revisa los últimos
   30 min cuando la sala lleve ese tiempo vacía (sin proceso de fondo).

La puerta y el radar son obligatorios; si no hay luz, el paso queda `NO INSTALADO`.

**Indicadores de salud**: el radar (Router) publica cambios y puede estar en
silencio mientras la escena no cambia; el calibrador usa la señal cruda y avisa
solo cuando supera `presence_stale_seconds` sin reportar. La puerta (SNZB-04P) y la luz (ZBMINIL2) son
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

## 10. Polaridad y ciclos de puerta (F104.3)

- La polaridad se infiere de la última evidencia de puerta **cerrada**, no del
  último evento. Si el último evento es una apertura, la polaridad no cambia.
- Si no hay evidencia de cerrada, se mantiene la polaridad actual; no se pide
  abrir/cerrar antes de empezar.
- Al pulsar **Empezar ciclos**, el servidor confirma `CLOSED` y pone una barrera
  para ignorar aperturas atrasadas.
- **Validar puerta** solo se habilita cuando el último evento es un cierre. Si
  queda una apertura pendiente, la app pide cerrar.
- Si la secuencia empieza por `CLOSED`, aparece **Invertir polaridad y repetir**.

> **F110 (vigente, sesión v5 `distance_inside_only_v1`)**: el **interior** sigue
> siendo el único punto donde se mide distancia, pero se captura con pasos
> laterales de 20–30 cm y se usa la **mediana** de todas las lecturas positivas.
> La dispersión lateral **no** invalida la medición. El **exterior** ya no se
> mide: se verifica solo por presencia. Las reglas de esta sección (dos lecturas,
> coherencia 0,30 m) quedan para sesiones antiguas y para la distancia exterior
> histórica.

## 11. Medición del radar por punto (F105)

- Cada punto (interior/exterior) exige al menos **2 lecturas nuevas** de
  distancia. Un valor positivo repetido en mensajes consecutivos no cuenta:
  Zigbee2MQTT publica todo el estado y no demuestra que la distancia se haya
  vuelto a medir.
- Las lecturas nuevas deben estar dentro de **0,30 m** entre sí
  (`RADAR_POINT_TOLERANCE_M`). Se usa la media del mayor conjunto coherente.
- Si faltan lecturas, la app pide **mover los brazos 5 s sin mover los pies**.
  Hay hasta **3 intentos** por punto (`RADAR_POINT_MAX_ATTEMPTS`).
- Si las medias exterior e interior se invierten o quedan demasiado próximas, no
  se aplica ningún alcance (`outside_not_farther` / `points_too_close`).
- La tolerancia es un criterio de coherencia, no una precisión del radar. Tras
  aplicar el ajuste sigue siendo necesaria una comprobación física.


## 12. Preparación limpia de una prueba (F110)

Ejecutar este runbook **después de cualquier cambio/despliegue** y antes de una
prueba física. Deja la sala sin calibraciones guardadas, sin corridas a medias y
con la pausa desactivada.

### 12.1 Limpieza y backup

```bash
cd /root/cerraduras/api
php bin/sensor-test-prepare.php --room=14
```

El script:

1. copia a `/tmp/f110-prep-<fecha>/` los JSON de las corridas de la sala y el
   `meta_json` del radar;
2. marca como `abandoned` cualquier corrida que siga en `running`;
3. cancela los intentos activos;
4. borra `calibration` y `calibration_previous` del radar;
5. desactiva la pausa manual.

No aplica la base del radar, no arranca la corrida y no toca la luz. Para ver
qué haría sin escribir:

```bash
php bin/sensor-test-prepare.php --room=14 --dry-run
```

Verificar que no queda ninguna corrida `running`:

```bash
curl -s 'http://127.0.0.1:8080/dashboard-api/sensores/v2/runs?room_id=14&limit=10'
```

Para F111 esta preparación es obligatoria y debe ejecutarse justo antes de
crear la corrida. Solo limpia estado local: no consulta Tuya, no aplica la
base del radar y no constituye un resultado de hardware.

### 12.2 Crear la corrida y aplicar la base

```bash
curl -s -X POST -H 'Content-Type: application/json' \
  -d '{"room_id":14,"operator":"prueba F110"}' \
  http://127.0.0.1:8080/dashboard-api/sensores/v2/run/start
```

Debe devolver `201`, un `run_id` nuevo y:

- `calibration.initial_settings.maximum_range = 6.0`;
- `minimum_range = 0.6`;
- `radar_sensitivity = 7`;
- `detection_delay = 0.1`;
- `fading_time = 15.5`.

Si devuelve `409 radar_baseline_failed`, esperar un reporte nuevo del radar y
repetir. No continuar con una base sin confirmar.

Comprobar el preflight:

```bash
curl -s 'http://127.0.0.1:8080/dashboard-api/sensores/v2/preflight?room_id=14'
```

Debe mostrar `can_start: true`, `automation_pause.paused: false` y
`maximum_range: 6`.

### 12.3 Luz apagada y alcanzable

```bash
curl -s -X POST -H 'Content-Type: application/json' \
  -d '{"room_id":14,"action":"OFF"}' \
  http://127.0.0.1:8080/dashboard-api/sensores/v2/switch/test
```

Exigir:

- `reachable: true` (conexión);
- `reported_state: "OFF"` (estado conocido).

`fresh:true` es deseable, pero **no obligatorio**: un comando redundante OFF
sobre una luz ya apagada puede no reemitir estado. En ese caso la app pide
confirmación humana y no debe mostrar “sin conexión”.

Si `reachable` es `false`, **no** iniciar la prueba de luz: revisar
alimentación/Zigbee y repetir la sonda. No se reempareja por este fallo.

Si el fallo se repite o no hay confirmación, **no cortes la alimentación
todavía**: exporta primero la evidencia y sigue
[`diagnostico-luz-zigbee.md`](diagnostico-luz-zigbee.md).

### 12.4 Estado final esperado

- Una sola corrida nueva en `running`.
- Sin intentos activos.
- `devices.meta_json.calibration` y `calibration_previous` ausentes.
- Radar en base 6,0 m confirmada por push.
- Pausa manual desactivada.
- Luz apagada, fresca y alcanzable.

### 12.5 Secuencia de la prueba

1. Puerta/polaridad.
2. Radar interior: primera subfase de captura; después, pasos laterales de
   20–30 cm durante una ventana de 60 s. Si no llega un DP9 nuevo, se usa la
   distancia PRESENT capturada en la primera subfase (máximo 60 s).
3. Validar/Continuar interior. Ambos aplican el alcance `mediana + 0,10 m`
   antes de pasar al exterior. La app usa la mediana y acepta la dispersión
   lateral.
4. Comprobar el exterior solo por presencia. Esto no es una calibración de
   distancia; la app solo verifica que el alcance aplicado ya no te detecta. Si detecta, baja 0,15 m; si no
   queda margen, se detiene con `inside_only_no_separation`.
5. Reentrada: ya estás fuera; pulsa «Voy a entrar» y entra. No hace falta
   volver a salir ni esperar a que el radar te pierda.
6. Luz: preparación OFF, encender, apagar, validar.
7. Ver veredicto y reanudar automatizaciones solo al terminar.

### 12.6 Retención de evidencia DP9

`radar_distance_reports` guarda **solo DP9 positivos**. Los ceros se conservan
en `presence_events`, no en esta tabla.

```bash
php bin/radar-distance-retention.php --days=30 --dry-run
php bin/radar-distance-retention.php --days=30
php bin/radar-distance-retention.php --days=30 --purge-zeros  # una vez
```

El timer `cerraduras-radar-distance-retention.timer` ejecuta la purga diaria.
No toca `presence_events`, visitas, grabaciones ni calibraciones.

### 12.7 Repetición tras cambios

Después de cada cambio o despliegue:

1. ejecutar la regresión (`cd api && bash bin/run-tests.sh`);
2. ejecutar este runbook;
3. crear la corrida y comprobar la luz;
4. hacer la prueba física.

Así no se reutilizan calibraciones, corridas ni intentos de pruebas anteriores.

## 6. Salida del radar del almacén (F114+)

El ZY-M100-S_2 solo publica el DP 1 (`presence`) cuando cambia. La trama
combinada de Zigbee2MQTT repite el último valor cacheado, así que esperar
`3 reportes / 30 s` solo añade latencia. La salida se decide ahora por el
**borde DP 1**:

- nuevo `ABSENT` estable durante `RADAR_ABSENT_EDGE_GRACE_SECONDS` (def. 5 s)
  → se aplica ausencia y se cierra la visita;
- un `PRESENT` nuevo dentro de la gracia cancela la ausencia (evita rebotes);
- el panel muestra el último DP1, la edad de la última trama y si la
  calibración guardada coincide con la reportada por el dispositivo.

El perfil de calibración del almacén usa `RADAR_WAREHOUSE_FADING_TIME=5.0`
para que el radar emita `ABSENT` antes de 10 s. Si el panel muestra
**CALIBRACIÓN NO APLICADA** (por ejemplo tras un corte de alimentación),
re-aplica el perfil guardado una sola vez:

```bash
cd /root/cerraduras/api
php bin/radar-apply-profile.php --room=14 --dry-run
php bin/radar-apply-profile.php --room=14
```

El script publica por MQTT y espera la confirmación por push. No consulta
Tuya ni crea temporizadores.
