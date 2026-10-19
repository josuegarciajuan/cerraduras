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
| 1 | LED rojo | salida | Nuevo; pendiente de implementar. |
| 2 | LED verde | salida | Nuevo; pendiente de implementar. |
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
- Formato inicial: **8N1**; confirmar con el manual del dispositivo.
- Lectura: GPIO7 **LOW**, escuchar UART1 RX (GPIO18).
- Escritura: GPIO7 **HIGH**, transmitir por UART1 TX (GPIO17), esperar a que la
  UART vacíe la FIFO y volver a GPIO7 **LOW**.
- Las primeras pruebas son **solo lectura**. No enviar comandos inventados: el
  manual debe confirmar si el lector transmite solo tras el escaneo o si exige
  una consulta, y cuál es la trama exacta.

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
- Siguiente paso: prueba de lectura del R35D-B a 9600, GPIO7 en LOW, UART1
  RX18/TX17.
