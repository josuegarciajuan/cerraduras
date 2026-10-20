# scanner-relay-prod-r35d

Sketch productivo de la base **ESP32-S3-USB-OTG + R35D-B**.

Abrir esta carpeta (no el `.ino` suelto de `docs/`) en Arduino IDE 2.x. El
directorio contiene un único sketch, por lo que el IDE no mezcla los `.ino`
de referencia del repositorio.

## Placa y puerto

| Ajuste | Valor |
|---|---|
| Board | **ESP32S3 USB OTG** (`esp32:esp32:esp32s3usbotg`) |
| USB Mode | **USB-OTG** (default) |
| Upload Mode | **UART0** |
| Puerto | COM/CH343 (en Linux suele ser `/dev/ttyACM0`) |
| Monitor serie | **115200**, **DTR off**, **RTS off** |

## Arduino IDE

1. Abrir `scanner-relay-prod-r35d.ino` desde esta carpeta.
2. Seleccionar la placa `ESP32S3 USB OTG`, USB Mode `USB-OTG`, Upload Mode `UART0`.
3. Seleccionar el puerto COM del CH343/CP210x.
4. Subir.
5. Abrir **Serial Monitor** a **115200** y desactivar DTR/RTS si el monitor
   retiene la placa en reset.

## arduino-cli

Compilar:

```bash
arduino-cli compile --profile esp32s3usbotg .
```

Subir (sustituir `COM3` por el puerto real):

```bash
arduino-cli upload --profile esp32s3usbotg -p COM3 .
```

Monitor serie:

```bash
arduino-cli monitor -p COM3 -c baudrate=115200
```

En Linux, el puerto suele ser `/dev/ttyACM0`:

```bash
arduino-cli upload --profile esp32s3usbotg -p /dev/ttyACM0 .
arduino-cli monitor -p /dev/ttyACM0 -c baudrate=115200
```

## Notas

- El sketch no contiene ningún token QR hardcodeado: todo texto leído se envía
  a `/api/v1/qr/validate` y solo abre con HTTP 200.
- Cada build nuevo borra las credenciales WiFi y obliga a reprovisionar por el
  portal `Cerraduras-Setup-<chipId>`; `device-cred` se conserva.
- El LED verde integrado de la placa está en GPIO15 (parpadea con la cerradura).
  El LED verde externo es GPIO2.
- La copia canónica de referencia está en
  `docs/esp32-qr-reader/scanner-relay-prod-r35d.ino`; ambas deben ser idénticas
  (lo comprueba `api/tests/Unit/R35dProductionSketchTest.php`).
