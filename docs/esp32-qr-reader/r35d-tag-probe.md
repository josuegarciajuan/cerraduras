# R35D-B — Prueba de lectura de llavero EM (diagnóstico)

> Estado: herramienta de diagnóstico, **no firmware productivo**.
> Objetivo: comprobar si el R35D-B entrega por RS-485 el identificador de un
> llavero EM 125 kHz y ver el contenido exacto de la trama.

## Por qué no basta con el pitido

El R35D-B puede tener el zumbador silenciado (`R_CMD_2000`) sin que ello
indique un fallo. La prueba válida es observar los bytes que salen por la UART.
El sketch `r35d-tag-probe.ino` no filtra por longitud ni exige que la trama sea
un QR: vuelca cualquier byte recibido.

## Seguridad antes de flashear

1. **Respaldar la flash completa** antes de sustituir el firmware. Es la única
   forma de restaurar el binario exacto y conservar WiFi/NVS y el marcador de
   build.
2. La cerradura se deja en **GPIO15 LOW** y el sketch no la activa.
3. El sketch **no usa WiFi, no escribe NVS y no llama a la API**.
4. No tocar la ESP-CAM: su puerto es el FTDI `/dev/ttyUSB0`. El lector es el
   CH343 `/dev/ttyACM0`.

### Respaldar la flash

Con el lector conectado por USB y sin ningún monitor serie abierto:

```bash
esptool.py --port /dev/serial/by-id/usb-1a86_USB_Single_Serial_5B90126830-if00 flash_id
```

Anotar el tamaño detectado (`Detected flash size: 8MB` o `16MB`) y leer esa
cantidad completa:

```bash
# ejemplo para 8 MB; cambiar 0x800000 por 0x1000000 si es de 16 MB
esptool.py --port /dev/serial/by-id/usb-1a86_USB_Single_Serial_5B90126830-if00 \
  --baud 921600 read_flash 0x0 0x800000 /tmp/r35d-pre-probe-$(date +%Y%m%d-%H%M%S).bin
```

Verificar que el fichero existe y tiene el tamaño esperado. Sin este respaldo
**no continuar**.

## Compilar y cargar el diagnóstico

Con `arduino-cli` y el core ESP32 instalado en el usuario `oficina`:

```bash
ARDUINO_CLI=/tmp/cerraduras-tools/arduino-cli   # o el que esté disponible
SKETCH=firmware/r35d-tag-probe

sudo -u oficina env HOME=/home/oficina \
  "$ARDUINO_CLI" compile --fqbn esp32:esp32:esp32s3usbotg "$SKETCH"

sudo -u oficina env HOME=/home/oficina \
  "$ARDUINO_CLI" upload \
  -p /dev/serial/by-id/usb-1a86_USB_Single_Serial_5B90126830-if00 \
  --fqbn esp32:esp32:esp32s3usbotg "$SKETCH"
```

El sketch canónico está en `docs/esp32-qr-reader/r35d-tag-probe.ino` y su copia
lista para Arduino IDE en `firmware/r35d-tag-probe/`. Deben ser idénticas.

## Observar desde el servidor

En el ordenador de oficina:

```bash
python3 docs/esp32-qr-reader/r35d-serial-observe.py
```

Opcionalmente guardar log:

```bash
python3 docs/esp32-qr-reader/r35d-serial-observe.py \
  --log /tmp/r35d-tag-probe-$(date +%Y%m%d-%H%M%S).log
```

Salida esperada:

```text
[READY] esperando tramas del R35D-B...
[ALIVE] uptime=5s frames=0 bytes=0 heap=...
[TAG] seq=1 len=14 term=CRLF gap_ms=...
[TAG] HEX: 30 41 31 42 32 43 33 44 34 45 0D 0A
[TAG] ASCII: 0A1B2C3D4E..
[TAG] content="0A1B2C3D4E"
[TAG] hint=possible-EM-UID (8/10 hex)
```

## Secuencia de prueba

1. **Reposo**: comprobar que llegan mensajes `[ALIVE]` cada 5 s y que
   `frames=0`. Si no hay `[ALIVE]`, el monitor está mal abierto (DTR/RTS,
   puerto o baudios).
2. **QR de control**: presentar un QR conocido. Debe aparecer una trama
   `[TAG]` con el texto del QR. Esto demuestra que lector, alimentación,
   RS-485, UART y captura funcionan.
3. **Llavero EM**: acercarlo a la cara frontal, primero pegado y luego a
   1–3 cm y 3–6 cm. Retirarlo entre intentos. Repetir al menos tres veces.
4. **Comparar**: el mismo llavero debe producir el mismo `content` en todas
   las lecturas. Anotar longitud, HEX y si el terminador es CRLF.
5. **Cerrar**: parar el monitor y restaurar el firmware anterior.

## Interpretación

| Observación | Lectura |
|---|---|
| QR de control llega, llavero produce trama | Lectura RFID correcta; el problema era el pitido o el formato |
| QR de control llega, llavero no produce nada | El R35D-B no está detectando ese llavero o no tiene módulo EM |
| Ni QR ni llavero producen tramas | Problema de recepción/alimentación, no del llavero |
| Llegan bytes del llavero pero no son 8/10 hex | El lector está configurado en decimal, orden inverso u otro formato |
| El llavero produce tramas inestables | Repetir con más distancia o revisar alimentación |

Si el QR funciona y el llavero no produce **ningún** byte, no cambiar el
sketch: el ESP no puede inventar compatibilidad RF. Hay que comprobar la
variante del R35D-B y el tipo exacto de llavero (`EM4100`, `EM4102`, `T5577`).

## Restaurar producción

Preferente, restaurar el respaldo completo:

```bash
esptool.py --port /dev/serial/by-id/usb-1a86_USB_Single_Serial_5B90126830-if00 \
  --baud 921600 write_flash 0x0 /tmp/r35d-pre-probe-XXXX.bin
```

Alternativa: compilar y cargar `firmware/scanner-relay-prod-r35d/`. Atención:
el firmware productivo borra las claves WiFi cuando detecta un build nuevo, por
lo que puede exigir repetir el provisioning. El respaldo completo evita ese
efecto.

## Trazabilidad

- Base y pinout: `docs/hardware/esp32-s3-r35d-pinmap.md`.
- Firmware productivo: `docs/esp32-qr-reader/scanner-relay-prod-r35d.ino`.
- Validación básica previa: `docs/esp32-qr-reader/r35d-basic-test.md`.
- Backend de fichas/llaves existente: F96, `POST /api/v1/keys/validate` con
  `ficha_code` (la integración del lector NFC no formaba parte de F96).
