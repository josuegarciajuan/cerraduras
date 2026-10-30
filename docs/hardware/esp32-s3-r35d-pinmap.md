# ESP32-S3-USB-OTG — Pinout definitivo de la base R35D-B

> Estado: **definitivo para la base nueva**. El firmware productivo anterior
> (`scanner-relay-prod-12v-robusto-lowpower.ino`) sigue usando el pinout de la
> base antigua y **no debe flashearse en esta base** sin adaptar los pines.

## Placa y puerto serie

- Placa: **ESP32-S3-USB-OTG** (Arduino FQBN `esp32:esp32:esp32s3usbotg`).
- USB Mode: **USB-OTG (TinyUSB)** (`USBMode=default`).
- Upload Mode: **UART0**.
- Conexión de trabajo: puerto **COM**. En la unidad validada el puente COM
  enumera como **QinHeng CH343** (`1a86:55d3`, `/dev/ttyACM0`); otras unidades
  pueden montar CP210x.
- Monitor serie: **115200**, **DTR off**, **RTS off**. Con DTR/RTS activos la
  placa puede quedar retenida en reset/bootloader y el monitor muestra basura.

Validado con `Hola Mundo` a 115200 por el puerto COM, con DTR/RTS desactivados.

## Pinout

| GPIO | Función | Dirección / nivel | Notas |
|---|---|---|---|
| 1 | LED rojo | salida | Validado en la prueba básica: ON por defecto. |
| 2 | LED verde | salida | Validado en la prueba básica: ON durante la apertura. |
| 18 | UART1 RX — lectura QR | entrada | R35D-B por RS-485. |
| 17 | UART1 TX — escritura QR | salida | R35D-B por RS-485; no se usa en pruebas de solo lectura. |
| 7 | Dirección del puerto QR | salida | **LOW = lectura**, **HIGH = escritura**. Mantener LOW mientras se escucha. |
| 15 | Control de cerradura | salida | **HIGH = activado** (activo-alto en esta base). No reutilizar la lógica activo-bajo tri-state anterior. |
| 9 | Pulsador | entrada | Solo lectura; confirmar polaridad eléctrica (usar `INPUT_PULLUP` si cierra a GND). |

### Conflicto con el variant `esp32s3usbotg`

El variant de la placa USB-OTG define:

- `LIMIT_EN = 17`
- `USB_HOST_EN = 18`

y en `initVariant()` los configura como salida y los pone a LOW. Para esta base
hay que inicializar UART1 **después** de ese arranque:

```cpp
Serial1.begin(9600, SERIAL_8N1, 18, 17);
```

No debe habilitarse el código de USB Host en esta base, porque volvería a
gobernar GPIO17/18. El macro `LED_GREEN` del variant también es GPIO15: no debe
usarse en este firmware. GPIO1/2 aparecen como `HOST_VOLTS`/`BAT_VOLTS` en el
variant, pero no se gobiernan automáticamente; verificar que la base no los
cargue.

## R35D-B por RS-485

- Interfaz: **RS-485 (A/B)** a través del transceptor de la base.
- Velocidad: **9600** (configurada con el QR del fabricante `R_CMD_BAUD_9600`).
- Formato confirmado: **8N1**.
- Protocolo confirmado: **texto ASCII plano terminado en CRLF** (`0D 0A`);
  **no es MODBUS**. El lector transmite el texto del QR tras el escaneo.
- Lectura: GPIO7 **LOW**, escuchar UART1 RX (GPIO18).
- Escritura: GPIO7 **HIGH**, transmitir por UART1 TX (GPIO17), esperar a que la
  UART vacíe la FIFO y volver a GPIO7 **LOW**.
- Lectura validada: el lector transmite solo tras el escaneo. No enviar comandos
  en operación normal. No inventar comandos: si alguna vez se necesita configurar
  el lector, usar exclusivamente los QR del manual.

## Pruebas básicas realizadas

El sketch [`r35d-basic-test.ino`](../esp32-qr-reader/r35d-basic-test.ino) y su
informe [`r35d-basic-test.md`](../esp32-qr-reader/r35d-basic-test.md) validan:

- Lectura del token de prueba `95l8bpUfThEF` a 9600 8N1, ASCII + CRLF.
- Rechazo de un QR incorrecto de 199 bytes y del ruido de arranque.
- Apertura de la cerradura con la secuencia del proveedor en GPIO15.
- LEDs externos: rojo ON por defecto; verde ON durante la ventana de apertura
  de 3 s; después rojo ON y verde OFF.
- Cooldown de 2 s para el mismo token.

> `r35d-basic-test.ino` es una prueba de hardware, no el firmware productivo:
> token de prueba hardcodeado, sin WiFi/API y con pulso bloqueante.

## Diferencias con el firmware productivo anterior

