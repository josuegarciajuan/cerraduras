# Calibración y verificación de sensores (F93)

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

## 2. Captura de una sesión

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

## 3. Prueba de puerta (10 transiciones)

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

## 4. Matriz del radar

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

## 5. Análisis sin efectos

```bash
php bin/sensor-capture.php --analyze=run/captures/14-20261028T100000Z.jsonl --mode=radar_only
```

Devuelve número de eventos, marcas, recuento por estado y transiciones. No escribe en BD
ni acciona nada. Comparar el modo operativo (`mixed`, `radar_door`, `camera_fusion`) con
el candidato antes de promoverlo.

## 6. Interpretación de estados

| Estado | Significado |
|---|---|
| `CANDIDATE_PRESENT` | señal positiva sin evidencia suficiente; **no** es ocupación |
| `PRESENT_CONFIRMED` | ocupación efectiva |
| `CANDIDATE_ABSENT` | negativo breve; la ocupación sigue vigente |
| `ABSENT_CONFIRMED` | ausencia efectiva (permite salida) |
| `stale` | el sensor dejó de reportar; no es ausencia |

## 7. Criterios de aceptación

- 10/10 transiciones de puerta correctas.
- Ninguna entrada real perdida en la matriz.
- Ningún efecto falso (visita, grabación, luz, apertura) en sala vacía durante la
  ventana acordada.
- Paneles coherentes con el servidor (incluido `?`/candidato).
- Reconexión/duplicados sin efectos nuevos.
- Regresión: `cd api && bash bin/run-tests.sh` con 0 fallos.

## 8. Límites conocidos

- La puerta Zigbee es un EndDevice dormido: `contact` no admite `/get`.
- El radar Zigbee publica su configuración en cada reporte; una publicación aceptada no
  prueba que el firmware la aplicó.
- El radar 24G puede detectar respiración/movimiento mínimo; no cuenta personas.
- La latencia física total incluye sensor + radio + broker + worker + panel.
