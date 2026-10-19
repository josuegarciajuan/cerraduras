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

El firmware anterior permanece en el repositorio para la base antigua. La
migración no está cerrada: no cambiar el pinout productivo ni flashear el
firmware antiguo en la base nueva.

## Estado de validación

- `Hola Mundo` compilado y subido por COM (`/dev/ttyACM0`) a 115200, con
  DTR/RTS off; salida verificada.
- **2026-10-19**: lectura del R35D-B y prueba básica de relé + LEDs superadas
  (ver `docs/esp32-qr-reader/r35d-basic-test.md`).
- Siguiente paso: crear el sketch productivo definitivo usando como base el
  firmware del prototipo y la referencia de hardware de `r35d-basic-test.ino`.