| Función | Base anterior | Base nueva |
|---|---|---|
| Lector QR | GM65 por USB Host | R35D-B por RS-485 UART1 |
| Relé | GPIO16, activo-bajo tri-state | GPIO15, activo-alto |
| Pulsador | GPIO4 | GPIO9 |
| Dirección QR | no existía | GPIO7 |
| LEDs | LED GPIO2 | rojo GPIO1, verde GPIO2 |

El firmware anterior permanece en el repositorio para la base antigua. El
firmware de la base nueva es
[`scanner-relay-prod-r35d.ino`](../esp32-qr-reader/scanner-relay-prod-r35d.ino).
No flashear el sketch antiguo en la base nueva ni el nuevo en la base antigua.

## Firmware productivo R35D-B (F89)

- Sketch canónico: [`scanner-relay-prod-r35d.ino`](../esp32-qr-reader/scanner-relay-prod-r35d.ino).
- Carpeta lista para abrir en Arduino IDE (un único `.ino`):
  [`firmware/scanner-relay-prod-r35d/`](../../firmware/scanner-relay-prod-r35d/).
- Conserva WiFiManager/NVS, `device-cred`, TLS/CA, NTP, `/api/v1/qr/validate`,
  health, heartbeat, command-queue, identify, announce, watchdog y mitigaciones
  de brownout.
- Lector: UART1 9600 8N1, RX=18, TX=17, DIR=7 LOW en lectura. Parser ASCII + CRLF;
  no MODBUS. Buffer RX de 1024 B para JWT de ~200 B.
- Cerradura: GPIO15 activo-alto con la secuencia del proveedor en una tarea
  FreeRTOS (250 ms + 125×(1/1) + 30×(5/5), LOW final).
- LEDs: rojo GPIO1 ON en reposo; verde GPIO2 ON durante 3 s al abrir. El LED
  integrado de GPIO15 no es el indicador externo.
- Pulsador: GPIO9 `INPUT_PULLUP`; identify y factory reset de WiFi al arrancar.
- Compilación verificada: `esp32:esp32:esp32s3usbotg`.
- Unit estático: `api/tests/Unit/R35dProductionSketchTest.php`.

## Estado de validación

- `Hola Mundo` compilado y subido por COM (`/dev/ttyACM0`) a 115200, con
  DTR/RTS off; salida verificada.
- **2026-10-19**: lectura del R35D-B y prueba básica de relé + LEDs superadas
  (ver `docs/esp32-qr-reader/r35d-basic-test.md`).
- **2026-10-19 (F89)**: firmware productivo creado y compilado para
  `esp32:esp32:esp32s3usbotg`. Pendiente la validación física del ciclo
  productivo (QR válido/inválido, WiFi/TLS, heartbeat, command-queue, GPIO9).

## Variantes F97: ESP-cerradura y ESP-imán

Misma placa y mismo pinout; cambia el actuador conectado a GPIO15.

| Variante | Sketch | Reposo | Apertura |
|---|---|---|---|
| ESP-cerradura | `firmware/esp-cerradura/` | GPIO15 LOW (sin corriente) | secuencia del proveedor |
| ESP-imán | `firmware/esp-iman/` | 10 ms ON / 5 ms OFF (ensayo F97.2b) | GPIO15 LOW hasta cierre físico |

- El sensor de puerta de ESP-imán es el `PROXIMITY` Zigbee del mismo pack.
- ESP-imán recibe el estado por `/dashboard-api/door-events` (SSE); no hay
  polling Tuya ni `/get` al sensor.
- El primer arranque sin historial queda en `UNSYNCED` (sin corriente) hasta
  una transición nueva o un snapshot contiguo.
- El diagnóstico y el comando `check` no pulsan el imán.

### Flasheo en el banco de oficina

Equipo del proyecto: CH343 `/dev/ttyACM0`
(`/dev/serial/by-id/usb-1a86_USB_Single_Serial_5B90126830-if00`).
La ESP-CAM es el FTDI `/dev/ttyUSB0` y **no se toca**.

```bash
# Respaldo completo antes de cargar (ajustar tamaño real de flash)
esptool.py --port /dev/serial/by-id/usb-1a86_USB_Single_Serial_5B90126830-if00 \
  flash_id
esptool.py --port /dev/serial/by-id/usb-1a86_USB_Single_Serial_5B90126830-if00 \
  --baud 921600 read_flash 0x0 0x800000 /tmp/r35d-pre-f97-$(date +%Y%m%d-%H%M%S).bin

# Compilar y cargar
sudo -u oficina env HOME=/home/oficina /tmp/cerraduras-tools/arduino-cli \
  compile --fqbn esp32:esp32:esp32s3usbotg firmware/esp-iman
sudo -u oficina env HOME=/home/oficina /tmp/cerraduras-tools/arduino-cli \
  upload -p /dev/serial/by-id/usb-1a86_USB_Single_Serial_5B90126830-if00 \
  --fqbn esp32:esp32:esp32s3usbotg firmware/esp-iman
```

ESP-imán conserva WiFi/NVS al cambiar el build; si se necesita volver a
provisionar, mantener GPIO9 al arrancar.
