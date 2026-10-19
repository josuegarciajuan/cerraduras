# Pruebas básicas de la nueva placa R35D-B

> Fecha: 2026-10-19
> Estado: **prueba de hardware superada**. No es el firmware productivo.

## Objetivo

Validar los elementos nuevos de la placa definitiva antes de adaptar el
firmware productivo:

- Lector **R35D-B** por RS-485.
- Relé/cerradura en **GPIO15** (activo-alto).
- LEDs externos: **GPIO1 rojo**, **GPIO2 verde**.
- UART1 en **GPIO18 RX / GPIO17 TX** y dirección **GPIO7**.

Pinout y notas eléctricas: [`docs/hardware/esp32-s3-r35d-pinmap.md`](../hardware/esp32-s3-r35d-pinmap.md).

## Hardware

| Elemento | Valor |
|---|---|
| Placa | ESP32-S3-USB-OTG (placa completa) |
| Puerto de trabajo | COM/CH343 (en la unidad validada, `/dev/ttyACM0`) |
| Monitor serie | 115200, DTR off, RTS off |
| Lector | R35D-B por RS-485 (A/B) |
| UART del lector | UART1: RX=18, TX=17 |
| Dirección RS-485 | GPIO7: LOW=lectura, HIGH=escritura |
| Relé/cerradura | GPIO15: HIGH=activado |
| LEDs externos | GPIO1 rojo, GPIO2 verde |

## Protocolo confirmado

- **9600 8N1**.
- **Texto ASCII plano**, no MODBUS.
- Terminador **CRLF** (`0D 0A`).
- Token de prueba: `95l8bpUfThEF`.
- Trama observada:

```text
HEX:   39 35 6C 38 62 70 55 66 54 68 45 46 0D 0A
ASCII: 95l8bpUfThEF..
```

- Un QR incorrecto de 199 bytes (token tipo JWT) se recibió completo y se
  clasificó como `[NO MATCH]`; no se incluye su contenido.
- El byte residual `00` del arranque se clasificó como `[NOISE]` y no actuó.

## Comportamiento del sketch de prueba

- En reposo: **rojo ON, verde OFF**.
- Matcher exacto del token de prueba.
- Al coincidir:
  - `[MATCH]`.
  - LEDs: rojo OFF, verde ON.
  - Secuencia del proveedor en GPIO15:
    - 250 ms HIGH.
    - 125 × (1 ms HIGH / 1 ms LOW).
    - 30 × (5 ms HIGH / 5 ms LOW).
    - Termina en LOW.
  - Ventana de apertura/LED verde: **3000 ms**.
  - Cooldown: **2000 ms**.
- Al terminar la ventana: verde OFF, rojo ON.
- QR incorrecto, ruido o repetición rápida: no acciona el relé.

## Resultado

Prueba realizada por el operador en la nueva placa:

- La cerradura abre con el QR de prueba.
- Los LEDs externos responden: rojo por defecto; verde durante la apertura.
- El QR incorrecto no abre.
- La repetición rápida no genera una segunda apertura.

Evidencia parcial del monitor serie:

```text
[MATCH] 95l8bpUfThEF
[LED] rojo OFF, verde ON
[LOCK] abriendo...
[LOCK] fin del pulso
```

El monitor del IDE y el logger compartieron el puerto durante la prueba, por lo
que el log completo quedó fragmentado. La validación física fue confirmada por
el operador.

## Limitaciones

- Es un sketch de **prueba de hardware**, no producción.
- Token de prueba hardcodeado (`95l8bpUfThEF`).
- Sin WiFi, TLS, API, heartbeat ni command-queue.
- Sin pulsador GPIO9.
- Pulso del proveedor **bloqueante** (~800 ms).
- No debe flashearse en la placa prototipo antigua.

## Siguiente paso

Crear el sketch definitivo usando como base:

```text
docs/esp32-qr-reader/scanner-relay-prod-12v-robusto-lowpower.ino
```

y como referencia de hardware nuevo:

```text
docs/esp32-qr-reader/r35d-basic-test.ino
```

El sketch definitivo debe conservar la lógica de producción (WiFiManager/NVS,
credenciales de fábrica, TLS, `/api/v1/qr/validate`, heartbeat, command-queue,
identify, watchdog, announce) y sustituir el lector GM65 USB Host y el relé
GPIO16 activo-bajo por el R35D-B RS-485 y el relé GPIO15 activo-alto.
